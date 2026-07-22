import { createApp } from 'vue'
import Tasks from './views/Tasks.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_tasks', (el, { widget }) => {
		const app = createApp(Tasks, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
