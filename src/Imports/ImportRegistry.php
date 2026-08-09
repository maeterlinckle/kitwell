<?php

declare(strict_types=1);

namespace App\Imports;

use App\Core\Auth;

/**
 * The importer registry.
 *
 * Same shape as the report registry: to add an import, write an Importer
 * subclass and add one line here.
 */
final class ImportRegistry
{
    /** @var array<int,class-string<Importer>> */
    private const IMPORTERS = [
        AssetImporter::class,
        PatImporter::class,
    ];

    /** @var array<string,Importer>|null */
    private static ?array $instances = null;

    /** @return array<string,Importer> */
    public static function all(): array
    {
        if (self::$instances === null) {
            self::$instances = [];

            foreach (self::IMPORTERS as $class) {
                /** @var Importer $importer */
                $importer = new $class();
                self::$instances[$importer->key()] = $importer;
            }
        }

        return self::$instances;
    }

    /** @return array<string,Importer> */
    public static function available(): array
    {
        return array_filter(
            self::all(),
            static fn (Importer $importer): bool => Auth::can($importer->permission())
        );
    }

    public static function find(string $key): ?Importer
    {
        return self::all()[$key] ?? null;
    }
}
