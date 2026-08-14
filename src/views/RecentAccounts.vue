<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="accounts.length > 0"
		:emptyText="t('integration_suitecrm', 'No recently added SuiteCRM Accounts')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Accounts — settings')"
		:refreshSeconds="refreshSeconds"
		:saving="saving"
		@refresh="fetchAccounts"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="a in accounts" :key="a.id" class="scw-item">
				<a
					:href="getAccountTarget(a)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(a) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(a) }}
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
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMAccounts',

	components: { SuiteCRMWidgetShell },

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			suitecrmUrl: null,
			accounts: [],
			state: 'loading',
			refreshSeconds: 300,
			saving: false,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Accounts&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchAccounts()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchAccounts())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.accounts_refresh_seconds)
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

		fetchAccounts() {
			axios.get(generateUrl('/apps/integration_suitecrm/recent-accounts')).then((response) => {
				this.accounts = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM Accounts'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: { accounts_refresh_seconds: String(refreshSeconds) },
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

		getAccountTarget(account) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Accounts&action=DetailView&record=' + account.id
		},

		getMainText(account) {
			return account.attributes?.name || t('integration_suitecrm', '(no name)')
		},

		getSubline(account) {
			const parts = []
			const industry = account.attributes?.industry
			if (industry) {
				parts.push(industry)
			}
			if (account.entered_ts) {
				parts.push(t('integration_suitecrm', 'added {when}', { when: moment.unix(account.entered_ts).fromNow() }))
			}
			return parts.join(' · ')
		},
	},
}
</script>
