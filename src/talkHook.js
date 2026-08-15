/**
 * Talk → SuiteCRM in-context action.
 *
 * Registers a per-message action via `window.OCA.Talk.registerMessageAction`
 * (public API since Talk 15 / Nextcloud 22). Click any message's kebab
 * menu in a Talk conversation → "Log conversation to SuiteCRM" → the
 * shared TalkToNoteModal opens pre-filled with the conversation the
 * user was in, so they only pick the SuiteCRM record + how many
 * recent messages to include.
 *
 * Loaded via LoadTalkScriptListener → `Util::addScript` fires only
 * while the Talk (spreed) app is rendering (`/call/…` path match on
 * BeforeTemplateRenderedEvent; spreed itself has no first-party
 * "additional scripts" event we could hook), so the script never
 * mounts on unrelated pages.
 *
 * Guards on `window.OCA?.Talk?.registerMessageAction` so a Talk
 * install older than 15 or a missing spreed install cleanly no-ops
 * instead of throwing at load time.
 *
 * @author Kim Haverblad
 */
import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import TalkToNoteModal from './components/TalkToNoteModal.vue'

/**
 *
 * @param root0
 * @param root0.conversationToken
 * @param root0.conversationName
 */
function openDialog({ conversationToken, conversationName }) {
	// Same mount-on-demand pattern as filesHook.js — no idle-page cost
	// and stack-two-dialogs prevention via a unique DOM id check.
	const existing = document.getElementById('suitecrm-talk-dialog-mount')
	if (existing) {
		return
	}
	const mount = document.createElement('div')
	mount.id = 'suitecrm-talk-dialog-mount'
	document.body.appendChild(mount)

	const app = createApp(TalkToNoteModal, {
		open: true,
		prefillToken: conversationToken,
		prefillLabel: conversationName,
		onClose: () => {
			app.unmount()
			mount.remove()
		},
	})
	app.mount(mount)
}

document.addEventListener('DOMContentLoaded', () => {
	// Talk's public JS API — bail politely if the current Talk install
	// is too old (<15) or spreed isn't present. Same graceful-degrade
	// pattern nextcloud/bookmarks' talk.js uses.
	if (!window.OCA?.Talk?.registerMessageAction) {
		return
	}

	window.OCA.Talk.registerMessageAction({
		label: t('integration_suitecrm', 'Log conversation to SuiteCRM'),
		icon: 'icon-external',
		callback: ({ metadata }) => {
			// metadata = { token, name, displayName, type, ... }
			// Grab both fields so the modal can show the human name in
			// its header while still fetching messages by token.
			openDialog({
				conversationToken: metadata?.token || '',
				conversationName: metadata?.displayName || metadata?.name || '',
			})
		},
	})
})
