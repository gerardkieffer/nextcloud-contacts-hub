import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

// createAppConfig emits to js/ with the app id prefixed, so the entry named
// 'main' below becomes js/contacthub-main.mjs -- which is what
// PageController::index passes to Util::addScript('contacthub', 'contacthub-main').
// Likewise 'settings' becomes js/contacthub-settings.mjs for Settings\Personal.
//
// The built bundle IS committed: Nextcloud installs apps as plain files with no
// build step on the server, so js/ has to be in the repository and in the
// release tarball.
const appConfig = createAppConfig(
	{
		main: resolve(join('src', 'main.js')),
		settings: resolve(join('src', 'settings.js')),
	},
	{
		// Keeps component CSS next to its chunk instead of one global stylesheet,
		// so the app cannot leak styles into the rest of Nextcloud.
		inlineCSS: { relativeCSSInjection: true },
	},
)

export default async (env) => {
	const config = await appConfig(env)

	// Vite's publicDir defaults to <root>/public and copies it into outDir on
	// every build. Here root and outDir are both the app directory, so leaving
	// it enabled dumps the copied tree straight into the repository root.
	// Nextcloud serves an app's static files from the app directory itself, so
	// there is nothing for Vite to copy in the first place.
	return { ...config, publicDir: false }
}
