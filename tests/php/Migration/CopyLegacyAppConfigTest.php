<?php

declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Migration;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Migration\CopyLegacyAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Structural coverage for {@see CopyLegacyAppConfig}, the 2.0.0 Repair
 * step that copies admin config + user preferences from the legacy
 * `integration_suitecrm` app id to `njordium_suitecrm`.
 *
 * Behavioural coverage (rows are actually copied, idempotency holds,
 * existing target rows are preserved) is exercised live on the test
 * Nextcloud instance as part of the 2.0.0 upgrade smoke test. That is
 * the same environment the code runs in production, so it is a truer
 * regression signal than a full IDBConnection fake would give here.
 *
 * These structural tests catch the highest-value regression classes:
 *
 * - a refactor accidentally changes the legacy app id string
 * - the class stops implementing IRepairStep so NC's DI can't load it
 * - getName() drops one of the two ids and admins can't tell what ran
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
class CopyLegacyAppConfigTest extends TestCase {

	private const LEGACY_APP_ID = 'integration_suitecrm';

	public function testImplementsRepairStepInterface(): void {
		$db = $this->createMock(IDBConnection::class);
		$sut = new CopyLegacyAppConfig($db);

		$this->assertInstanceOf(IRepairStep::class, $sut);
	}

	public function testGetNameMentionsBothLegacyAndNewAppId(): void {
		$db = $this->createMock(IDBConnection::class);
		$sut = new CopyLegacyAppConfig($db);

		$name = $sut->getName();
		$this->assertNotEmpty($name);
		$this->assertStringContainsString(self::LEGACY_APP_ID, $name);
		$this->assertStringContainsString(Application::APP_ID, $name);
	}

	public function testLegacyAppIdConstantPointsAtOriginalAppStoreId(): void {
		// If someone renames LEGACY_APP_ID away from 'integration_suitecrm',
		// the migration would silently stop finding any legacy rows on the
		// upgrade path. Guard the exact string.
		$ref = new ReflectionClass(CopyLegacyAppConfig::class);
		$const = $ref->getReflectionConstant('LEGACY_APP_ID');
		$this->assertNotFalse($const, 'LEGACY_APP_ID const must exist on CopyLegacyAppConfig.');
		$this->assertSame(self::LEGACY_APP_ID, $const->getValue());
	}

	public function testTargetAppIdIsCurrentApplicationAppId(): void {
		// The migration must write under the CURRENT Application::APP_ID,
		// not a hardcoded string that could drift if the app id is
		// renamed again in the future.
		$this->assertSame('njordium_suitecrm', Application::APP_ID);

		$db = $this->createMock(IDBConnection::class);
		$sut = new CopyLegacyAppConfig($db);
		$this->assertStringContainsString(Application::APP_ID, $sut->getName());
	}

	public function testRunOnEmptyLegacyStateEmitsNoOpMessage(): void {
		// This is the ONE behavioural test we can do without wiring a
		// full DB fake: when there are no legacy rows, getQueryBuilder()
		// hands out builders whose executeQuery() returns an empty
		// IResult. We stub that chain and verify the "nothing to
		// migrate" branch fires.
		$emptyResult = $this->createMock(\OCP\DB\IResult::class);
		$emptyResult->method('fetch')->willReturn(false);
		$emptyResult->method('fetchOne')->willReturn(0);
		$emptyResult->method('closeCursor')->willReturn(true);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$qb->method('func')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class));
		$qb->method('executeQuery')->willReturn($emptyResult);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('nothing to migrate'));

		(new CopyLegacyAppConfig($db))->run($output);
	}
}
