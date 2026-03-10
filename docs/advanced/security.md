# Security

## Capability Checks

Every REST endpoint checks permissions via `check_permission()`. The capability defaults to `manage_options` but can be configured per page:

```php
register_importers( 'my-plugin', [
    'capability' => 'edit_posts',    // Only users with edit_posts can import
] );
```

## File Security

- **UUID-based filenames** — uploaded files are renamed to UUID.csv, preventing path traversal and filename guessing
- **File ownership** — uploaded files can only be accessed by the user who uploaded them (verified on every access)
- **MIME validation** — actual file content is checked with `finfo`, not just the extension
- **Directory protection** — upload directory has `.htaccess` deny-all and `index.php` guard
- **Auto-expiry** — file metadata stored in transients with 24-hour TTL; daily cron cleans up orphaned files

## Input Sanitization

All REST parameters are sanitized:

- `page_id`, `operation_id` → `sanitize_key()`
- `file_uuid` → custom sanitizer allowing only hex chars and dashes
- `field_map` → keys via `sanitize_key()`, values via `sanitize_text_field()`
- `offset` → `absint()`

## Nonce Verification

All REST requests require a valid `X-WP-Nonce` header, provided automatically by the JavaScript client via `wp_create_nonce( 'wp_rest' )`.

## Database Safety

Any direct database queries use `$wpdb->prepare()` with parameterized queries.
