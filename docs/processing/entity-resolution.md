# Entity Resolution

WordPress entity types resolve CSV values to object IDs. This page covers the resolution strategies in detail.

## Resolution Cascade (identifier mode)

When `match_by` is `'identifier'` (the default), the resolver tries multiple strategies in order:

### Post

1. **Numeric** → `get_post( $value )` — match by ID
2. **Slug** → `get_posts( ['name' => $value] )` — match by post slug
3. **Title** → `get_posts( ['title' => $value] )` — match by exact title

### Term

1. **Numeric** → `get_term( $value )` — match by ID
2. **Slug** → `get_term_by( 'slug', $value )` — match by term slug
3. **Name** → `get_term_by( 'name', $value )` — match by exact name

### User

1. **Numeric** → `get_user_by( 'id', $value )` — match by ID
2. **Email** → `get_user_by( 'email', $value )` — match by email (if value is email format)
3. **Login** → `get_user_by( 'login', $value )` — match by username
4. **Slug** → `get_user_by( 'slug', $value )` — match by user slug

### Attachment

1. **Numeric** → `get_post( $value )` — match by ID
2. **URL** → `attachment_url_to_postid( $value )` — match by URL (with optional sideload)
3. **Filename** → Database LIKE query on guid — match by filename

## Specific match_by

When a specific `match_by` is set, only that strategy is tried. Use this when you know the exact format of your CSV
data:

```php
// Only match by email, don't try ID/login/slug
'author' => [
    'type'     => 'user',
    'match_by' => 'email',
],
```

## Post Meta Matching

Posts can be matched by custom field values:

```php
'product' => [
    'type'      => 'post',
    'post_type' => 'product',
    'match_by'  => 'meta',
    'meta_key'  => '_sku',       // CSV value is matched against this meta key
],
```

## Term Auto-Creation

Terms can be automatically created when not found:

```php
'category' => [
    'type'     => 'term',
    'taxonomy' => 'product_cat',
    'match_by' => 'name',
    'create'   => true,          // Creates "New Category" if it doesn't exist
],
```

If `wp_insert_term` returns a "term_exists" error (race condition or slug conflict), the existing term ID is returned.

## Attachment Sideloading

Remote URLs can be downloaded into the WordPress media library:

```php
'image' => [
    'type'     => 'attachment',
    'match_by' => 'url',
    'sideload' => true,          // Downloads https://example.com/photo.jpg into media library
],
```

Uses `media_sideload_image()` under the hood.

## Not Found Behavior

- **Required field + not found** → Row fails with error: `'{Label} "{value}" not found.'`
- **Optional field + not found** → Field returns `null`, row continues processing
