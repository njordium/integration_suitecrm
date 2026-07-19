<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Julien Veyssier <eneiluj@posteo.net>
 * @license GNU AGPL version 3 or any later version
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Dashboard;

use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;

/**
 * Nextcloud home dashboard widget listing upcoming SuiteCRM Meetings, Calls,
 * and Tasks assigned to the current user.
 *
 * Separate from {@see SuiteCRMWidget} (reminders); this one is
 * schedule-oriented rather than reminder/notification-oriented.
 *
 * Iteration 19 (Finding 26): migrated to IAPIWidget + IIconWidget so the
 * NC 30+ dashboard app can render items server-side as JSON. The legacy Vue
 * mount path (via {@see load()} and `OCA.Dashboard.register`) is preserved
 * so the classic dashboard continues to work — dual-mode migration.
 */
class SuiteCRMCalendarWidget implements IWidget, IAPIWidget, IIconWidget {

	/**
	 * Default horizon (in days) for upcoming events. Matches the historical
	 * default of {@see SuiteCRMAPIService::getUpcoming()}.
	 */
	private const HORIZON_DAYS = 7;

	private const TYPE_MODULE = [
		'meeting' => 'Meetings',
		'call' => 'Calls',
		'task' => 'Tasks',
	];

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_calendar';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM calendar');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-suitecrm';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, 'app.svg')
		);
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-calendar');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * IAPIWidget: return upcoming SuiteCRM events as structured data.
	 *
	 * `$since` is not currently used for filtering — {@see SuiteCRMAPIService::getUpcoming()}
	 * already scopes results to "from now until horizonDays ahead", which is the
	 * same behaviour the Vue frontend relies on. It is accepted (and threaded
	 * into `sinceId` on each item) so the dashboard app can still page through
	 * long lists without the widget silently ignoring the cursor.
	 *
	 * Returns an empty array on error or missing token so the dashboard app
	 * gracefully shows the empty state instead of crashing the request.
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$events = $this->service->getUpcoming(
			$suitecrmUrl,
			$accessToken,
			$userId,
			self::HORIZON_DAYS,
			$limit,
		);
		if (isset($events['error'])) {
			return [];
		}

		$items = [];
		foreach ($events as $event) {
			$type = (string) ($event['type'] ?? '');
			$eventId = (string) ($event['id'] ?? '');
			$whenTs = (int) ($event['event_ts'] ?? 0);
			$title = (string) ($event['attributes']['name'] ?? $this->l10n->t('(no title)'));

			$items[] = new WidgetItem(
				$title,
				$this->buildSubtitle($type, $whenTs, $event['attributes'] ?? []),
				$this->buildEventLink($suitecrmUrl, $type, $eventId),
				$this->iconForType($type),
				(string) $whenTs,
			);
		}

		return $items;
	}

	private function buildEventLink(string $suitecrmUrl, string $type, string $eventId): string {
		$module = self::TYPE_MODULE[$type] ?? null;
		if ($module === null || $eventId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=' . rawurlencode($module)
			. '&action=DetailView&record=' . rawurlencode($eventId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildSubtitle(string $type, int $whenTs, array $attributes): string {
		$label = $whenTs > 0 ? date('Y-m-d H:i', $whenTs) : '';
		if ($type === 'meeting') {
			$location = isset($attributes['location']) ? (string) $attributes['location'] : '';
			return $location !== '' ? $label . ' · ' . $location : $label;
		}
		if ($type === 'call') {
			return $this->l10n->t('Call at %s', [$label]);
		}
		if ($type === 'task') {
			$priority = isset($attributes['priority']) ? (string) $attributes['priority'] : '';
			return $priority !== '' ? $label . ' · ' . $priority : $label;
		}
		return $label;
	}

	private function iconForType(string $type): string {
		$file = match ($type) {
			'call' => 'call.png',
			'meeting' => 'meeting.png',
			default => 'app.svg',
		};
		return $this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, $file)
		);
	}
}
