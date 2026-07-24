import { createApp } from 'vue'
import RecentLeads from './views/RecentLeads.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_leads', (el, { widget }) => {
		const app = createApp(RecentLeads, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
