<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Service;

use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TokenStorageTest extends TestCase {

	private IConfig&MockObject $config;
	private ICrypto&MockObject $crypto;
	private TokenStorage $tokens;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->tokens = new TokenStorage($this->config, $this->crypto);
	}

	public function testWriteEncryptsBeforeStoring(): void {
		$this->crypto->expects($this->once())
			->method('encrypt')
			->with('plain-secret')
			->willReturn('cipher-blob');
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', Application::APP_ID, 'token', 'cipher-blob');

		$this->tokens->setAccessToken('alice', 'plain-secret');
	}

	public function testWriteEmptyStringSkipsEncryption(): void {
		$this->crypto->expects($this->never())->method('encrypt');
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', Application::APP_ID, 'token', '');

		$this->tokens->setAccessToken('alice', '');
	}

	public function testReadDecryptsStoredValue(): void {
		$this->config->method('getUserValue')
			->with('alice', Application::APP_ID, 'token', '')
			->willReturn('cipher-blob');
		$this->crypto->expects($this->once())
			->method('decrypt')
			->with('cipher-blob')
			->willReturn('plain-secret');

		$this->assertSame('plain-secret', $this->tokens->getAccessToken('alice'));
	}

	public function testReadEmptyStringSkipsDecryption(): void {
		$this->config->method('getUserValue')->willReturn('');
		$this->crypto->expects($this->never())->method('decrypt');

		$this->assertSame('', $this->tokens->getAccessToken('alice'));
	}

	/**
	 * Legacy plaintext tokens (from <= 1.1.x) must decode without error and
	 * be re-saved encrypted on first read, so subsequent reads take the fast
	 * path.
	 */
	public function testLegacyPlaintextIsMigratedOnFirstRead(): void {
		$this->config->method('getUserValue')
			->willReturn('legacy-plaintext-token');
		$this->crypto->method('decrypt')
			->willThrowException(new \Exception('bad ciphertext'));
		$this->crypto->expects($this->once())
			->method('encrypt')
			->with('legacy-plaintext-token')
			->willReturn('new-cipher');
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', Application::APP_ID, 'token', 'new-cipher');

		$this->assertSame('legacy-plaintext-token', $this->tokens->getAccessToken('alice'));
	}

	public function testClearWritesEmptyStrings(): void {
		$this->config->expects($this->exactly(2))
			->method('setUserValue')
			->willReturnCallback(function ($uid, $app, $key, $value) {
				$this->assertSame('alice', $uid);
				$this->assertSame(Application::APP_ID, $app);
				$this->assertContains($key, ['token', 'refresh_token']);
				$this->assertSame('', $value);
			});

		$this->tokens->clear('alice');
	}
}
