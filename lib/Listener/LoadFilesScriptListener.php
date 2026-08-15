<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Kim Haverblad (fork maintainer)
 * @license AGPL-3.0
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Listener;

use OCA\SuiteCRM\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Fires only while the Files app is being rendered — dispatched by
 * `OCA\Files\Event\LoadAdditionalScriptsEvent`, the canonical hook
 * documented in the Nextcloud server source at
 * `apps/files/lib/Event/LoadAdditionalScriptsEvent.php`.
 *
 * Adds our `filesHook` bundle which registers a per-file action via
 * `@nextcloud/files`' `registerFileAction()`; the action opens a
 * mount-on-demand Vue dialog that POSTs to the existing `/log-note`
 * endpoint with the file's Nextcloud shortcut URL as the note body
 * (link-only, no file bytes cross the boundary).
 *
 * Not registered for the sidebar / share / trashbin sub-apps because
 * those trigger their own scoped events; the standard file browser
 * covers the primary use case.
 */
class LoadFilesScriptListener implements IEventListener {

	public function handle(Event $event): void {
		// String class-name comparison — the Files app is a runtime
		// dependency, not a compose-time one, so we don't `use` the
		// FQCN. This lets phpstan build against `nextcloud/ocp` alone
		// (which doesn't ship the Files-app stubs) and keeps the
		// listener a safe no-op if the Files app is absent.
		if (get_class($event) !== 'OCA\\Files\\Event\\LoadAdditionalScriptsEvent') {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-fileshook');
	}
}
