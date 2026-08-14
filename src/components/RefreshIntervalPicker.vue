<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		:clearable="false"
		:searchable="false"
		label="label"
		class="scw-interval-picker"
		@update:modelValue="onChange" />
</template>

<script>
/**
 * Refresh-interval selector shared by every SuiteCRM dashboard widget's
 * settings modal. Mirrors the shape of the sibling
 * integration_forgejo_gitea RefreshIntervalPicker so the two apps offer
 * the same cadence choices — a user running both should not need to
 * relearn the picker semantics.
 *
 * v-model binds to a Number (seconds). 0 disables the periodic poll;
 * useAutoRefresh will still refetch on wake signals in that case.
 */
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'RefreshIntervalPicker',
	components: { NcSelect },
	props: {
		modelValue: { type: Number, default: 300 },
	},

	emits: ['update:modelValue'],
	computed: {
		options() {
			return [
				{ value: 0, label: t('integration_suitecrm', 'Never (manual only)') },
				{ value: 30, label: t('integration_suitecrm', 'Every 30 seconds') },
				{ value: 60, label: t('integration_suitecrm', 'Every minute') },
				{ value: 300, label: t('integration_suitecrm', 'Every 5 minutes') },
				{ value: 900, label: t('integration_suitecrm', 'Every 15 minutes') },
				{ value: 1800, label: t('integration_suitecrm', 'Every 30 minutes') },
				{ value: 3600, label: t('integration_suitecrm', 'Every hour') },
			]
		},

		selected() {
			return this.options.find((o) => o.value === this.modelValue)
				|| this.options.find((o) => o.value === 300)
		},
	},

	methods: {
		onChange(v) {
			if (v && typeof v === 'object' && 'value' in v) {
				this.$emit('update:modelValue', v.value)
			}
		},
	},
}
</script>

<style scoped>
.scw-interval-picker {
	width: 100%;
}
</style>
