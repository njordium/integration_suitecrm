<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Reference;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Reference provider that turns SuiteCRM record URLs pasted into Nextcloud
 * (Talk, Notes, Deck, Files comments, ...) into rich preview cards. Also
 * powers the smart picker: users can search across their CRM from any text
 * field via the @ menu.
 */
class SuiteCRMReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {

	private const RECORD_URL_PATTERN = '/\/index\.php\?[^"\s]*module=([A-Za-z]+)(?:&|&amp;)[^"\s]*record=([a-zA-Z0-9\-]+)/';

	private const SUPPORTED_MODULES = [
		'Contacts',
		'Accounts',
		'Leads',
		'Opportunities',
		'Cases',
		'Meetings',
		'Calls',
		'Tasks',
	];

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private SuiteCRMAPIService $service,
		private TokenStorage $tokens,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return 'suitecrm-reference';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-color.svg')
		);
	}

	public function getSupportedSearchProviderIds(): array {
		return ['suitecrm-search'];
	}

	public function matchReference(string $referenceText): bool {
		return $this->extractRecord($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		if (!$this->matchReference($referenceText)) {
			return null;
		}
		$record = $this->extractRecord($referenceText);
		if ($record === null || $this->userId === null) {
			return null;
		}

		[$module, $recordId] = $record;

		$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$accessToken = $this->tokens->getAccessToken($this->userId);
		if ($suitecrmUrl === '' || $accessToken === '') {
			return null;
		}

		$response = $this->service->request(
			$suitecrmUrl,
			$accessToken,
			$this->userId,
			'module/' . $module . '/' . $recordId
		);
		if (isset($response['error']) || !isset($response['data']['attributes'])) {
			return null;
		}
		$attrs = $response['data']['attributes'];

		$reference = new Reference($referenceText);
		$reference->setTitle($this->titleFor($module, $attrs));
		$reference->setDescription($this->descriptionFor($module, $attrs));
		$reference->setImageUrl($this->getIconUrl());
		$reference->setUrl($referenceText);
		$reference->setRichObject('integration_suitecrm', [
			'id' => $recordId,
			'module' => $module,
			'title' => $this->titleFor($module, $attrs),
			'description' => $this->descriptionFor($module, $attrs),
			'attributes' => $attrs,
			'url' => $referenceText,
		]);
		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		$record = $this->extractRecord($referenceId);
		if ($record === null) {
			return '';
		}
		return $record[0] . ':' . $record[1];
	}

	public function getCacheKey(string $referenceId): ?string {
		return $this->userId;
	}

	/**
	 * @return array{0: string, 1: string}|null [module, recordId] or null if the
	 *                                          text doesn't look like a SuiteCRM
	 *                                          record URL.
	 */
	private function extractRecord(string $text): ?array {
		if (!preg_match(self::RECORD_URL_PATTERN, $text, $matches)) {
			return null;
		}
		$module = $matches[1];
		$recordId = $matches[2];
		if (!in_array($module, self::SUPPORTED_MODULES, true)) {
			return null;
		}
		return [$module, $recordId];
	}

	private function titleFor(string $module, array $attrs): string {
		return $attrs['full_name']
			?? $attrs['name']
			?? $this->l10n->t('SuiteCRM %s', [$module]);
	}

	private function descriptionFor(string $module, array $attrs): string {
		return match ($module) {
			'Contacts', 'Leads' => trim(($attrs['title'] ?? '') . ' ' . ($attrs['account_name'] ?? '')),
			'Accounts' => $attrs['industry'] ?? '',
			'Opportunities' => trim(($attrs['sales_stage'] ?? '') . ' — ' . ($attrs['amount'] ?? '')),
			'Cases' => $attrs['status'] ?? '',
			'Meetings', 'Calls' => trim(($attrs['status'] ?? '') . ' — ' . ($attrs['date_start'] ?? '')),
			'Tasks' => trim(($attrs['status'] ?? '') . ' — ' . ($attrs['date_due'] ?? '')),
			default => '',
		};
	}
}
