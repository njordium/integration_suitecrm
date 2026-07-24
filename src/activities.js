import { createApp } from 'vue'
import Activities from './views/Activities.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_activities', (el, { widget }) => {
		const app = createApp(Activities, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
