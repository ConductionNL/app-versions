<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Notification;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Notification\Notifier;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private function l10nFactory(): IFactory {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf(
				$count === 1 ? $singular : $plural,
				$parameters
			)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return $factory;
	}

	private function notification(string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')->willReturnSelf();

		return $notification;
	}

	public function testPatExpiringIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('pat_expiring', [
			'label' => 'conduction-bot',
			'forge' => 'github',
			'daysRemaining' => 5,
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testPatExpiredIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('pat_expired', [
			'label' => 'conduction-bot',
			'forge' => 'github',
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testUnknownSubjectThrows(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('something_else');

		$this->expectException(UnknownNotificationException::class);
		$notifier->prepare($notification, 'en');
	}

	public function testWrongAppThrows(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(UnknownNotificationException::class);
		$notifier->prepare($notification, 'en');
	}
}
