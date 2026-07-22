<template>
	<div class="suitecrm-record-picker">
		<label class="suitecrm-record-picker__label">
			{{ label || t('njordium_suitecrm', 'SuiteCRM record URL') }}
		</label>

		<NcTextField
			v-model="urlInput"
			:placeholder="t('njordium_suitecrm', 'Paste a SuiteCRM record URL …')"
			:disabled="disabled" />

		<p v-if="parsed" class="suitecrm-record-picker__parsed">
			<CheckCircleIcon :size="16" class="suitecrm-record-picker__icon-ok" />
			{{ t('njordium_suitecrm', 'Selected') }}:
			<strong>{{ parsed.module }}</strong>
			<code>{{ parsed.id }}</code>
		</p>
		<p v-else-if="urlInput && !parsed" class="suitecrm-record-picker__error">
			<AlertCircleIcon :size="16" class="suitecrm-record-picker__icon-error" />
			{{ t('njordium_suitecrm', 'Not a recognised SuiteCRM record URL. Expected a URL containing module= and record= parameters (open a record in SuiteCRM and copy the browser URL).') }}
		</p>
	</div>
</template>

<script>
/**
 * SuiteCRMRecordPicker: shared record-selector infrastructure.
 *
 * Lightweight picker for a SuiteCRM record. Instead of a live-search
 * against the SuiteCRM API (which would need a new backend endpoint,
 * paginated search UI, keyboard nav, debouncing, and a rework of the
 * existing search provider), this picker accepts a pasted SuiteCRM
 * record URL and parses it client-side.
 *
 * The rationale: users who want to link a SuiteCRM record from a
 * Nextcloud workflow have almost certainly just opened that record in
 * a SuiteCRM tab. Copying the URL is a one-second gesture; live-search
 * from a tiny modal would be worse UX (unknown search terms, few
 * results shown, no context) even after significantly more code.
 *
 * The URL regex mirrors OCA\SuiteCRM\Reference\RecordUrlParser on the
 * backend so the reference-provider preview cards and the write-side
 * picker accept the exact same URL shapes.
 *
 * Consumed by TalkToNoteModal, LinkDeckCardModal,
 * and EmailToCaseModal. Each modal binds v-model to a `record`
 * data property and renders the picker inside its form.
 *
 * @author Kim Haverblad
 */
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'

const RECORD_URL_PATTERN = /\/index\.php\?[^"\s]*module=([A-Za-z0-9_]+)(?:&|&amp;)[^"\s]*record=([a-zA-Z0-9-]+)/

const SUPPORTED_MODULES = [
	'Contacts',
	'Accounts',
	'Leads',
	'Opportunities',
	'Cases',
	'Meetings',
	'Calls',
	'Tasks',
]

export default {
	name: 'SuiteCRMRecordPicker',

	components: {
		AlertCircleIcon,
		CheckCircleIcon,
		NcTextField,
	},

	props: {
		modelValue: {
			type: Object,
			default: null,
		},

		label: {
			type: String,
			default: '',
		},

		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			urlInput: '',
		}
	},

	computed: {
		parsed() {
			return this.parseUrl(this.urlInput)
		},
	},

	watch: {
		urlInput() {
			// Emit only when the parse result actually changes value,
			// avoids emit spam on every keystroke that doesn't move the
			// {module, id} tuple.
			const next = this.parsed
			const prev = this.modelValue
			const same = next === prev
				|| (next && prev && next.module === prev.module && next.id === prev.id)
			if (!same) {
				this.$emit('update:modelValue', next)
			}
		},

		modelValue: {
			immediate: true,
			handler(newValue) {
				// Allow parent to clear the picker by setting modelValue to null.
				if (newValue === null && this.urlInput !== '') {
					this.urlInput = ''
				}
			},
		},
	},

	methods: {
		parseUrl(url) {
			if (!url) {
				return null
			}
			const match = url.match(RECORD_URL_PATTERN)
			if (!match) {
				return null
			}
			const [, module, id] = match
			if (!SUPPORTED_MODULES.includes(module)) {
				return null
			}
			return { module, id }
		},
	},
}
</script>

<style scoped lang="scss">
.suitecrm-record-picker {
	display: flex;
	flex-direction: column;
	gap: 6px;

	&__label {
		font-weight: 500;
	}

	&__parsed,
	&__error {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 4px 0 0 0;
		font-size: 0.9em;
	}

	&__parsed {
		color: var(--color-success);
	}

	&__error {
		color: var(--color-warning);
	}

	&__icon-ok {
		color: var(--color-success);
	}

	&__icon-error {
		color: var(--color-warning);
	}

	code {
		font-family: monospace;
		padding: 1px 4px;
		background: var(--color-background-dark);
		border-radius: 3px;
	}
}
</style>
