# Housekeeper

Local household-accounting site built with PHP 8.3, Python, and SQLite.

## Run

1. Install PHP 8.3 with the SQLite/PDO extension enabled.
2. Ensure `python` points to Python 3.11+.
3. Start the PHP dev server:

```powershell
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

The first-run admin password is `admin123`. Change it from Settings after logging in.

## CSV Import

The Upload page imports:

- PayPay credit-card CSV files encoded as UTF-8 BOM.
- Mitsubishi UFJ bank CSV files encoded as CP932/Shift_JIS.

Imported data, config, sessions, and uploads live under `data/`, which is intentionally ignored by Git.

## Tests

```powershell
python -m unittest discover -s tests
```
