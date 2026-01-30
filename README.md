# WordPress Register Importers

A WordPress library for registering import and sync operations with batch processing, field mapping, and progress tracking. Create professional data management interfaces with minimal code.

## Features

- **Unified Registration API** - Single function to register both sync and import operations
- **Tabbed Interface** - Organize operations with a clean, WordPress-native tabbed UI
- **Batch Processing** - Process large datasets without timeouts using AJAX batches
- **CSV Imports** - Upload, preview, map fields, and import CSV files
- **API Syncs** - Pull data from external APIs with cursor-based pagination
- **Progress Tracking** - Real-time progress bars and activity logs
- **Automatic Stats** - Track created, updated, skipped, and failed items
- **Secure File Handling** - UUID filenames, protected directories, auto-cleanup
- **Error Reporting** - Detailed error logs with row numbers

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
        'page_title'  => 'Import & Sync',
        'menu_title'  => 'Import & Sync',
        'parent_slug' => 'my-plugin',
        
        'operations' => [
            // Sync from external API
            'api_products' => [
                'type'             => 'sync',
                'title'            => 'Sync Products',
                'description'      => 'Pull products from external API',
                'batch_size'       => 100,
                'data_callback'    => 'my_fetch_products',
                'process_callback' => 'my_process_product',
            ],
            
            // Import from CSV
            'csv_products' => [
                'type'             => 'import',
                'title'            => 'Import Products',
                'description'      => 'Upload products via CSV',
                'batch_size'       => 100,
                'fields'           => [
                    'sku'   => ['label' => 'SKU', 'required' => true],
                    'name'  => ['label' => 'Name', 'required' => true],
                    'price' => ['label' => 'Price', 'required' => true, 'sanitize_callback' => 'floatval'],
                ],
                'process_callback' => 'my_import_product',
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

```php
'tabs' => [
    'syncs' => [
        'label' => 'External Syncs',
        'icon'  => 'dashicons-update',
    ],
    'importers' => [
        'label' => 'CSV Importers', 
        'icon'  => 'dashicons-upload',
    ],
],
```

### Sync Operation Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `type` | string | Yes | Must be 'sync' |
| `title` | string | Yes | Display title |
| `description` | string | No | Short description |
| `tab` | string | No | Tab to display in (default: 'syncs') |
| `icon` | string | No | Dashicon class |
| `singular` | string | No | Singular item name (default: 'item') |
| `plural` | string | No | Plural item name (default: 'items') |
| `batch_size` | int | No | Items per batch (default: 100) |
| `data_callback` | callable | Yes | Function to fetch data from API |
| `process_callback` | callable | Yes | Function to process each item |

### Import Operation Options

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `type` | string | Yes | Must be 'import' |
| `title` | string | Yes | Display title |
| `description` | string | No | Short description |
| `tab` | string | No | Tab to display in (default: 'importers') |
| `icon` | string | No | Dashicon class |
| `singular` | string | No | Singular item name |
| `plural` | string | No | Plural item name |
| `batch_size` | int | No | Rows per batch (default: 100) |
| `fields` | array | Yes | Field definitions for mapping |
| `update_existing` | bool | No | Allow updating existing records |
| `match_field` | string | No | Field to match for updates |
| `skip_empty_rows` | bool | No | Skip rows with all empty values |
| `validate_callback` | callable | No | Custom validation function |
| `process_callback` | callable | Yes | Function to process each row |

### Field Definition Options

```php
'fields' => [
    'sku' => [
        'label'             => 'SKU',
        'required'          => true,
        'default'           => null,
        'sanitize_callback' => 'sanitize_text_field',
    ],
    'price' => [
        'label'             => 'Price',
        'required'          => true,
        'sanitize_callback' => 'floatval',
    ],
],
```

## Callbacks

### Data Callback (Sync Only)

Fetches a batch of items from an external API.

```php
function my_fetch_products( string $cursor, int $batch_size ): array {
    $response = my_api_client()->get_products([
        'limit'          => $batch_size,
        'starting_after' => $cursor,
    ]);
    
    return [
        'items'    => $response->data,           // Array of items to process
        'has_more' => $response->has_more,       // bool: more items available?
        'cursor'   => $response->last_id,        // string: cursor for next batch
        'total'    => $response->total ?? null,  // int|null: total count if known
    ];
}
```

### Process Callback (Both Sync and Import)

Processes a single item or row. Returns the result status.

```php
function my_process_product( $item ): string|WP_Error {
    // $item is an object (sync) or array (import)
    
    // Validate
    if ( empty( $item['sku'] ) ) {
        return new WP_Error( 'missing_sku', 'SKU is required' );
    }
    
    // Check if exists
    $existing = get_product_by_sku( $item['sku'] );
    
    if ( $existing ) {
        // Update
        update_product( $existing->id, $item );
        return 'updated';
    } else {
        // Create
        create_product( $item );
        return 'created';
    }
    
    // Other valid returns: 'skipped'
}
```

### Validate Callback (Import Only)

Optional pre-processing validation.

```php
function my_validate_row( array $row ): true|WP_Error {
    if ( strlen( $row['sku'] ) < 3 ) {
        return new WP_Error( 'invalid_sku', 'SKU must be at least 3 characters' );
    }
    
    return true;
}
```

## Helper Functions

```php
// Get a registered importers page
$importers = get_importer( 'my-plugin' );

// Check if exists
if ( importer_exists( 'my-plugin' ) ) {
    // ...
}

// Get stats for an operation
$stats = get_importer_stats( 'my-plugin', 'api_products' );
// Returns: ['last_run', 'duration', 'total', 'created', 'updated', 'skipped', 'failed', 'errors', 'history']

// Clear stats
clear_importer_stats( 'my-plugin', 'api_products' );

// Unregister
unregister_importer( 'my-plugin' );

// Get all registered
$all = get_all_importers();
```

## File Security

Uploaded CSV files are stored securely:

- **Location**: `/wp-content/uploads/importers/{page_id}/{uuid}.csv`
- **UUID Filenames**: Original filenames are never used
- **Protected Directory**: `.htaccess` and `index.php` block direct access
- **Auto-Cleanup**: Files deleted after import completion
- **Expiration**: Abandoned files cleaned up after 24 hours

## Stats Storage

Stats are stored in WordPress options:

- **Option Key**: `importers_stats_{page_id}_{operation_id}`
- **Auto-Tracked**: No custom callback required
- **History**: Last 20 runs stored per operation

## REST API Endpoints

The library registers endpoints under `importers/v1/`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/upload` | POST | Upload CSV file |
| `/preview/{uuid}` | GET | Get CSV preview |
| `/import/start` | POST | Initialize import |
| `/import/batch` | POST | Process import batch |
| `/sync/start` | POST | Initialize sync |
| `/sync/batch` | POST | Process sync batch |
| `/complete` | POST | Mark operation complete |
| `/stats/{page}/{op}` | GET | Get operation stats |

## License

GPL-2.0-or-later

## Credits

Developed by [ArrayPress](https://arraypress.com/)
