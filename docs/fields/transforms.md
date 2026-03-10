# Transforms

Transforms modify field values before validation and type casting.

## Reference

| Key | Type | Description |
|---|---|---|
| `uppercase` | bool | Convert value to uppercase |
| `lowercase` | bool | Convert value to lowercase |
| `separator` | string | Split value into array by this delimiter |
| `default` | mixed | Default value when the CSV cell is empty |

## Uppercase / Lowercase

Applied before type casting, so a `currency` field with `uppercase` is redundant (currency auto-uppercases), but useful for plain string fields.

```php
'sku' => [
    'label'     => 'SKU',
    'uppercase' => true,     // "abc-123" → "ABC-123"
],

'status' => [
    'label'     => 'Status',
    'lowercase' => true,     // "ACTIVE" → "active"
    'options'   => [ 'active', 'inactive' ],
],
```

## Default

Applied when the CSV cell is empty. The default value then passes through the rest of the pipeline (casting, validation, etc.).

```php
'currency' => [
    'label'   => 'Currency',
    'type'    => 'currency',
    'default' => 'USD',
],

'quantity' => [
    'label'   => 'Quantity',
    'type'    => 'integer',
    'default' => 1,
],
```

## Separator

Splits a cell value into an array. See [Separator & Arrays](processing/separators.md) for details.

```php
'tags' => [
    'label'     => 'Tags',
    'separator' => '|',      // "red|blue|green" → ['red', 'blue', 'green']
],
```
