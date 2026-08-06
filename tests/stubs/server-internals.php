<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */

/**
 * Psalm stubs for Nextcloud server-internal and transitive classes.
 *
 * These classes exist at runtime inside the Nextcloud server but are NOT part of
 * the public `nextcloud/ocp` package this app analyses against. The App Versions
 * installer deliberately reuses the server's app-installer internals (archive
 * extraction, code-signing verification, repair-step execution) because the
 * public OCP API exposes no equivalent for installing a *specific* app version.
 * Declaring them here lets Psalm type-check the call sites without pulling the
 * whole server into the analysis path.
 *
 * This file is never autoloaded — it is wired into Psalm via the <stubs> section
 * of psalm.xml only.
 */

namespace {
	class OC {
		/** @var string */
		public static $SERVERROOT;

		/** @var array<int, array{path: string, url: string, writable: bool}> */
		public static $APPSROOTS;
	}

	class OC_App {
		/**
		 * @return array<int, array<string, mixed>>
		 */
		public static function listAllApps(): array {
		}

		/**
		 * @param array<string, mixed> $info
		 */
		public static function checkAppDependencies(\OCP\IConfig $config, \OCP\IL10N $l, array $info, bool $ignoreMax = false): void {
		}

		public static function registerAutoloading(string $app, string $path): void {
		}

		/**
		 * @param array<array-key, mixed> $steps
		 */
		public static function executeRepairSteps(string $appId, array $steps): void {
		}

		public static function setAppTypes(string $appId): void {
		}
	}

	class PEAR_Error {
		public function getMessage(): string {
		}
	}
}

namespace OC\Archive {
	class Archive {
		public function __construct(string $source) {
		}

		public function extract(string $dest): bool {
		}

		public function getError(): \PEAR_Error|false {
		}
	}

	class TAR extends Archive {
	}

	class ZIP extends Archive {
	}
}

namespace OC\Files {
	class FilenameValidator {
		public function isForbidden(string $path): bool {
		}
	}
}

namespace OC\AppFramework\Bootstrap {
	class Coordinator {
		public function runLazyRegistration(string $appId): void {
		}
	}
}

namespace OC\DB {
	class Connection {
	}

	class MigrationService {
		public function __construct(string $appName, Connection $connection) {
		}

		public function setOutput(\OCP\Migration\IOutput $output): void {
		}

		public function migrate(string $to = 'latest', bool $schemaOnly = false): void {
		}
	}
}

namespace phpseclib\File {
	class X509 {
		public function loadCA(string $cert): mixed {
		}

		/**
		 * @return array<string, mixed>|false
		 */
		public function loadX509(string $cert): array|false {
		}

		public function loadCRL(string $crl): mixed {
		}

		public function validateSignature(): bool {
		}

		public function getRevoked(string $serial): mixed {
		}
	}
}

namespace Doctrine\DBAL\Schema {
	class Table {
		/**
		 * @param array<string, mixed> $options
		 */
		public function addColumn(string $name, string $type, array $options = []): void {
		}

		/**
		 * @param list<string> $columns
		 */
		public function setPrimaryKey(array $columns): void {
		}

		/**
		 * @param list<string> $columns
		 */
		public function addIndex(array $columns, ?string $name = null): void {
		}

		/**
		 * @param list<string> $columns
		 */
		public function addUniqueIndex(array $columns, ?string $name = null): void {
		}
	}
}
