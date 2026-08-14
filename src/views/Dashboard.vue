<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="notifications.length > 0"
		:emptyText="t('integration_suitecrm', 'No SuiteCRM notifications!')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Events — settings')"
		:refreshSeconds="refreshSeconds"
		:saving="saving"
		@refresh="fetchNotifications"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="n in notifications" :key="getUniqueKey(n)" class="scw-item">
				<span class="scw-item__icon">
					<component :is="iconForModule(n.attributes?.related_event_module)" :size="18" />
				</span>
				<a
					:href="getNotificationTarget(n)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getTargetTitle(n) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(n) }}
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
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import PhoneOutlineIcon from 'vue-material-design-icons/PhoneOutline.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMDashboard',

	components: {
		SuiteCRMWidgetShell,
		BellRingIcon,
		CalendarClockIcon,
		PhoneOutlineIcon,
	},

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			suitecrmUrl: null,
			notifications: [],
			state: 'loading',
			refreshSeconds: 300,
			saving: false,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Home&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchNotifications()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchNotifications())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.events_refresh_seconds)
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

		fetchNotifications() {
			const req = { params: { eventSinceTimestamp: moment().unix() } }
			axios.get(generateUrl('/apps/integration_suitecrm/reminders'), req).then((response) => {
				this.notifications = response.data || []
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM reminders'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: { events_refresh_seconds: String(refreshSeconds) },
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

		getNotificationTarget(n) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=' + n.attributes.related_event_module
				+ '&action=DetailView&record=' + n.attributes.related_event_module_id
		},

		getUniqueKey(n) {
			return n.id
		},

		iconForModule(module) {
			if (module === 'Calls') {
				return 'PhoneOutlineIcon'
			}
			if (module === 'Meetings') {
				return 'CalendarClockIcon'
			}
			return 'BellRingIcon'
		},

		getSubline(n) {
			const mom = moment.unix(n.attributes.date_willexecute)
			const date = mom.format('L') + ' ' + mom.format('HH:mm')
			if (n.attributes.related_event_module === 'Calls') {
				return t('integration_suitecrm', 'Call at {date}', { date })
			}
			if (n.attributes.related_event_module === 'Meetings') {
				return t('integration_suitecrm', 'Meeting at {date}', { date })
			}
			return ''
		},

		getTargetTitle(n) {
			return n.title
		},
	},
}
</script>
