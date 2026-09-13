<script setup>
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import { api } from '../api.js'
import { store } from '../store.js'

const props = defineProps({ job: { type: Object, required: true } })
const emit = defineEmits(['saved', 'cancel'])

const form = ref({ ...props.job })
const errors = ref({})
const saving = ref(false)
const warnings = ref(props.job.warnings ?? [])
const isNew = computed(() => !props.job.id)

const directions = [
	{ id: 'to_endpoint', label: t('contacthub', 'Nextcloud to endpoint (push)') },
	{ id: 'from_endpoint', label: t('contacthub', 'Endpoint to Nextcloud (pull)') },
]

/**
 * NcSelect options as objects, not bare ids.
 *
 * Passing raw numbers with a `get-option-label` that looked them up did not
 * work: the list rendered the ids themselves. Objects carrying their own
 * `label` are vue-select's native shape, so nothing has to resolve anything
 * at render time.
 *
 * The id stays visible in parentheses because it is what every error message,
 * log line and API response uses, so seeing the two together is what lets
 * someone connect a friendly name to those.
 */
const bookOptions = computed(() => store.addressBooks.map((b) => ({
	id: b.id,
	label: `${b.displayName} (#${b.id})`,
})))

const endpointOptions = computed(() => store.endpoints.map((e) => ({
	id: e.id,
	label: `${e.name} — ${e.preset_label} (#${e.id})`,
})))

/**
 * NcSelect wants the whole option object both ways, while the form and the
 * API deal in ids. These bridge the two without the form ever holding
 * anything but an id, which is what save() sends.
 */
function selection(options, id) {
	return options.find((o) => o.id === Number(id)) ?? null
}

const selectedBook = computed({
	get: () => selection(bookOptions.value, form.value.address_book_id),
	set: (option) => { form.value.address_book_id = option?.id ?? null },
})

const selectedEndpoint = computed({
	get: () => selection(endpointOptions.value, form.value.endpoint_id),
	set: (option) => { form.value.endpoint_id = option?.id ?? null },
})

async function save() {
	saving.value = true
	errors.value = {}
	const payload = {
		name: form.value.name,
		addressBookId: Number(form.value.address_book_id),
		endpointId: Number(form.value.endpoint_id),
		direction: form.value.direction,
		deletionPolicy: form.value.deletion_policy,
		archiveGroupName: form.value.archive_group_name,
		includePhotos: form.value.include_photos,
		intervalSeconds: Number(form.value.interval_seconds),
		enabled: form.value.enabled,
	}

	try {
		const saved = isNew.value
			? await api.createJob(payload)
			: await api.updateJob(props.job.id, payload)
		warnings.value = saved.warnings ?? []
		showSuccess(t('contacthub', 'Sync job saved'))
		emit('saved')
	} catch (error) {
		if (error.isValidation) {
			errors.value = error.errors
		} else {
			showError(error.message)
		}
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div class="ch-form">
		<h3>{{ isNew ? t('contacthub', 'New sync job') : t('contacthub', 'Edit sync job') }}</h3>

		<NcNoteCard
			v-for="(w, i) in warnings"
			:key="i"
			:type="w.severity === 'warning' ? 'warning' : 'info'">
			{{ w.message }}
		</NcNoteCard>

		<NcTextField
			v-model="form.name"
			:label="t('contacthub', 'Name')"
			:error="!!errors.name"
			:helper-text="errors.name" />

		<label class="ch-label">{{ t('contacthub', 'Nextcloud address book') }}</label>
		<NcSelect
			v-model="selectedBook"
			:options="bookOptions"
			:clearable="false"
			label="label" />
		<p v-if="errors.address_book_id" class="ch-error">{{ errors.address_book_id }}</p>

		<label class="ch-label">{{ t('contacthub', 'Endpoint') }}</label>
		<NcSelect
			v-model="selectedEndpoint"
			:options="endpointOptions"
			:clearable="false"
			label="label" />
		<p v-if="errors.endpoint_id" class="ch-error">{{ errors.endpoint_id }}</p>

		<label class="ch-label">{{ t('contacthub', 'Direction') }}</label>
		<NcCheckboxRadioSwitch
			v-for="d in directions"
			:key="d.id"
			:model-value="form.direction"
			:value="d.id"
			name="direction"
			type="radio"
			@update:model-value="form.direction = $event">
			{{ d.label }}
		</NcCheckboxRadioSwitch>

		<label class="ch-label">{{ t('contacthub', 'When a contact is deleted on one side') }}</label>
		<NcCheckboxRadioSwitch
			:model-value="form.deletion_policy"
			value="mirror"
			name="deletion_policy"
			type="radio"
			@update:model-value="form.deletion_policy = $event">
			{{ t('contacthub', 'Delete it on the other side too') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			:model-value="form.deletion_policy"
			value="archive"
			name="deletion_policy"
			type="radio"
			@update:model-value="form.deletion_policy = $event">
			{{ t('contacthub', 'Keep it, tagged as archived') }}
		</NcCheckboxRadioSwitch>

		<NcTextField
			v-if="form.deletion_policy === 'archive'"
			v-model="form.archive_group_name"
			:label="t('contacthub', 'Archive group name')"
			:error="!!errors.archive_group_name"
			:helper-text="errors.archive_group_name" />

		<NcCheckboxRadioSwitch
			:model-value="form.include_photos"
			@update:model-value="form.include_photos = $event">
			{{ t('contacthub', 'Sync contact photos') }}
		</NcCheckboxRadioSwitch>

		<NcTextField
			v-model="form.interval_seconds"
			type="number"
			:label="t('contacthub', 'Run automatically every (seconds)')"
			:error="!!errors.interval_seconds"
			:helper-text="errors.interval_seconds || t('contacthub', 'Scheduled runs need Nextcloud background jobs to be working.')" />

		<NcCheckboxRadioSwitch
			:model-value="form.enabled"
			@update:model-value="form.enabled = $event">
			{{ t('contacthub', 'Run this job automatically') }}
		</NcCheckboxRadioSwitch>

		<div class="ch-form__actions">
			<NcButton type="primary" :disabled="saving" @click="save">
				{{ t('contacthub', 'Save') }}
			</NcButton>
			<NcButton @click="emit('cancel')">
				{{ t('contacthub', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.ch-label {
	display: block;
	margin-block: 12px 4px;
	font-weight: 600;
}
.ch-error {
	color: var(--color-error);
}
.ch-form__actions {
	display: flex;
	gap: 8px;
	margin-block-start: 16px;
}
</style>
