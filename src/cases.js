import { createApp } from 'vue'
import Cases from './views/Cases.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_cases', (el, { widget }) => {
		const app = createApp(Cases, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
