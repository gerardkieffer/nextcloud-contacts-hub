<script setup>
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconSync from 'vue-material-design-icons/Sync.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import JobForm from '../components/JobForm.vue'
import RunPanel from '../components/RunPanel.vue'
import { api } from '../api.js'
import { store } from '../store.js'

const editing = ref(null)
const running = ref(null)

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

			<RunPanel v-if="running" :job="running" @finished="refresh" />
		</template>
	</div>
</template>
