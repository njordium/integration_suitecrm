<template>
	<NcDialog
		:name="dialogTitle"
		:noClose="submitting"
		size="normal"
		@closing="close">
		<div class="scw-file-dialog">
			<p class="scw-file-dialog__source">
				{{ t('integration_suitecrm', 'File') }}: <strong>{{ fileName }}</strong>
			</p>

			<label class="scw-file-dialog__label">
				{{ t('integration_suitecrm', 'Link to SuiteCRM record') }}
				<SuiteCRMRecordPicker
					v-model="targetRecord"
					:disabled="submitting" />
			</label>

			<label class="scw-file-dialog__label">
				{{ t('integration_suitecrm', 'Note title') }}
				<NcTextField
					v-model="noteTitle"
					:placeholder="defaultNoteTitle"
					:disabled="submitting" />
			</label>

			<label class="scw-file-dialog__label">
				{{ t('integration_suitecrm', 'Extra notes (optional)') }}
				<textarea
					v-model="extraNotes"
					:disabled="submitting"
					rows="3"
					class="scw-file-dialog__textarea"
					:placeholder="t('integration_suitecrm', 'Context that will not be obvious from the file name …')" />
			</label>
		</div>

		<template #actions>
			<NcButton variant="tertiary" :disabled="submitting" @click="close">
				{{ t('integration_suitecrm', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting || !canSubmit"
				@click="submit">
				<template v-if="submitting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ submitting ? t('integration_suitecrm', 'Linking …') : t('integration_suitecrm', 'Link file') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * FileToSuiteCRMDialog.
 *
 * Small modal launched from the Files action registered in
 * filesHook.js. Collects a SuiteCRM record + an optional note title
 * and extras, then POSTs to the existing `/log-note` endpoint with
 * a body that carries a Nextcloud link to the source file. No file
 * bytes are transferred — SuiteCRM stores a link, Nextcloud remains
 * the single source of truth for the file itself.
 *
 * `isTalkArtefact` flips the pre-filled note title so a recording or
 * AI-summary carries a more descriptive default without forcing the
 * user to retype it.
 *
 * Mounted on demand by filesHook.js (createApp + mount + unmount on
 * close) so an idle Files page pays zero cost for the modal.
 *
 * @author Kim Haverblad
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SuiteCRMRecordPicker from './SuiteCRMRecordPicker.vue'

export default {
	name: 'FileToSuiteCRMDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
		SuiteCRMRecordPicker,
	},

	props: {
		fileName: { type: String, required: true },
		filePath: { type: String, required: true },
		fileId: { type: Number, default: 0 },
		isTalkArtefact: { type: Boolean, default: false },
		onClose: { type: Function, default: () => {} },
	},

	data() {
		return {
			targetRecord: null,
			noteTitle: '',
			extraNotes: '',
			submitting: false,
		}
	},

	computed: {
		dialogTitle() {
			if (this.isTalkArtefact) {
				return t('integration_suitecrm', 'Log Talk artefact to SuiteCRM')
			}
			return t('integration_suitecrm', 'Link file to SuiteCRM record')
		},

		defaultNoteTitle() {
			if (this.isTalkArtefact) {
				return t('integration_suitecrm', 'Talk artefact: {name}', { name: this.fileName })
			}
			return t('integration_suitecrm', 'Nextcloud file: {name}', { name: this.fileName })
		},

		canSubmit() {
			return this.targetRecord && this.targetRecord.id && this.targetRecord.module
		},

		fileUrl() {
			// `/f/{fileid}` is Nextcloud's canonical shortcut to a
			// specific file; it resolves regardless of the file's
			// current folder path, so a rename or move on the NC
			// side doesn't break the link stored in SuiteCRM.
			if (this.fileId) {
				return generateRemoteUrl('').replace(/\/remote\.php.*$/, '') + generateUrl('/f/{id}', { id: this.fileId })
			}
			// Fallback: link to the containing folder path when no
			// numeric fileid is available (rare — mostly for shared
			// items with restricted permissions).
			return generateRemoteUrl('').replace(/\/remote\.php.*$/, '') + generateUrl('/apps/files/files' + this.filePath)
		},
	},

	methods: {
		close() {
			this.onClose()
		},

		async submit() {
			this.submitting = true
			const title = (this.noteTitle || '').trim() || this.defaultNoteTitle
			const body = [
				this.extraNotes.trim(),
				'',
				t('integration_suitecrm', 'Nextcloud file link: {url}', { url: this.fileUrl }),
				t('integration_suitecrm', 'File name: {name}', { name: this.fileName }),
			].filter(Boolean).join('\n')
			try {
				await axios.post(generateUrl('/apps/integration_suitecrm/log-note'), {
					targetModule: this.targetRecord.module,
					targetId: this.targetRecord.id,
					name: title,
					description: body,
				})
				showSuccess(t('integration_suitecrm', 'Linked to SuiteCRM.'))
				this.close()
			} catch (error) {
				showError(t('integration_suitecrm', 'Failed to link file: {msg}', {
					msg: error?.response?.data?.error || error?.message || 'unknown error',
				}))
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.scw-file-dialog {
	padding: 12px 4px 4px;
	display: flex;
	flex-direction: column;
	gap: 16px;

	&__source {
		margin: 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__label {
		display: flex;
		flex-direction: column;
		gap: 6px;
		font-size: 13px;
		font-weight: 500;
	}

	&__textarea {
		width: 100%;
		min-height: 60px;
		padding: 8px;
		border-radius: var(--border-radius);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-family: inherit;
		font-size: 13px;
	}
}
</style>
