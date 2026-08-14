<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="leads.length > 0"
		:emptyText="t('integration_suitecrm', 'No recently added SuiteCRM Leads')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Leads — settings')"
		:refreshSeconds="refreshSeconds"
		:saving="saving"
		@refresh="fetchLeads"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="l in leads" :key="l.id" class="scw-item">
				<span class="scw-item__icon"><HandshakeIcon :size="18" /></span>
				<a
					:href="getLeadTarget(l)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(l) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(l) }}
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
import HandshakeIcon from 'vue-material-design-icons/Handshake.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMLeads',

	components: { SuiteCRMWidgetShell, HandshakeIcon },

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			suitecrmUrl: null,
			leads: [],
			state: 'loading',
			refreshSeconds: 300,
			saving: false,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Leads&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchLeads()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchLeads())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.leads_refresh_seconds)
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

		fetchLeads() {
			axios.get(generateUrl('/apps/integration_suitecrm/recent-leads')).then((response) => {
				this.leads = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM Leads'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: { leads_refresh_seconds: String(refreshSeconds) },
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

		getLeadTarget(lead) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Leads&action=DetailView&record=' + lead.id
		},

		getMainText(lead) {
			const attrs = lead.attributes || {}
			const full = ((attrs.first_name || '') + ' ' + (attrs.last_name || '')).trim()
			if (full) {
				return full
			}
			if (attrs.email1) {
				return attrs.email1
			}
			return t('integration_suitecrm', '(no name)')
		},

		getSubline(lead) {
			const parts = []
			const account = lead.attributes?.account_name
			if (account) {
				parts.push(account)
			}
			const status = lead.attributes?.status
			if (status) {
				parts.push(status)
			}
			const source = lead.attributes?.lead_source
			if (source) {
				parts.push(source)
			}
			if (lead.entered_ts) {
				parts.push(moment.unix(lead.entered_ts).fromNow())
			}
			return parts.join(' · ')
		},
	},
}
</script>
