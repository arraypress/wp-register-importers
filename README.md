# Register Importers

A CSV import screen for a WordPress plugin. Declare the columns and one
callback; the library reads the file, matches the columns, coerces the
strings, checks the rules, resolves the entities, and walks it in batches.

## Install

```bash
composer require arraypress/wp-register-importers
```

Requires PHP 8.3.

## Use

```php
add_action( 'admin_menu', function () {
	register_importers( 'myplugin-import', [
		'parent_slug' => 'tools.php',
		'page_title'  => __( 'Import', 'my-plugin' ),
		'operations'  => [
			'products' => [
				'title'  => __( 'Products', 'my-plugin' ),
				'fields' => [
					'sku'      => [ 'label' => __( 'Code', 'my-plugin' ), 'required' => true ],
					'name'     => __( 'Name', 'my-plugin' ),
					'price'    => [ 'label' => __( 'Price', 'my-plugin' ), 'type' => 'number', 'minimum' => 0 ],
					'on_sale'  => [ 'label' => __( 'On sale', 'my-plugin' ), 'type' => 'boolean' ],
					'category' => [ 'label' => __( 'Category', 'my-plugin' ), 'type' => 'term', 'taxonomy' => 'product_cat', 'create' => true ],
				],
				'process_callback' => function ( array $row ) {
					$id = wp_insert_post( [
						'post_type'   => 'product',
						'post_title'  => $row['name'],
						'post_status' => 'publish',
					], true );

					if ( is_wp_error( $id ) ) {
						return $id;
					}

					update_post_meta( $id, '_sku', $row['sku'] );
					update_post_meta( $id, '_price', $row['price'] );
					wp_set_object_terms( $id, [ $row['category'] ], 'product_cat' );

					return 'created';
				},
			],
		],
	] );
} );
```

The callback is handed one row where every value is already what its column
said it would be: `price` is a float, `on_sale` is a bool, `category` is a
term id. It returns `created`, `updated` or `skipped`, or a `WP_Error` whose
message is shown against that row number.

## Column types

| Type         | What the cell may hold                                       |
| ------------ | ------------------------------------------------------------ |
| `string`     | Anything. The default.                                       |
| `number`     | `1299`, `1,299.00`, `£1,299.00`.                             |
| `integer`    | A whole number. `12.00` is twelve; `12.9` is refused.        |
| `boolean`    | yes/no, true/false, y/n, on/off, 1/0. Anything else is refused. |
| `email`      | An address.                                                  |
| `url`        | A URL.                                                       |
| `currency`   | An ISO 4217 code.                                            |
| `country`    | An ISO 3166-1 alpha-2 code.                                  |
| `date`       | Normalised to `Y-m-d`.                                       |
| `datetime`   | Normalised to `Y-m-d H:i:s`.                                 |
| `post`       | An id, slug or title. Becomes an id.                         |
| `term`       | An id, slug or name. Becomes an id.                          |
| `user`       | An id, email, login or slug. Becomes an id.                  |
| `attachment` | An id, URL or filename. Becomes an id.                       |

### Column options

| Option              | Applies to      | What it does                                          |
| ------------------- | --------------- | ----------------------------------------------------- |
| `label`             | all             | What the screen calls it.                              |
| `required`          | all             | The cell may not be empty. Nought is not empty.        |
| `default`           | all             | Used when the cell is empty.                           |
| `separator`         | all             | The cell holds a list. Every character separates.      |
| `uppercase`         | all             | Upper-case it first.                                   |
| `lowercase`         | all             | Lower-case it first.                                   |
| `options`           | all             | The values it accepts.                                 |
| `pattern`           | strings         | A regular expression it must match.                    |
| `min_length`        | strings         | Fewest characters.                                     |
| `max_length`        | strings         | Most characters.                                       |
| `minimum`           | numbers         | Smallest value.                                        |
| `maximum`           | numbers         | Largest value.                                         |
| `date_format`       | dates           | The format the *file* uses, enforced exactly.          |
| `match_by`          | entities        | `id`, `slug`, `title`, `name`, `email`, `login`, `url`, `filename`, `meta`. Guesses otherwise. |
| `post_type`         | `post`          | Which type to look in.                                 |
| `taxonomy`          | `term`          | Which taxonomy to look in.                             |
| `meta_key`          | `post`          | Which key, with `match_by => 'meta'`.                  |
| `create`            | `term`          | Make it when it is missing.                            |
| `example`           | all             | What the sample file shows.                            |
| `validate_callback` | all             | Your own check. `true`, `false` or `WP_Error`.         |
| `process_callback`  | all             | Your own transformation, on import only.               |

Give a column a `date_format` whenever you can. Without one, `03/04/2026` is
read the American way — the difference between importing March and importing
April, silently, on every date in the file.

### Operation options

| Option              | What it does                                             |
| ------------------- | -------------------------------------------------------- |
| `title`             | What the box is called.                                   |
| `description`       | A line under it.                                          |
| `icon`              | A dashicon, with or without its prefix.                   |
| `tab`               | Which tab it sits on.                                     |
| `batch_size`        | Rows per request. 100 by default, capped at 1000.         |
| `separator`         | What the file separates values with.                      |
| `fields`            | The columns.                                              |
| `process_callback`  | What to do with a row. Required for it to run.            |
| `validate_callback` | Check a whole row before it is processed.                 |
| `before_import`     | Runs once, before the first batch.                        |
| `after_import`      | Runs once, after the last.                                |

## Checking without importing

Every operation offers a dry run. It reads the whole file and reports what
would go wrong, and it is the same pass the import makes: the same coercion,
the same rules, the same entity lookups.

What it does not do is call your `process_callback` or create a missing term —
so a term the import would make is reported as one that will be made, rather
than as an error.

That equivalence is the point. The previous version ran a hand-copied subset
of the steps and had already drifted: it skipped entity resolution entirely,
so a file naming a category that did not exist was reported as *five thousand
rows, no problems*, and then the import silently stored null for all of them.
A dry run that disagrees with the import is worse than none, because it is the
thing people trust before pressing the button.

## What it gets right

**A typo in a Yes/No column is a mistake, not a No.** Anything unrecognised
used to become `false`, so one `Ys` in five thousand rows imported quietly as
No and the only symptom appeared weeks later.

**Reading a large file does not take quadratic time.** Each batch reached its
starting row by reading and discarding every row before it. A 64,000-row
import read 241 MB from a 1.5 MB file; it now reads 2.6 MB, and the gap widens
with the file.

**A file saved by Excel imports.** Its byte order mark is three invisible
bytes on the front of the first column name, so that column matched nothing
and imported empty on every row — with nothing wrong-looking about the file.

**A row with the wrong number of columns is reported.** It used to be handed
on as a numerically indexed array while every other row was keyed by header,
so the code reading `$row['sku']` got nothing, said nothing, and imported a
blank.

**Two plugins can both bundle this.** The REST namespace is derived from the
library's own PHP namespace, which Strauss rewrites. It was a literal, and
`WP_REST_Server::register_route()` merges same-path registrations by appending
handlers — so the plugin that registered first answered the other's import
requests, under its own capability and through its own callback.

**A request naming an unregistered screen is refused** rather than falling
back to `manage_options`, and a file uploaded for one screen cannot be
imported through another.

**The same value is looked up once.** A file of ten thousand products with six
categories between them asked the database ten thousand times.

## Upgrading from 2.x

**`FieldValidator` is gone.** Its 1,286 lines are `Row\Cast`, `Row\Check`,
`Row\Resolve` and `Row\Pipeline`, and the pipeline is the only path — the
separate dry-run copy is what this release exists to remove.

**An entity that does not resolve is an error**, whether or not the column is
required. `required` means the cell may not be empty; it never meant that a
value in it may be wrong. A missing term was silently stored as null.

**`register_importers()` returns an `Importer`** rather than nothing, and
`get_importer_page()` is `get_importers()`.

**The endpoints moved** to a namespace derived at runtime. Nothing should have
been calling them directly.

**`wp-countries`, `wp-currencies` and `wp-date-utils` are suggestions**, not
requirements. An importer of blog posts should not pull in a table of every
ISO currency; without them, a `currency` or `country` column is accepted as
written.

## Testing

```bash
composer test          # phpunit
composer lint          # phpcs, defect sniffs
composer format:check  # phpcs, formatting
```
