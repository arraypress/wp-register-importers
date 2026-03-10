# Architecture

## Directory Structure

```
src/
├── Importers.php              Main class — page registration, rendering
├── Registry.php               Singleton registry of all importer pages
├── RestApi.php                REST API endpoint handlers
├── FileManager.php            Secure file upload, storage, cleanup
├── StatsManager.php           Import statistics tracking
├── Traits/
│   ├── AssetManager.php       CSS/JS enqueuing and localization
│   ├── ConfigParser.php       Configuration normalization
│   ├── OperationRenderer.php  Card HTML rendering (wizard steps)
│   └── TabManager.php         Tab navigation management
├── Utilities/
│   └── Functions.php          Global helper functions
└── Validation/
    └── FieldValidator.php     Field processing pipeline, entity resolution

assets/
├── css/
│   └── importers.css          Admin styles (EDD-style header, cards, wizard)
└── js/
    └── importers.js           3-step wizard, file upload, batch processing
```

## Key Classes

### Importers

The main class. Instantiated by `register_importers()`. Handles WordPress admin menu registration, page rendering, asset
enqueuing, and coordinates the other classes via traits.

### Registry

Singleton that stores all registered `Importers` instances. Used by the REST API to look up configurations by page ID.

### RestApi

Static class that registers REST routes once (regardless of how many importer pages exist). Handles all 7 endpoints:
upload, preview, sample, dry-run, import start, batch, and complete.

### FileManager

Static class for secure file operations. Handles upload with UUID renaming, directory protection, file reading (batch
and full), preview generation, and cleanup.

### StatsManager

Tracks import run statistics using WordPress transients (7-day expiry). Stores last run timestamp, status, counts (
created/updated/skipped/failed), and the last 20 errors.

### FieldValidator

The core validation engine. Processes each field through the full pipeline: trim, default, transform, split, cast,
validate, custom callbacks, and WordPress entity resolution. Also generates sample CSV content.

## Dependencies

| Package                         | Purpose                                     |
|---------------------------------|---------------------------------------------|
| `arraypress/wp-composer-assets` | Asset path resolution for Composer packages |
| `arraypress/wp-currencies`      | ISO 4217 currency code validation           |
| `arraypress/wp-countries`       | ISO 3166-1 country code validation          |
| `arraypress/wp-date-utils`      | Date parsing and validation                 |
