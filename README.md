# Amazon Photos CLI

A Laravel-based CLI tool to find photos in your **Amazon Photos** library that are not classified in any album.

> **Note:** This project uses Amazon Photos' unofficial API (reverse-engineered). It requires manual cookie extraction from your browser session — there is no OAuth flow.

---

## Features

- Detect all photos that do not belong to any album
- Four combinable filters:
  - Skip photos already analyzed recently (`--skip-analyzed-days`)
  - Only analyze photos uploaded in the last N days (`--uploaded-last-days`)
  - Filter by upload date range (`--uploaded-between`)
  - Filter by capture/EXIF date range (`--taken-between`)
- Output to console (table) or export to CSV
- File-based analysis history — no database required
- Progress indicators and detailed logging

---

## Requirements

- PHP 8.4+
- Composer
- An active Amazon Photos account

---

## Installation

```bash
git clone https://github.com/your-username/amazon-photos-cli.git
cd amazon-photos-cli

composer install
cp .env.example .env
php artisan key:generate
```

---

## Configuration

### 1. Extract cookies from your browser

You need three cookies from an authenticated Amazon Photos browser session:

1. Open [https://www.amazon.com/photos](https://www.amazon.com/photos) and log in
2. Open **DevTools** → **Application** → **Cookies**
3. Copy the values for:
   - `session-id`
   - `ubid-main` (or `ubid-acbca` for Canada, `ubid-acuk` for UK, etc.)
   - `at-main` (or `at-acbca`, `at-acuk`, etc.)

### 2. Add to `.env`

```dotenv
AMAZON_PHOTOS_SESSION_ID=your-session-id-here
AMAZON_PHOTOS_UBID=your-ubid-here
AMAZON_PHOTOS_AT=your-at-token-here

# Region TLD: com (US), co.uk (UK), ca (Canada), de, fr, it, es, co.jp, com.au
AMAZON_PHOTOS_TLD=com
```

### Cookie suffix by region

| Region       | TLD      | Cookie suffix |
|--------------|----------|---------------|
| United States | `com`   | `main`        |
| Canada        | `ca`    | `acbca`       |
| United Kingdom| `co.uk` | `acuk`        |
| Germany       | `de`    | `acde`        |
| France        | `fr`    | `acfr`        |
| Italy         | `it`    | `acit`        |
| Spain         | `es`    | `aces`        |
| Japan         | `co.jp` | `acjp`        |
| Australia     | `com.au`| `acau`        |

---

## Usage

### Basic — list all unclassified photos

```bash
php artisan photos:find-unclassified
```

### Export to CSV

```bash
php artisan photos:find-unclassified --output=csv
# CSV saved to: storage/app/amazon-photos/unclassified.csv

# Custom path
php artisan photos:find-unclassified --output=csv --csv-path=exports/my-report.csv
```

### Available filters

| Option | Description | Example |
|--------|-------------|---------|
| `--skip-analyzed-days=N` | Skip photos analyzed within the last N days | `--skip-analyzed-days=7` |
| `--uploaded-last-days=N` | Only photos uploaded in the last N days | `--uploaded-last-days=30` |
| `--uploaded-between=FROM,TO` | Only photos uploaded between two dates (`dd/mm/yyyy`) | `--uploaded-between=01/01/2024,31/01/2024` |
| `--taken-between=FROM,TO` | Only photos taken between two dates (`dd/mm/yyyy`, uses EXIF) | `--taken-between=01/06/2023,31/08/2023` |
| `--output=csv` | Export results to a CSV file instead of the console | `--output=csv` |
| `--csv-path=PATH` | Path for the CSV file (relative to `storage/app`) | `--csv-path=exports/out.csv` |

Filters can be combined freely:

```bash
php artisan photos:find-unclassified \
  --skip-analyzed-days=7 \
  --uploaded-last-days=30 \
  --taken-between=01/01/2023,31/12/2023 \
  --output=csv
```

---

## CSV output format

| Column | Description |
|--------|-------------|
| `id` | Amazon Photos node ID |
| `name` | File name |
| `uploaded_at` | Upload timestamp (ISO 8601) |
| `taken_at` | Capture timestamp from EXIF (ISO 8601, empty if unavailable) |
| `url` | Temporary download URL (if returned by the API) |

---

## Analysis history

Each run records which photos were analyzed and when, in a local JSON file:

```
storage/app/amazon-photos/analysis-history.json
```

Use `--skip-analyzed-days=N` to avoid re-processing photos analyzed within the last N days. This speeds up incremental runs significantly on large libraries.

You can change the storage path via `.env`:

```dotenv
AMAZON_PHOTOS_HISTORY_FILE=amazon-photos/analysis-history.json
```

---

## Logging

Logs are written to `storage/logs/laravel.log`. Set `LOG_LEVEL=debug` in `.env` for verbose API request logging.

---

## Project structure

```
app/
├── Console/Commands/
│   └── FindUnclassifiedPhotosCommand.php  # Main Artisan command
├── DTOs/
│   ├── Photo.php                          # Immutable photo value object
│   └── Album.php                          # Immutable album value object
├── Services/
│   ├── AmazonPhotos/
│   │   ├── AmazonPhotosClient.php         # HTTP client (auth, retries)
│   │   ├── PhotoService.php               # Fetch all photos (paginated)
│   │   └── AlbumService.php               # Fetch albums + their children
│   └── Cache/
│       └── AnalysisHistoryCache.php       # File-based analysis history
└── Support/
    ├── DateParser.php                     # European date format (dd/mm/yyyy)
    └── PhotoFilter.php                    # Apply all CLI filters
config/
└── amazon-photos.php                      # Package configuration
```

---

## Running tests

```bash
php artisan test --compact
```

Tests mock the Amazon Photos API — no real credentials are required to run them.

---

## Disclaimer

This project uses Amazon Photos' **unofficial, reverse-engineered API**. It is not affiliated with or endorsed by Amazon. Use it responsibly and at your own risk. Amazon may change their API at any time, which could break functionality.

Cookie-based authentication means your credentials are sensitive — never commit your `.env` file to version control.

---

## Inspiration

API research based on the Python library [amazon_photos](https://github.com/trevorhobenshield/amazon_photos) by [@trevorhobenshield](https://github.com/trevorhobenshield).

---

## License

MIT

---

## Repository

This project is hosted at [https://github.com/icsbcn/awsphotosapi](https://github.com/icsbcn/awsphotosapi).

Suggestions, issues, and pull requests are welcome!

## Built with AI

This project was created with [Claude](https://claude.ai) by Anthropic.
