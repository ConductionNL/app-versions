<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\AppVersions\AppInfo\Application::APP_ID, OCA\AppVersions\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\AppVersions\AppInfo\Application::APP_ID, OCA\AppVersions\AppInfo\Application::APP_ID . '-main');

?>

<div id="app_versions"></div>
