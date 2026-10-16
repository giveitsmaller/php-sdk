<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Tests\Parity\FixtureLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- DflFw0j1: split is the last op only, never in files mode or a watermark
    // block, and carries a non-empty options mapping. Mirrors the TS
    // loader-schema-parity split cases.

    private const SPLIT_OP = ['op' => 'split', 'options' => ['mode' => 'page_range', 'page_range' => '1']];

    /**
     * Copy a fixture with `$raw[$block]['operations']` replaced by `$edit`.
     *
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $edit
     */
    private function copyWithOps(string $stem, string $block, callable $edit): string
    {
        return $this->copyFixture($stem, static function (array $raw) use ($block, $edit): array {
            /** @var array{operations: list<array<string, mixed>>} $spec */
            $spec = $raw[$block];
            $spec['operations'] = $edit($spec['operations']);
            $raw[$block] = $spec;
            return $raw;
        });
    }

    public function test_the_unmutated_split_fixtures_load(): void
    {
        foreach (['ff_lowering_split_pdf_page_groups', 'ff_run_split_pdf_page_index', 'ff_submit_split_pdf_page_groups'] as $stem) {
            FixtureLoader::loadByPath($this->copyFixture($stem, static fn (array $raw): array => $raw));
        }
        $this->addToAssertionCount(1);
    }

    public function test_split_in_files_mode_is_rejected(): void
    {
        $path = $this->copyWithOps('ff_files_lowering_multi_compress', 'files', static fn (array $ops): array => [...$ops, self::SPLIT_OP]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/operations\\[1\\]\\.op 'split' is not valid in files mode/");
        FixtureLoader::loadByPath($path);
    }

    public function test_split_in_watermark_overlay_ops_is_rejected(): void
    {
        $path = $this->copyFixture('ff_lowering_image_watermark_overlay', static function (array $raw): array {
            /** @var array{watermark: array{overlay: array<string, mixed>}} $lowering */
            $lowering = $raw['lowering'];
            $lowering['watermark']['overlay']['operations'] = [self::SPLIT_OP];
            $raw['lowering'] = $lowering;
            return $raw;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/\\[0\\]\\.op 'split' is not valid in a watermark block/");
        FixtureLoader::loadByPath($path);
    }

    public function test_split_together_with_a_watermark_block_is_rejected(): void
    {
        $path = $this->copyWithOps('ff_lowering_image_watermark_overlay', 'lowering', static fn (array $ops): array => [self::SPLIT_OP]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/operations\\[0\\]\\.op 'split' cannot be combined with a watermark block/");
        FixtureLoader::loadByPath($path);
    }

    /** @return iterable<string, array{string, string}> */
    public static function splitFixtureBlocks(): iterable
    {
        yield 'lowering' => ['lowering', 'ff_lowering_split_pdf_page_groups'];
        yield 'run' => ['run', 'ff_run_split_pdf_page_index'];
        yield 'submit' => ['submit', 'ff_submit_split_pdf_page_groups'];
    }

    #[DataProvider('splitFixtureBlocks')]
    public function test_split_that_is_not_the_last_op_is_rejected(string $block, string $stem): void
    {
        $splitAt = null;
        $path = $this->copyWithOps($stem, $block, static function (array $ops) use (&$splitAt): array {
            foreach ($ops as $i => $op) {
                if (($op['op'] ?? null) === 'split') {
                    $splitAt = $i;
                }
            }
            return [...$ops, ['op' => 'compress']];
        });
        self::assertIsInt($splitAt);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/{$block}\\.operations\\[{$splitAt}\\]\\.op 'split' must be the LAST op/");
        FixtureLoader::loadByPath($path);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function splitOptionsMutations(): iterable
    {
        yield 'no options key' => [static function (array $op): array {
            unset($op['options']);
            return $op;
        }];
        yield 'an empty options mapping' => [static fn (array $op): array => [...$op, 'options' => []]];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    #[DataProvider('splitOptionsMutations')]
    public function test_a_split_op_without_options_is_rejected(callable $edit): void
    {
        $path = $this->copyWithOps(
            'ff_lowering_split_pdf_page_groups',
            'lowering',
            static fn (array $ops): array => \array_map(
                static fn (array $op): array => ($op['op'] ?? null) === 'split' ? $edit($op) : $op,
                $ops,
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/split requires a non-empty 'options' mapping/");
        FixtureLoader::loadByPath($path);
    }
}
