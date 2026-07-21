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
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { generateUrl, imagePath } from '@nextcloud/router'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

const TYPE_MODULE = {
	meeting: 'Meetings',
	call: 'Calls',
	task: 'Tasks',
}

export default {
	name: 'SuiteCRMCalendar',

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
			events: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Calendar&action=index'
		},

		items() {
			return this.events.map((e) => ({
				id: e.id,
				targetUrl: this.getEventTarget(e),
				avatarUrl: this.getAvatarUrl(e),
				avatarUsername: this.getMainText(e),
				mainText: this.getMainText(e),
				subText: this.getSubline(e),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('integration_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('integration_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('integration_suitecrm', 'No upcoming SuiteCRM events')
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
				// URL probe is best-effort; the widget still works, just without
				// an absolute prefix on the "show more" link.
			}
			this.fetchEvents()
			this.loop = setInterval(() => this.fetchEvents(), 120000)
		},

		fetchEvents() {
			axios.get(generateUrl('/apps/integration_suitecrm/upcoming')).then((response) => {
				this.events = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM upcoming events'))
					this.state = 'error'
				}
			})
		},

		getEventTarget(e) {
			const module = TYPE_MODULE[e.type]
			if (!module) {
				return this.suitecrmUrl
			}
			return this.suitecrmUrl + '/index.php?module=' + module + '&action=DetailView&record=' + e.id
		},

		getAvatarUrl(e) {
			if (e.type === 'call') {
				return imagePath('integration_suitecrm', 'call.png')
			}
			if (e.type === 'meeting') {
				return imagePath('integration_suitecrm', 'meeting.png')
			}
			return ''
		},

		getMainText(e) {
			return e.attributes?.name || t('integration_suitecrm', '(no title)')
		},

		getSubline(e) {
			const when = moment.unix(e.event_ts)
			const label = when.calendar()
			if (e.type === 'meeting') {
				const loc = e.attributes?.location
				return loc ? `${label} · ${loc}` : label
			}
			if (e.type === 'call') {
				return `📞 ${label}`
			}
			if (e.type === 'task') {
				const prio = e.attributes?.priority
				return prio ? `${label} · ${prio}` : label
			}
			return label
		},
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
