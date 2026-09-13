<script setup>
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import IconCheck from 'vue-material-design-icons/CheckCircleOutline.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import ContactDiff from '../components/ContactDiff.vue'
import { api } from '../api.js'
import { store } from '../store.js'

const resolving = ref(null)
const batchResolving = ref(false)

// Every conflict this app raises is a duplicate match. is_resolvable ===
// false means the server could not tell which side the new contact came
// from, so it offers no choices for that conflict and the batch would only
// report it as an error. Counting it here would have put a number in the
// confirmation dialog that the batch could not deliver: "apply to all 7"
// followed by "5 resolved, 2 failed".
const duplicateConflictCount = computed(
	() => store.conflicts.filter((c) => c.is_resolvable !== false).length,
)

// Every choice on this screen says plainly what it does except "archive",
// which is the one that sounds reassuring without saying where the copy
// goes -- and it is also the only one that puts something somewhere the
// user has to know about to ever find it again. Said once, at the top,
// and repeated as a tooltip on each archive button: a line under every
// conflict card would be the same sentence twenty times.
const archiveHint = t('contacthub', 'Archiving saves the copy that is about to be replaced as a .vcf file in your Files, under “Contacts Hub → Archived contacts”. It is never synced anywhere, and it never overwrites an archive already there.')

/**
 * Resolutions are named for roles ('hub', 'endpoint'), never for the
 * Planner's A/B sides, and this component never decides which role a button
 * means -- the server sends the value with each conflict.
 *
 * That is not fastidiousness. Which role means "merge" and which means
 * "keep both" depends on the side the new contact arrived from, so a
 * hardcoded literal is right in one direction and destructive in the other.
 *
 * Also say which column holds the newly-arrived contact.
 */
function sideLabel(conflict, role, base) {
	if (conflict.new_contact_role === role) {
		return `${base} — ${t('contacthub', 'new contact')}`
	}
	return `${base} — ${t('contacthub', 'existing contact')}`
}

async function resolve(conflict, resolution) {
	resolving.value = conflict.id
	try {
		await api.resolveConflict(conflict.id, resolution)
		await store.loadConflicts()
		showSuccess(t('contacthub', 'Conflict resolved'))
	} catch (error) {
		showError(error.message)
	} finally {
		resolving.value = null
	}
}

async function resolveBatch(choice, label) {
	const count = duplicateConflictCount.value
	if (!window.confirm(t('contacthub', 'Apply “{label}” to all {count} remaining duplicate matches? This cannot be undone as a whole — only conflict by conflict.', { label, count }))) {
		return
	}
	batchResolving.value = true
	try {
		const result = await api.resolveBatchConflicts(choice)
		await store.loadConflicts()
		if (result.errors.length > 0) {
			showError(t('contacthub', '{resolved} resolved, {failed} failed: {errors}', {
				resolved: result.resolved,
				failed: result.errors.length,
				errors: result.errors.join('; '),
			}))
		} else {
			showSuccess(t('contacthub', '{resolved} conflict(s) resolved', { resolved: result.resolved }))
		}
	} catch (error) {
		showError(error.message)
	} finally {
		batchResolving.value = false
	}
}
</script>

<template>
	<div class="ch-view">
		<div class="ch-view__header">
			<h2>{{ t('contacthub', 'Conflicts') }}</h2>
		</div>

		<p v-if="store.conflicts.length > 0" class="ch-muted ch-archive-hint">
			{{ archiveHint }}
		</p>

		<NcEmptyContent
			v-if="store.conflicts.length === 0"
			:name="t('contacthub', 'Nothing needs your attention')"
			:description="t('contacthub', 'Conflicts show up here when a new contact looks like one that already exists.')">
			<template #icon>
				<IconCheck />
			</template>
		</NcEmptyContent>

		<div v-if="duplicateConflictCount > 0" class="ch-batch">
			<h3>{{ t('contacthub', 'Apply to all remaining duplicate matches') }}</h3>
			<p class="ch-muted">
				{{ t('contacthub', 'Applies to the {count} resolvable conflicts below, one choice for all of them.', { count: duplicateConflictCount }) }}
			</p>
			<div class="ch-conflict__actions">
				<NcButton
					type="primary"
					:disabled="batchResolving"
					@click="resolveBatch('merge', t('contacthub', 'Same person — merge into one'))">
					{{ t('contacthub', 'Same person — merge into one') }}
				</NcButton>
				<NcButton
					:disabled="batchResolving"
					@click="resolveBatch('keep_both', t('contacthub', 'Different people — keep both'))">
					{{ t('contacthub', 'Different people — keep both') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:title="archiveHint"
					:disabled="batchResolving"
					@click="resolveBatch('archive', t('contacthub', 'Archive & replace'))">
					{{ t('contacthub', 'Archive & replace') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:disabled="batchResolving"
					@click="resolveBatch('cancel', t('contacthub', 'Cancel'))">
					{{ t('contacthub', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<div v-for="conflict in store.conflicts" :key="conflict.id" class="ch-conflict">
			<div class="ch-conflict__head">
				<h3>{{ conflict.hub?.name || conflict.endpoint?.name || conflict.uid }}</h3>
				<span class="ch-muted">
					{{ conflict.job_name }} · {{ conflict.detected_at }}
				</span>
			</div>

			<NcNoteCard type="warning">
				{{ t('contacthub', 'These two look like the same person under different IDs. Nothing has been changed — tell me which they are.') }}
			</NcNoteCard>

			<ContactDiff
				:left="conflict.hub"
				:right="conflict.endpoint"
				:left-label="sideLabel(conflict, 'hub', t('contacthub', 'Nextcloud'))"
				:right-label="sideLabel(conflict, 'endpoint', conflict.endpoint_name || t('contacthub', 'Endpoint'))" />

			<!--
				Every resolution below comes from the server. Which role means
				"merge" and which means "keep both" flips with the side a
				duplicate arrived from, so hardcoding 'hub'/'endpoint' here
				silently did the opposite of the label in one of the two
				directions.
			-->
			<NcNoteCard v-if="conflict.is_resolvable === false" type="error">
				{{ t('contacthub', 'This conflict does not record which side the new contact came from, so the choices below cannot be offered for it. The two copies are shown above; resolve it in the Contacts app instead.') }}
			</NcNoteCard>

			<div v-else class="ch-conflict__actions">
				<NcButton
					type="primary"
					:disabled="resolving === conflict.id"
					@click="resolve(conflict, conflict.merge_resolution)">
					{{ t('contacthub', 'Same person — merge into one') }}
				</NcButton>
				<NcButton
					:disabled="resolving === conflict.id"
					@click="resolve(conflict, conflict.keep_both_resolution)">
					{{ t('contacthub', 'Different people — keep both') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:title="archiveHint"
					:disabled="resolving === conflict.id"
					@click="resolve(conflict, conflict.archive_resolution)">
					{{ t('contacthub', 'Archive & replace') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:disabled="resolving === conflict.id"
					@click="resolve(conflict, conflict.cancel_resolution)">
					{{ t('contacthub', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style scoped>
.ch-archive-hint {
	max-width: 70ch;
	margin-block: 0 16px;
}
.ch-batch {
	margin-block-end: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}
.ch-batch h3 {
	margin: 0;
}
.ch-conflict {
	margin-block-end: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}
.ch-conflict__head {
	display: flex;
	align-items: baseline;
	gap: 12px;
	flex-wrap: wrap;
}
.ch-conflict__head h3 {
	margin: 0;
}
.ch-conflict__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-start: 12px;
}
</style>
