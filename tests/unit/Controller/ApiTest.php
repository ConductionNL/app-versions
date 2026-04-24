<?php

/**
 * AppVersions API Controller Test
 *
 * Unit tests for OCA\AppVersions\Controller\ApiController.
 *
 * @category Tests
 * @package  OCA\AppVersions\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Controller;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Controller\ApiController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	public function testIndex(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new ApiController(Application::APP_ID, $request);

		$this->assertEquals('Hello world!', $controller->index()->getData()['message']);
	}
}
