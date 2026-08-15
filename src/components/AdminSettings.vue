<!--
	@Code Changes by: Kim Haverblad, 2026
-->
<template>
	<div id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('integration_suitecrm', 'SuiteCRM integration') }}
		</h2>

		<p class="settings-hint">
			{{ t('integration_suitecrm', 'Create an OAuth2 Client in your SuiteCRM admin ("OAuth2 Clients and Tokens" section) with the redirect URI shown below, then paste the resulting Client ID and Client Secret here. Make sure private and public keys are generated on the SuiteCRM instance — authentication won\'t work without them.') }}
			<a
				href="https://docs.suitecrm.com/developer/api/developer-setup-guide/json-api/#_generate_private_and_public_key_for_oauth2"
				target="_blank"
				rel="noopener noreferrer"
				class="external-link">
				{{ t('integration_suitecrm', 'SuiteCRM OAuth2 documentation') }}
				<OpenInNewIcon :size="14" />
			</a>
		</p>

		<div class="grid-form">
			<label for="oauth_instance_url">
				<span class="icon icon-link" />
				{{ t('integration_suitecrm', 'Instance address') }}
			</label>
			<NcTextField
				id="oauth_instance_url"
				v-model="state.oauth_instance_url"
				:placeholder="t('integration_suitecrm', 'https://my.suitecrm.org')"
				@update:value="onInput" />

			<label for="client_id">
				<span class="icon icon-category-auth" />
				{{ t('integration_suitecrm', 'OAuth client ID') }}
			</label>
			<NcPasswordField
				id="client_id"
				v-model="state.client_id"
				:placeholder="t('integration_suitecrm', 'ID of your OAuth application')"
				@update:value="onInput" />

			<label for="client_secret">
				<span class="icon icon-category-auth" />
				{{ t('integration_suitecrm', 'OAuth client secret') }}
			</label>
			<NcPasswordField
				id="client_secret"
				v-model="newSecret"
				:placeholder="secretPlaceholder"
				@update:value="onInput" />

			<label for="oauth_authorize_path">
				<span class="icon icon-rename" />
				{{ t('integration_suitecrm', 'OAuth authorize endpoint path') }}
			</label>
			<NcTextField
				id="oauth_authorize_path"
				v-model="state.oauth_authorize_path"
				:helperText="t('integration_suitecrm', 'SuiteCRM 8.10.x default: /Api/authorize. Older installs may use /legacy/oauth2/authorize.')"
				@update:value="onInput" />

			<label>
				<span class="icon icon-external" />
				{{ t('integration_suitecrm', 'Redirect URI') }}
			</label>
			<div class="redirect-uri">
				<code>{{ redirectUri }}</code>
				<NcButton variant="tertiary" @click="copyRedirect">
					<template #icon>
						<ContentCopyIcon :size="20" />
					</template>
					{{ t('integration_suitecrm', 'Copy') }}
				</NcButton>
			</div>
		</div>

		<!--
			"Reset connection" affordance (upstream issue #14). Closes the case
			where an admin picked the wrong OAuth2 client type in SuiteCRM
			(password vs authorization code) or seeded a bad client_secret and
			had no visible way to start over. The button opens a confirmation
			dialog; on confirm we DELETE the admin-config endpoint and clear
			the local form state.
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
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',

	components: {
		ContentCopyIcon,
		DeleteIcon,
		NcButton,
		NcDialog,
		NcPasswordField,
		NcTextField,
		OpenInNewIcon,
	},

	props: {},

	data() {
		const initial = loadState('integration_suitecrm', 'admin-config') ?? {}
		return {
			state: {
				oauth_instance_url: initial.oauth_instance_url ?? '',
				client_id: initial.client_id ?? '',
				oauth_authorize_path: initial.oauth_authorize_path ?? '/Api/authorize',
				// Preserved so the legacy plaintext-payload branch of
				// secretIsStored below keeps working during a partial deploy.
				client_secret: initial.client_secret ?? '',
				client_secret_set: !!initial.client_secret_set,
			},

			// Secret input is separate from `state` so we can distinguish
			// "user typed a new value" (send it) from "user hasn't touched
			// this field" (leave the stored secret untouched).
			newSecret: '',
			// Redirect URI is server-supplied (linkToRouteAbsolute); read-only
			// in the UI so the admin can't miscopy the path segment.
			redirectUri: initial.redirect_uri ?? '',
			// Reset confirmation dialog visibility.
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
				? t('integration_suitecrm', 'Leave empty to keep the stored secret')
				: t('integration_suitecrm', 'Secret of your OAuth application')
		},

		/**
		 * Buttons rendered by NcDialog. Kept as a computed so the
		 * translation strings are re-evaluated if the user changes NC's
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

		async copyRedirect() {
			try {
				await navigator.clipboard.writeText(this.redirectUri)
				showSuccess(t('integration_suitecrm', 'Redirect URI copied'))
			} catch {
				showError(t('integration_suitecrm', 'Failed to copy'))
			}
		},

		/**
		 * DELETE the admin-config endpoint, then clear the local form
		 * state so the fields reset without a page reload. User tokens
		 * are intentionally left in place; they'll fail their next
		 * SuiteCRM request and the per-user OAuth flow restarts naturally.
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
					showSuccess(t('integration_suitecrm', 'SuiteCRM connection reset, enter new credentials to reconnect'))
				})
				.catch((error) => {
					showError(t('integration_suitecrm', 'Failed to reset SuiteCRM connection')
						+ ': ' + (error.response?.request?.responseText || error.message || ''))
				})
		},

		saveOptions() {
			const values = {
				client_id: this.state.client_id,
				oauth_instance_url: (this.state.oauth_instance_url ?? '').replace(/\/+$/, ''),
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
	max-width: 720px;

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 8px;
	}

	.settings-hint {
		margin: 8px 0 16px;
		color: var(--color-text-maxcontrast);
	}

	.external-link {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	.grid-form {
		display: grid;
		grid-template-columns: max-content 1fr;
		column-gap: 12px;
		row-gap: 10px;
		align-items: center;

		label {
			display: flex;
			align-items: center;
			gap: 6px;
			white-space: nowrap;

			.icon {
				display: inline-block;
				width: 20px;
				height: 20px;
			}
		}
	}

	.redirect-uri {
		display: flex;
		align-items: center;
		gap: 8px;

		code {
			padding: 4px 8px;
			background: var(--color-background-hover);
			border-radius: var(--border-radius);
			font-size: 12px;
			word-break: break-all;
		}
	}

	.reset-zone {
		max-width: 500px;
		margin-block-start: 40px;
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
	background-image: url(./../../img/app-color.svg);
	background-size: 23px 23px;
	height: 23px;
	width: 23px;
	display: inline-block;
}
</style>
