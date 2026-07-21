<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Kim Haverblad (fork maintainer)
 * @license AGPL-3.0
 *
 * @Code Changes by: Kim Haverblad, 2026
 */

namespace OCA\SuiteCRM\Migration;

use OCA\SuiteCRM\AppInfo\Application;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Copies app config + per-user preferences from the legacy app id
 * `integration_suitecrm` (the fork's pre-2.0 name, also the id under
 * which Julien's original app was published to the Nextcloud App Store)
 * to the new fork-owned id `njordium_suitecrm`.
 *
 * The rename is a 2.0.0 breaking change. Existing 1.9.x deployments
 * carry all their OAuth tokens, admin config (instance URL, client
 * ID/secret, authorize path) and per-user prefs under the old app id.
 * Without this repair step, upgrading to 2.0.0 would strand every
 * admin- and user-side setting: the rows would still be in the
 * database, but the running app code would look them up under the new
 * app id and see nothing.
 *
 * This step is idempotent by design. If a target row already exists
 * under the new app id (either from a fresh 2.0.0 install or a re-run
 * of `occ upgrade`), the legacy row is skipped, not overwritten.
 * Legacy rows are NOT deleted here; that is deferred to a follow-up
 * 2.1.0 repair step so 2.0.0 → 1.9.x rollback stays trivial: an admin
 * can `occ app:disable njordium_suitecrm && occ app:enable
 * integration_suitecrm` and every setting is back where it was.
 *
 * Registered under `<repair-steps><post-migration>` in appinfo/info.xml
 * so it runs on every `occ upgrade` invocation after the schema
 * migration completes. On a fresh install (no legacy rows) it's a
 * silent no-op.
 */
class CopyLegacyAppConfig implements IRepairStep {

	private const LEGACY_APP_ID = 'integration_suitecrm';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getName(): string {
		return sprintf(
			'Copy SuiteCRM integration settings from legacy app id "%s" to "%s"',
			self::LEGACY_APP_ID,
			Application::APP_ID,
		);
	}

	public function run(IOutput $output): void {
		$appConfigCopied = $this->copyAppConfig();
		$userPrefsCopied = $this->copyUserPreferences();

		if ($appConfigCopied === 0 && $userPrefsCopied === 0) {
			$output->info('No legacy SuiteCRM integration settings found — nothing to migrate.');
			return;
		}

		$output->info(sprintf(
			'Migrated %d admin config key(s) and %d user preference row(s) from "%s" to "%s".',
			$appConfigCopied,
			$userPrefsCopied,
			self::LEGACY_APP_ID,
			Application::APP_ID,
		));
	}

	/**
	 * Copy every `oc_appconfig` row for the legacy app id under the
	 * new app id, skipping any target key that already exists.
	 *
	 * @return int number of rows actually inserted
	 */
	private function copyAppConfig(): int {
		$qb = $this->db->getQueryBuilder();
		$legacy = $qb->select('configkey', 'configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::LEGACY_APP_ID)))
			->executeQuery();

		$copied = 0;
		while ($row = $legacy->fetch()) {
			$key = (string)$row['configkey'];
			if ($this->appConfigExists(Application::APP_ID, $key)) {
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('appconfig')
				->values([
					'appid' => $ins->createNamedParameter(Application::APP_ID),
					'configkey' => $ins->createNamedParameter($key),
					'configvalue' => $ins->createNamedParameter((string)$row['configvalue']),
				])
				->executeStatement();
			$copied++;
		}
		$legacy->closeCursor();
		return $copied;
	}

	/**
	 * Copy every `oc_preferences` row for the legacy app id under the
	 * new app id, skipping any (user, key) tuple that already exists.
	 *
	 * @return int number of rows actually inserted
	 */
	private function copyUserPreferences(): int {
		$qb = $this->db->getQueryBuilder();
		$legacy = $qb->select('userid', 'configkey', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::LEGACY_APP_ID)))
			->executeQuery();

		$copied = 0;
		while ($row = $legacy->fetch()) {
			$userId = (string)$row['userid'];
			$key = (string)$row['configkey'];
			if ($this->userPrefExists($userId, Application::APP_ID, $key)) {
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('preferences')
				->values([
					'userid' => $ins->createNamedParameter($userId),
					'appid' => $ins->createNamedParameter(Application::APP_ID),
					'configkey' => $ins->createNamedParameter($key),
					'configvalue' => $ins->createNamedParameter((string)$row['configvalue']),
				])
				->executeStatement();
			$copied++;
		}
		$legacy->closeCursor();
		return $copied;
	}

	private function appConfigExists(string $appId, string $key): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	private function userPrefExists(string $userId, string $appId, string $key): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter($appId)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}
