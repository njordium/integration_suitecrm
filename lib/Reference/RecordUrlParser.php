<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Reference;

/**
 * Parses SuiteCRM record detail-view URLs like
 *   https://crm.example.com/index.php?module=Contacts&action=DetailView&record=abc-123
 * into a (module, recordId) tuple. Isolated from the reference provider so it
 * can be unit-tested without instantiating framework-heavy dependencies.
 */
final class RecordUrlParser {

	private const RECORD_URL_PATTERN = '/\/index\.php\?[^"\s]*module=([A-Za-z]+)(?:&|&amp;)[^"\s]*record=([a-zA-Z0-9\-]+)/';

	public const SUPPORTED_MODULES = [
		'Contacts',
		'Accounts',
		'Leads',
		'Opportunities',
		'Cases',
		'Meetings',
		'Calls',
		'Tasks',
	];

	/**
	 * @return array{module: string, recordId: string}|null
	 */
	public static function parse(string $text): ?array {
		if (!preg_match(self::RECORD_URL_PATTERN, $text, $matches)) {
			return null;
		}
		$module = $matches[1];
		$recordId = $matches[2];
		if (!in_array($module, self::SUPPORTED_MODULES, true)) {
			return null;
		}
		return ['module' => $module, 'recordId' => $recordId];
	}
}
