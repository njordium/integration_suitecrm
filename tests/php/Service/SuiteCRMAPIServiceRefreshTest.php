<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Service;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for the token-refresh retry inside
 * {@see SuiteCRMAPIService::request()}. The retry path is the single most
 * failure-prone branch in the whole app (it silently swallows 401s and
 * rewrites user tokens) and was previously untested.
 */
class SuiteCRMAPIServiceRefreshTest extends TestCase {

	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private IL10N&MockObject $l10n;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private INotificationManager&MockObject $notificationManager;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $client;
	private TokenStorage&MockObject $tokens;
	private IAppManager&MockObject $appManager;
	private SuiteCRMAPIService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->tokens = $this->createMock(TokenStorage::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->l10n->method('t')->willReturnArgument(0);
		$this->clientService->method('newClient')->willReturn($this->client);

		// Sensible defaults for the refresh path. Client_id/client_secret
		// resolution happens inside the catch block.
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'client_id' => 'cid',
				'client_secret' => 'csecret',
				default => $default,
			}
		);
		$this->tokens->method('getRefreshToken')->willReturn('rt-old');

		$this->service = new SuiteCRMAPIService(
			Application::APP_ID,
			$this->userManager,
			$this->logger,
			$this->l10n,
			$this->config,
			$this->appConfig,
			$this->notificationManager,
			$this->clientService,
			$this->tokens,
			$this->appManager,
		);
	}

	/**
	 * Happy path: first GET returns 401, refresh yields fresh tokens, second
	 * GET returns 200, and the decoded body bubbles out of the top-level call.
	 */
	public function testRefreshOnceThenSucceedsReturnsRetryResult(): void {
		$exception = new ClientException(
			'Unauthorized',
			new Psr7Request('GET', 'https://crm.example.com/Api/index.php/V8/module/Contacts'),
			new Psr7Response(401),
		);

		// Second (post-refresh) GET returns 200 with a JSON body.
		$okResponse = $this->createMock(IResponse::class);
		$okResponse->method('getStatusCode')->willReturn(200);
		$okResponse->method('getBody')->willReturn('{"data":[]}');

		$getCall = 0;
		$this->client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function () use (&$getCall, $exception, $okResponse) {
				$getCall++;
				if ($getCall === 1) {
					throw $exception;
				}
				return $okResponse;
			});

		// Refresh POST to /Api/access_token returns a valid token pair.
		$refreshResponse = $this->createMock(IResponse::class);
		$refreshResponse->method('getStatusCode')->willReturn(200);
		$refreshResponse->method('getBody')->willReturn(
			'{"access_token":"at-new","refresh_token":"rt-new"}'
		);
		$this->client->expects($this->once())
			->method('post')
			->willReturn($refreshResponse);

		$this->tokens->expects($this->once())->method('setAccessToken')->with('alice', 'at-new');
		$this->tokens->expects($this->once())->method('setRefreshToken')->with('alice', 'rt-new');

		$result = $this->service->request(
			'https://crm.example.com',
			'at-old',
			'alice',
			'module/Contacts',
		);

		$this->assertSame(['data' => []], $result);
	}

	/**
	 * Refresh itself fails (no access_token in the response). The outer
	 * request() returns an error payload rather than retrying blindly.
	 */
	public function testRefreshFailsReturnsErrorResult(): void {
		$exception = new ClientException(
			'Unauthorized',
			new Psr7Request('GET', 'https://crm.example.com/Api/index.php/V8/module/Contacts'),
			new Psr7Response(401),
		);
		$this->client->expects($this->once())
			->method('get')
			->willThrowException($exception);

		// Refresh POST returns a body without access_token/refresh_token.
		$refreshResponse = $this->createMock(IResponse::class);
		$refreshResponse->method('getStatusCode')->willReturn(200);
		$refreshResponse->method('getBody')->willReturn('{"error":"invalid_grant"}');
		$this->client->expects($this->once())
			->method('post')
			->willReturn($refreshResponse);

		$this->tokens->expects($this->never())->method('setAccessToken');
		$this->tokens->expects($this->never())->method('setRefreshToken');

		$result = $this->service->request(
			'https://crm.example.com',
			'at-old',
			'alice',
			'module/Contacts',
		);

		$this->assertArrayHasKey('error', $result);
	}

	/**
	 * Recursion guard: even if the retry request also 401s, the second
	 * pass sees retryCount === 1 and skips the refresh. Verified by
	 * exactly ONE POST to /Api/access_token across the whole call chain.
	 */
	public function testMaxRetryCountRespected(): void {
		$exception = new ClientException(
			'Unauthorized',
			new Psr7Request('GET', 'https://crm.example.com/Api/index.php/V8/module/Contacts'),
			new Psr7Response(401),
		);

		// Both GETs 401, but only the first one is allowed to trigger a
		// refresh because retryCount goes from 0 to 1 on the recursive call.
		$this->client->expects($this->exactly(2))
			->method('get')
			->willThrowException($exception);

		$refreshResponse = $this->createMock(IResponse::class);
		$refreshResponse->method('getStatusCode')->willReturn(200);
		$refreshResponse->method('getBody')->willReturn(
			'{"access_token":"at-new","refresh_token":"rt-new"}'
		);
		// The critical assertion: exactly ONE refresh POST, not two.
		$this->client->expects($this->once())
			->method('post')
			->willReturn($refreshResponse);

		$result = $this->service->request(
			'https://crm.example.com',
			'at-old',
			'alice',
			'module/Contacts',
		);

		$this->assertArrayHasKey('error', $result);
	}
}
