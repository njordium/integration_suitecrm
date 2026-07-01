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

use OCA\SuiteCRM\Dashboard\SuiteCRMCalendarWidget;
use OCA\SuiteCRM\Dashboard\SuiteCRMWidget;
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
		$context->registerSearchProvider(SuiteCRMSearchProvider::class);
		$context->registerReferenceProvider(SuiteCRMReferenceProvider::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}

