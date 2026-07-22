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
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;

/**
 * "My pipeline" dashboard widget — iter 77.
 *
 * Fifth dashboard widget. Framing is user-selectable via the
 * `pipeline_mode` personal preference (see PersonalSettings.vue):
 *
 *  - closing_quarter: Opportunities whose close_date falls in the
 *    current calendar quarter, earliest first. Matches the way
 *    reps use SuiteCRM's own Pipeline dashboard.
 *  - top_value: Opportunities sorted by amount DESC.
 *  - weighted: Opportunities sorted by amount × probability/100 DESC.
 *
 * All three modes filter out terminal sales_stages (Closed Won,
 * Closed Lost) client-side. Order 50 — bottom of the SuiteCRM widget
 * stack, since pipeline value tends to be a strategic morning check
 * rather than an operational one.
 */
class SuiteCRMPipelineWidget implements IWidget, IAPIWidget, IAPIWidgetV2, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IConfig $config,
		private IAppConfig $appConfig,
		private TokenStorage $tokens,
		private SuiteCRMAPIService $service,
	) {
	}

	public function getId(): string {
		return 'suitecrm_pipeline';
	}

	public function getTitle(): string {
		return $this->l10n->t('My SuiteCRM pipeline');
	}

	public function getOrder(): int {
		return 50;
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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-pipeline');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$accessToken = $this->tokens->getAccessToken($userId);
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		if ($accessToken === '' || $suitecrmUrl === '') {
			return [];
		}

		$mode = $this->config->getUserValue(
			$userId, Application::APP_ID, 'pipeline_mode', SuiteCRMAPIService::DEFAULT_PIPELINE_MODE
		);
		$opportunities = $this->service->getMyPipeline(
			$suitecrmUrl,
			$accessToken,
			$userId,
			$mode,
			$limit,
		);
		if (isset($opportunities['error'])) {
			return [];
		}

		$items = [];
		foreach ($opportunities as $index => $opp) {
			$oppId = (string) ($opp['id'] ?? '');
			$attributes = $opp['attributes'] ?? [];
			$title = (string) ($attributes['name'] ?? $this->l10n->t('(no title)'));

			$items[] = new WidgetItem(
				$title,
				$this->buildSubtitle($mode, $attributes, $opp),
				$this->buildOpportunityLink($suitecrmUrl, $oppId),
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')),
				// Sort key: index within the already-sorted array so
				// the dashboard app preserves getMyPipeline()'s ordering.
				(string) $index,
			);
		}

		return $items;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->getItems($userId, $since, $limit);
		return new WidgetItems(
			$items,
			$this->buildEmptyStateMessage($userId),
		);
	}

	private function buildOpportunityLink(string $suitecrmUrl, string $oppId): string {
		if ($oppId === '') {
			return rtrim($suitecrmUrl, '/');
		}
		return rtrim($suitecrmUrl, '/')
			. '/index.php?module=Opportunities&action=DetailView&record=' . rawurlencode($oppId);
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @param array<string, mixed> $opp Full row with tagged fields.
	 */
	private function buildSubtitle(string $mode, array $attributes, array $opp): string {
		$amount = (float) ($opp['amount_num'] ?? 0);
		$symbol = (string) ($attributes['currency_symbol'] ?? '');
		$probability = (float) ($opp['probability_num'] ?? 0);
		$stage = (string) ($attributes['sales_stage'] ?? '');
		$parts = [];
		if ($stage !== '') {
			$parts[] = $stage;
		}
		if ($mode === 'weighted') {
			$weighted = (float) ($opp['weighted_num'] ?? 0);
			$parts[] = $this->l10n->t('%s%s weighted (of %s%s at %d%%)', [
				$symbol,
				$this->formatMoney($weighted),
				$symbol,
				$this->formatMoney($amount),
				(int) $probability,
			]);
		} elseif ($mode === 'top_value') {
			$parts[] = $symbol . $this->formatMoney($amount);
			if ($probability > 0) {
				$parts[] = $this->l10n->t('%d%% probability', [(int) $probability]);
			}
		} else {
			// closing_quarter
			$closeTs = $opp['close_ts'] ?? null;
			if ($closeTs !== null) {
				$parts[] = $this->l10n->t('closes %s', [date('Y-m-d', (int) $closeTs)]);
			}
			$parts[] = $symbol . $this->formatMoney($amount);
		}
		return implode(' · ', $parts);
	}

	private function formatMoney(float $amount): string {
		return number_format($amount, 0, '.', ',');
	}

	private function buildEmptyStateMessage(string $userId): string {
		$mode = $this->config->getUserValue(
			$userId, Application::APP_ID, 'pipeline_mode', SuiteCRMAPIService::DEFAULT_PIPELINE_MODE
		);
		if ($mode === 'top_value' || $mode === 'weighted') {
			return $this->l10n->t('No open SuiteCRM Opportunities');
		}
		return $this->l10n->t('No SuiteCRM Opportunities closing this quarter');
	}
}
