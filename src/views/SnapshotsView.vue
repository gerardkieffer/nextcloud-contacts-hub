<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import IconBackupRestore from 'vue-material-design-icons/BackupRestore.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { t, n } from '@nextcloud/l10n'

import { api } from '../api.js'
import { store } from '../store.js'

/**
 * Snapshots of one Nextcloud address book, and restoring one.
 *
 * Snapshots were always taken -- before every sync run that writes -- but
 * until this screen the only way back was a curl command against the API,
 * and the files themselves are gzipped JSON the Contacts app cannot import.
 *
 * The restore panel's job is mostly to say what will happen next, because
 * a restore does not happen in a vacuum: the jobs using this address book
 * see it on their next run. A pull job puts back the endpoint's version of
 * everything the endpoint still has, and a push job sends the restored
 * address book on to its endpoint -- deletions included, under the mirror
 * policy. Switching them off first is offered, and on by default.
 */

const snapshotsFolderUrl = generateUrl('/apps/files/?dir=' + encodeURIComponent('/Contacts Hub/Snapshots'))

const bookOptions = computed(() => store.addressBooks.map((b) => ({
	id: b.id,
	label: b.readOnly
		? `${b.displayName} — ${t('contacthub', 'read-only')}`
		: b.displayName,
})))

// The first book a job uses is the one most likely wanted; any book will do
// when no job exists yet.
const initialBookId = store.jobs[0]?.address_book_id ?? store.addressBooks[0]?.id ?? null
const bookId = ref(initialBookId)

const selectedBook = computed({
	get: () => bookOptions.value.find((o) => o.id === bookId.value) ?? null,
	set: (option) => { bookId.value = option?.id ?? null },
})

const book = computed(() => store.addressBooks.find((b) => b.id === bookId.value) ?? null)
const jobsOnBook = computed(() => store.jobs.filter((j) => j.address_book_id === bookId.value))
const pullJobs = computed(() => jobsOnBook.value.filter((j) => j.direction === 'from_endpoint'))
const pushJobs = computed(() => jobsOnBook.value.filter((j) => j.direction === 'to_endpoint'))
const enabledJobs = computed(() => jobsOnBook.value.filter((j) => j.enabled))
const busyJobs = computed(() => jobsOnBook.value.filter((j) => j.open_run))

const snapshots = ref([])
const loading = ref(false)
const taking = ref(false)

const restoring = ref(null)
const panel = ref(null)
const mirror = ref(false)
const switchOff = ref(true)
const busy = ref(false)
const outcome = ref(null)

async function load() {
	snapshots.value = []
	restoring.value = null
	if (bookId.value === null) {
		return
	}
	loading.value = true
	try {
		snapshots.value = await api.backups(bookId.value)
	} catch (error) {
		showError(error.message)
	} finally {
		loading.value = false
	}
}

watch(bookId, () => {
	outcome.value = null
	load()
})
onMounted(load)

async function takeSnapshot() {
	taking.value = true
	try {
		snapshots.value = (await api.snapshot(bookId.value)).snapshots
		showSuccess(t('contacthub', 'Snapshot taken'))
	} catch (error) {
		showError(error.message)
	} finally {
		taking.value = false
	}
}

async function startRestore(snapshot) {
	restoring.value = snapshot
	mirror.value = false
	switchOff.value = enabledJobs.value.length > 0
	outcome.value = null
	// The panel sits below the list, which can be long.
	await nextTick()
	panel.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

async function confirmRestore() {
	busy.value = true
	try {
		const switchedOff = []
		if (switchOff.value) {
			// Before the restore, not after: a scheduled run starting in
			// between would act on the restored book straight away.
			for (const job of enabledJobs.value) {
				await api.updateJob(job.id, { enabled: false })
				switchedOff.push(job.name)
			}
		}
		const result = await api.restore(restoring.value.id, mirror.value)
		outcome.value = { ...result, switchedOff, when: restoring.value.created_at }
		restoring.value = null
		await Promise.all([load(), store.loadJobs()])
		showSuccess(t('contacthub', 'Snapshot restored'))
	} catch (error) {
		showError(error.message)
		// Some jobs may have been switched off before the failure.
		await store.loadJobs()
	} finally {
		busy.value = false
	}
}

/** Stored as UTC "Y-m-d H:i:s"; shown in the viewer's own time. */
function when(utc) {
	if (!utc) {
		return ''
	}
	return new Date(utc.replace(' ', 'T') + 'Z').toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function size(bytes) {
	if (bytes < 1024) {
		return t('contacthub', '{n} bytes', { n: bytes })
	}
	if (bytes < 1024 * 1024) {
		return t('contacthub', '{n} KB', { n: Math.round(bytes / 1024) })
	}
	return t('contacthub', '{n} MB', { n: (bytes / 1024 / 1024).toFixed(1) })
}

function why(snapshot) {
	switch (snapshot.reason) {
	case 'pre_sync': {
		const job = store.jobs.find((j) => j.id === snapshot.job_id)
		return job
			? t('contacthub', 'Before a run of “{job}”', { job: job.name })
			: t('contacthub', 'Before a sync run')
	}
	case 'pre_restore':
		return t('contacthub', 'Before a restore')
	case 'manual':
		return t('contacthub', 'Taken by hand')
	default:
		return snapshot.reason
	}
}

function contents(snapshot) {
	return n('contacthub', '%n contact', '%n contacts', snapshot.contact_count)
		+ (snapshot.group_count > 0 ? ', ' + n('contacthub', '%n group card', '%n group cards', snapshot.group_count) : '')
}

const names = (jobs) => jobs.map((j) => `“${j.name}”`).join(', ')
</script>

<template>
	<div class="ch-view">
		<div class="ch-view__header">
			<h2>{{ t('contacthub', 'Snapshots') }}</h2>
			<NcButton v-if="bookId !== null" :disabled="taking" @click="takeSnapshot">
				{{ t('contacthub', 'Take a snapshot now') }}
			</NcButton>
		</div>

		<p class="ch-muted">
			{{ t('contacthub', 'A snapshot of a Nextcloud address book is taken before every sync run that changes something in it, and kept for a while. Restoring one puts the address book back the way it was then.') }}
			<a :href="snapshotsFolderUrl" class="ch-link">{{ t('contacthub', 'The files are in your Contacts Hub folder.') }}</a>
		</p>

		<label class="ch-label">{{ t('contacthub', 'Nextcloud address book') }}</label>
		<NcSelect
			v-model="selectedBook"
			class="ch-snapshots__book"
			:options="bookOptions"
			:clearable="false"
			:input-label="t('contacthub', 'Nextcloud address book')"
			:label-outside="true" />

		<NcNoteCard v-if="outcome" type="success">
			<p>
				{{ t('contacthub', 'Restored the snapshot from {when}: {created} added back, {updated} reverted, {deleted} removed.', {
					when: when(outcome.when), created: outcome.created, updated: outcome.updated, deleted: outcome.deleted,
				}) }}
			</p>
			<p>{{ t('contacthub', 'The address book as it was just before this restore is the newest snapshot below, so this can be undone the same way.') }}</p>
			<p v-if="outcome.switchedOff.length">
				{{ t('contacthub', 'Switched off: {jobs}. Switch them back on from Sync jobs when you are happy with the result.', { jobs: outcome.switchedOff.join(', ') }) }}
			</p>
		</NcNoteCard>

		<div v-if="loading" class="ch-snapshots__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="bookId !== null && snapshots.length === 0"
			:name="t('contacthub', 'No snapshots of this address book')"
			:description="t('contacthub', 'One is taken automatically before the next sync run that changes it, or take one now.')">
			<template #icon>
				<IconBackupRestore />
			</template>
		</NcEmptyContent>

		<table v-else-if="snapshots.length" class="ch-table">
			<thead>
				<tr>
					<th>{{ t('contacthub', 'Taken') }}</th>
					<th>{{ t('contacthub', 'Why') }}</th>
					<th>{{ t('contacthub', 'Contents') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="snapshot in snapshots"
					:key="snapshot.id"
					:class="{ 'ch-snapshots__row--chosen': restoring?.id === snapshot.id }">
					<td>
						{{ when(snapshot.created_at) }}
						<div class="ch-muted ch-snapshots__small">
							{{ t('contacthub', 'kept until {when}', { when: when(snapshot.expires_at) }) }}
						</div>
					</td>
					<td>{{ why(snapshot) }}</td>
					<td>
						{{ contents(snapshot) }}
						<div class="ch-muted ch-snapshots__small">
							{{ size(snapshot.byte_size) }}
						</div>
					</td>
					<td class="ch-snapshots__action">
						<NcButton
							:disabled="busy || book?.readOnly || restoring?.id === snapshot.id"
							:title="book?.readOnly ? t('contacthub', 'This address book is shared with you read-only.') : ''"
							@click="startRestore(snapshot)">
							{{ t('contacthub', 'Restore…') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<section v-if="restoring" ref="panel" class="ch-snapshots__confirm">
			<h3>{{ t('contacthub', 'Restore “{book}” to {when}?', { book: book?.displayName, when: when(restoring.created_at) }) }}</h3>

			<NcCheckboxRadioSwitch
				:model-value="mirror ? 'exact' : 'keep'"
				value="keep"
				name="restore_mode"
				type="radio"
				@update:model-value="mirror = false">
				{{ t('contacthub', 'Put back what the snapshot has: deleted contacts come back and changed ones are reverted. Contacts added since are kept.') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				:model-value="mirror ? 'exact' : 'keep'"
				value="exact"
				name="restore_mode"
				type="radio"
				@update:model-value="mirror = true">
				{{ t('contacthub', 'Make it exactly as it was: the same, and contacts added since the snapshot are deleted.') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="pullJobs.length" type="warning">
				{{ n('contacthub',
					'%n job pulls into this address book ({jobs}). On its next run it puts back the endpoint’s version of every contact the endpoint still has — only what the endpoint no longer has stays as restored.',
					'%n jobs pull into this address book ({jobs}). On their next run they put back the endpoint’s version of every contact the endpoint still has — only what the endpoint no longer has stays as restored.',
					pullJobs.length, { jobs: names(pullJobs) }) }}
			</NcNoteCard>
			<NcNoteCard v-if="pushJobs.length" type="warning">
				{{ n('contacthub',
					'%n job pushes this address book to an endpoint ({jobs}). On its next run it sends the restored contacts there, and with “delete it from the destination too” it deletes there whatever the restore removed.',
					'%n jobs push this address book to an endpoint ({jobs}). On their next run they send the restored contacts there, and with “delete it from the destination too” they delete there whatever the restore removed.',
					pushJobs.length, { jobs: names(pushJobs) }) }}
			</NcNoteCard>
			<NcNoteCard v-if="busyJobs.length" type="warning">
				{{ t('contacthub', 'A sync run of {jobs} has not finished yet. Restoring now means it carries on against the restored address book.', { jobs: names(busyJobs) }) }}
			</NcNoteCard>

			<NcCheckboxRadioSwitch
				v-if="enabledJobs.length"
				:model-value="switchOff"
				@update:model-value="switchOff = $event">
				{{ n('contacthub',
					'Switch off the job using this address book first, so nothing syncs until you have checked the result',
					'Switch off the %n jobs using this address book first, so nothing syncs until you have checked the result',
					enabledJobs.length) }}
			</NcCheckboxRadioSwitch>

			<p class="ch-muted">
				{{ t('contacthub', 'The address book as it is now is saved as a snapshot first, so a restore can itself be undone.') }}
			</p>

			<div class="ch-actions">
				<NcButton type="primary" :disabled="busy" @click="confirmRestore">
					{{ busy ? t('contacthub', 'Restoring…') : t('contacthub', 'Restore') }}
				</NcButton>
				<NcButton :disabled="busy" @click="restoring = null">
					{{ t('contacthub', 'Cancel') }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<style scoped>
.ch-label {
	display: block;
	margin-block: 12px 4px;
	font-weight: 600;
}

.ch-snapshots__book {
	max-width: 420px;
	margin-block-end: 16px;
}

.ch-snapshots__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

.ch-snapshots__small {
	font-size: 0.9em;
}

.ch-snapshots__action {
	text-align: end;
}

.ch-snapshots__row--chosen {
	background-color: var(--color-primary-element-light);
}

.ch-snapshots__confirm {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-start: 16px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.ch-snapshots__confirm h3 {
	margin: 0;
}
</style>
