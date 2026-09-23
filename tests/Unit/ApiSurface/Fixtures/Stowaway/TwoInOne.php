<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface\Fixtures\Stowaway;

// Fixture: the path names TwoInOne, and the file ALSO declares Stowaway.
final class TwoInOne
{
}

final class Stowaway
{
    public function hidden(): void
    {
    }
}
