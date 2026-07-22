<?php
declare(strict_types=1);
/**
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Settings\ISettings;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private IInitialState $initialStateService,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		// IConfig::getUserValue requires a string uid; $this->userId is nullable
		// (guests, background contexts, cli), so guard here rather than blowing
		// up when the settings page is rendered outside a normal session.
		$userId = $this->userId ?? '';

		$userName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$searchEnabled = $this->config->getUserValue($userId, Application::APP_ID, 'search_enabled', '0');
		$notificationEnabled = $this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0');
		// Pipeline widget framing preference. Default falls back to
		// closing_quarter for fresh installs; an unknown value on disk
		// (Studio-customised install, hand-edited row) also snaps to the
		// default so the widget never crashes on a bad preference string.
		$pipelineMode = $this->config->getUserValue($userId, Application::APP_ID, 'pipeline_mode', SuiteCRMAPIService::DEFAULT_PIPELINE_MODE);
		if (!in_array($pipelineMode, SuiteCRMAPIService::PIPELINE_MODES, true)) {
			$pipelineMode = SuiteCRMAPIService::DEFAULT_PIPELINE_MODE;
		}
		// Global Quick Actions FAB opt-out. Default '1' so the button
		// stays visible for anyone who hasn't touched the toggle; the
		// listener also defaults to enabled when the row is missing,
		// so this row is really only written when the user unchecks.
		$quickActionsEnabled = $this->config->getUserValue($userId, Application::APP_ID, 'quick_actions_enabled', '1');

		$clientID = $this->appConfig->getValueString(Application::APP_ID, 'client_id');
		$clientSecret = ($this->appConfig->getValueString(Application::APP_ID, 'client_secret') !== '');
		$oauthUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');

		$this->initialStateService->provideInitialState('user-config', [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'oauth_instance_url' => $oauthUrl,
			'search_enabled' => ($searchEnabled === '1'),
			'notification_enabled' => ($notificationEnabled === '1'),
			'user_name' => $userName,
			'pipeline_mode' => $pipelineMode,
			'quick_actions_enabled' => ($quickActionsEnabled === '1'),
		]);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
