<?php

/**
 * AppVersions PHPUnit Bootstrap
 *
 * Loads the Nextcloud test harness, the app's composer autoloader, and
 * initializes the app under test.
 *
 * @category Tests
 * @package  OCA\AppVersions\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

\OC_App::loadApp(OCA\AppVersions\AppInfo\Application::APP_ID);
OC_Hook::clear();
