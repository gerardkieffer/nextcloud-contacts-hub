<script setup>
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconServer from 'vue-material-design-icons/ServerNetwork.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import EndpointForm from '../components/EndpointForm.vue'
import { api } from '../api.js'
import { store } from '../store.js'

const editing = ref(null)

function startCreate() {
	editing.value = { id: null, name: '', preset: 'generic', base_url: '', username: '', group_strategy: 'passthrough' }
}

async function remove(endpoint) {
	if (!window.confirm(t('contacthub', 'Delete the endpoint “{name}”?', { name: endpoint.name }))) {
		return
	}
	try {
		await api.deleteEndpoint(endpoint.id)
		await store.loadEndpoints()
		showSuccess(t('contacthub', 'Endpoint deleted'))
	} catch (error) {
		// The commonest failure here is "still used by a sync job", which is
		// guidance rather than a fault, so it goes to the user verbatim.
		showError(error.message)
	}
}

async function saved() {
	editing.value = null
	await store.loadEndpoints()
}
</script>

<template>
	<div class="ch-view">
		<div class="ch-view__header">
			<h2>{{ t('contacthub', 'Endpoints') }}</h2>
			<NcButton v-if="!editing" type="primary" @click="startCreate">
				<template #icon>
					<IconPlus :size="20" />
				</template>
				{{ t('contacthub', 'Add endpoint') }}
			</NcButton>
		</div>

		<EndpointForm
			v-if="editing"
			:endpoint="editing"
			@saved="saved"
			@cancel="editing = null" />

		<NcEmptyContent
			v-else-if="store.endpoints.length === 0"
			:name="t('contacthub', 'No endpoints yet')"
			:description="t('contacthub', 'Add the CardDAV service you want to sync with — iCloud, Infomaniak, Mailo, or any other server.')">
			<template #icon>
				<IconServer />
			</template>
		</NcEmptyContent>

		<table v-else class="ch-table">
			<thead>
				<tr>
					<th>{{ t('contacthub', 'Name') }}</th>
					<th>{{ t('contacthub', 'Service') }}</th>
					<th>{{ t('contacthub', 'Collection') }}</th>
					<th>{{ t('contacthub', 'Groups') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="endpoint in store.endpoints" :key="endpoint.id">
					<td>
						<strong>{{ endpoint.name }}</strong>
						<div class="ch-muted">{{ endpoint.username }}</div>
					</td>
					<td>{{ endpoint.preset_label }}</td>
					<td>
						<span v-if="endpoint.collection_href">{{ endpoint.collection_name || t('contacthub', 'Selected') }}</span>
						<span v-else class="ch-warn">{{ t('contacthub', 'Not tested yet') }}</span>
					</td>
					<td>{{ endpoint.group_strategy }}</td>
					<td class="ch-actions">
						<NcButton @click="editing = { ...endpoint }">
							{{ t('contacthub', 'Edit') }}
						</NcButton>
						<NcButton type="tertiary" @click="remove(endpoint)">
							{{ t('contacthub', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style scoped>
.ch-warn {
	color: var(--color-warning-text, var(--color-warning));
}
</style>
