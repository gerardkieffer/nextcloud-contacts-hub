<script setup>
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconSync from 'vue-material-design-icons/Sync.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import JobForm from '../components/JobForm.vue'
import RunPanel from '../components/RunPanel.vue'
import { api } from '../api.js'
import { store } from '../store.js'

const editing = ref(null)
const running = ref(null)
const notifications = ref(null)
const notificationSettingsUrl = generateUrl('/settings/user/contacthub')

onMounted(async () => {
	try {
		notifications.value = await api.notifications()
	} catch {
		// Best-effort: the warnings are a convenience, not a critical read.
		// A failed check should not itself look like a failed sync job.
	}
})

// Notifications are on but there is nowhere to send them: the default
// "profile" mode with no address on the profile, typically.
const notificationsHaveNoRecipient = () =>
	notifications.value !== null
	&& notifications.value.mode !== 'off'
	&& notifications.value.recipient === null

// Only worth saying when somebody is actually expecting mail.
const mailLooksBroken = () =>
	notifications.value !== null
	&& notifications.value.recipient !== null
	&& !notifications.value.mail.likely_configured

function startCreate() {
	editing.value = {
		id: null,
		name: '',
		address_book_id: store.addressBooks[0]?.id ?? 0,
		endpoint_id: store.endpoints[0]?.id ?? 0,
		direction: 'to_endpoint',
		deletion_policy: 'mirror',
		archive_group_name: 'Deleted',
		include_photos: true,
		interval_seconds: 1800,
		enabled: true,
	}
}

async function remove(job) {
	if (!window.confirm(t('contacthub', 'Delete the sync job “{name}”? Contacts already synced stay where they are.', { name: job.name }))) {
		return
	}
	try {
		await api.deleteJob(job.id)
		await store.loadJobs()
		showSuccess(t('contacthub', 'Sync job deleted'))
	} catch (error) {
		showError(error.message)
	}
}

async function refresh() {
	editing.value = null
	await store.loadJobs()
	await store.loadConflicts()
}

const canCreate = () => store.endpoints.length > 0 && store.addressBooks.length > 0

/**
 * Reopen a run the user walked away from.
 *
 * Switching to another tab unmounts the run panel, and `running` is local to
 * this view, so coming back showed nothing whatever state the run was in. A
 * run that paused on its time budget is still very much in progress -- it
 * just needs someone to ask for the next segment -- so being shown an idle
 * screen reads as "the sync stopped", which is what was reported.
 *
 * Only real runs qualify: the listing's open_run comes from the same filter
 * findOpenRun() uses, which excludes previews.
 */
onMounted(() => {
	if (running.value) {
		return
	}
	running.value = store.jobs.find((job) => job.open_run) ?? null
})
</script>

<template>
	<div class="ch-view">
		<div class="ch-view__header">
			<h2>{{ t('contacthub', 'Sync jobs') }}</h2>
			<NcButton v-if="!editing && canCreate()" type="primary" @click="startCreate">
				<template #icon>
					<IconPlus :size="20" />
				</template>
				{{ t('contacthub', 'Add sync job') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="store.jobs.length && notificationsHaveNoRecipient()" type="info">
			{{ t('contacthub', 'When a scheduled sync finds conflicts, the job pauses until you resolve them. You would normally get an email, but there is no email address to send it to.') }}
			<a :href="notificationSettingsUrl" class="ch-link">{{ t('contacthub', 'Set up conflict notifications') }}</a>
		</NcNoteCard>
		<NcNoteCard v-else-if="store.jobs.length && mailLooksBroken()" type="warning">
			{{ t('contacthub', "Nextcloud's outgoing email doesn't appear to be configured or working — conflict-pause notifications may not reach you. Check Administration settings → Basic settings → Email server.") }}
		</NcNoteCard>

		<JobForm v-if="editing" :job="editing" @saved="refresh" @cancel="editing = null" />

		<NcEmptyContent
			v-else-if="store.endpoints.length === 0"
			:name="t('contacthub', 'Add an endpoint first')"
			:description="t('contacthub', 'A sync job connects one Nextcloud address book to one external service, so there has to be a service to connect to.')">
			<template #icon>
				<IconSync />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="store.jobs.length === 0"
			:name="t('contacthub', 'No sync jobs yet')"
			:description="t('contacthub', 'Create one to start syncing an address book with an external service.')">
			<template #icon>
				<IconSync />
			</template>
		</NcEmptyContent>

		<template v-else>
			<table class="ch-table">
				<thead>
					<tr>
						<th>{{ t('contacthub', 'Name') }}</th>
						<th>{{ t('contacthub', 'Address book') }}</th>
						<th>{{ t('contacthub', 'Direction') }}</th>
						<th>{{ t('contacthub', 'Endpoint') }}</th>
						<th>{{ t('contacthub', 'Last run') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="job in store.jobs" :key="job.id">
						<td>
							<strong>{{ job.name }}</strong>
							<span v-if="!job.enabled" class="ch-muted"> — {{ t('contacthub', 'disabled') }}</span>
							<a
								v-if="job.conflict_paused"
								href="#conflicts"
								class="ch-muted"
								:title="job.conflict_paused_at ? t('contacthub', 'Paused since {when}', { when: job.conflict_paused_at }) : ''">
								— {{ t('contacthub', 'paused (conflicts)') }}
							</a>
						</td>
						<td>{{ job.address_book_name }}</td>
						<td>{{ job.direction_label }}</td>
						<td>{{ job.endpoint_name }}</td>
						<td>{{ job.last_run_at || t('contacthub', 'never') }}</td>
						<td class="ch-actions">
							<NcButton @click="running = running?.id === job.id ? null : job">
								{{ t('contacthub', 'Sync') }}
							</NcButton>
							<NcButton @click="editing = { ...job }">
								{{ t('contacthub', 'Edit') }}
							</NcButton>
							<NcButton type="tertiary" @click="remove(job)">
								{{ t('contacthub', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Keyed by job: without it Vue reuses the one panel when another
			     job's Sync is clicked, and the previous job's result, warnings
			     and failure stay on screen under the new job's heading. -->
			<RunPanel v-if="running" :key="running.id" :job="running" @finished="refresh" />
		</template>
	</div>
</template>
