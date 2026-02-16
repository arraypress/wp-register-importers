<?php
/**
 * Importers Main Class
 *
 * Provides a WordPress admin page for CSV import operations
 * with batch processing, field mapping, validation, and
 * progress tracking.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use ArrayPress\RegisterImporters\Traits\AssetManager;
use ArrayPress\RegisterImporters\Traits\ConfigParser;
use ArrayPress\RegisterImporters\Traits\OperationRenderer;
use ArrayPress\RegisterImporters\Traits\TabManager;

/**
 * Class Importers
 *
 * Main class for registering WordPress CSV importer pages.
 */
class Importers {

	use AssetManager;
	use ConfigParser;
	use OperationRenderer;
	use TabManager;

	/**
	 * Unique identifier for this importers page.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $id;

	/**
	 * Configuration array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected array $config;

	/**
	 * Parsed tabs array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected array $tabs = [];

	/**
	 * Parsed operations array (organized by tab).
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected array $operations = [];

	/**
	 * Page hook suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $hook_suffix = '';

	/**
	 * Default configuration values.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	protected array $defaults = [
		'page_title'   => 'Import',
		'menu_title'   => 'Import',
		'menu_slug'    => '',
		'capability'   => 'manage_options',
		'parent_slug'  => '',
		'icon'         => 'dashicons-upload',
		'position'     => null,
		'tabs'         => [],
		'operations'   => [],
		'show_title'   => true,
		'show_tabs'    => true,
		'body_class'   => '',
		'logo'         => '',
		'header_title' => '',
	];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     Unique identifier for this importers page.
	 * @param array  $config Configuration array.
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = sanitize_key( $id );
		$this->config = wp_parse_args( $config, $this->defaults );

		if ( empty( $this->config['menu_slug'] ) ) {
			$this->config['menu_slug'] = $this->id;
		}

		$this->parse_config();

		Registry::register( $this->id, $this );
		RestApi::register();

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );

		if ( ! empty( $this->config['parent_slug'] ) ) {
			add_filter( 'parent_file', [ $this, 'fix_parent_menu_highlight' ] );
			add_filter( 'submenu_file', [ $this, 'fix_submenu_highlight' ] );
		}

		$this->register_cleanup_cron();
	}

	/**
	 * Register cleanup cron job.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function register_cleanup_cron(): void {
		if ( ! wp_next_scheduled( 'importers_cleanup_files' ) ) {
			wp_schedule_event( time(), 'daily', 'importers_cleanup_files' );
		}

		add_action( 'importers_cleanup_files', [ FileManager::class, 'cleanup_expired' ] );
	}

	/**
	 * Add custom body class to the importers page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $classes Space-separated list of body classes.
	 *
	 * @return string
	 */
	public function add_body_class( string $classes ): string {
		$screen = get_current_screen();

		if ( ! $screen || $screen->id !== $this->hook_suffix ) {
			return $classes;
		}

		$classes .= ' importers importers-' . $this->id;

		if ( ! empty( $this->config['body_class'] ) ) {
			$classes .= ' ' . sanitize_html_class( $this->config['body_class'] );
		}

		return $classes;
	}

	/**
	 * Fix parent menu highlight for importers pages.
	 *
	 * @since 1.0.0
	 *
	 * @param string $parent_file The parent file.
	 *
	 * @return string
	 */
	public function fix_parent_menu_highlight( string $parent_file ): string {
		global $plugin_page;

		if ( $plugin_page === $this->config['menu_slug'] ) {
			return $this->config['parent_slug'];
		}

		return $parent_file;
	}

	/**
	 * Fix submenu highlight for importers pages.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $submenu_file The submenu file.
	 *
	 * @return string|null
	 */
	public function fix_submenu_highlight( ?string $submenu_file ): ?string {
		global $plugin_page;

		if ( $plugin_page === $this->config['menu_slug'] ) {
			return $this->config['menu_slug'];
		}

		return $submenu_file;
	}

	/**
	 * Register the admin menu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( ! empty( $this->config['parent_slug'] ) ) {
			$this->hook_suffix = add_submenu_page(
				$this->config['parent_slug'],
				$this->config['page_title'],
				$this->config['menu_title'],
				$this->config['capability'],
				$this->config['menu_slug'],
				[ $this, 'render_page' ]
			);
		} else {
			$this->hook_suffix = add_menu_page(
				$this->config['page_title'],
				$this->config['menu_title'],
				$this->config['capability'],
				$this->config['menu_slug'],
				[ $this, 'render_page' ],
				$this->config['icon'],
				$this->config['position']
			);
		}
	}

	/**
	 * Render the importers page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->config['capability'] ) ) {
			return;
		}

		$current_tab = $this->get_current_tab();

		?>
		<div class="wrap importers-wrap" data-page-id="<?php echo esc_attr( $this->id ); ?>">

			<?php $this->render_header( $current_tab ); ?>

			<div class="importers-notices">
				<?php settings_errors( $this->id . '_notices' ); ?>
			</div>

			<div class="importers-content">
				<?php $this->render_tab_content( $current_tab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the header with optional logo and tabs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $current_tab Current active tab.
	 *
	 * @return void
	 */
	protected function render_header( string $current_tab ): void {
		$logo_url     = $this->config['logo'] ?? '';
		$header_title = ! empty( $this->config['header_title'] ) ? $this->config['header_title'] : $this->config['page_title'];
		$show_title   = $this->config['show_title'] ?? true;

		?>
		<div class="importers-header">
			<div class="importers-header-top">
				<div class="importers-header-branding">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="" class="importers-header-logo">
					<?php endif; ?>
					<?php if ( $show_title ) : ?>
						<h1 class="importers-header-title"><?php echo esc_html( $header_title ); ?></h1>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $this->config['show_tabs'] && $this->has_multiple_tabs() ) : ?>
				<div class="importers-header-tabs">
					<?php $this->render_tabs( $current_tab ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render content for a specific tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tab Tab key.
	 *
	 * @return void
	 */
	protected function render_tab_content( string $tab ): void {
		if ( isset( $this->tabs[ $tab ]['render_callback'] ) && is_callable( $this->tabs[ $tab ]['render_callback'] ) ) {
			call_user_func( $this->tabs[ $tab ]['render_callback'], $this );

			return;
		}

		$this->render_operations( $tab );
	}

	/**
	 * Get the importers ID.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get a specific config value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get_config( string $key, $default = null ) {
		return $this->config[ $key ] ?? $default;
	}

	/**
	 * Get all operations organized by tab.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operations(): array {
		return $this->operations;
	}

	/**
	 * Get the hook suffix.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

}
