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
    personalSettings: { import: path.join(__dirname, 'src', 'personalSettings.js'), filename: 'njordium_suitecrm-personalSettings.js' },
    adminSettings: { import: path.join(__dirname, 'src', 'adminSettings.js'), filename: 'njordium_suitecrm-adminSettings.js' },
    dashboard: { import: path.join(__dirname, 'src', 'dashboard.js'), filename: 'njordium_suitecrm-dashboard.js' },
    calendar: { import: path.join(__dirname, 'src', 'calendar.js'), filename: 'njordium_suitecrm-calendar.js' },
    cases: { import: path.join(__dirname, 'src', 'cases.js'), filename: 'njordium_suitecrm-cases.js' },
    tasks: { import: path.join(__dirname, 'src', 'tasks.js'), filename: 'njordium_suitecrm-tasks.js' },
    pipeline: { import: path.join(__dirname, 'src', 'pipeline.js'), filename: 'njordium_suitecrm-pipeline.js' },
}

module.exports = webpackConfig
