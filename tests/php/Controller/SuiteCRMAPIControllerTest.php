<?php

declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Controller;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Controller\SuiteCRMAPIController;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Iter 69 — regression coverage for
 * {@see SuiteCRMAPIController::createFollowupTask()}, the first
 * user-facing write endpoint on the fork.
 *
 * The endpoint has three classes of failure it must handle before
 * reaching SuiteCRM's API:
 *
 * 1. Unauthenticated user (no stored access token) — return 401 so
 *    the frontend can surface the OAuth reconnect prompt.
 * 2. Malformed input (empty name / empty sourceId / invalid source
 *    module not in the whitelist / priority outside High/Medium/Low)
 *    — return 400 with a specific error message so the user sees why.
 * 3. SuiteCRM API error propagated up — surface as 502 (bad gateway)
 *    with the original error envelope so the frontend can decide
 *    between "user's fault" (400s from SuiteCRM) and "server's fault"
 *    (5xx from SuiteCRM).
 *
 * Only on the happy path does the endpoint invoke
 * {@see SuiteCRMAPIService::createRecord()}. When it does, the
 * payload must include `parent_type` and `parent_id` (linking the
 * follow-up Task back to its source Meeting/Call/etc.) — that's the
 * whole point of "follow-up".
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
class SuiteCRMAPIControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private IAppConfig&MockObject $appConfig;
	private SuiteCRMAPIService&MockObject $apiService;
	private TokenStorage&MockObject $tokens;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->apiService = $this->createMock(SuiteCRMAPIService::class);
		$this->tokens = $this->createMock(TokenStorage::class);
	}

	/**
	 * @param string|null $userId
	 * @param string $storedAccessToken
	 * @param string $suitecrmUrl
	 */
	private function makeController(
		?string $userId,
		string $storedAccessToken = 'valid-token',
		string $suitecrmUrl = 'https://crm.example.com',
	): SuiteCRMAPIController {
		$this->tokens->method('getAccessToken')->willReturn($storedAccessToken);
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'oauth_instance_url')
			->willReturn($suitecrmUrl);

		return new SuiteCRMAPIController(
			Application::APP_ID,
			$this->request,
			$this->appConfig,
			$this->apiService,
			$this->tokens,
			$userId,
		);
	}

	public function testCreateFollowupTaskRequiresAuthenticatedUser(): void {
		$controller = $this->makeController(null, '');

		$response = $controller->createFollowupTask('Meetings', 'meet-1', 'Follow up');

		$this->assertSame(401, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('not connected', $data['error']);
	}

	public function testCreateFollowupTaskRequiresName(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->createFollowupTask('Meetings', 'meet-1', '   ');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('name is required', $response->getData()['error']);
	}

	public function testCreateFollowupTaskRequiresSourceId(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->createFollowupTask('Meetings', '', 'Follow up');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('sourceId is required', $response->getData()['error']);
	}

	public function testCreateFollowupTaskRejectsUnlistedSourceModule(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		// Emails is deliberately NOT in the whitelist — Tasks can't be
		// parented by an Email in SuiteCRM's data model.
		$response = $controller->createFollowupTask('Emails', 'email-1', 'Reply to');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('Emails', $response->getData()['error']);
		$this->assertIsArray($response->getData()['allowed']);
		$this->assertContains('Meetings', $response->getData()['allowed']);
	}

	public function testCreateFollowupTaskRejectsInvalidPriority(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->createFollowupTask(
			'Meetings', 'meet-1', 'Follow up', '', null, 'Urgent',
		);

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('High / Medium / Low', $response->getData()['error']);
	}

	public function testCreateFollowupTaskHappyPathBuildsCorrectAttributes(): void {
		$controller = $this->makeController('alice', 'valid-token', 'https://crm');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				'https://crm',           // suitecrmUrl
				'valid-token',           // accessToken
				'alice',                 // userId
				'Tasks',                 // module
				$this->callback(function (array $attrs): bool {
					// Attributes must:
					//  - carry the trimmed name
					//  - default status to 'Not Started'
					//  - default priority to 'Medium' when not overridden
					//  - link parent_type + parent_id back to the source
					return $attrs['name'] === 'Weekly sync'
						&& $attrs['status'] === 'Not Started'
						&& $attrs['priority'] === 'Medium'
						&& $attrs['parent_type'] === 'Meetings'
						&& $attrs['parent_id'] === 'meet-42';
				}),
			)
			->willReturn(['data' => ['type' => 'Tasks', 'id' => 'new-task-77', 'attributes' => []]]);

		$response = $controller->createFollowupTask(
			'Meetings', 'meet-42', '  Weekly sync  ',
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('new-task-77', $response->getData()['data']['id']);
	}

	public function testCreateFollowupTaskOmitsDateDueWhenEmpty(): void {
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				$this->anything(), $this->anything(), $this->anything(),
				'Tasks',
				$this->callback(function (array $attrs): bool {
					return !array_key_exists('date_due', $attrs);
				}),
			)
			->willReturn(['data' => ['id' => 'x']]);

		$response = $controller->createFollowupTask(
			'Meetings', 'meet-1', 'Follow up',
			'', // description
			'', // dateDue — empty string, must be omitted from attributes
		);

		$this->assertSame(200, $response->getStatus());
	}

	public function testCreateFollowupTaskIncludesDateDueWhenProvided(): void {
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				$this->anything(), $this->anything(), $this->anything(),
				'Tasks',
				$this->callback(function (array $attrs): bool {
					return ($attrs['date_due'] ?? null) === '2026-08-15';
				}),
			)
			->willReturn(['data' => ['id' => 'x']]);

		$response = $controller->createFollowupTask(
			'Meetings', 'meet-1', 'Follow up',
			'', '2026-08-15',
		);

		$this->assertSame(200, $response->getStatus());
	}

	public function testCreateFollowupTaskPropagatesSuiteCRMErrorAsBadGateway(): void {
		$controller = $this->makeController('alice');

		$this->apiService->method('createRecord')->willReturn([
			'error' => 'Bad credentials',
			'body' => '{"errors":[{"detail":"token expired"}]}',
		]);

		$response = $controller->createFollowupTask('Meetings', 'meet-1', 'Follow up');

		$this->assertSame(502, $response->getStatus());
		$this->assertSame('Bad credentials', $response->getData()['error']);
		$this->assertStringContainsString('token expired', $response->getData()['body']);
	}

	public function testCreateFollowupTaskAcceptsAllListedParentModules(): void {
		$expected = ['Meetings', 'Calls', 'Tasks', 'Contacts', 'Accounts', 'Leads', 'Opportunities', 'Cases'];

		foreach ($expected as $module) {
			// Fresh controller per iteration so the createRecord mock's
			// expectation count doesn't overlap across modules.
			$fresh = new SuiteCRMAPIControllerTest();
			$fresh->setUp();
			$controller = $fresh->makeController('alice');

			$fresh->apiService->expects($this->once())
				->method('createRecord')
				->willReturn(['data' => ['id' => 'ok']]);

			$response = $controller->createFollowupTask($module, 'src-1', 'Follow up');

			$this->assertSame(200, $response->getStatus(),
				sprintf('Module "%s" should be accepted as a valid Task parent', $module));
		}
	}
}
