<?php
declare(strict_types=1);
/**
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Command;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use OCA\SuiteCRM\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ integration_suitecrm:test-connection`
 *
 * One-shot diagnostic that walks admins through every layer that has
 * bitten users during setup. Each check prints PASS / FAIL / WARN with
 * the exact `occ` command or config change that would fix it. Runs
 * without touching stored user tokens — safe to invoke at any time.
 */
class TestConnection extends Command {

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IClientService $clientService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_suitecrm:test-connection')
			->setDescription('Verify Nextcloud can reach the configured SuiteCRM instance and OAuth endpoints');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$anyFail = false;

		$output->writeln('<info>SuiteCRM integration — connection diagnostic</info>');
		$output->writeln('');

		// -----------------------------------------------------------------
		// 1. Admin config present
		// -----------------------------------------------------------------
		$url = $this->appConfig->getValueString(Application::APP_ID, 'oauth_instance_url');
		$clientId = $this->appConfig->getValueString(Application::APP_ID, 'client_id');
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, 'client_secret');
		$authorizePath = $this->appConfig->getValueString(Application::APP_ID, 'oauth_authorize_path', '/Api/authorize');

		if ($url === '') {
			$this->fail($output, 'Admin config: oauth_instance_url is empty', [
				'Set it via: occ config:app:set integration_suitecrm oauth_instance_url --value="https://your-suitecrm.example.com"',
				'Or via Settings → Administration → Connected accounts → SuiteCRM integration',
			]);
			return Command::FAILURE;
		}
		$this->pass($output, sprintf('Admin config: oauth_instance_url = %s', $url));

		if ($clientId === '') {
			$this->fail($output, 'Admin config: client_id is empty', [
				'Set via admin UI or: occ config:app:set integration_suitecrm client_id --value="<your-client-id>"',
			]);
			$anyFail = true;
		} else {
			$this->pass($output, sprintf('Admin config: client_id = %s', $clientId));
		}

		if ($clientSecret === '') {
			$this->fail($output, 'Admin config: client_secret is empty', [
				'Set via admin UI (SecretField hides the value from the browser after save)',
			]);
			$anyFail = true;
		} else {
			$this->pass($output, 'Admin config: client_secret is set (hidden)');
		}

		$this->pass($output, sprintf('Admin config: oauth_authorize_path = %s', $authorizePath));

		// -----------------------------------------------------------------
		// 2. Local-address whitelist (does the URL live on a private range?)
		// -----------------------------------------------------------------
		$host = parse_url($url, PHP_URL_HOST) ?: '';
		$allowLocal = $this->config->getSystemValueBool('allow_local_remote_servers', false);
		$isPrivate = $this->isPrivateAddress($host);

		if ($isPrivate && !$allowLocal) {
			$this->warn($output, sprintf(
				'SSRF guard: host "%s" looks like an RFC-1918 / loopback address but allow_local_remote_servers is FALSE',
				$host
			), [
				'The OAuth token exchange will fail with "Host violates local access rules".',
				'Fix: occ config:system:set allow_local_remote_servers --value=true --type=boolean',
			]);
			$anyFail = true;
		} else if ($isPrivate) {
			$this->pass($output, sprintf('SSRF guard: host "%s" is private but allow_local_remote_servers=true', $host));
		} else {
			$this->pass($output, sprintf('SSRF guard: host "%s" is public — no whitelist needed', $host));
		}

		// -----------------------------------------------------------------
		// 3. TCP + HTTP reachability
		// -----------------------------------------------------------------
		$client = $this->clientService->newClient();
		$baseOpts = [
			'timeout' => 8,
			'connect_timeout' => 5,
			'headers' => ['User-Agent' => 'Nextcloud SuiteCRM integration (test-connection)'],
			'nextcloud' => ['allow_local_address' => true],
			'http_errors' => false,
		];

		try {
			$response = $client->get(rtrim($url, '/') . '/', $baseOpts);
			$status = $response->getStatusCode();
			$this->pass($output, sprintf('HTTP reachability: %s → HTTP %d', $url, $status));
		} catch (LocalServerException $e) {
			$this->fail($output, sprintf('HTTP reachability: BLOCKED by allow_local_remote_servers = false'), [
				'occ config:system:set allow_local_remote_servers --value=true --type=boolean',
			]);
			return Command::FAILURE;
		} catch (ConnectException $e) {
			$this->fail($output, sprintf('HTTP reachability: cannot connect to %s (%s)', $url, $e->getMessage()), [
				'DNS resolves? Firewall open? Is the SuiteCRM container running?',
				sprintf('From inside the Nextcloud container, try: wget --tries=1 --timeout=5 %s', $url),
			]);
			return Command::FAILURE;
		} catch (Throwable $e) {
			$this->fail($output, sprintf('HTTP reachability: %s', $e->getMessage()));
			$anyFail = true;
		}

		// -----------------------------------------------------------------
		// 4. Authorize endpoint (should return 302 with Location containing
		//    an OAuth authorize page, OR 200 with a login form).
		// -----------------------------------------------------------------
		$authUrl = rtrim($url, '/') . $authorizePath
			. '?response_type=code&client_id=' . rawurlencode($clientId ?: 'test')
			. '&redirect_uri=' . rawurlencode('https://example.invalid/callback')
			. '&state=' . bin2hex(random_bytes(8));

		try {
			$response = $client->get($authUrl, $baseOpts + ['allow_redirects' => false]);
			$status = $response->getStatusCode();
			if ($status === 302 || $status === 303 || $status === 200) {
				$this->pass($output, sprintf('Authorize endpoint (%s): HTTP %d (OK)', $authorizePath, $status));
			} elseif ($status === 404) {
				$this->fail($output, sprintf('Authorize endpoint (%s): HTTP 404', $authorizePath), [
					'This path is not exposed by your SuiteCRM build.',
					'For SuiteCRM 8.10.x fresh installs: /Api/authorize',
					'For 8.x upgraded from 7.x or older: /legacy/oauth2/authorize',
					sprintf('Change with: occ config:app:set integration_suitecrm oauth_authorize_path --value="/legacy/oauth2/authorize"'),
				]);
				$anyFail = true;
			} else {
				$this->warn($output, sprintf('Authorize endpoint (%s): unexpected HTTP %d', $authorizePath, $status));
			}
		} catch (Throwable $e) {
			$this->warn($output, sprintf('Authorize endpoint (%s): %s', $authorizePath, $e->getMessage()));
			$anyFail = true;
		}

		// -----------------------------------------------------------------
		// 5. Token endpoint existence — POST with intentionally-bad grant.
		//    A 400 { unsupported_grant_type } proves the endpoint is alive
		//    and correctly parses OAuth2 errors; a 404 means the path is
		//    wrong or SuiteCRM's Api isn't enabled at all.
		// -----------------------------------------------------------------
		$tokenUrl = rtrim($url, '/') . '/Api/access_token';
		try {
			$response = $client->post($tokenUrl, $baseOpts + [
				'form_params' => ['grant_type' => 'diagnostic_check_not_real'],
			]);
			$status = $response->getStatusCode();
			$body = (string) $response->getBody();
			if ($status === 400 || $status === 401 || $status === 403) {
				$decoded = json_decode($body, true);
				$err = $decoded['error'] ?? '(no error field)';
				$this->pass($output, sprintf('Token endpoint (/Api/access_token): HTTP %d with error="%s" (OK)', $status, $err));
			} elseif ($status === 404) {
				$this->fail($output, 'Token endpoint (/Api/access_token): HTTP 404', [
					'SuiteCRM 8.x API is not exposed at /Api/. Are the OpenSSL keys generated?',
					'Check: Api/V8/OAuth2/{private,public}.key must exist inside the SuiteCRM install',
				]);
				$anyFail = true;
			} else {
				$this->warn($output, sprintf('Token endpoint: unexpected HTTP %d', $status));
			}
		} catch (Throwable $e) {
			$this->warn($output, sprintf('Token endpoint: %s', $e->getMessage()));
			$anyFail = true;
		}

		// -----------------------------------------------------------------
		// Summary
		// -----------------------------------------------------------------
		$output->writeln('');
		if ($anyFail) {
			$output->writeln('<comment>One or more checks failed. See suggestions above.</comment>');
			return Command::FAILURE;
		}
		$output->writeln('<info>All checks passed. Users should be able to complete the OAuth flow.</info>');
		return Command::SUCCESS;
	}

	private function pass(OutputInterface $output, string $msg): void {
		$output->writeln('  <info>✓</info> ' . $msg);
	}

	private function warn(OutputInterface $output, string $msg, array $hints = []): void {
		$output->writeln('  <comment>?</comment> ' . $msg);
		foreach ($hints as $h) {
			$output->writeln('     <comment>→</comment> ' . $h);
		}
	}

	private function fail(OutputInterface $output, string $msg, array $hints = []): void {
		$output->writeln('  <error>✗</error> ' . $msg);
		foreach ($hints as $h) {
			$output->writeln('     <comment>→</comment> ' . $h);
		}
	}

	/**
	 * Test whether a host string resolves to an RFC-1918 / loopback
	 * address. Simple heuristic — good enough for the "will NC's SSRF
	 * guard block this?" check.
	 */
	private function isPrivateAddress(string $host): bool {
		if ($host === '') {
			return false;
		}
		// If it's already an IP, use it directly; else resolve.
		$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
		if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
			// Resolution failed — err on the safe side, assume public
			return false;
		}
		if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.')) {
			return true;
		}
		return !filter_var($ip, FILTER_VALIDATE_IP,
			FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
	}
}
