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
 * "My open Tasks" dashboard widget — iter 76.
 *
 * Fourth dashboard widget. Distinct from the calendar widget's Tasks
 * section: the calendar widget is date-oriented and drops both
 * undated Tasks and Tasks whose due date is outside the horizon
 * window. This widget is workload-oriented and surfaces every
 * actionable Task assigned to the user, including undated ones — a
 * common miss in SuiteCRM 8 where reps create Tasks without setting
 * a due date and then never see them again.
 *
 * Order 40 — below the "My open Cases" widget (order 30) so a rep
 * scanning down sees Cases (external escalations) before Tasks
 * (internal workload).
 */
class SuiteCRMTasksWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_tasks';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM Tasks');
	}

	public function getOrder(): int {
		return 40;
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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-tasks');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$tasks = $this->service->getMyTasks(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$limit,
		);
		if (isset($tasks['error'])) {
			return [];
		}

		$now = time();
		$items = [];
		foreach ($tasks as $task) {
			$taskId = (string) ($task['id'] ?? '');
			$attributes = $task['attributes'] ?? [];
			$title = (string) ($attributes['name'] ?? $this->l10n->t('(no title)'));

			$items[] = new WidgetItem(
				$title,
				$this->buildSubtitle($attributes, $task['due_ts'] ?? null, $now),
				$this->buildTaskLink($suitecrmUrl, $taskId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
				(string) ($task['priority_rank'] ?? 99),
			);
		}

		return $items;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->l10n->t('No open SuiteCRM Tasks'),
		);
	}

	private function buildTaskLink(string $suitecrmUrl, string $taskId): string {
		if ($taskId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=Tasks&action=DetailView&record=' . rawurlencode($taskId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildSubtitle(array $attributes, ?int $dueTs, int $nowTs): string {
		$priority = isset($attributes['priority']) ? (string) $attributes['priority'] : '';
		$parts = [];
		if ($priority !== '') {
			$parts[] = $priority;
		}
		if ($dueTs === null) {
			$parts[] = $this->l10n->t('no due date');
		} else {
			$parts[] = $this->buildDueLabel($dueTs, $nowTs);
		}
		return implode(' · ', $parts);
	}

	/**
	 * Server-side rendered due-date label for the API-rendered dashboard
	 * variant. The Vue frontend uses moment() for a locale-aware version;
	 * this fallback keeps IAPIWidget's server-side path informative when
	 * the classic Vue widget isn't mounted.
	 */
	private function buildDueLabel(int $dueTs, int $nowTs): string {
		$diffDays = (int) floor(($dueTs - $nowTs) / 86400);
		if ($diffDays < -1) {
			return $this->l10n->n('overdue by %n day', 'overdue by %n days', -$diffDays);
		}
		if ($diffDays === -1) {
			return $this->l10n->t('due yesterday');
		}
		if ($diffDays === 0) {
			return $this->l10n->t('due today');
		}
		if ($diffDays === 1) {
			return $this->l10n->t('due tomorrow');
		}
		return $this->l10n->n('due in %n day', 'due in %n days', $diffDays);
	}
}
