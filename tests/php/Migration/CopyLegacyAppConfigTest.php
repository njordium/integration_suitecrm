<?php

declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Structural regression coverage for the 3.0.0 Migration Repair step
 * {@see \OCA\SuiteCRM\Migration\CopyLegacyAppConfig}, updated for the
 * app id rename back from `njordium_suitecrm` to `integration_suitecrm`
 * after Julien's App Store transfer.
 *
 * The step is the same class that shipped in 2.0.0, with `LEGACY_APP_ID`
 * flipped from `integration_suitecrm` (Julien's original) to
 * `njordium_suitecrm` (the intermediate name used through 2.x). Same
 * SQL body, opposite source. Tests below assert on the current
 * (`3.0.0`) direction of the migration; the previous direction is
 * documented in the git history and in CHANGELOG entries for 2.0.0.
 *
 * File-content assertions rather than runtime unit tests, same reason
 * as the 2.0.0 version: the fork's `composer.json` does not pull in
 * `doctrine/dbal` (only the `nextcloud/ocp` interface stubs), so
 * PHPUnit's `createMock(IDBConnection::class)` would trigger an
 * unloadable class autoload chain. The high-value invariants (constant
 * string, `IRepairStep` contract, required methods, info.xml
 * registration) are all detectable at the source-file level.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
class CopyLegacyAppConfigTest extends TestCase {

	private const LEGACY_APP_ID = 'njordium_suitecrm';
	private const NEW_APP_ID = 'integration_suitecrm';

	private string $sutPath;
	private string $infoXmlPath;

	protected function setUp(): void {
		$repoRoot = dirname(__DIR__, 3);
		$this->sutPath = $repoRoot . '/lib/Migration/CopyLegacyAppConfig.php';
		$this->infoXmlPath = $repoRoot . '/appinfo/info.xml';
	}

	public function testMigrationFileExists(): void {
		$this->assertFileExists($this->sutPath);
	}

	public function testMigrationDeclaresIRepairStep(): void {
		$body = (string)file_get_contents($this->sutPath);
		$this->assertStringContainsString(
			'implements IRepairStep',
			$body,
			'CopyLegacyAppConfig must implement OCP\\Migration\\IRepairStep '
			. 'so Nextcloud\'s DI picks it up on `occ upgrade`.',
		);
	}

	public function testMigrationDeclaresRequiredMethods(): void {
		$body = (string)file_get_contents($this->sutPath);
		$this->assertMatchesRegularExpression(
			'/public\s+function\s+getName\s*\(/',
			$body,
			'IRepairStep requires a public getName() method.',
		);
		$this->assertMatchesRegularExpression(
			'/public\s+function\s+run\s*\(\s*IOutput/',
			$body,
			'IRepairStep requires a public run(IOutput) method.',
		);
	}

	public function testLegacyAppIdConstantIsCorrect(): void {
		// If someone edits LEGACY_APP_ID away from 'njordium_suitecrm'
		// the migration silently stops finding any 2.x-era row to copy
		// on upgraded instances. Guard the exact string.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertMatchesRegularExpression(
			"/const\s+LEGACY_APP_ID\s*=\s*'" . self::LEGACY_APP_ID . "'/",
			$body,
			'LEGACY_APP_ID const must be "' . self::LEGACY_APP_ID . '", the '
			. 'app id the fork used through the 2.x series before the '
			. 'canonical id was restored in 3.0.0.',
		);
	}

	public function testMigrationReadsFromLegacyAppConfigAndPreferences(): void {
		// Migration must touch both tables, admin config alone leaves
		// per-user OAuth tokens stranded and every connected user has
		// to re-authorise SuiteCRM after the rename.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertStringContainsString("'appconfig'", $body);
		$this->assertStringContainsString("'preferences'", $body);
	}

	public function testMigrationTargetsCurrentApplicationAppId(): void {
		// The write side must reference Application::APP_ID (not a
		// hardcoded string) so if the app id is ever renamed again the
		// migration follows automatically.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertStringContainsString(
			'Application::APP_ID',
			$body,
			'Migration must write under the current Application::APP_ID '
			. 'symbol, not a duplicated string literal.',
		);
	}

	public function testMigrationDoesNotDeleteLegacyRows(): void {
		// 3.0.0 leaves 2.x rows in place so a rollback to 2.6.0 is
		// trivial: `occ app:disable integration_suitecrm && occ
		// app:enable njordium_suitecrm` restores every setting.
		// Deletion of the njordium_suitecrm rows is deferred to a
		// follow-up repair step once 3.0.0 has been stable.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertStringNotContainsString(
			'->delete(',
			$body,
			'3.0.0 Migration must NOT delete legacy rows, that would '
			. 'break the rollback path. Deletion is scheduled for a '
			. 'later release once 3.0.0 has been stable in production.',
		);
	}

	public function testInfoXmlRegistersMigrationAsPostMigrationStep(): void {
		$this->assertFileExists($this->infoXmlPath);
		$infoXml = (string)file_get_contents($this->infoXmlPath);

		$this->assertStringContainsString(
			'<repair-steps>',
			$infoXml,
			'appinfo/info.xml must declare <repair-steps>.',
		);

		// Extract just the <repair-steps> block so we do not trip on a
		// stray "<step>" element inside a comment elsewhere.
		$matched = preg_match(
			'|<repair-steps>(.*?)</repair-steps>|s',
			$infoXml,
			$m,
		);
		$this->assertSame(1, $matched, '<repair-steps> block malformed.');
		$this->assertStringContainsString(
			'<post-migration>',
			$m[1],
			'CopyLegacyAppConfig runs after schema migration.',
		);
		$this->assertStringContainsString(
			'<step>OCA\SuiteCRM\Migration\CopyLegacyAppConfig</step>',
			$m[1],
			'appinfo/info.xml must register the CopyLegacyAppConfig step.',
		);
	}

	public function testAppConfigCopyPreservesSensitiveFlag(): void {
		// Regression guard for the 3.1.0 security-review finding:
		// the migration used to write appconfig rows via a raw
		// QueryBuilder INSERT of (appid, configkey, configvalue), which
		// left the `sensitive` column at its table default (false).
		// The OAuth `client_secret` therefore landed under the new app id
		// as admin-inspectable plaintext even though the 2.x write path
		// had marked it sensitive. The fix routes writes through
		// IAppConfig::setValueString with the `sensitive` argument, and
		// backfills a whitelist of known-sensitive keys so a legacy row
		// with a null `sensitive` column still migrates protected.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertMatchesRegularExpression(
			'/SENSITIVE_APPCONFIG_KEYS\s*=\s*\[[^\]]*[\'"]client_secret[\'"]/',
			$body,
			'SENSITIVE_APPCONFIG_KEYS whitelist must include client_secret.',
		);
		$this->assertStringContainsString(
			'appConfig->setValueString',
			$body,
			'copyAppConfig() must write via IAppConfig::setValueString '
			. 'so the sensitive flag rides along.',
		);
		$this->assertMatchesRegularExpression(
			'/sensitive:\s*\$sensitive/',
			$body,
			'sensitive argument must be threaded into setValueString().',
		);
	}

	public function testInfoXmlAppIdIsNewFork(): void {
		$infoXml = (string)file_get_contents($this->infoXmlPath);
		$this->assertMatchesRegularExpression(
			'|<id>\s*' . preg_quote(self::NEW_APP_ID, '|') . '\s*</id>|',
			$infoXml,
			'appinfo/info.xml <id> must be "' . self::NEW_APP_ID . '" for 3.0.0.',
		);
	}
}
