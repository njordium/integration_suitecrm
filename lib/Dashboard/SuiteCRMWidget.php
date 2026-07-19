<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Julien Veyssier <eneiluj@posteo.net>
 * @license GNU AGPL version 3 or any later version
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Dashboard;

use DateTime;
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
 * Nextcloud home dashboard widget listing SuiteCRM reminders (Meetings, Calls)
 * assigned to the current user and about to fire.
 *
 * Iteration 19 (Finding 26): migrated from the legacy IWidget-only surface
 * to the modern IAPIWidget + IIconWidget interfaces so the Nextcloud 30+
 * "Dashboard" app can lazy-load items as JSON instead of eagerly mounting
 * the Vue widget just to discover it's empty. The legacy IWidget path
 * (load() + registered Vue callback) is preserved so the classic dashboard
 * keeps working — this is an additive, dual-mode migration.
 */
class SuiteCRMWidget implements IWidget, IAPIWidget, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_events';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM events');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconClass(): string {
		return 'icon-suitecrm';
	}

	/**
	 * IIconWidget: absolute URL to the widget's icon. NC 30 dashboard app
	 * uses this instead of the CSS class (`getIconClass()`) so the icon can
	 * be rendered outside the app's own stylesheet context.
	 */
	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, 'app.svg')
		);
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * IAPIWidget: return dashboard items as structured data instead of HTML.
	 *
	 * `$since` is an opaque cursor emitted as `sinceId` on the last item of the
	 * previous page. This widget interprets it as a Unix timestamp so pagination
	 * lines up with SuiteCRM's `date_willexecute` filter — matching the Vue
	 * frontend's `eventSinceTimestamp=moment().unix()` behaviour.
	 *
	 * Returns an empty array (rather than throwing) when the user is not
	 * connected or the SuiteCRM instance is unreachable; the dashboard app
	 * treats an empty response as "nothing to show" and falls back to the
	 * empty-content slot.
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$eventSinceTs = $since !== null && ctype_digit($since)
			? (int) $since
			: (new DateTime())->getTimestamp();

		$reminders = $this->service->getReminders(
			$suitecrmUrl,
			$accessToken,
			$userId,
			null,
			null,
			$eventSinceTs,
			null,
			$limit,
		);
		if (isset($reminders['error'])) {
			return [];
		}

		$items = [];
		foreach ($reminders as $reminder) {
			$module = (string) ($reminder['attributes']['related_event_module'] ?? '');
			$elemId = (string) ($reminder['attributes']['related_event_module_id'] ?? '');
			$title = (string) ($reminder['title'] ?? '');
			$whenTs = (int) ($reminder['attributes']['date_willexecute'] ?? 0);

			$items[] = new WidgetItem(
				$title,
				$this->buildSubtitle($module, $whenTs),
				$this->buildEventLink($suitecrmUrl, $module, $elemId),
				$this->iconForModule($module),
				(string) $whenTs,
			);
		}

		return $items;
	}

	private function buildEventLink(string $suitecrmUrl, string $module, string $elemId): string {
		if ($module === '' || $elemId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=' . rawurlencode($module)
			. '&action=DetailView&record=' . rawurlencode($elemId);
	}

	private function buildSubtitle(string $module, int $whenTs): string {
		if ($whenTs <= 0) {
			return $module;
		}
		$date = date('Y-m-d H:i', $whenTs);
		if ($module === 'Calls') {
			return $this->l10n->t('Call at %s', [$date]);
		}
		if ($module === 'Meetings') {
			return $this->l10n->t('Meeting at %s', [$date]);
		}
		return $date;
	}

	private function iconForModule(string $module): string {
		$file = match ($module) {
			'Calls' => 'call.png',
			'Meetings' => 'meeting.png',
			default => 'app.svg',
		};
		return $this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, $file)
		);
	}
}
