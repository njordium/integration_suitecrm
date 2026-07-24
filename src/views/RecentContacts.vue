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
 * SuiteCRMContacts.
 *
 * "SuiteCRM Contacts" widget. Lists Contacts most recently added to
 * SuiteCRM, subject to the ACL on the caller's OAuth token. Sorted by
 * date_entered DESC, capped at 20 rows.
 *
 * Subline shape: account_name · date_entered (YYYY-MM-DD). Falls back
 * to email if the person record has no name captured yet.
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
	name: 'SuiteCRMContacts',

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
			contacts: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Contacts&action=index'
		},

		items() {
			return this.contacts.map((c) => ({
				id: c.id,
				targetUrl: this.getContactTarget(c),
				avatarUrl: imagePath('njordium_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(c),
				mainText: this.getMainText(c),
				subText: this.getSubline(c),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('njordium_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('njordium_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('njordium_suitecrm', 'No recently added SuiteCRM Contacts')
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
			this.fetchContacts()
			this.loop = setInterval(() => this.fetchContacts(), 120000)
		},

		fetchContacts() {
			axios.get(generateUrl('/apps/njordium_suitecrm/recent-contacts')).then((response) => {
				this.contacts = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('njordium_suitecrm', 'Failed to get SuiteCRM Contacts'))
					this.state = 'error'
				}
			})
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
			return t('njordium_suitecrm', '(no name)')
		},

		getSubline(contact) {
			const parts = []
			const account = contact.attributes?.account_name
			if (account) {
				parts.push(account)
			}
			if (contact.entered_ts) {
				parts.push(t('njordium_suitecrm', 'added {when}', { when: moment.unix(contact.entered_ts).fromNow() }))
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
