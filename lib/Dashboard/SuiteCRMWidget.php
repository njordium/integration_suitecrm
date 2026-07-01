<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Julien Veyssier <eneiluj@posteo.net>
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\SuiteCRM\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\SuiteCRM\AppInfo\Application;

class SuiteCRMWidget implements IWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return 'suitecrm_events';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM events');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconClass(): string {
		return 'icon-suitecrm';
	}

	public function getUrl(): ?string {
		return $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}
}
