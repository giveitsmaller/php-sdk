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
    }

    // --- The extractor, one assertion per kind -------------------------------
    // A method-only extractor would pass a snapshot test while a property, a
    // constant, an enum case or an interface changed, so each kind is pinned.

    public function testAPublicMethodIsARow(): void
    {
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\Widget::describe()', $this->fixtureRows());
        self::assertContains('static method ' . self::FIXTURE_NS . 'Clean\\Widget::make()', $this->fixtureRows());
    }

    public function testAPublicPropertyIsARow(): void
    {
        self::assertContains('property ' . self::FIXTURE_NS . 'Clean\\Widget::$label : string = ""', $this->fixtureRows());
        self::assertContains('readonly property ' . self::FIXTURE_NS . 'Clean\\Widget::$size : int', $this->fixtureRows(), 'promoted readonly properties are API');
        self::assertContains('static property ' . self::FIXTURE_NS . 'Clean\\Widget::$counter : int = 0', $this->fixtureRows());
    }

    public function testAPublicConstantIsARow(): void
    {
        self::assertContains('const ' . self::FIXTURE_NS . 'Clean\\Widget::LIMIT = 3', $this->fixtureRows(), 'the VALUE is recorded');
        self::assertContains('final const ' . self::FIXTURE_NS . 'Clean\\Widget::SEALED = "x"', $this->fixtureRows(), 'final blocks subclass overrides');
        self::assertContains(
            'const ' . self::FIXTURE_NS . 'Clean\\Widget::TABLE = {"b":{"retryable":true},"a":{"retryable":false}}',
            $this->fixtureRows(),
            'behaviour fields are recorded; `description` prose is not',
        );
        self::assertContains(
            'const ' . self::FIXTURE_NS . 'Clean\\Widget::HOSTS = {"prod":"https://p.example"}',
            $this->fixtureRows(),
            'a flat scalar map keeps its values: a changed host is API',
        );
    }

    public function testAnEnumCaseIsARow(): void
    {
        $rows = $this->fixtureRows();
        self::assertContains('enum:string ' . self::FIXTURE_NS . 'Clean\\Shade', $rows, 'UnitEnum/BackedEnum are engine-added, not listed');
        self::assertContains('case ' . self::FIXTURE_NS . 'Clean\\Shade::Dark = "dark"', $rows, 'the backing VALUE is recorded: from() depends on it');
        self::assertSame([], preg_grep('/Shade::(from|tryFrom|cases)\(\)/', $rows), 'engine-supplied enum methods are not declared API');
    }

    public function testAnInterfaceIsARow(): void
    {
        self::assertContains('interface ' . self::FIXTURE_NS . 'Clean\\Describable', $this->fixtureRows());
        self::assertContains('const ' . self::FIXTURE_NS . 'Clean\\Describable::KIND = "widget"', $this->fixtureRows());
        self::assertSame([], preg_grep('/Widget::KIND/', $this->fixtureRows()), 'an inherited interface constant is recorded once, on the interface');
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\Describable::describe()', $this->fixtureRows());
    }

    public function testMemberLevelInternalIsExcludedAndProseIsNot(): void
    {
        $rows = $this->fixtureRows();
        self::assertNotContains('method ' . self::FIXTURE_NS . 'Clean\\Widget::internalHelper()', $rows);
        self::assertSame([], preg_grep('/Widget::HIDDEN_LIMIT/', $rows));
        self::assertSame([], preg_grep('/Widget::\$hiddenProperty/', $rows));
        self::assertSame([], preg_grep('/Widget::oneLineInternal\(\)/', $rows), 'a one-line /** @internal */ docblock is a tag too');
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\Widget::mentionsInternalInProse()', $rows);
        self::assertNotEmpty(preg_grep('/^class .*\\\\Clean\\\\Widget extends /', $rows), 'a member-level tag must not drop its class');
    }

    public function testClassLevelInternalExcludesOnlyThatClass(): void
    {
        $rows = $this->fixtureRows();
        self::assertContains('internal ' . self::FIXTURE_NS . 'Clean\\Plumbing', $rows);
        self::assertSame([], preg_grep('/Plumbing::/', $rows));
        self::assertNotEmpty(preg_grep('/^class .*\\\\Clean\\\\Widget extends /', $rows));
    }

    public function testAFinalMethodOnANonFinalClassIsMarked(): void
    {
        self::assertContains('final method ' . self::FIXTURE_NS . 'Clean\\Widget::locked()', $this->fixtureRows());
    }

    public function testAnInternalBaseOutsideTheRootContributesNoRows(): void
    {
        $rows = $this->fixtureRows();
        self::assertSame([], preg_grep('/ForeignChild::foreign\(\)/', $rows), 'only @internal bases UNDER the walk root are folded into the child');
        self::assertContains(
            'final class ' . self::FIXTURE_NS . 'Clean\\ForeignChild extends ' . self::FIXTURE_NS . 'Outside\\ForeignInternalBase',
            $rows,
        );
    }

    public function testNonPublicMembersAreNotRows(): void
    {
        self::assertSame([], preg_grep('/::notPublic\(\)/', $this->fixtureRows()));
    }

    public function testTheHeaderRecordsParentInterfacesAndTraits(): void
    {
        self::assertContains(
            'class ' . self::FIXTURE_NS . 'Clean\\Widget extends ' . self::FIXTURE_NS . 'Clean\\BaseWidget < RuntimeException < Exception'
            . ' implements ' . self::FIXTURE_NS . 'Clean\\Describable, Stringable, Throwable uses ' . self::FIXTURE_NS . 'Clean\\Greets',
            $this->fixtureRows(),
            'changing a parent breaks every `catch (Parent $e)`; dropping an interface breaks every instanceof. '
            . 'ALL implemented interfaces, inherited included: that is what instanceof answers',
        );
        self::assertContains('abstract class ' . self::FIXTURE_NS . 'Clean\\BaseWidget extends RuntimeException < Exception implements Stringable, Throwable', $this->fixtureRows());
        self::assertContains(
            'final class ' . self::FIXTURE_NS . 'Clean\\PublicChild extends ' . self::FIXTURE_NS . 'Clean\\HiddenBase < LogicException < Exception implements Stringable, Throwable',
            $this->fixtureRows(),
            'the FULL chain: HiddenBase is @internal and has no header, so its parent is only visible here',
        );
    }

    public function testATraitIsARowAndItsMethodsReachTheUsingClass(): void
    {
        $rows = $this->fixtureRows();
        self::assertContains('trait ' . self::FIXTURE_NS . 'Clean\\Greets', $rows);
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\Greets::greet()', $rows);
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\Widget::greet()', $rows, 'callable on Widget, so it is Widget surface');
    }

    public function testMembersInheritedFromAnInternalBaseAreRecordedOnThePublicChild(): void
    {
        $rows = $this->fixtureRows();
        self::assertContains('internal ' . self::FIXTURE_NS . 'Clean\\HiddenBase', $rows);
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\PublicChild::leaked()', $rows, 'the base has no rows, so the child must carry them');
        self::assertContains('property ' . self::FIXTURE_NS . 'Clean\\PublicChild::$leakedProperty : int = 0', $rows);
    }

    public function testASecondSymbolInAFileFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stowaway is declared in TwoInOne.php');

        ApiSurface::rows(self::FIXTURES . '/Stowaway', self::FIXTURE_NS . 'Stowaway\\');
    }

    public function testAnInheritedMemberIsRecordedOnceAtItsDeclaration(): void
    {
        $rows = $this->fixtureRows();
        self::assertContains('method ' . self::FIXTURE_NS . 'Clean\\BaseWidget::inherited()', $rows);
        self::assertNotContains('method ' . self::FIXTURE_NS . 'Clean\\Widget::inherited()', $rows);
        self::assertSame([], preg_grep('/::getMessage\(\)/', $rows), 'members of a non-package parent are not this package\'s surface');
    }

    public function testASourceFileThatYieldsNoSymbolFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NoSymbolHere.php declares no class');

        ApiSurface::rows(self::FIXTURES . '/Unaudited', self::FIXTURE_NS . 'Unaudited\\');
    }

    public function testRowsAreSortedAndUnique(): void
    {
        $rows = ApiSurface::rows(self::SOURCE_DIR, 'Gisl\\Sdk\\');
        $sorted = $rows;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $rows);
        self::assertSame(count($rows), count(array_unique($rows)));
    }

    /**
     * @return list<string>
     */
    private function fixtureRows(): array
    {
        return ApiSurface::rows(self::FIXTURES . '/Clean', self::FIXTURE_NS . 'Clean\\');
    }
}
