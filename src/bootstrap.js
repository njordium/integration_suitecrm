import { translate, translatePlural } from '@nextcloud/l10n'

export function applyGlobals(app) {
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	app.config.globalProperties.OC = window.OC
	app.config.globalProperties.OCA = window.OCA
}
