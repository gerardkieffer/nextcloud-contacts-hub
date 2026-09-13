import { reactive } from 'vue'
import { showError } from '@nextcloud/dialogs'

import { api } from './api.js'

/**
 * Shared application state.
 *
 * A plain reactive object rather than Pinia: this app has five screens and
 * three collections, and a store library would be more ceremony than the
 * problem has. Every mutation goes through a load* method so the API stays
 * the single source of truth and no component invents its own copy.
 */
export const store = reactive({
	endpoints: [],
	jobs: [],
	conflicts: [],
	addressBooks: [],
	presets: [],
	loading: true,

	async loadAll() {
		this.loading = true
		try {
			const [endpoints, jobs, conflicts, addressBooks, presets] = await Promise.all([
				api.endpoints(),
				api.jobs(),
				api.conflicts(),
				api.addressBooks(),
				api.presets(),
			])
			this.endpoints = endpoints
			this.jobs = jobs
			this.conflicts = conflicts
			this.addressBooks = addressBooks
			this.presets = presets
		} catch (error) {
			showError(error.message)
		} finally {
			this.loading = false
		}
	},

	async loadEndpoints() {
		this.endpoints = await api.endpoints()
	},

	async loadJobs() {
		this.jobs = await api.jobs()
	},

	async loadConflicts() {
		this.conflicts = await api.conflicts()
	},

	presetFor(key) {
		return this.presets.find((p) => p.key === key)
	},

	endpointName(id) {
		return this.endpoints.find((e) => e.id === id)?.name ?? '—'
	},
})
