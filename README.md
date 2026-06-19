# Housekeeper

Housekeeper is a local personal finance web app built with PHP 8.3, Python, and SQLite.

PHP renders the pages, handles login, settings, CSV upload, and calls the importer. Python parses bank and credit-card CSV files, normalizes transactions, writes SQLite records, and reconciles card statements.

## First Login

The first-run admin password is `admin123`.

After logging in, open `Settings` and change the admin password immediately. The app has no user accounts or roles; it uses one administrator password only. The default login token lifetime is 2 hours.

## Run

Requirements:

- PHP 8.3 with PDO SQLite enabled
- Python 3.11 or newer
- SQLite support bundled with PHP/Python

Start a local PHP server:

```powershell
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080` in your browser.

If the project is served by WAMP, open the `public/` entry point, for example `http://localhost/housekeeper/public/`.

## Data Files

Personal data is stored under `data/`, which is intentionally ignored by Git.

- `data/housekeeper.sqlite`: SQLite database
- `data/config.json`: password hash, session lifetime, Python command, source switches, paths
- `data/uploads/`: uploaded CSV files

## Supported CSV Imports

Use the `Upload` page to import CSV files.

Supported sources:

- MUFG bank card CSV: CP932 / Shift_JIS
- PayPay credit card CSV: UTF-8 BOM
- EPOS credit card CSV: CP932 / Shift_JIS

Duplicate imports are skipped. PayPay and EPOS transactions are kept as card details and linked under matching MUFG withdrawal transactions when the statement period and amount match.

EPOS cancellation rows are detected when an expense and refund match exactly. Cancelled pairs are hidden from normal views and excluded from charts.

## Main Pages

### Charts

The home page shows monthly spending charts. It compares the selected month with the previous month.

- Bars are grouped by major category.
- Each bar is stacked by subcategory.
- You can hide/show categories with checkboxes.
- The `cash withdrawal` category is unchecked by default.
- Credit-card details are counted by payment month.

### Transactions

The transaction page focuses on top-level MUFG transactions. PayPay and EPOS details are usually displayed as child rows under the corresponding MUFG card payment.

Filters include:

- date range
- income / expense
- tag
- untagged only

When no tag is selected, the list shows MUFG top-level rows. When a tag is selected, matching card child rows can also appear.

### Untagged

The untagged page lists expense transactions without tags.

Filters include:

- month
- source
- keyword in description or counterparty key

You can select multiple rows and add one tag to all selected transactions at once.

### Tag Management

Tags have two levels:

- parent category, used for chart grouping
- subcategory, assigned to transactions

The page supports:

- create tag
- edit parent category
- edit subcategory name
- choose from a fixed 32-color palette
- save one row
- bulk save all rows
- delete tag

The tag list is height-limited on desktop so the table scrolls inside the card instead of creating duplicate page scrollbars.

### Upload

The upload page imports CSV files and shows recent import batches.

For each batch it displays:

- created time
- file name
- detected source
- status
- inserted count
- duplicate count
- error message

Failed import records can be cleared.

### Settings

The settings page allows changing:

- admin password
- session lifetime in seconds
- SQLite database path
- upload directory
- Python command
- enabled import sources

## Automatic Tag Inheritance

The first occurrence of a transaction is not tagged automatically. After you manually tag a transaction, future imports with the same match key can inherit the latest manually confirmed tag set.

The match key is:

- source type
- normalized counterparty key

The app does exact matching only. It does not use fuzzy matching or machine-learning classification.

## Tests

Run the test suite:

```powershell
python -m unittest discover -s tests
```
