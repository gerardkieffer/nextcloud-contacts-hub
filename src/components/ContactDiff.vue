<script setup>
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'

/**
 * Side-by-side comparison of the two copies of a contact.
 *
 * The point of this component is that a user should not have to read two
 * vCards to decide which one to keep. Fields that differ are highlighted, so
 * the answer to "what actually changed?" is visible rather than hunted for.
 * The old app showed two static tables and left the comparing to the human.
 */
const props = defineProps({
	left: { type: Object, default: null },
	right: { type: Object, default: null },
	leftLabel: { type: String, required: true },
	rightLabel: { type: String, required: true },
})

const list = (v) => (Array.isArray(v) ? v.join(', ') : (v ?? ''))

const rows = computed(() => {
	const fields = [
		['name', t('contacthub', 'Name')],
		['emails', t('contacthub', 'Email')],
		['phones', t('contacthub', 'Phone')],
		['categories', t('contacthub', 'Groups')],
		['rev', t('contacthub', 'Last modified')],
	]

	return fields.map(([key, label]) => {
		const l = list(props.left?.[key])
		const r = list(props.right?.[key])
		return {
			key,
			label,
			left: l,
			right: r,
			// Only mark a difference when both sides exist. When one is gone
			// entirely, every row would light up and say nothing useful.
			differs: !!props.left && !!props.right && l !== r,
		}
	})
})

const photoRow = computed(() => ({
	left: props.left?.has_photo,
	right: props.right?.has_photo,
	differs: !!props.left && !!props.right && props.left.has_photo !== props.right.has_photo,
}))
</script>

<template>
	<table class="ch-diff">
		<thead>
			<tr>
				<th />
				<th>
					{{ leftLabel }}
					<span v-if="!left" class="ch-gone">{{ t('contacthub', '(no longer present)') }}</span>
				</th>
				<th>
					{{ rightLabel }}
					<span v-if="!right" class="ch-gone">{{ t('contacthub', '(no longer present)') }}</span>
				</th>
			</tr>
		</thead>
		<tbody>
			<tr v-for="row in rows" :key="row.key" :class="{ 'is-different': row.differs }">
				<th scope="row">{{ row.label }}</th>
				<td>{{ row.left || '—' }}</td>
				<td>{{ row.right || '—' }}</td>
			</tr>
			<tr :class="{ 'is-different': photoRow.differs }">
				<th scope="row">{{ t('contacthub', 'Photo') }}</th>
				<td>{{ photoRow.left ? t('contacthub', 'yes') : '—' }}</td>
				<td>{{ photoRow.right ? t('contacthub', 'yes') : '—' }}</td>
			</tr>
		</tbody>
	</table>
</template>

<style scoped>
.ch-diff {
	width: 100%;
	border-collapse: collapse;
	margin-block: 12px;
}
.ch-diff th,
.ch-diff td {
	padding: 6px 8px;
	text-align: start;
	vertical-align: top;
	border-block-end: 1px solid var(--color-border);
}
.ch-diff tbody th {
	width: 140px;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}
.ch-diff .is-different td {
	background-color: var(--color-warning-hover, rgba(255, 193, 7, 0.12));
	font-weight: 600;
}
.ch-gone {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}
</style>
