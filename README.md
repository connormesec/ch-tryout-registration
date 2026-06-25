# CH Tryout Registration

A WordPress plugin that provides a tryout registration form. Submissions are stored
in the database, can be exported to CSV, and are synced to a Google Sheet via a Google
Apps Script Web App. Companion to the Club Hockey Divi child theme (uses the same `ch_`
conventions, namespaced `ch_tryout_*`).

## Usage

Drop the shortcode on any page:

```
[ch_tryout_form]
```

## Admin

- **Tryout Registrants** (top-level menu) — view submissions and export to CSV.
- **Form Fields** (under Tryout Registrants) — add, edit, reorder, or remove the
  registration fields with no code. Set each field's type (Text, Email, Phone,
  Number, Date, Dropdown), edit dropdown options, and toggle Required. Saving a
  new field automatically adds its database column. A field's type is locked
  after creation (delete & re-add to change it), and removing a field keeps its
  data column. Reset to defaults at any time.
- **Settings** (`Settings → Tryout Registration`) — configure:
  - **Web App URL** — the deployed Google Apps Script Web App endpoint.
  - **Notification email(s)** — who gets emailed on a new registration (falls back to the site admin email).

> The built-in default fields are defined in `ch_tryout_default_fields()`; once
> you save changes on the Form Fields screen they're stored in the
> `ch_tryout_fields_config` option. If you change fields after registrations
> start, update your Google Sheet's header row to match.

## Google Sheets sync

The plugin generates a shared secret on first use (stored in the `ch_tryout_secret`
option — never hardcoded) and shows a ready-to-paste Apps Script in the settings screen.
Create a Google Apps Script Web App with that script, deploy it, and paste the Web App
URL into the settings. New registrations are then POSTed to the sheet, authenticated with
the shared secret.

## Notes

- No credentials are stored in source — the shared secret and Web App URL live in the
  site's options table.
- `inc/google-auth.php` and `inc/sheets-api.php` are deprecated stubs from an earlier
  OAuth-based approach and are no longer loaded.

## License

GPL-2.0-or-later · Author: Connor Mesec
