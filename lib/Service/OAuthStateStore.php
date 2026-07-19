<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Kim Haverblad
 * @copyright Kim Haverblad 2026
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Service;

use OCP\IConfig;
use OCP\Security\ISecureRandom;

use OCA\SuiteCRM\AppInfo\Application;

/**
 * Manages the OAuth 2.0 authorization-code `state` parameter lifecycle.
 *
 * The `state` param is the standard RFC 6749 §10.12 defence against
 * cross-site request forgery on the OAuth redirect: the server generates
 * a high-entropy random string, sends it to the authorization server, and
 * refuses to accept a callback whose `state` doesn't match what was issued.
 *
 * We back it with per-user preferences (not a session) so a callback that
 * arrives in a fresh browser tab / after a redirect chain still verifies.
 * A 10-minute expiry bounds replay-attack windows and prevents stale states
 * from lingering forever when a user abandons the flow midway.
 */
class OAuthStateStore {

	private const STATE_TTL_SECONDS = 600;

	public function __construct(
		private IConfig $config,
		private ISecureRandom $secureRandom,
	) {
	}

	/**
	 * Generate a fresh state token, persist it against $userId, and return it
	 * for embedding in the outgoing authorize URL.
	 */
	public function generate(string $userId): string {
		$state = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->config->setUserValue($userId, Application::APP_ID, 'oauth_state', $state);
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			'oauth_state_expiry',
			(string) (time() + self::STATE_TTL_SECONDS),
		);
		return $state;
	}

	/**
	 * Constant-time compare the incoming state against the stored one and
	 * confirm it hasn't expired. Returns false for any tampering, mismatch,
	 * missing value, or expiry so callers can uniformly redirect with error.
	 */
	public function verify(string $userId, string $incomingState): bool {
		$stored = $this->config->getUserValue($userId, Application::APP_ID, 'oauth_state', '');
		$expiry = (int) $this->config->getUserValue($userId, Application::APP_ID, 'oauth_state_expiry', '0');
		if ($stored === '' || $incomingState === '' || !hash_equals($stored, $incomingState)) {
			return false;
		}
		if (time() > $expiry) {
			return false;
		}
		return true;
	}

	/**
	 * Wipe the stored state. Always call this after verify() (whether the
	 * outcome was success or failure) so a single state token can't be reused.
	 */
	public function clear(string $userId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state_expiry');
	}
}
