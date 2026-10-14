<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Whether a column added by a later migration exists yet. Migrations are
 * published, not loaded, so an app may run a newer version of the package
 * before it runs the new migration; writes skip the missing columns until then.
 */
final class Columns
{
    /** @var array<string, bool> */
    private static array $known = [];

    public static function has(string $table, string $column): bool
    {
        return self::$known["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }

    /** Tests and migrations change the schema within one process. */
    public static function flush(): void
    {
        self::$known = [];
    }
}
