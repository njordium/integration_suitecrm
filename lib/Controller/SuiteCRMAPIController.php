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
	 * "My pipeline" widget backing endpoint.
	 *
	 * Returns Opportunities assigned to the current user, framed by
	 * the requested `mode` (closing_quarter | top_value | weighted).
	 * An unknown mode falls back silently to the default rather than
	 * 400ing. The widget's Vue frontend or the personal-settings
	 * NcSelect might send an outdated value during rollout, and the
	 * widget should still render.
	 *
	 * @param string $mode See SuiteCRMAPIService::PIPELINE_MODES.
	 * @param int    $limit Cap on total results.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/my-pipeline')]
	public function getMyPipeline(string $mode = 'closing_quarter', int $limit = 20): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getMyPipeline(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $mode, $limit
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * "My open Tasks" widget backing endpoint.
	 *
	 * Returns Tasks assigned to the current user whose status is not
	 * terminal (Completed / Deferred), priority-sorted with due date
	 * as tiebreaker and undated Tasks sorted last within a priority
	 * tier. Distinct from `/upcoming`, which drops Tasks outside the
	 * schedule window and undated Tasks entirely.
	 *
	 * @param int $limit Cap on total results.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/my-tasks')]
	public function getMyTasks(int $limit = 20): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getMyTasks(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * "My open Cases" widget backing endpoint.
	 *
	 * Returns Cases assigned to the current user where status is not
	 * in the terminal set (Closed / Rejected / Duplicate), priority-
	 * sorted then oldest-first within priority. Shape matches the
	 * frontend contract used by the Vue widget in `src/views/Cases.vue`.
	 *
	 * @param int $limit Cap on total results (default matches getUpcoming).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/my-cases')]
	public function getMyCases(int $limit = 20): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getMyCases(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * "SuiteCRM Activities" widget backing endpoint.
	 *
	 * Recently-modified Calls, Meetings, Tasks, and Notes across the
	 * tenant, subject to SuiteCRM ACL against the current user's OAuth
	 * token. Same 400-when-no-token / 401-on-upstream-error / 200-on-ok
	 * shape as {@see getUpcoming()} so the Vue widget can reuse the
	 * error handling.
	 *
	 * @param int $limit Cap on total merged results.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/recent-activities')]
	public function getRecentActivities(int $limit = 20): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getRecentActivities(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * "SuiteCRM Contacts" widget backing endpoint.
	 *
	 * Most recently added Contacts visible to the current user. Same
	 * 400/401/200 contract as the other widget endpoints. The lookback
	 * window and sort key live in the service; the controller is a
	 * thin auth + delegation layer.
	 *
	 * @param int $limit Cap on total results.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/recent-contacts')]
	public function getRecentContacts(int $limit = 20): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getRecentContacts(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * Follow-up Task creation endpoint.
	 *
	 * Create a follow-up SuiteCRM Task linked back to a source record
	 * (a Meeting, Call, existing Task, Contact, Account, Lead,
	 * Opportunity, or Case). Triggered from the "Create follow-up Task"
	 * action on entries in the SuiteCRM events + calendar dashboard
	 * widgets, so the user can turn "just had this meeting" into "must
	 * do this by Friday" without leaving Nextcloud.
	 *
	 * The link is stored via SuiteCRM's flat `parent_type` / `parent_id`
	 * pair on the Task record: one round trip, no separate relationship
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
	 * @param string $name          Required. New Task's name
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
		// Anything else silently degrades to Medium on the SuiteCRM side;
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

	/**
	 * Generic Note-creation endpoint.
	 *
	 * Creates a SuiteCRM Note record attached to any allowed parent
	 * (Contacts, Accounts, Leads, Opportunities, Cases, Meetings,
	 * Calls, Tasks). Intended as the primitive that later features
	 * compose:
	 *
	 *  - Talk conversation to Note: the frontend fetches the Talk convo,
	 *    formats the transcript as markdown, and POSTs here.
	 *  - Deck card linked to Opportunity: both sides get a Note referring
	 *    to each other; the SuiteCRM side goes through here.
	 *  - Email to Case: Case creation goes through a separate endpoint,
	 *    but the "log source email as Note on the Case" step could reuse
	 *    this endpoint.
	 *
	 * Keeping the endpoint generic avoids duplicating the auth guard,
	 * whitelist, and error propagation across three near-identical
	 * flows.
	 *
	 * @param string $targetModule  SuiteCRM module the Note attaches to
	 * @param string $targetId      Parent record id (UUID)
	 * @param string $name          Required. Note title
	 * @param string $description   Free-text body, may be markdown
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/log-note')]
	public function logNote(
		string $targetModule,
		string $targetId,
		string $name,
		string $description = '',
	): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse(['error' => 'not connected'], 401);
		}
		if (trim($name) === '') {
			return new DataResponse(['error' => 'name is required'], 400);
		}
		if ($targetId === '') {
			return new DataResponse(['error' => 'targetId is required'], 400);
		}

		// Notes in SuiteCRM 8.x can attach to essentially any bean via
		// parent_type/parent_id, but restricting to the modules the
		// fork actually integrates with keeps error surfaces small and
		// avoids exposing our endpoint as a generic write channel to
		// any Sugar bean an attacker could name.
		$allowedTargets = [
			'Contacts', 'Accounts', 'Leads',
			'Opportunities', 'Cases',
			'Meetings', 'Calls', 'Tasks',
		];
		if (!in_array($targetModule, $allowedTargets, true)) {
			return new DataResponse([
				'error' => sprintf('target module "%s" is not allowed for Note attachment', $targetModule),
				'allowed' => $allowedTargets,
			], 400);
		}

		$attributes = [
			'name' => trim($name),
			'description' => $description,
			'parent_type' => $targetModule,
			'parent_id' => $targetId,
		];

		$result = $this->suitecrmAPIService->createRecord(
			$this->suitecrmUrl, $this->accessToken, $this->userId,
			'Notes', $attributes,
		);

		if (isset($result['error'])) {
			return new DataResponse($result, 502);
		}
		return new DataResponse($result);
	}

	/**
	 * Deck card to SuiteCRM record link (SuiteCRM side).
	 *
	 * Creates a Note on the target SuiteCRM record that points back at
	 * a Nextcloud Deck card. The Deck side (a comment on the card
	 * pointing at the SuiteCRM record) is handled by the frontend
	 * because it can hit NC Deck's OCS API directly with the user's
	 * session, so no server-side cross-app coupling is needed.
	 *
	 * Architecturally close to {@see logNote()}: both endpoints
	 * ultimately call {@see SuiteCRMAPIService::createRecord()} with a
	 * Notes payload. The reason for a dedicated endpoint:
	 *
	 *  1. Consistent Note-body formatting. Every Deck-linked Note
	 *     reads "Linked from Nextcloud Deck card '<title>' at <url>",
	 *     so a SuiteCRM user searching or filtering for "Nextcloud
	 *     Deck card" gets a clean predictable hit set.
	 *  2. URL validation lives here, not scattered in every caller.
	 *     A Deck card URL that isn't at least a plausible URL means
	 *     the caller's UI is buggy and we should refuse instead of
	 *     dropping garbage into SuiteCRM.
	 *  3. Testable domain logic: the body-format contract has its
	 *     own tests.
	 *
	 * @param string $deckCardUrl    Fully-qualified URL of the Deck card
	 * @param string $deckCardTitle  Human-readable card title (for Note body)
	 * @param string $targetModule   SuiteCRM module the Note attaches to
	 * @param string $targetId       Parent record id
	 * @param string $extraNote      Optional free-text appended below the link
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/link-deck-card')]
	public function linkDeckCard(
		string $deckCardUrl,
		string $deckCardTitle,
		string $targetModule,
		string $targetId,
		string $extraNote = '',
	): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse(['error' => 'not connected'], 401);
		}
		if ($targetId === '') {
			return new DataResponse(['error' => 'targetId is required'], 400);
		}
		if (trim($deckCardUrl) === '') {
			return new DataResponse(['error' => 'deckCardUrl is required'], 400);
		}
		// Cheap URL sanity: filter_var catches the common mistakes
		// (leading whitespace, missing scheme, `//host/path` shortcuts)
		// without pulling in a full URL parser. We're not trying to
		// verify the Deck card actually exists; that's the frontend's
		// job because only the frontend has session-level access to
		// Deck's API.
		if (filter_var(trim($deckCardUrl), FILTER_VALIDATE_URL) === false) {
			return new DataResponse(['error' => 'deckCardUrl is not a valid URL'], 400);
		}

		// Same whitelist as logNote: any of the eight modules the fork
		// integrates with can be the SuiteCRM side of a Deck link.
		$allowedTargets = [
			'Contacts', 'Accounts', 'Leads',
			'Opportunities', 'Cases',
			'Meetings', 'Calls', 'Tasks',
		];
		if (!in_array($targetModule, $allowedTargets, true)) {
			return new DataResponse([
				'error' => sprintf('target module "%s" is not allowed', $targetModule),
				'allowed' => $allowedTargets,
			], 400);
		}

		// Compose the Note body. Format is deliberately stable so that
		// a SuiteCRM search or export can identify Deck-sourced notes.
		$titleLabel = trim($deckCardTitle) !== '' ? trim($deckCardTitle) : $deckCardUrl;
		$body = sprintf(
			"Linked from Nextcloud Deck card \"%s\"\nURL: %s",
			$titleLabel,
			trim($deckCardUrl),
		);
		if (trim($extraNote) !== '') {
			$body .= "\n\n" . $extraNote;
		}

		$attributes = [
			'name' => sprintf('Deck link: %s', $titleLabel),
			'description' => $body,
			'parent_type' => $targetModule,
			'parent_id' => $targetId,
		];

		$result = $this->suitecrmAPIService->createRecord(
			$this->suitecrmUrl, $this->accessToken, $this->userId,
			'Notes', $attributes,
		);

		if (isset($result['error'])) {
			return new DataResponse($result, 502);
		}
		return new DataResponse($result);
	}

	/**
	 * Email to SuiteCRM Case.
	 *
	 * Turns an inbound (or otherwise selected) email into a SuiteCRM
	 * Case. The frontend responsibility is either the NC Mail
	 * integration hook or a plain paste-form; either way the backend
	 * receives the same shape: subject + body + optional sender
	 * metadata + priority. This endpoint composes them into a Case.
	 *
	 * The body format is stable and searchable:
	 *
	 *   From: <sender name> <email@address>
	 *   Date: <email date>
	 *
	 *   <email body>
	 *
	 * Only the lines the caller supplied appear (no empty "From:"
	 * header if senderEmail was omitted). That keeps the composed body
	 * clean when the frontend can only extract partial metadata (e.g.
	 * paste-form fallback where the user only copies the message
	 * text).
	 *
	 * Contact / Account linking (matching sender email to an existing
	 * SuiteCRM Contact) is deferred because the lookup belongs in the
	 * frontend, which already has the SuiteCRMRecordPicker infrastructure.
	 * The frontend can call this endpoint to create the Case, then call
	 * SuiteCRMAPIService::linkRecord() (exposed by a future endpoint)
	 * to attach the Contact. Keeping the two operations separate keeps
	 * the endpoint composable and the tests focused.
	 *
	 * @param string $subject      Required. Becomes Case.name
	 * @param string $body         Required. Becomes the Case.description body
	 * @param string $senderEmail  Optional. Displayed in the "From:" header
	 * @param string $senderName   Optional. Displayed alongside senderEmail
	 * @param string $emailDate    Optional. Displayed in the "Date:" header
	 * @param string $priority     'High' | 'Medium' | 'Low'
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/email-to-case')]
	public function emailToCase(
		string $subject,
		string $body,
		string $senderEmail = '',
		string $senderName = '',
		string $emailDate = '',
		string $priority = 'Medium',
	): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse(['error' => 'not connected'], 401);
		}
		if (trim($subject) === '') {
			return new DataResponse(['error' => 'subject is required'], 400);
		}
		if (trim($body) === '') {
			return new DataResponse(['error' => 'body is required'], 400);
		}
		if (!in_array($priority, ['High', 'Medium', 'Low'], true)) {
			return new DataResponse(['error' => 'priority must be one of High / Medium / Low'], 400);
		}

		// Compose a stable body; only include headers the caller
		// actually filled in.
		$headerLines = [];
		if (trim($senderEmail) !== '' || trim($senderName) !== '') {
			$fromValue = trim($senderName) !== '' && trim($senderEmail) !== ''
				? sprintf('%s <%s>', trim($senderName), trim($senderEmail))
				: (trim($senderEmail) !== '' ? trim($senderEmail) : trim($senderName));
			$headerLines[] = 'From: ' . $fromValue;
		}
		if (trim($emailDate) !== '') {
			$headerLines[] = 'Date: ' . trim($emailDate);
		}

		$description = $headerLines === []
			? $body
			: implode("\n", $headerLines) . "\n\n" . $body;

		$attributes = [
			'name' => trim($subject),
			'description' => $description,
			'priority' => $priority,
			// SuiteCRM 8.x Case default state; kept explicit so a
			// future SuiteCRM default change doesn't silently move
			// email-sourced Cases into a different queue.
			'status' => 'New',
		];

		$result = $this->suitecrmAPIService->createRecord(
			$this->suitecrmUrl, $this->accessToken, $this->userId,
			'Cases', $attributes,
		);

		if (isset($result['error'])) {
			return new DataResponse($result, 502);
		}
		return new DataResponse($result);
	}

}
