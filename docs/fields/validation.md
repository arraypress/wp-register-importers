# Validation Rules

Built-in validation rules that can be applied to any field.

## Reference

| Key | Type | Description |
|---|---|---|
| `required` | bool | Value must not be empty |
| `minimum` | float/int | Minimum numeric value (for `number`/`integer` types) |
| `maximum` | float/int | Maximum numeric value |
| `min_length` | int | Minimum string length |
| `max_length` | int | Maximum string length |
| `pattern` | string | Regex pattern the value must match |
| `options` | array | Allowed values whitelist |
| `unique` | bool | Value must be unique within the CSV |

## Required

Fails the row if the value is empty, null, or (for arrays) has no items.

```php
'email' => [
    'label'    => 'Email',
    'type'     => 'email',
    'required' => true,
],
```

## Numeric Range

Applied to `number` and `integer` types.

```php
'price' => [
    'label'   => 'Price',
    'type'    => 'number',
    'minimum' => 0.01,
    'maximum' => 99999.99,
],
```

## String Length

Applied to string values.

```php
'sku' => [
    'label'      => 'SKU',
    'min_length' => 3,
    'max_length' => 20,
],
```

## Pattern

Regex pattern the value must match.

```php
'country_code' => [
    'label'   => 'Country Code',
    'pattern' => '/^[A-Z]{2}$/',
],
```

## Options

Restricts values to a whitelist. When the field has a separator (array values), each item is checked.

```php
'status' => [
    'label'   => 'Status',
    'options' => [ 'draft', 'publish', 'pending' ],
],
```

## Unique

Checks for duplicate values across the entire CSV before any rows are processed. Useful for fields like email addresses, SKUs, or codes that must be unique.

```php
'email' => [
    'label'  => 'Email',
    'type'   => 'email',
    'unique' => true,
],
```

Duplicate checking reports the row number of the first occurrence.

## Custom Validation

For validation beyond built-in rules, use a per-field `validate_callback`:

```php
'discount_code' => [
    'label'             => 'Discount Code',
    'validate_callback' => function( $value, $row ) {
        if ( ! str_starts_with( $value, 'DC-' ) ) {
            return new WP_Error( 'invalid', 'Must start with "DC-".' );
        }
        return true;
    },
],
```

See [Callbacks](callbacks.md) for more on custom validation.
