<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

import { api, newProgressToken } from '../api.js'

const props = defineProps({ job: { type: Object, required: true } })
const emit = defineEmits(['finished'])

const busy = ref(false)
const force = ref(false)
const result = ref(null)
const progress = ref(null)
const autoResumeIn = ref(0)

// A run that throws produces no result at all, so none of the reporting
// below has anything to render and the panel just goes quiet. A toast alone
// was never enough here even once toasts became visible: it times out, and
// the thing it is reporting is usually a configuration mistake the user has
// to go and fix somewhere else. This stays on screen until the next run.
const failure = ref(null)

// A run this panel did not start: one left open when the user navigated
// away, handed back on the jobs listing. Shown rather than silently resumed,
// with the same countdown a paused segment gets, so Stop is reachable.
const adopted = ref(null)

// A paused run is continued by a *fresh* request, whose result replaces the
// previous one. Anything the earlier segment reported would vanish with it,
// which is how an error could appear and then disappear as the next chunk
// went through. Carried across segments and cleared only when the user
// starts a run themselves.
const carried = ref({ warnings: [], errors: [] })

const merge = (previous, current) => [...new Set([...previous, ...(current ?? [])])]
const allWarnings = computed(() => merge(carried.value.warnings, result.value?.warnings))
const allErrors = computed(() => merge(carried.value.errors, result.value?.errors))

let pollTimer = null
let resumeTimer = null

// Which start() a poll belongs to. clearInterval stops future firings but not
// a poll already suspended on its await, so without this a request that was
// in flight when the run finished resolves afterwards and re-populates
// progress — putting the bar and "Finishing up" back underneath the finished
// result, which is the very thing clearing progress was meant to prevent.
let generation = 0

const PHASES = {
	fetch_a: t('contacthub', 'Reading the first side'),
	fetch_b: t('contacthub', 'Reading the second side'),
	planning: t('contacthub', 'Comparing both sides'),
	applying: t('contacthub', 'Applying changes'),
	resuming: t('contacthub', 'Continuing where the last run stopped'),
}

function stopTimers() {
	clearInterval(pollTimer)
	clearTimeout(resumeTimer)
	pollTimer = null
	resumeTimer = null
}

onUnmounted(stopTimers)

onMounted(() => {
	const open = props.job.open_run
	if (!open) {
		return
	}
	adopted.value = open
	scheduleResume(false)
})

/**
 * A run is bounded by a server-side time budget, so a large address book
 * comes back 'paused' with work still queued. That is a normal outcome, not
 * a failure: submitting again continues the same queue rather than starting
 * over. This schedules that automatically so the user is not left clicking
 * Resume until it drains.
 */
function scheduleResume(dryRun) {
	autoResumeIn.value = 3
	resumeTimer = setInterval(() => {
		autoResumeIn.value -= 1
		if (autoResumeIn.value <= 0) {
			clearInterval(resumeTimer)
			start(dryRun, true)
		}
	}, 1000)
}

async function start(dryRun, resumed = false) {
	stopTimers()
	busy.value = true
	if (resumed) {
		carried.value = {
			warnings: allWarnings.value,
			errors: allErrors.value,
		}
	} else {
		carried.value = { warnings: [], errors: [] }
	}
	result.value = null
	progress.value = null
	autoResumeIn.value = 0
	failure.value = null
	adopted.value = null

	const mine = ++generation
	const token = newProgressToken()
	pollTimer = setInterval(async () => {
		try {
			const update = await api.progress(token)
			if (mine === generation) {
				progress.value = update
			}
		} catch {
			// A missed poll is cosmetic; the run itself is unaffected.
		}
	}, 900)

	try {
		const run = await api.runJob(props.job.id, { dryRun, force: force.value, progressToken: token })
		result.value = run
		if (run.status === 'paused') {
			scheduleResume(dryRun)
		} else {
			emit('finished')
		}
	} catch (error) {
		if (error.status === 409) {
			// Another process holds this job: a cron tick, or another tab.
			showError(t('contacthub', 'This job is already running somewhere else. It will continue on its own.'))
		} else {
			// Whatever the server said, verbatim. A sync talks to a server
			// this app knows nothing about, so any friendlier wording would
			// be a guess, and the useful part -- "Username or password was
			// incorrect" -- is already in there.
			failure.value = error.message
			showError(error.message)
		}
	} finally {
		busy.value = false
		clearInterval(pollTimer)
		pollTimer = null
		// Retiring the generation is what actually stops the polling, since a
		// suspended poll ignores clearInterval entirely.
		generation++
		// Drop the last poll, or the panel keeps announcing "Applying changes
		// — Finishing up" under a finished run's summary. The progress object
		// describes work in flight; once the request has returned there is
		// none, and the result block below says what happened.
		progress.value = null
	}
}

async function abandon() {
	stopTimers()
	autoResumeIn.value = 0
	try {
		await api.abandonRun(props.job.id)
		result.value = null
		failure.value = null
		adopted.value = null
		emit('finished')
	} catch (error) {
		failure.value = error.message
		showError(error.message)
	}
}

const percent = () => {
	const p = progress.value
	if (!p || !p.total) {
		return null
	}
	return Math.min(100, Math.round((p.current / p.total) * 100))
}
</script>

<template>
	<div class="ch-run">
		<h3>{{ t('contacthub', 'Sync “{name}”', { name: job.name }) }}</h3>

		<NcCheckboxRadioSwitch :model-value="force" @update:model-value="force = $event">
			{{ t('contacthub', 'Force — ignore stored state and re-compare everything') }}
		</NcCheckboxRadioSwitch>

		<div class="ch-run__actions">
			<NcButton :disabled="busy" @click="start(true)">
				{{ t('contacthub', 'Preview') }}
			</NcButton>
			<NcButton type="primary" :disabled="busy" @click="start(false)">
				{{ t('contacthub', 'Apply') }}
			</NcButton>
		</div>

		<div v-if="busy || progress" class="ch-run__progress">
			<p>
				{{ PHASES[progress?.phase] || t('contacthub', 'Working') }}
				<span v-if="progress?.message" class="ch-muted"> — {{ progress.message }}</span>
			</p>
			<NcProgressBar :value="percent() ?? 0" :error="false" size="medium" />
			<p v-if="progress?.total" class="ch-muted">
				{{ progress.current }} / {{ progress.total }}
			</p>
		</div>

		<NcNoteCard v-if="adopted" type="info">
			{{ t('contacthub', 'A sync started earlier has not finished yet.') }}
			<span v-if="autoResumeIn > 0">
				{{ t('contacthub', 'Continuing in {n}s…', { n: autoResumeIn }) }}
			</span>
			<NcButton type="tertiary" @click="abandon">
				{{ t('contacthub', 'Stop') }}
			</NcButton>
		</NcNoteCard>

		<NcNoteCard v-if="failure" type="error">
			<p>{{ t('contacthub', 'This run did not finish.') }}</p>
			<p class="ch-run__failure">{{ failure }}</p>
		</NcNoteCard>

		<div v-if="result" class="ch-run__result">
			<NcNoteCard v-if="result.status === 'paused'" type="info">
				{{ result.paused_reason }}
				<span v-if="autoResumeIn > 0">
					{{ t('contacthub', 'Continuing in {n}s…', { n: autoResumeIn }) }}
				</span>
				<NcButton type="tertiary" @click="abandon">
					{{ t('contacthub', 'Stop') }}
				</NcButton>
			</NcNoteCard>

			<NcNoteCard v-else-if="result.dry_run" type="info">
				{{ t('contacthub', 'Preview only — nothing was written.') }}
			</NcNoteCard>

			<ul class="ch-stats">
				<li>{{ t('contacthub', 'Created') }}: <strong>{{ result.created }}</strong></li>
				<li>{{ t('contacthub', 'Updated') }}: <strong>{{ result.updated }}</strong></li>
				<li>{{ t('contacthub', 'Deleted') }}: <strong>{{ result.deleted }}</strong></li>
				<li>{{ t('contacthub', 'Archived') }}: <strong>{{ result.archived }}</strong></li>
				<li>{{ t('contacthub', 'Conflicts') }}: <strong>{{ result.conflicts }}</strong></li>
			</ul>

			<NcNoteCard v-for="(w, i) in allWarnings" :key="'w' + i" type="warning">
				{{ w }}
			</NcNoteCard>
			<NcNoteCard v-for="(e, i) in allErrors" :key="'e' + i" type="error">
				{{ e }}
			</NcNoteCard>

			<NcNoteCard v-if="result.conflicts > 0" type="warning">
				{{ t('contacthub', 'Some contacts need you to pick a winner. See the Conflicts screen.') }}
			</NcNoteCard>
		</div>
	</div>
</template>

<style scoped>
.ch-run {
	margin-block-start: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}
.ch-run__actions {
	display: flex;
	gap: 8px;
	margin-block: 12px;
}
.ch-run__progress {
	margin-block: 12px;
}
.ch-run__failure {
	margin-block-start: 4px;
	font-family: var(--font-face-monospace, monospace);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
.ch-stats {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	list-style: none;
	padding: 0;
	margin-block: 12px;
}
</style>
