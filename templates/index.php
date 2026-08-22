<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Versioniq\AppInfo\Application::APP_ID, OCA\Versioniq\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Versioniq\AppInfo\Application::APP_ID, OCA\Versioniq\AppInfo\Application::APP_ID . '-main');

?>

<div id="versioniq"></div>
