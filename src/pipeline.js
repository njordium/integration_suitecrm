import { createApp } from 'vue'
import Pipeline from './views/Pipeline.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('suitecrm_pipeline', (el, { widget }) => {
		const app = createApp(Pipeline, { title: widget.title })
		applyGlobals(app)
		app.mount(el)
	})
})
