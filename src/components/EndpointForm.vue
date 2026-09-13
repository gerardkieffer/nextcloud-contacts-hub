<script setup>
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import CapabilityTest from './CapabilityTest.vue'
import { api } from '../api.js'
import { store } from '../store.js'

const props = defineProps({ endpoint: { type: Object, required: true } })
const emit = defineEmits(['saved', 'cancel'])

const form = ref({ ...props.endpoint, password: '' })
const errors = ref({})
const saving = ref(false)
const applied = ref(null)

// Derived from the form, not from props: creating an endpoint fills form.id
// from the response while props stays as it was, so reading props here meant
// a second click on Save created a second endpoint.
const isNew = computed(() => !form.value.id)

const preset = computed(() => store.presetFor(form.value.preset))
const blocked = computed(() => preset.value?.unsupported_reason ?? null)

// Changing the service pre-fills the URL and group strategy from the preset,
// but only while creating: overwriting a URL someone has already tested and
// corrected would undo the capability test's whole point.
watch(() => form.value.preset, (key) => {
	const p = store.presetFor(key)
	if (!p || !isNew.value) {
		return
	}
	form.value.base_url = p.default_base_url
	form.value.group_strategy = p.default_group_strategy
})

const strategies = [
	{ id: 'passthrough', label: t('contacthub', 'Group vCards (passthrough)') },
	{ id: 'categories', label: t('contacthub', 'CATEGORIES on each contact') },
	{ id: 'collections', label: t('contacthub', 'One collection per group') },
]

/**
 * Fold the capability test's result back into the form.
 *
 * The endpoint has already been written server-side at this point, so this
 * is display only: showing the stored values is what makes "saved" credible
 * rather than something the user has to go and verify.
 */
function onApplied(updated) {
	form.value = { ...updated, password: '' }
	applied.value = [
		updated.collection_name ? t('contacthub', 'Address book: {name}', { name: updated.collection_name }) : null,
		t('contacthub', 'Groups: {strategy}', { strategy: updated.group_strategy }),
		t('contacthub', 'Base URL: {url}', { url: updated.base_url }),
	].filter(Boolean).join(' · ')
	store.loadEndpoints()
}

async function save() {
	saving.value = true
	errors.value = {}
	applied.value = null
	const payload = {
		name: form.value.name,
		preset: form.value.preset,
		baseUrl: form.value.base_url,
		username: form.value.username,
		collectionName: form.value.collection_name || '',
		groupStrategy: form.value.group_strategy,
	}
	// Only send a password when one was typed. Blank means "keep the stored
	// one", which is why the field is never pre-filled either.
	if (form.value.password) {
		payload.password = form.value.password
	}

	try {
		if (isNew.value) {
			const created = await api.createEndpoint(payload)
			form.value = { ...created, password: '' }
			showSuccess(t('contacthub', 'Credentials verified and endpoint created. Run the capability test next.'))
			await store.loadEndpoints()
		} else {
			await api.updateEndpoint(form.value.id, payload)
			showSuccess(t('contacthub', 'Endpoint saved'))
			emit('saved')
		}
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
		<h3>{{ isNew ? t('contacthub', 'New endpoint') : t('contacthub', 'Edit endpoint') }}</h3>

		<NcTextField
			v-model="form.name"
			:label="t('contacthub', 'Name')"
			:error="!!errors.name"
			:helper-text="errors.name" />

		<label class="ch-label">{{ t('contacthub', 'Service') }}</label>
		<NcSelect
			v-model="form.preset"
			:options="store.presets.map(p => p.key)"
			:clearable="false"
			:get-option-label="key => store.presetFor(key)?.label ?? key" />

		<NcNoteCard v-if="blocked" type="error">
			{{ blocked }}
		</NcNoteCard>
		<NcNoteCard v-else-if="preset" type="info">
			{{ preset.auth_hint }}
		</NcNoteCard>

		<NcTextField
			v-model="form.base_url"
			:label="t('contacthub', 'Base URL')"
			:error="!!errors.base_url"
			:helper-text="errors.base_url" />

		<NcTextField
			v-model="form.username"
			:label="t('contacthub', 'Username')"
			:error="!!errors.username"
			:helper-text="errors.username" />

		<NcTextField
			v-model="form.password"
			type="password"
			:label="isNew ? t('contacthub', 'Password') : t('contacthub', 'New password (leave blank to keep)')"
			:error="!!errors.password"
			:helper-text="errors.password" />

		<NcTextField
			v-model="form.collection_name"
			:label="t('contacthub', 'Address book name (optional)')"
			:helper-text="t('contacthub', 'Only needed when the account has more than one address book.')" />

		<label class="ch-label">{{ t('contacthub', 'How this server stores groups') }}</label>
		<NcCheckboxRadioSwitch
			v-for="s in strategies"
			:key="s.id"
			:model-value="form.group_strategy"
			:value="s.id"
			name="group_strategy"
			type="radio"
			@update:model-value="form.group_strategy = $event">
			{{ s.label }}
		</NcCheckboxRadioSwitch>
		<p class="ch-muted">
			{{ t('contacthub', 'Not sure? Run the capability test below and it will tell you.') }}
		</p>

		<p class="ch-muted">
			{{ t('contacthub', 'Saving contacts the server to check these credentials, so it may take a moment.') }}
		</p>

		<div class="ch-form__actions">
			<NcButton type="primary" :disabled="saving || !!blocked" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ saving ? t('contacthub', 'Checking credentials…') : t('contacthub', 'Save') }}
			</NcButton>
			<NcButton @click="emit('cancel')">
				{{ t('contacthub', 'Close') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="applied" type="success">
			{{ t('contacthub', 'Settings saved to this endpoint.') }}
			<strong>{{ applied }}</strong>
		</NcNoteCard>

		<CapabilityTest
			v-if="form.id"
			:endpoint-id="form.id"
			@applied="onApplied" />
	</div>
</template>

<style scoped>
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
</style>
