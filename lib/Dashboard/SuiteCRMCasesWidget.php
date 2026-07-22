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
 * "My open Cases" dashboard widget.
 *
 * Third widget in the roadmap after the classic reminder widget
 * ({@see SuiteCRMWidget}) and the schedule widget
 * ({@see SuiteCRMCalendarWidget}). This one is service-desk oriented
 * rather than schedule oriented: the rep or support agent opens
 * Nextcloud in the morning and sees exactly the SuiteCRM Cases still
 * waiting on them, priority-ranked.
 *
 * Data-shape choices deliberately mirror {@see SuiteCRMCalendarWidget}:
 * IAPIWidget + IAPIWidgetV2 + IIconWidget, and the same dual-mode
 * (Vue mount via {@see load()} plus server-side rendering via
 * {@see getItems()}) so this widget behaves identically on both the
 * legacy dashboard and the NC 30+ API-rendered dashboard.
 *
 * Backlog note: the same structure can be reused for the
 * "My open Tasks" and "My pipeline" widgets so it pays to keep the
 * pattern strictly parallel here.
 */
class SuiteCRMCasesWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_cases';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM Cases');
	}

	public function getOrder(): int {
		// Sits between the reminder widget (10) and the schedule widget (20)
		// is tempting, but the schedule widget is time-sensitive and should
		// come first thing in the morning; put Cases below it so a rep
		// checking the dashboard sees "what's happening today" first,
		// "what's still open" second.
		return 30;
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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-cases');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * IAPIWidget: return open Cases as structured data for the dashboard app.
	 *
	 * `$since` is threaded through unused for the same reason as
	 * {@see SuiteCRMCalendarWidget::getItems()}, {@see SuiteCRMAPIService::getMyCases()}
	 * already scopes to the caller and priority-sorts, so cursor paging
	 * would only matter for tenants with hundreds of open Cases per rep.
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

		$cases = $this->service->getMyCases(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$limit,
		);
		if (isset($cases['error'])) {
			return [];
		}

		$items = [];
		foreach ($cases as $case) {
			$caseId = (string) ($case['id'] ?? '');
			$attributes = $case['attributes'] ?? [];
			$name = (string) ($attributes['name'] ?? $this->l10n->t('(no title)'));
			$caseNumber = (string) ($attributes['case_number'] ?? '');
			$title = $caseNumber !== ''
				? sprintf('#%s · %s', $caseNumber, $name)
				: $name;

			$items[] = new WidgetItem(
				$title,
				$this->buildSubtitle($attributes, (int) ($case['age_days'] ?? 0)),
				$this->buildCaseLink($suitecrmUrl, $caseId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
				(string) ($case['priority_rank'] ?? 99),
			);
		}

		return $items;
	}

	/**
	 * IAPIWidgetV2: wrap getItems() output in a {@see WidgetItems} envelope
	 * so the dashboard app renders "No open SuiteCRM Cases" instead of the
	 * generic "No entries" placeholder.
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->l10n->t('No open SuiteCRM Cases'),
		);
	}

	private function buildCaseLink(string $suitecrmUrl, string $caseId): string {
		if ($caseId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=Cases&action=DetailView&record=' . rawurlencode($caseId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildSubtitle(array $attributes, int $ageDays): string {
		$priority = isset($attributes['priority']) ? (string) $attributes['priority'] : '';
		$status = isset($attributes['status']) ? (string) $attributes['status'] : '';
		$ageLabel = $ageDays > 0
			? $this->l10n->n('%n day open', '%n days open', $ageDays)
			: $this->l10n->t('opened today');
		$parts = [];
		if ($priority !== '') {
			$parts[] = $priority;
		}
		if ($status !== '') {
			$parts[] = $status;
		}
		$parts[] = $ageLabel;
		return implode(' · ', $parts);
	}
}
