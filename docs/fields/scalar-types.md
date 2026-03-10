# Scalar Types

Scalar types validate format and cast values to the appropriate PHP type.

## Reference

| Type | Casts to | Validates |
|---|---|---|
| `string` | string | No additional validation |
| `number` | float | Checks `is_numeric`, strips currency symbols and commas |
| `integer` | int | Checks `is_numeric`, strips commas |
| `boolean` | bool | Accepts: `true`, `false`, `yes`, `no`, `1`, `0`, `on`, `off`, `y` |
| `email` | string | Validates with `is_email()` |
| `url` | string | Validates with `filter_var(FILTER_VALIDATE_URL)` |
| `currency` | string (uppercase) | Validates against all Stripe-supported ISO 4217 codes via `arraypress/wp-currencies` |
| `country` | string (uppercase) | Validates against ISO 3166-1 alpha-2 codes via `arraypress/wp-countries` |
| `date` | string (Y-m-d) | Validates parseable date, normalizes flexible formats |
| `datetime` | string (Y-m-d H:i:s) | Validates parseable datetime, normalizes flexible formats |

## string

The default type. No format validation beyond optional rules like `min_length`, `max_length`, `pattern`, and `options`.

```php
'name' => [
    'label'    => 'Product Name',
    'required' => true,
],
```

## number

Casts to float. Automatically strips `$`, `€`, `£`, commas, and spaces before parsing.

```php
'price' => [
    'label'   => 'Price',
    'type'    => 'number',
    'minimum' => 0.01,
    'maximum' => 99999.99,
],
```

## integer

Casts to int. Strips commas and spaces before parsing.

```php
'quantity' => [
    'label'   => 'Quantity',
    'type'    => 'integer',
    'minimum' => 1,
    'default' => 1,
],
```

## boolean

Casts to bool. Accepts common truthy/falsy strings from CSV data.

```php
'active' => [
    'label'   => 'Active',
    'type'    => 'boolean',
    'default' => true,
],
```

Truthy values: `1`, `true`, `yes`, `on`, `y`. Everything else is `false`.

## email

Validates with WordPress `is_email()`.

```php
'email' => [
    'label'    => 'Email Address',
    'type'     => 'email',
    'required' => true,
    'unique'   => true,
],
```

## url

Validates with `filter_var(FILTER_VALIDATE_URL)`.

```php
'website' => [
    'label' => 'Website URL',
    'type'  => 'url',
],
```

## currency

Validates against all Stripe-supported ISO 4217 currency codes using the `arraypress/wp-currencies` library. Automatically uppercased.

```php
'currency' => [
    'label'   => 'Currency',
    'type'    => 'currency',
    'default' => 'USD',
],
```

Accepts: `USD`, `EUR`, `GBP`, `JPY`, `CAD`, and 130+ other Stripe-supported codes.

## country

Validates against ISO 3166-1 alpha-2 country codes using the `arraypress/wp-countries` library. Automatically uppercased.

```php
'country_code' => [
    'label'   => 'Country',
    'type'    => 'country',
    'uppercase' => true,
],
```

Accepts: `US`, `GB`, `DE`, `FR`, `JP`, and 240+ other codes.

## date

Accepts flexible input formats and normalizes to `Y-m-d`. Uses `strtotime()` for parsing, so formats like `2025-01-15`, `01/15/2025`, `Jan 15, 2025`, and `15 January 2025` all work.

```php
'publish_date' => [
    'label'    => 'Publish Date',
    'type'     => 'date',
    'required' => true,
],
```

To enforce a specific input format:

```php
'expiry_date' => [
    'label'       => 'Expiry Date',
    'type'        => 'date',
    'date_format' => 'Y-m-d',    // Rejects anything not in this exact format
],
```

No timezone conversion is applied. The value is parsed and normalized as-is. Handle UTC conversion in your `process_callback` if needed.

## datetime

Same as `date` but normalizes to `Y-m-d H:i:s` and preserves the time component.

```php
'event_start' => [
    'label' => 'Event Start',
    'type'  => 'datetime',
],
```

Input like `01/15/2025 2:30 PM` becomes `2025-01-15 14:30:00`.
