<template>
	<NcDialog
		v-if="open"
		:name="t('njordium_suitecrm', 'Log Talk conversation to SuiteCRM')"
		:noClose="submitting"
		size="normal"
		@closing="$emit('close')">
		<div class="talk-to-note-modal">
			<NcNoteCard v-if="loadError" type="warning">
				{{ loadError }}
			</NcNoteCard>

			<label class="talk-to-note-modal__label">
				{{ t('njordium_suitecrm', 'Talk conversation') }}
				<NcSelect
					v-model="selectedConversation"
					:options="conversationOptions"
					:reduce="option => option.value"
					:loading="loadingConversations"
					:disabled="submitting"
					:placeholder="t('njordium_suitecrm', 'Pick a conversation …')" />
			</label>

			<SuiteCRMRecordPicker
				v-model="selectedRecord"
				:label="t('njordium_suitecrm', 'Attach Note to (SuiteCRM record URL)')"
				:disabled="submitting" />

			<label class="talk-to-note-modal__label">
				{{ t('njordium_suitecrm', 'How many recent messages to include') }}
				<NcSelect
					v-model="messageLimit"
					:options="messageLimitOptions"
					:reduce="option => option.value"
					:clearable="false"
					:disabled="submitting" />
			</label>

			<p class="talk-to-note-modal__hint">
				{{ t('njordium_suitecrm', 'The selected messages become the body of a SuiteCRM Note attached to the record above. System messages (joins, leaves, permission changes) are excluded — only user messages are included.') }}
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
				{{ submitting ? t('njordium_suitecrm', 'Creating Note …') : t('njordium_suitecrm', 'Create Note') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * TalkToNoteModal — iter 70b.
 *
 * Two-step user flow:
 *   1. Pick a Nextcloud Talk conversation (from the user's list, via
 *      the standard Talk OCS API).
 *   2. Pick a SuiteCRM record to attach the resulting Note to (via
 *      SuiteCRMRecordPicker — URL paste, client-side parse).
 *
 * On submit, fetches the last N messages from the picked conversation,
 * formats them as a simple markdown transcript, and POSTs to the
 * generic /log-note endpoint from iter 70a. System messages (joins,
 * leaves, permission changes, poll voting notifications, etc.) are
 * filtered out — only real user messages land in SuiteCRM. Long
 * conversations get truncated at the picked limit rather than paged
 * because a single SuiteCRM Note has no scroll UI to speak of and a
 * 500-message dump would be unusable.
 *
 * Talk not installed / user has no conversations / user has no
 * permission: the conversation dropdown stays empty and the modal
 * surfaces the OCS error via NcNoteCard. Never blows up the caller.
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import SuiteCRMRecordPicker from './SuiteCRMRecordPicker.vue'

const MESSAGE_LIMIT_OPTIONS = [10, 25, 50, 100, 200]

export default {
	name: 'TalkToNoteModal',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
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
			conversations: [],
			selectedConversation: null,
			selectedRecord: null,
			messageLimit: 50,
			loadingConversations: false,
			loadError: '',
			submitting: false,
		}
	},

	computed: {
		conversationOptions() {
			return this.conversations.map((c) => ({
				label: c.displayName || c.name || c.token,
				value: c.token,
			}))
		},

		messageLimitOptions() {
			return MESSAGE_LIMIT_OPTIONS.map((n) => ({
				label: t('njordium_suitecrm', 'Last {n} messages', { n }),
				value: n,
			}))
		},

		canSubmit() {
			return this.selectedConversation !== null
				&& this.selectedRecord !== null
		},

		selectedConversationLabel() {
			const c = this.conversations.find((c) => c.token === this.selectedConversation)
			return c ? (c.displayName || c.name || c.token) : ''
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.selectedConversation = null
				this.selectedRecord = null
				this.messageLimit = 50
				this.loadError = ''
				this.loadConversations()
			}
		},
	},

	methods: {
		async loadConversations() {
			this.loadingConversations = true
			try {
				const url = generateOcsUrl('apps/spreed/api/v4/room')
				const config = { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
				const response = await axios.get(url, config)
				this.conversations = response?.data?.ocs?.data ?? []
			} catch (err) {
				const status = err?.response?.status
				if (status === 404) {
					// NC Talk not installed on this instance.
					this.loadError = t('njordium_suitecrm', 'Nextcloud Talk is not installed on this server, or is not available to your account.')
				} else {
					this.loadError = t('njordium_suitecrm', 'Could not load Talk conversations. Check the Nextcloud log for details.')
				}
				this.conversations = []
			} finally {
				this.loadingConversations = false
			}
		},

		async fetchMessages(token, limit) {
			const url = generateOcsUrl('apps/spreed/api/v1/chat/' + encodeURIComponent(token))
			const config = {
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				params: { lookIntoFuture: 0, limit },
			}
			const response = await axios.get(url, config)
			const messages = response?.data?.ocs?.data ?? []
			// Talk returns newest-first; reverse so the transcript reads
			// oldest-first (conventional chat log direction).
			return messages
				.filter((m) => m.messageType !== 'system' && m.messageType !== 'command')
				.reverse()
		},

		formatTranscript(conversationName, messages) {
			if (messages.length === 0) {
				return t('njordium_suitecrm', 'Talk conversation: {name}\n\n(No user messages found in the selected range.)', { name: conversationName })
			}
			const header = t('njordium_suitecrm', 'Talk conversation: {name}\nMessages: {count}', {
				name: conversationName,
				count: messages.length,
			})
			const body = messages.map((m) => {
				const when = new Date(m.timestamp * 1000).toISOString().replace('T', ' ').slice(0, 16)
				const who = m.actorDisplayName || m.actorId || t('njordium_suitecrm', 'Unknown speaker')
				return '**' + who + '** — ' + when + '\n> ' + (m.message || '').replace(/\n/g, '\n> ')
			}).join('\n\n')
			return header + '\n\n' + body
		},

		async submit() {
			if (!this.canSubmit || this.submitting) {
				return
			}
			this.submitting = true
			try {
				const messages = await this.fetchMessages(this.selectedConversation, this.messageLimit)
				const description = this.formatTranscript(this.selectedConversationLabel, messages)
				const name = t('njordium_suitecrm', 'Talk conversation: {name}', { name: this.selectedConversationLabel })

				const url = generateUrl('/apps/njordium_suitecrm/log-note')
				const payload = {
					targetModule: this.selectedRecord.module,
					targetId: this.selectedRecord.id,
					name,
					description,
				}
				const response = await axios.post(url, payload)
				const recordId = response?.data?.data?.id ?? null
				showSuccess(t('njordium_suitecrm', 'Note created in SuiteCRM'))
				this.$emit('created', { id: recordId })
				this.$emit('close')
			} catch (err) {
				const backendError = err?.response?.data?.error
				const msg = backendError
					? t('njordium_suitecrm', 'Could not create Note: {msg}', { msg: backendError })
					: t('njordium_suitecrm', 'Could not create Note in SuiteCRM')
				showError(msg)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.talk-to-note-modal {
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

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}
}
</style>
