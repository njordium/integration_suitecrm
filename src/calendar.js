import { createApp } from 'vue'
import Calendar from './views/Calendar.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_calendar', (el, { widget }) => {
		const app = createApp(Calendar, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
