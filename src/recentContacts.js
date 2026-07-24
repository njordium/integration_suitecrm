import { createApp } from 'vue'
import RecentContacts from './views/RecentContacts.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_contacts', (el, { widget }) => {
		const app = createApp(RecentContacts, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
