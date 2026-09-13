import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Thin wrapper over this app's OCS API.
 *
 * Two things it normalises so no component has to:
 *
 *  - Unwrapping the OCS envelope, so callers get their payload rather than
 *    `response.data.ocs.data`.
 *  - Turning a 400 into an ApiError carrying the per-field `errors` map the
 *    service layer produces, so a form can put each message next to the
 *    input that caused it.
 */

export class ApiError extends Error {

	constructor(message, { status = 0, errors = {} } = {}) {
		super(message)
		this.name = 'ApiError'
		this.status = status
		this.errors = errors
	}

	/** True when the failure was per-field validation rather than a fault. */
	get isValidation() {
		return this.status === 400 && Object.keys(this.errors).length > 0
	}

}

const url = (path) => generateOcsUrl('apps/contacthub/api/v1/' + path)

async function call(method, path, data = undefined, params = undefined) {
	try {
		const response = await axios({
			method,
			url: url(path),
			data,
			params,
			headers: { 'OCS-APIRequest': 'true' },
		})
		return response.data?.ocs?.data
	} catch (error) {
		const ocs = error.response?.data?.ocs
		throw new ApiError(
			ocs?.data?.message || ocs?.meta?.message || error.message || 'Request failed',
			{ status: error.response?.status ?? 0, errors: ocs?.data?.errors ?? {} },
		)
	}
}

export const api = {
	presets: () => call('get', 'presets'),

	addressBooks: () => call('get', 'address-books'),
	backups: (bookId) => call('get', `address-books/${bookId}/backups`),
	snapshot: (bookId) => call('post', `address-books/${bookId}/backups`),
	restore: (backupId, mirror) => call('post', `backups/${backupId}/restore`, { mirror }),

	endpoints: () => call('get', 'endpoints'),
	endpoint: (id) => call('get', `endpoints/${id}`),
	createEndpoint: (data) => call('post', 'endpoints', data),
	updateEndpoint: (id, data) => call('put', `endpoints/${id}`, data),
	deleteEndpoint: (id) => call('delete', `endpoints/${id}`),
	testEndpoint: (id, writeProbe, mkcolProbe) =>
		call('post', `endpoints/${id}/test`, { writeProbe, mkcolProbe }),
	applySuggestions: (id, data) => call('post', `endpoints/${id}/apply`, data),

	exportSettings: (includePasswords) => call('post', 'settings/export', { includePasswords }),
	settingsFiles: () => call('get', 'settings/files'),
	inspectSettings: (source) => call('post', 'settings/inspect', source),
	importSettings: (source, passwords) => call('post', 'settings/import', { ...source, passwords }),

	jobs: () => call('get', 'jobs'),
	job: (id) => call('get', `jobs/${id}`),
	createJob: (data) => call('post', 'jobs', data),
	updateJob: (id, data) => call('put', `jobs/${id}`, data),
	deleteJob: (id) => call('delete', `jobs/${id}`),
	runJob: (id, { dryRun, force, progressToken }) =>
		call('post', `jobs/${id}/run`, { dryRun, force, progressToken }),

	runs: (jobId) => call('get', `jobs/${jobId}/runs`),
	openRun: (jobId) => call('get', `jobs/${jobId}/runs/open`),
	runItems: (jobId, runId) => call('get', `jobs/${jobId}/runs/${runId}/items`),
	abandonRun: (jobId) => call('post', `jobs/${jobId}/runs/abandon`),
	progress: (token) => call('get', 'progress', undefined, { token }),

	conflicts: () => call('get', 'conflicts'),
	jobConflicts: (jobId) => call('get', `jobs/${jobId}/conflicts`),
	resolveConflict: (id, resolution) => call('post', `conflicts/${id}/resolve`, { resolution }),
	resolveBatchConflicts: (choice) => call('post', 'conflicts/resolve-batch', { choice }),
}

/** Identifies a run to the page that started it, so polling follows its own. */
export function newProgressToken() {
	return Math.random().toString(36).slice(2) + Date.now().toString(36)
}
