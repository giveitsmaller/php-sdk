<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Support;

/**
 * A stream wrapper whose paths are regular files that cannot be opened.
 *
 * Why not chmod 0: root bypasses POSIX read permissions, and both CI and
 * scripts/local-suite.sh run PHPUnit as root, so a permission-based test of
 * the read-failure branch never executed anywhere (fz53RLmS). `url_stat`
 * reports a regular file, so `is_file()` passes; `stream_open` refuses, so
 * `file_get_contents()` returns false. The caller's identity cannot change
 * either answer.
 */
final class UnreadableProfileStreamWrapper
{
    public const SCHEME = 'gisl-unreadable';

    /** @var resource|null Assigned by PHP on every wrapper instance. */
    public $context;

    public static function register(): void
    {
        if (!stream_wrapper_register(self::SCHEME, self::class)) {
            throw new \RuntimeException('Could not register the ' . self::SCHEME . ' stream wrapper');
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        // 0100000 is S_IFREG: a regular file, and world-readable on paper.
        return ['mode' => 0100644, 'size' => 64];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
