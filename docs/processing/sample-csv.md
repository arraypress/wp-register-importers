# Sample CSV

The library auto-generates a downloadable sample CSV for each operation based on field definitions. Users can download
it from the "Sample CSV" link in each operation card header.

## How It Works

The sample CSV contains one header row and one example row. Example values are generated based on:

1. **Default value** — if the field has a `default`, that's used
2. **Options** — if the field has an `options` whitelist, the first option is used
3. **Type** — type-specific examples (see below)
4. **Key name** — falls back to guessing based on the field key

## Type-Based Examples

| Type         | Example Value                                               |
|--------------|-------------------------------------------------------------|
| `string`     | `Example` (or key-based guess)                              |
| `number`     | `minimum` value or `9.99`                                   |
| `integer`    | `minimum` value or `1`                                      |
| `boolean`    | `true`                                                      |
| `email`      | `user@example.com`                                          |
| `url`        | `https://example.com/image.jpg`                             |
| `currency`   | `USD`                                                       |
| `country`    | `US`                                                        |
| `date`       | `2025-01-15`                                                |
| `datetime`   | `2025-01-15 14:30:00`                                       |
| `post`       | `My Post Title` (if match_by is title) or `1`               |
| `term`       | `Category Name` (if match_by is name) or `1`                |
| `user`       | `user@example.com` (if match_by is email) or `1`            |
| `attachment` | `https://example.com/image.jpg` (if match_by is url) or `1` |

## Key-Based Guesses

For string fields without a default, the generator guesses based on the field key:

- Keys containing `name` → `Example Name`
- Keys containing `description` → `A brief description of the item.`
- Keys containing `email` → `user@example.com`
- Keys containing `url` or `image` → `https://example.com/image.jpg`
- Keys containing `price` or `amount` → `9.99`
- Keys containing `country` → `US`
- Keys containing `date` → `2025-01-15`
