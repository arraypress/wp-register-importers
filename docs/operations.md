# Operations

Each operation represents one import type (e.g. "Import Products", "Import Customers"). Operations are defined as an
associative array within the `operations` key.

```php
'operations' => [
    'import_products' => [
        'title'            => 'Import Products & Prices',
        'description'      => 'Create products and prices from a CSV file.',
        'tab'              => 'products',
        'icon'             => 'dashicons-money-alt',
        'batch_size'       => 25,
        'max_file_size'    => 0,
        'skip_empty_rows'  => true,
        'fields'           => [ ... ],
        'validate_callback' => null,
        'process_callback'  => 'my_process_function',
        'before_import'     => null,
        'after_import'      => null,
    ],
],
```

## Options Reference

| Key                 | Type     | Default                 | Description                                               |
|---------------------|----------|-------------------------|-----------------------------------------------------------|
| `title`             | string   | Auto-generated from key | Display title for the import card                         |
| `description`       | string   | `''`                    | Description shown below the title                         |
| `tab`               | string   | First tab               | Which tab this operation belongs to                       |
| `icon`              | string   | `'dashicons-upload'`    | Dashicon class for the card icon                          |
| `batch_size`        | int      | `100`                   | Rows processed per AJAX request                           |
| `max_file_size`     | int      | `0`                     | Max upload size in bytes (0 = unlimited)                  |
| `skip_empty_rows`   | bool     | `true`                  | Skip rows where all values are empty                      |
| `fields`            | array    | `[]`                    | Field definitions (see [Field Types](fields/overview.md)) |
| `validate_callback` | callable | `null`                  | Operation-level row validation                            |
| `process_callback`  | callable | *required*              | Row processing function                                   |
| `before_import`     | callable | `null`                  | Fires once before import starts                           |
| `after_import`      | callable | `null`                  | Fires once after import completes                         |

## Multiple Operations

A single page can have multiple operations. Each gets its own card with an independent wizard:

```php
'operations' => [
    'import_products' => [
        'title'            => 'Import Products',
        'fields'           => [ ... ],
        'process_callback' => 'process_product_row',
    ],
    'import_customers' => [
        'title'            => 'Import Customers',
        'fields'           => [ ... ],
        'process_callback' => 'process_customer_row',
    ],
],
```
