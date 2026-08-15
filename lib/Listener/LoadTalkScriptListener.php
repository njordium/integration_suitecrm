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
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Util;

/**
 * Injects our Talk-integration bundle when a Talk-app page is being
 * rendered. Spreed does NOT ship a first-party `LoadAdditionalScripts`
 * event (verified against spreed main), so the canonical pattern for
 * third-party Talk integrations — used by `nextcloud/bookmarks`'
 * `talk.js` loader and by spreed's own DeckPluginLoader — is to
 * listen on `BeforeTemplateRenderedEvent` and filter by request
 * pathinfo.
 *
 * The bundle registers a per-message action via
 * `window.OCA.Talk.registerMessageAction`; clicking the action opens
 * the shared TalkToNoteModal pre-filled with the conversation the
 * message was posted in. Backend endpoint is the existing `/log-note`
 * — same USER_ALLOWED_KEYS + rate-limit boundaries.
 *
 * No-op on:
 *   - non-logged-in visitors (Talk isn't reachable anyway)
 *   - any path not starting with `/call/` (spreed's routes) so the
 *     script doesn't ship on unrelated pages
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class LoadTalkScriptListener implements IEventListener {

	public function __construct(
		private readonly IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}
		if (!$event->isLoggedIn()) {
			return;
		}
		$path = $this->request->getPathInfo();
		if (!is_string($path) || !str_starts_with($path, '/call/')) {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-talkhook');
	}
}
