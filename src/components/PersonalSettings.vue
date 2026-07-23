<!--
	@Code Changes by: Kim Haverblad, 2026
-->
<template>
	<div id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('njordium_suitecrm', 'SuiteCRM integration') }}
		</h2>

		<NcNoteCard v-if="!oAuthConfigured" type="warning">
			{{ t('njordium_suitecrm', 'No SuiteCRM OAuth app configured. Ask your Nextcloud administrator to configure SuiteCRM connected accounts admin section.') }}
		</NcNoteCard>

		<div v-else id="suitecrm-content">
			<div class="fields">
				<NcTextField
					v-model="state.oauth_instance_url"
					:label="t('njordium_suitecrm', 'SuiteCRM instance address')"
					:placeholder="t('njordium_suitecrm', 'https://my.suitecrm.org')"
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
						{{ t('njordium_suitecrm', 'Connect via SuiteCRM OAuth (recommended)') }}
					</NcButton>
				</div>
				<p class="settings-hint">
					{{ t('njordium_suitecrm', 'You will be redirected to your SuiteCRM instance to sign in and approve access. This is the recommended, more secure connect path.') }}
				</p>

				<details class="advanced-fallback">
					<summary>
						{{ t('njordium_suitecrm', 'Advanced: username + password fallback (SuiteCRM legacy grant)') }}
					</summary>
					<NcNoteCard type="info">
						{{ t('njordium_suitecrm', 'Only use this if your SuiteCRM instance cannot complete a browser redirect back to Nextcloud. Your login and password are not stored, they are only used once to obtain an access token.') }}
					</NcNoteCard>
					<div class="fields">
						<NcTextField
							v-model="login"
							:label="t('njordium_suitecrm', 'User name')"
							:placeholder="t('njordium_suitecrm', 'SuiteCRM login')"
							@keyup.enter="onConnect" />

						<NcPasswordField
							v-model="password"
							:label="t('njordium_suitecrm', 'Password')"
							:placeholder="t('njordium_suitecrm', 'SuiteCRM password')"
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
							{{ t('njordium_suitecrm', 'Connect with username + password') }}
						</NcButton>
					</div>
				</details>
			</template>

			<div v-if="connected" class="actions">
				<span class="connected-label">
					<CheckCircleIcon :size="20" class="connected-icon" />
					{{ t('njordium_suitecrm', 'Connected as {user}', { user: state.user_name }) }}
				</span>
				<NcButton variant="secondary" @click="onLogoutClick">
					<template #icon>
						<LogoutIcon :size="20" />
					</template>
					{{ t('njordium_suitecrm', 'Disconnect from SuiteCRM') }}
				</NcButton>
			</div>

			<div v-if="connected" class="toggles">
				<NcCheckboxRadioSwitch
					:modelValue="!!state.search_enabled"
					@update:modelValue="onSearchChange">
					{{ t('njordium_suitecrm', 'Enable unified search for contacts, accounts, leads, opportunities and cases') }}
				</NcCheckboxRadioSwitch>
				<NcNoteCard v-if="state.search_enabled" type="warning">
					{{ t('njordium_suitecrm', 'Warning, everything you type in the search bar will be sent to your SuiteCRM instance.') }}
				</NcNoteCard>

				<NcCheckboxRadioSwitch
					:modelValue="!!state.notification_enabled"
					@update:modelValue="onNotificationChange">
					{{ t('njordium_suitecrm', 'Enable notifications for reminders on calls and meetings') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="connected" class="suitecrm-widget-prefs">
				<h3 class="suitecrm-widget-prefs__heading">
					<ViewDashboardOutlineIcon :size="20" />
					{{ t('njordium_suitecrm', 'Dashboard widget preferences') }}
				</h3>
				<div class="suitecrm-widget-prefs__toggle">
					<NcCheckboxRadioSwitch
						:modelValue="!!state.calendar_show_tasks"
						@update:modelValue="onCalendarShowTasksChange">
						{{ t('njordium_suitecrm', 'Include Tasks in the Calendar widget') }}
					</NcCheckboxRadioSwitch>
					<p class="settings-hint">
						{{ t('njordium_suitecrm', 'When enabled, the Calendar widget lists SuiteCRM Tasks alongside Meetings and Calls (dated Tasks within the horizon window). Turn off if you already use the standalone "My open Tasks" widget and want to stop seeing the same Task in both places. Takes effect on the next widget refresh.') }}
					</p>
				</div>
				<div class="suitecrm-widget-prefs__group">
					<span class="suitecrm-widget-prefs__group-label">
						{{ t('njordium_suitecrm', 'Pipeline widget mode') }}
					</span>
					<div
						v-for="option in pipelineModeOptions"
						:key="option.value"
						class="suitecrm-widget-prefs__radio">
						<input
							:id="'pipeline-mode-' + option.value"
							type="radio"
							name="pipeline_mode"
							:value="option.value"
							:checked="pipelineMode === option.value"
							@change="onPipelineModeChange(option.value)">
						<label :for="'pipeline-mode-' + option.value">{{ option.label }}</label>
					</div>
				</div>
				<p class="settings-hint">
					{{ pipelineModeHint }}
				</p>
			</div>

			<div v-if="connected" class="suitecrm-quick-actions">
				<h3 class="suitecrm-quick-actions__heading">
					<PlusBoxOutlineIcon :size="20" />
					{{ t('njordium_suitecrm', 'Quick actions to SuiteCRM') }}
				</h3>
				<p class="settings-hint">
					{{ t('njordium_suitecrm', 'Capture something from Nextcloud into your SuiteCRM record. Each action creates a linked SuiteCRM record and opens the confirmation in the SuiteCRM UI.') }}
				</p>
				<div class="suitecrm-quick-actions__buttons">
					<NcButton variant="secondary" @click="openTalkModal">
						<template #icon>
							<MessageTextOutlineIcon :size="20" />
						</template>
						{{ t('njordium_suitecrm', 'Log Talk conversation …') }}
					</NcButton>
					<NcButton variant="secondary" @click="openDeckModal">
						<template #icon>
							<CardsOutlineIcon :size="20" />
						</template>
						{{ t('njordium_suitecrm', 'Link Deck card …') }}
					</NcButton>
					<NcButton variant="secondary" @click="openEmailModal">
						<template #icon>
							<EmailOutlineIcon :size="20" />
						</template>
						{{ t('njordium_suitecrm', 'Convert email to Case …') }}
					</NcButton>
				</div>
				<div class="suitecrm-quick-actions__fab-toggle">
					<NcCheckboxRadioSwitch
						:modelValue="!!state.quick_actions_enabled"
						@update:modelValue="onQuickActionsFabChange">
						{{ t('njordium_suitecrm', 'Show the floating Quick Actions button on every page') }}
					</NcCheckboxRadioSwitch>
					<p class="settings-hint">
						{{ t('njordium_suitecrm', 'When enabled, a "+" button appears in the bottom-right of every Nextcloud page for one-click access to the actions above. Turn off if you prefer to reach the actions from this settings page only. Takes effect on the next page reload.') }}
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
					{{ t('njordium_suitecrm', 'Calendar sync (SuiteCRM module)') }}
				</h3>
				<NcNoteCard type="info">
					{{ t('njordium_suitecrm', 'The companion SuiteCRM module pulls your Nextcloud calendar into SuiteCRM and pushes Meetings/Calls back. Configure it inside SuiteCRM (User Profile → Nextcloud Calendar Integration) with the values below.') }}
				</NcNoteCard>
				<div v-if="companion" class="suitecrm-companion__rows">
					<div class="suitecrm-companion__row">
						<label>{{ t('njordium_suitecrm', 'Nextcloud URL') }}</label>
						<code>{{ companion.nextcloud_url }}</code>
						<NcButton variant="tertiary" @click="copy(companion.nextcloud_url, $event)">
							<template #icon>
								<ContentCopyIcon :size="18" />
							</template>
							{{ t('njordium_suitecrm', 'Copy') }}
						</NcButton>
					</div>
					<div class="suitecrm-companion__row">
						<label>{{ t('njordium_suitecrm', 'Nextcloud login') }}</label>
						<code>{{ companion.login }}</code>
						<NcButton variant="tertiary" @click="copy(companion.login, $event)">
							<template #icon>
								<ContentCopyIcon :size="18" />
							</template>
							{{ t('njordium_suitecrm', 'Copy') }}
						</NcButton>
					</div>
					<div class="suitecrm-companion__row">
						<NcButton variant="secondary" :href="companion.app_password_url" target="_blank">
							<template #icon>
								<KeyPlusIcon :size="20" />
							</template>
							{{ t('njordium_suitecrm', 'Generate Nextcloud App Password') }}
						</NcButton>
					</div>
				</div>
				<p v-else class="settings-hint">
					{{ t('njordium_suitecrm', 'Loading companion details…') }}
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
import ViewDashboardOutlineIcon from 'vue-material-design-icons/ViewDashboardOutline.vue'
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
		ViewDashboardOutlineIcon,
	},

	props: {},

	data() {
		return {
			state: loadState('njordium_suitecrm', 'user-config'),
			login: '',
			password: '',
			loading: false,
			authorizing: false,
			companion: null,
			// Which of the write-feature modals is open. null when none.
			// Not a set of individual flags because the modals are
			// mutually exclusive (only one dialog can be open at once).
			quickAction: null,
			// Pipeline widget framing preference. Kept in component state
			// (rather than reading state.pipeline_mode directly on each
			// render) so NcSelect's v-model works without a two-way
			// computed. Backend validates the value against
			// SuiteCRMAPIService::PIPELINE_MODES on read; an unknown
			// string here falls back silently to the default.
			pipelineMode: loadState('njordium_suitecrm', 'user-config').pipeline_mode || 'closing_quarter',
		}
	},

	computed: {
		pipelineModeOptions() {
			return [
				{ label: t('njordium_suitecrm', 'Closing this quarter'), value: 'closing_quarter' },
				{ label: t('njordium_suitecrm', 'Top value'), value: 'top_value' },
				{ label: t('njordium_suitecrm', 'Weighted value (amount × probability)'), value: 'weighted' },
			]
		},

		pipelineModeHint() {
			if (this.pipelineMode === 'top_value') {
				return t('njordium_suitecrm', 'The pipeline widget lists your open Opportunities sorted by amount, largest first, regardless of close date. Deals with no amount sort last.')
			}
			if (this.pipelineMode === 'weighted') {
				return t('njordium_suitecrm', 'The pipeline widget lists your open Opportunities sorted by forecast-weighted value (amount × probability), largest first. Matches the way finance tracks pipeline.')
			}
			return t('njordium_suitecrm', 'The pipeline widget lists your open Opportunities whose close date falls in the current calendar quarter, earliest first.')
		},

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
			showSuccess(t('njordium_suitecrm', 'Successfully connected to SuiteCRM!'))
		} else if (zmToken === 'error') {
			const message = urlParams.get('message') || t('njordium_suitecrm', 'Unknown error')
			showError(t('njordium_suitecrm', 'OAuth access token could not be obtained:') + ' ' + message)
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
				const response = await axios.get(generateUrl('/apps/njordium_suitecrm/calendar-companion'))
				this.companion = response.data
			} catch {
				// Companion is a best-effort enhancement; failure is silent so it
				// doesn't block the rest of the personal settings UI.
			}
		},

		async copy(value, event) {
			try {
				await navigator.clipboard.writeText(value)
				showSuccess(t('njordium_suitecrm', 'Copied to clipboard'))
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

		onPipelineModeChange(value) {
			// NcCheckboxRadioSwitch in radio mode emits the selected
			// `value` prop directly (a string), so we can store it as-is.
			this.pipelineMode = value
			this.saveOptions({ pipeline_mode: value })
		},

		onCalendarShowTasksChange(checked) {
			// Widget picks up the change on the next 120s poll; no need
			// to force a reload. The service reads the pref inside
			// getUpcoming() so both the Vue-mounted and the server-side
			// dashboard-API render paths respect it uniformly.
			this.state.calendar_show_tasks = checked
			this.saveOptions({ calendar_show_tasks: checked ? '1' : '0' })
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
			const url = generateUrl('/apps/njordium_suitecrm/config')
			axios.put(url, req)
				.then(() => {
					showSuccess(t('njordium_suitecrm', 'SuiteCRM options saved'))
				})
				.catch((error) => {
					showError(t('njordium_suitecrm', 'Failed to save SuiteCRM options')
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
				const url = generateUrl('/apps/njordium_suitecrm/oauth-authorize-url')
				const response = await axios.get(url)
				if (response.data && response.data.authorize_url) {
					window.location = response.data.authorize_url
					// leave `authorizing = true`, the whole page is about to unload.
					return
				}
				showError(t('njordium_suitecrm', 'OAuth is not configured on the server.'))
			} catch (error) {
				if (error.response?.data?.error) {
					showError(t('njordium_suitecrm', 'Failed to start OAuth flow') + ': ' + error.response.data.error)
				} else {
					showError(t('njordium_suitecrm', 'Failed to start OAuth flow'))
				}
			} finally {
				this.authorizing = false
			}
		},

		onConnect() {
			this.loading = true
			const url = generateUrl('/apps/njordium_suitecrm/oauth-connect')
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
							showError(t('njordium_suitecrm', 'Failed')
								+ ': ' + error.response.data.error)
						} else if (error.response.request && error.response.request.responseText) {
							showError(t('njordium_suitecrm', 'Failed')
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

	.suitecrm-widget-prefs__heading,
	.suitecrm-quick-actions__heading {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-block-start: 24px;
	}

	.suitecrm-widget-prefs__toggle {
		margin-block-start: 12px;
		max-width: 500px;
	}

	.suitecrm-widget-prefs {
		&__group {
			margin-block-start: 12px;
			display: flex;
			flex-direction: column;
			gap: 6px;
			max-width: 500px;
		}

		&__group-label {
			font-weight: 500;
			margin-block-end: 4px;
		}

		&__radio {
			display: flex;
			align-items: center;
			gap: 8px;

			input[type="radio"] {
				accent-color: var(--color-primary-element);
				cursor: pointer;
			}

			label {
				cursor: pointer;
				color: var(--color-main-text);
			}
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
	background-image: url(./../../img/app-dark.svg);
	background-size: 23px 23px;
	height: 23px;
	width: 23px;
	display: inline-block;
}

body.theme--dark .icon-suitecrm {
	background-image: url(./../../img/app.svg);
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
