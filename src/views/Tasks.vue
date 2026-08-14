<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="tasks.length > 0"
		:emptyText="t('integration_suitecrm', 'No open SuiteCRM Tasks')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Tasks — settings')"
		:refreshSeconds="refreshSeconds"
		:showOnlyMineToggle="true"
		:onlyMine="onlyMine"
		:maxItems="maxItems"
		:saving="saving"
		@refresh="fetchTasks"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="task in tasks.slice(0, maxItems)" :key="task.id" class="scw-item">
				<span class="scw-item__icon"><FormatListChecksIcon :size="18" /></span>
				<a
					:href="getTaskTarget(task)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(task) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(task) }}
					</div>
				</a>
			</li>
		</ul>
	</SuiteCRMWidgetShell>
</template>

<script>
/**
 * SuiteCRMTasks — "My open Tasks" dashboard widget. Workload-oriented
 * (includes undated Tasks the calendar widget drops). Uses the shared
 * `SuiteCRMWidgetShell` for the 3-dot toolbar + settings modal.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import FormatListChecksIcon from 'vue-material-design-icons/FormatListChecks.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMTasks',

	components: { SuiteCRMWidgetShell, FormatListChecksIcon },

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			suitecrmUrl: null,
			tasks: [],
			state: 'loading',
			refreshSeconds: 300,
			onlyMine: true,
			maxItems: 20,
			saving: false,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Tasks&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchTasks()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchTasks())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.tasks_refresh_seconds)
				if (!Number.isNaN(seconds)) {
					this.refreshSeconds = seconds
					this.autoRefresh.setIntervalMs(seconds * 1000)
				}
				if (typeof response.data?.tasks_only_mine === 'boolean') {
					this.onlyMine = response.data.tasks_only_mine
				}
				const maxN = Number(response.data?.tasks_max_items)
				if (!Number.isNaN(maxN) && maxN > 0) {
					this.maxItems = maxN
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

		fetchTasks() {
			const url = generateUrl('/apps/integration_suitecrm/my-tasks?onlyMine={m}&limit={l}', {
				m: this.onlyMine ? '1' : '0',
				l: String(this.maxItems),
			})
			axios.get(url).then((response) => {
				this.tasks = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM open Tasks'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, onlyMine, maxItems, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: {
						tasks_refresh_seconds: String(refreshSeconds),
						tasks_only_mine: onlyMine ? '1' : '0',
						tasks_max_items: String(maxItems),
					},
				})
				this.refreshSeconds = refreshSeconds
				this.autoRefresh.setIntervalMs(refreshSeconds * 1000)
				let needRefetch = false
				if (onlyMine !== this.onlyMine) {
					this.onlyMine = onlyMine
					needRefetch = true
				}
				if (maxItems !== this.maxItems) {
					this.maxItems = maxItems
					needRefetch = true
				}
				if (needRefetch) {
					this.fetchTasks()
				}
				close()
				showSuccess(t('integration_suitecrm', 'Widget settings saved.'))
			} catch {
				showError(t('integration_suitecrm', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},

		getTaskTarget(task) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Tasks&action=DetailView&record=' + task.id
		},

		getMainText(task) {
			return task.attributes?.name || t('integration_suitecrm', '(no title)')
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
				parts.push(t('integration_suitecrm', 'no due date'))
			}
			return parts.join(' · ')
		},
	},
}
</script>

<!-- List styles are shared across all nine widgets via css/dashboard.css. -->
