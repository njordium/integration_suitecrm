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
 * "SuiteCRM Accounts" dashboard widget.
 *
 * Recently-added Accounts within the caller's SuiteCRM ACL. Sibling to
 * the Contacts widget introduced in 2.5.0 — same shape, different bean.
 * Ordered 80 so it sits below the personal workload and activity
 * cluster (30-60) and next to the other "discovery" widgets (Contacts
 * at 70, Leads at 90). Users enable exactly the discovery cuts they
 * care about.
 */
class SuiteCRMAccountsWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_accounts';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM: Accounts');
	}

	public function getOrder(): int {
		return 80;
	}

	public function getIconClass(): string {
		return 'icon-suitecrm';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, 'app-color.svg')
		);
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-recentaccounts');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$accounts = $this->service->getRecentAccounts(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$limit,
		);
		if (isset($accounts['error'])) {
			return [];
		}

		$items = [];
		foreach ($accounts as $account) {
			$accountId = (string) ($account['id'] ?? '');
			$attributes = $account['attributes'] ?? [];

			$items[] = new WidgetItem(
				(string) ($attributes['name'] ?? $this->l10n->t('(no name)')),
				$this->buildSubtitle($attributes, (int) ($account['entered_ts'] ?? 0)),
				$this->buildAccountLink($suitecrmUrl, $accountId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-color.svg')),
				(string) ($account['entered_ts'] ?? 0),
			);
		}

		return $items;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->l10n->t('No recently added SuiteCRM Accounts'),
		);
	}

	private function buildAccountLink(string $suitecrmUrl, string $accountId): string {
		if ($accountId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=Accounts&action=DetailView&record=' . rawurlencode($accountId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildSubtitle(array $attributes, int $enteredTs): string {
		$parts = [];
		$industry = isset($attributes['industry']) ? (string) $attributes['industry'] : '';
		if ($industry !== '') {
			$parts[] = $industry;
		}
		if ($enteredTs > 0) {
			$parts[] = date('Y-m-d', $enteredTs);
		}
		return implode(' · ', $parts);
	}
}
