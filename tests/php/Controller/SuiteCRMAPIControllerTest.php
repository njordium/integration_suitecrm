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

	/**
	 * Data-driven check that every whitelisted parent module reaches the
	 * SuiteCRM API without a 400. Uses dataProvider so PHPUnit runs each
	 * case with its own setUp() / mocks — instantiating the TestCase
	 * class ourselves inside a foreach breaks PHPUnit's lifecycle.
	 *
	 * @dataProvider provideWhitelistedParentModules
	 */
	public function testCreateFollowupTaskAcceptsWhitelistedParentModule(string $module): void {
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->willReturn(['data' => ['id' => 'ok']]);

		$response = $controller->createFollowupTask($module, 'src-1', 'Follow up');

		$this->assertSame(200, $response->getStatus(),
			sprintf('Module "%s" should be accepted as a valid Task parent', $module));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provideWhitelistedParentModules(): array {
		return [
			'Meetings' => ['Meetings'],
			'Calls' => ['Calls'],
			'Tasks' => ['Tasks'],
			'Contacts' => ['Contacts'],
			'Accounts' => ['Accounts'],
			'Leads' => ['Leads'],
			'Opportunities' => ['Opportunities'],
			'Cases' => ['Cases'],
		];
	}

	// ---------------------------------------------------------------------
	// Iter 70a — logNote() coverage.
	//
	// Same failure-mode structure as createFollowupTask(): auth guard,
	// input validation, target whitelist, happy path builds correct
	// Note payload with parent_type/parent_id, SuiteCRM errors propagate
	// as 502. Kept in the same test file rather than a new one because
	// both endpoints share the SuiteCRMAPIController fixture and would
	// otherwise duplicate setUp() / makeController() helpers.
	// ---------------------------------------------------------------------

	public function testLogNoteRequiresAuthenticatedUser(): void {
		$controller = $this->makeController(null, '');

		$response = $controller->logNote('Contacts', 'contact-1', 'Meeting recap');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('not connected', $response->getData()['error']);
	}

	public function testLogNoteRequiresName(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->logNote('Contacts', 'contact-1', '');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('name is required', $response->getData()['error']);
	}

	public function testLogNoteRequiresTargetId(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->logNote('Contacts', '', 'Meeting recap');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('targetId is required', $response->getData()['error']);
	}

	public function testLogNoteRejectsUnlistedTargetModule(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		// Users is deliberately NOT in the whitelist — attaching a Note
		// to a system module would be a strange thing to allow and
		// widens the endpoint's blast radius unnecessarily.
		$response = $controller->logNote('Users', 'user-1', 'Test');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('Users', $response->getData()['error']);
		$this->assertContains('Contacts', $response->getData()['allowed']);
	}

	public function testLogNoteHappyPathBuildsCorrectAttributes(): void {
		$controller = $this->makeController('alice', 'tok', 'https://crm');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				'https://crm', 'tok', 'alice',
				'Notes',
				$this->callback(function (array $attrs): bool {
					return $attrs['name'] === 'Follow-up call recap'
						&& $attrs['description'] === 'Discussed pricing and next steps.'
						&& $attrs['parent_type'] === 'Contacts'
						&& $attrs['parent_id'] === 'contact-42';
				}),
			)
			->willReturn(['data' => ['type' => 'Notes', 'id' => 'note-99', 'attributes' => []]]);

		$response = $controller->logNote(
			'Contacts', 'contact-42',
			'  Follow-up call recap  ',
			'Discussed pricing and next steps.',
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('note-99', $response->getData()['data']['id']);
	}

	public function testLogNoteAcceptsEmptyDescription(): void {
		// The description field is optional — a Note with just a title is
		// legitimate ("logged: call happened, no further detail").
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				$this->anything(), $this->anything(), $this->anything(),
				'Notes',
				$this->callback(function (array $attrs): bool {
					return $attrs['description'] === '';
				}),
			)
			->willReturn(['data' => ['id' => 'ok']]);

		$response = $controller->logNote('Contacts', 'contact-1', 'Bare note');
		$this->assertSame(200, $response->getStatus());
	}

	public function testLogNotePropagatesSuiteCRMErrorAsBadGateway(): void {
		$controller = $this->makeController('alice');

		$this->apiService->method('createRecord')->willReturn([
			'error' => 'Token expired',
			'body' => '{"errors":[{"detail":"unauthorised"}]}',
		]);

		$response = $controller->logNote('Contacts', 'contact-1', 'Test');

		$this->assertSame(502, $response->getStatus());
		$this->assertSame('Token expired', $response->getData()['error']);
	}

	/**
	 * @dataProvider provideWhitelistedNoteTargets
	 */
	public function testLogNoteAcceptsWhitelistedTargetModule(string $module): void {
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->willReturn(['data' => ['id' => 'ok']]);

		$response = $controller->logNote($module, 'target-1', 'Test');
		$this->assertSame(200, $response->getStatus(),
			sprintf('Module "%s" should accept a Note attachment', $module));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provideWhitelistedNoteTargets(): array {
		return [
			'Contacts' => ['Contacts'],
			'Accounts' => ['Accounts'],
			'Leads' => ['Leads'],
			'Opportunities' => ['Opportunities'],
			'Cases' => ['Cases'],
			'Meetings' => ['Meetings'],
			'Calls' => ['Calls'],
			'Tasks' => ['Tasks'],
		];
	}

	// ---------------------------------------------------------------------
	// Iter 71a — linkDeckCard() coverage.
	//
	// Deck-side comment on the card is handled by the frontend (iter 71b,
	// via NC Deck's OCS API). This endpoint just handles the SuiteCRM
	// side: a Note attached to the target SuiteCRM record that points
	// back at the Deck card. Body format is stable so SuiteCRM users
	// can search for "Nextcloud Deck card" and get a clean hit set.
	// ---------------------------------------------------------------------

	public function testLinkDeckCardRequiresAuthenticatedUser(): void {
		$controller = $this->makeController(null, '');

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'Q3 launch checklist',
			'Opportunities', 'opp-42',
		);

		$this->assertSame(401, $response->getStatus());
	}

	public function testLinkDeckCardRequiresTargetId(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'Launch',
			'Opportunities', '',
		);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('targetId is required', $response->getData()['error']);
	}

	public function testLinkDeckCardRequiresDeckCardUrl(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->linkDeckCard(
			'   ', 'Launch',
			'Opportunities', 'opp-1',
		);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('deckCardUrl is required', $response->getData()['error']);
	}

	public function testLinkDeckCardRejectsMalformedUrl(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->linkDeckCard(
			'not a url at all',
			'Launch',
			'Opportunities', 'opp-1',
		);

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('not a valid URL', $response->getData()['error']);
	}

	public function testLinkDeckCardRejectsUnlistedTargetModule(): void {
		$controller = $this->makeController('alice');
		$this->apiService->expects($this->never())->method('createRecord');

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'Launch',
			'Users', 'user-1',
		);

		$this->assertSame(400, $response->getStatus());
		$this->assertContains('Opportunities', $response->getData()['allowed']);
	}

	public function testLinkDeckCardHappyPathBuildsStableNoteBody(): void {
		$controller = $this->makeController('alice', 'tok', 'https://crm');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				'https://crm', 'tok', 'alice',
				'Notes',
				$this->callback(function (array $attrs): bool {
					// The exact body format is a documented contract —
					// SuiteCRM users may search or filter on it.
					return $attrs['name'] === 'Deck link: Q3 launch checklist'
						&& str_contains($attrs['description'], 'Linked from Nextcloud Deck card "Q3 launch checklist"')
						&& str_contains($attrs['description'], 'URL: https://nc.example.com/apps/deck/')
						&& $attrs['parent_type'] === 'Opportunities'
						&& $attrs['parent_id'] === 'opp-42';
				}),
			)
			->willReturn(['data' => ['id' => 'note-11']]);

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'Q3 launch checklist',
			'Opportunities', 'opp-42',
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('note-11', $response->getData()['data']['id']);
	}

	public function testLinkDeckCardFallsBackToUrlWhenTitleEmpty(): void {
		// If the Deck card has no title (rare but possible for
		// freshly-created cards), the URL becomes the visible label.
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				$this->anything(), $this->anything(), $this->anything(),
				'Notes',
				$this->callback(function (array $attrs): bool {
					return str_contains($attrs['name'], 'https://nc.example.com/');
				}),
			)
			->willReturn(['data' => ['id' => 'x']]);

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'   ',
			'Opportunities', 'opp-1',
		);

		$this->assertSame(200, $response->getStatus());
	}

	public function testLinkDeckCardAppendsExtraNoteWhenProvided(): void {
		$controller = $this->makeController('alice');

		$this->apiService->expects($this->once())
			->method('createRecord')
			->with(
				$this->anything(), $this->anything(), $this->anything(),
				'Notes',
				$this->callback(function (array $attrs): bool {
					return str_contains($attrs['description'], 'Related to the summer campaign push.');
				}),
			)
			->willReturn(['data' => ['id' => 'x']]);

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'Launch',
			'Opportunities', 'opp-1',
			'Related to the summer campaign push.',
		);

		$this->assertSame(200, $response->getStatus());
	}

	public function testLinkDeckCardPropagatesSuiteCRMErrorAsBadGateway(): void {
		$controller = $this->makeController('alice');

		$this->apiService->method('createRecord')->willReturn([
			'error' => 'Bad payload',
		]);

		$response = $controller->linkDeckCard(
			'https://nc.example.com/apps/deck/#/board/1/card/2',
			'Launch',
			'Opportunities', 'opp-1',
		);

		$this->assertSame(502, $response->getStatus());
	}
}
