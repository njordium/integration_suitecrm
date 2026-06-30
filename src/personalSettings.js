import { createApp } from 'vue'
import { applyGlobals } from './bootstrap.js'
import PersonalSettings from './components/PersonalSettings.vue'

const app = createApp(PersonalSettings)
applyGlobals(app)
app.mount('#suitecrm_prefs')
