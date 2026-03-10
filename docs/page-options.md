# Page Options

The first argument to `register_importers()` is a unique page ID. The second argument is the configuration array.

```php
register_importers( 'my-plugin', [
    'page_title'   => 'Import Data',
    'menu_title'   => 'Import',
    'parent_slug'  => 'my-plugin-menu',
    'capability'   => 'manage_options',
    'icon'         => 'dashicons-upload',
    'position'     => null,
    'header_title' => 'My Plugin Import',
    'logo'         => plugins_url( 'assets/logo.svg', __FILE__ ),
    'show_title'   => true,
    'show_tabs'    => true,
    'tabs'         => [],
    'operations'   => [],
    'help_tabs'    => [],
    'help_sidebar' => '',
] );
```

## Options Reference

| Key            | Type   | Default              | Description                                                                      |
|----------------|--------|----------------------|----------------------------------------------------------------------------------|
| `page_title`   | string | *required*           | The `<title>` and heading for the admin page                                     |
| `menu_title`   | string | `page_title`         | Text shown in the WordPress admin menu                                           |
| `parent_slug`  | string | `null`               | Parent menu slug for submenu placement. If `null`, creates a top-level menu item |
| `capability`   | string | `'manage_options'`   | WordPress capability required to access the page                                 |
| `icon`         | string | `'dashicons-upload'` | Dashicon class for top-level menus                                               |
| `position`     | int    | `null`               | Menu position                                                                    |
| `header_title` | string | `page_title`         | Title displayed in the page header                                               |
| `logo`         | string | `''`                 | URL to a logo image displayed in the header (max-height 36px)                    |
| `show_title`   | bool   | `true`               | Whether to display the header title                                              |
| `show_tabs`    | bool   | `true`               | Whether to display tabs when configured                                          |
| `tabs`         | array  | `[]`                 | Tab definitions (see [Tabs](tabs.md))                                            |
| `operations`   | array  | *required*           | Import operation definitions (see [Operations](operations.md))                   |
| `help_tabs`    | array  | `[]`                 | WordPress contextual help tabs                                                   |
| `help_sidebar` | string | `''`                 | WordPress help sidebar content                                                   |

## Parent Slug Examples

The `parent_slug` accepts any WordPress menu slug:

```php
'parent_slug' => 'my-plugin',              // Your plugin's menu
'parent_slug' => 'woocommerce',            // WooCommerce
'parent_slug' => 'tools.php',              // Tools
'parent_slug' => 'options-general.php',    // Settings
```
