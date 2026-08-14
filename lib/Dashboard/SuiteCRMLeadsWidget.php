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
 * "SuiteCRM Leads" dashboard widget.
 *
 * Recently-added Leads within the caller's SuiteCRM ACL. Sibling to
 * the Contacts and Accounts widgets — same shape, different bean.
 * Ordered 90, closing out the "discovery" cluster (Contacts 70,
 * Accounts 80, Leads 90). Splitting Leads into its own widget rather
 * than adding a scope toggle to the Contacts widget matches how
 * SuiteCRM's own home page groups these into separate "My Contacts"
 * and "My Leads" panels — users enable exactly the cuts they want.
 *
 * Subline carries `lead_source` and `status` in addition to
 * `account_name`, so a rep can distinguish a fresh Web-form capture
 * ("New / Web") from an already-worked cold call ("In Process /
 * Cold Call") at a glance without opening the record.
 */
class SuiteCRMLeadsWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_leads';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM: Leads');
	}

	public function getOrder(): int {
		return 90;
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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-recentleads');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$leads = $this->service->getRecentLeads(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$limit,
		);
		if (isset($leads['error'])) {
			return [];
		}

		$items = [];
		foreach ($leads as $lead) {
			$leadId = (string) ($lead['id'] ?? '');
			$attributes = $lead['attributes'] ?? [];

			$items[] = new WidgetItem(
				$this->buildTitle($attributes),
				$this->buildSubtitle($attributes, (int) ($lead['entered_ts'] ?? 0)),
				$this->buildLeadLink($suitecrmUrl, $leadId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-color.svg')),
				(string) ($lead['entered_ts'] ?? 0),
			);
		}

		return $items;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->l10n->t('No recently added SuiteCRM Leads'),
		);
	}

	private function buildLeadLink(string $suitecrmUrl, string $leadId): string {
		if ($leadId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=Leads&action=DetailView&record=' . rawurlencode($leadId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildTitle(array $attributes): string {
		$firstName = isset($attributes['first_name']) ? trim((string) $attributes['first_name']) : '';
		$lastName = isset($attributes['last_name']) ? trim((string) $attributes['last_name']) : '';
		$full = trim($firstName . ' ' . $lastName);
		if ($full !== '') {
			return $full;
		}
		// Email-only lead capture (typical Web-form path) still needs a
		// clickable label; fall back through email then to the safety
		// placeholder rather than rendering an empty row.
		$email = isset($attributes['email1']) ? (string) $attributes['email1'] : '';
		if ($email !== '') {
			return $email;
		}
		return $this->l10n->t('(no name)');
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function buildSubtitle(array $attributes, int $enteredTs): string {
		$parts = [];
		$account = isset($attributes['account_name']) ? (string) $attributes['account_name'] : '';
		if ($account !== '') {
			$parts[] = $account;
		}
		$status = isset($attributes['status']) ? (string) $attributes['status'] : '';
		if ($status !== '') {
			$parts[] = $status;
		}
		$leadSource = isset($attributes['lead_source']) ? (string) $attributes['lead_source'] : '';
		if ($leadSource !== '') {
			$parts[] = $leadSource;
		}
		if ($enteredTs > 0) {
			$parts[] = date('Y-m-d', $enteredTs);
		}
		return implode(' · ', $parts);
	}
}
