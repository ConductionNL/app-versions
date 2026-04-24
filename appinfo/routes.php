<?php

/**
 * AppVersions route registration.
 *
 * Per ADR-016, appinfo/routes.php is the only route registration path — no
 * #[ApiRoute] / #[FrontpageRoute] attributes, no runtime registration from
 * Application::register(). Each entry names `controller#method` explicitly,
 * so grepping this file returns the full URL surface area of the app.
 *
 * Route names compose as `{app_id}.{controller}.{method}`; the navigation
 * entry in appinfo/info.xml references `app_versions.page.index`.
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

return [
    'routes' => [
        // Admin UI entry — served as a TemplateResponse (Vue 3 SPA mount point).
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ],
    'ocs' => [
        // OCS endpoints consumed by the admin UI. Auth attributes
        // (#[NoAdminRequired], #[PasswordConfirmationRequired], etc.) stay on
        // the controller methods — routes.php declares the URL surface, the
        // attributes declare the auth posture (ADR-005 + hydra-gate-route-auth).
        ['name' => 'api#adminCheck', 'url' => '/api/admin-check', 'verb' => 'GET'],
        ['name' => 'api#apps', 'url' => '/api/apps', 'verb' => 'GET'],
        ['name' => 'api#updateChannel', 'url' => '/api/update-channel', 'verb' => 'GET'],
        ['name' => 'api#appVersions', 'url' => '/api/app/{appId}/versions', 'verb' => 'GET'],
        ['name' => 'api#installVersion', 'url' => '/api/app/{appId}/versions/{version}/install', 'verb' => 'POST'],
    ],
];
