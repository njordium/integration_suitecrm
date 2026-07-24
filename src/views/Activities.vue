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
 * SuiteCRMActivities.
 *
 * "SuiteCRM Activities" widget. Cross-module recent-activity feed
 * covering Calls, Meetings, Tasks, and Notes as SuiteCRM's canonical
 * activity types. Sort key is date_modified so "recent activity" means
 * "recently touched", not "recently created".
 *
 * Subline shape: type · assigned-user · relative-modified-time.
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
	name: 'SuiteCRMActivities',

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
			activities: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Home&action=index'
		},

		items() {
			return this.activities.map((a) => ({
				id: a.id,
				targetUrl: this.getActivityTarget(a),
				avatarUrl: imagePath('njordium_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(a),
				mainText: this.getMainText(a),
				subText: this.getSubline(a),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('njordium_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('njordium_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('njordium_suitecrm', 'No recent SuiteCRM activity')
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
				// URL probe is best-effort; widget still works, just without
				// an absolute prefix on the DetailView links.
			}
			this.fetchActivities()
			this.loop = setInterval(() => this.fetchActivities(), 120000)
		},

		fetchActivities() {
			axios.get(generateUrl('/apps/njordium_suitecrm/recent-activities')).then((response) => {
				this.activities = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('njordium_suitecrm', 'Failed to get SuiteCRM recent activity'))
					this.state = 'error'
				}
			})
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
				case 'meeting': return t('njordium_suitecrm', 'Meeting')
				case 'call': return t('njordium_suitecrm', 'Call')
				case 'task': return t('njordium_suitecrm', 'Task')
				case 'note': return t('njordium_suitecrm', 'Note')
				default: return type
			}
		},

		getMainText(activity) {
			return activity.attributes?.name || t('njordium_suitecrm', '(no title)')
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

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
