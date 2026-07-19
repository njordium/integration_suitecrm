<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Julien Veyssier <eneiluj@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;

/**
 * Class CheckAlerts
 *
 * @package OCA\SuiteCRM\BackgroundJob
 */
class CheckAlerts extends TimedJob {

	public function __construct(ITimeFactory $time,
								protected SuiteCRMAPIService $suitecrmAPIService,
								protected LoggerInterface $logger) {
		parent::__construct($time);
		// Every 15 minutes
		$this->setInterval(60 * 15);
	}

	protected function run($argument): void {
		// Swallow any transport or upstream failure so the cron dispatcher
		// still marks this job as completed and moves on. Otherwise a single
		// SuiteCRM outage would surface as an uncaught exception in the
		// Nextcloud job runner and leave the whole job queue stuck.
		try {
			$this->suitecrmAPIService->checkAlerts();
			$this->logger->info('Checked if users have SuiteCRM alerts.', [
				'app' => Application::APP_ID,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('SuiteCRM checkAlerts background job failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
