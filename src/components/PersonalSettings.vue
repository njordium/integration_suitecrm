<!--
	@Code Changes by: Kim Haverblad, 2026
-->
<template>
	<div id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('integration_suitecrm', 'SuiteCRM integration') }}
		</h2>

		<NcNoteCard v-if="!oAuthConfigured" type="warning">
			{{ t('integration_suitecrm', 'No SuiteCRM OAuth app configured. Ask your Nextcloud administrator to configure SuiteCRM connected accounts admin section.') }}
		</NcNoteCard>

		<div v-else id="suitecrm-content">
			<div class="fields">
				<NcTextField
					v-model="state.oauth_instance_url"
					:label="t('integration_suitecrm', 'SuiteCRM instance address')"
					:placeholder="t('integration_suitecrm', 'https://my.suitecrm.org')"
					:disabled="true" />
			</div>

			<template v-if="!connected">
				<div class="actions">
					<NcButton
						variant="primary"
						:disabled="authorizing"
						@click="onOAuthConnect">
						<template #icon>
							<LoginIcon :size="20" />
						</template>
						{{ t('integration_suitecrm', 'Connect via SuiteCRM OAuth (recommended)') }}
					</NcButton>
				</div>
				<p class="settings-hint">
					{{ t('integration_suitecrm', 'You will be redirected to your SuiteCRM instance to sign in and approve access. This is the recommended, more secure connect path.') }}
				</p>

				<details class="advanced-fallback">
					<summary>
						{{ t('integration_suitecrm', 'Advanced: username + password fallback (SuiteCRM legacy grant)') }}
					</summary>
					<NcNoteCard type="info">
						{{ t('integration_suitecrm', 'Only use this if your SuiteCRM instance cannot complete a browser redirect back to Nextcloud. Your login and password are not stored, they are only used once to obtain an access token.') }}
					</NcNoteCard>
					<div class="fields">
						<NcTextField
							v-model="login"
							:label="t('integration_suitecrm', 'User name')"
							:placeholder="t('integration_suitecrm', 'SuiteCRM login')"
							@keyup.enter="onConnect" />

						<NcPasswordField
							v-model="password"
							:label="t('integration_suitecrm', 'Password')"
							:placeholder="t('integration_suitecrm', 'SuiteCRM password')"
							@keyup.enter="onConnect" />
					</div>
					<div class="actions">
						<NcButton
							variant="secondary"
							:disabled="loading"
							@click="onConnect">
							<template #icon>
								<LoginIcon :size="20" />
							</template>
							{{ t('integration_suitecrm', 'Connect with username + password') }}
						</NcButton>
					</div>
				</details>
			</template>

			<div v-if="connected" class="actions">
				<span class="connected-label">
					<CheckCircleIcon :size="20" class="connected-icon" />
					{{ t('integration_suitecrm', 'Connected as {user}', { user: state.user_name }) }}
				</span>
				<NcButton variant="secondary" @click="onLogoutClick">
					<template #icon>
						<LogoutIcon :size="20" />
					</template>
					{{ t('integration_suitecrm', 'Disconnect from SuiteCRM') }}
				</NcButton>
			</div>

			<div v-if="connected" class="toggles">
				<NcCheckboxRadioSwitch
					:modelValue="!!state.search_enabled"
					@update:modelValue="onSearchChange">
					{{ t('integration_suitecrm', 'Enable unified search for contacts, accounts, leads, opportunities and cases') }}
				</NcCheckboxRadioSwitch>
				<NcNoteCard v-if="state.search_enabled" type="warning">
					{{ t('integration_suitecrm', 'Warning, everything you type in the search bar will be sent to your SuiteCRM instance.') }}
				</NcNoteCard>

				<NcCheckboxRadioSwitch
					:modelValue="!!state.notification_enabled"
					@update:modelValue="onNotificationChange">
					{{ t('integration_suitecrm', 'Enable notifications for reminders on calls and meetings') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="connected" class="suitecrm-override">
				<label for="scrm-override-user" class="suitecrm-override__label">
					{{ t('integration_suitecrm', 'Query as a different SuiteCRM username') }}
				</label>
				<NcTextField
					id="scrm-override-user"
					v-model="overrideUserName"
					:placeholder="state.user_name"
					@change="onOverrideChange" />
				<p class="settings-hint">
					{{ t('integration_suitecrm', 'Widgets filter SuiteCRM data by this username (assigned to me, my open cases, my pipeline, etc.). Leave empty to use the OAuth-connected user shown above. Set this only when your OAuth account and the account whose records you want on the dashboard are different — e.g. an SSO login that differs from your SuiteCRM username, or a shared / bot user. Takes effect on the next widget refresh.') }}
				</p>
			</div>

			<div v-if="connected" class="suitecrm-quick-actions">
				<h3 class="suitecrm-quick-actions__heading">
					<PlusBoxOutlineIcon :size="20" />
					{{ t('integration_suitecrm', 'Quick actions to SuiteCRM') }}
				</h3>
				<p class="settings-hint">
					{{ t('integration_suitecrm', 'Capture something from Nextcloud into your SuiteCRM record. Each action creates a linked SuiteCRM record and opens the confirmation in the SuiteCRM UI.') }}
				</p>
				<div class="suitecrm-quick-actions__buttons">
					<NcButton variant="secondary" @click="openTalkModal">
						<template #icon>
							<MessageTextOutlineIcon :size="20" />
						</template>
						{{ t('integration_suitecrm', 'Log Talk conversation …') }}
					</NcButton>
					<NcButton variant="secondary" @click="openDeckModal">
						<template #icon>
							<CardsOutlineIcon :size="20" />
						</template>
						{{ t('integration_suitecrm', 'Link Deck card …') }}
					</NcButton>
					<NcButton variant="secondary" @click="openEmailModal">
						<template #icon>
							<EmailOutlineIcon :size="20" />
						</template>
						{{ t('integration_suitecrm', 'Convert email to Case …') }}
					</NcButton>
				</div>
				<div class="suitecrm-quick-actions__fab-toggle">
					<NcCheckboxRadioSwitch
						:modelValue="!!state.quick_actions_enabled"
						@update:modelValue="onQuickActionsFabChange">
						{{ t('integration_suitecrm', 'Show the floating Quick Actions button on every page') }}
					</NcCheckboxRadioSwitch>
					<p class="settings-hint">
						{{ t('integration_suitecrm', 'When enabled, a "+" button appears in the bottom-right of every Nextcloud page for one-click access to the actions above. Turn off if you prefer to reach the actions from this settings page only. Takes effect on the next page reload.') }}
					</p>
				</div>
			</div>

			<TalkToNoteModal
				:open="quickAction === 'talk'"
				@close="quickAction = null" />
			<LinkDeckCardModal
				:open="quickAction === 'deck'"
				@close="quickAction = null" />
			<EmailToCaseModal
				:open="quickAction === 'email'"
				@close="quickAction = null" />

			<div v-if="connected" class="suitecrm-companion">
				<h3>
					<CalendarSyncIcon :size="20" class="companion-heading-icon" />
					{{ t('integration_suitecrm', 'Calendar sync (SuiteCRM module)') }}
				</h3>
				<NcNoteCard type="info">
					{{ t('integration_suitecrm', 'The companion SuiteCRM module pulls your Nextcloud calendar into SuiteCRM and pushes Meetings/Calls back. Configure it inside SuiteCRM (User Profile → Nextcloud Calendar Integration) with the values below.') }}
				</NcNoteCard>
				<div v-if="companion" class="suitecrm-companion__rows">
					<div class="suitecrm-companion__row">
						<label>{{ t('integration_suitecrm', 'Nextcloud URL') }}</label>
						<code>{{ companion.nextcloud_url }}</code>
						<NcButton variant="tertiary" @click="copy(companion.nextcloud_url, $event)">
							<template #icon>
								<ContentCopyIcon :size="18" />
							</template>
							{{ t('integration_suitecrm', 'Copy') }}
						</NcButton>
					</div>
					<div class="suitecrm-companion__row">
						<label>{{ t('integration_suitecrm', 'Nextcloud login') }}</label>
						<code>{{ companion.login }}</code>
						<NcButton variant="tertiary" @click="copy(companion.login, $event)">
							<template #icon>
								<ContentCopyIcon :size="18" />
							</template>
							{{ t('integration_suitecrm', 'Copy') }}
						</NcButton>
					</div>
					<div class="suitecrm-companion__row">
						<NcButton variant="secondary" :href="companion.app_password_url" target="_blank">
							<template #icon>
								<KeyPlusIcon :size="20" />
							</template>
							{{ t('integration_suitecrm', 'Generate Nextcloud App Password') }}
						</NcButton>
					</div>
				</div>
				<p v-else class="settings-hint">
					{{ t('integration_suitecrm', 'Loading companion details…') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import CardsOutlineIcon from 'vue-material-design-icons/CardsOutline.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import KeyPlusIcon from 'vue-material-design-icons/KeyPlus.vue'
import LoginIcon from 'vue-material-design-icons/Login.vue'
import LogoutIcon from 'vue-material-design-icons/Logout.vue'
import MessageTextOutlineIcon from 'vue-material-design-icons/MessageTextOutline.vue'
import PlusBoxOutlineIcon from 'vue-material-design-icons/PlusBoxOutline.vue'
import EmailToCaseModal from './EmailToCaseModal.vue'
import LinkDeckCardModal from './LinkDeckCardModal.vue'
import TalkToNoteModal from './TalkToNoteModal.vue'

export default {
	name: 'PersonalSettings',

	components: {
		EmailToCaseModal,
		LinkDeckCardModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		TalkToNoteModal,
		CalendarSyncIcon,
		CardsOutlineIcon,
		CheckCircleIcon,
		ContentCopyIcon,
		EmailOutlineIcon,
		KeyPlusIcon,
		LoginIcon,
		LogoutIcon,
		MessageTextOutlineIcon,
		PlusBoxOutlineIcon,
	},

	props: {},

	data() {
		return {
			state: loadState('integration_suitecrm', 'user-config'),
			login: '',
			password: '',
			loading: false,
			authorizing: false,
			companion: null,
			// Which of the write-feature modals is open. null when none.
			// Not a set of individual flags because the modals are
			// mutually exclusive (only one dialog can be open at once).
			quickAction: null,
			// "Query as a different SuiteCRM username" — mirrors the same
			// field in integration_forgejo_gitea. Empty string = use the
			// OAuth-connected user (default). Non-empty = the backend
			// resolves this SuiteCRM user_name to its user_id and uses
			// that id in the assigned_user_id filter every widget sends.
			overrideUserName: loadState('integration_suitecrm', 'user-config').override_user_name || '',
		}
	},

	computed: {
		oAuthConfigured() {
			return this.state.oauth_instance_url && this.state.client_id && this.state.client_secret
		},

		connected() {
			return this.oAuthConfigured && this.state.user_name && this.state.user_name !== ''
		},
	},

	mounted() {
		const paramString = window.location.search.substr(1)

		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('suitecrmToken')
		if (zmToken === 'success') {
			showSuccess(t('integration_suitecrm', 'Successfully connected to SuiteCRM!'))
		} else if (zmToken === 'error') {
			const message = urlParams.get('message') || t('integration_suitecrm', 'Unknown error')
			showError(t('integration_suitecrm', 'OAuth access token could not be obtained:') + ' ' + message)
		}
		this.loadCompanion()
	},

	methods: {
		openTalkModal() {
			this.quickAction = 'talk'
		},

		openDeckModal() {
			this.quickAction = 'deck'
		},

		openEmailModal() {
			this.quickAction = 'email'
		},

		async loadCompanion() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/calendar-companion'))
				this.companion = response.data
			} catch {
				// Companion is a best-effort enhancement; failure is silent so it
				// doesn't block the rest of the personal settings UI.
			}
		},

		async copy(value, event) {
			try {
				await navigator.clipboard.writeText(value)
				showSuccess(t('integration_suitecrm', 'Copied to clipboard'))
			} catch {
				const range = document.createRange()
				range.selectNodeContents(event.target.previousElementSibling)
				const selection = window.getSelection()
				selection.removeAllRanges()
				selection.addRange(range)
			}
		},

		onLogoutClick() {
			this.state.user_name = ''
			this.saveOptions({ user_name: '' })
		},

		onNotificationChange(checked) {
			this.state.notification_enabled = checked
			this.saveOptions({ notification_enabled: checked ? '1' : '0' })
		},

		onSearchChange(checked) {
			this.state.search_enabled = checked
			this.saveOptions({ search_enabled: checked ? '1' : '0' })
		},

		onOverrideChange() {
			// Trim whitespace before persisting so a stray leading/trailing
			// space does not defeat the backend's exact user_name match.
			// Empty string is a valid state (clears the override).
			const value = String(this.overrideUserName || '').trim()
			this.overrideUserName = value
			this.saveOptions({ override_user_name: value })
		},

		onQuickActionsFabChange(checked) {
			// Server-side listener reads this on the next page render and
			// skips the script tag, so the change takes effect after the
			// user navigates or reloads. We deliberately do not force a
			// reload here to avoid interrupting whatever the user is doing
			// in the settings page.
			this.state.quick_actions_enabled = checked
			this.saveOptions({ quick_actions_enabled: checked ? '1' : '0' })
		},

		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_suitecrm/config')
			axios.put(url, req)
				.then(() => {
					showSuccess(t('integration_suitecrm', 'SuiteCRM options saved'))
				})
				.catch((error) => {
					showError(t('integration_suitecrm', 'Failed to save SuiteCRM options')
						+ ': ' + error.response.request.responseText)
				})
				.then(() => {
					this.loading = false
				})
		},

		// Primary connect path. Ask the server for a state-bound authorize
		// URL, then hand the browser off to SuiteCRM. The callback controller
		// finishes the flow and redirects the user back here.
		async onOAuthConnect() {
			this.authorizing = true
			try {
				const url = generateUrl('/apps/integration_suitecrm/oauth-authorize-url')
				const response = await axios.get(url)
				if (response.data && response.data.authorize_url) {
					window.location = response.data.authorize_url
					// leave `authorizing = true`, the whole page is about to unload.
					return
				}
				showError(t('integration_suitecrm', 'OAuth is not configured on the server.'))
			} catch (error) {
				if (error.response?.data?.error) {
					showError(t('integration_suitecrm', 'Failed to start OAuth flow') + ': ' + error.response.data.error)
				} else {
					showError(t('integration_suitecrm', 'Failed to start OAuth flow'))
				}
			} finally {
				this.authorizing = false
			}
		},

		onConnect() {
			this.loading = true
			const url = generateUrl('/apps/integration_suitecrm/oauth-connect')
			const req = {
				login: this.login,
				password: this.password,
			}
			axios.post(url, req)
				.then((response) => {
					this.state.user_name = response.data.user_name
					this.password = ''
				})
				.catch((error) => {
					if (error.response) {
						if (error.response?.data?.error) {
							showError(t('integration_suitecrm', 'Failed')
								+ ': ' + error.response.data.error)
						} else if (error.response.request && error.response.request.responseText) {
							showError(t('integration_suitecrm', 'Failed')
								+ ': ' + error.response.request.responseText)
						}
					}
				})
				.then(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
#suitecrm_prefs {
	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	#suitecrm-content {
		margin-inline-start: 30px;
	}

	.fields {
		display: flex;
		flex-direction: column;
		gap: 12px;
		max-width: 500px;
		margin-block-start: 12px;
	}

	.actions {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-block-start: 16px;
		flex-wrap: wrap;
	}

	.connected-label {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		color: var(--color-success);
	}

	.connected-icon {
		color: var(--color-success);
	}

	.toggles {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin-block-start: 24px;
		max-width: 500px;
	}

	.settings-hint {
		color: var(--color-text-maxcontrast);
		margin-block-start: 8px;
		max-width: 500px;
	}

	// Scoped h3 layout so the icon aligns inline with the section heading
	// text instead of floating far to the right.
	.suitecrm-quick-actions__fab-toggle {
		margin-block-start: 20px;
		padding-block-start: 12px;
		border-block-start: 1px solid var(--color-border);
		max-width: 500px;
	}

	.suitecrm-quick-actions__heading {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-block-start: 24px;
	}

	.suitecrm-override {
		margin-block-start: 24px;
		padding-block-start: 20px;
		border-block-start: 1px solid var(--color-border);
		max-width: 500px;

		&__label {
			display: block;
			font-weight: 500;
			margin-block-end: 6px;
		}
	}

	.advanced-fallback {
		margin-block-start: 24px;
		max-width: 500px;

		summary {
			cursor: pointer;
			color: var(--color-text-maxcontrast);
			padding-block: 6px;
			user-select: none;
		}

		summary:hover {
			color: var(--color-main-text);
		}

		.fields {
			margin-block-start: 8px;
		}
	}
}

.icon-suitecrm {
	background-image: url(./../../img/app-color.svg);
	background-size: 23px 23px;
	height: 23px;
	width: 23px;
	display: inline-block;
}

.suitecrm-companion {
	margin-block-start: 32px;
	padding-block-start: 20px;
	border-block-start: 1px solid var(--color-border);

	h3 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-block-end: 8px;
	}

	.suitecrm-companion__rows {
		margin-block-start: 12px;
	}

	.suitecrm-companion__row {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-block-end: 8px;
		flex-wrap: wrap;

		label {
			min-width: 150px;
			color: var(--color-text-maxcontrast);
		}

		code {
			background: var(--color-background-dark);
			padding-block: 4px;
			padding-inline: 8px;
			border-radius: 4px;
			font-family: monospace;
			user-select: all;
		}
	}
}
</style>
