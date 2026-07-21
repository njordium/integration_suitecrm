<template>
	<NcDialog
		v-if="open"
		:name="dialogTitle"
		:can-close="!submitting"
		size="normal"
		@closing="$emit('close')">
		<div class="task-followup-modal">
			<p v-if="sourceLabel" class="task-followup-modal__source">
				{{ t('njordium_suitecrm', 'Linked to') }}: <strong>{{ sourceLabel }}</strong>
			</p>

			<NcTextField
				ref="nameField"
				:value.sync="name"
				:label="t('njordium_suitecrm', 'Task name')"
				:placeholder="t('njordium_suitecrm', 'Follow up …')"
				:disabled="submitting"
				required />

			<label class="task-followup-modal__label">
				{{ t('njordium_suitecrm', 'Due date (optional)') }}
				<input
					v-model="dateDue"
					type="date"
					:disabled="submitting"
					class="task-followup-modal__date-input">
			</label>

			<label class="task-followup-modal__label">
				{{ t('njordium_suitecrm', 'Priority') }}
				<NcSelect
					v-model="priority"
					:options="priorityOptions"
					:reduce="option => option.value"
					:clearable="false"
					:disabled="submitting" />
			</label>

			<label class="task-followup-modal__label">
				{{ t('njordium_suitecrm', 'Notes (optional)') }}
				<textarea
					v-model="description"
					:disabled="submitting"
					rows="3"
					class="task-followup-modal__textarea"
					:placeholder="t('njordium_suitecrm', 'Details, agenda items, action items …')" />
			</label>
		</div>

		<template #actions>
			<NcButton
				variant="tertiary"
				:disabled="submitting"
				@click="$emit('close')">
				{{ t('njordium_suitecrm', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting || !canSubmit"
				@click="submit">
				<template v-if="submitting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ submitting ? t('njordium_suitecrm', 'Creating …') : t('njordium_suitecrm', 'Create Task') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * TaskFollowupModal — iter 69.
 *
 * Prompts the user for a follow-up SuiteCRM Task's name, optional due
 * date, priority, and notes; POSTs to `/apps/njordium_suitecrm/task-followup`;
 * emits `created` on success. Meant to be rendered per widget (dashboard
 * events + calendar), each of which passes its own {sourceModule,
 * sourceId, sourceLabel} to prefill the link.
 *
 * Design deliberately minimal — this is the first user-facing write
 * feature; keeping the modal simple makes the "did this work end-to-end
 * against SuiteCRM 8.10.x" verification cheap. Extra fields (assigned
 * user, contact link, follow-up date reminder) can land in later iters
 * once the base flow is proven in production.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'TaskFollowupModal',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		sourceModule: {
			type: String,
			required: true,
		},
		sourceId: {
			type: String,
			required: true,
		},
		sourceLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			name: '',
			description: '',
			dateDue: '',
			priority: 'Medium',
			submitting: false,
			priorityOptions: [
				{ label: t('njordium_suitecrm', 'High'), value: 'High' },
				{ label: t('njordium_suitecrm', 'Medium'), value: 'Medium' },
				{ label: t('njordium_suitecrm', 'Low'), value: 'Low' },
			],
		}
	},

	computed: {
		dialogTitle() {
			return this.sourceLabel
				? t('njordium_suitecrm', 'Create follow-up Task')
				: t('njordium_suitecrm', 'Create SuiteCRM Task')
		},
		canSubmit() {
			return this.name.trim().length > 0
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				// Reset each time the modal is opened — avoids the prior
				// user's stale data leaking into the next follow-up.
				this.name = ''
				this.description = ''
				this.dateDue = ''
				this.priority = 'Medium'
				this.$nextTick(() => this.$refs.nameField?.focus?.())
			}
		},
	},

	methods: {
		async submit() {
			if (!this.canSubmit || this.submitting) {
				return
			}
			this.submitting = true
			try {
				const response = await axios.post(
					generateUrl('/apps/njordium_suitecrm/task-followup'),
					{
						sourceModule: this.sourceModule,
						sourceId: this.sourceId,
						name: this.name.trim(),
						description: this.description,
						// Only send dateDue if the user picked one; the
						// endpoint treats null/empty as "no due date".
						dateDue: this.dateDue || null,
						priority: this.priority,
					},
				)
				const recordId = response?.data?.data?.id ?? null
				showSuccess(t('njordium_suitecrm', 'Follow-up Task created in SuiteCRM'))
				this.$emit('created', { id: recordId })
				this.$emit('close')
			} catch (err) {
				const backendError = err?.response?.data?.error
				showError(
					backendError
						? t('njordium_suitecrm', 'Could not create Task: {msg}', { msg: backendError })
						: t('njordium_suitecrm', 'Could not create Task in SuiteCRM'),
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.task-followup-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;

	&__source {
		margin: 0 0 4px 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__label {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-weight: 500;
	}

	&__date-input {
		width: 100%;
		padding: 8px;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius);
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	&__textarea {
		width: 100%;
		padding: 8px;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-family: inherit;
		resize: vertical;
	}
}
</style>
