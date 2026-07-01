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
			<NcNoteCard v-if="!connected" type="info">
				{{ t('integration_suitecrm', 'Your login and password are not stored. They are just used once to get an access token which will be used to interact with your account.') }}
			</NcNoteCard>

			<div class="fields">
				<NcTextField
					v-model="state.oauth_instance_url"
					:label="t('integration_suitecrm', 'SuiteCRM instance address')"
					:placeholder="t('integration_suitecrm', 'https://my.suitecrm.org')"
					:disabled="true" />

				<NcTextField
					v-if="!connected"
					v-model="login"
					:label="t('integration_suitecrm', 'User name')"
					:placeholder="t('integration_suitecrm', 'SuiteCRM login')"
					@keyup.enter="onConnect" />

				<NcPasswordField
					v-if="!connected"
					v-model="password"
					:label="t('integration_suitecrm', 'Password')"
					:placeholder="t('integration_suitecrm', 'SuiteCRM password')"
					@keyup.enter="onConnect" />
			</div>

			<div class="actions">
				<NcButton
					v-if="!connected"
					variant="primary"
					:disabled="loading"
					@click="onConnect">
					<template #icon>
						<LoginIcon :size="20" />
					</template>
					{{ t('integration_suitecrm', 'Connect to SuiteCRM') }}
				</NcButton>

				<template v-if="connected">
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
				</template>
			</div>

			<div v-if="connected" class="toggles">
				<NcCheckboxRadioSwitch
					:modelValue="!!state.search_enabled"
					@update:checked="onSearchChange">
					{{ t('integration_suitecrm', 'Enable unified search for contacts, accounts, leads, opportunities and cases') }}
				</NcCheckboxRadioSwitch>
				<NcNoteCard v-if="state.search_enabled" type="warning">
					{{ t('integration_suitecrm', 'Warning, everything you type in the search bar will be sent to your SuiteCRM instance.') }}
				</NcNoteCard>

				<NcCheckboxRadioSwitch
					:modelValue="!!state.notification_enabled"
					@update:checked="onNotificationChange">
					{{ t('integration_suitecrm', 'Enable notifications for reminders on calls and meetings') }}
				</NcCheckboxRadioSwitch>
			</div>

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
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import KeyPlusIcon from 'vue-material-design-icons/KeyPlus.vue'
import LoginIcon from 'vue-material-design-icons/Login.vue'
import LogoutIcon from 'vue-material-design-icons/Logout.vue'

export default {
	name: 'PersonalSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		CalendarSyncIcon,
		CheckCircleIcon,
		ContentCopyIcon,
		KeyPlusIcon,
		LoginIcon,
		LogoutIcon,
	},

	props: {},

	data() {
		return {
			state: loadState('integration_suitecrm', 'user-config'),
			login: '',
			password: '',
			loading: false,
			companion: null,
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
			showError(t('integration_suitecrm', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
		this.loadCompanion()
	},

	methods: {
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
