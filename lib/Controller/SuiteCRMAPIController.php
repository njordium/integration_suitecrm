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
		// Gated behind a live OAuth token so the admin-configured
		// SuiteCRM hostname is not enumerable by every NC user on the
		// tenant. Non-connected users get an empty string, which the
		// PersonalSettings.vue "Instance URL" link check treats as
		// "not connected" (falsy hides the link).
		if ($this->accessToken === '') {
			return new DataResponse('');
		}
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
		$bytes = $this->suitecrmAPIService->getSuiteCRMAvatar(
			$this->suitecrmUrl, $this->accessToken, $suiteUserId
		);
		// Pin the Content-Type to a safe image mime rather than the
		// DataDisplayResponse default (`application/octet-stream`).
		// The response is served with a 24-hour browser cache and is
		// #[NoCSRFRequired] so it can be embedded in <img> tags, which
		// means an attacker who could return an HTML or SVG-with-script
		// blob upstream would get their payload cached by every viewer
		// under the Nextcloud origin. Byte-sniffing gives us a mime we
		// can trust; anything that does not sniff as a real image falls
		// back to `image/png` with an empty body so a compromised
		// upstream cannot smuggle text/html.
		$mime = $this->sniffImageMime($bytes);
		if ($mime === null) {
			$bytes = '';
			$mime = 'image/png';
		}
		$response = new DataDisplayResponse($bytes, 200, ['Content-Type' => $mime]);
		$response->cacheFor(60*60*24);
		return $response;
	}

	/**
	 * Clamp a widget-supplied `limit` into a sane range before it is
	 * passed to the SuiteCRM API. The UI picker exposes 5–50 (see
	 * SuiteCRMWidgetShell.vue `maxItemsOptions`), so 100 is the
	 * defence-in-depth ceiling that a tampered client would hit —
	 * enough headroom to allow the largest picker option, small enough
	 * that a malicious user can't ask the widget endpoint for tens of
	 * thousands of rows in a single call.
	 */
	private function clampLimit(int $limit, int $default = 20): int {
		if ($limit <= 0) {
			return $default;
		}
		return min(100, $limit);
	}

	/**
	 * Return a whitelisted `image/*` mime type by inspecting the first
	 * few bytes of the payload, or null if the bytes do not look like
	 * any of the supported image types. Deliberately narrow — SuiteCRM
	 * profile photos are always JPEG/PNG/GIF/WebP in practice, and
	 * SVG is excluded on purpose because it can carry <script>.
	 */
	private function sniffImageMime(string $bytes): ?string {
		if (strlen($bytes) < 12) {
			return null;
		}
		if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
			return 'image/jpeg';
		}
		if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
			return 'image/png';
		}
		if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
			return 'image/gif';
		}
		if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
			return 'image/webp';
		}
		return null;
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
		if ($limit !== null) {
			$limit = $this->clampLimit($limit);
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
	public function getUpcoming(int $horizonDays = 7, int $limit = 20, bool $onlyMine = true): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getUpcoming(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $horizonDays, $limit, 30, $onlyMine
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
	public function getMyPipeline(string $mode = 'closing_quarter', int $limit = 20, bool $onlyMine = true): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getMyPipeline(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $mode, $limit, $onlyMine
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
	public function getMyTasks(int $limit = 20, bool $onlyMine = true): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getMyTasks(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit, $onlyMine
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
	public function getMyCases(int $limit = 20, bool $onlyMine = true): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getMyCases(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit, $onlyMine
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
	public function getRecentActivities(int $limit = 20, bool $onlyMine = false): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getRecentActivities(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit, 30, $onlyMine
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
	public function getRecentContacts(int $limit = 20, bool $onlyMine = false): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getRecentContacts(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit, 90, $onlyMine
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * "SuiteCRM Accounts" widget backing endpoint. Same 400/401/200
	 * contract as the other widget endpoints.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/recent-accounts')]
	public function getRecentAccounts(int $limit = 20, bool $onlyMine = false): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getRecentAccounts(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit, 90, $onlyMine
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}

	/**
	 * "SuiteCRM Leads" widget backing endpoint. Same 400/401/200
	 * contract as the other widget endpoints.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/recent-leads')]
	public function getRecentLeads(int $limit = 20, bool $onlyMine = false): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$limit = $this->clampLimit($limit);
		$result = $this->suitecrmAPIService->getRecentLeads(
			$this->suitecrmUrl, $this->accessToken, $this->userId, $limit, 90, $onlyMine
		);
		if (!isset($result['error'])) {
			return new DataResponse($result);
		}
		return new DataResponse($result, 401);
	}
}
