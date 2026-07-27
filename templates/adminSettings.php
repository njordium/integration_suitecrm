<?php
/**
 * Nextcloud renders this template when a user opens
 * Settings → Administration → Connected accounts → SuiteCRM integration.
 * `Settings\Admin::getForm()` returns a `TemplateResponse` naming this file;
 * the runtime loads it in the settings-page context and calls `script()` to
 * pull the compiled Vue bundle (`js/integration_suitecrm-adminSettings.js`)
 * into the page. Vue mounts on the empty `<div>` below.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
$appId = OCA\SuiteCRM\AppInfo\Application::APP_ID;
script($appId, $appId . '-adminSettings');
?>

<div id="suitecrm_prefs"></div>