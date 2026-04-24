<?php

/**
 * AppVersions Page Controller
 *
 * Serves the admin single-page UI (Vue 3) that drives version selection and
 * install.
 *
 * @category Controller
 * @package  OCA\AppVersions\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppVersions\Controller;

use OCA\AppVersions\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * Renders the admin SPA container template. All interactive work happens
 * client-side via the Vue app mounted into `#app_versions`.
 *
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller
{
	/**
	 * Renders the admin UI shell.
	 *
	 * @return TemplateResponse
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function index(): TemplateResponse
	{
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}//end index()
}//end class
