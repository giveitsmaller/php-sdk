<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface\Fixtures\Clean;

interface Describable
{
    public const KIND = 'widget';

    public function describe(): string;
}
