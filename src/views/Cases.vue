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
 * SuiteCRMCases.
 *
 * "My open Cases" dashboard widget. Mirrors the shape of
 * ./Calendar.vue so a rep familiar with the schedule widget finds
 * the same interaction model here, click a row to open the record
 * in SuiteCRM, connect-button empty-state, 120s polling loop paused
 * when the tab is hidden.
 *
 * The subline format is priority · status · "N days open" (or
 * "opened today" for age 0). Case number is prefixed to the main
 * text so a rep with the SuiteCRM case number in mind can spot the
 * right row without opening it.
 *
 * @author Kim Haverblad
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl, imagePath } from '@nextcloud/router'
import NcDashboardWidget from '@nextcloud/vue/components/NcDashboardWidget'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

export default {
	name: 'SuiteCRMCases',

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
			cases: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Cases&action=index'
		},

		items() {
			return this.cases.map((c) => ({
				id: c.id,
				targetUrl: this.getCaseTarget(c),
				avatarUrl: imagePath('njordium_suitecrm', 'app.svg'),
				avatarUsername: this.getMainText(c),
				mainText: this.getMainText(c),
				subText: this.getSubline(c),
			}))
		},

		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('njordium_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('njordium_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('njordium_suitecrm', 'No open SuiteCRM Cases')
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
			this.fetchCases()
			this.loop = setInterval(() => this.fetchCases(), 120000)
		},

		fetchCases() {
			axios.get(generateUrl('/apps/njordium_suitecrm/my-cases')).then((response) => {
				this.cases = response.data
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('njordium_suitecrm', 'Failed to get SuiteCRM open Cases'))
					this.state = 'error'
				}
			})
		},

		getCaseTarget(c) {
			if (!this.suitecrmUrl) {
				return ''
			}
			return this.suitecrmUrl + '/index.php?module=Cases&action=DetailView&record=' + c.id
		},

		getMainText(c) {
			const name = c.attributes?.name || t('njordium_suitecrm', '(no title)')
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
				parts.push(n('njordium_suitecrm', '%n day open', '%n days open', ageDays))
			} else {
				parts.push(t('njordium_suitecrm', 'opened today'))
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
