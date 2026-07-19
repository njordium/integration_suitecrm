<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

use OCA\SuiteCRM\AppInfo\Application;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
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

		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = ($this->config->getAppValue(Application::APP_ID, 'client_secret') !== '');
		$oauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');

		$this->initialStateService->provideInitialState('user-config', [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'oauth_instance_url' => $oauthUrl,
			'search_enabled' => ($searchEnabled === '1'),
			'notification_enabled' => ($notificationEnabled === '1'),
			'user_name' => $userName,
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
