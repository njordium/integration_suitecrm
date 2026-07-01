<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Julien Veyssier
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\SuiteCRM\Search;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCA\SuiteCRM\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

class SuiteCRMSearchProvider implements IProvider {

	/** @var IAppManager */
	private $appManager;

	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IConfig */
	private $config;
	/** @var SuiteCRMAPIService */
	private $service;
	/** @var TokenStorage */
	private $tokens;

	public function __construct(IAppManager $appManager,
								IL10N $l10n,
								IConfig $config,
								IURLGenerator $urlGenerator,
								SuiteCRMAPIService $service,
								TokenStorage $tokens) {
		$this->appManager = $appManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
		$this->tokens = $tokens;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'suitecrm-search';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('SuiteCRM');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer SuiteCRM results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

//		$theme = $this->config->getUserValue($user->getUID(), 'accessibility', 'theme', '');
		$thumbnailUrl = $this->urlGenerator->imagePath(Application::APP_ID, 'app-color.svg');

		$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$accessToken = $this->tokens->getAccessToken($user->getUID());

		$searchEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_enabled', '0') === '1';
		if ($accessToken === '' || !$searchEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$searchResults = $this->service->search($suitecrmUrl, $accessToken, $user->getUID(), $term, $offset, $limit);

		if (isset($searchResults['error'])) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$formattedResults = array_map(function (array $entry) use ($thumbnailUrl, $suitecrmUrl): SuiteCRMSearchResultEntry {
			return new SuiteCRMSearchResultEntry(
				$this->getThumbnailUrl($entry, $thumbnailUrl),
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToSuiteCRM($entry, $suitecrmUrl),
				'',
				false
			);
		}, $searchResults);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * Maps the `type` tag emitted by SuiteCRMAPIService::search() to the
	 * corresponding SuiteCRM v8 module segment for detail-view URLs.
	 */
	private const TYPE_TO_MODULE = [
		'contact' => 'Contacts',
		'account' => 'Accounts',
		'lead' => 'Leads',
		'opportunity' => 'Opportunities',
		'case' => 'Cases',
		'meeting' => 'Meetings',
		'task' => 'Tasks',
		'email' => 'Emails',
	];

	protected function getMainText(array $entry): string {
		$attrs = $entry['attributes'] ?? [];
		return match ($entry['type'] ?? '') {
			'contact', 'lead' => $attrs['full_name'] ?? $attrs['name'] ?? '',
			'account', 'opportunity', 'case', 'meeting', 'task', 'email' => $attrs['name'] ?? '',
			default => '',
		};
	}

	protected function getSubline(array $entry): string {
		$attrs = $entry['attributes'] ?? [];
		return match ($entry['type'] ?? '') {
			'contact' => '👤 ' . $this->l10n->t('Contact'),
			'account' => '🛡 ' . $this->l10n->t('Account'),
			'lead' => '💥 ' . $this->l10n->t('Lead'),
			'opportunity' => '💡 ' . $this->l10n->t('Opportunity')
				. ' (' . ($attrs['amount'] ?? '') . ' '
				. ($attrs['currency_symbol'] ?? $attrs['currency_name'] ?? '') . ')',
			'case' => '📁 ' . $this->l10n->t('Case')
				. (isset($attrs['case_number']) ? ' #' . $attrs['case_number'] : ''),
			'meeting' => '📅 ' . $this->l10n->t('Meeting')
				. $this->formatDate($attrs['date_start'] ?? null),
			'task' => '✅ ' . $this->l10n->t('Task')
				. $this->formatDate($attrs['date_due'] ?? null)
				. (isset($attrs['priority']) ? ' [' . $attrs['priority'] . ']' : ''),
			'email' => '✉ ' . $this->l10n->t('Email')
				. (isset($attrs['from_addr_name']) && $attrs['from_addr_name'] !== ''
					? ' — ' . $attrs['from_addr_name'] : ''),
			default => '',
		};
	}

	protected function getLinkToSuiteCRM(array $entry, string $url): string {
		$module = self::TYPE_TO_MODULE[$entry['type'] ?? ''] ?? null;
		if ($module === null) {
			return '';
		}
		return $url . '/index.php?module=' . $module . '&action=DetailView&record=' . $entry['id'];
	}

	private function formatDate(?string $iso): string {
		if ($iso === null || $iso === '') {
			return '';
		}
		try {
			$date = new \DateTimeImmutable($iso);
			return ' — ' . $date->format('Y-m-d H:i');
		} catch (\Exception) {
			return '';
		}
	}

	/**
	 * @param array $entry
	 * @param string $thumbnailUrl
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry, string $thumbnailUrl): string {
		return $thumbnailUrl;
	}
}
