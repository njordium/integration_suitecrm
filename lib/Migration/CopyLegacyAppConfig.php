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
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Copies app config + per-user preferences from the intermediate
 * `njordium_suitecrm` app id (used through the 2.x series) back to the
 * canonical `integration_suitecrm` app id.
 *
 * Background: the fork was renamed from `integration_suitecrm` to
 * `njordium_suitecrm` in 2.0.0 because Julien's original App Store
 * record was stale and blocking updates. In July 2026 Julien
 * transferred ownership of the original record to this fork, so 3.0.0
 * renames the app id back to `integration_suitecrm`. See #1114 on
 * nextcloud/app-certificate-requests for the transfer and
 * docs/upgrade-2.x-to-3.0.md for the user-facing guide.
 *
 * Any deployment on the 2.x series carries all OAuth tokens, admin
 * config (instance URL, client ID/secret, authorize path) and per-user
 * prefs under `njordium_suitecrm`. Without this repair step, upgrading
 * to 3.0.0 would strand every setting: the rows would still be in the
 * database, but the running app code would look them up under the new
 * (well, restored) app id and see nothing.
 *
 * Idempotent by design. If a target row already exists under
 * `integration_suitecrm` (fresh 3.0.0 install, re-run of
 * `occ upgrade`, or a 1.9.x deployment whose rows never moved to
 * `njordium_suitecrm`), the legacy row is skipped, not overwritten.
 * Legacy rows are not deleted so rollback stays trivial.
 *
 * Registered under `<repair-steps><post-migration>` in appinfo/info.xml
 * so it runs on every `occ upgrade` invocation after the schema
 * migration completes. On a fresh install (no legacy rows) it's a
 * silent no-op.
 */
class CopyLegacyAppConfig implements IRepairStep {

	private const LEGACY_APP_ID = 'njordium_suitecrm';

	/**
	 * Keys that must land under the new app id with `sensitive=true` no
	 * matter what the legacy row's `sensitive` column said. The legacy
	 * 2.x row set the flag on the write path ({@see ConfigController::setAdminConfig()}
	 * at commit-time in 2.x), so on a clean 2.x install this list is a
	 * belt-and-braces backup — but any admin who hand-set the value via
	 * `occ config:app:set integration_suitecrm client_secret --value=…`
	 * without `--sensitive` in the 2.x window has a plaintext row we do
	 * not want to migrate forward unmasked. Named as a class constant so
	 * later contributors can add newly-sensitive keys without editing the
	 * write loop.
	 */
	private const SENSITIVE_APPCONFIG_KEYS = ['client_secret'];

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
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
			$output->info('No legacy SuiteCRM integration settings found, nothing to migrate.');
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
		// SELECT only key/value — the `sensitive` column was added to
		// oc_appconfig in Nextcloud 31; on NC 30 selecting it errors
		// with "Column not found: 1054 Unknown column 'sensitive'" and
		// the whole migration aborts before copying any row (the failure
		// mode caught on the 3.1.0 deploy to a NC 30 install).
		//
		// The SENSITIVE_APPCONFIG_KEYS whitelist below is enough for
		// this app's real-world protection surface: on every supported
		// NC version we know client_secret is the only sensitive key
		// and it lands under the new app id with sensitive:true because
		// setValueString() sets the flag explicitly on the write.
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
			$sensitive = in_array($key, self::SENSITIVE_APPCONFIG_KEYS, true);
			// Route through IAppConfig::setValueString rather than a raw
			// INSERT so the `lazy` and `sensitive` columns get set
			// through the same code path admins hit when they update
			// values via the settings UI. A raw INSERT with only
			// (appid, configkey, configvalue) leaves both columns at
			// their table default (`sensitive=false`), which silently
			// downgrades the OAuth client_secret to admin-inspectable
			// plaintext on `occ config:app:get` — the finding from the
			// 3.1.0 security review.
			$this->appConfig->setValueString(
				Application::APP_ID,
				$key,
				(string)$row['configvalue'],
				lazy: true,
				sensitive: $sensitive,
			);
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
