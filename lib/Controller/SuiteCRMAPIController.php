<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Controller;

use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCA\SuiteCRM\AppInfo\Application;

class SuiteCRMAPIController extends Controller {

	/** @var string */
	private $accessToken;
	/** @var string */
	private $suitecrmUrl;

	public function __construct(string $appName,
								IRequest $request,
								IAppConfig $appConfig,
								private SuiteCRMAPIService $suitecrmAPIService,
								private TokenStorage $tokens,
								private ?string $userId) {
		parent::__construct($appName, $request);
		$this->accessToken = $userId !== null ? $this->tokens->getAccessToken($userId) : '';
		$this->suitecrmUrl = $appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
	}

	/**
	 * get suitecrm instance URL
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/url')]
	public function getSuiteCRMUrl(): DataResponse {
		return new DataResponse($this->suitecrmUrl);
	}

	/**
	 * get suitecrm user avatar
	 *
	 * @param string $suiteUserId
	 * @return DataDisplayResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/avatar')]
	public function getSuiteCRMAvatar(string $suiteUserId = ''): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$this->suitecrmAPIService->getSuiteCRMAvatar(
				$this->suitecrmUrl, $this->accessToken, $suiteUserId
			)
		);
		$response->cacheFor(60*60*24);
		return $response;
	}

	/**
	 * get reminder list for future events
	 *
	 * @param int|null $eventSinceTimestamp
	 * @param int|null $limit
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/reminders')]
	public function getReminders(?int $eventSinceTimestamp = null, ?int $limit = null): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getReminders(
			$this->suitecrmUrl, $this->accessToken, $this->userId, null, null, $eventSinceTimestamp, null, $limit
		);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}

	/**
	 * Upcoming Meetings/Calls/Tasks for the calendar dashboard widget.
	 *
	 * @param int $horizonDays How far into the future to look.
	 * @param int $limit Cap on total results.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/upcoming')]
	public function getUpcoming(int $horizonDays = 7, int $limit = 20): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getUpcoming(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $horizonDays, $limit
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * Iter 69 — first user-facing write feature.
	 *
	 * Create a follow-up SuiteCRM Task linked back to a source record
	 * (a Meeting, Call, existing Task, Contact, Account, Lead,
	 * Opportunity, or Case). Triggered from the "Create follow-up Task"
	 * action on entries in the SuiteCRM events + calendar dashboard
	 * widgets, so the user can turn "just had this meeting" into "must
	 * do this by Friday" without leaving Nextcloud.
	 *
	 * The link is stored via SuiteCRM's flat `parent_type` / `parent_id`
	 * pair on the Task record — one round trip, no separate relationship
	 * call needed. This is the same pattern the SuiteCRM UI uses when
	 * you click "Create Task" from a Meeting DetailView.
	 *
	 * Modules accepted as source are whitelisted: no Emails (parent
	 * relation doesn't exist on Tasks), no Notes (Note-parented-by-Task
	 * would be inside-out), no user or system modules. Anything the
	 * whitelist doesn't cover gets a 400 with a clear error rather than
	 * a downstream SuiteCRM 500.
	 *
	 * @param string $sourceModule  SuiteCRM module name of the source record
	 * @param string $sourceId      SuiteCRM record id (UUID) of the source
	 * @param string $name          Required — new Task's name
	 * @param string $description   Free-text body (may be empty)
	 * @param string|null $dateDue  ISO-8601 date/datetime, or null for no due date
	 * @param string $priority      'High' | 'Medium' | 'Low'
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/task-followup')]
	public function createFollowupTask(
		string $sourceModule,
		string $sourceId,
		string $name,
		string $description = '',
		?string $dateDue = null,
		string $priority = 'Medium',
	): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse(['error' => 'not connected'], 401);
		}
		if (trim($name) === '') {
			return new DataResponse(['error' => 'name is required'], 400);
		}
		if ($sourceId === '') {
			return new DataResponse(['error' => 'sourceId is required'], 400);
		}

		// Whitelist of source modules that can legitimately parent a
		// SuiteCRM Task. Keep in lockstep with the calendar-widget's
		// `TYPE_MODULE` map + the search-widget's known modules.
		$allowedParents = [
			'Meetings', 'Calls', 'Tasks',
			'Contacts', 'Accounts', 'Leads',
			'Opportunities', 'Cases',
		];
		if (!in_array($sourceModule, $allowedParents, true)) {
			return new DataResponse([
				'error' => sprintf('source module "%s" is not allowed as a Task parent', $sourceModule),
				'allowed' => $allowedParents,
			], 400);
		}

		// SuiteCRM's Task priority enum in 8.10.x is exactly {High, Medium, Low}.
		// Anything else silently degrades to Medium on the SuiteCRM side —
		// catch it here so the user sees a clear message instead.
		if (!in_array($priority, ['High', 'Medium', 'Low'], true)) {
			return new DataResponse(['error' => 'priority must be one of High / Medium / Low'], 400);
		}

		$attributes = [
			'name' => trim($name),
			'description' => $description,
			'status' => 'Not Started',
			'priority' => $priority,
			'parent_type' => $sourceModule,
			'parent_id' => $sourceId,
		];
		if ($dateDue !== null && trim($dateDue) !== '') {
			$attributes['date_due'] = $dateDue;
		}

		$result = $this->suitecrmAPIService->createRecord(
			$this->suitecrmUrl, $this->accessToken, $this->userId,
			'Tasks', $attributes,
		);

		if (isset($result['error'])) {
			return new DataResponse($result, 502);
		}
		return new DataResponse($result);
	}

}
