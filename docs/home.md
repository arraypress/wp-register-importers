# WordPress Register Importers

A WordPress library for creating CSV import interfaces with a declarative, WordPress-style API. Define your fields, validation rules, and a single process callback — the library handles the UI, file upload, field mapping, batch processing, progress tracking, and error reporting.

## Features

- **Declarative API** — define fields and callbacks, get a full import UI
- **3-step wizard** — Upload → Map Fields → Import
- **10 scalar types** — string, number, integer, boolean, email, url, currency, country, date, datetime
- **4 WordPress entity types** — post, term, user, attachment with automatic ID resolution
- **Built-in validation** — required, min/max, pattern, options, unique, and custom callbacks
- **Batch processing** — configurable batch size with real-time progress
- **Dry run** — validate all rows without importing
- **Auto-mapping** — matches CSV headers to field labels automatically
- **Sample CSV** — auto-generated from field definitions
- **Tabbed interface** — organize operations into groups
- **EDD-style header** — full-bleed header with logo and tab navigation

## Installation

```bash
composer require arraypress/wp-register-importers
```

## Requirements

- PHP 8.1+
- WordPress 6.0+

## License

GPL-2.0-or-later
