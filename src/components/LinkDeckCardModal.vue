<template>
	<NcDialog
		v-if="open"
		:name="t('njordium_suitecrm', 'Link Nextcloud Deck card to SuiteCRM')"
		:noClose="submitting"
		size="normal"
		@closing="$emit('close')">
		<div class="link-deck-card-modal">
			<label class="link-deck-card-modal__label">
				{{ t('njordium_suitecrm', 'Deck card URL') }}
				<NcTextField
					v-model="deckCardUrl"
					:placeholder="t('njordium_suitecrm', 'Open a Deck card, copy the URL, paste it here …')"
					:disabled="submitting" />
			</label>

			<p v-if="parsedDeckCard" class="link-deck-card-modal__parsed">
				<CheckCircleIcon :size="16" />
				{{ t('njordium_suitecrm', 'Card') }}:
				<code>{{ parsedDeckCard.cardId }}</code>
				({{ t('njordium_suitecrm', 'board {b}', { b: parsedDeckCard.boardId }) }})
			</p>
			<p v-else-if="deckCardUrl" class="link-deck-card-modal__error">
				<AlertCircleIcon :size="16" />
				{{ t('njordium_suitecrm', 'Not a recognised Deck card URL. Expected the form /apps/deck/#/board/<id>/card/<id>.') }}
			</p>

			<label class="link-deck-card-modal__label">
				{{ t('njordium_suitecrm', 'Deck card title (optional)') }}
				<NcTextField
					v-model="deckCardTitle"
					:placeholder="t('njordium_suitecrm', 'Human-readable label for the SuiteCRM Note …')"
					:disabled="submitting" />
			</label>

			<SuiteCRMRecordPicker
				v-model="targetRecord"
				:label="t('njordium_suitecrm', 'Attach Note to (SuiteCRM record URL)')"
				:disabled="submitting" />

			<label class="link-deck-card-modal__label">
				{{ t('njordium_suitecrm', 'Extra note (optional)') }}
				<textarea
					v-model="extraNote"
					:disabled="submitting"
					rows="2"
					class="link-deck-card-modal__textarea"
					:placeholder="t('njordium_suitecrm', 'Why this card is relevant …')" />
			</label>

			<NcNoteCard v-if="postWarning" type="warning">
				{{ postWarning }}
			</NcNoteCard>

			<p class="link-deck-card-modal__hint">
				{{ t('njordium_suitecrm', 'A Note is created on the SuiteCRM record with the Deck card URL, and a matching comment is added to the Deck card pointing back at the SuiteCRM record. If commenting on the Deck card fails, the SuiteCRM Note is still created and reported.') }}
			</p>
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
				{{ submitting ? t('njordium_suitecrm', 'Linking …') : t('njordium_suitecrm', 'Link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * LinkDeckCardModal — iter 71b.
 *
 * Creates a bidirectional link between a Nextcloud Deck card and a
 * SuiteCRM record. Two-part submit:
 *
 *   1. POST /apps/njordium_suitecrm/link-deck-card creates a Note on
 *      the SuiteCRM record referencing the Deck card URL (this is the
 *      side we can guarantee — same failure envelope as the other
 *      write endpoints).
 *   2. POST /ocs/v2.php/apps/deck/api/v1.0/cards/{cardId}/comments
 *      adds a Deck comment pointing back at the SuiteCRM record. If
 *      this fails (Deck not installed, insufficient permissions,
 *      etc.) we still report the SuiteCRM-side success and surface
 *      the Deck failure as a warning so the user isn't left thinking
 *      nothing happened.
 *
 * URL-parsing is client-side; the backend also validates the URL as
 * a URL but doesn't fetch it.
 *
 * @author Kim Haverblad
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import SuiteCRMRecordPicker from './SuiteCRMRecordPicker.vue'

// Matches URLs like https://cloud.example.com/apps/deck/#/board/12/card/347
// or /index.php/apps/deck/#/board/12/card/347. The second capture is the
// card id we POST comments to; the first is used for display + audit.
const DECK_CARD_URL_PATTERN = /\/apps\/deck\/#\/board\/(\d+)\/card\/(\d+)/

export default {
	name: 'LinkDeckCardModal',

	components: {
		AlertCircleIcon,
		CheckCircleIcon,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		SuiteCRMRecordPicker,
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
			deckCardUrl: '',
			deckCardTitle: '',
			targetRecord: null,
			extraNote: '',
			submitting: false,
			postWarning: '',
		}
	},

	computed: {
		parsedDeckCard() {
			const match = this.deckCardUrl.match(DECK_CARD_URL_PATTERN)
			if (!match) {
				return null
			}
			return { boardId: match[1], cardId: match[2] }
		},

		canSubmit() {
			return this.parsedDeckCard !== null && this.targetRecord !== null
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.deckCardUrl = ''
				this.deckCardTitle = ''
				this.targetRecord = null
				this.extraNote = ''
				this.postWarning = ''
			}
		},
	},

	methods: {
		async submit() {
			if (!this.canSubmit || this.submitting) {
				return
			}
			this.submitting = true
			this.postWarning = ''
			try {
				// Step 1: SuiteCRM-side Note. If this fails there's no
				// point commenting on the Deck card — the link would be
				// half-dead.
				const suitecrmUrl = generateUrl('/apps/njordium_suitecrm/link-deck-card')
				const suitecrmPayload = {
					deckCardUrl: this.deckCardUrl.trim(),
					deckCardTitle: this.deckCardTitle.trim(),
					targetModule: this.targetRecord.module,
					targetId: this.targetRecord.id,
					extraNote: this.extraNote.trim(),
				}
				const suitecrmResponse = await axios.post(suitecrmUrl, suitecrmPayload)
				const noteId = suitecrmResponse?.data?.data?.id ?? null

				// Step 2: Deck-side comment. Best-effort — a failure here
				// still counts as partial success because the SuiteCRM
				// side landed.
				const commentBody = this.composeDeckComment()
				const cardId = encodeURIComponent(this.parsedDeckCard.cardId)
				const commentUrl = generateOcsUrl('apps/deck/api/v1.0/cards/' + cardId + '/comments')
				const commentPayload = { message: commentBody }
				const commentConfig = {
					headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				}
				try {
					await axios.post(commentUrl, commentPayload, commentConfig)
					showSuccess(t('njordium_suitecrm', 'Deck card and SuiteCRM record linked both ways'))
				} catch (deckErr) {
					// Deck comment failed. Report as a warning; the
					// SuiteCRM side is fine.
					const status = deckErr?.response?.status
					if (status === 404) {
						this.postWarning = t('njordium_suitecrm', 'SuiteCRM Note created, but Deck is not installed on this server so no card comment was added.')
					} else if (status === 403) {
						this.postWarning = t('njordium_suitecrm', 'SuiteCRM Note created, but you do not have permission to comment on that Deck card.')
					} else {
						this.postWarning = t('njordium_suitecrm', 'SuiteCRM Note created, but the Deck-card comment failed. Nextcloud log should have details.')
					}
					showSuccess(t('njordium_suitecrm', 'SuiteCRM Note created (Deck comment failed — see the panel above)'))
				}

				this.$emit('created', { noteId })
				if (this.postWarning === '') {
					this.$emit('close')
				}
			} catch (err) {
				const backendError = err?.response?.data?.error
				const msg = backendError
					? t('njordium_suitecrm', 'Could not create SuiteCRM Note: {msg}', { msg: backendError })
					: t('njordium_suitecrm', 'Could not create SuiteCRM Note')
				showError(msg)
			} finally {
				this.submitting = false
			}
		},

		composeDeckComment() {
			// SuiteCRM record URL isn't directly known to the frontend;
			// the reference-provider preview cards will render if the
			// user pastes a proper SuiteCRM URL. For the audit comment
			// on the Deck side we include the module + record id in
			// plain text so a human viewer knows what was linked.
			const label = this.targetRecord.module + ' ' + this.targetRecord.id
			if (this.extraNote.trim() !== '') {
				const params = { label, note: this.extraNote.trim() }
				return t('njordium_suitecrm', 'Linked to SuiteCRM {label} — {note}', params)
			}
			return t('njordium_suitecrm', 'Linked to SuiteCRM {label}', { label })
		},
	},
}
</script>

<style scoped lang="scss">
.link-deck-card-modal {
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

	&__parsed,
	&__error {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: -6px 0 0 0;
		font-size: 0.9em;
	}

	&__parsed {
		color: var(--color-success);
	}

	&__error {
		color: var(--color-warning);
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

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	code {
		font-family: monospace;
		padding: 1px 4px;
		background: var(--color-background-dark);
		border-radius: 3px;
	}
}
</style>
