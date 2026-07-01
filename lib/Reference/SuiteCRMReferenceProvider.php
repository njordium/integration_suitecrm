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
		return RecordUrlParser::parse($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$record = RecordUrlParser::parse($referenceText);
		if ($record === null || $this->userId === null) {
			return null;
		}

		$module = $record['module'];
		$recordId = $record['recordId'];

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
		$record = RecordUrlParser::parse($referenceId);
		if ($record === null) {
			return '';
		}
		return $record['module'] . ':' . $record['recordId'];
	}

	public function getCacheKey(string $referenceId): ?string {
		return $this->userId;
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
