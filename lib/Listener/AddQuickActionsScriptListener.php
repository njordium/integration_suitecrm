<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Kim Haverblad
 * @license GNU AGPL version 3 or any later version
 *
 * @author Kim Haverblad
 */

namespace OCA\SuiteCRM\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Util;

use OCA\SuiteCRM\AppInfo\Application;

/**
 * Injects the global Quick Actions floating action button on every
 * Nextcloud page render.
 *
 * Runs on `BeforeTemplateRenderedEvent`, which fires whenever Nextcloud is
 * about to render a full HTML template (i.e. not on AJAX or OCS calls). That
 * scopes the script inclusion to actual page loads and keeps API traffic
 * lean.
 *
 * The listener is a no-op for unauthenticated visitors, since the FAB has
 * nothing useful to offer them. The frontend script does its own connection
 * check before rendering the button, so a signed-in user without SuiteCRM
 * credentials also sees nothing (avoiding a dead-end button that opens a
 * modal to "please connect first").
 *
 * @implements IEventListener<BeforeTemplateRenderedEvent>
 */
class AddQuickActionsScriptListener implements IEventListener {

	public function __construct(
		private IUserSession $userSession,
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		// Per-user opt-out: users who prefer to reach the write actions
		// via Personal Settings (or via a custom key binding, or not at
		// all) can uncheck the toggle and skip the FAB entirely. Missing
		// row defaults to '1' so behaviour is unchanged for anyone who
		// has never touched the setting.
		$enabled = $this->config->getUserValue(
			$user->getUID(), Application::APP_ID, 'quick_actions_enabled', '1'
		);
		if ($enabled !== '1') {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-quickactions');
	}
}
