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

use OCA\SuiteCRM\Dashboard\SuiteCRMAccountsWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMActivitiesWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMCalendarWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMCasesWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMContactsWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMLeadsWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMPipelineWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMTasksWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMWidget;
use OCA\SuiteCRM\Listener\AddQuickActionsScriptListener;
use OCA\SuiteCRM\Listener\LoadFilesScriptListener;
use OCA\SuiteCRM\Notification\Notifier;
use OCA\SuiteCRM\Reference\SuiteCRMReferenceProvider;
use OCA\SuiteCRM\Search\SuiteCRMSearchProvider;

/**
 * Class Application
 *
 * @package OCA\SuiteCRM\AppInfo
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_suitecrm';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(SuiteCRMWidget::class);
		$context->registerDashboardWidget(SuiteCRMCalendarWidget::class);
		$context->registerDashboardWidget(SuiteCRMCasesWidget::class);
		$context->registerDashboardWidget(SuiteCRMTasksWidget::class);
		$context->registerDashboardWidget(SuiteCRMPipelineWidget::class);
		$context->registerDashboardWidget(SuiteCRMActivitiesWidget::class);
		$context->registerDashboardWidget(SuiteCRMContactsWidget::class);
		$context->registerDashboardWidget(SuiteCRMAccountsWidget::class);
		$context->registerDashboardWidget(SuiteCRMLeadsWidget::class);
		$context->registerSearchProvider(SuiteCRMSearchProvider::class);
		$context->registerReferenceProvider(SuiteCRMReferenceProvider::class);
		$context->registerNotifierService(Notifier::class);
		// Inject the global Quick Actions floating action button
		// on every full page render for signed-in users.
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, AddQuickActionsScriptListener::class);
		// Register the "Link to SuiteCRM record" file action when the
		// Files app is rendering. The listener is a no-op on any other
		// dispatched event, so a stray registration outside Files does
		// nothing rather than corrupting the target-app surface.
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}

