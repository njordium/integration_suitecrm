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
 * SuiteCRMPipeline — iter 77.
 *
 * "My pipeline" dashboard widget. Framing is settings-driven via
 * the `pipeline_mode` personal preference — see PersonalSettings.vue
 * for the selector. All three modes are handled here through the
 * initial-state loadState call so the widget doesn't need to
 * re-fetch preferences on every polling cycle.
 *
 * The subline shape shifts with mode:
 *   - closing_quarter: `stage · closes YYYY-MM-DD · $amount`
 *   - top_value:       `stage · $amount · N% probability`
 *   - weighted:        `stage · $weighted weighted (of $amount at N%)`
 *
 * Backend already emits the mode-appropriate `weighted_num` /
 * `close_ts` fields so the frontend just picks what to show.
 *
 * @author Kim Haverblad
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl, imagePath } from '@nextcloud/router'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

export default {
	name: 'SuiteCRMPipeline',

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
		// loadState may not be available in every dashboard-mount
		// context; wrap in try/catch and fall back to the default.
		let mode = 'closing_quarter'
		try {
			const config = loadState('njordium_suitecrm', 'user-config')
			if (config?.pipeline_mode) {
				mode = config.pipeline_mode
			}
		} catch {
			// no-op — settings not present, use default
		}
		return {
			suitecrmUrl: null,
			opportunities: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
			mode,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Opportunities&action=index'
		},

		items() {
			return this.opportunities.map((opp) => ({
				id: opp.id,
				targetUrl: this.getOpportunityTarget(opp),
				avatarUrl: imagePath('njordium_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(opp),
				mainText: this.getMainText(opp),
				subText: this.getSubline(opp),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('njordium_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('njordium_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				if (this.mode === 'top_value' || this.mode === 'weighted') {
					return t('njordium_suitecrm', 'No open SuiteCRM Opportunities')
				}
				return t('njordium_suitecrm', 'No SuiteCRM Opportunities closing this quarter')
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
			this.fetchOpportunities()
			this.loop = setInterval(() => this.fetchOpportunities(), 120000)
		},

		fetchOpportunities() {
			const url = generateUrl('/apps/njordium_suitecrm/my-pipeline?mode={mode}', { mode: this.mode })
			axios.get(url).then((response) => {
				this.opportunities = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('njordium_suitecrm', 'Failed to get SuiteCRM pipeline'))
					this.state = 'error'
				}
			})
		},

		getOpportunityTarget(opp) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Opportunities&action=DetailView&record=' + opp.id
		},

		getMainText(opp) {
			return opp.attributes?.name || t('njordium_suitecrm', '(no title)')
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
				parts.push(t('njordium_suitecrm', '{sym}{w} weighted (of {sym}{a} at {p}%)', {
					sym: symbol,
					w: this.formatMoney(weighted),
					a: this.formatMoney(amount),
					p: Math.round(probability),
				}))
			} else if (this.mode === 'top_value') {
				parts.push(`${symbol}${this.formatMoney(amount)}`)
				if (probability > 0) {
					parts.push(t('njordium_suitecrm', '{p}% probability', { p: Math.round(probability) }))
				}
			} else {
				if (opp.close_ts) {
					const closeDate = new Date(opp.close_ts * 1000).toISOString().slice(0, 10)
					parts.push(t('njordium_suitecrm', 'closes {d}', { d: closeDate }))
				}
				parts.push(`${symbol}${this.formatMoney(amount)}`)
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
