<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\ApiSurface\Fixtures\Clean;

class Widget extends BaseWidget implements Describable
{
    use Greets;

    public const LIMIT = 3;

    final public const SEALED = 'x';

    /**
     * @internal
     */
    public const HIDDEN_LIMIT = 4;

    public string $label = '';

    public static int $counter = 0;

    /**
     * @internal
     */
    public int $hiddenProperty = 0;

    public function __construct(public readonly int $size = 0)
    {
    }

    public function describe(): string
    {
        return $this->label;
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Mentions `@internal` in prose only, so it stays public API.
     */
    public function mentionsInternalInProse(): void
    {
    }

    /**
     * @internal Not part of the public API.
     */
    public function internalHelper(): void
    {
    }

    /** @internal One-line docblock form. */
    public function oneLineInternal(): void
    {
    }

    final public function locked(): void
    {
    }

    protected function notPublic(): void
    {
    }
}
