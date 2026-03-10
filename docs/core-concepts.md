# Core Concepts

## How It Works

1. You call `register_importers()` with a page ID and configuration array
2. The library creates a WordPress admin page with one or more import operations
3. Each operation is a card with a 3-step wizard: **Upload → Map Fields → Import**
4. Users upload a CSV, map columns to your defined fields, and run the import
5. Each row passes through the field processing pipeline, then your `process_callback`

## Page → Operations → Fields

The hierarchy is straightforward:

- **Page** — a single admin menu page (e.g. "Import")
- **Operations** — one or more import types on that page (e.g. "Import Products", "Import Customers")
- **Fields** — the expected columns for each operation, with types and validation rules

## Data Flow

```
CSV Upload
    → File validation & storage
    → Column-to-field mapping (user maps headers to fields)
    → Per-row processing:
        Raw CSV value
            → Trim → Default → Transform → Split → Cast → Validate → Process → Resolve
            → Final value passed to process_callback
    → process_callback returns: 'created', 'updated', 'skipped', or WP_Error
```

## Return Values

Your `process_callback` should return one of:

| Return Value    | Effect                                                  |
|-----------------|---------------------------------------------------------|
| `'created'`     | Increments created counter                              |
| `'updated'`     | Increments updated counter                              |
| `'skipped'`     | Increments skipped counter                              |
| `WP_Error`      | Increments failed counter, error logged with row number |
| Any other value | Treated as `'created'`                                  |

## Registry

Every registered importer page is stored in a singleton registry. You can retrieve instances later:

```php
$importer = get_importer( 'my-plugin' );
$stats    = get_importer_stats( 'my-plugin', 'import_products' );
```
