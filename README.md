# WordPress Register Importers

A WordPress library for registering import and sync operations with batch processing, field mapping, and progress tracking. Create professional data management interfaces with minimal code.

## Features

- **Unified Registration API** - Single function to register both sync and import operations
- **Tabbed Interface** - Organize operations with a clean, WordPress-native tabbed UI
- **Batch Processing** - Process large datasets without timeouts using AJAX batches
- **CSV Imports** - Upload, preview, map fields, and import CSV files
- **API Syncs** - Pull data from external APIs with cursor-based pagination
- **Real-time Progress** - Progress bars and live activity logs
- **Dynamic Preview** - CSV preview updates in real-time as you change field mappings
- **Automatic Stats** - Track created, updated, skipped, and failed items
- **Error History** - View and copy past errors with one click
- **Secure File Handling** - UUID filenames, protected directories, auto-cleanup
- **Responsive Grid** - Cards adapt from 1-4 columns based on count

## Requirements

- PHP 8.1 or higher
- WordPress 6.0 or higher
- Composer

## Installation

```bash
composer require arraypress/wp-register-importers
```

## Quick Start

```php
add_action( 'admin_menu', function() {
    register_importers( 'my-plugin', [
        'page_title'   => 'Import & Sync',
        'menu_title'   => 'Import & Sync',
        'parent_slug'  => 'my-plugin',
        'header_title' => 'Data Management Center',
        
        'operations' => [
            // Sync from external API
            'stripe_products' => [
                'type'             => 'sync',
                'tab'              => 'syncs',
                'title'            => 'Stripe Products',
                'description'      => 'Sync products from Stripe API',
                'icon'             => 'dashicons-money-alt',
                'singular'         => 'product',
                'plural'           => 'products',
                'batch_size'       => 100,
                'data_callback'    => 'my_fetch_stripe_products',
                'process_callback' => 'my_process_stripe_product',
            ],
            
            // Import from CSV
            'csv_products' => [
                'type'             => 'import',
                'tab'              => 'importers',
                'title'            => 'Import Products',
                'description'      => 'Upload products via CSV file',
                'icon'             => 'dashicons-upload',
                'batch_size'       => 50,
                'update_existing'  => true,
                'match_field'      => 'sku',
                'skip_empty_rows'  => true,
                'fields'           => [
                    'sku'         => ['label' => 'SKU', 'required' => true],
                    'name'        => ['label' => 'Name', 'required' => true],
                    'price'       => ['label' => 'Price', 'required' => true, 'sanitize_callback' => 'floatval'],
                    'description' => ['label' => 'Description', 'sanitize_callback' => 'wp_kses_post'],
                    'category'    => ['label' => 'Category'],
                    'stock'       => ['label' => 'Stock', 'sanitize_callback' => 'absint', 'default' => 0],
                ],
                'validate_callback' => 'my_validate_product_row',
                'process_callback'  => 'my_import_product_row',
            ],
        ],
    ]);
}, 20 );
```

## Configuration Options

### Page Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `page_title` | string | 'Import & Sync' | Page title shown in browser |
| `menu_title` | string | 'Import & Sync' | Menu item text |
| `menu_slug` | string | (id) | URL slug for the page |
| `capability` | string | 'manage_options' | Required capability |
| `parent_slug` | string | '' | Parent menu slug (for submenu) |
| `icon` | string | 'dashicons-database-import' | Menu icon (top-level only) |
| `position` | int | null | Menu position |
| `logo` | string | '' | URL to header logo image |
| `header_title` | string | (page_title) | Custom header title |
| `show_title` | bool | true | Show page title |
| `show_tabs` | bool | true | Show tab navigation |

### Tab Options

Customize the default tabs or create your own:

```php
'tabs' => [
    'syncs' => [
        'label' => 'API Syncs',
        'icon'  => 'dashicons-update',
    ],
    'importers' => [
        'label' => 'CSV Imports', 
        'icon'  => 'dashicons-upload',
    ],
    // Add custom tabs
    'exports' => [
        'label' => 'Exports',
        'icon'  => 'dashicons-download',
    ],
],
```

### Sync Operation Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `type` | string | Yes | Must be `'sync'` |
| `title` | string | Yes | Display title |
| `description` | string | No | Short description |
| `tab` | string | No | Tab to display in (default: `'syncs'`) |
| `icon` | string | No | Dashicon class (e.g., `'dashicons-cloud'`) |
| `singular` | string | No | Singular item name (default: `'item'`) |
| `plural` | string | No | Plural item name (default: `'items'`) |
| `batch_size` | int | No | Items per batch (default: `100`) |
| `data_callback` | callable | Yes | Function to fetch data from API |
| `process_callback` | callable | Yes | Function to process each item |

### Import Operation Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `type` | string | Yes | Must be `'import'` |
| `title` | string | Yes | Display title |
| `description` | string | No | Short description |
| `tab` | string | No | Tab to display in (default: `'importers'`) |
| `icon` | string | No | Dashicon class |
| `singular` | string | No | Singular item name |
| `plural` | string | No | Plural item name |
| `batch_size` | int | No | Rows per batch (default: `100`) |
| `fields` | array | Yes | Field definitions for mapping |
| `update_existing` | bool | No | Allow updating existing records |
| `match_field` | string | No | Field to match for updates |
| `skip_empty_rows` | bool | No | Skip rows with all empty values |
| `validate_callback` | callable | No | Custom row validation function |
| `process_callback` | callable | Yes | Function to process each row |

### Field Definition Options

```php
'fields' => [
    'sku' => [
        'label'             => 'SKU',           // Display label
        'required'          => true,            // Must be mapped
        'default'           => null,            // Default value if empty
        'sanitize_callback' => 'sanitize_text_field', // Sanitization function
    ],
    'price' => [
        'label'             => 'Price',
        'required'          => true,
        'sanitize_callback' => 'floatval',
    ],
    'description' => [
        'label'             => 'Description',
        'required'          => false,
        'sanitize_callback' => 'wp_kses_post',  // Allow safe HTML
    ],
    'stock' => [
        'label'             => 'Stock Quantity',
        'sanitize_callback' => 'absint',
        'default'           => 0,               // Default to 0 if not provided
    ],
],
```

## Callbacks

### Data Callback (Sync Only)

Fetches a batch of items from an external source. Called repeatedly until `has_more` is `false`.

```php
/**
 * Fetch products from Stripe API.
 *
 * @param string $cursor    Cursor from previous batch (empty on first call).
 * @param int    $batch_size Number of items to fetch.
 *
 * @return array {
 *     @type array       $items    Array of items to process.
 *     @type bool        $has_more Whether more items are available.
 *     @type string      $cursor   Cursor for next batch.
 *     @type int|null    $total    Total count if known (for progress bar).
 * }
 */
function my_fetch_stripe_products( string $cursor, int $batch_size ): array {
    $stripe = new \Stripe\StripeClient( get_option( 'stripe_secret_key' ) );
    
    $params = [ 'limit' => $batch_size ];
    if ( $cursor ) {
        $params['starting_after'] = $cursor;
    }
    
    $response = $stripe->products->all( $params );
    
    $last_item = end( $response->data );
    
    return [
        'items'    => $response->data,
        'has_more' => $response->has_more,
        'cursor'   => $last_item ? $last_item->id : '',
        'total'    => null, // Stripe doesn't provide total count
    ];
}
```

### Process Callback (Both Sync and Import)

Processes a single item (sync) or row (import). Must return a status string or `WP_Error`.

**Valid return values:**
- `'created'` - New record was created
- `'updated'` - Existing record was updated
- `'skipped'` - Record was intentionally skipped
- `WP_Error` - Processing failed with error message

```php
/**
 * Process a product from Stripe.
 *
 * @param object|array $item The item to process (object for sync, array for import).
 *
 * @return string|WP_Error Result status.
 */
function my_process_stripe_product( $item ): string|WP_Error {
    global $wpdb;
    
    // For sync, $item is typically an object from the API
    $stripe_id = $item->id;
    $name      = $item->name;
    $price     = $item->default_price?->unit_amount / 100;
    
    // Check if product exists
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}products WHERE stripe_id = %s",
        $stripe_id
    ) );
    
    $data = [
        'stripe_id' => $stripe_id,
        'name'      => $name,
        'price'     => $price,
        'active'    => $item->active ? 1 : 0,
        'updated_at' => current_time( 'mysql' ),
    ];
    
    if ( $existing ) {
        $wpdb->update(
            $wpdb->prefix . 'products',
            $data,
            [ 'id' => $existing->id ]
        );
        return 'updated';
    } else {
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'products', $data );
        return 'created';
    }
}

/**
 * Process a row from CSV import.
 *
 * @param array $row Mapped and sanitized row data.
 *
 * @return string|WP_Error Result status.
 */
function my_import_product_row( array $row ): string|WP_Error {
    global $wpdb;
    
    // Row keys match your field definitions
    $sku   = $row['sku'];
    $name  = $row['name'];
    $price = $row['price'];
    
    // Check for existing by SKU
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}products WHERE sku = %s",
        $sku
    ) );
    
    if ( $existing ) {
        $wpdb->update(
            $wpdb->prefix . 'products',
            [
                'name'       => $name,
                'price'      => $price,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $existing->id ]
        );
        return 'updated';
    } else {
        $wpdb->insert( $wpdb->prefix . 'products', [
            'sku'        => $sku,
            'name'       => $name,
            'price'      => $price,
            'created_at' => current_time( 'mysql' ),
        ] );
        return 'created';
    }
}
```

### Validate Callback (Import Only)

Optional pre-processing validation. Called before `process_callback` for each row.

```php
/**
 * Validate a product row before import.
 *
 * @param array $row The mapped row data.
 *
 * @return true|WP_Error True if valid, WP_Error if invalid.
 */
function my_validate_product_row( array $row ): true|WP_Error {
    // Check SKU length
    if ( strlen( $row['sku'] ) < 3 ) {
        return new WP_Error( 'invalid_sku', 'SKU must be at least 3 characters' );
    }
    
    // Check price is positive
    if ( $row['price'] <= 0 ) {
        return new WP_Error( 'invalid_price', 'Price must be greater than 0' );
    }
    
    // Check for duplicate SKU in same import
    static $seen_skus = [];
    if ( in_array( $row['sku'], $seen_skus, true ) ) {
        return new WP_Error( 'duplicate_sku', 'Duplicate SKU in import file' );
    }
    $seen_skus[] = $row['sku'];
    
    return true;
}
```

## Helper Functions

```php
// Register importers (primary function)
register_importers( 'my-plugin', $config );

// Get a registered importers instance
$importers = get_importer( 'my-plugin' );

// Check if an importer page exists
if ( importer_exists( 'my-plugin' ) ) {
    // ...
}

// Get stats for a specific operation
$stats = get_importer_stats( 'my-plugin', 'stripe_products' );
/*
Returns array:
[
    'last_run'     => '2025-01-30 12:00:00',
    'last_status'  => 'complete', // 'complete', 'error', 'cancelled'
    'duration'     => 45,         // seconds
    'total'        => 150,
    'created'      => 10,
    'updated'      => 140,
    'skipped'      => 0,
    'failed'       => 0,
    'errors'       => [],         // Array of error details
    'history'      => [],         // Last 20 runs
    'run_count'    => 5,
    'source_file'  => null,       // For imports: original filename
]
*/

// Clear stats for an operation
clear_importer_stats( 'my-plugin', 'stripe_products' );

// Unregister an importer page
unregister_importer( 'my-plugin' );

// Get all registered importer pages
$all = get_all_importers();
```

## User Interface Features

### Activity Log

Both sync and import operations display a real-time activity log showing:
- Batch progress with counts (created, updated, skipped, failed)
- Individual errors with item identifiers
- Completion summary with duration

The log can be closed after completion using the X button.

### Error History

When operations have errors, the "Errors" count becomes clickable. Clicking it reveals a panel showing:
- Recent errors from the last run (up to 20 displayed)
- Item identifier and error message
- "Copy" button to copy all errors to clipboard as tab-separated text

### Dynamic Field Mapping Preview

During CSV import, the preview table updates in real-time as you change field mappings:
- Shows your target fields as columns (not raw CSV headers)
- Updates immediately when you change a dropdown
- Unmapped fields appear grayed out with "(unmapped)" label
- Helps verify your mapping is correct before starting import

### Responsive Grid Layout

Operation cards automatically arrange based on count:
- 1 operation: Full width
- 2 operations: 2 columns
- 3 operations: 3 columns
- 4+ operations: Responsive grid (max 4 columns on large screens)

Collapses to fewer columns on smaller screens.

### Step Navigation (Imports)

Import operations use a 4-step wizard:
1. **Upload** - Drop or select CSV file
2. **Map Fields** - Map CSV columns to your fields with live preview
3. **Processing** - Watch progress with activity log
4. **Complete** - Summary with stats and error details

## File Security

Uploaded CSV files are stored securely:

- **Location**: `/wp-content/uploads/importers/{page_id}/{uuid}.csv`
- **UUID Filenames**: Original filenames are never exposed in the filesystem
- **Protected Directory**: `.htaccess` and `index.php` block direct access
- **Auto-Cleanup**: Files deleted after import completion
- **Expiration**: Abandoned files cleaned up after 24 hours via WP-Cron

## Stats Storage

Stats are stored in WordPress options:

- **Option Key**: `importers_stats_{page_id}_{operation_id}`
- **Auto-Tracked**: No custom code required
- **History**: Last 20 runs stored per operation
- **Error Limit**: Up to 50 errors stored per run

## REST API Endpoints

The library registers endpoints under `importers/v1/`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/upload` | POST | Upload CSV file |
| `/preview/{uuid}` | GET | Get CSV preview (first 5 rows + headers) |
| `/import/start` | POST | Initialize import operation |
| `/import/batch` | POST | Process import batch |
| `/sync/start` | POST | Initialize sync operation |
| `/sync/batch` | POST | Process sync batch |
| `/complete` | POST | Mark operation complete |
| `/stats/{page}/{operation}` | GET | Get operation stats |

All endpoints require the `wp_rest` nonce and appropriate user capabilities.

## Complete Example

See the `examples/sugarcart-example.php` file for a complete working example including:
- Multiple sync operations (products, customers, orders)
- CSV import with validation
- Database table creation on activation
- All callback implementations

## Changelog

### 1.0.0
- Initial release
- Sync and import operations with batch processing
- CSV field mapping with dynamic preview
- Real-time activity logs
- Clickable error history with copy to clipboard
- Responsive card grid layout
- Automatic stats tracking and history
- Secure file handling with auto-cleanup

## License

GPL-2.0-or-later

## Credits

Developed by [ArrayPress](https://arraypress.com/)
