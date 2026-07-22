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
 * SuiteCRMTasks.
 *
 * "My open Tasks" dashboard widget. Workload-oriented, not
 * schedule-oriented, includes undated Tasks the calendar widget
 * drops. Subline shows priority · due-date-relative-label, or
 * "no due date" when date_due is empty. Moment renders the
 * relative label so the user sees "due yesterday" / "due in 3 days"
 * in their own locale rather than raw ISO dates.
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
	name: 'SuiteCRMTasks',

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
			tasks: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Tasks&action=index'
		},

		items() {
			return this.tasks.map((t) => ({
				id: t.id,
				targetUrl: this.getTaskTarget(t),
				avatarUrl: imagePath('njordium_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(t),
				mainText: this.getMainText(t),
				subText: this.getSubline(t),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('njordium_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('njordium_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('njordium_suitecrm', 'No open SuiteCRM Tasks')
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
				// URL probe is best-effort; the widget still works, just without
				// an absolute prefix on the "show more" link.
			}
			this.fetchTasks()
			this.loop = setInterval(() => this.fetchTasks(), 120000)
		},

		fetchTasks() {
			axios.get(generateUrl('/apps/njordium_suitecrm/my-tasks')).then((response) => {
				this.tasks = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('njordium_suitecrm', 'Failed to get SuiteCRM open Tasks'))
					this.state = 'error'
				}
			})
		},

		getTaskTarget(task) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Tasks&action=DetailView&record=' + task.id
		},

		getMainText(task) {
			return task.attributes?.name || t('njordium_suitecrm', '(no title)')
		},

		getSubline(task) {
			const parts = []
			const priority = task.attributes?.priority
			if (priority) {
				parts.push(priority)
			}
			if (task.due_ts) {
				parts.push(moment.unix(task.due_ts).fromNow())
			} else {
				parts.push(t('njordium_suitecrm', 'no due date'))
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
