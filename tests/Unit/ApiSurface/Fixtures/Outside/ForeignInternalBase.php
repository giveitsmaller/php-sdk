<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface\Fixtures\Outside;

/**
 * Fixture: an @internal class OUTSIDE the walk root (as a vendor class could be).
 *
 * @internal
 */
abstract class ForeignInternalBase
{
    public function foreign(): void
    {
    }
}
