<template>
	<NcDashboardWidget
		:items="items"
		:showMoreUrl="showMoreUrl"
		:showMoreText="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent v-if="emptyContentMessage" :name="emptyContentMessage">
				<template #action>
					<div v-if="state === 'no-token' || state === 'error'" class="connect-button">
						<a class="button" :href="settingsUrl">
							{{ t('njordium_suitecrm', 'Connect to SuiteCRM') }}
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
/**
 * SuiteCRMLeads.
 *
 * "SuiteCRM Leads" widget. Sibling to the Contacts and Accounts
 * widgets. Subline carries status and lead_source so a fresh Web-form
 * capture reads differently at a glance from an already-worked cold
 * call.
 *
 * @author Kim Haverblad
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { generateUrl, imagePath } from '@nextcloud/router'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

export default {
	name: 'SuiteCRMLeads',

	components: {
		NcDashboardWidget,
		NcEmptyContent,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			suitecrmUrl: null,
			leads: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Leads&action=index'
		},

		items() {
			return this.leads.map((l) => ({
				id: l.id,
				targetUrl: this.getLeadTarget(l),
				avatarUrl: imagePath('njordium_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(l),
				mainText: this.getMainText(l),
				subText: this.getSubline(l),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('njordium_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('njordium_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('njordium_suitecrm', 'No recently added SuiteCRM Leads')
			}
			return ''
		},
	},

	watch: {
		windowVisibility(newValue) {
			if (newValue) {
				this.launchLoop()
			} else {
				this.stopLoop()
			}
		},
	},

	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.changeWindowVisibility)
	},

	beforeMount() {
		this.launchLoop()
		document.addEventListener('visibilitychange', this.changeWindowVisibility)
	},

	methods: {
		changeWindowVisibility() {
			this.windowVisibility = !document.hidden
		},

		stopLoop() {
			clearInterval(this.loop)
		},

		async launchLoop() {
			try {
				const response = await axios.get(generateUrl('/apps/njordium_suitecrm/url'))
				this.suitecrmUrl = response.data.replace(/\/+$/, '')
			} catch {
				// best-effort URL probe
			}
			this.fetchLeads()
			this.loop = setInterval(() => this.fetchLeads(), 120000)
		},

		fetchLeads() {
			axios.get(generateUrl('/apps/njordium_suitecrm/recent-leads')).then((response) => {
				this.leads = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('njordium_suitecrm', 'Failed to get SuiteCRM Leads'))
					this.state = 'error'
				}
			})
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
			return t('njordium_suitecrm', '(no name)')
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

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
