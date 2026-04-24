<?php

/**
 * AppVersions Application
 *
 * Main application class for the AppVersions Nextcloud app. Bootstraps the app
 * and hosts its DI registrations.
 *
 * @category AppInfo
 * @package  OCA\AppVersions\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppVersions\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Bootstrap class — Nextcloud instantiates this at app load.
 */
class Application extends App implements IBootstrap
{
	public const APP_ID = 'app_versions';

	/**
	 * Constructor — delegates to the OCP App base class with the app id.
	 *
	 * @return void
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct()
	{
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register services and event listeners.
	 *
	 * No wiring needed today — the app has no listeners, repair steps, or
	 * container bindings beyond what Nextcloud auto-resolves for DI.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function register(IRegistrationContext $context): void
	{
	}//end register()

	/**
	 * Boot the application after registration.
	 *
	 * @param IBootContext $context Nextcloud boot context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function boot(IBootContext $context): void
	{
	}//end boot()
}//end class
