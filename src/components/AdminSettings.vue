<!--
	@Code Changes by: Kim Haverblad, 2026
-->
<template>
	<div id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('integration_suitecrm', 'SuiteCRM integration') }}
		</h2>

		<NcNoteCard type="info">
			<p>
				{{ t('integration_suitecrm', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a SuiteCRM instance, create a "new password client" in your SuiteCRM admin settings ("OAuth2 Clients and Tokens" section) and put the client ID and secret below.') }}
			</p>
			<p>
				{{ t('integration_suitecrm', 'Make sure you created private and public keys for your SuiteCRM instance. Authentication won\'t work if those keys are missing.') }}
				<a
					href="https://docs.suitecrm.com/developer/api/developer-setup-guide/json-api/#_generate_private_and_public_key_for_oauth2"
					target="_blank"
					rel="noopener noreferrer"
					class="external-link">
					{{ t('integration_suitecrm', 'SuiteCRM OAuth2 documentation') }}
					<OpenInNewIcon :size="14" />
				</a>
			</p>
		</NcNoteCard>

		<div class="fields">
			<NcTextField
				v-model="state.oauth_instance_url"
				:label="t('integration_suitecrm', 'SuiteCRM instance address')"
				:placeholder="t('integration_suitecrm', 'https://my.suitecrm.org')"
				@update:value="onInput" />

			<NcPasswordField
				v-model="state.client_id"
				:label="t('integration_suitecrm', 'Application ID')"
				:placeholder="t('integration_suitecrm', 'ID of your application')"
				@update:value="onInput" />

			<NcPasswordField
				v-model="newSecret"
				:label="t('integration_suitecrm', 'Application secret')"
				:placeholder="secretPlaceholder"
				@update:value="onInput" />

			<!--
				Iteration 21 (Finding 2): the OAuth authorize endpoint path is
				now editable from the admin UI. Fresh SuiteCRM 8.4+ installs
				don't need to touch this; installs upgraded from 7.x or fronted
				by a rewriting proxy sometimes do.
			-->
			<NcTextField
				v-model="state.oauth_authorize_path"
				:label="t('integration_suitecrm', 'OAuth authorize endpoint path')"
				:helperText="t('integration_suitecrm', '(SuiteCRM 8.10.x default: /Api/authorize. Older installs may use /legacy/oauth2/authorize.)')"
				@update:value="onInput" />
		</div>

		<!--
			Iteration 51 (upstream issue #14): "Reset connection" affordance.
			Closes the case where an admin picked the wrong OAuth2 client type
			in SuiteCRM (password vs authorization code) or seeded a bad
			client_secret and had no visible way to start over. The button
			opens a confirmation dialog; on confirm we DELETE the admin-config
			endpoint and clear the local form state.
		-->
		<div class="reset-zone">
			<h3>{{ t('integration_suitecrm', 'Reset connection') }}</h3>
			<p class="reset-explanation">
				{{ t('integration_suitecrm', 'Clears the SuiteCRM instance URL, client ID, client secret, and authorize path. Use this to start over after entering the wrong credentials, or when moving to a different SuiteCRM instance. Individual users stay connected until their next SuiteCRM request; they are then prompted to reconnect via the OAuth flow.') }}
			</p>
			<NcButton variant="warning" @click="showResetDialog = true">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('integration_suitecrm', 'Reset connection') }}
			</NcButton>
		</div>

		<NcDialog
			v-if="showResetDialog"
			:open="showResetDialog"
			:name="t('integration_suitecrm', 'Reset SuiteCRM connection?')"
			:message="t('integration_suitecrm', 'This will clear the SuiteCRM instance URL, client ID, client secret, and authorize path from the admin configuration. Individual users stay connected until their next SuiteCRM request, then reconnect through the OAuth flow. This cannot be undone.')"
			:buttons="resetDialogButtons"
			@close="showResetDialog = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',

	components: {
		DeleteIcon,
		NcButton,
		NcDialog,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		OpenInNewIcon,
	},

	props: {},

	data() {
		return {
			state: loadState('integration_suitecrm', 'admin-config'),
			// Secret input is separate from `state` so we can distinguish
			// "user typed a new value" (send it) from "user hasn't touched
			// this field" (leave the stored secret untouched).
			newSecret: '',
			// Iteration 51: reset confirmation dialog visibility.
			showResetDialog: false,
		}
	},

	computed: {
		/**
		 * Supports both the current PHP payload (client_secret_set: bool) and the
		 * legacy payload where client_secret was a plaintext string. Legacy is
		 * kept for one release so a partial deploy doesn't break the UI.
		 */
		secretIsStored() {
			if (this.state.client_secret_set === true) {
				return true
			}
			return typeof this.state.client_secret === 'string' && this.state.client_secret !== ''
		},

		secretPlaceholder() {
			return this.secretIsStored
				? t('integration_suitecrm', 'A secret is stored — type to replace')
				: t('integration_suitecrm', 'Client secret of your application')
		},

		/**
		 * Iteration 51: buttons rendered by NcDialog. Kept as a computed so
		 * the translation strings are re-evaluated if the user changes NC's
		 * UI language between dialog opens.
		 */
		resetDialogButtons() {
			return [
				{
					label: t('integration_suitecrm', 'Cancel'),
					variant: 'secondary',
					callback: () => {
						this.showResetDialog = false
					},
				},
				{
					label: t('integration_suitecrm', 'Reset connection'),
					variant: 'error',
					callback: () => this.performReset(),
				},
			]
		},
	},

	methods: {
		onInput() {
			delay(() => {
				this.saveOptions()
			}, 2000)()
		},

		/**
		 * Iteration 51: DELETE the admin-config endpoint, then clear the
		 * local form state so the fields reset without a page reload.
		 * User tokens are intentionally left in place — they'll fail their
		 * next SuiteCRM request and the per-user OAuth flow restarts
		 * naturally.
		 */
		performReset() {
			const url = generateUrl('/apps/integration_suitecrm/admin-config')
			axios.delete(url)
				.then(() => {
					this.state.oauth_instance_url = ''
					this.state.client_id = ''
					this.state.client_secret_set = false
					this.state.oauth_authorize_path = ''
					this.newSecret = ''
					this.showResetDialog = false
					showSuccess(t('integration_suitecrm', 'SuiteCRM connection reset — enter new credentials to reconnect'))
				})
				.catch((error) => {
					showError(t('integration_suitecrm', 'Failed to reset SuiteCRM connection')
						+ ': ' + (error.response?.request?.responseText || error.message || ''))
				})
		},

		saveOptions() {
			const values = {
				client_id: this.state.client_id,
				oauth_instance_url: this.state.oauth_instance_url,
				oauth_authorize_path: this.state.oauth_authorize_path,
			}
			// Only include client_secret when the admin actually typed a new
			// value. Sending the empty string would clear the stored secret,
			// which is almost never what an admin editing the other fields
			// intends.
			if (this.newSecret !== '') {
				values.client_secret = this.newSecret
			}
			const req = { values }
			const url = generateUrl('/apps/integration_suitecrm/admin-config')
			axios.put(url, req)
				.then(() => {
					showSuccess(t('integration_suitecrm', 'SuiteCRM admin options saved'))
					if (this.newSecret !== '') {
						// Buffer consumed by the server; clear the input so the
						// stored-secret placeholder returns and the next auto-save
						// doesn't resend the same value.
						this.newSecret = ''
						this.state.client_secret_set = true
					}
				})
				.catch((error) => {
					showError(t('integration_suitecrm', 'Failed to save SuiteCRM admin options')
						+ ': ' + error.response.request.responseText)
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

	.fields {
		display: flex;
		flex-direction: column;
		gap: 12px;
		max-width: 500px;
		margin-block-start: 20px;
		margin-inline-start: 30px;
	}

	.external-link {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	.reset-zone {
		max-width: 500px;
		margin-block-start: 40px;
		margin-inline-start: 30px;
		padding-block-start: 20px;
		border-block-start: 1px solid var(--color-border);

		h3 {
			margin-block-end: 8px;
			font-weight: bold;
		}

		.reset-explanation {
			margin-block-end: 12px;
			color: var(--color-text-maxcontrast);
			font-size: 0.9em;
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
</style>
