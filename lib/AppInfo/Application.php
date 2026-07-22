<?php
/**
 * Nextcloud - SuiteCRM
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\SuiteCRM\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

use OCA\SuiteCRM\Dashboard\SuiteCRMCalendarWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMCasesWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMPipelineWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMTasksWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMWidget;
use OCA\SuiteCRM\Listener\AddQuickActionsScriptListener;
use OCA\SuiteCRM\Notification\Notifier;
use OCA\SuiteCRM\Reference\SuiteCRMReferenceProvider;
use OCA\SuiteCRM\Search\SuiteCRMSearchProvider;

/**
 * Class Application
 *
 * @package OCA\SuiteCRM\AppInfo
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'njordium_suitecrm';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(SuiteCRMWidget::class);
		$context->registerDashboardWidget(SuiteCRMCalendarWidget::class);
		$context->registerDashboardWidget(SuiteCRMCasesWidget::class);
		$context->registerDashboardWidget(SuiteCRMTasksWidget::class);
		$context->registerDashboardWidget(SuiteCRMPipelineWidget::class);
		$context->registerSearchProvider(SuiteCRMSearchProvider::class);
		$context->registerReferenceProvider(SuiteCRMReferenceProvider::class);
		$context->registerNotifierService(Notifier::class);
		// Iter 79: inject the global Quick Actions floating action button
		// on every full page render for signed-in users.
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, AddQuickActionsScriptListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}

