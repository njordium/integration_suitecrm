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
 * "SuiteCRM Contacts" dashboard widget.
 *
 * Seventh widget in the pack. Answers a specific question: "who has
 * been added to the CRM lately, subject to my ACL". Separate from the
 * unified search (which needs a query string) and from the Activities
 * widget (which is a change-feed across activity-type modules only,
 * excluding person records by design).
 *
 * Order 70 puts it below Activities (60) and above any future
 * KPI-tile or company-wide summary widget. The Contacts widget is
 * discovery-oriented, so it sits after the workload/schedule/activity
 * cluster.
 */
class SuiteCRMContactsWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_contacts';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM Contacts');
	}

	public function getOrder(): int {
		return 70;
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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-recentcontacts');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * IAPIWidget: return recently-added Contacts as structured data.
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$contacts = $this->service->getRecentContacts(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$limit,
		);
		if (isset($contacts['error'])) {
			return [];
		}

		$items = [];
		foreach ($contacts as $contact) {
			$contactId = (string) ($contact['id'] ?? '');
			$attributes = $contact['attributes'] ?? [];

			$items[] = new WidgetItem(
				$this->buildTitle($attributes),
				$this->buildSubtitle($attributes, (int) ($contact['entered_ts'] ?? 0)),
				$this->buildContactLink($suitecrmUrl, $contactId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
				(string) ($contact['entered_ts'] ?? 0),
			);
		}

		return $items;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->l10n->t('No recently added SuiteCRM Contacts'),
		);
	}

	private function buildContactLink(string $suitecrmUrl, string $contactId): string {
		if ($contactId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=Contacts&action=DetailView&record=' . rawurlencode($contactId);
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
		// Fall back to email or "(no name)" so a row with only an email
		// captured still renders something the user can click through.
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
		if ($enteredTs > 0) {
			$parts[] = date('Y-m-d', $enteredTs);
		}
		return implode(' · ', $parts);
	}
}
