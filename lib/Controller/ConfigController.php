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

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCA\SuiteCRM\AppInfo\Application;

class ConfigController extends Controller {

	/**
	 * User settings keys that PersonalSettings.vue is allowed to write via
	 * setConfig(). Any other key in the request payload is silently discarded.
	 * Prevents an authenticated user from writing arbitrary rows into
	 * oc_preferences via the setConfig endpoint.
	 */
	private const USER_ALLOWED_KEYS = [
		'user_name',
		'search_enabled',
		'notification_enabled',
	];

	public function __construct(string $appName,
								IRequest $request,
								private IConfig $config,
								private IAppConfig $appConfig,
								private SuiteCRMAPIService $suitecrmAPIService,
								private TokenStorage $tokens,
								private IURLGenerator $urlGenerator,
								private IUserSession $userSession,
								private ?string $userId) {
		parent::__construct($appName, $request);
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'No user session'], 401);
		}
		foreach ($values as $key => $value) {
			if (!in_array($key, self::USER_ALLOWED_KEYS, true)) {
				continue;
			}
			// IConfig::setUserValue requires string; a bool/int in the payload
			// (Vue's NcCheckboxRadioSwitch can emit either) would TypeError on
			// NC 29+ without this cast.
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, (string) $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$accessToken = $this->tokens->getAccessToken($this->userId);
			$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
			$this->suitecrmAPIService->request(
				$suitecrmUrl, $accessToken, $this->userId, 'logout', [], 'POST'
			);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			$this->tokens->clear($this->userId);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'last_reminder_check', '');
			$result = [
				'user_name' => '',
			];
		}

		return new DataResponse($result);
	}

	/**
	 * set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$sensitive = $key === 'client_secret';
			$this->appConfig->setValueString(
				Application::APP_ID,
				$key,
				(string) $value,
				lazy: true,
				sensitive: $sensitive,
			);
		}
		return new DataResponse(1);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $login
	 * @param string $password
	 * @return DataResponse
	 * @throws \OCP\PreConditionNotMetException
	 */
	public function oauthConnect(string $login = '', string $password = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'No user session'], 401);
		}
		$suitecrmUrl = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		$clientID = $this->appConfig->getValueString(Application::APP_ID, 'client_id');
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret');

		$result = $this->suitecrmAPIService->requestOAuthAccessToken($suitecrmUrl, [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'username' => $login,
			'password' => $password,
			'grant_type' => 'password'
		], 'POST');
		if (isset($result['access_token'], $result['refresh_token'])) {
			$accessToken = $result['access_token'];
			$this->tokens->setAccessToken($this->userId, $accessToken);
			$this->tokens->setRefreshToken($this->userId, $result['refresh_token']);

			$filter = urlencode('filter[user_name][eq]') . '=' . urlencode($login);
			$info = $this->suitecrmAPIService->request(
				$suitecrmUrl, $accessToken, $this->userId, 'module/Users?' . $filter
			);
			$userName = $login;
			$userId = '';
			if (isset($info['data'])) {
				foreach ($info['data'] as $user) {
					if (isset($user['attributes'], $user['attributes']['user_name'], $user['attributes']['full_name'])
						&& $user['attributes']['user_name'] === $login) {
						$userName = $user['attributes']['full_name'];
						$userId = $user['id'];
						break;
					}
				}
			}
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $userName);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $userId);
			return new DataResponse(['user_name' => $userName]);
		} else {
			return new DataResponse(['error' => 'Invalid login/password'], 401);
		}
	}

	/**
	 * Companion info for the SuiteCRM Calendar Sync module.
	 *
	 * Returns the values the user would otherwise have to look up manually when
	 * configuring the SuiteCRM-side {@link https://github.com/njordium/suitecrm_nextcloud_calendar}
	 * Nextcloud connection: their Nextcloud base URL, login, and a deep link
	 * to the Security settings page for app-password generation.
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getCalendarCompanion(): DataResponse {
		$user = $this->userSession->getUser();
		$login = $user !== null ? $user->getUID() : ($this->userId ?? '');
		$nextcloudUrl = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		$appPasswordUrl = $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'security']);
		return new DataResponse([
			'nextcloud_url' => $nextcloudUrl,
			'login' => $login,
			'app_password_url' => $appPasswordUrl,
		]);
	}
}
