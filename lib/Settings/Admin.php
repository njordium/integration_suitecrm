<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

use OCA\SuiteCRM\AppInfo\Application;

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm(): TemplateResponse {
		// Never ship the plaintext OAuth client_secret to the browser. The Vue
		// admin form only needs to know whether one is stored so it can render
		// a "stored — type to replace" placeholder; the value stays on the
		// server until the admin explicitly overwrites it.
		$this->initialStateService->provideInitialState('admin-config', [
			'client_id' => $this->config->getAppValue(Application::APP_ID, 'client_id'),
			'client_secret_set' => ($this->config->getAppValue(Application::APP_ID, 'client_secret') !== ''),
			'oauth_instance_url' => $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'),
		]);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
