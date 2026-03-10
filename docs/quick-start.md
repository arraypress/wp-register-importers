# Quick Start

Register an importer page, define your fields, and provide a process callback:

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
    // $row contains validated, type-cast, transformed data
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

The library generates a complete admin page with a 3-step wizard for each operation: Upload → Map Fields → Import.
