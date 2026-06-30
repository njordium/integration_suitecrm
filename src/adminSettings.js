import { createApp } from 'vue'
import { applyGlobals } from './bootstrap.js'
import AdminSettings from './components/AdminSettings.vue'

const app = createApp(AdminSettings)
applyGlobals(app)
app.mount('#suitecrm_prefs')
