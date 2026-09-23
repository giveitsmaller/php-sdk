<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface\Fixtures\Clean;

/**
 * @internal
 */
abstract class HiddenBase extends \LogicException
{
    public int $leakedProperty = 0;

    public function leaked(): void
    {
    }
}
