<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="contacts.length > 0"
		:emptyText="t('integration_suitecrm', 'No recently added SuiteCRM Contacts')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Contacts — settings')"
		:refreshSeconds="refreshSeconds"
		:saving="saving"
		@refresh="fetchContacts"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="c in contacts" :key="c.id" class="scw-item">
				<span class="scw-item__icon"><AccountOutlineIcon :size="18" /></span>
				<a
					:href="getContactTarget(c)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(c) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(c) }}
					</div>
				</a>
			</li>
		</ul>
	</SuiteCRMWidgetShell>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import AccountOutlineIcon from 'vue-material-design-icons/AccountOutline.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMContacts',

	components: { SuiteCRMWidgetShell, AccountOutlineIcon },

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			suitecrmUrl: null,
			contacts: [],
			state: 'loading',
			refreshSeconds: 300,
			saving: false,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Contacts&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchContacts()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchContacts())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.contacts_refresh_seconds)
				if (!Number.isNaN(seconds)) {
					this.refreshSeconds = seconds
					this.autoRefresh.setIntervalMs(seconds * 1000)
				}
			} catch {
				// best-effort — widget still functions at defaults
			}
		},

		async probeUrl() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/url'))
				this.suitecrmUrl = (response.data || '').replace(/\/+$/, '')
			} catch {
				// best-effort — widget still functions at defaults
			}
		},

		fetchContacts() {
			axios.get(generateUrl('/apps/integration_suitecrm/recent-contacts')).then((response) => {
				this.contacts = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM Contacts'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: { contacts_refresh_seconds: String(refreshSeconds) },
				})
				this.refreshSeconds = refreshSeconds
				this.autoRefresh.setIntervalMs(refreshSeconds * 1000)
				close()
				showSuccess(t('integration_suitecrm', 'Widget settings saved.'))
			} catch {
				showError(t('integration_suitecrm', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},

		getContactTarget(contact) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Contacts&action=DetailView&record=' + contact.id
		},

		getMainText(contact) {
			const attrs = contact.attributes || {}
			const full = ((attrs.first_name || '') + ' ' + (attrs.last_name || '')).trim()
			if (full) {
				return full
			}
			if (attrs.email1) {
				return attrs.email1
			}
			return t('integration_suitecrm', '(no name)')
		},

		getSubline(contact) {
			const parts = []
			const account = contact.attributes?.account_name
			if (account) {
				parts.push(account)
			}
			if (contact.entered_ts) {
				parts.push(t('integration_suitecrm', 'added {when}', { when: moment.unix(contact.entered_ts).fromNow() }))
			}
			return parts.join(' · ')
		},
	},
}
</script>
