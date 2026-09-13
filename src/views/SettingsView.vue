<script setup>
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import { api } from '../api.js'
import { store } from '../store.js'

const FOLDER = 'Contacts Hub'

const includePasswords = ref(false)
const exporting = ref(false)
const exported = ref(null)

const files = ref([])
const source = ref(null)
const inspected = ref(null)
const passwords = ref({})
const busy = ref(false)
const outcome = ref(null)

async function loadFiles() {
	try {
		files.value = (await api.settingsFiles()).files ?? []
	} catch (error) {
		showError(error.message)
	}
}

onMounted(loadFiles)

async function runExport() {
	exporting.value = true
	exported.value = null
	try {
		exported.value = await api.exportSettings(includePasswords.value)
		showSuccess(t('contacthub', 'Settings exported'))
		await loadFiles()
	} catch (error) {
		showError(error.message)
	} finally {
		exporting.value = false
	}
}

/**
 * Read a file the user picked in the browser and inspect it.
 *
 * The content is posted as text rather than uploaded: the file is a few
 * kilobytes of JSON, and this avoids a multipart route whose only job would
 * be to hand the same string to the same service.
 */
async function pickLocal(event) {
	const file = event.target.files?.[0]
	if (!file) {
		return
	}
	await inspect({ content: await file.text() })
	event.target.value = ''
}

async function inspect(next) {
	busy.value = true
	inspected.value = null
	outcome.value = null
	passwords.value = {}
	try {
		source.value = next
		inspected.value = await api.inspectSettings(next)
	} catch (error) {
		source.value = null
		showError(error.message)
	} finally {
		busy.value = false
	}
}

async function runImport() {
	busy.value = true
	try {
		outcome.value = await api.importSettings(source.value, passwords.value)
		inspected.value = null
		await store.loadAll()
		showSuccess(t('contacthub', 'Import finished'))
	} catch (error) {
		showError(error.message)
	} finally {
		busy.value = false
	}
}

const needsPassword = (endpoint) => !endpoint.exists && !endpoint.has_password
const formatSize = (bytes) => `${Math.max(1, Math.round(bytes / 1024))} kB`
</script>

<template>
	<div class="ch-view">
		<div class="ch-view__header">
			<h2>{{ t('contacthub', 'Export and import') }}</h2>
		</div>

		<p class="ch-muted">
			{{ t('contacthub', 'Endpoints and sync jobs, saved to “{folder}” in your files where you can download or share them. This does not include your contacts — those are in the snapshots taken before each sync.', { folder: FOLDER }) }}
		</p>

		<h3>{{ t('contacthub', 'Export') }}</h3>

		<NcCheckboxRadioSwitch :model-value="includePasswords" @update:model-value="includePasswords = $event">
			{{ t('contacthub', 'Include endpoint passwords') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard v-if="includePasswords" type="warning">
			{{ t('contacthub', 'The file will contain your CardDAV passwords in plain text. Anyone who gets hold of it can use them. Leave this off unless you are moving to another server, and delete the file once you have.') }}
		</NcNoteCard>
		<p v-else class="ch-muted">
			{{ t('contacthub', 'Without passwords the file is safe to keep anywhere, and importing it asks you to type each password once.') }}
		</p>

		<div class="ch-form__actions">
			<NcButton type="primary" :disabled="exporting" @click="runExport">
				<template v-if="exporting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('contacthub', 'Export settings') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="exported" type="success">
			{{ t('contacthub', 'Saved {endpoints} endpoints and {jobs} jobs to {path}', { endpoints: exported.endpoints, jobs: exported.jobs, path: exported.path }) }}
		</NcNoteCard>

		<h3>{{ t('contacthub', 'Import') }}</h3>

		<p class="ch-muted">
			{{ t('contacthub', 'Nothing is ever overwritten. Anything whose name already exists is skipped, and imported jobs arrive switched off so you can check them before they run.') }}
		</p>

		<label class="ch-label">{{ t('contacthub', 'From a file on this computer') }}</label>
		<input type="file" accept=".json,application/json" @change="pickLocal">

		<template v-if="files.length">
			<label class="ch-label">{{ t('contacthub', 'From “{folder}” in your files', { folder: FOLDER }) }}</label>
			<ul class="ch-files">
				<li v-for="file in files" :key="file.path">
					<NcButton type="tertiary" :disabled="busy" @click="inspect({ path: file.path })">
						{{ file.name }}
					</NcButton>
					<span class="ch-muted">{{ formatSize(file.size) }}</span>
				</li>
			</ul>
		</template>

		<div v-if="busy" class="ch-view__busy">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-if="inspected" class="ch-preview">
			<h4>{{ t('contacthub', 'This file contains') }}</h4>

			<table class="ch-table">
				<thead>
					<tr>
						<th>{{ t('contacthub', 'Endpoint') }}</th>
						<th>{{ t('contacthub', 'Server') }}</th>
						<th>{{ t('contacthub', 'Password') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="e in inspected.endpoints" :key="e.name">
						<td>
							<strong>{{ e.name }}</strong>
							<div v-if="e.exists" class="ch-muted">{{ t('contacthub', 'Already here — will be skipped') }}</div>
						</td>
						<td class="ch-muted">{{ e.username }} · {{ e.base_url }}</td>
						<td>
							<span v-if="e.exists" class="ch-muted">—</span>
							<span v-else-if="e.has_password">{{ t('contacthub', 'In the file') }}</span>
							<NcTextField
								v-else
								:model-value="passwords[e.name] ?? ''"
								type="password"
								:label="t('contacthub', 'Password')"
								@update:model-value="passwords[e.name] = $event" />
						</td>
					</tr>
				</tbody>
			</table>

			<table v-if="inspected.jobs.length" class="ch-table">
				<thead>
					<tr>
						<th>{{ t('contacthub', 'Sync job') }}</th>
						<th>{{ t('contacthub', 'Address book') }}</th>
						<th>{{ t('contacthub', 'Endpoint') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="j in inspected.jobs" :key="j.name">
						<td>
							<strong>{{ j.name }}</strong>
							<div v-if="j.exists" class="ch-muted">{{ t('contacthub', 'Already here — will be skipped') }}</div>
						</td>
						<td>
							{{ j.address_book_name }}
							<span v-if="!j.address_book_found" class="ch-warn">
								{{ t('contacthub', '(not found here)') }}
							</span>
						</td>
						<td>{{ j.endpoint_name }}</td>
					</tr>
				</tbody>
			</table>

			<NcNoteCard v-if="inspected.endpoints.some(needsPassword)" type="info">
				{{ t('contacthub', 'Each password is checked against its server before the endpoint is created, so a wrong one is refused here rather than failing on the first sync.') }}
			</NcNoteCard>

			<div class="ch-form__actions">
				<NcButton type="primary" :disabled="busy" @click="runImport">
					{{ t('contacthub', 'Import these settings') }}
				</NcButton>
				<NcButton @click="inspected = null">
					{{ t('contacthub', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<div v-if="outcome" class="ch-outcome">
			<NcNoteCard v-if="outcome.created.endpoints.length || outcome.created.jobs.length" type="success">
				{{ t('contacthub', 'Created {endpoints} endpoints and {jobs} jobs.', { endpoints: outcome.created.endpoints.length, jobs: outcome.created.jobs.length }) }}
				<span v-if="outcome.jobs_are_disabled">
					{{ t('contacthub', 'The jobs are switched off — turn each one on once you have checked it.') }}
				</span>
			</NcNoteCard>

			<NcNoteCard
				v-for="s in [...outcome.skipped.endpoints, ...outcome.skipped.jobs]"
				:key="'s' + s.name"
				type="info">
				{{ s.name }} — {{ s.reason }}
			</NcNoteCard>

			<NcNoteCard
				v-for="f in [...outcome.failed.endpoints, ...outcome.failed.jobs]"
				:key="'f' + f.name"
				type="error">
				{{ f.name }} — {{ f.reason }}
			</NcNoteCard>
		</div>
	</div>
</template>

<style scoped>
h3 {
	margin-block: 24px 8px;
}
.ch-label {
	display: block;
	margin-block: 12px 4px;
	font-weight: 600;
}
.ch-form__actions {
	display: flex;
	gap: 8px;
	margin-block: 16px;
}
.ch-files {
	list-style: none;
	padding: 0;
}
.ch-files li {
	display: flex;
	align-items: center;
	gap: 8px;
}
.ch-preview {
	margin-block-start: 24px;
	padding-block-start: 16px;
	border-block-start: 1px solid var(--color-border);
}
.ch-warn {
	color: var(--color-warning-text, var(--color-warning));
}
.ch-view__busy {
	display: flex;
	justify-content: center;
	padding: 24px;
}
</style>
