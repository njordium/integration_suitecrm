<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\SuiteCRM\Controller;

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

	/** @var IConfig */
	private $config;
	/** @var SuiteCRMAPIService */
	private $suitecrmAPIService;
	/** @var TokenStorage */
	private $tokens;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IUserSession */
	private $userSession;
	/** @var string|null */
	private $userId;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								SuiteCRMAPIService $suitecrmAPIService,
								TokenStorage $tokens,
								IURLGenerator $urlGenerator,
								IUserSession $userSession,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->suitecrmAPIService = $suitecrmAPIService;
		$this->tokens = $tokens;
		$this->urlGenerator = $urlGenerator;
		$this->userSession = $userSession;
		$this->userId = $userId;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$accessToken = $this->tokens->getAccessToken($this->userId);
			$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
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
			$this->config->setAppValue(Application::APP_ID, $key, $value);
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
		$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

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
