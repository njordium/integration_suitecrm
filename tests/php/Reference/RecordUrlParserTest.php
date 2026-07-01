<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Reference;

use OCA\SuiteCRM\Reference\RecordUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecordUrlParserTest extends TestCase {

	public static function validUrls(): array {
		return [
			'Contact detail view' => [
				'https://crm.example.com/index.php?module=Contacts&action=DetailView&record=abc-123',
				'Contacts',
				'abc-123',
			],
			'Account with extra params' => [
				'https://crm.example.com/index.php?offset=1&module=Accounts&action=DetailView&record=deadbeef-0000-0000-0000-000000000001',
				'Accounts',
				'deadbeef-0000-0000-0000-000000000001',
			],
			'Meeting with HTML-encoded ampersands' => [
				'https://crm.example.com/index.php?module=Meetings&amp;action=DetailView&amp;record=meet-42',
				'Meetings',
				'meet-42',
			],
			'Uppercase record id' => [
				'https://crm.example.com/index.php?module=Opportunities&action=DetailView&record=UPPER-CASE-ID',
				'Opportunities',
				'UPPER-CASE-ID',
			],
			'URL embedded in surrounding text' => [
				'Please look at https://crm.example.com/index.php?module=Cases&action=DetailView&record=case-77 when you get a chance.',
				'Cases',
				'case-77',
			],
		];
	}

	#[DataProvider('validUrls')]
	public function testParsesValidUrls(string $input, string $expectedModule, string $expectedRecordId): void {
		$result = RecordUrlParser::parse($input);

		$this->assertNotNull($result);
		$this->assertSame($expectedModule, $result['module']);
		$this->assertSame($expectedRecordId, $result['recordId']);
	}

	public static function rejectedInputs(): array {
		return [
			'plain text' => ['just a friendly note'],
			'unrelated URL' => ['https://example.com/foo/bar'],
			'missing record parameter' => ['https://crm.example.com/index.php?module=Contacts&action=DetailView'],
			'missing module parameter' => ['https://crm.example.com/index.php?action=DetailView&record=abc'],
			'unsupported module' => ['https://crm.example.com/index.php?module=SomeRandomModule&action=DetailView&record=x'],
			'empty string' => [''],
		];
	}

	#[DataProvider('rejectedInputs')]
	public function testRejectsInvalidInputs(string $input): void {
		$this->assertNull(RecordUrlParser::parse($input));
	}

	public function testAllExpectedModulesAreListed(): void {
		$this->assertContains('Contacts', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Accounts', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Leads', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Opportunities', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Cases', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Meetings', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Calls', RecordUrlParser::SUPPORTED_MODULES);
		$this->assertContains('Tasks', RecordUrlParser::SUPPORTED_MODULES);
	}
}
