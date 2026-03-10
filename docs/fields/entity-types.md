# WordPress Entity Types

Entity types resolve CSV values to WordPress object IDs. The resolved ID is what your `process_callback` receives.

| Type | Resolves to | `match_by` options |
|---|---|---|
| `post` | Post ID (int) | `identifier`, `title`, `slug`, `id`, `meta` |
| `term` | Term ID (int) | `identifier`, `name`, `slug`, `id` |
| `user` | User ID (int) | `identifier`, `email`, `login`, `id`, `slug` |
| `attachment` | Attachment ID (int) | `identifier`, `url`, `id`, `filename` |

## The `identifier` Mode (Default)

When `match_by` is `'identifier'` (or omitted), the resolver cascades through multiple strategies based on the value:

- **Post**: numeric → slug → title
- **Term**: numeric → slug → name
- **User**: numeric → email → login → slug
- **Attachment**: numeric → URL → filename

This is the most flexible option. Use a specific `match_by` when you know exactly what format the CSV column contains.

## Post

```php
'related_post' => [
    'label'       => 'Related Post',
    'type'        => 'post',
    'post_type'   => 'product',      // Default: 'post'
    'post_status' => 'publish',      // Default: 'any'
    'match_by'    => 'title',        // Or: identifier, slug, id, meta
],
```

Match by post meta:

```php
'product_by_sku' => [
    'label'    => 'Product',
    'type'     => 'post',
    'post_type' => 'product',
    'match_by' => 'meta',
    'meta_key' => 'sku',             // Required when match_by is 'meta'
],
```

## Term

```php
'category' => [
    'label'    => 'Category',
    'type'     => 'term',
    'taxonomy' => 'product_cat',     // Default: 'category'
    'match_by' => 'name',            // Or: identifier, slug, id
    'create'   => true,              // Auto-create term if not found
],
```

With separator for multiple terms per cell:

```php
'tags' => [
    'label'     => 'Tags',
    'type'      => 'term',
    'taxonomy'  => 'post_tag',
    'match_by'  => 'name',
    'create'    => true,
    'separator' => '|',              // "tag1|tag2|tag3" → [12, 15, 18]
],
```

When a term field has a `separator`, each value is resolved individually and the result is an array of term IDs.

## User

```php
'author' => [
    'label'    => 'Author',
    'type'     => 'user',
    'match_by' => 'email',           // Or: identifier, login, id, slug
],
```

## Attachment

```php
'thumbnail' => [
    'label'    => 'Featured Image',
    'type'     => 'attachment',
    'match_by' => 'url',             // Or: identifier, id, filename
    'sideload' => true,              // Download remote URL into media library
],
```

With separator for multiple files:

```php
'downloads' => [
    'label'     => 'Download Files',
    'type'      => 'attachment',
    'match_by'  => 'filename',
    'separator' => ',',              // "file1.zip, file2.zip" → [45, 67]
],
```

## Non-Required Entity Fields

When a WordPress entity type field is **not required** and the entity is not found, the field returns `null` instead of failing the row. This allows optional lookups.

When the field **is required** and the entity is not found, the row fails with a descriptive error.
