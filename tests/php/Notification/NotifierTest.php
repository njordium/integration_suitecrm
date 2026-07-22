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
 * Regression coverage for Notifier::prepare(). A reminder whose type is
 * neither 'Calls' nor 'Meetings' produces no renderable content, so the
 * notifier throws InvalidArgumentException (the NC contract for
 * "suppress this row entirely") instead of silently emitting a blank
 * notification.
 *
 * @Code Changes by: Kim Haverblad, 2026
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

		// URL generator no-ops, the icon path is not under test here.
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
	 * arm of the switch and throws.
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
	 * ever entered.
	 */
	public function testUnknownAppThrows(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Regression: when the SuiteCRM payload has no 'link' key we must NOT
	 * call setLink() with null, since that blows up the notifier under
	 * strict types on NC 28+.
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
	 * key present and set to the empty string; the guard is `$link !== ''`.
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
	 * A reminder with no type, or with any type we don't know how to
	 * render, produces no content string. Rather than emitting a blank
	 * row in the notification tray the notifier throws, to tell NC's
	 * notification manager to suppress the row. Empty-params is the
	 * canonical instance of "unknown type" (type defaults to '').
	 */
	public function testEmptyParamsUnknownTypeThrows(): void {
		$notification = $this->createReminderNotification([]);
		$notification->expects($this->never())->method('setLink');
		$notification->expects($this->never())->method('setParsedSubject');
		$this->dateFormatter->expects($this->never())->method('formatDateTime');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Explicit unknown type, same suppression contract as
	 * {@see self::testEmptyParamsUnknownTypeThrows()} but with the type
	 * field set to a value that just isn't in the render matrix.
	 */
	public function testForeignReminderTypeThrows(): void {
		$notification = $this->createReminderNotification([
			'type' => 'Cases',
			'title' => 'Ticket 42',
			'event_timestamp' => 1_700_000_000,
		]);
		$notification->expects($this->never())->method('setParsedSubject');

		$this->expectException(InvalidArgumentException::class);
		$this->notifier->prepare($notification, 'en');
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
