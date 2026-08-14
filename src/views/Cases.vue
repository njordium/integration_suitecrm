<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="cases.length > 0"
		:emptyText="t('integration_suitecrm', 'No open SuiteCRM Cases')"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Cases — settings')"
		:refreshSeconds="refreshSeconds"
		:showOnlyMineToggle="true"
		:onlyMine="onlyMine"
		:maxItems="maxItems"
		:saving="saving"
		@refresh="fetchCases"
		@save="onSaveSettings">
		<ul class="scw-list">
			<li v-for="c in cases.slice(0, maxItems)" :key="c.id" class="scw-item">
				<span class="scw-item__icon"><BriefcaseIcon :size="18" /></span>
				<a
					:href="getCaseTarget(c)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(c) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(c) }}
					</div>
				</a>
			</li>
		</ul>
	</SuiteCRMWidgetShell>
</template>

<script>
/**
 * SuiteCRMCases.
 *
 * "My open Cases" dashboard widget. Renders through the shared
 * `SuiteCRMWidgetShell` so it inherits the 3-dot toolbar
 * (Refresh, Widget settings) and settings modal shape used across
 * every SuiteCRM dashboard widget.
 *
 * Per-widget refresh cadence is persisted under
 * `cases_refresh_seconds` (allowed value set enforced by
 * `ConfigController::USER_ALLOWED_KEYS`); the initial value comes
 * from `GET /widget-config` on mount, and `useAutoRefresh` drives the
 * polling loop with wake-signal refetches on tab focus / visibility
 * change / bfcache restore.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import BriefcaseIcon from 'vue-material-design-icons/Briefcase.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMCases',

	components: {
		SuiteCRMWidgetShell,
		BriefcaseIcon,
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
			cases: [],
			state: 'loading',
			refreshSeconds: 300,
			onlyMine: true,
			maxItems: 20,
			saving: false,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Cases&action=index' : ''
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchCases()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchCases())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.cases_refresh_seconds)
				if (!Number.isNaN(seconds)) {
					this.refreshSeconds = seconds
					this.autoRefresh.setIntervalMs(seconds * 1000)
				}
				if (typeof response.data?.cases_only_mine === 'boolean') {
					this.onlyMine = response.data.cases_only_mine
				}
				const maxN = Number(response.data?.cases_max_items)
				if (!Number.isNaN(maxN) && maxN > 0) {
					this.maxItems = maxN
				}
			} catch {
				// Config fetch is best-effort; widget still functions at default cadence.
			}
		},

		async probeUrl() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/url'))
				this.suitecrmUrl = (response.data || '').replace(/\/+$/, '')
			} catch {
				// URL probe is best-effort; the widget still works, just without
				// an absolute prefix on the row-click and "show more" link.
			}
		},

		fetchCases() {
			const url = generateUrl('/apps/integration_suitecrm/my-cases?onlyMine={m}&limit={l}', {
				m: this.onlyMine ? '1' : '0',
				l: String(this.maxItems),
			})
			axios.get(url).then((response) => {
				this.cases = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM open Cases'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, onlyMine, maxItems, close }) {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), {
					values: {
						cases_refresh_seconds: String(refreshSeconds),
						cases_only_mine: onlyMine ? '1' : '0',
						cases_max_items: String(maxItems),
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
					this.fetchCases()
				}
				close()
				showSuccess(t('integration_suitecrm', 'Widget settings saved.'))
			} catch {
				showError(t('integration_suitecrm', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},

		getCaseTarget(c) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Cases&action=DetailView&record=' + c.id
		},

		getMainText(c) {
			const name = c.attributes?.name || t('integration_suitecrm', '(no title)')
			const caseNumber = c.attributes?.case_number
			return caseNumber ? `#${caseNumber} · ${name}` : name
		},

		getSubline(c) {
			const parts = []
			const priority = c.attributes?.priority
			if (priority) {
				parts.push(priority)
			}
			const status = c.attributes?.status
			if (status) {
				parts.push(status)
			}
			const ageDays = c.age_days ?? 0
			if (ageDays > 0) {
				parts.push(n('integration_suitecrm', '%n day open', '%n days open', ageDays))
			} else {
				parts.push(t('integration_suitecrm', 'opened today'))
			}
			return parts.join(' · ')
		},
	},
}
</script>

<!-- List styles are shared across all nine widgets via css/dashboard.css. -->
