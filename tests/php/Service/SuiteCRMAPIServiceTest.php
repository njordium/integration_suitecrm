<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Service;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Iteration 42 — regression coverage for {@see SuiteCRMAPIService::search()}.
 *
 * Search has been the fork's most bug-prone method. Every prior fix was
 * caught by live testing against a real SuiteCRM install rather than by
 * CI, because SEARCH_MODULES is a private const and PHPStan doesn't have
 * anything to say about its contents. The tests below guard the invariants
 * each production bug taught us:
 *
 *   * Iter 18: filter must be pushed to SuiteCRM, not grepped client-side.
 *   * Iter 21 (Finding 1): the operator `contains` is not valid on
 *     SuiteCRM 8.x — SuiteCRM 8.10.1 responds `400 Filter operator contains
 *     is invalid`. `like` with explicit `%wildcards%` is the stable path.
 *   * Iter 21 (Finding 4): `full_name` is a non-db computed column on both
 *     Contacts and Leads — filtering by it silently matched zero rows. The
 *     real column to filter on is `last_name` (plus, since iter 35,
 *     `first_name`).
 *   * Iter 35 (Finding 25 follow-up): person modules now list both
 *     `last_name` and `first_name` in `name_attrs`, and search() fires one
 *     request per attribute + dedupes by `module|id`. A first-name-only
 *     query ("Serena") must return the Contact "Serena Arent".
 *   * Iter 36: the Emails module's `fields` list must not contain
 *     `date_sent` — that column does not exist on SuiteCRM 8's Email bean
 *     and requesting it responds 400.
 *   * Iter 35 error semantics: a single-attribute failure inside a module
 *     must not crash the whole search or spam the log — only if every
 *     attribute for the module errors do we emit a warning.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
class SuiteCRMAPIServiceTest extends TestCase {

	private LoggerInterface&MockObject $logger;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $client;
	private IUserManager&MockObject $userManager;
	private IL10N&MockObject $l10n;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private INotificationManager&MockObject $notificationManager;
	private TokenStorage&MockObject $tokens;
	private IAppManager&MockObject $appManager;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		// The SUT calls $clientService->newClient() in its constructor.
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->tokens = $this->createMock(TokenStorage::class);
		$this->appManager = $this->createMock(IAppManager::class);
	}

	/**
	 * Partial mock of SuiteCRMAPIService: real search(), stubbed request().
	 * The stub receives the exact endpoint string that search() would send
	 * to the SuiteCRM v8 API, letting each test assert on both the URL
	 * shape and the module-level flow.
	 *
	 * @return SuiteCRMAPIService&MockObject
	 */
	private function makeService(callable $requestStub): SuiteCRMAPIService&MockObject {
		$service = $this->getMockBuilder(SuiteCRMAPIService::class)
			->setConstructorArgs([
				'njordium_suitecrm',
				$this->userManager,
				$this->logger,
				$this->l10n,
				$this->config,
				$this->appConfig,
				$this->notificationManager,
				$this->clientService,
				$this->tokens,
				$this->appManager,
			])
			->onlyMethods(['request'])
			->getMock();
		$service->method('request')->willReturnCallback($requestStub);
		return $service;
	}

	/**
	 * Pull the filter attribute out of an endpoint URL. search() emits
	 * URLs of the form
	 *   module/Contacts?fields[Contacts]=name,first_name,...&filter%5Blast_name%5D%5Blike%5D=%25X%25
	 * and we need to distinguish "filtering ON last_name" from "requesting
	 * first_name as a returned FIELD". The filter clause is always
	 * URL-encoded because search() calls urlencode() on the bracketed key.
	 */
	private function extractFilterAttr(string $endpoint): ?string {
		if (preg_match('/filter%5B([a-z_]+)%5D%5Blike%5D/', $endpoint, $m)) {
			return $m[1];
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Behavioral tests via partial mock — guard search() flow.
	// ---------------------------------------------------------------------

	/**
	 * Iter 35 regression: first-name-only search must hit Contact records.
	 * Before iter 35 the Contacts filter was pinned to `last_name`, so
	 * typing "Serena" returned zero Contact hits even when "Serena Arent"
	 * existed as a Contact. Live-verified against the docker container
	 * earlier in this session; this test locks it in.
	 */
	public function testSearchByFirstNameHitsContacts(): void {
		$test = $this;
		$requestStub = function ($url, $token, $userId, $endpoint) use ($test) {
			$attr = $test->extractFilterAttr($endpoint);
			if (str_contains($endpoint, 'module/Contacts') && $attr === 'first_name') {
				return [
					'data' => [
						[
							'id' => 'contact-serena-uuid',
							'type' => 'Contact',
							'attributes' => [
								'name' => 'Serena Arent',
								'first_name' => 'Serena',
								'last_name' => 'Arent',
							],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->search('http://scrm.example', 'tok', 'alice', 'Serena', 0, 5);

		$this->assertCount(1, $results);
		$this->assertSame('contact-serena-uuid', $results[0]['id']);
		$this->assertSame('contact', $results[0]['type']);
	}

	/**
	 * The pre-iter-35 default (last-name filter) must still work — iter 35
	 * added a second attribute rather than swapping.
	 */
	public function testSearchByLastNameHitsContacts(): void {
		$test = $this;
		$requestStub = function ($url, $token, $userId, $endpoint) use ($test) {
			$attr = $test->extractFilterAttr($endpoint);
			if (str_contains($endpoint, 'module/Contacts') && $attr === 'last_name') {
				return [
					'data' => [
						[
							'id' => 'contact-arent-uuid',
							'type' => 'Contact',
							'attributes' => ['name' => 'Serena Arent'],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->search('http://scrm.example', 'tok', 'alice', 'Arent', 0, 5);

		$this->assertCount(1, $results);
		$this->assertSame('contact-arent-uuid', $results[0]['id']);
	}

	/**
	 * Iter 35 dedup: a Contact whose first_name AND last_name both match
	 * the substring must appear exactly once, not twice. Dedup key is
	 * `module|id`.
	 */
	public function testSameRecordAcrossAttributesIsDedupedToOne(): void {
		$requestStub = function ($url, $token, $userId, $endpoint) {
			if (str_contains($endpoint, 'module/Contacts')) {
				return [
					'data' => [
						[
							'id' => 'contact-dup-uuid',
							'type' => 'Contact',
							'attributes' => ['name' => 'X'],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->search('http://scrm.example', 'tok', 'alice', 'X', 0, 10);

		$contactRows = array_values(array_filter($results, fn ($r) => $r['type'] === 'contact'));
		$this->assertCount(1, $contactRows, 'Same contact must appear once, not twice');
		$this->assertSame('contact-dup-uuid', $contactRows[0]['id']);
	}

	/**
	 * Iter 35 error semantics — partial failure. If one of a module's
	 * attributes errors but the other returns cleanly, we log NOTHING.
	 * Otherwise every degraded-but-working custom schema would spam
	 * warnings on every search.
	 */
	public function testPartialAttributeErrorSuppressesWarning(): void {
		$test = $this;
		$requestStub = function ($url, $token, $userId, $endpoint) use ($test) {
			$attr = $test->extractFilterAttr($endpoint);
			if (str_contains($endpoint, 'module/Contacts') && $attr === 'first_name') {
				return ['error' => 'simulated 400'];
			}
			return ['data' => []];
		};

		$this->logger->expects($this->never())->method('warning');

		$service = $this->makeService($requestStub);
		$service->search('http://scrm.example', 'tok', 'alice', 'X', 0, 5);
	}

	/**
	 * Iter 35 error semantics — total failure. When EVERY attribute of a
	 * module errors, we DO emit a warning with the module name and the
	 * per-attribute errors, so a broken schema surfaces in the admin log
	 * rather than silently returning empty. Uses Emails (single attribute)
	 * as the total-failure case since one error = all attributes failed.
	 */
	public function testAllAttributesFailingLogsWarning(): void {
		$requestStub = function ($url, $token, $userId, $endpoint) {
			if (str_contains($endpoint, 'module/Emails')) {
				return ['error' => 'simulated 400 for Emails'];
			}
			return ['data' => []];
		};

		$this->logger->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('all name attributes rejected'),
				$this->callback(function ($ctx) {
					return isset($ctx['module']) && $ctx['module'] === 'Emails';
				})
			);

		$service = $this->makeService($requestStub);
		$service->search('http://scrm.example', 'tok', 'alice', 'X', 0, 5);
	}

	/**
	 * Iter 24 regression: the `like` operator requires explicit
	 * `%wildcards%` wrapping around the substring so mid-word matches work.
	 * URL-encoded, the % becomes %25, so a live URL should contain
	 * `%25<query>%25`.
	 */
	public function testSearchWrapsQueryWithWildcardsAndUrlEncodes(): void {
		$seenEndpoints = [];
		$requestStub = function ($url, $token, $userId, $endpoint) use (&$seenEndpoints) {
			$seenEndpoints[] = $endpoint;
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$service->search('http://scrm.example', 'tok', 'alice', 'cent', 0, 5);

		$this->assertNotEmpty($seenEndpoints, 'search() should fire at least one request');
		$matched = array_filter($seenEndpoints, fn ($e) => str_contains($e, '%25cent%25'));
		$this->assertNotEmpty(
			$matched,
			'Expected at least one request URL to contain URL-encoded %cent% (%25cent%25). Got: '
				. implode(' | ', $seenEndpoints),
		);
	}

	/**
	 * Iter 21 (Finding 1) regression: the operator must be `like`, not
	 * `contains`. SuiteCRM 8.10.1 rejects the latter with
	 * `400 Filter operator contains is invalid`.
	 */
	public function testSearchUsesLikeOperatorNotContains(): void {
		$seenEndpoints = [];
		$requestStub = function ($url, $token, $userId, $endpoint) use (&$seenEndpoints) {
			$seenEndpoints[] = $endpoint;
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$service->search('http://scrm.example', 'tok', 'alice', 'anything', 0, 5);

		foreach ($seenEndpoints as $endpoint) {
			$this->assertStringNotContainsString(
				'%5Bcontains%5D',
				$endpoint,
				'search() must not use [contains] — SuiteCRM 8.x rejects it. Endpoint: ' . $endpoint,
			);
			$this->assertStringContainsString(
				'%5Blike%5D',
				$endpoint,
				'search() must use [like] for the operator. Endpoint: ' . $endpoint,
			);
		}
	}

	/**
	 * Iter 35 requires each module row to declare the `name_attrs` array
	 * (renamed from the pre-iter-35 single-string `name_attr`). A future
	 * refactor could accidentally revert this — this test locks the shape.
	 */
	public function testEveryModuleRowDeclaresNameAttrsArray(): void {
		$searchModules = self::readSearchModules();

		foreach ($searchModules as $entry) {
			$this->assertArrayHasKey('name_attrs', $entry, "Module {$entry['module']} missing name_attrs");
			$this->assertIsArray($entry['name_attrs'], "Module {$entry['module']} name_attrs must be array");
			$this->assertNotEmpty($entry['name_attrs'], "Module {$entry['module']} name_attrs must be non-empty");
			$this->assertArrayNotHasKey(
				'name_attr',
				$entry,
				"Module {$entry['module']} still has the old singular name_attr — iter 35 removed it",
			);
		}
	}

	// ---------------------------------------------------------------------
	// Structural tests on SEARCH_MODULES — cheap, catch drift before the
	// URL is ever built.
	// ---------------------------------------------------------------------

	/**
	 * Iter 35 regression guard: person modules (Contacts, Leads) MUST list
	 * both last_name and first_name in `name_attrs` so a first-name-only
	 * query hits them. See iter 35 commit + Finding 25 audit trail.
	 */
	public function testContactsAndLeadsFilterOnBothNameHalves(): void {
		$searchModules = self::readSearchModules();
		foreach (['Contacts', 'Leads'] as $module) {
			$entry = self::findModule($searchModules, $module);
			$this->assertNotNull($entry, "$module must be present in SEARCH_MODULES");
			$this->assertContains(
				'last_name',
				$entry['name_attrs'],
				"$module must filter on last_name (iter 21 finding 4)",
			);
			$this->assertContains(
				'first_name',
				$entry['name_attrs'],
				"$module must filter on first_name (iter 35 finding 25 follow-up)",
			);
		}
	}

	/**
	 * Iter 36 regression guard: SuiteCRM 8's Email bean does not expose a
	 * `date_sent` column. Requesting it responds
	 * `400 The following field in Email module is not found: date_sent`.
	 * Iter 36 removed it from the Emails module's `fields` list.
	 */
	public function testEmailsFieldsListDoesNotIncludeDateSent(): void {
		$searchModules = self::readSearchModules();
		$emails = self::findModule($searchModules, 'Emails');
		$this->assertNotNull($emails, 'Emails must be in SEARCH_MODULES');
		$this->assertStringNotContainsString(
			'date_sent',
			$emails['fields'],
			'iter 36: Emails.fields must not contain date_sent — the column does not exist on SuiteCRM 8 Email',
		);
	}

	/**
	 * Iter 21 (Finding 4) regression guard: `full_name` must not appear as
	 * a `name_attrs` entry on any module. It's a computed column and
	 * filtering by it silently matches nothing on every SuiteCRM 8 install.
	 * (`full_name` is still fine as a returned FIELD in `fields` — we just
	 * can't filter on it.)
	 */
	public function testNoModuleFiltersByComputedFullName(): void {
		$searchModules = self::readSearchModules();
		foreach ($searchModules as $entry) {
			$this->assertNotContains(
				'full_name',
				$entry['name_attrs'],
				"Module {$entry['module']} must not filter by full_name (iter 21 finding 4)",
			);
		}
	}

	// ---------------------------------------------------------------------
	// Iter 52 — regression coverage for iter 50's getUpcoming() past-due
	// handling (upstream issue #8). Every test uses the partial-mock
	// pattern established for search() above so we can synthesise SuiteCRM
	// responses without hitting the wire.
	// ---------------------------------------------------------------------

	/**
	 * Iter 50: a past-due Meeting whose `status` is still `Planned` (the
	 * rep hasn't marked it Held) must appear in the widget's output with
	 * `is_overdue=true`. Before iter 50 the `date_start > now` filter
	 * silently dropped every such row.
	 */
	public function testGetUpcomingIncludesPastDueNotDispositionedMeeting(): void {
		$requestStub = function ($url, $token, $userId, $endpoint) {
			if (str_contains($endpoint, 'module/Meetings')) {
				return [
					'data' => [
						[
							'id' => 'meeting-past-uuid',
							'attributes' => [
								'name' => 'Discovery call with client',
								'date_start' => (new \DateTime('-2 days'))->format('Y-m-d\TH:i:s'),
								'status' => 'Planned',
							],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->getUpcoming('http://scrm.example', 'tok', 'alice', 7, 20, 30);

		$meetings = array_values(array_filter($results, fn ($r) => $r['type'] === 'meeting'));
		$this->assertCount(1, $meetings, 'past-due Planned meeting must be retained');
		$this->assertSame('meeting-past-uuid', $meetings[0]['id']);
		$this->assertTrue($meetings[0]['is_overdue']);
	}

	/**
	 * Iter 50: a past-due Meeting whose status is `Held` has been
	 * dispositioned; the widget must skip it (no nagging about resolved
	 * items).
	 */
	public function testGetUpcomingExcludesPastDueDispositionedMeeting(): void {
		$requestStub = function ($url, $token, $userId, $endpoint) {
			if (str_contains($endpoint, 'module/Meetings')) {
				return [
					'data' => [
						[
							'id' => 'meeting-held-uuid',
							'attributes' => [
								'name' => 'Old meeting the rep logged',
								'date_start' => (new \DateTime('-2 days'))->format('Y-m-d\TH:i:s'),
								'status' => 'Held',
							],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->getUpcoming('http://scrm.example', 'tok', 'alice', 7, 20, 30);

		$this->assertEmpty($results, 'Held meeting should be filtered out client-side');
	}

	/**
	 * Iter 50: a future-dated Meeting is always included regardless of
	 * status — the widget's job is to surface the schedule.
	 */
	public function testGetUpcomingIncludesFutureMeetingRegardlessOfStatus(): void {
		$requestStub = function ($url, $token, $userId, $endpoint) {
			if (str_contains($endpoint, 'module/Meetings')) {
				return [
					'data' => [
						[
							'id' => 'meeting-future-uuid',
							'attributes' => [
								'name' => 'Tomorrow standup',
								'date_start' => (new \DateTime('+1 day'))->format('Y-m-d\TH:i:s'),
								// Even 'Held' shouldn't dodge a future item, though
								// that combination is unusual.
								'status' => 'Held',
							],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->getUpcoming('http://scrm.example', 'tok', 'alice', 7, 20, 30);

		$meetings = array_values(array_filter($results, fn ($r) => $r['type'] === 'meeting'));
		$this->assertCount(1, $meetings);
		$this->assertFalse($meetings[0]['is_overdue']);
	}

	/**
	 * Iter 50: Task status vocabulary differs from Meetings/Calls. The
	 * SEARCH_MODULES entry lists `Not Started`, `In Progress`,
	 * `Pending Input` as still-actionable. `Completed` and `Deferred`
	 * disposition the row.
	 */
	public function testGetUpcomingHandlesTaskStatusVocabulary(): void {
		$requestStub = function ($url, $token, $userId, $endpoint) {
			if (str_contains($endpoint, 'module/Tasks')) {
				return [
					'data' => [
						[
							'id' => 'task-in-progress-uuid',
							'attributes' => [
								'name' => 'Draft the proposal',
								'date_due' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
								'status' => 'In Progress',
							],
						],
						[
							'id' => 'task-completed-uuid',
							'attributes' => [
								'name' => 'Already-done task',
								'date_due' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
								'status' => 'Completed',
							],
						],
					],
				];
			}
			return ['data' => []];
		};

		$service = $this->makeService($requestStub);
		$results = $service->getUpcoming('http://scrm.example', 'tok', 'alice', 7, 20, 30);

		$tasks = array_values(array_filter($results, fn ($r) => $r['type'] === 'task'));
		$this->assertCount(1, $tasks, 'Only the In Progress task should be retained');
		$this->assertSame('task-in-progress-uuid', $tasks[0]['id']);
		$this->assertTrue($tasks[0]['is_overdue']);
	}

	/**
	 * Iter 50 structural guard: every UPCOMING_MODULES row must declare
	 * `overdue_statuses` — the past-due filter path dereferences it
	 * unconditionally after iter 50b dropped the `?? []` fallback.
	 */
	public function testEveryUpcomingModuleRowDeclaresOverdueStatuses(): void {
		$upcomingModules = self::readUpcomingModules();

		foreach ($upcomingModules as $entry) {
			$this->assertArrayHasKey(
				'overdue_statuses',
				$entry,
				"Module {$entry['module']} missing overdue_statuses (iter 50)",
			);
			$this->assertIsArray($entry['overdue_statuses'], "Module {$entry['module']} overdue_statuses must be array");
			$this->assertNotEmpty(
				$entry['overdue_statuses'],
				"Module {$entry['module']} overdue_statuses must be non-empty — an empty list would mean 'never actionable'"
			);
		}
	}

	// ---------------------------------------------------------------------
	// Reflection helpers for the private const.
	// ---------------------------------------------------------------------

	private static function readSearchModules(): array {
		$refl = new \ReflectionClass(SuiteCRMAPIService::class);
		$constant = $refl->getReflectionConstant('SEARCH_MODULES');
		if ($constant === false) {
			throw new \RuntimeException('SUT no longer declares SEARCH_MODULES — search invariants broken');
		}
		return $constant->getValue();
	}

	private static function readUpcomingModules(): array {
		$refl = new \ReflectionClass(SuiteCRMAPIService::class);
		$constant = $refl->getReflectionConstant('UPCOMING_MODULES');
		if ($constant === false) {
			throw new \RuntimeException('SUT no longer declares UPCOMING_MODULES — calendar widget invariants broken');
		}
		return $constant->getValue();
	}

	private static function findModule(array $searchModules, string $module): ?array {
		foreach ($searchModules as $entry) {
			if (($entry['module'] ?? null) === $module) {
				return $entry;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Iter 68 — createRecord + linkRecord write-path coverage.
	//
	// Guards the JSON:API envelope shape and endpoint routing that all
	// four planned write features (Task from widget, Talk → Note,
	// Email → Case, Deck ↔ Opportunity) depend on. If SuiteCRM's
	// V8 API rejects our payload shape at any point, these tests are
	// where we notice — not in a production incident.
	// ---------------------------------------------------------------------

	public function testCreateRecordWrapsAttributesInJsonApiEnvelope(): void {
		$capturedEndpoint = null;
		$capturedParams = null;
		$capturedMethod = null;
		$capturedJsonBody = null;

		$service = $this->makeService(function (
			string $suitecrmUrl, string $accessToken, string $userId,
			string $endpoint, array $params, string $method,
			int $retryCount, bool $jsonBody,
		) use (&$capturedEndpoint, &$capturedParams, &$capturedMethod, &$capturedJsonBody) {
			$capturedEndpoint = $endpoint;
			$capturedParams = $params;
			$capturedMethod = $method;
			$capturedJsonBody = $jsonBody;
			return ['data' => ['type' => 'Tasks', 'id' => 'abc-123', 'attributes' => []]];
		});

		$result = $service->createRecord(
			'https://crm.example.com',
			'access-token-xyz',
			'alice',
			'Tasks',
			['name' => 'Follow up', 'description' => 'Called client', 'status' => 'Not Started'],
		);

		$this->assertSame('module/Tasks', $capturedEndpoint);
		$this->assertSame('POST', $capturedMethod);
		$this->assertTrue($capturedJsonBody, 'createRecord() must set $jsonBody=true so request() sends application/vnd.api+json');
		$this->assertSame([
			'data' => [
				'type' => 'Tasks',
				'attributes' => [
					'name' => 'Follow up',
					'description' => 'Called client',
					'status' => 'Not Started',
				],
			],
		], $capturedParams, 'createRecord() must wrap attributes in the JSON:API data/type/attributes envelope');
		$this->assertSame('abc-123', $result['data']['id']);
	}

	public function testCreateRecordUrlEncodesModuleName(): void {
		// SuiteCRM module names are all ASCII in practice, but the
		// endpoint construction must still guard against injection if a
		// future module name contains slashes or spaces.
		$capturedEndpoint = null;
		$service = $this->makeService(function (...$args) use (&$capturedEndpoint) {
			$capturedEndpoint = $args[3];
			return ['data' => []];
		});
		$service->createRecord('https://crm', 'tok', 'u', 'Weird Module/Name', []);
		$this->assertSame('module/Weird%20Module%2FName', $capturedEndpoint);
	}

	public function testCreateRecordPropagatesRequestErrorEnvelope(): void {
		// The write path must not swallow error envelopes — a failed
		// POST should surface the same {'error' => msg, 'body' => raw}
		// shape as failed reads, so controllers can render actionable
		// admin messages.
		$service = $this->makeService(fn (...$args) => [
			'error' => 'Bad credentials',
			'body' => '{"errors":[{"detail":"invalid token"}]}',
		]);
		$result = $service->createRecord('https://crm', 'tok', 'u', 'Tasks', []);
		$this->assertSame('Bad credentials', $result['error']);
		$this->assertStringContainsString('invalid token', $result['body']);
	}

	public function testLinkRecordBuildsRelationshipEndpoint(): void {
		$capturedEndpoint = null;
		$capturedParams = null;
		$capturedMethod = null;
		$capturedJsonBody = null;

		$service = $this->makeService(function (
			string $suitecrmUrl, string $accessToken, string $userId,
			string $endpoint, array $params, string $method,
			int $retryCount, bool $jsonBody,
		) use (&$capturedEndpoint, &$capturedParams, &$capturedMethod, &$capturedJsonBody) {
			$capturedEndpoint = $endpoint;
			$capturedParams = $params;
			$capturedMethod = $method;
			$capturedJsonBody = $jsonBody;
			return ['data' => []];
		});

		$service->linkRecord(
			'https://crm', 'tok', 'alice',
			'Meetings', 'meet-1',
			'contacts',
			'Contacts', 'contact-7',
		);

		$this->assertSame('module/Meetings/meet-1/relationships/contacts', $capturedEndpoint);
		$this->assertSame('POST', $capturedMethod);
		$this->assertTrue($capturedJsonBody);
		$this->assertSame([
			'data' => [
				'type' => 'Contacts',
				'id' => 'contact-7',
			],
		], $capturedParams, 'linkRecord() must send the resource-linkage envelope { data: { type, id } }');
	}

	public function testCreateRecordSignatureAcceptsEmptyAttributes(): void {
		// SuiteCRM 8.x will 400 on empty attributes for most modules,
		// but the SUT must let that happen — not pre-emptively refuse.
		// Users deserve the real API error message, not our guess.
		$service = $this->makeService(fn (...$args) => ['data' => ['id' => 'ok']]);
		$result = $service->createRecord('https://crm', 'tok', 'u', 'Tasks', []);
		$this->assertSame('ok', $result['data']['id']);
	}
}
