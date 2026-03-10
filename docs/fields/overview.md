# Field Types Overview

Fields define the expected columns in the CSV, their types, validation rules, and transformations. Each field value
passes through the [processing pipeline](processing/pipeline.md) before reaching your `process_callback`.

## Field Definition

```php
'fields' => [
    'field_key' => [
        'label'             => 'Display Label',
        'type'              => 'string',
        'required'          => false,
        'default'           => null,
        'group'             => null,
        // Validation
        'minimum'           => null,
        'maximum'           => null,
        'min_length'        => null,
        'max_length'        => null,
        'pattern'           => null,
        'options'           => null,
        'unique'            => false,
        // Transforms
        'uppercase'         => false,
        'lowercase'         => false,
        'separator'         => null,
        // Callbacks
        'validate_callback' => null,
        'process_callback'  => null,
        // WordPress entity options
        'post_type'         => 'post',
        'post_status'       => 'any',
        'taxonomy'          => 'category',
        'match_by'          => 'identifier',
        'create'            => false,
        'meta_key'          => null,
        'sideload'          => false,
        // Date options
        'date_format'       => null,
    ],
],
```

## Type Categories

Fields fall into two categories:

**Scalar types** — validate and cast to a PHP type:

`string`, `number`, `integer`, `boolean`, `email`, `url`, `currency`, `country`, `date`, `datetime`

**WordPress entity types** — resolve CSV values to WordPress object IDs:

`post`, `term`, `user`, `attachment`

See [Scalar Types](scalar-types.md) and [Entity Types](entity-types.md) for details.

## Simple Format

For basic string fields, you can use a shorthand:

```php
'fields' => [
    'sku'  => 'SKU',           // Equivalent to ['label' => 'SKU', 'type' => 'string']
    'name' => 'Product Name',
],
```
