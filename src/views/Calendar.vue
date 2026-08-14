<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="events.length > 0"
		:emptyText="t('integration_suitecrm', 'No upcoming SuiteCRM events')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Calendar — settings')"
		:refreshSeconds="refreshSeconds"
		:extras="{ calendar_show_tasks: calendarShowTasks }"
		:saving="saving"
		@refresh="fetchEvents"
		@save="onSaveSettings">
		<template #settings="{ draft, updateExtra }">
			<section class="scw-modal__section">
				<h4>{{ t('integration_suitecrm', 'Item types') }}</h4>
				<label class="scw-toggle">
					<input
						type="checkbox"
						:checked="!!draft.calendar_show_tasks"
						@change="updateExtra('calendar_show_tasks', $event.target.checked)">
					<span>{{ t('integration_suitecrm', 'Include Tasks alongside Meetings and Calls') }}</span>
				</label>
			</section>
		</template>

		<ul class="scw-list">
			<li v-for="e in events" :key="e.id" class="scw-item">
				<span class="scw-item__icon">
					<component :is="iconForType(e.type)" :size="18" />
				</span>
				<a
					:href="getEventTarget(e)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(e) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(e) }}
					</div>
				</a>
			</li>
		</ul>
	</SuiteCRMWidgetShell>
</template>

<script>
/**
 * SuiteCRMCalendar — upcoming Meetings, Calls, and (optionally) Tasks.
 *
 * The `calendar_show_tasks` toggle has moved into this widget's own
 * 3-dot settings menu — previously it lived under PersonalSettings.vue
 * only. The same key is written, so existing values from 2.4.x+
 * installs carry over untouched.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CalendarMonthIcon from 'vue-material-design-icons/CalendarMonth.vue'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import PhoneOutlineIcon from 'vue-material-design-icons/PhoneOutline.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const TYPE_MODULE = {
	meeting: 'Meetings',
	call: 'Calls',
	task: 'Tasks',
}

export default {
	name: 'SuiteCRMCalendar',

	components: {
		SuiteCRMWidgetShell,
		CalendarClockIcon,
		CalendarMonthIcon,
		FormatListChecksIcon,
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
			events: [],
			state: 'loading',
			refreshSeconds: 300,
			saving: false,
			calendarShowTasks: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Calendar&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchEvents()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchEvents())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.calendar_refresh_seconds)
				if (!Number.isNaN(seconds)) {
					this.refreshSeconds = seconds
					this.autoRefresh.setIntervalMs(seconds * 1000)
				}
				if (typeof response.data?.calendar_show_tasks === 'boolean') {
					this.calendarShowTasks = response.data.calendar_show_tasks
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

		fetchEvents() {
			axios.get(generateUrl('/apps/integration_suitecrm/upcoming')).then((response) => {
				this.events = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM upcoming events'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, extras, close }) {
			this.saving = true
			try {
				const values = {
					calendar_refresh_seconds: String(refreshSeconds),
					calendar_show_tasks: extras?.calendar_show_tasks ? '1' : '0',
				}
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), { values })
				this.refreshSeconds = refreshSeconds
				this.autoRefresh.setIntervalMs(refreshSeconds * 1000)
				const nextShowTasks = !!extras?.calendar_show_tasks
				if (nextShowTasks !== this.calendarShowTasks) {
					this.calendarShowTasks = nextShowTasks
					this.fetchEvents()
				}
				close()
				showSuccess(t('integration_suitecrm', 'Widget settings saved.'))
			} catch {
				showError(t('integration_suitecrm', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},

		getEventTarget(e) {
			if (!this.suitecrmUrl) {
				return ''
			}
			const module = TYPE_MODULE[e.type]
			if (!module) {
				return this.suitecrmUrl
			}
			return this.suitecrmUrl + '/index.php?module=' + module + '&action=DetailView&record=' + e.id
		},

		iconForType(type) {
			switch (type) {
				case 'meeting': return 'CalendarClockIcon'
				case 'call': return 'PhoneOutlineIcon'
				case 'task': return 'FormatListChecksIcon'
				default: return 'CalendarMonthIcon'
			}
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
.scw-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	cursor: pointer;

	input[type="checkbox"] {
		margin: 0;
		flex-shrink: 0;
	}
}
</style>
