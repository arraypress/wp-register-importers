<?php
/**
 * Example: Registering CSV Importers for a SugarCart-style Plugin
 *
 * Demonstrates how to use the wp-register-importers library (v2)
 * to create CSV import operations with declarative field validation,
 * WordPress entity resolution, and before/after hooks.
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
		'page_title'   => __( 'Import', 'sugarcart' ),
		'menu_title'   => __( 'Import', 'sugarcart' ),
		'parent_slug'  => 'sugarcart',
		'header_title' => __( 'SugarCart Import', 'sugarcart' ),

		// Operations
		'operations' => [

			// =====================================================
			// Import products and prices to Stripe from CSV
			// =====================================================
			'import_prices' => [
				'title'       => __( 'Import Products to Stripe', 'sugarcart' ),
				'description' => __( 'Create products and prices in Stripe from a CSV file.', 'sugarcart' ),
				'icon'        => 'dashicons-cloud-upload',
				'batch_size'  => 25,
				'fields'      => [
					'product_name'        => [
						'label'    => __( 'Product Name', 'sugarcart' ),
						'required' => true,
					],
					'product_description' => [
						'label' => __( 'Description', 'sugarcart' ),
					],
					'amount'              => [
						'label'    => __( 'Price', 'sugarcart' ),
						'required' => true,
						'type'     => 'number',
						'minimum'  => 0.01,
					],
					'currency'            => [
						'label'   => __( 'Currency', 'sugarcart' ),
						'type'    => 'currency',
						'default' => 'USD',
					],
					'interval'            => [
						'label'   => __( 'Billing Interval', 'sugarcart' ),
						'options' => [ 'day', 'week', 'month', 'year' ],
					],
					'interval_count'      => [
						'label'   => __( 'Interval Count', 'sugarcart' ),
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'image_url'           => [
						'label' => __( 'Image URL', 'sugarcart' ),
						'type'  => 'url',
					],
					'features'            => [
						'label'     => __( 'Features', 'sugarcart' ),
						'separator' => '|',
					],
				],

				'before_import'    => function () {
					// Verify Stripe is configured before starting
					$stripe = sugarcart_get_stripe_client();
					if ( ! $stripe->is_configured() ) {
						wp_die( 'Stripe is not configured.' );
					}
				},
				'after_import'     => function ( $stats ) {
					// Clear product caches after import
					delete_transient( 'sugarcart_products_cache' );
				},
				'process_callback' => 'sugarcart_process_price_import_row',
			],

			// =====================================================
			// Import customers from CSV
			// =====================================================
			'import_customers' => [
				'title'       => __( 'Import Customers', 'sugarcart' ),
				'description' => __( 'Import customers from a CSV file.', 'sugarcart' ),
				'icon'        => 'dashicons-groups',
				'batch_size'  => 50,
				'fields'      => [
					'email'        => [
						'label'    => __( 'Email', 'sugarcart' ),
						'required' => true,
						'type'     => 'email',
						'unique'   => true,
					],
					'name'         => [
						'label' => __( 'Name', 'sugarcart' ),
					],
					'country_code' => [
						'label'     => __( 'Country Code', 'sugarcart' ),
						'uppercase' => true,
						'pattern'   => '/^[A-Z]{2}$/',
					],
				],
				'process_callback' => 'sugarcart_process_customer_import_row',
			],

			// =====================================================
			// Import blog posts with WordPress entity resolution
			// =====================================================
			'import_posts' => [
				'title'       => __( 'Import Blog Posts', 'sugarcart' ),
				'description' => __( 'Import blog posts with automatic category and author resolution.', 'sugarcart' ),
				'icon'        => 'dashicons-admin-post',
				'batch_size'  => 20,
				'fields'      => [
					'title'    => [
						'label'    => __( 'Post Title', 'sugarcart' ),
						'required' => true,
					],
					'content'  => [
						'label'    => __( 'Content', 'sugarcart' ),
						'required' => true,
					],
					'category' => [
						'label'    => __( 'Category', 'sugarcart' ),
						'type'     => 'term',
						'taxonomy' => 'category',
						'match_by' => 'name',
						'create'   => true,
					],
					'tags'     => [
						'label'     => __( 'Tags', 'sugarcart' ),
						'type'      => 'term',
						'taxonomy'  => 'post_tag',
						'match_by'  => 'name',
						'create'    => true,
						'separator' => '|',
					],
					'author'   => [
						'label'    => __( 'Author', 'sugarcart' ),
						'type'     => 'user',
						'match_by' => 'email',
					],
					'status'   => [
						'label'   => __( 'Status', 'sugarcart' ),
						'default' => 'draft',
						'options' => [ 'draft', 'publish', 'pending', 'private' ],
					],
				],
				'process_callback' => 'sugarcart_process_post_import_row',
			],

		],
	] );
}, 20 );


// =============================================================================
// CALLBACK IMPLEMENTATIONS
// =============================================================================

/**
 * Process a price import row.
 *
 * By the time this callback receives the row, all field validation
 * has already passed: amount is a float, currency is valid ISO code,
 * interval is one of the allowed values, features is an array, etc.
 *
 * @param array $row Validated and processed row data.
 *
 * @return string|WP_Error 'created', 'updated', or WP_Error.
 */
function sugarcart_process_price_import_row( array $row ): string|WP_Error {
	$stripe = sugarcart_get_stripe_client();

	try {
		// Build Stripe product params
		$product_params = [
			'name'   => $row['product_name'],
			'active' => true,
		];

		if ( ! empty( $row['product_description'] ) ) {
			$product_params['description'] = $row['product_description'];
		}

		if ( ! empty( $row['image_url'] ) ) {
			$product_params['images'] = [ $row['image_url'] ];
		}

		// Features is already an array thanks to the 'separator' field config
		if ( ! empty( $row['features'] ) ) {
			$product_params['marketing_features'] = array_map( function ( $feature ) {
				return [ 'name' => $feature ];
			}, $row['features'] );
		}

		// Create product in Stripe
		$product = $stripe->client->products->create( $product_params );

		// Build price params — amount is already a float from 'type' => 'number'
		$price_params = [
			'product'     => $product->id,
			'currency'    => strtolower( $row['currency'] ),
			'unit_amount' => (int) round( $row['amount'] * 100 ),
			'active'      => true,
		];

		if ( ! empty( $row['interval'] ) ) {
			$price_params['recurring'] = [
				'interval'       => $row['interval'],
				'interval_count' => $row['interval_count'],
			];
		}

		$price = $stripe->client->prices->create( $price_params );

		return 'created';

	} catch ( \Exception $e ) {
		return new WP_Error( 'stripe_error', $e->getMessage() );
	}
}

/**
 * Process a customer import row.
 *
 * Email is already validated as a proper email address.
 * Country code is already uppercased and pattern-validated.
 *
 * @param array $row Validated row data.
 *
 * @return string|WP_Error
 */
function sugarcart_process_customer_import_row( array $row ): string|WP_Error {
	global $wpdb;

	$table = $wpdb->prefix . 'sugarcart_customers';

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE email = %s",
		$row['email']
	) );

	$data = [
		'email'        => $row['email'],
		'name'         => $row['name'] ?? '',
		'country_code' => $row['country_code'] ?? '',
		'updated_at'   => current_time( 'mysql' ),
	];

	if ( $existing ) {
		$wpdb->update( $table, $data, [ 'id' => $existing ] );

		return 'updated';
	}

	$data['created_at'] = current_time( 'mysql' );
	$wpdb->insert( $table, $data );

	return 'created';
}

/**
 * Process a blog post import row.
 *
 * WordPress entity fields are already resolved to IDs:
 * - $row['category'] is a term ID (created if needed)
 * - $row['tags'] is an array of term IDs (created if needed)
 * - $row['author'] is a user ID
 *
 * @param array $row Validated and resolved row data.
 *
 * @return string|WP_Error
 */
function sugarcart_process_post_import_row( array $row ): string|WP_Error {
	$post_data = [
		'post_title'   => $row['title'],
		'post_content' => $row['content'],
		'post_status'  => $row['status'] ?? 'draft',
		'post_type'    => 'post',
	];

	// Author is already resolved to a user ID
	if ( ! empty( $row['author'] ) ) {
		$post_data['post_author'] = $row['author'];
	}

	$post_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Category is already a term ID
	if ( ! empty( $row['category'] ) ) {
		wp_set_post_terms( $post_id, [ $row['category'] ], 'category' );
	}

	// Tags is already an array of term IDs
	if ( ! empty( $row['tags'] ) ) {
		wp_set_post_terms( $post_id, $row['tags'], 'post_tag' );
	}

	return 'created';
}

/**
 * Helper: Get Stripe client instance.
 *
 * @return object
 */
function sugarcart_get_stripe_client() {
	$api_key = get_option( 'sugarcart_stripe_secret_key' );

	return new \Stripe\StripeClient( $api_key );
}
