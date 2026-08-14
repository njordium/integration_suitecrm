<template>
	<SuiteCRMWidgetShell
		:loading="state === 'loading'"
		:notConnected="state === 'no-token'"
		:error="state === 'error' ? t('integration_suitecrm', 'Error connecting to SuiteCRM') : ''"
		:hasItems="opportunities.length > 0"
		:emptyText="emptyText"
		:showMoreUrl="showMoreUrl"
		:settingsTitle="t('integration_suitecrm', 'SuiteCRM: Pipeline — settings')"
		:refreshSeconds="refreshSeconds"
		:showOnlyMineToggle="true"
		:onlyMine="onlyMine"
		:extras="{ pipeline_mode: mode }"
		:saving="saving"
		@refresh="fetchOpportunities"
		@save="onSaveSettings">
		<template #settings="{ draft, updateExtra }">
			<section class="scw-modal__section">
				<h4>{{ t('integration_suitecrm', 'Pipeline framing') }}</h4>
				<div class="scw-mode-choice">
					<label v-for="opt in modeOptions" :key="opt.value" class="scw-mode-radio">
						<input
							type="radio"
							name="pipeline_mode"
							:value="opt.value"
							:checked="draft.pipeline_mode === opt.value"
							@change="updateExtra('pipeline_mode', opt.value)">
						<span>{{ opt.label }}</span>
					</label>
				</div>
			</section>
		</template>

		<ul class="scw-list">
			<li v-for="opp in opportunities" :key="opp.id" class="scw-item">
				<span class="scw-item__icon"><TrendingUpIcon :size="18" /></span>
				<a
					:href="getOpportunityTarget(opp)"
					target="_blank"
					rel="noopener"
					class="scw-item__link">
					<div class="scw-item__row">
						<span class="scw-item__title">{{ getMainText(opp) }}</span>
					</div>
					<div class="scw-item__meta">
						{{ getSubline(opp) }}
					</div>
				</a>
			</li>
		</ul>
	</SuiteCRMWidgetShell>
</template>

<script>
/**
 * SuiteCRMPipeline — "My pipeline" dashboard widget.
 *
 * Pipeline framing (`pipeline_mode`) has moved out of the global
 * PersonalSettings.vue block and into this widget's own 3-dot menu, so
 * a user with multiple SuiteCRM dashboards on their profile can pick
 * a framing without touching a global setting. The pref key remains
 * the same (`pipeline_mode`) so existing values from 3.0.x install
 * carry over verbatim; PersonalSettings.vue continues to expose the
 * key as an alias for discovery.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import TrendingUpIcon from 'vue-material-design-icons/TrendingUp.vue'
import SuiteCRMWidgetShell from '../components/SuiteCRMWidgetShell.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'SuiteCRMPipeline',

	components: { SuiteCRMWidgetShell, TrendingUpIcon },

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			suitecrmUrl: null,
			opportunities: [],
			state: 'loading',
			refreshSeconds: 300,
			onlyMine: true,
			saving: false,
			mode: 'closing_quarter',
		}
	},

	computed: {
		modeOptions() {
			return [
				{ value: 'closing_quarter', label: t('integration_suitecrm', 'Closing this quarter') },
				{ value: 'top_value', label: t('integration_suitecrm', 'Top value (all open)') },
				{ value: 'weighted', label: t('integration_suitecrm', 'Weighted value') },
			]
		},

		showMoreUrl() {
			return this.suitecrmUrl ? this.suitecrmUrl + '/index.php?module=Opportunities&action=index' : ''
		},

		emptyText() {
			if (this.mode === 'top_value' || this.mode === 'weighted') {
				return t('integration_suitecrm', 'No open SuiteCRM Opportunities')
			}
			return t('integration_suitecrm', 'No SuiteCRM Opportunities closing this quarter')
		},
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchOpportunities()
		this.loadWidgetConfig().then(() => this.probeUrl()).then(() => this.fetchOpportunities())
	},

	methods: {
		async loadWidgetConfig() {
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/widget-config'))
				const seconds = Number(response.data?.pipeline_refresh_seconds)
				if (!Number.isNaN(seconds)) {
					this.refreshSeconds = seconds
					this.autoRefresh.setIntervalMs(seconds * 1000)
				}
				if (response.data?.pipeline_mode) {
					this.mode = response.data.pipeline_mode
				}
				if (typeof response.data?.pipeline_only_mine === 'boolean') {
					this.onlyMine = response.data.pipeline_only_mine
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

		fetchOpportunities() {
			const url = generateUrl('/apps/integration_suitecrm/my-pipeline?mode={mode}&onlyMine={m}', {
				mode: this.mode,
				m: this.onlyMine ? '1' : '0',
			})
			axios.get(url).then((response) => {
				this.opportunities = response.data
				this.state = 'ok'
			}).catch((error) => {
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM pipeline'))
					this.state = 'error'
				}
			})
		},

		async onSaveSettings({ refreshSeconds, onlyMine, extras, close }) {
			this.saving = true
			try {
				const values = {
					pipeline_refresh_seconds: String(refreshSeconds),
					pipeline_only_mine: onlyMine ? '1' : '0',
				}
				if (extras?.pipeline_mode) {
					values.pipeline_mode = String(extras.pipeline_mode)
				}
				await axios.put(generateUrl('/apps/integration_suitecrm/config'), { values })
				this.refreshSeconds = refreshSeconds
				this.autoRefresh.setIntervalMs(refreshSeconds * 1000)
				let needRefetch = false
				if (extras?.pipeline_mode && extras.pipeline_mode !== this.mode) {
					this.mode = extras.pipeline_mode
					needRefetch = true
				}
				if (onlyMine !== this.onlyMine) {
					this.onlyMine = onlyMine
					needRefetch = true
				}
				if (needRefetch) {
					this.fetchOpportunities()
				}
				close()
				showSuccess(t('integration_suitecrm', 'Widget settings saved.'))
			} catch {
				showError(t('integration_suitecrm', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},

		getOpportunityTarget(opp) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Opportunities&action=DetailView&record=' + opp.id
		},

		getMainText(opp) {
			return opp.attributes?.name || t('integration_suitecrm', '(no title)')
		},

		formatMoney(amount) {
			return Number(amount || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })
		},

		getSubline(opp) {
			const parts = []
			const stage = opp.attributes?.sales_stage
			if (stage) {
				parts.push(stage)
			}
			const symbol = opp.attributes?.currency_symbol || ''
			const amount = opp.amount_num || 0
			const probability = opp.probability_num || 0
			if (this.mode === 'weighted') {
				const weighted = opp.weighted_num || 0
				parts.push(t('integration_suitecrm', '{sym}{w} weighted (of {sym}{a} at {p}%)', {
					sym: symbol,
					w: this.formatMoney(weighted),
					a: this.formatMoney(amount),
					p: Math.round(probability),
				}))
			} else if (this.mode === 'top_value') {
				parts.push(`${symbol}${this.formatMoney(amount)}`)
				if (probability > 0) {
					parts.push(t('integration_suitecrm', '{p}% probability', { p: Math.round(probability) }))
				}
			} else {
				if (opp.close_ts) {
					const closeDate = new Date(opp.close_ts * 1000).toISOString().slice(0, 10)
					parts.push(t('integration_suitecrm', 'closes {d}', { d: closeDate }))
				}
				parts.push(`${symbol}${this.formatMoney(amount)}`)
			}
			return parts.join(' · ')
		},
	},
}
</script>

<style scoped lang="scss">
.scw-mode-choice {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.scw-mode-radio {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 4px;
	cursor: pointer;

	input[type="radio"] {
		margin: 0;
		flex-shrink: 0;
	}
}
</style>
