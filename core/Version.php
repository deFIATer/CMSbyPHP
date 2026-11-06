<?php
declare(strict_types=1);

namespace Core;

/**
 * Single source of truth for the installed CMSbyPHP version.
 */
final class Version
{
    /** Currently installed application version. */
    public const CURRENT = '3.3.0';

    public static function current(): string
    {
        return self::CURRENT;
    }

    /**
     * Version reduced to its comparable numeric part, e.g. "3.3.0".
     */
    public static function comparable(?string $version = null): string
    {
        $version = ltrim(trim($version ?? self::CURRENT), 'vV');
        $numeric = preg_replace('/[-+].*$/', '', $version);

        return $numeric !== null && $numeric !== '' ? $numeric : $version;
    }
}
