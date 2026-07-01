<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\SuiteCRM\AppInfo\Application;

/**
 * Nextcloud home dashboard widget listing upcoming SuiteCRM Meetings, Calls,
 * and Tasks assigned to the current user.
 *
 * Separate from {@see SuiteCRMWidget} (reminders); this one is
 * schedule-oriented rather than reminder/notification-oriented.
 */
class SuiteCRMCalendarWidget implements IWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return 'suitecrm_calendar';
	}

	public function getTitle(): string {
		return $this->l10n->t('SuiteCRM calendar');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-suitecrm';
	}

	public function getUrl(): ?string {
		return $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-calendar');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}
}
