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
							{{ t('integration_suitecrm', 'Connect to SuiteCRM') }}
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
/**
 * SuiteCRMAccounts.
 *
 * "SuiteCRM Accounts" widget. Sibling to the Contacts widget from
 * 2.5.0 — recently added Accounts within the caller's ACL, sorted
 * date_entered DESC, subline shows industry and date.
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
	name: 'SuiteCRMAccounts',

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
			accounts: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Accounts&action=index'
		},

		items() {
			return this.accounts.map((a) => ({
				id: a.id,
				targetUrl: this.getAccountTarget(a),
				avatarUrl: imagePath('integration_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(a),
				mainText: this.getMainText(a),
				subText: this.getSubline(a),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('integration_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('integration_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('integration_suitecrm', 'No recently added SuiteCRM Accounts')
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
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/url'))
				this.suitecrmUrl = response.data.replace(/\/+$/, '')
			} catch {
				// best-effort URL probe
			}
			this.fetchAccounts()
			this.loop = setInterval(() => this.fetchAccounts(), 120000)
		},

		fetchAccounts() {
			axios.get(generateUrl('/apps/integration_suitecrm/recent-accounts')).then((response) => {
				this.accounts = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM Accounts'))
					this.state = 'error'
				}
			})
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

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
