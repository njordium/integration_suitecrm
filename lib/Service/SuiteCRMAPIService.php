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
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Reminders?' . implode('&filter[operator]=and&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		$finalResults = [];
		foreach (($result['data'] ?? []) as $reminder) {
			// apply time filter on real reminder date
			$realReminderTs = (int) $reminder['attributes']['date_willexecute'] - (int) $reminder['attributes']['timer_popup'];
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
	 * The modules searched by {@see search()} and how their attributes map to
	 * the result payload.
	 *
	 * `module`      = SuiteCRM v8 module endpoint segment
	 * `type`        = tag emitted on each result; consumed by SuiteCRMSearchProvider
	 *                 to pick main text / subline / icon / URL
	 * `fields`      = comma-separated attribute list for the JSON:API fields[] filter
	 * `name_attr`   = attribute used to match against the search query
	 */
	/**
	 * The modules queried by {@see getUpcoming()} for the calendar widget.
	 *
	 * `module`    = SuiteCRM v8 module endpoint segment
	 * `type`      = tag emitted on each result (drives icon + link building on the
	 *               frontend)
	 * `fields`    = attribute list for the JSON:API fields[] filter
	 * `date_attr` = attribute used as the primary sort/date key ("when it happens")
	 */
	private const UPCOMING_MODULES = [
		['module' => 'Meetings', 'type' => 'meeting', 'fields' => 'name,date_start,date_end,location,status,assigned_user_id', 'date_attr' => 'date_start'],
		['module' => 'Calls', 'type' => 'call', 'fields' => 'name,date_start,duration_hours,duration_minutes,status,assigned_user_id', 'date_attr' => 'date_start'],
		['module' => 'Tasks', 'type' => 'task', 'fields' => 'name,date_due,priority,status,assigned_user_id', 'date_attr' => 'date_due'],
	];

	private const SEARCH_MODULES = [
		['module' => 'Contacts', 'type' => 'contact', 'fields' => 'name,first_name,last_name,full_name', 'name_attr' => 'full_name'],
		['module' => 'Accounts', 'type' => 'account', 'fields' => 'name', 'name_attr' => 'name'],
		['module' => 'Leads', 'type' => 'lead', 'fields' => 'name,full_name', 'name_attr' => 'full_name'],
		['module' => 'Opportunities', 'type' => 'opportunity', 'fields' => 'name,amount,currency_symbol,currency_name', 'name_attr' => 'name'],
		['module' => 'Cases', 'type' => 'case', 'fields' => 'name,case_number,status', 'name_attr' => 'name'],
		['module' => 'Meetings', 'type' => 'meeting', 'fields' => 'name,date_start,status,location', 'name_attr' => 'name'],
		['module' => 'Tasks', 'type' => 'task', 'fields' => 'name,date_due,priority,status', 'name_attr' => 'name'],
		['module' => 'Emails', 'type' => 'email', 'fields' => 'name,from_addr_name,date_sent,status', 'name_attr' => 'name'],
	];

	/**
	 * @return array Combined result rows, each tagged with a `type` field.
	 *               On upstream API failure, returns the SuiteCRM error payload
	 *               so callers can distinguish "no results" from "no connection".
	 */
	/**
	 * Returns SuiteCRM Meetings/Calls/Tasks assigned to the user, upcoming within
	 * the next `$horizonDays` days, sorted chronologically. Used by the calendar
	 * dashboard widget.
	 *
	 * @return array Sorted result rows, each tagged with `type` and a normalised
	 *               `event_ts` int timestamp for client-side sorting/formatting.
	 *               On upstream API failure, returns the SuiteCRM error payload.
	 */
	public function getUpcoming(string $url, string $accessToken, string $userId, int $horizonDays = 7, int $limit = 20): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$now = new DateTime();
		$horizon = (clone $now)->add(new DateInterval('P' . $horizonDays . 'D'));

		$combined = [];
		foreach (self::UPCOMING_MODULES as $moduleDef) {
			$filters = [
				'fields[' . $moduleDef['module'] . ']=' . $moduleDef['fields'],
				urlencode('filter[assigned_user_id][eq]') . '=' . urlencode($scrmUserId),
				urlencode('filter[' . $moduleDef['date_attr'] . '][gt]') . '=' . urlencode($now->format('Y-m-d\TH:i:s')),
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
			foreach ($response['data'] ?? [] as $row) {
				$dateStr = $row['attributes'][$moduleDef['date_attr']] ?? null;
				if ($dateStr === null || $dateStr === '') {
					continue;
				}
				try {
					$row['event_ts'] = (new DateTime($dateStr))->getTimestamp();
				} catch (Exception) {
					continue;
				}
				$row['type'] = $moduleDef['type'];
				$combined[] = $row;
			}
		}

		usort($combined, fn ($a, $b) => $a['event_ts'] <=> $b['event_ts']);
		return array_slice($combined, 0, $limit);
	}

	public function search(string $url, string $accessToken, string $userId, string $query, int $offset = 0, int $limit = 5): array {
		$combinedResults = [];
		$queryRegex = '/' . preg_quote($query, '/') . '/i';

		foreach (self::SEARCH_MODULES as $moduleDef) {
			$response = $this->request(
				$url, $accessToken, $userId,
				'module/' . $moduleDef['module'] . '?fields[' . $moduleDef['module'] . ']=' . $moduleDef['fields']
			);
			if (isset($response['error'])) {
				return $response;
			}
			foreach ($response['data'] ?? [] as $row) {
				$candidate = $row['attributes'][$moduleDef['name_attr']] ?? '';
				if ($candidate !== '' && preg_match($queryRegex, $candidate)) {
					$row['type'] = $moduleDef['type'];
					$combinedResults[] = $row;
				}
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
				'Authorization'  => 'Bearer ' . $accessToken,
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
							int $retryCount = 0): array {
		try {
			$url = $suitecrmUrl . '/Api/index.php/V8/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'Bearer ' . $accessToken,
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
						$suitecrmUrl, $accessToken, $userId, $endPoint, $params, $method, $retryCount + 1
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
					'User-Agent'  => 'Nextcloud SuiteCRM integration',
				]
			];

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
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Throwable $e) {
			$redactedParams = $params;
			if (isset($redactedParams['password'])) {
				$redactedParams['password'] = '********';
			}
			if (isset($redactedParams['client_secret'])) {
				$redactedParams['client_secret'] = '********';
			}
			$this->logger->warning('SuiteCRM OAuth error', [
				'app' => $this->appName,
				'exception' => $e,
				'params' => $redactedParams,
			]);
			return ['error' => $e->getMessage()];
		}
	}
}
