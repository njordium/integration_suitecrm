<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Service;

use Exception;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCA\SuiteCRM\AppInfo\Application;

/**
 * Centralised storage for OAuth tokens.
 *
 * Encrypts tokens at rest using {@see ICrypto}. Reads transparently fall back
 * to plaintext (and re-encrypt) for installs upgraded from < 1.2.0.
 */
class TokenStorage {

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
	) {
	}

	public function getAccessToken(string $userId): string {
		return $this->readSecret($userId, 'token');
	}

	public function getRefreshToken(string $userId): string {
		return $this->readSecret($userId, 'refresh_token');
	}

	public function setAccessToken(string $userId, string $token): void {
		$this->writeSecret($userId, 'token', $token);
	}

	public function setRefreshToken(string $userId, string $token): void {
		$this->writeSecret($userId, 'refresh_token', $token);
	}

	public function clear(string $userId): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'token', '');
		$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', '');
	}

	private function readSecret(string $userId, string $key): string {
		$stored = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (Exception) {
			// Legacy plaintext from < 1.2.0 — migrate on first read so subsequent
			// reads take the encrypted path.
			$this->writeSecret($userId, $key, $stored);
			return $stored;
		}
	}

	private function writeSecret(string $userId, string $key, string $token): void {
		if ($token === '') {
			$this->config->setUserValue($userId, Application::APP_ID, $key, '');
			return;
		}
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			$key,
			$this->crypto->encrypt($token),
		);
	}
}
