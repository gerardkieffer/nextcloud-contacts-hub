<script setup>
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import { api } from '../api.js'

const loaded = ref(null)
const mode = ref('profile')
const customEmail = ref('')
const errors = ref({})
const saving = ref(false)

const profileInfoUrl = generateUrl('/settings/user/personal-info')
const appUrl = generateUrl('/apps/contacthub/') + '#jobs'

function adopt(prefs) {
	loaded.value = prefs
	mode.value = prefs.mode
	customEmail.value = prefs.custom_email
}

onMounted(async () => {
	try {
		adopt(await api.notifications())
	} catch (error) {
		showError(error.message)
	}
})

const dirty = computed(() => loaded.value !== null
	&& (mode.value !== loaded.value.mode || customEmail.value.trim() !== loaded.value.custom_email))

async function save() {
	saving.value = true
	errors.value = {}
	try {
		adopt(await api.updateNotifications(mode.value, customEmail.value))
		showSuccess(t('contacthub', 'Notification settings saved'))
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
	<NcSettingsSection
		:name="t('contacthub', 'Conflict notifications')"
		:description="t('contacthub', 'When a scheduled sync finds a contact that may already exist on the other side under a different identity, the job pauses until you decide what to do. Contacts Hub can email you when that happens.')">
		<div v-if="loaded === null" class="ch-settings__busy">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<NcCheckboxRadioSwitch
				:model-value="mode"
				value="profile"
				name="contacthub_notify_mode"
				type="radio"
				@update:model-value="mode = $event">
				<template v-if="loaded.profile_email_valid">
					{{ t('contacthub', 'Email my profile address ({email})', { email: loaded.profile_email }) }}
				</template>
				<template v-else>
					{{ t('contacthub', 'Email my profile address (none set)') }}
				</template>
			</NcCheckboxRadioSwitch>
			<p v-if="mode === 'profile' && !loaded.profile_email_valid" class="ch-settings__hint">
				{{ loaded.profile_email
					? t('contacthub', '“{email}” on your profile is not a valid address, so nothing will be sent.', { email: loaded.profile_email })
					: t('contacthub', 'Your profile has no email address, so nothing will be sent until you add one.') }}
				<a :href="profileInfoUrl" class="ch-link">{{ t('contacthub', 'Edit your profile') }}</a>
			</p>

			<NcCheckboxRadioSwitch
				:model-value="mode"
				value="custom"
				name="contacthub_notify_mode"
				type="radio"
				@update:model-value="mode = $event">
				{{ t('contacthub', 'Email a different address') }}
			</NcCheckboxRadioSwitch>
			<div v-if="mode === 'custom'" class="ch-settings__field">
				<NcTextField
					v-model="customEmail"
					type="email"
					autocomplete="email"
					:label="t('contacthub', 'Email address')"
					:error="!!errors.custom_email"
					:helper-text="errors.custom_email" />
			</div>

			<NcCheckboxRadioSwitch
				:model-value="mode"
				value="off"
				name="contacthub_notify_mode"
				type="radio"
				@update:model-value="mode = $event">
				{{ t('contacthub', 'Do not email me') }}
			</NcCheckboxRadioSwitch>
			<p v-if="mode === 'off'" class="ch-settings__hint">
				{{ t('contacthub', 'Paused jobs still show up in Contacts Hub, next to the job and on the Conflicts screen.') }}
				<a :href="appUrl" class="ch-link">{{ t('contacthub', 'Open Contacts Hub') }}</a>
			</p>

			<NcNoteCard v-if="mode !== 'off' && loaded.mail.delivery_disabled" type="warning">
				{{ t('contacthub', 'Outgoing email is switched off on this Nextcloud server, so no notification can be sent. Ask your administrator.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="mode !== 'off' && !loaded.mail.likely_configured" type="warning">
				{{ t('contacthub', 'Outgoing email on this Nextcloud server does not look configured, or a recent notification failed to send. Notifications may not reach you. Ask your administrator to check the email server settings.') }}
			</NcNoteCard>

			<div class="ch-settings__actions">
				<NcButton type="primary" :disabled="saving || !dirty" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('contacthub', 'Save') }}
				</NcButton>
			</div>
		</template>
	</NcSettingsSection>
</template>

<style scoped>
.ch-settings__busy {
	display: flex;
	padding: 16px;
}
.ch-settings__hint {
	margin: 0 0 8px 36px;
	color: var(--color-text-maxcontrast);
}
.ch-settings__field {
	max-width: 400px;
	margin: 0 0 8px 36px;
}
.ch-settings__actions {
	margin-block-start: 16px;
}
.ch-link {
	text-decoration: underline;
}
</style>
