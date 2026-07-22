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
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Controller;

use OCA\SuiteCRM\Service\OAuthStateStore;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use Psr\Log\LoggerInterface;
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
                // Framing mode for the "My pipeline" widget. Validated
                // against SuiteCRMAPIService::PIPELINE_MODES on read; an
                // unknown value stored here silently falls back to the
                // default rather than crashing the widget.
                'pipeline_mode',
                // Global Quick Actions FAB opt-out. Stored as '1'/'0'
                // (IConfig::setUserValue always stringifies). When set
                // to '0' the AddQuickActionsScriptListener skips the
                // script tag entirely so opted-out users pay zero JS
                // cost per page render. Default on missing row is '1'.
                'quick_actions_enabled',
        ];

        public function __construct(string $appName,
                                                                IRequest $request,
                                                                private IConfig $config,
                                                                private IAppConfig $appConfig,
                                                                private SuiteCRMAPIService $suitecrmAPIService,
                                                                private TokenStorage $tokens,
                                                                private OAuthStateStore $stateStore,
                                                                private IURLGenerator $urlGenerator,
                                                                private IUserSession $userSession,
                                                                private LoggerInterface $logger,
                                                                private ?string $userId) {
                parent::__construct($appName, $request);
        }

        /**
         * set config values
         *
         * @param array $values
         * @return DataResponse
         */
        #[NoAdminRequired]
        #[FrontpageRoute(verb: 'PUT', url: '/config')]
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
        #[FrontpageRoute(verb: 'PUT', url: '/admin-config')]
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
         * Reset all admin configuration values. After reset the admin must
         * re-enter the SuiteCRM instance URL, client ID, and client secret
         * before any user can connect.
         *
         * Addresses upstream issue #14. Previously, an admin who picked the
         * wrong OAuth2 client type (password vs authorization code) or seeded
         * a bad client_secret had no visible affordance to start over. They
         * had to reach for `occ config:app:delete` on the shell. Now the
         * button is right in the admin settings.
         *
         * Individual user tokens are deliberately NOT cleared here. Each
         * user's next SuiteCRM request will 401 (the credentials the tokens
         * were issued against are gone) and the app's normal reconnect flow
         * will kick in. Wiping every user's token would need a
         * `callForAllUsers` loop and would silently invalidate SuiteCRM
         * sessions for users who had nothing to do with the admin config
         * mistake.
         *
         * @return DataResponse
         */
        #[FrontpageRoute(verb: 'DELETE', url: '/admin-config')]
        public function resetAdminConfig(): DataResponse {
                foreach (['oauth_instance_url', 'client_id', 'client_secret', 'oauth_authorize_path'] as $key) {
                        $this->appConfig->deleteKey(Application::APP_ID, $key);
                }
                $this->logger->info('SuiteCRM admin config reset via admin UI', ['app' => Application::APP_ID]);
                return new DataResponse(1);
        }

        /**
         * Build the SuiteCRM 8.x OAuth authorize URL and hand it back to Vue so the
         * frontend can `window.location = authorize_url`.
         *
         * The primary connect path is the RFC 6749 authorization-code flow.
         * The password grant on {@see oauthConnect()} stays as an explicit
         * "Advanced" fallback because some SuiteCRM 8.x installs are still
         * fronted by password clients and because a couple of air-gapped
         * setups can't complete a browser redirect back to Nextcloud.
         *
         * @return DataResponse
         */
        #[NoAdminRequired]
        #[FrontpageRoute(verb: 'GET', url: '/oauth-authorize-url')]
        public function oauthAuthorizeUrl(): DataResponse {
                if ($this->userId === null) {
                        return new DataResponse(['error' => 'No user session'], 401);
                }
                $suitecrmUrl = rtrim($this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url'), '/');
                $clientId = $this->appConfig->getValueString(Application::APP_ID, 'client_id');
                // Path is configurable because SuiteCRM 8.x installs disagree on where
                // the OAuth authorize endpoint sits. Verified live against 8.10.1:
                // `/Api/authorize` is the endpoint on stock 8.10.x installs; older
                // or upgraded-from-7.x installs may expose `/legacy/oauth2/authorize`.
                $authorizePath = ltrim(
                        $this->appConfig->getValueString(Application::APP_ID, 'oauth_authorize_path', '/Api/authorize'),
                        '/',
                );
                if ($suitecrmUrl === '' || $clientId === '') {
                        return new DataResponse(['error' => 'OAuth not configured'], 400);
                }
                $state = $this->stateStore->generate($this->userId);
                $redirectUri = $this->urlGenerator->linkToRouteAbsolute('njordium_suitecrm.config.oauthCallback');
                $authorizeUrl = $suitecrmUrl . '/' . $authorizePath . '?' . http_build_query([
                        'response_type' => 'code',
                        'client_id' => $clientId,
                        'redirect_uri' => $redirectUri,
                        'state' => $state,
                ]);
                return new DataResponse([
                        'authorize_url' => $authorizeUrl,
                        'state' => $state,
                ]);
        }

        /**
         * Redirect target for the OAuth 2.0 authorization-code flow. SuiteCRM
         * bounces the user back here after they approve the app on the SuiteCRM
         * consent screen.
         *
         * NoCSRFRequired: the browser arrives here from an external redirect, so
         * there's no requesttoken to send. The `state` param, verified against
         * the per-user store below, is the CSRF defence for this endpoint.
         *
         * @return RedirectResponse
         */
        #[NoAdminRequired]
        #[NoCSRFRequired]
        #[FrontpageRoute(verb: 'GET', url: '/oauth-callback')]
        public function oauthCallback(string $code = '', string $state = ''): RedirectResponse {
                if ($this->userId === null) {
                        return $this->redirectWithError('Not authenticated');
                }
                if ($code === '' || $state === '') {
                        // User aborted the SuiteCRM consent screen (Cancel button, tab
                        // close, network drop). The pending state row would otherwise
                        // sit valid in the store for ~10min until TTL expiry; clear it
                        // now so it can't be reused.
                        $this->stateStore->clear($this->userId);
                        return $this->redirectWithError('Missing code or state');
                }
                if (!$this->stateStore->verify($this->userId, $state)) {
                        // Always clear on failure so a leaked/guessed state can't be reused.
                        $this->stateStore->clear($this->userId);
                        return $this->redirectWithError('Invalid or expired OAuth state');
                }
                // One-shot: consume the state whether or not the token exchange succeeds.
                $this->stateStore->clear($this->userId);

                $suitecrmUrl = rtrim($this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url'), '/');
                $clientId = $this->appConfig->getValueString(Application::APP_ID, 'client_id');
                $clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret');
                $redirectUri = $this->urlGenerator->linkToRouteAbsolute('njordium_suitecrm.config.oauthCallback');

                $result = $this->suitecrmAPIService->requestOAuthAccessToken($suitecrmUrl, [
                        'grant_type' => 'authorization_code',
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'code' => $code,
                        'redirect_uri' => $redirectUri,
                ], 'POST');

                // The previous try/catch that lived here was dead code:
                // requestOAuthAccessToken() catches every Throwable and returns
                // ['error' => ...], so no exception ever crosses the method
                // boundary. The three actionable catch branches
                // (LocalServerException, 401/invalid_client, generic
                // ClientException) never ran; the small ['error' => msg]
                // fallback did, producing a raw guzzle message with no admin
                // guidance. The service's error return shape now carries
                // http_status / error_code / error_description / error_kind
                // so this call site can produce the same admin-friendly
                // messages from the returned array (this time reachable).
                if (!isset($result['access_token'], $result['refresh_token'])) {
                        $errorKind = (string) ($result['error_kind'] ?? '');
                        $httpStatus = (int) ($result['http_status'] ?? 0);
                        $errCode = (string) ($result['error_code'] ?? '');
                        $errDesc = (string) ($result['error_description'] ?? '');

                        $this->logger->error('SuiteCRM OAuth exchange failed', [
                                'app' => Application::APP_ID,
                                'error_kind' => $errorKind,
                                'http_status' => $httpStatus,
                                'error_code' => $errCode,
                                'error_description' => $errDesc,
                                'raw' => (string) ($result['error'] ?? ''),
                        ]);

                        if ($errorKind === 'local_server_blocked') {
                                return $this->redirectWithError('Nextcloud refused to reach your SuiteCRM instance because its address is on a local network. An administrator can allow this with: occ config:system:set allow_local_remote_servers --value=true --type=boolean');
                        }
                        if ($httpStatus === 401 && $errCode === 'invalid_client') {
                                return $this->redirectWithError('SuiteCRM rejected the client credentials. Two common causes: (1) the redirect URI in SuiteCRM does not match this Nextcloud URL byte-for-byte (check http vs https, port, and trailing slash); (2) the client was seeded via SQL with bcrypt but SuiteCRM 8.10.x expects SHA-256. Recreate the OAuth2 client via the SuiteCRM admin UI to avoid the algorithm mismatch.');
                        }
                        if ($httpStatus > 0) {
                                return $this->redirectWithError('SuiteCRM OAuth exchange failed: ' . ($errDesc !== '' ? $errDesc : ($errCode !== '' ? $errCode : 'HTTP ' . $httpStatus)));
                        }
                        // http_status === 0 means no HTTP response arrived
                        // (DNS failure, TCP refused, TLS handshake, timeout).
                        // Guide the admin to check the instance URL rather
                        // than dumping the raw guzzle message which is often
                        // opaque noise like "cURL error 6: Could not resolve".
                        return $this->redirectWithError('Cannot reach SuiteCRM at the configured instance URL. Check the admin config and confirm the URL is spelled correctly and the host is reachable from this Nextcloud server.');
                }
                $this->tokens->setAccessToken($this->userId, $result['access_token']);
                $this->tokens->setRefreshToken($this->userId, $result['refresh_token']);

                // The previous implementation called `V8/me`, which is not
                // part of SuiteCRM 8's documented API surface and 404s on
                // stock installs. That meant the auth-code path stored
                // user_name='connected' and, worse, never populated `user_id`,
                // so dashboards that filter Meetings/Calls/Tasks by
                // `assigned_user_id` silently returned empty for every
                // OAuth-connected user.
                //
                // Fix: pull the SuiteCRM user id from the JWT `sub` claim and
                // fetch `module/Users/{sub}` directly. Both `user_name` and
                // `user_id` are now populated on connect.
                $sub = $this->decodeJwtSub($result['access_token']);
                $userName = 'connected';
                $scrmUserId = '';
                if ($sub !== null) {
                        try {
                                $userResponse = $this->suitecrmAPIService->request(
                                        $suitecrmUrl, $result['access_token'], $this->userId,
                                        'module/Users/' . $sub . '?fields[Users]=user_name,full_name'
                                );
                                if (isset($userResponse['data'])) {
                                        $attrs = $userResponse['data']['attributes'] ?? [];
                                        $userName = $attrs['user_name'] ?? $attrs['full_name'] ?? 'connected';
                                        $scrmUserId = $userResponse['data']['id'] ?? '';
                                }
                        } catch (\Throwable $e) {
                                // Keep the fallback values; tokens are stored, user_name
                                // defaults to 'connected'. The user can still connect; the
                                // dashboards will be empty until the next login refreshes
                                // the whoami lookup.
                        }
                }
                $this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', (string) $userName);
                $this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', (string) $scrmUserId);

                $successUrl = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
                        . '?suitecrmToken=success';
                return new RedirectResponse($successUrl);
        }

        /**
         * Decode the JWT payload of a SuiteCRM access token and return its `sub`
         * claim (the SuiteCRM user GUID) if present.
         *
         * Deliberately does NOT verify the JWT signature: we just received this
         * token in the token-exchange response from SuiteCRM itself over TLS,
         * so the source is already authenticated at the transport layer. We
         * only need to read the `sub` claim; if it's wrong the follow-up
         * `module/Users/{sub}` fetch will 404 and we fall through to the
         * default label.
         *
         * @param string $token
         * @return string|null the `sub` claim, or null if the token isn't a JWT
         *                     or the sub is missing / malformed
         */
        private function decodeJwtSub(string $token): ?string {
                $parts = explode('.', $token);
                if (count($parts) !== 3) {
                        return null;
                }
                $payload = strtr($parts[1], '-_', '+/');
                $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
                $decoded = base64_decode($payload, true);
                if ($decoded === false) {
                        return null;
                }
                $json = json_decode($decoded, true);
                if (!is_array($json) || !isset($json['sub']) || !is_string($json['sub'])) {
                        return null;
                }
                // Guard: reject anything that doesn't look like a UUID-ish sub so
                // we can't be talked into requesting an arbitrary path via the API
                // service. SuiteCRM's user ids are UUID-shaped.
                if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $json['sub'])) {
                        return null;
                }
                return $json['sub'];
        }

        /**
         * Redirect back to Personal Settings with an error banner. Consolidates
         * the boilerplate for every early-exit path in {@see oauthCallback()}.
         */
        private function redirectWithError(string $message): RedirectResponse {
                $url = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
                        . '?' . http_build_query([
                                'suitecrmToken' => 'error',
                                'message' => $message,
                        ]);
                return new RedirectResponse($url);
        }

        /**
         * @param string $login
         * @param string $password
         * @return DataResponse
         * @throws \OCP\PreConditionNotMetException
         */
        #[NoAdminRequired]
        #[FrontpageRoute(verb: 'POST', url: '/oauth-connect')]
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
         * @return DataResponse
         */
        #[NoAdminRequired]
        #[FrontpageRoute(verb: 'GET', url: '/calendar-companion')]
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