# Tabs

Tabs organize operations into groups. Define tabs and assign operations to them via the `tab` key.

```php
register_importers( 'my-plugin', [
    'page_title' => 'Import',

    'tabs' => [
        'products'  => [ 'label' => 'Products',  'icon' => 'dashicons-cart' ],
        'customers' => [ 'label' => 'Customers', 'icon' => 'dashicons-groups' ],
    ],

    'operations' => [
        'import_products' => [
            'title' => 'Import Products',
            'tab'   => 'products',
            // ...
        ],
        'import_customers' => [
            'title' => 'Import Customers',
            'tab'   => 'customers',
            // ...
        ],
    ],
] );
```

## Tab Options

| Key | Type | Description |
|---|---|---|
| `label` | string | Tab display text |
| `icon` | string | Dashicon class (e.g. `'dashicons-cart'`) |
| `render_callback` | callable | Optional custom render function (replaces default operation cards) |

## Behavior

- If no tabs are defined, all operations display on a single page
- Tabs render in the EDD-style header as a navigation bar
- If an operation's `tab` doesn't match any defined tab, it falls back to the first tab
- When only one tab exists, the tab navigation is hidden
