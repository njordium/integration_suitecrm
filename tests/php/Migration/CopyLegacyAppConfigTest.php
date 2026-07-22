<?php

declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Structural regression coverage for the 2.0.0 Migration Repair step
 * {@see \OCA\SuiteCRM\Migration\CopyLegacyAppConfig}.
 *
 * These are file-content assertions rather than runtime unit tests
 * because the fork's `composer.json` does not pull in `doctrine/dbal`
 * (only the `nextcloud/ocp` interface stubs), and PHPUnit's
 * `createMock(IDBConnection::class)` triggers autoload of
 * `OCP\DB\QueryBuilder\IQueryBuilder`, which resolves a
 * `Doctrine\DBAL\ParameterType` class-level constant at load time,
 * that class is not on the test classpath, so the mock generator dies
 * before the test body runs.
 *
 * File-content assertions are weaker than behaviour tests but catch
 * the highest-value regression classes for a one-shot migration:
 *
 *   - the legacy app id string drifts (would silently skip migration)
 *   - the class stops implementing `IRepairStep` (NC's DI would fail
 *     to register the step)
 *   - `getName()` or `run()` disappears
 *   - the Repair step is dropped from `appinfo/info.xml`, so it
 *     stops running on `occ upgrade`
 *
 * Actual copy semantics, rows are moved, target keys are preserved
 * on collision, empty state is a no-op, are exercised live during
 * the 2.0.0 upgrade smoke test on the test Nextcloud instance. The
 * `occ upgrade` run emits a summary line with concrete row counts
 * that we verify against seeded fixtures on the real database.
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
class CopyLegacyAppConfigTest extends TestCase {

	private const LEGACY_APP_ID = 'integration_suitecrm';
	private const NEW_APP_ID = 'njordium_suitecrm';

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
		// If someone edits LEGACY_APP_ID away from 'integration_suitecrm'
		// the migration silently stops finding any row to copy on
		// upgraded instances. Guard the exact string.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertMatchesRegularExpression(
			"/const\s+LEGACY_APP_ID\s*=\s*'" . self::LEGACY_APP_ID . "'/",
			$body,
			'LEGACY_APP_ID const must be "' . self::LEGACY_APP_ID . '", the '
			. 'app id used on Julien\'s original App Store record and '
			. 'every 1.x deployment of this fork.',
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
		// 2.0.0 leaves legacy rows in place so a rollback to 1.9.x is
		// trivial. The `->delete()` call is deferred to a follow-up
		// 2.1.0 repair step once 2.0.0 has been stable in production.
		$body = (string)file_get_contents($this->sutPath);
		$this->assertStringNotContainsString(
			'->delete(',
			$body,
			'2.0.0 Migration must NOT delete legacy rows, that would '
			. 'break the rollback path. Deletion is scheduled for 2.1.0.',
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

	public function testInfoXmlAppIdIsNewFork(): void {
		$infoXml = (string)file_get_contents($this->infoXmlPath);
		$this->assertMatchesRegularExpression(
			'|<id>\s*' . preg_quote(self::NEW_APP_ID, '|') . '\s*</id>|',
			$infoXml,
			'appinfo/info.xml <id> must be "' . self::NEW_APP_ID . '" for 2.0.0.',
		);
	}
}
