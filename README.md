# WordPress Register Importers

A WordPress library for creating CSV import interfaces with a declarative, WordPress-style API. Define your fields, validation rules, and a single process callback — the library handles the UI, file upload, field mapping, batch processing, progress tracking, and error reporting.

## Installation

```bash
composer require arraypress/wp-register-importers
```

## Quick Start

```php
add_action( 'init', function() {
    register_importers( 'my-plugin', [
        'page_title'  => 'Import Data',
        'menu_title'  => 'Import',
        'parent_slug' => 'my-plugin-menu',
        'operations'  => [
            'import_products' => [
                'title'       => 'Import Products',
                'description' => 'Import products from a CSV file.',
                'fields'      => [
                    'name'  => [ 'label' => 'Product Name', 'required' => true ],
                    'price' => [ 'label' => 'Price', 'type' => 'number', 'minimum' => 0.01 ],
                ],
                'process_callback' => function( array $row ) {
                    $post_id = wp_insert_post( [
                        'post_title' => $row['name'],
                        'post_type'  => 'product',
                        'post_status' => 'publish',
                    ] );
                    if ( is_wp_error( $post_id ) ) {
                        return $post_id;
                    }
                    update_post_meta( $post_id, '_price', $row['price'] );
                    return 'created';
                },
            ],
        ],
    ] );
}, 20 );
```

## Documentation

Full documentation is available at **[https://arraypress.github.io/wp-register-importers](https://arraypress.github.io/wp-register-importers)**

## Requirements

- PHP 8.1+
- WordPress 6.0+

## License

GPL-2.0-or-later
