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
				v-model="state.client_secret"
				:label="t('integration_suitecrm', 'Application secret')"
				:placeholder="t('integration_suitecrm', 'Client secret of your application')"
				@update:value="onInput" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',

	components: {
		NcNoteCard,
		NcPasswordField,
		NcTextField,
		OpenInNewIcon,
	},

	props: {},

	data() {
		return {
			state: loadState('integration_suitecrm', 'admin-config'),
		}
	},

	methods: {
		onInput() {
			delay(() => {
				this.saveOptions()
			}, 2000)()
		},

		saveOptions() {
			const req = {
				values: {
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
					oauth_instance_url: this.state.oauth_instance_url,
				},
			}
			const url = generateUrl('/apps/integration_suitecrm/admin-config')
			axios.put(url, req)
				.then(() => {
					showSuccess(t('integration_suitecrm', 'SuiteCRM admin options saved'))
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
