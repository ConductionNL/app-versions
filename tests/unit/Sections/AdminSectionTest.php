<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Sections;

use OCA\AppVersions\Sections\AdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class AdminSectionTest extends TestCase {
	private function build(): AdminSection {
		$l = $this->createMock(IL10N::class);
		// Faithful to OC\L10N\L10NString: vsprintf against the parameter array.
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/app_versions/img/app.svg');

		return new AdminSection($l, $urlGenerator);
	}

	public function testGetIdIsAppId(): void {
		self::assertSame('app_versions', $this->build()->getID());
	}

	public function testGetNameIsNonEmpty(): void {
		self::assertNotSame('', $this->build()->getName());
	}

	public function testGetPriorityIsInt(): void {
		self::assertIsInt($this->build()->getPriority());
	}

	public function testGetIconIsNonEmpty(): void {
		self::assertNotSame('', $this->build()->getIcon());
	}
}
