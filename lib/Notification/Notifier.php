<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\SuiteCRM\Notification;


use InvalidArgumentException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\IDateTimeFormatter;
use OCA\SuiteCRM\AppInfo\Application;

class Notifier implements INotifier {

	/**
	 * @param IFactory $factory
	 * @param IUserManager $userManager
	 * @param INotificationManager $notificationManager
	 * @param IDateTimeFormatter $dateFormatter
	 * @param IURLGenerator $url
	 */
	public function __construct(protected IFactory $factory,
								protected IUserManager $userManager,
								protected INotificationManager $notificationManager,
								private IDateTimeFormatter $dateFormatter,
								protected IURLGenerator $url) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return Application::APP_ID;
	}
	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->factory->get(Application::APP_ID)->t('SuiteCRM');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'integration_suitecrm') {
			// Not my app => throw
			throw new InvalidArgumentException();
		}

		$l = $this->factory->get('integration_suitecrm', $languageCode);

		switch ($notification->getSubject()) {
		case 'reminder':
			$p = $notification->getSubjectParameters();
			$type = $p['type'] ?? '';
			$title = $p['title'] ?? '';
			$link = $p['link'] ?? '';
			$eventTimestamp = $p['event_timestamp'] ?? null;
			$formattedDate = $eventTimestamp !== null ? $this->dateFormatter->formatDateTime($eventTimestamp) : '';

			$content = '';
			if ($type === 'Calls') {
				$content = $l->t('SuiteCRM call: %s, %s', [$title, $formattedDate]);
			} elseif ($type === 'Meetings') {
				$content = $l->t('SuiteCRM meeting: %s, %s', [$title, $formattedDate]);
			}

			$notification->setParsedSubject($content)
				->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
				//->setIcon($this->url->getAbsoluteURL($iconUrl));
			if ($link !== '') {
				$notification->setLink($link);
			}
			return $notification;

		default:
			// Unknown subject => Unknown notification => throw
			throw new InvalidArgumentException();
		}
	}
}
