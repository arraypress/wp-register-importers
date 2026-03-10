# Separator & Arrays

The `separator` option splits a single CSV cell into an array of values. This works with any field type.

## Basic Usage

```php
'features' => [
    'label'     => 'Features',
    'separator' => '|',     // "fast|reliable|secure" → ['fast', 'reliable', 'secure']
],
```

## With Entity Types

When combined with WordPress entity types, each value in the array is resolved individually:

```php
// Terms: each tag name resolved to a term ID
'tags' => [
    'label'     => 'Tags',
    'type'      => 'term',
    'taxonomy'  => 'post_tag',
    'match_by'  => 'name',
    'create'    => true,
    'separator' => '|',         // "red|blue|green" → [12, 15, 18]
],

// Attachments: each filename resolved to an attachment ID
'downloads' => [
    'label'     => 'Download Files',
    'type'      => 'attachment',
    'match_by'  => 'filename',
    'separator' => ',',         // "file1.zip, file2.zip" → [45, 67]
],

// Posts: each title resolved to a post ID
'related' => [
    'label'     => 'Related Products',
    'type'      => 'post',
    'post_type' => 'product',
    'match_by'  => 'title',
    'separator' => '|',         // "Widget|Gadget" → [101, 205]
],
```

## How Splitting Works

Values are trimmed and empty items are removed:

```
"red | blue | | green " → ['red', 'blue', 'green']
```

If the separator string is multiple characters, the library tries each character and uses the first one found in the
value:

```php
'separator' => '|,',    // Tries '|' first, then ','
```

## Result in process_callback

Your `process_callback` receives an array instead of a single value:

```php
'process_callback' => function( array $row ) {
    // $row['tags'] is [12, 15, 18] (array of term IDs)
    // $row['features'] is ['fast', 'reliable', 'secure'] (array of strings)

    $post_id = wp_insert_post( [ ... ] );
    wp_set_post_terms( $post_id, $row['tags'], 'post_tag' );

    return 'created';
},
```
