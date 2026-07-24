<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Kim Haverblad
 * @license GNU AGPL version 3 or any later version
 *
 * @author Kim Haverblad
 */

namespace OCA\SuiteCRM\Dashboard;

use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;

/**
 * "SuiteCRM Activities" dashboard widget.
 *
 * Sixth widget in the pack after reminder (10), schedule (20), Cases
 * (30), Tasks (40), and pipeline (50). This one is an activity-stream
 * view: what's been touched in the CRM lately across Calls, Meetings,
 * Tasks, and Notes. Distinct from the workload widgets (Cases, Tasks)
 * which surface open items assigned to the user, and from the schedule
 * widget which surfaces upcoming and past-due dated items. The
 * Activities widget answers "what's happening in the CRM right now",
 * subject to SuiteCRM's own ACL layer.
 *
 * Order 60 puts it below the personal workload widgets but above the
 * "who's newly in the CRM" Contacts widget, matching the mental
 * hierarchy of daily-glance material.
 */
class SuiteCRMActivitiesWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_activities';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM Activities');
	}

	public function getOrder(): int {
		return 60;
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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-activities');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * IAPIWidget: return recent activities as structured data.
	 *
	 * Empty array on missing token or upstream error — the dashboard app
	 * then shows the empty state gracefully instead of failing the
	 * whole page render.
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$activities = $this->service->getRecentActivities(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$limit,
		);
		if (isset($activities['error'])) {
			return [];
		}

		$items = [];
		foreach ($activities as $activity) {
			$recordId = (string) ($activity['id'] ?? '');
			$attributes = $activity['attributes'] ?? [];
			$type = (string) ($activity['type'] ?? 'meeting');
			$title = (string) ($attributes['name'] ?? $this->l10n->t('(no title)'));

			$items[] = new WidgetItem(
				$title,
				$this->buildSubtitle($type, $attributes, (int) ($activity['modified_ts'] ?? 0)),
				$this->buildLink($suitecrmUrl, $type, $recordId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
				(string) ($activity['modified_ts'] ?? 0),
			);
		}

		return $items;
	}

	/**
	 * IAPIWidgetV2: wraps getItems() with an activity-specific empty
	 * state so the dashboard reads naturally when there's been no
	 * recent CRM activity in the lookback window.
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->l10n->t('No recent SuiteCRM activity'),
		);
	}

	private function buildLink(string $suitecrmUrl, string $type, string $recordId): string {
		if ($recordId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		// The `type` tag maps back to SuiteCRM's module name for the
		// DetailView deep link. Meetings/Calls/Tasks/Notes are the only
		// four we emit, but a fallthrough default keeps the widget
		// robust against a future ACTIVITY_MODULES addition.
		$module = match ($type) {
			'meeting' => 'Meetings',
			'call'    => 'Calls',
			'task'    => 'Tasks',
			'note'    => 'Notes',
			default   => 'Home',
		};
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=' . $module . '&action=DetailView&record=' . rawurlencode($recordId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildSubtitle(string $type, array $attributes, int $modifiedTs): string {
		$parts = [];
		$typeLabel = match ($type) {
			'meeting' => $this->l10n->t('Meeting'),
			'call'    => $this->l10n->t('Call'),
			'task'    => $this->l10n->t('Task'),
			'note'    => $this->l10n->t('Note'),
			default   => ucfirst($type),
		};
		$parts[] = $typeLabel;
		$assignedUser = isset($attributes['assigned_user_name']) ? (string) $attributes['assigned_user_name'] : '';
		if ($assignedUser !== '') {
			$parts[] = $assignedUser;
		}
		if ($modifiedTs > 0) {
			$parts[] = date('Y-m-d H:i', $modifiedTs);
		}
		return implode(' · ', $parts);
	}
}
