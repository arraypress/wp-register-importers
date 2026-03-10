# REST API

The library registers 7 REST API endpoints under the `importers/v1` namespace. All require the configured capability (
default: `manage_options`) and a valid WordPress REST nonce via the `X-WP-Nonce` header.

## Endpoints

| Method | Endpoint                           | Purpose                                        |
|--------|------------------------------------|------------------------------------------------|
| POST   | `/upload`                          | Upload a CSV file                              |
| GET    | `/preview/{uuid}`                  | Get first 5 rows for column preview            |
| GET    | `/sample/{page_id}/{operation_id}` | Download auto-generated sample CSV             |
| POST   | `/dry-run`                         | Validate all rows without importing            |
| POST   | `/import/start`                    | Initialize import, fire `before_import`        |
| POST   | `/import/batch`                    | Process a batch of rows                        |
| POST   | `/complete`                        | Finalize import, fire `after_import`, clean up |

## Upload

Uploads a CSV file and returns metadata including headers, row count, and a UUID for subsequent requests.

**Parameters:** `page_id`, `operation_id`, `import_file` (multipart)

**Response:**

```json
{
  "success": true,
  "file": {
    "uuid": "a1b2c3d4-...",
    "original_name": "products.csv",
    "size": 12345,
    "size_human": "12 KB",
    "rows": 150,
    "headers": [
      "Name",
      "Price",
      "SKU"
    ]
  }
}
```

## Preview

Returns the first N rows of an uploaded CSV for the mapping preview table.

**Parameters:** `uuid`, `max_rows` (default: 5)

## Sample

Returns auto-generated sample CSV content based on field definitions.

**Parameters:** `page_id`, `operation_id`

## Dry Run

Validates all rows through the field pipeline without calling `process_callback`. Reports valid count, error count, and
detailed error messages with row numbers.

**Parameters:** `page_id`, `operation_id`, `file_uuid`, `field_map`

**Response:**

```json
{
  "success": true,
  "total_rows": 150,
  "valid_rows": 148,
  "error_count": 2,
  "errors": [
    {
      "row": 15,
      "item": "bad-sku",
      "message": "Price is required."
    },
    {
      "row": 42,
      "item": "test@bad",
      "message": "Email must be a valid email address."
    }
  ]
}
```

## Import Start

Initializes an import run, fires the `before_import` callback, and returns total items and batch size.

**Parameters:** `page_id`, `operation_id`, `file_uuid`, `field_map`

## Import Batch

Processes a batch of rows. Called repeatedly by the JavaScript client until all rows are processed.

**Parameters:** `page_id`, `operation_id`, `file_uuid`, `offset`, `field_map`

**Response includes:** processed count, created/updated/skipped/failed counts, errors, `has_more` flag, next offset,
percentage, cumulative stats.

## Complete

Finalizes the import, fires `after_import`, cleans up the uploaded file, and returns final stats.

**Parameters:** `page_id`, `operation_id`, `status` (complete/cancelled/error), `file_uuid`
