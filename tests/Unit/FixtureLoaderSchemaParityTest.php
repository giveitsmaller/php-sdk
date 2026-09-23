<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Tests\Parity\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * cEUWPgKW: the loader enforces the two rules fixture.schema.json pins that it
 * used to skip - a files-submit fixture needs a canned response, and
 * resize/output `fit` is max|crop|scale. Each case mutates a REAL fixture that
 * first loads cleanly, so only the new rule can reject it.
 */
#[CoversClass(FixtureLoader::class)]
final class FixtureLoaderSchemaParityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/fixture_schema_parity_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        @\rmdir($this->dir);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    private function copyFixture(string $stem, callable $mutate): string
    {
        $raw = Yaml::parseFile(__DIR__ . "/../../../../tests/parity/fixtures/{$stem}.yaml");
        self::assertIsArray($raw);
        /** @var array<string, mixed> $raw */
        $path = "{$this->dir}/{$stem}.yaml";
        \file_put_contents($path, Yaml::dump($mutate($raw), 20, 2));
        return $path;
    }

    public function test_the_unmutated_fixtures_load(): void
    {
        foreach (['ff_files_submit_multi_compress', 'ff_lowering_output_resize_format_change'] as $stem) {
            FixtureLoader::loadByPath($this->copyFixture($stem, static fn (array $raw): array => $raw));
        }
        $this->addToAssertionCount(1);
    }

    public function test_a_files_submit_fixture_without_a_response_is_rejected(): void
    {
        $path = $this->copyFixture('ff_files_submit_multi_compress', static function (array $raw): array {
            $raw['responses'] = [];
            return $raw;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/submit variant requires at least one response/');
        FixtureLoader::loadByPath($path);
    }

    public function test_an_unknown_fit_is_rejected(): void
    {
        $path = $this->copyFixture('ff_lowering_output_resize_format_change', static function (array $raw): array {
            /** @var array{operations: list<array<string, mixed>>} $lowering */
            $lowering = $raw['lowering'];
            foreach ($lowering['operations'] as $i => $op) {
                if (($op['op'] ?? null) === 'resize') {
                    $lowering['operations'][$i]['fit'] = 'stretch';
                }
            }
            $raw['lowering'] = $lowering;
            return $raw;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/resize 'fit' must be one of max\\|crop\\|scale/");
        FixtureLoader::loadByPath($path);
    }

    public function test_a_bad_fit_is_rejected_on_any_op(): void
    {
        $path = $this->copyFixture('ff_lowering_output_resize_format_change', static function (array $raw): array {
            /** @var array{operations: list<array<string, mixed>>} $lowering */
            $lowering = $raw['lowering'];
            $lowering['operations'][] = ['op' => 'convert', 'format' => 'png', 'fit' => 'bogus'];
            $raw['lowering'] = $lowering;
            return $raw;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/convert 'fit' must be one of max\\|crop\\|scale/");
        FixtureLoader::loadByPath($path);
    }
}
