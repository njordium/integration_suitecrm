<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Controller;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Controller\ConfigController;
use OCA\SuiteCRM\Service\OAuthStateStore;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for {@see ConfigController::setConfig()}.
 *
 * A strict allowlist governs which user preferences the endpoint accepts;
 * without these tests a future refactor could silently reintroduce
 * arbitrary-preference writes from any authenticated user.
 *
 * Test fixture note: ConfigController's constructor now carries a
 * LoggerInterface dependency (argument #10, one slot before the trailing
 * ?string $userId). The setUp() and makeController() helpers below wire
 * ten mocks; an earlier nine-mock wiring caused a TypeError in CI because
 * the userId string was landing on the LoggerInterface slot.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
class ConfigControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private SuiteCRMAPIService&MockObject $apiService;
	private TokenStorage&MockObject $tokens;
	private OAuthStateStore&MockObject $stateStore;
	private IURLGenerator&MockObject $urlGenerator;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->apiService = $this->createMock(SuiteCRMAPIService::class);
		$this->tokens = $this->createMock(TokenStorage::class);
		$this->stateStore = $this->createMock(OAuthStateStore::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function makeController(?string $userId): ConfigController {
		return new ConfigController(
			Application::APP_ID,
			$this->request,
			$this->config,
			$this->appConfig,
			$this->apiService,
			$this->tokens,
			$this->stateStore,
			$this->urlGenerator,
			$this->userSession,
			$this->logger,
			$userId,
		);
	}

	/**
	 * The allowlist (user_name, search_enabled, notification_enabled) must
	 * drop any other key silently. `admin_secret_hack` is the canonical
	 * would-be attacker payload from Finding 5.
	 */
	public function testSetConfigRejectsUnallowedKeys(): void {
		$seenKeys = [];
		$this->config->expects($this->atLeastOnce())
			->method('setUserValue')
			->willReturnCallback(function ($uid, $app, $key, $value) use (&$seenKeys) {
				$this->assertSame('alice', $uid);
				$this->assertSame(Application::APP_ID, $app);
				$seenKeys[] = $key;
			});

		$controller = $this->makeController('alice');
		$response = $controller->setConfig([
			'admin_secret_hack' => 'p0wn3d',
			'user_name' => 'bob',
			'search_enabled' => '1',
			'notification_enabled' => '0',
			'random_row' => 'nope',
		]);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertNotContains('admin_secret_hack', $seenKeys);
		$this->assertNotContains('random_row', $seenKeys);
		foreach ($seenKeys as $key) {
			$this->assertContains($key, ['user_name', 'search_enabled', 'notification_enabled']);
		}
	}

	/**
	 * IConfig::setUserValue is typed `string` on NC 29+; NcCheckboxRadioSwitch
	 * can emit bool/int, so the controller casts. Verify every received
	 * value is a native string.
	 */
	public function testSetConfigCastsValuesToString(): void {
		$this->config->expects($this->atLeastOnce())
			->method('setUserValue')
			->willReturnCallback(function ($uid, $app, $key, $value) {
				$this->assertIsString($value, "setUserValue for $key was passed a " . gettype($value));
			});

		$controller = $this->makeController('alice');
		$controller->setConfig([
			'search_enabled' => true,
			'notification_enabled' => 0,
			'user_name' => 'bob',
		]);
	}

	/**
	 * Without a session the endpoint must refuse; regression against the
	 * previous version that dereferenced a null userId in the loop.
	 */
	public function testSetConfigRequiresSession(): void {
		$this->config->expects($this->never())->method('setUserValue');

		$controller = $this->makeController(null);
		$response = $controller->setConfig(['user_name' => 'bob']);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(401, $response->getStatus());
	}

	/**
	 * The admin "Reset connection" button fires DELETE /admin-config,
	 * which must call IAppConfig::deleteKey() for each of the four
	 * admin-scoped keys (oauth_instance_url, client_id, client_secret,
	 * oauth_authorize_path), and no others. Also verifies the controller
	 * writes an info-level log line so a session grep can distinguish
	 * "admin used the reset button" from an occ-driven config wipe.
	 */
	public function testResetAdminConfigDeletesAllExpectedKeys(): void {
		$deleted = [];
		$this->appConfig->expects($this->exactly(4))
			->method('deleteKey')
			->willReturnCallback(function ($app, $key) use (&$deleted) {
				$this->assertSame(Application::APP_ID, $app);
				$deleted[] = $key;
				return true;
			});

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('admin config reset'),
				$this->callback(function ($ctx) {
					return isset($ctx['app']) && $ctx['app'] === Application::APP_ID;
				})
			);

		$controller = $this->makeController('admin');
		$response = $controller->resetAdminConfig();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['oauth_instance_url', 'client_id', 'client_secret', 'oauth_authorize_path'], $deleted);
	}

	/**
	 * Empty user_name is the "log out" signal from the personal settings UI.
	 * Verify the disconnect chain: HTTP logout request, token clear, and a
	 * response body advertising the cleared state.
	 */
	public function testEmptyUserNameTriggersLogout(): void {
		$this->tokens->method('getAccessToken')->willReturn('at');
		$this->appConfig->method('getValueString')
			->willReturn('https://crm.example.com');

		$this->apiService->expects($this->once())
			->method('request')
			->with(
				'https://crm.example.com',
				'at',
				'alice',
				'logout',
				[],
				'POST',
			)
			->willReturn([]);

		$this->tokens->expects($this->once())->method('clear')->with('alice');

		$controller = $this->makeController('alice');
		$response = $controller->setConfig(['user_name' => '']);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(['user_name' => ''], $response->getData());
	}
}
