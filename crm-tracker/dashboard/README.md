# GHL Multi-Location Dashboard

PHP 8.x and vanilla JavaScript dashboard for loading GoHighLevel metrics on demand without a SQL database.

## Deploy

1. Upload the `dashboard` folder into the web root.
2. Copy `dashboard/config.example.json` to `config.json` one directory above `dashboard`.
3. Add each GHL location ID, display name, and access token to `config.json`.
4. Ensure PHP cURL is enabled.
5. Ensure the configured cache directory is writable by PHP.

By default, PHP reads `../config.json` relative to the `dashboard` directory. To use another path, set the `GHL_DASHBOARD_CONFIG` environment variable if your host supports it.

## Cache

The default cache directory is `dashboard/cache`, protected by `.htaccess`. Override it in `config.json`:

```json
{
  "cache": {
    "dir": "cache"
  }
}
```

Use the dashboard Refresh button to call the active endpoint with `?refresh=1` and invalidate that tab's cached data.

## Endpoints

- `api/locations.php` returns safe subaccount metadata for the dropdown.
- Tab endpoints require `locationId`, for example `api/appointments.php?locationId=LOCATION_ID`.
- `api/social.php` also accepts `startDate` and `endDate` as `YYYY-MM-DD`; the end date is inclusive.
