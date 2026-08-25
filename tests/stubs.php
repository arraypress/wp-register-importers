<?php
/**
 * WordPress stubs.
 *
 * @package ArrayPress\RegisterImporters
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function ri_reset_globals(): void {
	$GLOBALS['ri_posts']       = [];
	$GLOBALS['ri_terms']       = [];
	$GLOBALS['ri_users']       = [];
	$GLOBALS['ri_options']     = [];
	$GLOBALS['ri_transients']  = [];
	$GLOBALS['ri_hooks']       = [];
	$GLOBALS['ri_queries']     = 0;
	$GLOBALS['ri_wrong']       = [];
	$GLOBALS['ri_caps']        = [ 'manage_options' ];
	$GLOBALS['ri_user']        = 1;
	$GLOBALS['ri_menu']        = [];
	$GLOBALS['ri_routes']      = [];
	$GLOBALS['ri_cron']        = [];
	$GLOBALS['_parent_pages']  = [];

	foreach ( [ 'importers', 'hooked' ] as $property ) {
		if ( class_exists( 'ArrayPress\\RegisterImporters\\Importers' ) ) {
			( new ReflectionProperty( 'ArrayPress\\RegisterImporters\\Importers', $property ) )
				->setValue( null, 'hooked' === $property ? false : [] );
		}
	}

	$_GET  = [];
	$_POST = [];

	if ( class_exists( 'ArrayPress\\RegisterImporters\\Row\\Resolve' ) ) {
		ArrayPress\RegisterImporters\Row\Resolve::forget();
	}

	if ( class_exists( 'ArrayPress\\RegisterImporters\\Csv\\Reader' ) ) {
		ArrayPress\RegisterImporters\Csv\Reader::forget();
	}
}

ri_reset_globals();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $context = 'display' ) {
		return str_replace( '&', '&#038;', (string) $url );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title, $fallback = '', $context = 'save' ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9\s-]/', '', $title ) ?? '';

		return trim( preg_replace( '/[\s-]+/', '-', $title ) ?? '', '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ?? '' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['ri_wrong'][] = $message;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['ri_options'] ) ? $GLOBALS['ri_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['ri_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['ri_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['ri_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiry = 0 ) {
		$GLOBALS['ri_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['ri_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['ri_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['ri_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['ri_hooks']['fired'][] = $hook;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

/* ---------------------------------------------------------------------
 * Just enough of the entity APIs for the resolver.
 *
 * Every read counts a query, so a test can assert that looking the same
 * value up twice asks the database once.
 * ------------------------------------------------------------------ */

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		++$GLOBALS['ri_queries'];

		return $GLOBALS['ri_posts'][ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) {
		$post = $GLOBALS['ri_posts'][ (int) $id ] ?? null;

		return $post ? $post->post_type : false;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		++$GLOBALS['ri_queries'];

		foreach ( $GLOBALS['ri_posts'] as $post ) {
			if ( isset( $args['post_type'] ) && 'any' !== $args['post_type'] && $post->post_type !== $args['post_type'] ) {
				continue;
			}

			if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
				continue;
			}

			if ( isset( $args['title'] ) && $post->post_title !== $args['title'] ) {
				continue;
			}

			if ( isset( $args['meta_query'][0] ) ) {
				$want = $args['meta_query'][0];
				$has  = $post->meta[ $want['key'] ] ?? null;

				if ( 'LIKE' === ( $want['compare'] ?? '=' ) ) {
					if ( null === $has || ! str_contains( (string) $has, (string) $want['value'] ) ) {
						continue;
					}
				} elseif ( (string) $has !== (string) $want['value'] ) {
					continue;
				}
			}

			return [ $post->ID ];
		}

		return [];
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $by, $value, $taxonomy = '' ) {
		++$GLOBALS['ri_queries'];

		foreach ( $GLOBALS['ri_terms'] as $term ) {
			if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
				continue;
			}

			$field = [ 'id' => 'term_id', 'slug' => 'slug', 'name' => 'name' ][ $by ] ?? $by;

			if ( (string) $term->$field === (string) $value ) {
				return $term;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $name, $taxonomy, $args = [] ) {
		$id = count( $GLOBALS['ri_terms'] ) + 100;

		$GLOBALS['ri_terms'][ $id ] = (object) [
			'term_id'  => $id,
			'name'     => $name,
			'slug'     => sanitize_title( $name ),
			'taxonomy' => $taxonomy,
		];

		return [ 'term_id' => $id ];
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $by, $value ) {
		++$GLOBALS['ri_queries'];

		foreach ( $GLOBALS['ri_users'] as $user ) {
			$field = [ 'id' => 'ID', 'email' => 'user_email', 'login' => 'user_login', 'slug' => 'user_nicename' ][ $by ] ?? $by;

			if ( (string) ( $user->$field ?? '' ) === (string) $value ) {
				return $user;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( $url ) {
		foreach ( $GLOBALS['ri_posts'] as $post ) {
			if ( 'attachment' === $post->post_type && ( $post->url ?? '' ) === $url ) {
				return $post->ID;
			}
		}

		return 0;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return in_array( $capability, (array) $GLOBALS['ri_caps'], true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['ri_user'];
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		$parts = explode( '?', (string) $url, 2 );
		$query = [];

		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}

		return $parts[0] . '?' . http_build_query( array_merge( $query, (array) $args ) );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon = '', $position = null ) {
		$GLOBALS['ri_menu'][ $menu_slug ] = compact( 'page_title', 'menu_title', 'capability', 'callback' );

		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		$GLOBALS['ri_menu'][ $menu_slug ]       = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'callback' );
		$GLOBALS['_parent_pages'][ $menu_slug ] = $parent_slug;

		return 'tools_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = [] ) {
		throw new RuntimeException( is_string( $message ) ? $message : 'died' );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = [] ) {
		return $GLOBALS['ri_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) {
		$GLOBALS['ri_cron'][ $hook ] = $timestamp;

		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce';
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [], $override = false ) {
		$GLOBALS['ri_routes'][ $namespace . $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response instanceof WP_Error ? $response : new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code() {
		return 401;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		return number_format( (float) $bytes / 1048576, $decimals ) . ' MB';
	}
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() {
		return 8388608;
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class_name, $fallback = '' ) {
		$class_name = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class_name ) ?? '';

		return '' === $class_name ? $fallback : $class_name;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( $min, $max );
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * The parts of a REST response these tests read.
	 */
	class WP_REST_Response {

		/**
		 * Build one.
		 *
		 * @param mixed $data   The body.
		 * @param int   $status Its status.
		 */
		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		/**
		 * The body.
		 *
		 * @return mixed
		 */
		public function get_data(): mixed {
			return $this->data;
		}

		/**
		 * The status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Enough of a request to carry parameters.
	 */
	class WP_REST_Request {

		/**
		 * Build one.
		 *
		 * @param array<string, mixed> $params Its parameters.
		 */
		public function __construct( private array $params = [] ) {}

		/**
		 * One parameter.
		 *
		 * @param string $key Its name.
		 *
		 * @return mixed
		 */
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Set one.
		 *
		 * @param string $key   Its name.
		 * @param mixed  $value Its value.
		 *
		 * @return void
		 */
		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * The method constants.
	 */
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * The parts of a post the resolver touches.
	 */
	class WP_Post {

		/**
		 * Build one.
		 *
		 * @param int                  $ID         Its id.
		 * @param string               $post_type  Its type.
		 * @param string               $post_name  Its slug.
		 * @param string               $post_title Its title.
		 * @param array<string, mixed> $meta       Its meta.
		 * @param string               $url        Its URL, for an attachment.
		 */
		public function __construct(
			public int $ID = 0,
			public string $post_type = 'post',
			public string $post_name = '',
			public string $post_title = '',
			public array $meta = [],
			public string $url = ''
		) {}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * The parts of WP_Error this library uses.
	 */
	class WP_Error {

		/**
		 * The messages, by code.
		 *
		 * @var array<string, string>
		 */
		private array $errors = [];

		/**
		 * The data, by code.
		 *
		 * @var array<string, mixed>
		 */
		private array $data = [];

		/**
		 * Build one.
		 *
		 * @param string $code    Its code.
		 * @param string $message Its message.
		 * @param mixed  $data    Anything else worth carrying.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			if ( '' !== $code ) {
				$this->errors[ $code ] = $message;
				$this->data[ $code ]   = $data;
			}
		}

		/**
		 * The first code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return (string) array_key_first( $this->errors );
		}

		/**
		 * The first message.
		 *
		 * @param string $code Which one.
		 *
		 * @return string
		 */
		public function get_error_message( string $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;

			return (string) ( $this->errors[ $code ] ?? '' );
		}

		/**
		 * What was carried with it.
		 *
		 * @param string $code Which one.
		 *
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->data[ $code ] ?? null;
		}

		/**
		 * Carry something with it.
		 *
		 * @param mixed  $data The data.
		 * @param string $code Which one.
		 *
		 * @return void
		 */
		public function add_data( $data, $code = '' ): void {
			$code = '' === $code ? $this->get_error_code() : $code;

			$this->data[ $code ] = $data;
		}
	}
}
