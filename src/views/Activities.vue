<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="activities.length > 0"
		:emptyText="t('integration_suitecrm', 'No recent SuiteCRM activity')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Activities — settings')"
		:refreshSeconds="refreshSeconds"
		:saving="saving"
		@refresh="fetchActivities"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="a in activities" :key="a.id" class="scw-item">
				<a
					:href="getActivityTarget(a)"
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
	name: 'SuiteCRMActivities',

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
			activities: [],
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
		this.autoRefresh.fetchLater = () => this.fetchActivities()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchActivities())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.activities_refresh_seconds)
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

		fetchActivities() {
			axios.get(generateUrl('/apps/integration_suitecrm/recent-activities')).then((response) => {
				this.activities = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM recent activity'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: { activities_refresh_seconds: String(refreshSeconds) },
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

		getActivityTarget(activity) {
			if (!this.suitecrmUrl) {
				return ''
			}
			const module = this.moduleForType(activity.type)
			return this.suitecrmUrl + '/index.php?module=' + module + '&action=DetailView&record=' + activity.id
		},

		moduleForType(type) {
			switch (type) {
				case 'meeting': return 'Meetings'
				case 'call': return 'Calls'
				case 'task': return 'Tasks'
				case 'note': return 'Notes'
				default: return 'Home'
			}
		},

		typeLabel(type) {
			switch (type) {
				case 'meeting': return t('integration_suitecrm', 'Meeting')
				case 'call': return t('integration_suitecrm', 'Call')
				case 'task': return t('integration_suitecrm', 'Task')
				case 'note': return t('integration_suitecrm', 'Note')
				default: return type
			}
		},

		getMainText(activity) {
			return activity.attributes?.name || t('integration_suitecrm', '(no title)')
		},

		getSubline(activity) {
			const parts = []
			parts.push(this.typeLabel(activity.type))
			const assignedUser = activity.attributes?.assigned_user_name
			if (assignedUser) {
				parts.push(assignedUser)
			}
			if (activity.modified_ts) {
				parts.push(moment.unix(activity.modified_ts).fromNow())
			}
			return parts.join(' · ')
		},
	},
}
</script>
