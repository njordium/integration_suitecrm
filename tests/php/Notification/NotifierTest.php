<?php
declare(strict_types=1);

namespace OCA\SuiteCRM\Tests\Notification;

use InvalidArgumentException;
use OCA\SuiteCRM\AppInfo\Application;
use OCA\SuiteCRM\Notification\Notifier;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Iteration 17 — Finding 49
 *
 * Regression coverage for {@see Notifier::prepare()}. The Iteration 13 fix
 * against setLink() with an empty/missing link is exercised by
 * {@see self::testMissingLinkSkipsSetLink()} and
 * {@see self::testEmptyLinkSkipsSetLink()}; unknown-subject and unknown-app
 * branches are pinned to the currently thrown InvalidArgumentException
 * (Nextcloud's newer AlreadyProcessed/UnknownNotification exceptions are not
 * yet adopted upstream in this file — the goal here is coverage, not a rename).
 */
class NotifierTest extends TestCase {

	private IFactory&MockObject $factory;
	private IUserManager&MockObject $userManager;
	private INotificationManager&MockObject $notificationManager;
	private IDateTimeFormatter&MockObject $dateFormatter;
	private IURLGenerator&MockObject $url;
	private IL10N&MockObject $l10n;
	private Notifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->factory = $this->createMock(IFactory::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->dateFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);

		// factory->get(APP_ID, ...) always returns our IL10N mock; t() is a
		// pass-through so tests can assert against the raw template.
		$this->factory->method('get')->willReturn($this->l10n);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf(str_replace('%s', '%s', $text), $params)
		);

		// URL generator no-ops — the icon path is not under test here.
		$this->url->method('imagePath')->willReturn('/img/app-dark.svg');
		$this->url->method('getAbsoluteURL')->willReturnArgument(0);

		$this->notifier = new Notifier(
			$this->factory,
			$this->userManager,
			$this->notificationManager,
			$this->dateFormatter,
			$this->url,
		);
	}

	/**
	 * A notification whose subject is not 'reminder' falls into the default
	 * arm of the switch and throws. Renamed from the requested
	 * testUnknownSubjectThrowsAlreadyProcessedException — the file still
	 * throws InvalidArgumentException upstream.
	 */
	public function testUnknownSubjectThrows(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('not-a-real-subject');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * A notification with a foreign app id is rejected before the switch is
	 * ever entered. Renamed from testUnknownAppReturnsSameNotification —
	 * the current implementation throws rather than returning the
	 * notification untouched.
	 */
	public function testUnknownAppThrows(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Iteration 13 regression: when the SuiteCRM payload has no 'link' key
	 * we must NOT call setLink() with null — that used to blow up the
	 * notifier under strict types on NC 28+.
	 */
	public function testMissingLinkSkipsSetLink(): void {
		$notification = $this->createReminderNotification([
			'type' => 'Calls',
			'title' => 'Ring Alice',
			'event_timestamp' => 1_700_000_000,
			// no 'link' key at all
		]);
		$notification->expects($this->never())->method('setLink');

		$this->dateFormatter->method('formatDateTime')->willReturn('2023-11-14 22:13');

		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}

	/**
	 * Same regression as {@see testMissingLinkSkipsSetLink()} but with the
	 * key present and set to the empty string — the guard is `$link !== ''`.
	 */
	public function testEmptyLinkSkipsSetLink(): void {
		$notification = $this->createReminderNotification([
			'type' => 'Meetings',
			'title' => 'Coffee',
			'event_timestamp' => 1_700_000_000,
			'link' => '',
		]);
		$notification->expects($this->never())->method('setLink');

		$this->dateFormatter->method('formatDateTime')->willReturn('2023-11-14 22:13');

		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Empty subject params must not raise — every field uses ?? '' or
	 * ?? null and the formatter is only invoked when the timestamp is
	 * non-null.
	 */
	public function testGracefulOnMissingParams(): void {
		$notification = $this->createReminderNotification([]);
		$notification->expects($this->never())->method('setLink');
		$this->dateFormatter->expects($this->never())->method('formatDateTime');

		$result = $this->notifier->prepare($notification, 'en');
		$this->assertSame($notification, $result);
	}

	/**
	 * Helper: build a reminder-subject INotification mock whose
	 * setParsedSubject()->setIcon() chain returns itself so the fluent
	 * builder in prepare() does not blow up.
	 */
	private function createReminderNotification(array $params): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('reminder');
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		return $notification;
	}
}
