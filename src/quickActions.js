/**
 * Global Quick Actions entry.
 *
 * Mounts a Vue app onto a self-injected DOM node so the floating action
 * button appears on every Nextcloud page. The mount is guarded by a
 * signed-in-user + SuiteCRM-connection check inside the component itself
 * so the FAB stays invisible for users who have nothing to fire it into.
 *
 * @author Kim Haverblad
 */
import { createApp } from 'vue'
import QuickActionsFab from './components/QuickActionsFab.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', () => {
	// Avoid double-mount if the script somehow gets injected twice
	// (defensive, Nextcloud's addScript is normally idempotent, but a
	// misconfigured reverse proxy or cache can duplicate the tag).
	if (document.getElementById('suitecrm-quick-actions-fab')) {
		return
	}
	const el = document.createElement('div')
	el.id = 'suitecrm-quick-actions-fab'
	document.body.appendChild(el)
	const app = createApp(QuickActionsFab)
	applyGlobals(app)
	app.mount(el)
})
