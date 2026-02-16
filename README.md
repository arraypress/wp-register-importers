# wp-register-importers v2.0.0

A WordPress library for creating CSV import interfaces with a declarative, WordPress-style API. Define your fields,
validation rules, and a single process callback — the library handles the UI, file upload, field mapping, batch
processing, progress tracking, and error reporting.

## Installation

Require via Composer in your plugin or theme:

```bash
composer require arraypress/register-importers
```

Then load the autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Quick Start

```php
add_action( 'init', function() {
    register_importers( 'my-plugin', [
        'page_title' => 'Import Data',
        'menu_title' => 'Import',
        'parent_slug' => 'my-plugin-menu',
        'operations' => [
            'import_products' => [
                'title'            => 'Import Products',
                'description'      => 'Import products from a CSV file.',
                'fields'           => [
                    'name' => [
                        'label'    => 'Product Name',
                        'required' => true,
                    ],
                    'price' => [
                        'label'   => 'Price',
                        'type'    => 'number',
                        'minimum' => 0.01,
                    ],
                ],
                'process_callback' => 'my_plugin_process_product',
            ],
        ],
    ] );
}, 20 );

function my_plugin_process_product( array $row ) {
    // $row contains validated, type-cast, transformed data.
    // Return: 'created', 'updated', 'skipped', or WP_Error.
    $post_id = wp_insert_post( [
        'post_title'   => $row['name'],
        'post_content' => $row['description'] ?? '',
        'post_type'    => 'product',
        'post_status'  => 'publish',
    ] );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    update_post_meta( $post_id, '_price', $row['price'] );

    return 'created';
}
```

---

## Page Configuration

The first argument to `register_importers()` is a unique page ID. The second argument is the configuration array:

| Key            | Type   | Default              | Description                                                                                                                                                                                                                                          |
|----------------|--------|----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `page_title`   | string | *required*           | The `<title>` and heading for the admin page                                                                                                                                                                                                         |
| `menu_title`   | string | `page_title`         | Text shown in the WordPress admin menu                                                                                                                                                                                                               |
| `parent_slug`  | string | `null`               | Parent menu slug for submenu placement. Accepts any WordPress menu slug: your plugin's menu (`'my-plugin'`), WooCommerce (`'woocommerce'`), Tools (`'tools.php'`), Settings (`'options-general.php'`), etc. If `null`, creates a top-level menu item |
| `capability`   | string | `'manage_options'`   | WordPress capability required to access the page                                                                                                                                                                                                     |
| `icon`         | string | `'dashicons-upload'` | Dashicon class for top-level menus                                                                                                                                                                                                                   |
| `position`     | int    | `null`               | Menu position                                                                                                                                                                                                                                        |
| `header_title` | string | `page_title`         | Title displayed in the page header                                                                                                                                                                                                                   |
| `logo`         | string | `''`                 | URL to a logo image displayed in the header (max-height 36px)                                                                                                                                                                                        |
| `show_title`   | bool   | `true`               | Whether to display the header title                                                                                                                                                                                                                  |
| `show_tabs`    | bool   | `true`               | Whether to display tabs when tabs are configured                                                                                                                                                                                                     |
| `tabs`         | array  | `[]`                 | Tab definitions (see Tabs section)                                                                                                                                                                                                                   |
| `operations`   | array  | *required*           | Import operation definitions (see Operations section)                                                                                                                                                                                                |
| `help_tabs`    | array  | `[]`                 | WordPress contextual help tabs                                                                                                                                                                                                                       |
| `help_sidebar` | string | `''`                 | WordPress help sidebar content                                                                                                                                                                                                                       |

### Tabs

Tabs organize operations into groups. Each tab has a key and a configuration:

```php
'tabs' => [
    'products' => [
        'label' => 'Products',
        'icon'  => 'dashicons-cart',
    ],
    'customers' => [
        'label' => 'Customers',
        'icon'  => 'dashicons-groups',
    ],
],
```

Then assign operations to tabs via the `tab` key on each operation.

If no tabs are defined, all operations display on a single page. Tabs always display when configured, even with a single
tab.

---

## Operations

Each operation represents one import type (e.g. "Import Products", "Import Customers"). Operations are defined as an
associative array within the `operations` key:

```php
'operations' => [
    'import_products' => [
        // ... operation config
    ],
    'import_customers' => [
        // ... operation config
    ],
],
```

### Operation Configuration

| Key                 | Type     | Default                 | Description                                       |
|---------------------|----------|-------------------------|---------------------------------------------------|
| `title`             | string   | Auto-generated from key | Display title for the import card                 |
| `description`       | string   | `''`                    | Description shown below the title                 |
| `tab`               | string   | First tab               | Which tab this operation belongs to               |
| `icon`              | string   | `'dashicons-upload'`    | Dashicon class for the card icon                  |
| `batch_size`        | int      | `100`                   | Rows processed per AJAX request                   |
| `max_file_size`     | int      | `0`                     | Max upload size in bytes (0 = unlimited)          |
| `skip_empty_rows`   | bool     | `true`                  | Skip rows where all values are empty              |
| `fields`            | array    | `[]`                    | Field definitions (see Fields section)            |
| `validate_callback` | callable | `null`                  | Operation-level row validation (see Callbacks)    |
| `process_callback`  | callable | *required*              | Row processing function (see Callbacks)           |
| `before_import`     | callable | `null`                  | Fires once before import starts (see Callbacks)   |
| `after_import`      | callable | `null`                  | Fires once after import completes (see Callbacks) |

---

## Fields

Fields define the expected columns in the CSV, their types, validation rules, and transformations. The library processes
each field value through a pipeline:

1. **Trim** — Whitespace removed
2. **Default** — Applied if value is empty
3. **Transform** — `uppercase`, `lowercase`
4. **Separator** — Split into array (e.g. `"a|b|c"` → `['a','b','c']`)
5. **Type cast** — Convert to the declared scalar type
6. **Built-in validation** — Required, min/max, pattern, options, etc.
7. **`validate_callback`** — Custom per-field validation (return `true` or `WP_Error`)
8. **`process_callback`** — Custom per-field transformation (return new value or `WP_Error`)
9. **WordPress entity resolution** — Resolve to post/term/user/attachment IDs

### Field Definition

```php
'fields' => [
    'field_key' => [
        'label'             => 'Display Label',
        'type'              => 'string',
        'required'          => false,
        'default'           => null,
        'group'             => null,
        // Validation
        'minimum'           => null,
        'maximum'           => null,
        'min_length'        => null,
        'max_length'        => null,
        'pattern'           => null,
        'options'           => null,
        'unique'            => false,
        // Transforms
        'uppercase'         => false,
        'lowercase'         => false,
        'separator'         => null,
        // Callbacks
        'validate_callback' => null,
        'process_callback'  => null,
        // WordPress entity (post, term, user, attachment)
        'post_type'         => 'post',
        'post_status'       => 'any',
        'taxonomy'          => 'category',
        'match_by'          => 'identifier',
        'create'            => false,
        'meta_key'          => null,
        'sideload'          => false,
    ],
],
```

### Scalar Types

| Type       | Casts to           | Auto-validates                                                  |
|------------|--------------------|-----------------------------------------------------------------|
| `string`   | string             | No additional format validation                                 |
| `number`   | float              | Checks `is_numeric`                                             |
| `integer`  | int                | Checks `is_numeric`, casts with `intval`                        |
| `boolean`  | bool               | Accepts: `true`, `false`, `yes`, `no`, `1`, `0`, `on`, `off`    |
| `email`    | string             | Validates with `is_email()`                                     |
| `url`      | string             | Validates with `filter_var(FILTER_VALIDATE_URL)`                |
| `currency` | string (uppercase) | Validates against ISO 4217 currency codes (USD, EUR, GBP, etc.) |

### WordPress Entity Types

These types resolve CSV values to WordPress object IDs. The resolved ID is what your `process_callback` receives.

| Type         | Resolves to         | `match_by` options                           |
|--------------|---------------------|----------------------------------------------|
| `post`       | Post ID (int)       | `identifier`, `title`, `slug`, `id`, `meta`  |
| `term`       | Term ID (int)       | `identifier`, `name`, `slug`, `id`           |
| `user`       | User ID (int)       | `identifier`, `email`, `login`, `id`, `slug` |
| `attachment` | Attachment ID (int) | `identifier`, `url`, `id`, `filename`        |

#### The `identifier` mode (default)

When `match_by` is `'identifier'` (or omitted), the resolver cascades through multiple strategies automatically based on
the value:

- **Post**: numeric → slug → title
- **Term**: numeric → slug → name
- **User**: numeric → email → login → slug
- **Attachment**: numeric → URL → filename

This is the most flexible option. Use a specific `match_by` value when you know exactly what format the CSV column will
contain.

#### Entity-specific options

**Post fields:**

```php
'related_post' => [
    'label'       => 'Related Post',
    'type'        => 'post',
    'post_type'   => 'product',      // Post type to search (default: 'post')
    'post_status' => 'publish',       // Post status filter (default: 'any')
    'match_by'    => 'title',         // Or: identifier, slug, id, meta
    'meta_key'    => 'sku',           // Required when match_by is 'meta'
],
```

**Term fields:**

```php
'category' => [
    'label'    => 'Category',
    'type'     => 'term',
    'taxonomy' => 'product_cat',     // Taxonomy to search (default: 'category')
    'match_by' => 'name',            // Or: identifier, slug, id
    'create'   => true,              // Auto-create term if not found (default: false)
],
```

**Term fields with separator** (multiple terms per cell):

```php
'tags' => [
    'label'     => 'Tags',
    'type'      => 'term',
    'taxonomy'  => 'post_tag',
    'match_by'  => 'name',
    'create'    => true,
    'separator' => '|',              // "tag1|tag2|tag3" → [12, 15, 18]
],
```

When a term field has a `separator`, each value is resolved individually and the result is an array of term IDs.

**User fields:**

```php
'author' => [
    'label'    => 'Author',
    'type'     => 'user',
    'match_by' => 'email',           // Or: identifier, login, id, slug
],
```

**Attachment fields:**

```php
'thumbnail' => [
    'label'    => 'Featured Image',
    'type'     => 'attachment',
    'match_by' => 'url',             // Or: identifier, id, filename
    'sideload' => true,              // Download remote URL into media library
],
```

#### Non-required entity fields

When a WordPress entity type field is **not required** and the entity is not found, the field returns `null` instead of
failing the row. This allows optional lookups — e.g. an author email that doesn't match any user simply gets skipped.

When the field **is required** and the entity is not found, the row fails with a descriptive error.

### Validation Rules

| Key          | Type      | Description                                                        |
|--------------|-----------|--------------------------------------------------------------------|
| `required`   | bool      | Value must not be empty                                            |
| `minimum`    | float/int | Minimum numeric value (for `number`/`integer` types)               |
| `maximum`    | float/int | Maximum numeric value                                              |
| `min_length` | int       | Minimum string length                                              |
| `max_length` | int       | Maximum string length                                              |
| `pattern`    | string    | Regex pattern the value must match (e.g. `'/^[A-Z0-9\-]+$/i'`)     |
| `options`    | array     | Allowed values (e.g. `['draft', 'publish', 'pending']`)            |
| `unique`     | bool      | Value must be unique within the CSV (checked before import starts) |

### Transforms

| Key         | Type   | Description                                       |
|-------------|--------|---------------------------------------------------|
| `uppercase` | bool   | Convert value to uppercase                        |
| `lowercase` | bool   | Convert value to lowercase                        |
| `separator` | string | Split value into array by this delimiter (e.g. `' |'` or `','`) |
| `default`   | mixed  | Default value when the CSV cell is empty          |

### Field Groups

Fields can be visually grouped in the mapping UI using the `group` key:

```php
'fields' => [
    'sku'    => [ 'label' => 'SKU',          'group' => 'Identification', ... ],
    'name'   => [ 'label' => 'Product Name', 'group' => 'Identification', ... ],
    'price'  => [ 'label' => 'Price',        'group' => 'Pricing',        ... ],
    'stock'  => [ 'label' => 'Stock',        'group' => 'Inventory',      ... ],
],
```

Groups appear as labelled separators in the field mapping step.

---

## Callbacks

### `process_callback` (required)

The main callback that handles each validated row. Receives a fully processed array where all values have been trimmed,
type-cast, transformed, validated, and entity-resolved.

```php
'process_callback' => function( array $row ) {
    // $row['category'] is already a term ID (int), not the name string.
    // $row['price'] is already a float, not a string.
    // $row['sku'] is already uppercased and trimmed.

    // Return values:
    // - 'created'  — Increments created counter
    // - 'updated'  — Increments updated counter
    // - 'skipped'  — Increments skipped counter
    // - WP_Error   — Increments failed counter, error logged
    // - any other  — Treated as 'created'

    $existing = get_posts( [ 'meta_key' => '_sku', 'meta_value' => $row['sku'], 'post_type' => 'product', 'posts_per_page' => 1 ] );

    if ( ! empty( $existing ) ) {
        // Update existing
        wp_update_post( [ 'ID' => $existing[0]->ID, 'post_title' => $row['name'] ] );
        update_post_meta( $existing[0]->ID, '_price', $row['price'] );
        return 'updated';
    }

    $post_id = wp_insert_post( [ 'post_title' => $row['name'], 'post_type' => 'product', 'post_status' => 'publish' ] );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    update_post_meta( $post_id, '_sku', $row['sku'] );
    update_post_meta( $post_id, '_price', $row['price'] );

    return 'created';
},
```

### `validate_callback` (operation-level)

Optional cross-field validation that runs after individual field validation but before `process_callback`. Receives the
validated row. Return `true` to pass or `WP_Error` to reject the row.

```php
'validate_callback' => function( array $row ) {
    // Block admin role imports
    if ( ( $row['role'] ?? '' ) === 'administrator' ) {
        return new WP_Error( 'blocked', 'Cannot import administrator accounts.' );
    }

    // Require at least one name field
    if ( empty( $row['first_name'] ) && empty( $row['last_name'] ) ) {
        return new WP_Error( 'missing_name', 'At least one name field is required.' );
    }

    return true;
},
```

### `validate_callback` (per-field)

Custom validation for a single field. Runs after built-in validation. Return `true` to pass or `WP_Error` to reject. *
*Does not modify the value.**

```php
'discount_code' => [
    'label'             => 'Discount Code',
    'uppercase'         => true,
    'validate_callback' => function( $value, $row ) {
        if ( ! str_starts_with( $value, 'DC-' ) ) {
            return new WP_Error( 'invalid_code', 'Discount code must start with "DC-".' );
        }
        return true;
    },
],
```

### `process_callback` (per-field)

Custom transformation for a single field. Runs after validation. The return value **replaces** the field value. Return
`WP_Error` to reject the row.

```php
'price_raw' => [
    'label'            => 'Price',
    'required'         => true,
    'process_callback' => function( $value, $row ) {
        // Strip currency symbols and commas
        $cleaned = preg_replace( '/[^0-9.]/', '', str_replace( ',', '', $value ) );
        if ( ! is_numeric( $cleaned ) ) {
            return new WP_Error( 'invalid_price', 'Could not parse price value.' );
        }
        return (float) $cleaned;
    },
],
```

### `before_import`

Fires once before the first batch starts. Use for setup, connection checks, cache warming. Can return `WP_Error` to
abort the import with a user-visible error message.

```php
'before_import' => function() {
    $api_key = get_option( 'my_plugin_api_key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', 'API key not configured. Go to Settings to add it.' );
    }
    // Pre-warm cache, initialize connections, etc.
},
```

### `after_import`

Fires once after the last batch completes. Receives the final stats array. Use for cleanup, cache flushing,
notifications.

```php
'after_import' => function( array $stats ) {
    // Clear any caches
    delete_transient( 'my_plugin_products_cache' );

    // Log results
    error_log( sprintf(
        'Import complete: %d created, %d updated, %d failed',
        $stats['created'], $stats['updated'], $stats['failed']
    ) );
},
```

---

## Complete Example

```php
add_action( 'init', function() {
    register_importers( 'sugarcart', [
        'page_title'   => 'Import',
        'menu_title'   => 'Import',
        'parent_slug'  => 'sugarcart',
        'header_title' => 'SugarCart Import',
        'logo'         => plugins_url( 'assets/images/logo.svg', __FILE__ ),

        'tabs' => [
            'products'  => [ 'label' => 'Products',  'icon' => 'dashicons-cart' ],
            'customers' => [ 'label' => 'Customers', 'icon' => 'dashicons-groups' ],
        ],

        'operations' => [
            'import_prices' => [
                'title'       => 'Import Products & Prices',
                'description' => 'Create products and prices from a CSV file.',
                'tab'         => 'products',
                'icon'        => 'dashicons-money-alt',
                'batch_size'  => 25,

                'fields' => [
                    'product_name' => [
                        'label'    => 'Product Name',
                        'required' => true,
                        'group'    => 'Product',
                    ],
                    'product_description' => [
                        'label' => 'Description',
                        'group' => 'Product',
                    ],
                    'amount' => [
                        'label'    => 'Price',
                        'required' => true,
                        'type'     => 'number',
                        'minimum'  => 0.01,
                        'group'    => 'Pricing',
                    ],
                    'currency' => [
                        'label'   => 'Currency',
                        'type'    => 'currency',
                        'default' => 'USD',
                        'group'   => 'Pricing',
                    ],
                    'interval' => [
                        'label'     => 'Billing Interval',
                        'lowercase' => true,
                        'options'   => [ 'day', 'week', 'month', 'year' ],
                        'group'     => 'Pricing',
                    ],
                    'interval_count' => [
                        'label'   => 'Interval Count',
                        'type'    => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                        'group'   => 'Pricing',
                    ],
                    'image_url' => [
                        'label' => 'Image URL',
                        'type'  => 'url',
                        'group' => 'Media',
                    ],
                    'features' => [
                        'label'     => 'Features',
                        'separator' => '|',
                        'group'     => 'Media',
                    ],
                ],

                'before_import'    => function() {
                    if ( empty( get_option( 'sugarcart_stripe_key' ) ) ) {
                        return new WP_Error( 'no_key', 'Stripe API key not configured.' );
                    }
                },
                'process_callback' => 'SugarCart\process_price_import_row',
                'after_import'     => function( $stats ) {
                    delete_transient( 'sugarcart_products_cache' );
                },
            ],

            'import_customers' => [
                'title'       => 'Import Customers',
                'description' => 'Import customers from a CSV file.',
                'tab'         => 'customers',
                'icon'        => 'dashicons-groups',
                'batch_size'  => 50,

                'fields' => [
                    'email' => [
                        'label'    => 'Email',
                        'required' => true,
                        'type'     => 'email',
                        'unique'   => true,
                    ],
                    'name' => [
                        'label' => 'Name',
                    ],
                    'country_code' => [
                        'label'     => 'Country Code',
                        'uppercase' => true,
                        'pattern'   => '/^[A-Z]{2}$/',
                    ],
                ],

                'process_callback' => 'SugarCart\process_customer_import_row',
            ],
        ],
    ] );
}, 20 );
```

---

## UI Features

The library generates a complete admin interface:

- **EDD-style full-bleed header** with optional logo, title, and tab navigation
- **Import cards** displayed as full-width stacked panels
- **3-step wizard** per card: Upload → Map Fields → Import
- **Drag-and-drop file upload** with CSV validation
- **Auto-mapping** of CSV columns to fields by matching column headers to field labels/keys
- **Field groups** displayed as labelled sections in the mapping step
- **Sample CSV download** auto-generated from field definitions with example values
- **Validate button** (dry run) checks all rows without importing, reports errors
- **Batch processing** with real-time progress bar, live stat counters, and activity log
- **Error reporting** with row number, item identifier, and error message in a scrollable table
- **Last import info** shown in card footer with filename and timestamp

---

## REST API Endpoints

The library registers 7 REST API endpoints under the `importers/v1` namespace. All require the configured capability (
default: `manage_options`) and a valid WordPress REST nonce.

| Method | Endpoint                           | Purpose                                        |
|--------|------------------------------------|------------------------------------------------|
| POST   | `/upload`                          | Upload a CSV file                              |
| GET    | `/preview/{uuid}`                  | Get first 5 rows for preview                   |
| GET    | `/sample/{page_id}/{operation_id}` | Download auto-generated sample CSV             |
| POST   | `/dry-run`                         | Validate all rows without importing            |
| POST   | `/import/start`                    | Initialize import, fire `before_import`        |
| POST   | `/import/batch`                    | Process a batch of rows                        |
| POST   | `/complete`                        | Finalize import, fire `after_import`, clean up |

---

## Helper Functions

Available via `ArrayPress\RegisterImporters\Utilities\Functions` (auto-loaded):

```php
// Register importers (global function)
register_importers( string $id, array $config ): ?Importers;

// Get an importer page instance
get_importer( string $id ): ?Importers;

// Get stats for a specific operation
get_importer_stats( string $page_id, string $operation_id ): array;

// Clear stats for a specific operation
clear_importer_stats( string $page_id, string $operation_id ): void;

// Clean up expired upload files
cleanup_importer_files(): int;
```

---

## Security

- **Capability checks** on every REST endpoint via `check_permission()`
- **File ownership** — uploaded files can only be accessed by the user who uploaded them
- **UUID-based filenames** prevent path traversal and guessing
- **MIME type validation** on actual file content (not just extension)
- **Directory protection** with `.htaccess` deny-all and `index.php`
- **Transient-based file metadata** with 24-hour auto-expiry
- **Input sanitization** on all REST parameters: `sanitize_key`, `sanitize_uuid`, `sanitize_field_map`
- **Nonce verification** via WordPress REST API `X-WP-Nonce` header
- **Prepared SQL queries** for any direct database access

---

## Architecture

```
src/
├── Importers.php              Main class — page registration, rendering
├── Registry.php               Singleton registry of all importer pages
├── RestApi.php                REST API endpoint handlers
├── FileManager.php            Secure file upload, storage, cleanup
├── StatsManager.php           Import statistics tracking
├── Traits/
│   ├── AssetManager.php       CSS/JS enqueuing and localization
│   ├── ConfigParser.php       Configuration normalization
│   ├── OperationRenderer.php  Card HTML rendering (wizard steps)
│   └── TabManager.php         Tab navigation management
└── Validation/
    └── FieldValidator.php     Field processing pipeline, entity resolution
```

---

## Field Processing Pipeline Summary

When a CSV is imported, each cell goes through this pipeline in order:

```
Raw CSV value
    → Trim whitespace
    → Apply default (if empty)
    → Transform (uppercase/lowercase)
    → Split by separator (if configured)
    → Type cast (string/number/integer/boolean/email/url/currency)
    → Built-in validation (required/min/max/length/pattern/options)
    → Per-field validate_callback (return true or WP_Error)
    → Per-field process_callback (return new value or WP_Error)
    → WordPress entity resolution (post/term/user/attachment → ID)
    → Final value passed to operation process_callback
```

For `unique` fields, duplicate checking happens across the entire CSV before any rows are processed.

The operation-level `validate_callback` runs after all individual fields pass, giving you access to the full validated
row for cross-field checks.