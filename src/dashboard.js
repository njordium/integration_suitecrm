import { createApp } from 'vue'
import Dashboard from './views/Dashboard.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_events', (el, { widget }) => {
		const app = createApp(Dashboard, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
