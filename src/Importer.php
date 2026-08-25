<?php
/**
 * Importer
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use ArrayPress\RegisterImporters\Utils\Runtime;

/**
 * One import screen, and the operations on it.
 *
 * The page itself is thin: a menu entry, a set of tabs, and a list of things
 * that can be imported. Everything that matters happens per operation, and
 * everything that is hard happens per row.
 */
final class Importer {

	/**
	 * Its identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Its configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Its operations, by key.
	 *
	 * @var array<string, Operation>
	 */
	private array $operations = [];

	/**
	 * The screen hook its page was registered under.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Construct.
	 *
	 * @param string               $id     Its identifier.
	 * @param array<string, mixed> $config Its configuration.
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = sanitize_key( $id );
		$this->config = array_merge(
			[
				'page_title'  => __( 'Import', 'arraypress' ),
				'menu_title'  => __( 'Import', 'arraypress' ),
				'menu_slug'   => '',
				'parent_slug' => 'tools.php',
				'capability'  => 'manage_options',
				'icon'        => 'dashicons-upload',
				'position'    => null,
				'description' => '',
				'tabs'        => [],
				'operations'  => [],
			],
			$config
		);

		if ( '' === (string) $this->config['menu_slug'] ) {
			$this->config['menu_slug'] = $this->id;
		}

		foreach ( (array) $this->config['operations'] as $key => $operation ) {
			$this->operations[ sanitize_key( (string) $key ) ] = new Operation( (string) $key, (array) $operation );
		}

		$this->config['tabs'] = $this->normalize_tabs( (array) $this->config['tabs'] );
	}

	/**
	 * Work out the tabs.
	 *
	 * A screen with one group of operations does not need tabs and does not
	 * get them. A screen whose operations name tabs that were never declared
	 * gets those, in the order they were first mentioned, rather than losing
	 * the operations.
	 *
	 * @param array<string, mixed> $declared What the configuration said.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function normalize_tabs( array $declared ): array {
		$tabs = [];

		foreach ( $declared as $key => $tab ) {
			$tabs[ sanitize_key( (string) $key ) ] = is_array( $tab )
				? array_merge( [
					'label' => ucfirst( (string) $key ),
					'icon' => '',
				], $tab )
				: [
					'label' => (string) $tab,
					'icon' => '',
				];
		}

		foreach ( $this->operations as $operation ) {
			$named = sanitize_key( (string) $operation->get( 'tab', '' ) );

			if ( '' !== $named && ! isset( $tabs[ $named ] ) ) {
				$tabs[ $named ] = [
					'label' => ucfirst( str_replace( [ '_', '-' ], ' ', $named ) ),
					'icon' => '',
				];
			}
		}

		return $tabs;
	}

	/**
	 * Its identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * One configuration value.
	 *
	 * @param string $key      The key.
	 * @param mixed  $fallback Returned when it is not set.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * Its page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return (string) $this->config['menu_slug'];
	}

	/**
	 * Its tabs.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function tabs(): array {
		return (array) $this->config['tabs'];
	}

	/**
	 * Its operations, or the ones on one tab.
	 *
	 * @param string $tab A tab, or empty for all of them.
	 *
	 * @return array<string, Operation>
	 */
	public function operations( string $tab = '' ): array {
		if ( '' === $tab ) {
			return $this->operations;
		}

		return array_filter(
			$this->operations,
			static fn( Operation $operation ): bool => sanitize_key( (string) $operation->get( 'tab', '' ) ) === $tab
		);
	}

	/**
	 * One operation.
	 *
	 * @param string $key Its key.
	 *
	 * @return Operation|null
	 */
	public function operation( string $key ): ?Operation {
		return $this->operations[ sanitize_key( $key ) ] ?? null;
	}

	/**
	 * The tab being looked at.
	 *
	 * @return string
	 */
	public function current_tab(): string {
		$tabs = array_keys( $this->tabs() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to show, not acting on it.
		$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $asked, $tabs, true ) ? $asked : (string) ( $tabs[0] ?? '' );
	}

	/**
	 * Whether this user may use the screen.
	 *
	 * @return bool
	 */
	public function is_permitted(): bool {
		return current_user_can( (string) $this->config['capability'] );
	}

	/**
	 * Whether the request is on this screen.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which page is being viewed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $page === $this->slug();
	}

	/**
	 * The screen's URL.
	 *
	 * @param string $tab A tab to open it on.
	 *
	 * @return string
	 */
	public function url( string $tab = '' ): string {
		global $_parent_pages;

		$slug   = $this->slug();
		$parent = (string) ( $_parent_pages[ $slug ] ?? $this->config['parent_slug'] );

		// Core's own rule, from menu_page_url(): a page whose parent is
		// itself a plugin page is reached through admin.php, because the
		// parent has no file of its own. Built rather than borrowed, because
		// menu_page_url() escapes what it returns for display.
		$base = ( '' === $parent || isset( $_parent_pages[ $parent ] ) ) ? 'admin.php' : $parent;

		$args = [ 'page' => $slug ];

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return admin_url( add_query_arg( $args, $base ) );
	}

	/**
	 * Put the screen on the menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$render = fn() => ( new Screen( $this ) )->render();

		$this->hook = (string) (
			'' === (string) $this->config['parent_slug']
				? add_menu_page(
					(string) $this->config['page_title'],
					(string) $this->config['menu_title'],
					(string) $this->config['capability'],
					$this->slug(),
					$render,
					(string) $this->config['icon'],
					$this->config['position']
				)
				: add_submenu_page(
					(string) $this->config['parent_slug'],
					(string) $this->config['page_title'],
					(string) $this->config['menu_title'],
					(string) $this->config['capability'],
					$this->slug(),
					$render
				)
		);
	}

	/**
	 * The screen hook the page was registered under.
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->hook;
	}

	/**
	 * Load what the screen needs.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		arraypress_enqueue_composer_style( Runtime::handle(), __FILE__, 'css/importers.css' );
		arraypress_enqueue_composer_script( Runtime::handle(), __FILE__, 'js/importers.js', [ 'wp-api-fetch' ], false, true );
	}
}
