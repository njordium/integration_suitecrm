<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Service;

use DateInterval;
use DateTime;
use Exception;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager as INotificationManager;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCP\Http\Client\LocalServerException;

use OCA\SuiteCRM\AppInfo\Application;

class SuiteCRMAPIService {
	/**
	 * @var IClient
	 */
	private IClient $client;

	/**
	 * Service to make requests to SuiteCRM v8 REST (JSON:API)
	 */
	public function __construct(private string $appName,
								private IUserManager $userManager,
								private LoggerInterface $logger,
								private IL10N $l10n,
								private IConfig $config,
								private IAppConfig $appConfig,
								private INotificationManager $notificationManager,
								IClientService $clientService,
								private TokenStorage $tokens,
								private IAppManager $appManager) {
		$this->client = $clientService->newClient();
	}

	/**
	 * triggered by a cron job
	 * notifies user of their number of new tickets
	 *
	 * @return void
	 */
	public function checkAlerts(): void {
		$this->userManager->callForAllUsers(function (IUser $user) {
			try {
				$this->checkAlertsForUser($user->getUID());
			} catch (\Throwable $e) {
				$this->logger->warning('SuiteCRM checkAlertsForUser failed: ' . $e->getMessage(), [
					'app' => $this->appName,
					'user' => $user->getUID(),
					'exception' => $e,
				]);
			}
		});
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	private function checkAlertsForUser(string $userId): void {
		$user = $this->userManager->get($userId);
		if ($user === null || !$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return;
		}
		$accessToken = $this->tokens->getAccessToken($userId);
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');
		if ($accessToken && $notificationEnabled) {
			$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
			$lastReminderCheck = (int) $this->config->getUserValue($userId, Application::APP_ID, 'last_reminder_check', '0');
			if ($lastReminderCheck === 0) {
				// back one week
				$d = new DateTime();
				$d->sub(new DateInterval('P1W'));
				$lastReminderCheck = $d->getTimestamp();
			}

			$tsNow = (new DateTime())->getTimestamp();
			$reminders = $this->getReminders($suitecrmUrl, $accessToken, $userId, $lastReminderCheck, $tsNow);
			if (!isset($reminders['error']) && count($reminders) > 0) {
				foreach ($reminders as $reminder) {
					if ($reminder['real_reminder_timestamp'] > $lastReminderCheck) {
						$lastReminderCheck = $reminder['real_reminder_timestamp'];
					}
					$module = $reminder['attributes']['related_event_module'];
					$elemId = $reminder['attributes']['related_event_module_id'];
					$this->sendNCNotification($userId, 'reminder', [
						'type' => $module,
						'elem_id' => $elemId,
						'link' => $suitecrmUrl . '/index.php?module=' . $module . '&action=DetailView&record=' . $elemId,
						'title' => $reminder['title'],
						'event_timestamp' => $reminder['attributes']['date_willexecute'],
					]);
				}
				// update last check date
				$this->config->setUserValue($userId, Application::APP_ID, 'last_reminder_check', $lastReminderCheck);
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject((string) ($params['type'] ?? 'reminder'), (string) ($params['elem_id'] ?? uniqid()))
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * Get reminders about stuff assigned to connected user:
	 * - related to call/meeting assigned to the user
	 * - for an event in the future
	 * - not already read
	 * - reminder set after $since (if defined)
	 *
	 * Iteration 21 (Finding 6): the Reminders query now pushes
	 * `filter[assigned_user_id][eq]=<scrmUserId>` to the server so we don't
	 * pull every reminder in the tenant just to discard 99% of them. The
	 * per-event assigned_user_id check below still runs — the two IDs can
	 * diverge (e.g. a reminder created by an admin on behalf of another
	 * user), so the server filter narrows the input set and the client-side
	 * check remains authoritative for the "this reminder's event is mine"
	 * decision.
	 *
	 * Iteration 21 (Finding 5): the sprayed
	 * `implode('&filter[operator]=and&', ...)` produced a URL with an
	 * `operator=and` between every pair of filters, which SuiteCRM parses
	 * as one final operator anyway but which trips some WAFs. The operator
	 * is now appended once, only when there is more than one filter.
	 *
	 * Iteration 21 (Finding 9): defensive parse of `date_willexecute` and
	 * `timer_popup`. Some SuiteCRM installs return NULL for these fields
	 * on orphaned reminders (a reminder whose event was deleted before the
	 * reminder was cleaned up), which used to arithmetic to a large
	 * negative timestamp and flood the tray with garbage rows from ~1970.
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param int|null $reminderSinceTs
	 * @param int|null $reminderUntilTs
	 * @param int|null $eventSinceTs
	 * @param int|null $eventUntilTs
	 * @param ?int $limit
	 * @return array
	 */
	public function getReminders(string $url, string $accessToken, string $userId,
								 ?int $reminderSinceTs = null, ?int $reminderUntilTs = null,
								 ?int $eventSinceTs = null, ?int $eventUntilTs = null,
								 ?int $limit = null): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$filters = [];
		if ($scrmUserId !== '') {
			$filters[] = urlencode('filter[assigned_user_id][eq]') . '=' . urlencode($scrmUserId);
		}
		if (!is_null($reminderSinceTs)) {
			$filters[] = 'filter[date_willexecute][gt]=' . $reminderSinceTs;
		}
		if (!is_null($reminderUntilTs)) {
			// date_willexecute is actually the date of the event, not the reminder one...
			// so we make sure we get the max reminder popup_timer
			$filters[] = 'filter[date_willexecute][lt]=' . ($reminderUntilTs + (60 * 60 * 24));
		}
		if (!is_null($eventSinceTs)) {
			$filters[] = 'filter[date_willexecute][gt]=' . $eventSinceTs;
		}
		if (!is_null($eventUntilTs)) {
			$filters[] = 'filter[date_willexecute][lt]=' . $eventUntilTs;
		}
		$queryString = implode('&', $filters);
		if (count($filters) > 1) {
			$queryString .= '&filter[operator]=and';
		}
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Reminders?' . $queryString
		);
		if (isset($result['error'])) {
			return $result;
		}
		$finalResults = [];
		foreach (($result['data'] ?? []) as $reminder) {
			// Defensive: orphan reminders can have NULL date_willexecute /
			// timer_popup on some installs — skip those rather than emit a
			// bogus 1970 timestamp downstream.
			$dateWillExecute = $reminder['attributes']['date_willexecute'] ?? null;
			$timerPopup = $reminder['attributes']['timer_popup'] ?? null;
			if (!is_numeric($dateWillExecute) || !is_numeric($timerPopup)) {
				continue;
			}
			$realReminderTs = (int) $dateWillExecute - (int) $timerPopup;
			if (!is_null($reminderSinceTs) && $realReminderTs <= $reminderSinceTs) {
				continue;
			}
			if (!is_null($reminderUntilTs) && $realReminderTs >= $reminderUntilTs) {
				continue;
			}
			$reminder['real_reminder_timestamp'] = $realReminderTs;
			// is it assigned to user?
			// get related element
			$module = $reminder['attributes']['related_event_module'];
			$elemId = $reminder['attributes']['related_event_module_id'];
			$elem = $this->request(
				$url, $accessToken, $userId, 'module/' . $module . '/' . $elemId
			);
			if (!isset($elem['error'])
				&& isset($elem['data'], $elem['data']['attributes'], $elem['data']['attributes']['assigned_user_id'])
				&& $elem['data']['attributes']['assigned_user_id'] === $scrmUserId
			) {
				$reminder['title'] = $elem['data']['attributes']['name'];
				$finalResults[] = $reminder;
			}
		}

		usort($finalResults, fn ($a, $b) => $a['real_reminder_timestamp'] <=> $b['real_reminder_timestamp']);
		if ($limit) {
			$finalResults = array_slice($finalResults, 0, $limit);
		}
		return array_values($finalResults);
	}

	/**
	 * !!!! problem with alerts: they appear after the popup has been shown in UI so we don't see them if user didn't see them already...
	 * Get user alerts that are
	 * - assigned to the user
	 * - for an event in the future
	 * - not already read
	 * - reminder set after $since (if defined)
	 * Alerts are created once the reminder execution date is reached
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param ?int $sinceTs
	 * @param ?int $limit
	 * @return array
	 */
	public function getAlerts(string $url, string $accessToken, string $userId, ?int $sinceTs = null, ?int $limit = null): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$filters = [
			urlencode('filter[assigned_user_id][eq]') . '=' . urlencode($scrmUserId),
			urlencode('filter[is_read][eq]') . '=0',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Alerts?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		// get target date for calls and meetings
		$tsNow = (new DateTime())->getTimestamp();
		$finalAlerts = [];
		foreach (($result['data'] ?? []) as $alert) {
			$urlRedirect = $alert['attributes']['url_redirect'];
			$isCall = preg_match('/module=Calls/', $urlRedirect);
			$isMeeting = preg_match('/module=Meetings/', $urlRedirect);
			$recordMatch = [];
			preg_match('/record=([a-z0-9\-]+)/', $urlRedirect, $recordMatch);
			if (($isCall || $isMeeting) && count($recordMatch) > 1) {
				$recordId = $recordMatch[1];
				$module = $isCall ? 'Calls' : 'Meetings';
				$elem = $this->request(
					$url, $accessToken, $userId, 'module/' . $module . '/' . $recordId
				);
				if (!isset($elem['error']) && isset($elem['data']) && isset($elem['data']['attributes']['date_start'])
				) {
					$tsElem = (new DateTime($elem['data']['attributes']['date_start']))->getTimestamp();
					if ($tsElem > $tsNow) {
						$alert['date_start'] = $elem['data']['attributes']['date_start'];
						$alert['type'] = $isCall ? 'call' : 'meeting';

						// get the related reminder
						$reminder = $this->request(
							$url, $accessToken, $userId, 'module/Reminders/' . $alert['attributes']['reminder_id']
						);
						if (isset($reminder['data'], $reminder['data']['attributes'], $reminder['data']['attributes']['date_willexecute'])) {
							$dateWillExecute = $reminder['data']['attributes']['date_willexecute'];
							$alert['date_willexecute'] = (int) $dateWillExecute;
							// finally add the alert
							$finalAlerts[] = $alert;
						}
					}
				}
			}
		}
		// filter by reminder execution date
		if (!is_null($sinceTs)) {
			$finalAlerts = array_filter($finalAlerts, function($elem) use ($sinceTs) {
				return $elem['date_willexecute'] > $sinceTs;
			});
		}
		// sort by reminder execution date
		usort($finalAlerts, fn ($a, $b) => $a['date_willexecute'] <=> $b['date_willexecute']);
		if ($limit) {
			$finalAlerts = array_slice($finalAlerts, 0, $limit);
		}
		return array_values($finalAlerts);
	}

	/**
	 * The modules queried by {@see getUpcoming()} for the calendar widget.
	 *
	 * `module`    = SuiteCRM v8 module endpoint segment
	 * `type`      = tag emitted on each result (drives icon + link building on the
	 *               frontend)
	 * `fields`    = attribute list for the JSON:API fields[] filter
	 * `date_attr` = attribute used as the primary sort/date key ("when it happens")
	 */
	/**
	 * Iteration 50 (upstream issue #8 follow-through): `overdue_statuses` lists
	 * the SuiteCRM status values that mean "the user hasn't yet dispositioned
	 * this record". A past-due Meeting or Call with status = 'Planned' still
	 * needs the rep's attention — same for a Task whose date_due is in the past
	 * but that hasn't been marked Completed. Before iter 50 the widget's
	 * `date_start > now()` filter silently dropped every such row and the rep
	 * only found out about missed activity from SuiteCRM itself, defeating the
	 * point of a dashboard reminder.
	 *
	 * `Held`, `Not Held`, `Completed`, `Deferred` are the disposition-terminal
	 * statuses and are NOT in this list — a Held meeting is done, and the
	 * calendar widget stops nagging about it.
	 */
	private const UPCOMING_MODULES = [
		['module' => 'Meetings', 'type' => 'meeting', 'fields' => 'name,date_start,date_end,location,status,assigned_user_id', 'date_attr' => 'date_start', 'overdue_statuses' => ['Planned']],
		['module' => 'Calls',    'type' => 'call',    'fields' => 'name,date_start,duration_hours,duration_minutes,status,assigned_user_id', 'date_attr' => 'date_start', 'overdue_statuses' => ['Planned']],
		['module' => 'Tasks',    'type' => 'task',    'fields' => 'name,date_due,priority,status,assigned_user_id', 'date_attr' => 'date_due', 'overdue_statuses' => ['Not Started', 'In Progress', 'Pending Input']],
	];

	/**
	 * The modules searched by {@see search()} and how their attributes map to
	 * the result payload.
	 *
	 * `module`     = SuiteCRM v8 module endpoint segment
	 * `type`       = tag emitted on each result; consumed by SuiteCRMSearchProvider
	 *                to pick main text / subline / icon / URL
	 * `fields`     = comma-separated attribute list for the JSON:API fields[] filter
	 * `name_attrs` = ordered list of attributes to match the search substring
	 *                against. One request is fired per attribute and results are
	 *                merged and de-duplicated by record id — see the block
	 *                comment on {@see search()} for why a client-side merge is
	 *                preferred over `filter[operator]=or` on SuiteCRM 8.x.
	 *
	 * Iteration 21 (Finding 4): Contacts and Leads used to filter by
	 * `full_name`, which is a non-db computed field on both modules — the
	 * filter silently matched nothing on every install, so cross-module
	 * search returned no Contacts/Leads at all. Switched to `last_name`,
	 * which is a real column and the standard SuiteCRM "search person by
	 * name" surface.
	 *
	 * Iteration 35 (Finding 25 follow-up): matching only on `last_name`
	 * meant a user typing a first name ("Serena") got zero hits even when
	 * "Serena Arent" exists as a Contact. The person modules now list
	 * both `last_name` and `first_name` in `name_attrs`; {@see search()}
	 * fires one request per attribute, unions the rows, and dedupes by
	 * record id. All other modules stay single-attribute — their only
	 * user-facing display field is `name`.
	 */
	private const SEARCH_MODULES = [
		['module' => 'Contacts',      'type' => 'contact',     'fields' => 'name,first_name,last_name,full_name',           'name_attrs' => ['last_name', 'first_name']],
		['module' => 'Accounts',      'type' => 'account',     'fields' => 'name',                                          'name_attrs' => ['name']],
		['module' => 'Leads',         'type' => 'lead',        'fields' => 'name,first_name,last_name,full_name',           'name_attrs' => ['last_name', 'first_name']],
		['module' => 'Opportunities', 'type' => 'opportunity', 'fields' => 'name,amount,currency_symbol,currency_name',     'name_attrs' => ['name']],
		['module' => 'Cases',         'type' => 'case',        'fields' => 'name,case_number,status',                       'name_attrs' => ['name']],
		['module' => 'Meetings',      'type' => 'meeting',     'fields' => 'name,date_start,status,location',               'name_attrs' => ['name']],
		['module' => 'Tasks',         'type' => 'task',        'fields' => 'name,date_due,priority,status',                 'name_attrs' => ['name']],
		// Iteration 36 (Finding 25 tail): `date_sent` is not a real column on
		// SuiteCRM 8.10.x's Email bean — the API responded 400
		// "The following field in Email module is not found: date_sent" on every
		// search since Iteration 24. Iteration 35's per-attribute error handling
		// stopped it crashing the endpoint, but Emails still contributed zero
		// hits. `SuiteCRMSearchProvider::getSubline()` only reads `name` and
		// `from_addr_name` for the email type, so the offending field is dropped
		// rather than substituted — one fewer over-the-wire attribute per search.
		['module' => 'Emails',        'type' => 'email',       'fields' => 'name,from_addr_name,status',                    'name_attrs' => ['name']],
	];

	/**
	 * Returns SuiteCRM Meetings/Calls/Tasks assigned to the user for the
	 * calendar widget. Includes both:
	 *
	 *   - Upcoming items (now .. now + $horizonDays)
	 *   - Past-due-but-not-dispositioned items (now - $overdueLookbackDays .. now)
	 *     — Meetings/Calls whose status is still `Planned`, Tasks whose status
	 *     is not one of `Held / Not Held / Completed / Deferred`.
	 *
	 * Sorted chronologically (oldest first) so overdue rows surface at the top
	 * of the widget. Each row is tagged with a boolean `is_overdue` for the
	 * frontend to badge/highlight.
	 *
	 * Iteration 50 (upstream issue #8): before this iteration the filter was
	 * `date > now AND date < horizon`, which silently dropped past-due
	 * Meetings/Calls the rep hadn't dispositioned. Widget users had to open
	 * SuiteCRM directly to notice missed activity, defeating the purpose of a
	 * dashboard reminder. The single API call is now widened to cover the past
	 * lookback window as well; the not-actionable rows are filtered client-side
	 * so we don't have to lean on SuiteCRM 8.x's `filter[operator]=or` (which
	 * we know from iter 35 misbehaves on 8.4/8.5 DBAL).
	 *
	 * @return array Sorted result rows, each tagged with `type`, `event_ts`
	 *               (int Unix timestamp), and `is_overdue` (bool).
	 *               On upstream API failure, returns the SuiteCRM error payload.
	 */
	public function getUpcoming(string $url, string $accessToken, string $userId, int $horizonDays = 7, int $limit = 20, int $overdueLookbackDays = 30): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$now = new DateTime();
		$nowTs = $now->getTimestamp();
		$horizon = (clone $now)->add(new DateInterval('P' . $horizonDays . 'D'));
		$lookback = (clone $now)->sub(new DateInterval('P' . $overdueLookbackDays . 'D'));

		$combined = [];
		foreach (self::UPCOMING_MODULES as $moduleDef) {
			$filters = [
				'fields[' . $moduleDef['module'] . ']=' . $moduleDef['fields'],
				urlencode('filter[assigned_user_id][eq]') . '=' . urlencode($scrmUserId),
				urlencode('filter[' . $moduleDef['date_attr'] . '][gt]') . '=' . urlencode($lookback->format('Y-m-d\TH:i:s')),
				urlencode('filter[' . $moduleDef['date_attr'] . '][lt]') . '=' . urlencode($horizon->format('Y-m-d\TH:i:s')),
				'filter[operator]=and',
			];
			$response = $this->request(
				$url, $accessToken, $userId,
				'module/' . $moduleDef['module'] . '?' . implode('&', $filters)
			);
			if (isset($response['error'])) {
				return $response;
			}
			// PHPStan proves overdue_statuses is present on every UPCOMING_MODULES row,
			// so a null-coalesce fallback would be dead code (level 5 rejects it).
			$overdueActionable = $moduleDef['overdue_statuses'];
			foreach ($response['data'] ?? [] as $row) {
				$dateStr = $row['attributes'][$moduleDef['date_attr']] ?? null;
				if ($dateStr === null || $dateStr === '') {
					continue;
				}
				try {
					$eventTs = (new DateTime($dateStr))->getTimestamp();
				} catch (Exception) {
					continue;
				}
				$isUpcoming = $eventTs >= $nowTs;
				$status = (string) ($row['attributes']['status'] ?? '');
				$isActionableOverdue = !$isUpcoming && in_array($status, $overdueActionable, true);
				if (!$isUpcoming && !$isActionableOverdue) {
					// Past-due but already dispositioned (Held, Completed, etc) — skip.
					continue;
				}
				$row['event_ts'] = $eventTs;
				$row['type'] = $moduleDef['type'];
				$row['is_overdue'] = !$isUpcoming;
				$combined[] = $row;
			}
		}

		usort($combined, fn ($a, $b) => $a['event_ts'] <=> $b['event_ts']);
		return array_slice($combined, 0, $limit);
	}

	/**
	 * Cross-module free-text search.
	 *
	 * Iteration 18 (Finding 16): the filter is pushed to SuiteCRM v8 REST
	 * instead of fetching every row per module and grepping client-side with
	 * `preg_match`. The old approach did not scale to real CRM sizes — a
	 * tenant with 100k Contacts would pull 100k rows over the wire per
	 * keystroke.
	 *
	 * Iteration 24 (regression fix): the operator briefly moved to
	 * `contains` in Iteration 21 but SuiteCRM 8.10.1 returns
	 * `400 Filter operator contains is invalid`. The stable operator is
	 * `like` with explicit `%wildcard%` bracketing.
	 *
	 * Iteration 35 (Finding 25 follow-up): each module declares an ordered
	 * list of `name_attrs` (see {@see SEARCH_MODULES}). For person modules
	 * (Contacts, Leads) the list is `['last_name', 'first_name']` so that
	 * a first-name-only query still hits records that would otherwise be
	 * invisible.
	 *
	 * A dedicated `filter[operator]=or` request across both fields would
	 * cut the request count in half, but SuiteCRM 8.x's DBAL layer applies
	 * the OR at the top of the WHERE clause rather than between the two
	 * `filter[<field>][like]` clauses — on 8.4 / 8.5 the query ends up as
	 * `WHERE (contacts.deleted = 0) OR (last_name LIKE ...) OR (first_name
	 * LIKE ...)`, which returns every deleted-flag-0 row in the module.
	 * The client-side union below is one extra HTTP round trip per person
	 * module (two total for a normal search), which is much cheaper than
	 * pulling and discarding thousands of unrelated rows.
	 *
	 * Rows are de-duplicated by `module|id` so a Contact whose first name
	 * and last name both contain the search substring appears once, in the
	 * position of its first hit. Module ordering in the combined result
	 * follows {@see SEARCH_MODULES}.
	 *
	 * @return array Combined result rows, each tagged with a `type` field.
	 *               On upstream API failure the module is skipped and the
	 *               error is logged rather than propagated, so one broken
	 *               module cannot silence the entire search.
	 */
	public function search(string $url, string $accessToken, string $userId, string $query, int $offset = 0, int $limit = 5): array {
		$combinedResults = [];
		$seenIds = [];
		// `like` takes a %-wrapped substring; both leading and trailing
		// wildcards so mid-word matches ("cent" → "Vincent") work.
		$searchValue = '%' . $query . '%';

		foreach (self::SEARCH_MODULES as $moduleDef) {
			$moduleErrors = [];
			$moduleAcceptedAtLeastOne = false;
			foreach ($moduleDef['name_attrs'] as $nameAttr) {
				$filters = [
					'fields[' . $moduleDef['module'] . ']=' . $moduleDef['fields'],
					urlencode('filter[' . $nameAttr . '][like]') . '=' . urlencode($searchValue),
				];
				$response = $this->request(
					$url, $accessToken, $userId,
					'module/' . $moduleDef['module'] . '?' . implode('&', $filters)
				);
				if (isset($response['error'])) {
					// A single attribute rejecting the filter (missing
					// column in a custom schema, non-filterable field,
					// etc.) shouldn't kill the whole search — record the
					// error and try the next attribute. We only log if
					// EVERY attribute in this module failed, to avoid
					// spamming warnings for the normal case of a partially
					// filterable schema.
					$moduleErrors[$nameAttr] = $response['error'];
					continue;
				}
				$moduleAcceptedAtLeastOne = true;
				foreach ($response['data'] ?? [] as $row) {
					$rowId = $row['id'] ?? '';
					if ($rowId === '') {
						continue;
					}
					$dedupKey = $moduleDef['module'] . '|' . $rowId;
					if (isset($seenIds[$dedupKey])) {
						continue;
					}
					$seenIds[$dedupKey] = true;
					$row['type'] = $moduleDef['type'];
					$combinedResults[] = $row;
				}
			}
			if (!$moduleAcceptedAtLeastOne && !empty($moduleErrors)) {
				$this->logger->warning('SuiteCRM search: all name attributes rejected for module', [
					'app' => $this->appName,
					'module' => $moduleDef['module'],
					'name_attrs' => $moduleDef['name_attrs'],
					'errors' => $moduleErrors,
				]);
			}
		}

		return array_slice($combinedResults, $offset, $limit);
	}

	/**
	 * authenticated request to get an image from suitecrm
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $suiteUserId
	 * @return string
	 */
	public function getSuiteCRMAvatar(string $url,
									  string $accessToken,
									  string $suiteUserId): string {
		$url = $url . '/index.php?entryPoint=download&id=' . urlencode($suiteUserId) . '_photo&type=Users';
		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $accessToken,
				'User-Agent' => 'Nextcloud SuiteCRM integration',
			]
		];
		try {
			return $this->client->get($url, $options)->getBody();
		} catch (\Throwable $e) {
			$this->logger->warning('SuiteCRM avatar fetch failed', [
				'app' => $this->appName,
				'exception' => $e,
			]);
			return '';
		}
	}

	/**
	 * @param string $suitecrmUrl
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param int $retryCount
	 * @return array
	 */
	public function request(string $suitecrmUrl, string $accessToken, string $userId,
							string $endPoint, array $params = [], string $method = 'GET',
							int $retryCount = 0, bool $jsonBody = false): array {
		try {
			$url = $suitecrmUrl . '/Api/index.php/V8/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud SuiteCRM integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);
					$url .= '?' . $paramsContent;
				} elseif ($jsonBody) {
					// Iter 68: write requests to SuiteCRM 8.x V8 API require
					// the JSON:API envelope + `application/vnd.api+json`
					// content type. Enabled only when the caller opts in
					// via $jsonBody=true — read call sites keep the legacy
					// form-encoded body they were built against.
					$options['headers']['Content-Type'] = 'application/vnd.api+json';
					$options['headers']['Accept'] = 'application/vnd.api+json';
					$options['body'] = (string)json_encode($params);
				} else {
					$options['body'] = $params;
				}
			}

			$response = match ($method) {
				'GET' => $this->client->get($url, $options),
				'POST' => $this->client->post($url, $options),
				'PUT' => $this->client->put($url, $options),
				'DELETE' => $this->client->delete($url, $options),
				default => null,
			};
			if ($response === null) {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}
			$decoded = json_decode((string) $body, true);
			return is_array($decoded) ? $decoded : ['error' => $this->l10n->t('Invalid JSON response from SuiteCRM')];
		} catch (\Throwable $e) {
			$response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
			$errorBody = $response !== null ? (string) $response->getBody() : null;
			// try to refresh token if it's invalid
			if ($response !== null && $response->getStatusCode() === 401 && $retryCount < 1) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
				$refreshToken = $this->tokens->getRefreshToken($userId);
				$clientID = $this->appConfig->getValueString(Application::APP_ID, 'client_id');
				$clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret');
				// try to refresh the token
				$result = $this->requestOAuthAccessToken($suitecrmUrl, [
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'], $result['refresh_token'])) {
					$accessToken = $result['access_token'];
					$this->tokens->setAccessToken($userId, $accessToken);
					$this->tokens->setRefreshToken($userId, $result['refresh_token']);
					// retry the request with new access token
					return $this->request(
						$suitecrmUrl, $accessToken, $userId, $endPoint, $params, $method, $retryCount + 1, $jsonBody
					);
				}
			}
			$this->logger->warning('SuiteCRM API error', [
				'app' => $this->appName,
				'exception' => $e,
				'body' => $errorBody,
			]);
			return ['error' => $e->getMessage(), 'body' => $errorBody];
		}
	}

	/**
	 * Create a record in a SuiteCRM module via the V8 JSON:API.
	 *
	 * Iter 68 — foundation for the four planned write features (Task
	 * from widget, Talk → Note, Email → Case, Deck ↔ Opportunity).
	 * Wraps the caller-supplied attributes in the required JSON:API
	 * envelope, then delegates to {@see request()} with $jsonBody=true
	 * so the token-refresh retry and error handling stay in one place.
	 *
	 * Successful response shape (SuiteCRM 8.10.x):
	 *   [
	 *     'data' => [
	 *       'type' => 'Tasks',       // module name
	 *       'id'   => '<uuid>',      // the created record id
	 *       'attributes' => [...],   // full attribute set of the new record
	 *     ],
	 *   ]
	 *
	 * Error response is whatever {@see request()} returns for that layer
	 * — same envelope as read failures so callers can share error paths.
	 *
	 * @param string $suitecrmUrl  Base SuiteCRM instance URL (no trailing slash needed)
	 * @param string $accessToken  Valid OAuth2 access token
	 * @param string $userId       Nextcloud user id (used for token refresh on 401)
	 * @param string $module       SuiteCRM module name (e.g. 'Tasks', 'Notes', 'Cases')
	 * @param array $attributes    Field values for the new record (module-specific)
	 * @return array               JSON:API response or {'error' => ...} envelope
	 */
	public function createRecord(string $suitecrmUrl, string $accessToken, string $userId,
								 string $module, array $attributes): array {
		$payload = [
			'data' => [
				'type' => $module,
				'attributes' => $attributes,
			],
		];
		return $this->request(
			$suitecrmUrl, $accessToken, $userId,
			'module/' . rawurlencode($module),
			$payload, 'POST', 0, true,
		);
	}

	/**
	 * Attach one SuiteCRM record to another via a named relationship.
	 *
	 * Iter 68. Used for the parent_type/parent_id linkages that the four
	 * write features need — a follow-up Task linked back to the source
	 * Meeting, a Note attached to a Contact, a Case attached to an
	 * Account, and so on. SuiteCRM's V8 JSON:API exposes relationship
	 * management at `/module/{module}/{id}/relationships/{relationship}`.
	 *
	 * For the common case of parent_type/parent_id, prefer setting those
	 * two fields directly in the {@see createRecord()} attributes map —
	 * it's one round trip instead of two. Use `linkRecord()` when you
	 * need a many-to-many link that isn't reachable through the flat
	 * attribute set (e.g. Contacts attached to a Meeting as attendees).
	 *
	 * @param string $suitecrmUrl
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $fromModule    Owning-side module (e.g. 'Meetings')
	 * @param string $fromId        Owning-side record id
	 * @param string $relationship  Relationship name (e.g. 'contacts')
	 * @param string $toType        Related-side module (e.g. 'Contacts')
	 * @param string $toId          Related-side record id
	 * @return array
	 */
	public function linkRecord(string $suitecrmUrl, string $accessToken, string $userId,
							   string $fromModule, string $fromId, string $relationship,
							   string $toType, string $toId): array {
		$payload = [
			'data' => [
				'type' => $toType,
				'id' => $toId,
			],
		];
		return $this->request(
			$suitecrmUrl, $accessToken, $userId,
			sprintf(
				'module/%s/%s/relationships/%s',
				rawurlencode($fromModule),
				rawurlencode($fromId),
				rawurlencode($relationship),
			),
			$payload, 'POST', 0, true,
		);
	}

	/**
	 * OAuth token endpoint call. Returns the decoded JSON on success. On
	 * failure the returned array carries an enriched error envelope that
	 * ConfigController::oauthCallback() inspects to produce actionable admin
	 * messages:
	 *
	 *   [
	 *     'error'             => string   // guzzle / message text (fallback)
	 *     'error_kind'        => string   // 'local_server_blocked' | 'oauth_error' |
	 *                                     //   'transport' | 'bad_method' | 'bad_json'
	 *     'http_status'       => int      // response status code, 0 if no HTTP reply
	 *     'error_code'        => string   // OAuth error field from response body
	 *     'error_description' => string   // OAuth error_description from response
	 *     'body'              => string   // raw response body (only when present)
	 *   ]
	 *
	 * Iteration 37 (audit fix): this method used to return `['error' => msg]`
	 * only, losing every dimension of failure (blocked by SSRF guard vs
	 * 401/invalid_client vs cURL 6 name-resolution). ConfigController's
	 * try/catch around the call could never fire because this outer
	 * `catch (\Throwable)` swallowed everything first — so all
	 * admin-friendly diagnostic messages that ConfigController set up in
	 * Iteration 26 were unreachable. Preserving the raw dimensions here
	 * unblocks that.
	 *
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/Api/access_token';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud SuiteCRM integration',
				],
			];
			// Deliberately no `nextcloud => allow_local_address => true` here.
			// If the admin turns off allow_local_remote_servers system-wide,
			// Nextcloud's SSRF guard is expected to raise
			// LocalServerException for a private/loopback SuiteCRM URL —
			// which the catch below converts into the actionable
			// error_kind='local_server_blocked' envelope so
			// ConfigController::oauthCallback() can show the admin the
			// exact `occ config:system:set` command to run.

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			$response = match ($method) {
				'GET' => $this->client->get($url, $options),
				'POST' => $this->client->post($url, $options),
				'PUT' => $this->client->put($url, $options),
				'DELETE' => $this->client->delete($url, $options),
				default => null,
			};
			if ($response === null) {
				return [
					'error' => $this->l10n->t('Bad HTTP method'),
					'error_kind' => 'bad_method',
					'http_status' => 0,
					'error_code' => '',
					'error_description' => '',
				];
			}
			$body = (string) $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				// Nextcloud's IClient wraps guzzle with `http_errors => true`
				// by default, so 4xx normally throws a ClientException before
				// we get here. This branch is defensive for the case where a
				// future refactor sets `http_errors => false` or a subclass
				// returns a 4xx synchronously — we still preserve the
				// diagnostic dimensions instead of collapsing to a
				// single-string error.
				$decoded = json_decode($body, true);
				$decoded = is_array($decoded) ? $decoded : [];
				return [
					'error' => $this->l10n->t('OAuth access token refused'),
					'error_kind' => 'oauth_error',
					'http_status' => $respCode,
					'error_code' => (string) ($decoded['error'] ?? ''),
					'error_description' => (string) ($decoded['error_description'] ?? ''),
					'body' => $body,
				];
			}
			$decoded = json_decode($body, true);
			if (!is_array($decoded)) {
				return [
					'error' => $this->l10n->t('Invalid JSON response from SuiteCRM'),
					'error_kind' => 'bad_json',
					'http_status' => $respCode,
					'error_code' => '',
					'error_description' => '',
					'body' => $body,
				];
			}
			return $decoded;
		} catch (LocalServerException $e) {
			// Nextcloud's SSRF guard refused the outbound request to
			// SuiteCRM. Signal this specifically so the call site can suggest
			// `occ config:system:set allow_local_remote_servers`.
			$this->logger->warning('SuiteCRM OAuth blocked by SSRF guard', [
				'app' => $this->appName,
				'exception' => $e,
			]);
			return [
				'error' => $e->getMessage(),
				'error_kind' => 'local_server_blocked',
				'http_status' => 0,
				'error_code' => '',
				'error_description' => '',
			];
		} catch (ClientException $e) {
			$response = $e->getResponse();
			$status = $response->getStatusCode();
			$body = (string) $response->getBody();
			$decoded = json_decode($body, true);
			$decoded = is_array($decoded) ? $decoded : [];
			$redactedParams = $this->redactOAuthParams($params);
			$this->logger->warning('SuiteCRM OAuth rejected by upstream', [
				'app' => $this->appName,
				'status' => $status,
				'oauth_error' => $decoded['error'] ?? null,
				'oauth_error_description' => $decoded['error_description'] ?? null,
				'params' => $redactedParams,
			]);
			return [
				'error' => $e->getMessage(),
				'error_kind' => 'oauth_error',
				'http_status' => $status,
				'error_code' => (string) ($decoded['error'] ?? ''),
				'error_description' => (string) ($decoded['error_description'] ?? ''),
				'body' => $body,
			];
		} catch (\Throwable $e) {
			$redactedParams = $this->redactOAuthParams($params);
			$this->logger->warning('SuiteCRM OAuth transport error', [
				'app' => $this->appName,
				'exception' => $e,
				'params' => $redactedParams,
			]);
			return [
				'error' => $e->getMessage(),
				'error_kind' => 'transport',
				'http_status' => 0,
				'error_code' => '',
				'error_description' => '',
			];
		}
	}

	/**
	 * Shared param redactor for OAuth log entries. Extracted from
	 * requestOAuthAccessToken() so each catch branch can log the same
	 * safe-to-record view of the credentials-bearing payload.
	 */
	private function redactOAuthParams(array $params): array {
		if (isset($params['password'])) {
			$params['password'] = '********';
		}
		if (isset($params['client_secret'])) {
			$params['client_secret'] = '********';
		}
		return $params;
	}
}
