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
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}
		if ($this->userSession->getUser() === null) {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-quickactions');
	}
}
