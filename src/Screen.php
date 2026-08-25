<?php
/**
 * Screen
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
 * The import screen, drawn.
 *
 * Core markup throughout: `.wrap`, `.nav-tab-wrapper`, `.postbox`,
 * `.form-table`, `.button`, `.notice`, `.dashicons`. The steps of an import —
 * choose a file, line the columns up, check it, run it — are `<section>`s the
 * script shows one at a time, so the page works as a form before any script
 * loads and the script only hides what is not needed yet.
 *
 * The mapping table is the part worth getting right. It is the only moment
 * where somebody has to understand what the importer wants, and a select per
 * column with the file's own headers in it is the whole of the explanation.
 */
final class Screen {

	/**
	 * The screen.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * Construct.
	 *
	 * @param Importer $importer The screen.
	 */
	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Draw it.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->importer->is_permitted() ) {
			wp_die( esc_html__( 'You are not allowed to import here.', 'arraypress' ), '', [ 'response' => 403 ] );
		}

		$tab = $this->importer->current_tab();

		echo '<div class="wrap">';

		printf( '<h1>%s</h1>', esc_html( (string) $this->importer->get( 'page_title' ) ) );

		if ( '' !== (string) $this->importer->get( 'description', '' ) ) {
			printf( '<p>%s</p>', esc_html( (string) $this->importer->get( 'description' ) ) );
		}

		$this->tabs( $tab );

		$operations = $this->importer->operations( $tab );

		if ( [] === $operations ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'There is nothing to import here yet.', 'arraypress' )
			);
		}

		foreach ( $operations as $operation ) {
			$this->operation( $operation );
		}

		echo '</div>';
	}

	/**
	 * The tabs, when there is more than one.
	 *
	 * @param string $current The tab being looked at.
	 *
	 * @return void
	 */
	private function tabs( string $current ): void {
		$tabs = $this->importer->tabs();

		if ( count( $tabs ) < 2 ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Import sections', 'arraypress' ) . '">';

		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s%s</a>',
				esc_url( $this->importer->url( (string) $key ) ),
				$key === $current ? ' nav-tab-active' : '',
				$key === $current ? ' aria-current="page"' : '',
				'' === (string) $tab['icon']
					? ''
					: sprintf( '<span class="dashicons %s" aria-hidden="true"></span> ', esc_attr( self::dashicon( (string) $tab['icon'] ) ) ),
				esc_html( (string) $tab['label'] )
			);
		}

		echo '</nav>';
	}

	/**
	 * One operation, from choosing a file to running it.
	 *
	 * @param Operation $operation The operation.
	 *
	 * @return void
	 */
	private function operation( Operation $operation ): void {
		$id = sprintf( '%s-%s', $this->importer->id(), $operation->key() );

		printf(
			'<div class="postbox" id="%s" data-importer="%s" data-operation="%s" data-rest="%s" data-nonce="%s">',
			esc_attr( $id ),
			esc_attr( $this->importer->id() ),
			esc_attr( $operation->key() ),
			esc_url( rest_url( Runtime::rest_namespace() ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) )
		);

		printf(
			'<h2 class="hndle"><span class="dashicons %s" aria-hidden="true"></span> %s</h2>',
			esc_attr( self::dashicon( (string) $operation->get( 'icon', 'upload' ) ) ),
			esc_html( (string) $operation->get( 'title' ) )
		);

		echo '<div class="inside">';

		if ( '' !== (string) $operation->get( 'description', '' ) ) {
			printf( '<p>%s</p>', esc_html( (string) $operation->get( 'description' ) ) );
		}

		if ( ! $operation->is_runnable() ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'This importer has no process callback, so there is nothing for it to do with a row. That is a mistake in the plugin rather than in your file.', 'arraypress' )
			);
		}

		$this->columns( $operation );
		$this->choose( $operation );
		$this->mapping( $operation );
		$this->report();

		echo '</div></div>';
	}

	/**
	 * What the file has to contain.
	 *
	 * Shown before anything is uploaded, because it is what somebody needs in
	 * order to go and make the file.
	 *
	 * @param Operation $operation The operation.
	 *
	 * @return void
	 */
	private function columns( Operation $operation ): void {
		echo '<table class="widefat striped"><thead><tr>';

		printf( '<th scope="col">%s</th>', esc_html__( 'Column', 'arraypress' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Holds', 'arraypress' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Needed', 'arraypress' ) );

		echo '</tr></thead><tbody>';

		foreach ( $operation->fields() as $key => $field ) {
			printf(
				'<tr><td><strong>%s</strong><br><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $field['label'] ),
				esc_html( (string) $key ),
				esc_html( self::describe( $field ) ),
				empty( $field['required'] )
					? esc_html__( 'Optional', 'arraypress' )
					: esc_html__( 'Required', 'arraypress' )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p><button type="button" class="button" data-action="sample">%s</button></p>',
			esc_html__( 'Download a sample file', 'arraypress' )
		);
	}

	/**
	 * Choosing a file.
	 *
	 * A plain file input in a plain form. The script takes it over and
	 * uploads without leaving the page; without the script it is still a
	 * control somebody can use.
	 *
	 * @param Operation $operation The operation.
	 *
	 * @return void
	 */
	private function choose( Operation $operation ): void {
		printf(
			'<section data-step="choose"><h3>%s</h3><p><input type="file" accept=".csv,text/csv" data-input="file"> <button type="button" class="button button-primary" data-action="upload"%s>%s</button></p><p class="description">%s</p></section>',
			esc_html__( 'Choose a file', 'arraypress' ),
			$operation->is_runnable() ? '' : ' disabled',
			esc_html__( 'Upload', 'arraypress' ),
			esc_html(
				sprintf(
					/* translators: %s: a file size. */
					__( 'A CSV file, up to %s.', 'arraypress' ),
					size_format( wp_max_upload_size() )
				)
			)
		);
	}

	/**
	 * Lining the columns up.
	 *
	 * Rendered empty and filled in by the script once a file is uploaded and
	 * its headers are known.
	 *
	 * @param Operation $operation The operation.
	 *
	 * @return void
	 */
	private function mapping( Operation $operation ): void {
		printf(
			'<section data-step="map" hidden><h3>%s</h3><table class="form-table" role="presentation"><tbody data-region="mapping"></tbody></table>',
			esc_html__( 'Match the columns', 'arraypress' )
		);

		printf(
			'<p><button type="button" class="button" data-action="check">%s</button> <button type="button" class="button button-primary" data-action="import">%s</button></p>',
			esc_html__( 'Check without importing', 'arraypress' ),
			esc_html__( 'Import', 'arraypress' )
		);

		printf( '<p class="description">%s</p></section>', esc_html__( 'Checking reads the whole file and reports what would go wrong, without changing anything.', 'arraypress' ) );
	}

	/**
	 * What happened.
	 *
	 * @return void
	 */
	private function report(): void {
		printf(
			'<section data-step="report" hidden><h3>%s</h3><p><progress data-region="bar" max="100" value="0"></progress> <span data-region="count" role="status"></span></p><div data-region="summary"></div><div data-region="errors"></div></section>',
			esc_html__( 'Progress', 'arraypress' )
		);
	}

	/**
	 * What a column holds, in words.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 *
	 * @return string
	 */
	private static function describe( array $field ): string {
		$type = (string) ( $field['type'] ?? 'string' );

		$described = match ( $type ) {
			'number'     => __( 'A number', 'arraypress' ),
			'integer'    => __( 'A whole number', 'arraypress' ),
			'boolean'    => __( 'Yes or no', 'arraypress' ),
			'email'      => __( 'An email address', 'arraypress' ),
			'url'        => __( 'A web address', 'arraypress' ),
			'currency'   => __( 'A currency code', 'arraypress' ),
			'country'    => __( 'A country code', 'arraypress' ),
			'date'       => __( 'A date', 'arraypress' ),
			'datetime'   => __( 'A date and a time', 'arraypress' ),
			'post'       => __( 'The name, slug or id of an existing entry', 'arraypress' ),
			'term'       => __( 'A category or tag name', 'arraypress' ),
			'user'       => __( 'A username or email address', 'arraypress' ),
			'attachment' => __( 'The URL or filename of a file in the media library', 'arraypress' ),
			default      => __( 'Text', 'arraypress' ),
		};

		$notes = [];

		if ( '' !== (string) ( $field['date_format'] ?? '' ) ) {
			/* translators: %s: a date format such as d/m/Y. */
			$notes[] = sprintf( __( 'written as %s', 'arraypress' ), (string) $field['date_format'] );
		}

		if ( '' !== (string) ( $field['separator'] ?? '' ) ) {
			/* translators: %s: the characters that separate several values in one column. */
			$notes[] = sprintf( __( 'several, separated by %s', 'arraypress' ), (string) $field['separator'] );
		}

		if ( is_array( $field['options'] ?? null ) && [] !== $field['options'] ) {
			/* translators: %s: the values the column accepts. */
			$notes[] = sprintf( __( 'one of %s', 'arraypress' ), implode( ', ', array_map( 'strval', $field['options'] ) ) );
		}

		return [] === $notes
			? $described
			: sprintf( '%s (%s)', $described, implode( '; ', $notes ) );
	}

	/**
	 * A dashicon class, whether or not the caller wrote the prefix.
	 *
	 * Not ltrim(): that takes a set of characters rather than a prefix, so
	 * `dashicons-chart-bar` comes back as `rt-bar`.
	 *
	 * @param string $icon The icon.
	 *
	 * @return string
	 */
	private static function dashicon( string $icon ): string {
		$icon = sanitize_html_class( $icon );

		return str_starts_with( $icon, 'dashicons-' ) ? $icon : 'dashicons-' . $icon;
	}
}
