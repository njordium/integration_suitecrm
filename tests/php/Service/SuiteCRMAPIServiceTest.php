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

		// v2.1.1 hotfix: creation endpoint is `module` (no module
		// suffix) — the module name is in `data.type` of the JSON:API
		// payload. See createRecord() docblock for the SuiteCRM 8.10.x
		// route-registration reasoning.
		$this->assertSame('module', $capturedEndpoint);
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

	public function testCreateRecordEndpointIsAlwaysSuffixlessModule(): void {
		// v2.1.1: with the JSON:API-compliant creation endpoint the
		// module name never appears in the URL path — it lives in
		// `data.type`. Weird module names therefore can't inject via
		// the URL. This is a defensive check to guard the invariant.
		$capturedEndpoint = null;
		$capturedType = null;
		$service = $this->makeService(function (...$args) use (&$capturedEndpoint, &$capturedType) {
			$capturedEndpoint = $args[3];
			$capturedType = $args[4]['data']['type'] ?? null;
			return ['data' => []];
		});
		$service->createRecord('https://crm', 'tok', 'u', 'Weird Module/Name', []);
		$this->assertSame('module', $capturedEndpoint);
		$this->assertSame('Weird Module/Name', $capturedType);
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

	// ---------------------------------------------------------------------
	// Iter 75 — regression coverage for getMyCases().
	//
	// getMyCases() backs the "My open Cases" dashboard widget. Invariants:
	//   * Cases with terminal statuses (Closed / Rejected / Duplicate)
	//     are filtered out client-side because SuiteCRM 8.10.x's JSON:API
	//     filter surface has no reliable NOT-IN operator (iter 24 finding
	//     applies here too).
	//   * Priority ordering follows PRIORITY_ORDER: P1/High first,
	//     P2/Medium next, P3/Low last, unknown values sort last but
	//     don't crash. Both label sets are covered because SuiteCRM
	//     installs are inconsistent about which they ship.
	//   * Within a priority tier, older Cases (larger age_days) come
	//     first — the rep should notice long-open Cases before newly
	//     opened ones.
	//   * If the caller hasn't stored their SuiteCRM user_id (fresh
	//     install, unlinked account), we return [] instead of firing an
	//     unscoped query that would return every Case in the tenant.
	//   * Upstream error envelopes propagate unchanged so the controller
	//     can render an actionable admin message.
	// ---------------------------------------------------------------------

	/**
	 * Wire the config mock so getUserValue('u', app, 'user_id') returns
	 * the given SuiteCRM user id. All getMyCases tests need this because
	 * an empty user id short-circuits the method (safety guard against
	 * fetching every Case in the tenant).
	 */
	private function stubSuiteCRMUserId(string $suiteUserId): void {
		$this->config->method('getUserValue')->willReturn($suiteUserId);
	}

	public function testGetMyCasesReturnsEmptyWhenNoSuiteCRMUserIdStored(): void {
		// Safety guard: without a stored SuiteCRM user_id we cannot
		// filter by `assigned_user_id`, so the query would return every
		// Case in the tenant. The service returns [] instead — the
		// controller renders it as an empty widget.
		$requestCalls = 0;
		$service = $this->makeService(function () use (&$requestCalls) {
			$requestCalls++;
			return ['data' => []];
		});
		$result = $service->getMyCases('https://crm', 'tok', 'alice', 20);
		$this->assertSame([], $result);
		$this->assertSame(0, $requestCalls, 'unscoped Case query must never fire');
	}

	public function testGetMyCasesFiltersOutTerminalStatuses(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'case-open',
						'attributes' => [
							'name' => 'Login issue',
							'case_number' => '101',
							'priority' => 'P2',
							'status' => 'New',
							'date_entered' => (new \DateTime('-3 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-closed',
						'attributes' => [
							'name' => 'Old resolved bug',
							'case_number' => '99',
							'priority' => 'P1',
							'status' => 'Closed',
							'date_entered' => (new \DateTime('-30 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-rejected',
						'attributes' => [
							'name' => 'Not our problem',
							'case_number' => '98',
							'priority' => 'P1',
							'status' => 'Rejected',
							'date_entered' => (new \DateTime('-15 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-duplicate',
						'attributes' => [
							'name' => 'Duplicate of 101',
							'case_number' => '102',
							'priority' => 'P2',
							'status' => 'Duplicate',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyCases('https://crm', 'tok', 'alice', 20);

		$this->assertCount(1, $results, 'only the New Case should survive the terminal-status filter');
		$this->assertSame('case-open', $results[0]['id']);
	}

	public function testGetMyCasesSortsByPriorityThenAge(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'case-p2-old',
						'attributes' => [
							'name' => 'P2 case, older',
							'priority' => 'P2',
							'status' => 'Assigned',
							'date_entered' => (new \DateTime('-10 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-p1-fresh',
						'attributes' => [
							'name' => 'P1 case, fresh',
							'priority' => 'P1',
							'status' => 'New',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-p1-old',
						'attributes' => [
							'name' => 'P1 case, older',
							'priority' => 'P1',
							'status' => 'New',
							'date_entered' => (new \DateTime('-5 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-p3-fresh',
						'attributes' => [
							'name' => 'P3 case, fresh',
							'priority' => 'P3',
							'status' => 'Pending Input',
							'date_entered' => (new \DateTime('-2 days'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyCases('https://crm', 'tok', 'alice', 20);

		$this->assertCount(4, $results);
		// P1 tier first, older-of-the-two ahead of fresher.
		$this->assertSame('case-p1-old', $results[0]['id']);
		$this->assertSame('case-p1-fresh', $results[1]['id']);
		// Then P2, then P3.
		$this->assertSame('case-p2-old', $results[2]['id']);
		$this->assertSame('case-p3-fresh', $results[3]['id']);
	}

	public function testGetMyCasesHandlesHighMediumLowLabelSet(): void {
		// SuiteCRM 8 installs are inconsistent about Case priority
		// labels — stock English ships P1/P2/P3, but relabelled installs
		// use High/Medium/Low. PRIORITY_ORDER weights both so widgets
		// sort predictably regardless of the tenant's labelling choice.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'case-medium',
						'attributes' => [
							'name' => 'Medium priority case',
							'priority' => 'Medium',
							'status' => 'New',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-high',
						'attributes' => [
							'name' => 'High priority case',
							'priority' => 'High',
							'status' => 'New',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-low',
						'attributes' => [
							'name' => 'Low priority case',
							'priority' => 'Low',
							'status' => 'New',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyCases('https://crm', 'tok', 'alice', 20);

		$this->assertSame(['case-high', 'case-medium', 'case-low'], array_column($results, 'id'));
	}

	public function testGetMyCasesUnknownPrioritySortsLast(): void {
		// A Studio-customised install could ship priority values that
		// aren't in PRIORITY_ORDER. Unknown values shouldn't crash;
		// they should sort behind the known tiers so the rep still sees
		// their P1/High Cases first.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'case-weird',
						'attributes' => [
							'name' => 'Studio-custom priority',
							'priority' => 'Blocker',
							'status' => 'New',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'case-p3',
						'attributes' => [
							'name' => 'Standard P3',
							'priority' => 'P3',
							'status' => 'New',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyCases('https://crm', 'tok', 'alice', 20);

		$this->assertSame(['case-p3', 'case-weird'], array_column($results, 'id'));
	}

	public function testGetMyCasesRespectsLimit(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			$data = [];
			for ($i = 0; $i < 30; $i++) {
				$data[] = [
					'id' => 'case-' . $i,
					'attributes' => [
						'name' => 'Case ' . $i,
						'priority' => 'P2',
						'status' => 'New',
						'date_entered' => (new \DateTime('-' . ($i + 1) . ' days'))->format('Y-m-d\TH:i:s'),
					],
				];
			}
			return ['data' => $data];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyCases('https://crm', 'tok', 'alice', 7);
		$this->assertCount(7, $results, 'limit must cap the result set after client-side sort');
	}

	public function testGetMyCasesPropagatesUpstreamErrorEnvelope(): void {
		// A read failure must surface the same error shape as writes —
		// the widget renders "Error connecting to SuiteCRM" only if
		// the payload has an `error` key.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$service = $this->makeService(fn () => [
			'error' => 'Bad credentials',
			'body' => '{"errors":[{"detail":"invalid token"}]}',
		]);
		$result = $service->getMyCases('https://crm', 'tok', 'alice', 20);
		$this->assertArrayHasKey('error', $result);
		$this->assertSame('Bad credentials', $result['error']);
	}

	// ---------------------------------------------------------------------
	// Iter 76 — regression coverage for getMyTasks().
	//
	// Invariants:
	//   * Terminal statuses (Completed / Deferred) are filtered out
	//     client-side. Actionable: Not Started / In Progress / Pending Input.
	//   * Priority sort follows PRIORITY_ORDER (shared with getMyCases()).
	//   * Within a priority tier, dated Tasks sort by due_ts ASC (earliest
	//     due first). Undated Tasks (date_due empty or malformed) fall
	//     LAST within the tier — a dated Task carries an urgency signal
	//     an undated one doesn't.
	//   * Between two undated Tasks at the same priority, date_entered
	//     is the stable tiebreaker — older created first.
	//   * Empty-user-id safety guard (same reasoning as getMyCases).
	//   * Upstream error envelope propagates unchanged.
	// ---------------------------------------------------------------------

	public function testGetMyTasksReturnsEmptyWhenNoSuiteCRMUserIdStored(): void {
		$requestCalls = 0;
		$service = $this->makeService(function () use (&$requestCalls) {
			$requestCalls++;
			return ['data' => []];
		});
		$result = $service->getMyTasks('https://crm', 'tok', 'alice', 20);
		$this->assertSame([], $result);
		$this->assertSame(0, $requestCalls, 'unscoped Task query must never fire');
	}

	public function testGetMyTasksFiltersOutTerminalStatuses(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'task-open-in-progress',
						'attributes' => [
							'name' => 'Draft proposal',
							'priority' => 'High',
							'status' => 'In Progress',
							'date_due' => (new \DateTime('+2 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-5 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-open-not-started',
						'attributes' => [
							'name' => 'Schedule review',
							'priority' => 'Medium',
							'status' => 'Not Started',
							'date_due' => (new \DateTime('+5 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-open-pending',
						'attributes' => [
							'name' => 'Awaiting client sign-off',
							'priority' => 'Medium',
							'status' => 'Pending Input',
							'date_due' => (new \DateTime('+10 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-3 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-completed',
						'attributes' => [
							'name' => 'Old finished task',
							'priority' => 'High',
							'status' => 'Completed',
							'date_due' => (new \DateTime('-2 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-20 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-deferred',
						'attributes' => [
							'name' => 'Deliberately skipped',
							'priority' => 'Low',
							'status' => 'Deferred',
							'date_due' => (new \DateTime('+30 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 20);

		$ids = array_column($results, 'id');
		$this->assertContains('task-open-in-progress', $ids);
		$this->assertContains('task-open-not-started', $ids);
		$this->assertContains('task-open-pending', $ids);
		$this->assertNotContains('task-completed', $ids);
		$this->assertNotContains('task-deferred', $ids);
	}

	public function testGetMyTasksSortsByPriorityThenDueDate(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'task-medium-due-tomorrow',
						'attributes' => [
							'name' => 'M, due tomorrow',
							'priority' => 'Medium',
							'status' => 'Not Started',
							'date_due' => (new \DateTime('+1 day'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-high-due-next-week',
						'attributes' => [
							'name' => 'H, due next week',
							'priority' => 'High',
							'status' => 'Not Started',
							'date_due' => (new \DateTime('+7 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-high-due-tomorrow',
						'attributes' => [
							'name' => 'H, due tomorrow',
							'priority' => 'High',
							'status' => 'In Progress',
							'date_due' => (new \DateTime('+1 day'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 20);

		$this->assertSame(
			['task-high-due-tomorrow', 'task-high-due-next-week', 'task-medium-due-tomorrow'],
			array_column($results, 'id'),
		);
	}

	public function testGetMyTasksSortsUndatedTasksLastWithinPriorityTier(): void {
		// A dated Task at High carries an urgency signal an undated
		// High Task doesn't — the undated one must sort last within
		// the tier.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'task-high-undated',
						'attributes' => [
							'name' => 'High no due date',
							'priority' => 'High',
							'status' => 'Not Started',
							'date_due' => '',
							'date_entered' => (new \DateTime('-2 days'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-high-dated',
						'attributes' => [
							'name' => 'High with due date',
							'priority' => 'High',
							'status' => 'Not Started',
							'date_due' => (new \DateTime('+3 days'))->format('Y-m-d\TH:i:s'),
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 20);

		$this->assertSame(['task-high-dated', 'task-high-undated'], array_column($results, 'id'));
		$this->assertNull($results[1]['due_ts'], 'undated Task must expose due_ts=null');
	}

	public function testGetMyTasksBreaksUndatedTiesByCreationDate(): void {
		// Two undated Tasks at the same priority — the older creation
		// wins so an old forgotten Task surfaces above a fresh
		// undated one.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'task-undated-fresh',
						'attributes' => [
							'name' => 'Fresh undated',
							'priority' => 'Medium',
							'status' => 'Not Started',
							'date_due' => '',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
					[
						'id' => 'task-undated-old',
						'attributes' => [
							'name' => 'Old undated',
							'priority' => 'Medium',
							'status' => 'Not Started',
							'date_due' => '',
							'date_entered' => (new \DateTime('-30 days'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 20);

		$this->assertSame(['task-undated-old', 'task-undated-fresh'], array_column($results, 'id'));
	}

	public function testGetMyTasksTagsRowsWithTypeAndDueTs(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$dueDate = (new \DateTime('+3 days'))->format('Y-m-d\TH:i:s');
		$requestStub = function () use ($dueDate) {
			return [
				'data' => [
					[
						'id' => 'task-1',
						'attributes' => [
							'name' => 'Tagged',
							'priority' => 'High',
							'status' => 'Not Started',
							'date_due' => $dueDate,
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 20);

		$this->assertCount(1, $results);
		$this->assertSame('task', $results[0]['type']);
		$this->assertSame(1, $results[0]['priority_rank']);
		$this->assertNotNull($results[0]['due_ts']);
		$this->assertSame((new \DateTime($dueDate))->getTimestamp(), $results[0]['due_ts']);
	}

	public function testGetMyTasksHandlesMalformedDueDateAsUndated(): void {
		// Studio-customised installs occasionally return non-ISO strings.
		// A parse failure must not crash the sort — the row falls through
		// to the undated tier.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'task-bad-date',
						'attributes' => [
							'name' => 'Malformed date',
							'priority' => 'Medium',
							'status' => 'Not Started',
							'date_due' => 'not-a-date',
							'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 20);

		$this->assertCount(1, $results);
		$this->assertNull($results[0]['due_ts']);
	}

	public function testGetMyTasksPropagatesUpstreamErrorEnvelope(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$service = $this->makeService(fn () => [
			'error' => 'Bad credentials',
			'body' => '{"errors":[{"detail":"invalid token"}]}',
		]);
		$result = $service->getMyTasks('https://crm', 'tok', 'alice', 20);
		$this->assertArrayHasKey('error', $result);
		$this->assertSame('Bad credentials', $result['error']);
	}

	public function testGetMyTasksRespectsLimit(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			$data = [];
			for ($i = 0; $i < 25; $i++) {
				$data[] = [
					'id' => 'task-' . $i,
					'attributes' => [
						'name' => 'Task ' . $i,
						'priority' => 'Medium',
						'status' => 'Not Started',
						'date_due' => (new \DateTime('+' . ($i + 1) . ' days'))->format('Y-m-d\TH:i:s'),
						'date_entered' => (new \DateTime('-1 day'))->format('Y-m-d\TH:i:s'),
					],
				];
			}
			return ['data' => $data];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyTasks('https://crm', 'tok', 'alice', 7);
		$this->assertCount(7, $results);
	}

	// ---------------------------------------------------------------------
	// Iter 77 — regression coverage for getMyPipeline().
	//
	// Invariants:
	//   * All three modes filter out terminal sales_stages (Closed Won,
	//     Closed Lost) client-side.
	//   * closing_quarter mode further filters to close_date within the
	//     current calendar quarter and sorts by close_date ASC.
	//   * top_value mode sorts by amount DESC across all open Opportunities
	//     regardless of close_date (including deals with empty close_date).
	//   * weighted mode sorts by amount × probability/100 DESC.
	//   * Unknown mode strings snap to DEFAULT_PIPELINE_MODE rather than
	//     crashing — old bookmarks, hand-edited preferences, or Vue's
	//     v-model returning stale value shouldn't kill the widget.
	//   * Empty-user-id safety guard (same reasoning as getMyCases).
	//   * Rows tagged with type='opportunity', close_ts (int|null),
	//     amount_num (float), probability_num (float), weighted_num (float).
	// ---------------------------------------------------------------------

	public function testGetMyPipelineReturnsEmptyWhenNoSuiteCRMUserIdStored(): void {
		$requestCalls = 0;
		$service = $this->makeService(function () use (&$requestCalls) {
			$requestCalls++;
			return ['data' => []];
		});
		$result = $service->getMyPipeline('https://crm', 'tok', 'alice', 'closing_quarter', 20);
		$this->assertSame([], $result);
		$this->assertSame(0, $requestCalls);
	}

	public function testGetMyPipelineFiltersOutTerminalSalesStages(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			$thisQuarter = (new \DateTime())->format('Y-m-d');
			return [
				'data' => [
					[
						'id' => 'opp-open',
						'attributes' => [
							'name' => 'Open deal',
							'amount' => '10000',
							'probability' => '50',
							'sales_stage' => 'Negotiation/Review',
							'close_date' => $thisQuarter,
							'currency_symbol' => '$',
						],
					],
					[
						'id' => 'opp-won',
						'attributes' => [
							'name' => 'Already-won deal',
							'amount' => '50000',
							'probability' => '100',
							'sales_stage' => 'Closed Won',
							'close_date' => $thisQuarter,
							'currency_symbol' => '$',
						],
					],
					[
						'id' => 'opp-lost',
						'attributes' => [
							'name' => 'Lost deal',
							'amount' => '20000',
							'probability' => '0',
							'sales_stage' => 'Closed Lost',
							'close_date' => $thisQuarter,
							'currency_symbol' => '$',
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'top_value', 20);

		$ids = array_column($results, 'id');
		$this->assertContains('opp-open', $ids);
		$this->assertNotContains('opp-won', $ids);
		$this->assertNotContains('opp-lost', $ids);
	}

	public function testGetMyPipelineTopValueModeSortsByAmountDesc(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'opp-small',
						'attributes' => [
							'name' => 'Small',
							'amount' => '5000',
							'probability' => '90',
							'sales_stage' => 'Proposal/Price Quote',
							'close_date' => (new \DateTime('+10 years'))->format('Y-m-d'),
						],
					],
					[
						'id' => 'opp-huge',
						'attributes' => [
							'name' => 'Huge',
							'amount' => '500000',
							'probability' => '20',
							'sales_stage' => 'Prospecting',
							'close_date' => (new \DateTime('+10 years'))->format('Y-m-d'),
						],
					],
					[
						'id' => 'opp-medium',
						'attributes' => [
							'name' => 'Medium',
							'amount' => '50000',
							'probability' => '50',
							'sales_stage' => 'Qualification',
							'close_date' => (new \DateTime('+10 years'))->format('Y-m-d'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'top_value', 20);

		$this->assertSame(['opp-huge', 'opp-medium', 'opp-small'], array_column($results, 'id'));
	}

	public function testGetMyPipelineWeightedModeSortsByWeightedValue(): void {
		// weighted = amount × probability / 100.
		// $500k × 20% = $100k     (huge deal, low probability)
		// $50k × 90%  = $45k      (medium deal, high probability)
		// $5k × 100%  = $5k       (small deal, guaranteed)
		// Expected order: huge ($100k weighted) > medium ($45k) > small ($5k).
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'opp-small-certain',
						'attributes' => [
							'name' => 'Small guaranteed',
							'amount' => '5000',
							'probability' => '100',
							'sales_stage' => 'Proposal/Price Quote',
						],
					],
					[
						'id' => 'opp-medium-likely',
						'attributes' => [
							'name' => 'Medium likely',
							'amount' => '50000',
							'probability' => '90',
							'sales_stage' => 'Negotiation/Review',
						],
					],
					[
						'id' => 'opp-huge-longshot',
						'attributes' => [
							'name' => 'Huge longshot',
							'amount' => '500000',
							'probability' => '20',
							'sales_stage' => 'Prospecting',
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'weighted', 20);

		$this->assertSame(
			['opp-huge-longshot', 'opp-medium-likely', 'opp-small-certain'],
			array_column($results, 'id'),
		);
		$this->assertEqualsWithDelta(100000.0, $results[0]['weighted_num'], 0.01);
		$this->assertEqualsWithDelta(45000.0, $results[1]['weighted_num'], 0.01);
		$this->assertEqualsWithDelta(5000.0, $results[2]['weighted_num'], 0.01);
	}

	public function testGetMyPipelineClosingQuarterFiltersByQuarterWindow(): void {
		// closing_quarter mode drops rows whose close_date isn't in the
		// current calendar quarter. Rows without a close_date are also
		// dropped from this mode entirely.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'opp-in-quarter',
						'attributes' => [
							'name' => 'In-quarter deal',
							'amount' => '10000',
							'probability' => '50',
							'sales_stage' => 'Qualification',
							'close_date' => (new \DateTime())->format('Y-m-d'),
						],
					],
					[
						'id' => 'opp-far-future',
						'attributes' => [
							'name' => 'Far future deal',
							'amount' => '100000',
							'probability' => '50',
							'sales_stage' => 'Qualification',
							'close_date' => (new \DateTime('+2 years'))->format('Y-m-d'),
						],
					],
					[
						'id' => 'opp-undated',
						'attributes' => [
							'name' => 'Undated deal',
							'amount' => '50000',
							'probability' => '50',
							'sales_stage' => 'Qualification',
							'close_date' => '',
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'closing_quarter', 20);

		$ids = array_column($results, 'id');
		$this->assertContains('opp-in-quarter', $ids);
		$this->assertNotContains('opp-far-future', $ids, 'far-future close_date excluded from closing_quarter mode');
		$this->assertNotContains('opp-undated', $ids, 'undated deals excluded from closing_quarter mode');
	}

	public function testGetMyPipelineClosingQuarterSortsByCloseDateAsc(): void {
		// Build two close dates guaranteed to be in the current quarter
		// regardless of when this test runs: pick the middle two months of
		// the current quarter and use the 15th of each. That avoids the
		// quarter-boundary edge cases the earlier version of this test
		// left unguarded (which PHPUnit's failOnRisky flagged as a test
		// with no assertions on first-of-quarter days).
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$now = new \DateTime();
		$month = (int) $now->format('n');
		$year = (int) $now->format('Y');
		$quarterFirstMonth = ((int) floor(($month - 1) / 3)) * 3 + 1;
		$midMonth = $quarterFirstMonth + 1;
		$laterMonth = $quarterFirstMonth + 2;
		$earlierDate = sprintf('%04d-%02d-15', $year, $midMonth);
		$laterDate = sprintf('%04d-%02d-15', $year, $laterMonth);

		$requestStub = function () use ($earlierDate, $laterDate) {
			return [
				'data' => [
					[
						'id' => 'opp-later',
						'attributes' => [
							'name' => 'Closes later',
							'amount' => '10000',
							'probability' => '50',
							'sales_stage' => 'Negotiation/Review',
							'close_date' => $laterDate,
						],
					],
					[
						'id' => 'opp-earlier',
						'attributes' => [
							'name' => 'Closes earlier',
							'amount' => '10000',
							'probability' => '50',
							'sales_stage' => 'Negotiation/Review',
							'close_date' => $earlierDate,
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'closing_quarter', 20);

		$this->assertCount(2, $results);
		$this->assertSame('opp-earlier', $results[0]['id']);
		$this->assertSame('opp-later', $results[1]['id']);
	}

	public function testGetMyPipelineUnknownModeFallsBackToDefault(): void {
		// A garbled preference value must not crash the widget. Since
		// closing_quarter is the default it applies the quarter-window
		// filter — an undated row would be dropped even though the
		// caller passed 'not_a_real_mode'.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'opp-undated',
						'attributes' => [
							'name' => 'Undated deal',
							'amount' => '10000',
							'probability' => '50',
							'sales_stage' => 'Qualification',
							'close_date' => '',
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'not_a_real_mode', 20);
		// Undated deal should be dropped because the fallback was
		// closing_quarter, which filters undated deals out.
		$this->assertSame([], $results);
	}

	public function testGetMyPipelineTagsRows(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'opp-1',
						'attributes' => [
							'name' => 'Tagged',
							'amount' => '75000',
							'probability' => '40',
							'sales_stage' => 'Proposal/Price Quote',
							'close_date' => (new \DateTime('+1 year'))->format('Y-m-d'),
							'currency_symbol' => '$',
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'top_value', 20);

		$this->assertCount(1, $results);
		$this->assertSame('opportunity', $results[0]['type']);
		$this->assertEqualsWithDelta(75000.0, $results[0]['amount_num'], 0.01);
		$this->assertEqualsWithDelta(40.0, $results[0]['probability_num'], 0.01);
		$this->assertEqualsWithDelta(30000.0, $results[0]['weighted_num'], 0.01);
		$this->assertNotNull($results[0]['close_ts']);
	}

	public function testGetMyPipelinePropagatesUpstreamErrorEnvelope(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$service = $this->makeService(fn () => [
			'error' => 'Bad credentials',
			'body' => '{"errors":[{"detail":"invalid token"}]}',
		]);
		$result = $service->getMyPipeline('https://crm', 'tok', 'alice', 'top_value', 20);
		$this->assertArrayHasKey('error', $result);
		$this->assertSame('Bad credentials', $result['error']);
	}

	public function testGetMyPipelineRespectsLimit(): void {
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			$data = [];
			for ($i = 0; $i < 30; $i++) {
				$data[] = [
					'id' => 'opp-' . $i,
					'attributes' => [
						'name' => 'Deal ' . $i,
						'amount' => (string) (($i + 1) * 1000),
						'probability' => '50',
						'sales_stage' => 'Qualification',
					],
				];
			}
			return ['data' => $data];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyPipeline('https://crm', 'tok', 'alice', 'top_value', 7);
		$this->assertCount(7, $results);
	}

	public function testGetMyCasesTagsRowsWithTypeAndAgeAndPriorityRank(): void {
		// Rows must carry `type='case'`, `age_days` (int), and
		// `priority_rank` (int) — the widget uses these to render the
		// icon, the "N days open" subline, and the sort key for
		// server-side dashboard rendering.
		$this->stubSuiteCRMUserId('scrm-alice-uuid');
		$requestStub = function () {
			return [
				'data' => [
					[
						'id' => 'case-1',
						'attributes' => [
							'name' => 'Tagged',
							'priority' => 'P1',
							'status' => 'New',
							'date_entered' => (new \DateTime('-4 days'))->format('Y-m-d\TH:i:s'),
						],
					],
				],
			];
		};
		$service = $this->makeService($requestStub);
		$results = $service->getMyCases('https://crm', 'tok', 'alice', 20);

		$this->assertCount(1, $results);
		$this->assertSame('case', $results[0]['type']);
		$this->assertSame(1, $results[0]['priority_rank']);
		// `diff->days` can be 3 or 4 depending on the exact moment the
		// test runs vs the "-4 days" fixture; assert a generous range.
		$this->assertGreaterThanOrEqual(3, $results[0]['age_days']);
		$this->assertLessThanOrEqual(4, $results[0]['age_days']);
	}
}
