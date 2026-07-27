<template>
	<NcDialog
		v-if="open"
		:name="t('integration_suitecrm', 'Convert email to SuiteCRM Case')"
		:noClose="submitting"
		size="normal"
		@closing="$emit('close')">
		<div class="email-to-case-modal">
			<label class="email-to-case-modal__label">
				{{ t('integration_suitecrm', 'Subject') }}
				<NcTextField
					v-model="subject"
					:placeholder="t('integration_suitecrm', 'Case name, usually the email subject')"
					:disabled="submitting"
					required />
			</label>

			<label class="email-to-case-modal__label">
				{{ t('integration_suitecrm', 'Email body') }}
				<textarea
					v-model="body"
					:disabled="submitting"
					rows="8"
					class="email-to-case-modal__textarea"
					:placeholder="t('integration_suitecrm', 'Paste the full message text …')" />
			</label>

			<div class="email-to-case-modal__row">
				<label class="email-to-case-modal__label email-to-case-modal__col">
					{{ t('integration_suitecrm', 'Sender name (optional)') }}
					<NcTextField
						v-model="senderName"
						:disabled="submitting" />
				</label>

				<label class="email-to-case-modal__label email-to-case-modal__col">
					{{ t('integration_suitecrm', 'Sender email (optional)') }}
					<NcTextField
						v-model="senderEmail"
						:disabled="submitting" />
				</label>
			</div>

			<div class="email-to-case-modal__row">
				<label class="email-to-case-modal__label email-to-case-modal__col">
					{{ t('integration_suitecrm', 'Email date (optional)') }}
					<input
						v-model="emailDate"
						type="date"
						:disabled="submitting"
						class="email-to-case-modal__date-input">
				</label>

				<label class="email-to-case-modal__label email-to-case-modal__col">
					{{ t('integration_suitecrm', 'Priority') }}
					<NcSelect
						v-model="priority"
						:options="priorityOptions"
						:reduce="(option) => option.value"
						:clearable="false"
						:disabled="submitting" />
				</label>
			</div>

			<p class="email-to-case-modal__hint">
				{{ t('integration_suitecrm', 'A SuiteCRM Case is created with the subject as name and the email body as description. The From/Date headers appear at the top of the description only if you supply them, so paste-only submissions stay clean.') }}
			</p>
		</div>

		<template #actions>
			<NcButton
				variant="tertiary"
				:disabled="submitting"
				@click="$emit('close')">
				{{ t('integration_suitecrm', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting || !canSubmit"
				@click="submit">
				<template v-if="submitting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ submitting ? t('integration_suitecrm', 'Creating Case …') : t('integration_suitecrm', 'Create Case') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * EmailToCaseModal.
 *
 * Paste-form flow for the fourth planned write feature: convert an
 * email into a SuiteCRM Case. Because NC Mail's third-party action-
 * hook API is out of scope for the initial roadmap (would need Mail
 * to expose a message-context extension point), the MVP UX is a
 * plain form. Users paste the subject + body and optionally sender
 * metadata; the backend endpoint composes the Case body with a
 * stable "From:" / "Date:" header and creates the Case with the
 * chosen priority.
 *
 * Contact / Account linking (match sender email to a SuiteCRM
 * Contact) is intentionally omitted from the MVP, the frontend
 * would need its own record-picker + search API round-trip, and
 * the value is limited until we prove the base flow in production.
 * Can be added later without breaking the endpoint contract.
 *
 * @author Kim Haverblad
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
	name: 'EmailToCaseModal',

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
	},

	emits: ['close', 'created'],

	data() {
		return {
			subject: '',
			body: '',
			senderName: '',
			senderEmail: '',
			emailDate: '',
			priority: 'Medium',
			submitting: false,
			priorityOptions: [
				{ label: t('integration_suitecrm', 'High'), value: 'High' },
				{ label: t('integration_suitecrm', 'Medium'), value: 'Medium' },
				{ label: t('integration_suitecrm', 'Low'), value: 'Low' },
			],
		}
	},

	computed: {
		canSubmit() {
			return this.subject.trim().length > 0 && this.body.trim().length > 0
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.subject = ''
				this.body = ''
				this.senderName = ''
				this.senderEmail = ''
				this.emailDate = ''
				this.priority = 'Medium'
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
				const url = generateUrl('/apps/integration_suitecrm/email-to-case')
				const payload = {
					subject: this.subject.trim(),
					body: this.body,
					senderEmail: this.senderEmail.trim(),
					senderName: this.senderName.trim(),
					emailDate: this.emailDate,
					priority: this.priority,
				}
				const response = await axios.post(url, payload)
				const recordId = response?.data?.data?.id ?? null
				showSuccess(t('integration_suitecrm', 'SuiteCRM Case created'))
				this.$emit('created', { id: recordId })
				this.$emit('close')
			} catch (err) {
				const backendError = err?.response?.data?.error
				const msg = backendError
					? t('integration_suitecrm', 'Could not create Case: {msg}', { msg: backendError })
					: t('integration_suitecrm', 'Could not create Case in SuiteCRM')
				showError(msg)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.email-to-case-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;

	&__label {
		display: flex;
		flex-direction: column;
		gap: 4px;
		font-weight: 500;
	}

	&__row {
		display: flex;
		gap: 12px;
	}

	&__col {
		flex: 1;
		min-width: 0;
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

	&__date-input {
		width: 100%;
		padding: 8px;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius);
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}
}
</style>
