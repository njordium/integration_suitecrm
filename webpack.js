const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
    colors: true,
    modules: false,
}

webpackConfig.entry = {
    personalSettings: { import: path.join(__dirname, 'src', 'personalSettings.js'), filename: 'integration_suitecrm-personalSettings.js' },
    adminSettings: { import: path.join(__dirname, 'src', 'adminSettings.js'), filename: 'integration_suitecrm-adminSettings.js' },
    dashboard: { import: path.join(__dirname, 'src', 'dashboard.js'), filename: 'integration_suitecrm-dashboard.js' },
    calendar: { import: path.join(__dirname, 'src', 'calendar.js'), filename: 'integration_suitecrm-calendar.js' },
    cases: { import: path.join(__dirname, 'src', 'cases.js'), filename: 'integration_suitecrm-cases.js' },
    tasks: { import: path.join(__dirname, 'src', 'tasks.js'), filename: 'integration_suitecrm-tasks.js' },
    pipeline: { import: path.join(__dirname, 'src', 'pipeline.js'), filename: 'integration_suitecrm-pipeline.js' },
    quickactions: { import: path.join(__dirname, 'src', 'quickActions.js'), filename: 'integration_suitecrm-quickactions.js' },
    activities: { import: path.join(__dirname, 'src', 'activities.js'), filename: 'integration_suitecrm-activities.js' },
    recentcontacts: { import: path.join(__dirname, 'src', 'recentContacts.js'), filename: 'integration_suitecrm-recentcontacts.js' },
    recentaccounts: { import: path.join(__dirname, 'src', 'recentAccounts.js'), filename: 'integration_suitecrm-recentaccounts.js' },
    recentleads: { import: path.join(__dirname, 'src', 'recentLeads.js'), filename: 'integration_suitecrm-recentleads.js' },
    fileshook: { import: path.join(__dirname, 'src', 'filesHook.js'), filename: 'integration_suitecrm-fileshook.js' },
}

module.exports = webpackConfig
