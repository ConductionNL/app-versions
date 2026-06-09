<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Settings;

use OCA\AppVersions\Settings\Admin;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase {
	public function testGetSectionIsAppId(): void {
		self::assertSame('app_versions', (new Admin())->getSection());
	}

	public function testGetPriorityIsInt(): void {
		self::assertIsInt((new Admin())->getPriority());
	}

	public function testGetFormReturnsTemplateResponse(): void {
		$form = (new Admin())->getForm();
		self::assertInstanceOf(TemplateResponse::class, $form);
		// Embedded inside the settings page (empty renderAs).
		self::assertSame('', $form->getRenderAs());
	}
}
