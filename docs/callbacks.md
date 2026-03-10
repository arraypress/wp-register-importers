# Callbacks

## process_callback (required)

The main callback that handles each validated row. Receives a fully processed array where all values have been trimmed, type-cast, transformed, validated, and entity-resolved.

```php
'process_callback' => function( array $row ) {
    // $row['category'] is already a term ID (int), not the name string
    // $row['price'] is already a float, not a string
    // $row['sku'] is already uppercased and trimmed

    $existing = get_posts( [
        'meta_key'       => '_sku',
        'meta_value'     => $row['sku'],
        'post_type'      => 'product',
        'posts_per_page' => 1,
    ] );

    if ( ! empty( $existing ) ) {
        wp_update_post( [
            'ID'         => $existing[0]->ID,
            'post_title' => $row['name'],
        ] );
        update_post_meta( $existing[0]->ID, '_price', $row['price'] );
        return 'updated';
    }

    $post_id = wp_insert_post( [
        'post_title'  => $row['name'],
        'post_type'   => 'product',
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    update_post_meta( $post_id, '_sku', $row['sku'] );
    update_post_meta( $post_id, '_price', $row['price'] );

    return 'created';
},
```

## validate_callback (operation-level)

Optional cross-field validation that runs after individual field validation but before `process_callback`. Receives the validated row. Return `true` to pass or `WP_Error` to reject the row.

```php
'validate_callback' => function( array $row ) {
    if ( ( $row['role'] ?? '' ) === 'administrator' ) {
        return new WP_Error( 'blocked', 'Cannot import administrator accounts.' );
    }

    if ( empty( $row['first_name'] ) && empty( $row['last_name'] ) ) {
        return new WP_Error( 'missing_name', 'At least one name field is required.' );
    }

    return true;
},
```

## validate_callback (per-field)

Custom validation for a single field. Runs after built-in validation. Return `true` to pass or `WP_Error` to reject. Does not modify the value.

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

## process_callback (per-field)

Custom transformation for a single field. Runs after validation. The return value replaces the field value. Return `WP_Error` to reject the row.

```php
'price_raw' => [
    'label'            => 'Price',
    'required'         => true,
    'process_callback' => function( $value, $row ) {
        $cleaned = preg_replace( '/[^0-9.]/', '', str_replace( ',', '', $value ) );
        if ( ! is_numeric( $cleaned ) ) {
            return new WP_Error( 'invalid_price', 'Could not parse price value.' );
        }
        return (float) $cleaned;
    },
],
```

## before_import

Fires once before the first batch starts. Use for setup, connection checks, or cache warming. Return `WP_Error` to abort the import.

```php
'before_import' => function() {
    $api_key = get_option( 'my_plugin_api_key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', 'API key not configured. Go to Settings to add it.' );
    }
},
```

## after_import

Fires once after the last batch completes. Receives the final stats array.

```php
'after_import' => function( array $stats ) {
    delete_transient( 'my_plugin_products_cache' );

    error_log( sprintf(
        'Import complete: %d created, %d updated, %d failed',
        $stats['created'], $stats['updated'], $stats['failed']
    ) );
},
```
