<?php

declare(strict_types=1);

// Minimal bootstrap for tests that only exercise pure-PHP classes from
// `lib/Service/Source/*` against mocked OCP interfaces. This avoids pulling
// in the full Nextcloud server bootstrap so tests can run in CI / locally
// without a checked-out Nextcloud server tree.

require_once __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp ships interface stubs without composer autoload — register
// them manually so PHPUnit can build mocks for OCP\* interfaces.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\') || str_starts_with($class, 'NCU\\')) {
		$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
