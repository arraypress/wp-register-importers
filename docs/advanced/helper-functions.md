# Helper Functions

Global functions available via `ArrayPress\RegisterImporters\Utilities\Functions` (auto-loaded by Composer).

## register_importers

Register a CSV importers page.

```php
$importer = register_importers( string $id, array $config ): ?Importers;
```

## get_importer

Get a registered importer page instance by ID.

```php
$importer = get_importer( string $id ): ?Importers;
```

## importer_exists

Check if an importer page is registered.

```php
if ( importer_exists( 'my-plugin' ) ) {
    // ...
}
```

## get_importer_stats

Get the last run stats for a specific operation.

```php
$stats = get_importer_stats( string $page_id, string $operation_id ): array;
```

Returns:
```php
[
    'last_run'    => '2025-01-15 10:30:00',  // or null
    'last_status' => 'complete',              // complete, cancelled, error, or null
    'total'       => 150,
    'created'     => 120,
    'updated'     => 25,
    'skipped'     => 3,
    'failed'      => 2,
    'errors'      => [ ... ],                 // Last 20 errors
    'source_file' => 'products.csv',
]
```

## clear_importer_stats

Clear stats for a specific operation.

```php
clear_importer_stats( string $page_id, string $operation_id ): bool;
```

## unregister_importer

Unregister an importer page.

```php
unregister_importer( string $id ): bool;
```

## cleanup_importer_files

Manually clean up expired upload files (normally handled by daily cron).

```php
$deleted_count = cleanup_importer_files(): int;
```
