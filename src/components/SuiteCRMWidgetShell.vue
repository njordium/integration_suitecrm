<template>
	<div class="scw-widget">
		<div class="scw-toolbar">
			<NcActions :forceMenu="true">
				<NcActionButton @click="openSettings">
					<template #icon>
						<CogIcon :size="20" />
					</template>
					{{ t('integration_suitecrm', 'Widget settings') }}
				</NcActionButton>
				<NcActionButton @click="$emit('refresh')">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('integration_suitecrm', 'Refresh') }}
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="loading" class="scw-status">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="notConnected" class="scw-status">
			<span>{{ t('integration_suitecrm', 'No SuiteCRM account connected.') }}</span>
			<a class="button" :href="settingsUrl">
				{{ t('integration_suitecrm', 'Connect to SuiteCRM') }}
			</a>
		</div>
		<div v-else-if="error" class="scw-status scw-error">
			{{ error }}
		</div>
		<div v-else-if="!hasItems" class="scw-status">
			<CheckCircleOutlineIcon :size="40" class="scw-status__icon" />
			<span>{{ emptyText }}</span>
		</div>
		<template v-else>
			<slot />
			<a
				v-if="showMoreUrl"
				:href="showMoreUrl"
				target="_blank"
				rel="noopener"
				class="scw-more">
				{{ t('integration_suitecrm', 'Show all') }}
				<OpenInNewIcon :size="14" />
			</a>
		</template>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="scw-modal">
				<h3>{{ settingsTitle }}</h3>
				<section class="scw-modal__section">
					<h4>{{ t('integration_suitecrm', 'Refresh frequency') }}</h4>
					<RefreshIntervalPicker v-model="draftRefreshSeconds" />
				</section>
				<section v-if="showOnlyMineToggle" class="scw-modal__section">
					<h4>{{ t('integration_suitecrm', 'Show') }}</h4>
					<label class="scw-toggle">
						<input
							type="checkbox"
							:checked="draftOnlyMine"
							@change="draftOnlyMine = $event.target.checked">
						<span>{{ onlyMineLabel }}</span>
					</label>
				</section>
				<slot
					name="settings"
					:draft="draftExtras"
					:updateExtra="updateExtra" />
				<div class="scw-modal__actions">
					<NcButton @click="closeSettings">
						{{ t('integration_suitecrm', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="onSave">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="16" />
							<ContentSaveIcon v-else :size="16" />
						</template>
						{{ t('integration_suitecrm', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
/**
 * Shared widget shell for all nine SuiteCRM dashboard widgets. Renders
 * the toolbar (3-dot menu with Refresh + Widget settings), state
 * placeholders (loading / not-connected / error / empty), the caller's
 * item list via the default slot, and the settings modal.
 *
 * The Forgejo/Gitea sibling app inlines this shape per widget; we
 * chose to abstract it here because SuiteCRM has nine widgets that
 * share the same shell and duplicating the modal + toolbar nine times
 * would be 200+ lines of copy-paste. The visible UX matches Forgejo
 * exactly — same NcActions layout, same modal structure, same
 * RefreshIntervalPicker component.
 *
 * Callers pass:
 *   - `loading` / `notConnected` / `error` / `hasItems`: state booleans
 *   - `emptyText`: the widget-specific empty-state string
 *   - `showMoreUrl`: link to the equivalent SuiteCRM listing page
 *   - `settingsTitle`: modal heading (e.g. "SuiteCRM: Cases — settings")
 *   - `refreshSeconds`: current refresh cadence, seeded into the modal draft
 *   - `extras`: object of widget-specific pref keys to expose in the modal
 * and listen for:
 *   - `refresh`: user clicked the toolbar refresh button
 *   - `save`: user clicked the modal Save button; emitted with
 *     `{ refreshSeconds, extras }` — the caller PUTs /config and resets
 *     its own state.
 */
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import RefreshIntervalPicker from './RefreshIntervalPicker.vue'

export default {
	name: 'SuiteCRMWidgetShell',

	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcModal,
		CheckCircleOutlineIcon,
		CogIcon,
		ContentSaveIcon,
		OpenInNewIcon,
		RefreshIcon,
		RefreshIntervalPicker,
	},

	props: {
		loading: { type: Boolean, default: false },
		notConnected: { type: Boolean, default: false },
		error: { type: String, default: '' },
		hasItems: { type: Boolean, default: false },
		emptyText: { type: String, required: true },
		showMoreUrl: { type: String, default: '' },
		settingsTitle: { type: String, required: true },
		refreshSeconds: { type: Number, default: 300 },
		saving: { type: Boolean, default: false },
		// Widget-specific extra settings (e.g. calendar_show_tasks, pipeline_mode).
		// Rendered via the `settings` slot; snapshotted into the modal draft on
		// open so cancelling does not persist mid-edit values.
		extras: { type: Object, default: () => ({}) },
		// "Only records assigned to me" toggle. Opted into by widgets whose
		// backing endpoint accepts an `only_mine` filter (Contacts, Accounts,
		// Leads, Activities — the "recently added" widgets). Widgets that
		// already always filter to the current user (Cases, Tasks, Pipeline,
		// Calendar, Events) do not surface this toggle; adding a "show all"
		// inverse there is a separate feature.
		showOnlyMineToggle: { type: Boolean, default: false },
		onlyMine: { type: Boolean, default: false },
		onlyMineLabel: {
			type: String,
			default: () => t('integration_suitecrm', 'Only records assigned to me'),
		},
	},

	emits: ['refresh', 'save'],

	data() {
		return {
			showSettings: false,
			draftRefreshSeconds: 300,
			draftOnlyMine: false,
			draftExtras: {},
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
		}
	},

	methods: {
		openSettings() {
			this.draftRefreshSeconds = this.refreshSeconds
			this.draftOnlyMine = this.onlyMine
			// Deep-copy so the modal edits don't leak into the parent
			// widget's committed state until Save fires.
			this.draftExtras = JSON.parse(JSON.stringify(this.extras || {}))
			this.showSettings = true
		},

		closeSettings() {
			this.showSettings = false
		},

		updateExtra(key, value) {
			this.draftExtras = { ...this.draftExtras, [key]: value }
		},

		onSave() {
			this.$emit('save', {
				refreshSeconds: this.draftRefreshSeconds,
				onlyMine: this.draftOnlyMine,
				extras: this.draftExtras,
				close: () => { this.showSettings = false },
			})
		},
	},
}
</script>

<style scoped lang="scss">
.scw-widget {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
	font-size: 13px;
	/*
	 * No max-height / overflow on the widget itself — the internal
	 * .scw-list handles its own scrolling at max-height 400px (see
	 * css/dashboard.css). Widget grows with toolbar + list + more-link
	 * to a natural, bounded size.
	 */
}

.scw-toolbar {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	min-height: 32px;
	margin-top: -8px;
	margin-bottom: -4px;
}

.scw-status {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 24px 4px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.scw-status__icon {
	opacity: 0.5;
}

.scw-error { color: var(--color-error); }

.scw-more {
	display: flex;
	align-self: center;
	align-items: center;
	justify-content: center;
	gap: 4px;
	padding: 6px 12px;
	margin: 4px auto 0;
	width: fit-content;
	color: var(--color-primary-element);
	text-decoration: none;
	font-size: 12px;
	border-radius: var(--border-radius);
	/* Anchored at the bottom of the widget below the scrollable list. */
	flex-shrink: 0;

	&:hover {
		text-decoration: underline;
		background: var(--color-background-hover);
	}
}

.scw-modal {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
	width: min(480px, 90vw);

	h3 { margin: 0; }
	h4 { margin: 0 0 8px; font-size: 14px; }
	&__section { display: flex; flex-direction: column; gap: 8px; }
	&__actions { display: flex; justify-content: flex-end; gap: 8px; }
}

.scw-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	cursor: pointer;

	input[type="checkbox"] {
		margin: 0;
		flex-shrink: 0;
	}
}
</style>
