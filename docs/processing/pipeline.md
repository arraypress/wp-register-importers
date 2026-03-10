# Field Processing Pipeline

Every CSV cell passes through this pipeline in order:

```
Raw CSV value
    → 1. Trim whitespace
    → 2. Apply default (if empty)
    → 3. Transform (uppercase/lowercase)
    → 4. Split by separator (if configured)
    → 5. Type cast (string/number/integer/boolean/email/url/currency/country/date/datetime)
    → 6. Built-in validation (required/min/max/length/pattern/options)
    → 7. Per-field validate_callback (return true or WP_Error)
    → 8. Per-field process_callback (return new value or WP_Error)
    → 9. WordPress entity resolution (post/term/user/attachment → ID)
    → Final value passed to operation process_callback
```

## Step Details

**1. Trim** — Whitespace removed from string values.

**2. Default** — If the value is empty/null and a `default` is defined, the default is substituted. The default then continues through the remaining steps.

**3. Transform** — `uppercase` and `lowercase` transforms applied to string values.

**4. Separator** — If a `separator` is defined, the string is split into an array. Each array item then passes through subsequent steps individually (for entity types).

**5. Type cast** — Value is cast to the declared type. Numbers strip currency symbols and commas. Dates are normalized to `Y-m-d` or `Y-m-d H:i:s`. Booleans accept common truthy/falsy strings.

**6. Built-in validation** — Required, minimum, maximum, min_length, max_length, pattern, options checks. Type-specific format validation (email, URL, currency code, country code, date validity).

**7. validate_callback** — Custom per-field validation. Receives the value and full row. Return `true` or `WP_Error`. Does not modify the value.

**8. process_callback** — Custom per-field transformation. Return value replaces the field value. Return `WP_Error` to reject the row.

**9. Entity resolution** — For `post`, `term`, `user`, and `attachment` types, the value is resolved to a WordPress object ID. Arrays (from separator split) are resolved individually.

## Unique Field Checking

For fields with `unique: true`, duplicate checking happens across the entire CSV **before** any rows are processed. This is a separate pre-pass, not part of the per-row pipeline.

## Operation-Level Validation

After all individual fields pass, the operation-level `validate_callback` runs with access to the full validated row for cross-field checks.
