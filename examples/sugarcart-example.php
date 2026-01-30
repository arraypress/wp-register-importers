<?php
/**
 * Example: Registering Importers for a SugarCart-style Plugin
 *
 * This example demonstrates how to use the wp-register-importers library
 * to create import and sync operations for a plugin.
 *
 * @package     ArrayPress\RegisterImporters
 * @subpackage  Examples
 */

// Prevent direct access
defined( 'ABSPATH' ) || exit;

/**
 * Register importers on the 'admin_menu' hook (priority 20+ to ensure parent exists)
 */
add_action( 'admin_menu', function () {
	if ( ! function_exists( 'register_importers' ) ) {
		return;
	}

	register_importers( 'sugarcart', [
		// Page configuration
		'page_title'  => __( 'Import & Sync', 'sugarcart' ),
		'menu_title'  => __( 'Import & Sync', 'sugarcart' ),
		'parent_slug' => 'sugarcart',
		'capability'  => 'manage_options',

		// Optional branding
		'logo'         => plugins_url( 'assets/images/logo.svg', __FILE__ ),
		'header_title' => __( 'SugarCart Data Manager', 'sugarcart' ),

		// Tabs (optional - auto-generated if not specified)
		'tabs' => [
			'syncs'     => [
				'label' => __( 'External Syncs', 'sugarcart' ),
				'icon'  => 'dashicons-update',
			],
			'importers' => [
				'label' => __( 'CSV Importers', 'sugarcart' ),
				'icon'  => 'dashicons-upload',
			],
		],

		// Operations
		'operations' => [

			// =====================================================
			// SYNC OPERATIONS
			// =====================================================

			'stripe_products' => [
				'type'             => 'sync',
				'tab'              => 'syncs',
				'title'            => __( 'Stripe Products', 'sugarcart' ),
				'description'      => __( 'Sync products and prices from your Stripe account.', 'sugarcart' ),
				'icon'             => 'dashicons-money-alt',
				'singular'         => 'product',
				'plural'           => 'products',
				'batch_size'       => 100,
				'data_callback'    => 'sugarcart_fetch_stripe_products',
				'process_callback' => 'sugarcart_process_stripe_product',
			],

			'stripe_customers' => [
				'type'             => 'sync',
				'tab'              => 'syncs',
				'title'            => __( 'Stripe Customers', 'sugarcart' ),
				'description'      => __( 'Sync customer data from Stripe.', 'sugarcart' ),
				'icon'             => 'dashicons-groups',
				'singular'         => 'customer',
				'plural'           => 'customers',
				'batch_size'       => 100,
				'data_callback'    => 'sugarcart_fetch_stripe_customers',
				'process_callback' => 'sugarcart_process_stripe_customer',
			],

			// =====================================================
			// IMPORT OPERATIONS
			// =====================================================

			'products_csv' => [
				'type'             => 'import',
				'tab'              => 'importers',
				'title'            => __( 'Import Products', 'sugarcart' ),
				'description'      => __( 'Import products from a CSV file.', 'sugarcart' ),
				'icon'             => 'dashicons-products',
				'singular'         => 'product',
				'plural'           => 'products',
				'batch_size'       => 100,
				'update_existing'  => true,
				'match_field'      => 'sku',
				'skip_empty_rows'  => true,
				'fields'           => [
					'sku'         => [
						'label'    => __( 'SKU', 'sugarcart' ),
						'required' => true,
					],
					'name'        => [
						'label'    => __( 'Product Name', 'sugarcart' ),
						'required' => true,
					],
					'price'       => [
						'label'             => __( 'Price', 'sugarcart' ),
						'required'          => true,
						'sanitize_callback' => 'floatval',
					],
					'description' => [
						'label'             => __( 'Description', 'sugarcart' ),
						'required'          => false,
						'sanitize_callback' => 'wp_kses_post',
					],
					'category'    => [
						'label'    => __( 'Category', 'sugarcart' ),
						'required' => false,
					],
					'stock'       => [
						'label'             => __( 'Stock Quantity', 'sugarcart' ),
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
				'process_callback' => 'sugarcart_import_product_row',
			],

			'coupons_csv' => [
				'type'             => 'import',
				'tab'              => 'importers',
				'title'            => __( 'Import Coupons', 'sugarcart' ),
				'description'      => __( 'Import discount coupons from a CSV file.', 'sugarcart' ),
				'icon'             => 'dashicons-tickets-alt',
				'singular'         => 'coupon',
				'plural'           => 'coupons',
				'batch_size'       => 50,
				'fields'           => [
					'code'       => [
						'label'    => __( 'Coupon Code', 'sugarcart' ),
						'required' => true,
					],
					'type'       => [
						'label'    => __( 'Discount Type', 'sugarcart' ),
						'required' => true,
					],
					'amount'     => [
						'label'             => __( 'Amount', 'sugarcart' ),
						'required'          => true,
						'sanitize_callback' => 'floatval',
					],
					'expires_at' => [
						'label'    => __( 'Expiration Date', 'sugarcart' ),
						'required' => false,
					],
				],
				'process_callback' => 'sugarcart_import_coupon_row',
			],

		],
	] );
}, 20 );


// =============================================================================
// CALLBACK IMPLEMENTATIONS
// =============================================================================

/**
 * Fetch Stripe products for sync.
 *
 * @param string $cursor     Pagination cursor (starting_after ID).
 * @param int    $batch_size Number of items to fetch.
 *
 * @return array {
 *     @type array    $items    Array of product objects.
 *     @type bool     $has_more Whether more items exist.
 *     @type string   $cursor   Cursor for next batch.
 *     @type int|null $total    Total items if known.
 * }
 */
function sugarcart_fetch_stripe_products( string $cursor, int $batch_size ): array {
	// Initialize Stripe client (you'd use your actual Stripe SDK)
	$stripe = sugarcart_get_stripe_client();

	$params = [
		'limit'  => $batch_size,
		'active' => true,
		'expand' => [ 'data.default_price' ],
	];

	if ( ! empty( $cursor ) ) {
		$params['starting_after'] = $cursor;
	}

	try {
		$response = $stripe->products->all( $params );

		$items      = $response->data;
		$has_more   = $response->has_more;
		$new_cursor = ! empty( $items ) ? end( $items )->id : '';

		return [
			'items'    => $items,
			'has_more' => $has_more,
			'cursor'   => $new_cursor,
			'total'    => null, // Stripe doesn't provide total count
		];
	} catch ( \Exception $e ) {
		// Throw exception to be caught by the library
		throw new \Exception( 'Stripe API error: ' . $e->getMessage() );
	}
}

/**
 * Process a single Stripe product.
 *
 * @param object $product Stripe product object.
 *
 * @return string|WP_Error 'created', 'updated', 'skipped', or WP_Error
 */
function sugarcart_process_stripe_product( object $product ) {
	global $wpdb;

	$table = $wpdb->prefix . 'sugarcart_products';

	// Check if product exists
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE stripe_id = %s",
		$product->id
	) );

	$data = [
		'stripe_id'   => $product->id,
		'name'        => $product->name,
		'description' => $product->description ?? '',
		'active'      => $product->active ? 1 : 0,
		'updated_at'  => current_time( 'mysql' ),
	];

	// Get price from default_price if expanded
	if ( isset( $product->default_price->unit_amount ) ) {
		$data['price'] = $product->default_price->unit_amount / 100;
	}

	if ( $existing ) {
		// Update existing
		$result = $wpdb->update( $table, $data, [ 'id' => $existing ] );

		if ( $result === false ) {
			return new WP_Error( 'db_error', 'Failed to update product: ' . $wpdb->last_error );
		}

		return 'updated';
	} else {
		// Create new
		$data['created_at'] = current_time( 'mysql' );
		$result             = $wpdb->insert( $table, $data );

		if ( $result === false ) {
			return new WP_Error( 'db_error', 'Failed to create product: ' . $wpdb->last_error );
		}

		return 'created';
	}
}

/**
 * Fetch Stripe customers for sync.
 *
 * @param string $cursor     Pagination cursor.
 * @param int    $batch_size Number of items to fetch.
 *
 * @return array
 */
function sugarcart_fetch_stripe_customers( string $cursor, int $batch_size ): array {
	$stripe = sugarcart_get_stripe_client();

	$params = [ 'limit' => $batch_size ];

	if ( ! empty( $cursor ) ) {
		$params['starting_after'] = $cursor;
	}

	try {
		$response = $stripe->customers->all( $params );

		$items      = $response->data;
		$has_more   = $response->has_more;
		$new_cursor = ! empty( $items ) ? end( $items )->id : '';

		return [
			'items'    => $items,
			'has_more' => $has_more,
			'cursor'   => $new_cursor,
			'total'    => null,
		];
	} catch ( \Exception $e ) {
		throw new \Exception( 'Stripe API error: ' . $e->getMessage() );
	}
}

/**
 * Process a single Stripe customer.
 *
 * @param object $customer Stripe customer object.
 *
 * @return string|WP_Error
 */
function sugarcart_process_stripe_customer( object $customer ) {
	global $wpdb;

	$table = $wpdb->prefix . 'sugarcart_customers';

	// Check if customer exists
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE stripe_id = %s",
		$customer->id
	) );

	$data = [
		'stripe_id'  => $customer->id,
		'email'      => $customer->email ?? '',
		'name'       => $customer->name ?? '',
		'updated_at' => current_time( 'mysql' ),
	];

	if ( $existing ) {
		$wpdb->update( $table, $data, [ 'id' => $existing ] );

		return 'updated';
	} else {
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );

		return 'created';
	}
}

/**
 * Process a product import row.
 *
 * @param array $row Mapped row data with field keys.
 *
 * @return string|WP_Error 'created', 'updated', 'skipped', or WP_Error
 */
function sugarcart_import_product_row( array $row ) {
	global $wpdb;

	$table = $wpdb->prefix . 'sugarcart_products';

	// Validate required fields
	if ( empty( $row['sku'] ) ) {
		return new WP_Error( 'missing_sku', 'SKU is required' );
	}

	if ( empty( $row['name'] ) ) {
		return new WP_Error( 'missing_name', 'Product name is required' );
	}

	if ( ! is_numeric( $row['price'] ) || $row['price'] < 0 ) {
		return new WP_Error( 'invalid_price', 'Invalid price value' );
	}

	// Check if product exists by SKU
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE sku = %s",
		$row['sku']
	) );

	$data = [
		'sku'         => $row['sku'],
		'name'        => $row['name'],
		'price'       => floatval( $row['price'] ),
		'description' => $row['description'] ?? '',
		'category'    => $row['category'] ?? '',
		'stock'       => absint( $row['stock'] ?? 0 ),
		'updated_at'  => current_time( 'mysql' ),
	];

	if ( $existing ) {
		$result = $wpdb->update( $table, $data, [ 'id' => $existing ] );

		if ( $result === false ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		return 'updated';
	} else {
		$data['created_at'] = current_time( 'mysql' );
		$result             = $wpdb->insert( $table, $data );

		if ( $result === false ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		return 'created';
	}
}

/**
 * Process a coupon import row.
 *
 * @param array $row Mapped row data.
 *
 * @return string|WP_Error
 */
function sugarcart_import_coupon_row( array $row ) {
	global $wpdb;

	$table = $wpdb->prefix . 'sugarcart_coupons';

	// Validate coupon code
	$code = strtoupper( trim( $row['code'] ?? '' ) );
	if ( empty( $code ) ) {
		return new WP_Error( 'missing_code', 'Coupon code is required' );
	}

	// Validate type
	$valid_types = [ 'percentage', 'fixed', 'percent', 'amount' ];
	$type        = strtolower( $row['type'] ?? '' );
	if ( ! in_array( $type, $valid_types, true ) ) {
		return new WP_Error( 'invalid_type', 'Invalid discount type. Use: percentage or fixed' );
	}

	// Normalize type
	$type = in_array( $type, [ 'percentage', 'percent' ] ) ? 'percentage' : 'fixed';

	// Validate amount
	$amount = floatval( $row['amount'] ?? 0 );
	if ( $amount <= 0 ) {
		return new WP_Error( 'invalid_amount', 'Amount must be greater than 0' );
	}

	// Check if coupon exists
	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE code = %s",
		$code
	) );

	$data = [
		'code'       => $code,
		'type'       => $type,
		'amount'     => $amount,
		'expires_at' => ! empty( $row['expires_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $row['expires_at'] ) ) : null,
		'updated_at' => current_time( 'mysql' ),
	];

	if ( $existing ) {
		$wpdb->update( $table, $data, [ 'id' => $existing ] );

		return 'updated';
	} else {
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );

		return 'created';
	}
}

/**
 * Helper: Get Stripe client instance.
 *
 * @return \Stripe\StripeClient
 */
function sugarcart_get_stripe_client() {
	$api_key = get_option( 'sugarcart_stripe_secret_key' );

	return new \Stripe\StripeClient( $api_key );
}
