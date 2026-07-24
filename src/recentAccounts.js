import { createApp } from 'vue'
import RecentAccounts from './views/RecentAccounts.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_accounts', (el, { widget }) => {
		const app = createApp(RecentAccounts, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
