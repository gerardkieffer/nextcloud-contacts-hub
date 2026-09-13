<script setup>
import { computed, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAlert from 'vue-material-design-icons/AlertCircleOutline.vue'
import IconServer from 'vue-material-design-icons/ServerNetwork.vue'
import IconSync from 'vue-material-design-icons/Sync.vue'
import IconCog from 'vue-material-design-icons/Cog.vue'
import { t } from '@nextcloud/l10n'

import ConflictsView from './views/ConflictsView.vue'
import EndpointsView from './views/EndpointsView.vue'
import JobsView from './views/JobsView.vue'
import SettingsView from './views/SettingsView.vue'
import { store } from './store.js'

// Hash routing rather than vue-router: three destinations, no nested routes,
// no route params worth resolving. It also survives a reload, which matters
// when a sync is mid-flight and the user refreshes.
//
// The navigation items carry a real href and the hashchange listener is the
// only thing that sets the view. Handling the click instead does not work:
// NcAppNavigationItem renders an anchor, whose default navigation to "#"
// fires *after* the handler and immediately undoes it.
const view = ref(window.location.hash.replace('#', '') || 'jobs')

window.addEventListener('hashchange', () => {
	view.value = window.location.hash.replace('#', '') || 'jobs'
})

const conflictCount = computed(() => store.conflicts.length)

// Read off the mount point (rendered by PageController on every request)
// rather than fetched over the API, so it reflects whatever PHP is actually
// running right now, not whatever the JS bundle was built to expect.
const version = document.getElementById('contacthub')?.dataset.version ?? ''

onMounted(() => store.loadAll())
</script>

<template>
	<NcContent app-name="contacthub">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="t('contacthub', 'Sync jobs')"
					:active="view === 'jobs'"
					href="#jobs">
					<template #icon>
						<IconSync :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('contacthub', 'Endpoints')"
					:active="view === 'endpoints'"
					href="#endpoints">
					<template #icon>
						<IconServer :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('contacthub', 'Conflicts')"
					:active="view === 'conflicts'"
					:counter="conflictCount"
					href="#conflicts">
					<template #icon>
						<IconAlert :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('contacthub', 'Export and import')"
					:active="view === 'settings'"
					href="#settings">
					<template #icon>
						<IconCog :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<div class="ch-version">
					{{ t('contacthub', 'Contacts Hub {version}', { version }) }}
				</div>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div v-if="store.loading" class="ch-loading">
				<NcLoadingIcon :size="44" />
			</div>
			<JobsView v-else-if="view === 'jobs'" />
			<EndpointsView v-else-if="view === 'endpoints'" />
			<ConflictsView v-else-if="view === 'conflicts'" />
			<SettingsView v-else-if="view === 'settings'" />
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.ch-version {
	padding: 8px 16px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.ch-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}
</style>
