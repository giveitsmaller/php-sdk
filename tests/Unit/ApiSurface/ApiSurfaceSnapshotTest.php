<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The PHP SDK's public surface, committed as a snapshot (DFtpDIl7).
 *
 * A change to the surface is a change to this file, so it shows up in review
 * as a diff of rows instead of having to be spotted in the code.
 *
 * ⚠️ Rows are names, kinds, modifiers, parents/interfaces, property types and
 * defaults, and constant/case VALUES — NOT method signatures. A new required parameter passes this test
 * (`YebCTMuY`). A green run is not "no breaking change".
 *
 * Regenerate after an INTENDED change with:
 *
 *     GISL_UPDATE_API_SURFACE=1 php vendor/bin/phpunit --filter ApiSurfaceSnapshotTest
 *
 * `src/Generated/` is included on purpose. The codegen-drift check proves the
 * generated code MATCHES THE SPEC; it does not prove the change is COMPATIBLE.
 * A re-vendor that removes an enum case passes drift and breaks consumers,
 * and here it churns this snapshot, which is the point.
 */
#[CoversNothing]
final class ApiSurfaceSnapshotTest extends TestCase
{
    private const SNAPSHOT = __DIR__ . '/api-surface.snapshot.txt';
    private const SOURCE_DIR = __DIR__ . '/../../../src';
    private const FIXTURES = __DIR__ . '/Fixtures';
    private const FIXTURE_NS = 'Gisl\\Sdk\\Tests\\Unit\\ApiSurface\\Fixtures\\';

    public function testThePublicSurfaceMatchesTheCommittedSnapshot(): void
    {
        $actual = ApiSurface::rows(self::SOURCE_DIR, 'Gisl\\Sdk\\');

        if (getenv('GISL_UPDATE_API_SURFACE') === '1') {
            file_put_contents(self::SNAPSHOT, implode("\n", $actual) . "\n");
            self::markTestSkipped('snapshot rewritten; review the diff and commit it');
        }

        $contents = file_get_contents(self::SNAPSHOT);
        self::assertIsString($contents, 'snapshot missing: ' . self::SNAPSHOT);
        $expected = array_values(array_filter(explode("\n", $contents), static fn (string $row): bool => $row !== ''));

        $added = array_values(array_diff($actual, $expected));
        $removed = array_values(array_diff($expected, $actual));
        self::assertSame(
            [[], []],
            [$added, $removed],
            "The PHP SDK public surface changed.\n"
            . 'ADDED:   ' . ($added === [] ? '(none)' : "\n  + " . implode("\n  + ", $added)) . "\n"
            . 'REMOVED: ' . ($removed === [] ? '(none)' : "\n  - " . implode("\n  - ", $removed)) . "\n"
            . "If intended, regenerate: GISL_UPDATE_API_SURFACE=1 php vendor/bin/phpunit --filter ApiSurfaceSnapshotTest\n"
            . "A REMOVED row breaks callers; an ADDED method on an interface breaks implementers.\n"
            . "A moved member shows as one REMOVED plus one ADDED row — check which it is before calling it breaking.",
        );
    }

    public function testTheSnapshotCoversGeneratedCodeAndIsNotTrivial(): void
    {
        $rows = ApiSurface::rows(self::SOURCE_DIR, 'Gisl\\Sdk\\');

        // A population floor, so a discovery root that silently shrinks cannot
        // pass by agreeing with an equally shrunken snapshot.
        self::assertGreaterThan(500, count($rows));
        self::assertNotEmpty(preg_grep('/^case Gisl\\\\Sdk\\\\Generated\\\\SdkSpec\\\\Enums\\\\/', $rows), 'generated enum cases are public API');
        self::assertNotEmpty(preg_grep('/^const Gisl\\\\Sdk\\\\Generated\\\\SdkSpec\\\\/', $rows), 'generated constants are public API');

        // The snapshot comparison is a set difference, blind to order and
        // duplicates, so the extractor's sorted-and-unique contract is pinned here.
        $sorted = $rows;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $rows, 'rows are sorted');
        self::assertSame(count($rows), count(array_unique($rows)), 'rows are unique');
    }

    // --- The extractor, every kind pinned at once ----------------------------
    // A method-only extractor would pass a snapshot test while a property, a
    // constant, an enum case or an interface changed, so each kind is pinned.
    //
    // ⚠️ This golden is EXACT and ORDERED, and nothing regenerates it:
    // GISL_UPDATE_API_SURFACE rewrites the src/ snapshot only. The documented
    // regen command runs this whole class, so a golden it rewrote would bless
    // an extractor regression. Edit it by hand, and only for an intended
    // extractor change. Being exact, it also pins every absence below.
    private const FIXTURE_GOLDEN = [
        // The FULL chain: changing a parent breaks every `catch (Parent $e)`;
        // dropping an interface breaks every instanceof.
        'abstract class ' . self::FIXTURE_NS . 'Clean\\BaseWidget extends RuntimeException < Exception implements Stringable, Throwable',
        // The backing VALUE is recorded: from() depends on it.
        'case ' . self::FIXTURE_NS . 'Clean\\Shade::Dark = "dark"',
        'case ' . self::FIXTURE_NS . 'Clean\\Shade::Light = "light"',
        // ALL implemented interfaces, inherited included (that is what instanceof
        // answers), and the traits. A member-level @internal does not drop it.
        'class ' . self::FIXTURE_NS . 'Clean\\Widget extends ' . self::FIXTURE_NS . 'Clean\\BaseWidget < RuntimeException < Exception'
            . ' implements ' . self::FIXTURE_NS . 'Clean\\Describable, Stringable, Throwable uses ' . self::FIXTURE_NS . 'Clean\\Greets',
        // An inherited interface constant is recorded once, on the interface (no Widget::KIND).
        'const ' . self::FIXTURE_NS . 'Clean\\Describable::KIND = "widget"',
        // A flat scalar map keeps its values: a changed host is API.
        'const ' . self::FIXTURE_NS . 'Clean\\Widget::HOSTS = {"prod":"https://p.example"}',
        // The VALUE is recorded.
        'const ' . self::FIXTURE_NS . 'Clean\\Widget::LIMIT = 3',
        // Behaviour fields are recorded; `description` prose is not.
        'const ' . self::FIXTURE_NS . 'Clean\\Widget::TABLE = {"b":{"retryable":true},"a":{"retryable":false}}',
        // UnitEnum/BackedEnum are engine-added, not listed; nor are from/tryFrom/cases.
        'enum:string ' . self::FIXTURE_NS . 'Clean\\Shade',
        // Only @internal bases UNDER the walk root are folded into the child:
        // no ForeignChild::foreign().
        'final class ' . self::FIXTURE_NS . 'Clean\\ForeignChild extends ' . self::FIXTURE_NS . 'Outside\\ForeignInternalBase',
        // HiddenBase is @internal and has no header, so its parent is only visible here.
        'final class ' . self::FIXTURE_NS . 'Clean\\PublicChild extends ' . self::FIXTURE_NS . 'Clean\\HiddenBase < LogicException < Exception implements Stringable, Throwable',
        // final blocks subclass overrides.
        'final const ' . self::FIXTURE_NS . 'Clean\\Widget::SEALED = "x"',
        // A final method on a non-final class is marked.
        'final method ' . self::FIXTURE_NS . 'Clean\\Widget::locked()',
        'interface ' . self::FIXTURE_NS . 'Clean\\Describable',
        // A class-level @internal excludes only that class, and none of its members.
        'internal ' . self::FIXTURE_NS . 'Clean\\HiddenBase',
        'internal ' . self::FIXTURE_NS . 'Clean\\Plumbing',
        // An inherited member is recorded once, at its declaration (no Widget::inherited(),
        // and nothing of a non-package parent such as getMessage()).
        'method ' . self::FIXTURE_NS . 'Clean\\BaseWidget::inherited()',
        'method ' . self::FIXTURE_NS . 'Clean\\Describable::describe()',
        'method ' . self::FIXTURE_NS . 'Clean\\Greets::greet()',
        // The internal base has no rows, so the public child must carry them.
        'method ' . self::FIXTURE_NS . 'Clean\\PublicChild::leaked()',
        'method ' . self::FIXTURE_NS . 'Clean\\Widget::__construct()',
        'method ' . self::FIXTURE_NS . 'Clean\\Widget::describe()',
        // A trait method is callable on Widget, so it is Widget surface.
        'method ' . self::FIXTURE_NS . 'Clean\\Widget::greet()',
        // `@internal` in prose only is still public API. The tagged members
        // (internalHelper, HIDDEN_LIMIT, $hiddenProperty, the one-line-docblock
        // oneLineInternal) and the protected notPublic are not rows.
        'method ' . self::FIXTURE_NS . 'Clean\\Widget::mentionsInternalInProse()',
        'property ' . self::FIXTURE_NS . 'Clean\\PublicChild::$leakedProperty : int = 0',
        'property ' . self::FIXTURE_NS . 'Clean\\Widget::$label : string = ""',
        'readonly property ' . self::FIXTURE_NS . 'Clean\\Shade::$name : string',
        'readonly property ' . self::FIXTURE_NS . 'Clean\\Shade::$value : string',
        // Promoted readonly properties are API.
        'readonly property ' . self::FIXTURE_NS . 'Clean\\Widget::$size : int',
        'static method ' . self::FIXTURE_NS . 'Clean\\Widget::make()',
        'static property ' . self::FIXTURE_NS . 'Clean\\Widget::$counter : int = 0',
        'trait ' . self::FIXTURE_NS . 'Clean\\Greets',
    ];

    public function testTheExtractorEmitsExactlyTheFixtureGolden(): void
    {
        self::assertSame(self::FIXTURE_GOLDEN, $this->fixtureRows());
    }

    public function testASecondSymbolInAFileFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stowaway is declared in TwoInOne.php');

        ApiSurface::rows(self::FIXTURES . '/Stowaway', self::FIXTURE_NS . 'Stowaway\\');
    }

    public function testASourceFileThatYieldsNoSymbolFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NoSymbolHere.php declares no class');

        ApiSurface::rows(self::FIXTURES . '/Unaudited', self::FIXTURE_NS . 'Unaudited\\');
    }

    /**
     * @return list<string>
     */
    private function fixtureRows(): array
    {
        return ApiSurface::rows(self::FIXTURES . '/Clean', self::FIXTURE_NS . 'Clean\\');
    }
}
