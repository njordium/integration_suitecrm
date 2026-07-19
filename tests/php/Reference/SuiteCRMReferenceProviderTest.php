<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Reference;

use OCA\SuiteCRM\Reference\SuiteCRMReferenceProvider;
use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\Service\TokenStorage;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Iteration 17 — Finding 49
 *
 * Regression coverage for {@see SuiteCRMReferenceProvider::getCacheKey()}.
 * The Iteration 13 fix returns null for unauthenticated calls; without that
 * guard the reference cache would key on the empty string and leak resolved
 * cards between users on the public share endpoint.
 */
class SuiteCRMReferenceProviderTest extends TestCase {

	private IConfig&MockObject $config;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private SuiteCRMAPIService&MockObject $service;
	private TokenStorage&MockObject $tokens;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = $this->createMock(SuiteCRMAPIService::class);
		$this->tokens = $this->createMock(TokenStorage::class);
	}

	private function makeProvider(?string $userId): SuiteCRMReferenceProvider {
		return new SuiteCRMReferenceProvider(
			$this->config,
			$this->l10n,
			$this->urlGenerator,
			$this->service,
			$this->tokens,
			$userId,
		);
	}

	/**
	 * Iteration 13 regression: no session → no cache key. Prevents cross-
	 * user leakage of resolved reference cards via the shared cache.
	 */
	public function testCacheKeyIsNullWhenUserIsNull(): void {
		$provider = $this->makeProvider(null);

		$this->assertNull($provider->getCacheKey('https://crm.example.com/index.php?module=Contacts&action=DetailView&record=x'));
		$this->assertNull($provider->getCacheKey(''));
	}

	/**
	 * With a session the cache key is the uid — every user gets their own
	 * bucket regardless of the reference text.
	 */
	public function testCacheKeyReturnsUserIdWhenAuthenticated(): void {
		$provider = $this->makeProvider('alice');

		$this->assertSame('alice', $provider->getCacheKey('anything'));
		$this->assertSame('alice', $provider->getCacheKey('https://crm.example.com/index.php?module=Cases&action=DetailView&record=case-77'));
	}
}
