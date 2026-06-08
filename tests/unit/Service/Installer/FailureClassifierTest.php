<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Installer;

use Exception;
use OCA\AppVersions\Service\Installer\FailureClassifier;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class FailureClassifierTest extends TestCase {
	private function build(): FailureClassifier {
		$l = $this->createMock(IL10N::class);
		// Echo the source string so hint/message assertions stay readable.
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		return new FailureClassifier($factory);
	}

	/**
	 * @dataProvider statusProvider
	 */
	public function testHttpStatusForEveryCategory(string $category, int $expected): void {
		self::assertSame($expected, $this->build()->httpStatusFor($category));
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function statusProvider(): array {
		return [
			'preflight' => [FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION, Http::STATUS_CONFLICT],
			'incompatible' => [FailureClassifier::CATEGORY_INCOMPATIBLE, Http::STATUS_UNPROCESSABLE_ENTITY],
			'version' => [FailureClassifier::CATEGORY_VERSION_MISMATCH, Http::STATUS_UNPROCESSABLE_ENTITY],
			'appid' => [FailureClassifier::CATEGORY_APPID_MISMATCH, Http::STATUS_UNPROCESSABLE_ENTITY],
			'checksum' => [FailureClassifier::CATEGORY_CHECKSUM_MISMATCH, Http::STATUS_UNPROCESSABLE_ENTITY],
			'download' => [FailureClassifier::CATEGORY_DOWNLOAD, Http::STATUS_BAD_GATEWAY],
			'extract' => [FailureClassifier::CATEGORY_EXTRACT, Http::STATUS_INTERNAL_SERVER_ERROR],
			'finalize' => [FailureClassifier::CATEGORY_FINALIZE, Http::STATUS_INTERNAL_SERVER_ERROR],
			'unknown' => [FailureClassifier::CATEGORY_UNKNOWN, Http::STATUS_INTERNAL_SERVER_ERROR],
		];
	}

	public function testUnknownMapsToInternalServerError(): void {
		$classifier = $this->build();
		$result = $classifier->classify(new Exception('something totally opaque'), null);

		self::assertSame(FailureClassifier::CATEGORY_UNKNOWN, $result['category']);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result['statusCode']);
		self::assertNotSame('', $result['hint']);
	}

	/**
	 * @dataProvider messageProvider
	 */
	public function testCategoryDerivedFromMessage(string $message, string $expected): void {
		self::assertSame($expected, $this->build()->categoryFor(new Exception($message)));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function messageProvider(): array {
		return [
			'not compatible' => ['App "Foo" is not compatible with this version of the server.', FailureClassifier::CATEGORY_INCOMPATIBLE],
			'app id' => ["Downloaded archive declares appId 'bar', expected 'foo'.", FailureClassifier::CATEGORY_APPID_MISMATCH],
			'version' => ["Downloaded archive declares version '1.2.3', expected '1.2.4'.", FailureClassifier::CATEGORY_VERSION_MISMATCH],
			'checksum' => ['SHA-256 mismatch — expected abc, got def.', FailureClassifier::CATEGORY_CHECKSUM_MISMATCH],
			'download' => ['Could not download selected release: timeout.', FailureClassifier::CATEGORY_DOWNLOAD],
			'extract' => ['Could not extract release archive (tried TAR and ZIP).', FailureClassifier::CATEGORY_EXTRACT],
			'permission' => ['Could not backup existing app folder before replacement.', FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION],
		];
	}

	public function testStageFallbackWhenMessageIsOpaque(): void {
		$classifier = $this->build();

		self::assertSame(
			FailureClassifier::CATEGORY_FINALIZE,
			$classifier->categoryFor(new Exception('opaque'), FailureClassifier::STAGE_FILESYSTEM_UPDATED)
		);
		self::assertSame(
			FailureClassifier::CATEGORY_UNKNOWN,
			$classifier->categoryFor(new Exception('opaque'), null)
		);
	}

	public function testEveryCategoryHasANonEmptyHint(): void {
		$classifier = $this->build();
		foreach ([
			FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION,
			FailureClassifier::CATEGORY_DOWNLOAD,
			FailureClassifier::CATEGORY_CHECKSUM_MISMATCH,
			FailureClassifier::CATEGORY_EXTRACT,
			FailureClassifier::CATEGORY_APPID_MISMATCH,
			FailureClassifier::CATEGORY_VERSION_MISMATCH,
			FailureClassifier::CATEGORY_INCOMPATIBLE,
			FailureClassifier::CATEGORY_FINALIZE,
			FailureClassifier::CATEGORY_UNKNOWN,
		] as $category) {
			self::assertNotSame('', $classifier->hintFor($category), $category . ' should have a hint');
		}
	}

	public function testFinalizeHintReflectsRestoreCleanliness(): void {
		$classifier = $this->build();
		$clean = $classifier->finalizeHint(true);
		$dirty = $classifier->finalizeHint(false);

		self::assertNotSame('', $clean);
		self::assertNotSame('', $dirty);
		self::assertNotSame($clean, $dirty, 'A failed restore must produce a stronger hint');
		self::assertStringContainsString('indeterminate', $dirty);
	}

	public function testRevertedHintIsNonEmpty(): void {
		self::assertNotSame('', $this->build()->revertedHint());
	}
}
