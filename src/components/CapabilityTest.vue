<script setup>
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import { api } from '../api.js'

const props = defineProps({ endpointId: { type: Number, required: true } })
const emit = defineEmits(['applied'])

const writeProbe = ref(false)
const mkcolProbe = ref(false)
const running = ref(false)
const result = ref(null)
const chosen = ref(null)

async function run() {
	running.value = true
	result.value = null
	try {
		result.value = await api.testEndpoint(props.endpointId, writeProbe.value, mkcolProbe.value)
		chosen.value = result.value.selected?.href
			?? result.value.collections?.[0]?.href
			?? null
	} catch (error) {
		showError(error.message)
	} finally {
		running.value = false
	}
}

async function apply() {
	const collection = (result.value.collections ?? []).find((c) => c.href === chosen.value)
	try {
		const updated = await api.applySuggestions(props.endpointId, {
			capabilities: {
				vcard_version: result.value.vcard_version ?? null,
				write_probe: result.value.write_probe ?? null,
				mkcol_probe: result.value.mkcol_probe ?? null,
			},
			collectionHref: chosen.value,
			// The discovery result calls this 'displayname', not 'name'.
			collectionName: collection?.displayname ?? null,
			groupStrategy: result.value.suggested_group_strategy ?? null,
			// Discovery can land on a different host than the one configured
			// (iCloud redirects per account); keeping the old one would make
			// every future request take the redirect again.
			baseUrl: result.value.discovered_at ?? null,
		})
		showSuccess(t('contacthub', 'Settings applied and saved'))
		// The updated endpoint travels with the event so the form can show
		// the new values rather than sending the user back to the list to
		// find out what changed.
		emit('applied', updated)
	} catch (error) {
		showError(error.message)
	}
}

const ok = (v) => v === true
</script>

<template>
	<div class="ch-probe">
		<h3>{{ t('contacthub', 'Capability test') }}</h3>
		<p class="ch-muted">
			{{ t('contacthub', 'Asks the server what it supports, then suggests matching settings.') }}
		</p>

		<NcCheckboxRadioSwitch :model-value="writeProbe" @update:model-value="writeProbe = $event">
			{{ t('contacthub', 'Write probe — creates and deletes a throwaway contact and group') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch :model-value="mkcolProbe" @update:model-value="mkcolProbe = $event">
			{{ t('contacthub', 'Collection probe — creates and deletes a throwaway address book') }}
		</NcCheckboxRadioSwitch>
		<p class="ch-muted">
			{{ t('contacthub', 'Only the write probe can tell which group format this server really supports.') }}
		</p>

		<NcButton type="secondary" :disabled="running" @click="run">
			<template v-if="running" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('contacthub', 'Run test') }}
		</NcButton>

		<div v-if="result" class="ch-probe__result">
			<NcNoteCard v-for="(err, i) in result.errors ?? []" :key="'e' + i" type="error">
				{{ err }}
			</NcNoteCard>
			<NcNoteCard v-for="(warn, i) in result.warnings ?? []" :key="'w' + i" type="warning">
				{{ warn }}
			</NcNoteCard>

			<template v-if="(result.collections ?? []).length">
				<h4>{{ t('contacthub', 'Address books found') }}</h4>
				<NcCheckboxRadioSwitch
					v-for="c in result.collections"
					:key="c.href"
					:model-value="chosen"
					:value="c.href"
					name="collection"
					type="radio"
					@update:model-value="chosen = $event">
					{{ c.displayname || c.href }}
					<span v-if="c.writable === false" class="ch-warn">
						{{ t('contacthub', '(read-only)') }}
					</span>
				</NcCheckboxRadioSwitch>
			</template>

			<template v-if="result.write_probe">
				<h4>{{ t('contacthub', 'Write probe') }}</h4>
				<ul class="ch-checks">
					<li :class="ok(result.write_probe.contact_roundtrip_ok) ? 'is-ok' : 'is-bad'">
						{{ t('contacthub', 'Contact round trip') }}
					</li>
					<li :class="ok(result.write_probe.group_roundtrip_ok) ? 'is-ok' : 'is-bad'">
						{{ t('contacthub', 'Group vCard round trip') }}
					</li>
					<li :class="ok(result.write_probe.categories_roundtrip_ok) ? 'is-ok' : 'is-bad'">
						{{ t('contacthub', 'CATEGORIES round trip') }}
					</li>
					<li :class="ok(result.write_probe.if_match_enforced) ? 'is-ok' : 'is-bad'">
						{{ t('contacthub', 'Enforces If-Match (safe concurrent writes)') }}
					</li>
					<li :class="ok(result.write_probe.cleanup_ok) ? 'is-ok' : 'is-bad'">
						{{ t('contacthub', 'Test data cleaned up') }}
					</li>
				</ul>
				<NcNoteCard v-if="!ok(result.write_probe.cleanup_ok)" type="warning">
					{{ t('contacthub', 'The probe could not delete everything it created. Look for contacts marked as CardDAV Sync test data and remove them by hand.') }}
				</NcNoteCard>
			</template>

			<NcNoteCard v-if="result.suggested_group_strategy" type="success">
				{{ t('contacthub', 'This server looks like a “{strategy}” server.', { strategy: result.suggested_group_strategy }) }}
			</NcNoteCard>

			<NcButton v-if="chosen" type="primary" @click="apply">
				{{ t('contacthub', 'Apply these settings') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.ch-probe {
	margin-block-start: 24px;
	padding-block-start: 16px;
	border-block-start: 1px solid var(--color-border);
}
.ch-probe__result {
	margin-block-start: 16px;
}
.ch-checks {
	list-style: none;
	padding: 0;
}
.ch-checks li::before {
	margin-inline-end: 8px;
}
.ch-checks .is-ok::before {
	content: '✓';
	color: var(--color-success);
}
.ch-checks .is-bad::before {
	content: '✕';
	color: var(--color-error);
}
.ch-warn {
	color: var(--color-warning-text, var(--color-warning));
}
</style>
