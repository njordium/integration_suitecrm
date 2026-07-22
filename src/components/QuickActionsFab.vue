<template>
	<div v-if="connected" class="suitecrm-fab">
		<NcActions
			:menuTitle="t('njordium_suitecrm', 'Log to SuiteCRM')"
			:open="menuOpen"
			container=".suitecrm-fab"
			@update:open="menuOpen = $event">
			<template #icon>
				<PlusBoxOutlineIcon :size="28" />
			</template>
			<NcActionButton @click="openTalk">
				<template #icon>
					<MessageTextOutlineIcon :size="20" />
				</template>
				{{ t('njordium_suitecrm', 'Log Talk conversation …') }}
			</NcActionButton>
			<NcActionButton @click="openDeck">
				<template #icon>
					<CardsOutlineIcon :size="20" />
				</template>
				{{ t('njordium_suitecrm', 'Link Deck card …') }}
			</NcActionButton>
			<NcActionButton @click="openEmail">
				<template #icon>
					<EmailOutlineIcon :size="20" />
				</template>
				{{ t('njordium_suitecrm', 'Convert email to Case …') }}
			</NcActionButton>
			<NcActionCaption :name="shortcutHint" />
		</NcActions>

		<TalkToNoteModal
			:open="quickAction === 'talk'"
			@close="quickAction = null" />
		<LinkDeckCardModal
			:open="quickAction === 'deck'"
			@close="quickAction = null" />
		<EmailToCaseModal
			:open="quickAction === 'email'"
			@close="quickAction = null" />
	</div>
</template>

<script>
/**
 * QuickActionsFab — iter 79.
 *
 * Floating action button anchored to the bottom-right of every
 * Nextcloud page. Clicking the button opens a menu of the three
 * write-side Quick Actions; each menu item opens its dedicated modal.
 * The modals themselves are the same components used in Personal
 * Settings — no duplication of the fetch/format/submit logic.
 *
 * Visibility gate:
 *   1. `connected` — from the /connection-state endpoint. False for
 *      signed-in users who haven't linked SuiteCRM yet. The FAB stays
 *      hidden rather than opening a modal to "connect first" — that
 *      would be an annoying dead end.
 *   2. `dismissed` (session-scoped) — a user can press Escape to close
 *      the menu; the button itself stays put, matching the Nextcloud
 *      Talk floating chat button behaviour.
 *
 * Keyboard shortcut:
 *   Cmd/Ctrl+Shift+K toggles the menu open. Once open, 1/2/3 select
 *   the corresponding action (Talk / Deck / Email). Escape closes the
 *   menu. Reference: the shortcut matches Slack's "jump to" pattern
 *   so it stays familiar for the CRM-adjacent user population.
 *
 * @author Kim Haverblad
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import CardsOutlineIcon from 'vue-material-design-icons/CardsOutline.vue'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import MessageTextOutlineIcon from 'vue-material-design-icons/MessageTextOutline.vue'
import PlusBoxOutlineIcon from 'vue-material-design-icons/PlusBoxOutline.vue'
import EmailToCaseModal from './EmailToCaseModal.vue'
import LinkDeckCardModal from './LinkDeckCardModal.vue'
import TalkToNoteModal from './TalkToNoteModal.vue'

export default {
	name: 'QuickActionsFab',

	components: {
		CardsOutlineIcon,
		EmailOutlineIcon,
		EmailToCaseModal,
		LinkDeckCardModal,
		MessageTextOutlineIcon,
		NcActionButton,
		NcActionCaption,
		NcActions,
		PlusBoxOutlineIcon,
		TalkToNoteModal,
	},

	data() {
		return {
			connected: false,
			menuOpen: false,
			quickAction: null,
		}
	},

	computed: {
		shortcutHint() {
			// User-agent-aware label so Mac users see Cmd, others see Ctrl.
			const isMac = /Mac|iPod|iPhone|iPad/.test(navigator.platform)
			const prefix = isMac ? '⌘' : 'Ctrl'
			return t('njordium_suitecrm', 'Shortcut: {p}+Shift+K', { p: prefix })
		},
	},

	async mounted() {
		await this.checkConnected()
		if (this.connected) {
			document.addEventListener('keydown', this.onGlobalKeydown)
		}
	},

	beforeUnmount() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
	},

	methods: {
		async checkConnected() {
			try {
				// The /url endpoint returns the SuiteCRM instance URL when
				// the user is linked, and either a 400 or an empty string
				// otherwise. That's the same probe the calendar widget
				// uses to decide whether to launch its polling loop.
				const response = await axios.get(generateUrl('/apps/njordium_suitecrm/url'))
				this.connected = !!response.data && response.data.length > 0
			} catch {
				this.connected = false
			}
		},

		onGlobalKeydown(event) {
			// Toggle: Cmd/Ctrl + Shift + K
			if (event.key === 'K' && event.shiftKey && (event.metaKey || event.ctrlKey)) {
				event.preventDefault()
				this.menuOpen = !this.menuOpen
				return
			}
			if (!this.menuOpen) {
				return
			}
			// Once the menu is open, 1/2/3 select the corresponding action.
			if (event.key === '1') {
				event.preventDefault()
				this.openTalk()
			} else if (event.key === '2') {
				event.preventDefault()
				this.openDeck()
			} else if (event.key === '3') {
				event.preventDefault()
				this.openEmail()
			}
		},

		openTalk() {
			this.menuOpen = false
			this.quickAction = 'talk'
		},

		openDeck() {
			this.menuOpen = false
			this.quickAction = 'deck'
		},

		openEmail() {
			this.menuOpen = false
			this.quickAction = 'email'
		},
	},
}
</script>

<style scoped lang="scss">
.suitecrm-fab {
	position: fixed;
	inset-inline-end: 20px;
	bottom: 20px;
	z-index: 2000;

	// Overlay a filled-circle styling on top of NcActions' trigger to
	// give it the classic floating-action-button look; NcActions is
	// designed to sit inline in a header, not float, so a few local
	// overrides do the visual work.
	:deep(.action-item__menutoggle) {
		width: 56px;
		height: 56px;
		border-radius: 28px;
		background-color: var(--color-primary-element);
		color: var(--color-primary-element-text);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);

		&:hover,
		&:focus {
			background-color: var(--color-primary-element-hover);
		}

		svg {
			fill: var(--color-primary-element-text);
		}
	}
}
</style>
