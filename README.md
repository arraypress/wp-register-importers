# Register Importers

A CSV import screen: column mapping, validation, a dry run and batching,
from a description of the fields.

## What it does

Importing a CSV properly is more work than it sounds. The columns are never
in the order you expect, so they have to be mapped. The values are strings,
so they have to be validated and converted. A file of ten thousand rows will
time out, so it has to be batched. And a mistake found on row 4,000 is a mess
to undo, so it needs a dry run first.

This is all of that, generated from a list of fields and one callback that
handles a single row.

## Features

* Let the user map their columns to your fields, in whatever order they came
* Validate and convert values by type — numbers, booleans, dates, terms
* Create a missing term during import, rather than failing the row
* Run the whole file without writing anything, and report what would happen
* Import in batches, so a large file does not time out
* Report failures per row and carry on, instead of stopping at the first
* Put several importers behind one screen, as tabs

## Installation

```bash
composer require arraypress/wp-register-importers
```

## Quick start

```php
add_action( 'admin_menu', function () {
	register_importers( 'myplugin-import', [
		'parent_slug' => 'tools.php',
		'page_title'  => __( 'Import', 'my-plugin' ),
		'operations'  => [
			'products' => [
				'title'  => __( 'Products', 'my-plugin' ),
				'fields' => [
					'sku'   => [ 'label' => __( 'Code', 'my-plugin' ), 'required' => true ],
					'name'  => __( 'Name', 'my-plugin' ),
					'price' => [ 'label' => __( 'Price', 'my-plugin' ), 'type' => 'number', 'minimum' => 0 ],
				],
				'process_callback' => function ( array $row ) {
					// One row, already mapped and converted.
				},
			],
		],
	] );
} );
```

`process_callback` gets a row that has been mapped, validated and converted.
Everything before that point is not yours to write.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
