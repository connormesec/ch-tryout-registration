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
  - **Email templates** — editable subject/body for both the registrant confirmation
    and the team notification, with insertable mail-tags, a live preview, and a
    "send test" button.
  - **Updates** — shows the installed version and a link to check for updates (see the
    self-update notes below and `RELEASE.md`).

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

---

# Maintenance & development

**Read this section before changing any code.** Future-you (or whoever picks this
up) can break the public form, lose registrant data, or push a bad update to every
site if a few invariants are violated. They're listed below. None of this is
complicated — it just isn't obvious from reading one file.

## How it's wired (file map + load order)

`ch-tryout-registration.php` is the entry point. It defines the field config and the
table name, then `require_once`s each module **in this order** (order matters —
`handler.php` calls functions from `emails.php`, etc.):

| File | Responsibility |
|------|----------------|
| `ch-tryout-registration.php` | Constants, `ch_tryout_default_fields()`, `ch_tryout_fields()`, `ch_tryout_table()`, the require list, activation/upgrade hooks. |
| `inc/schema.php` | Builds `CREATE TABLE` from the fields; `ch_tryout_install()` runs dbDelta. |
| `inc/sheets-sync.php` | POSTs each registrant to the Apps Script Web App. |
| `inc/settings.php` | `Settings → Tryout Registration` (Sheet URL, notification email, email templates, preview/test, updates info). |
| `inc/emails.php` | Template-driven confirmation + team emails (mail-tags, render, send). |
| `inc/form.php` | `[ch_tryout_form]` shortcode + front-end assets. |
| `inc/cache.php` | Marks form pages non-cacheable (keeps the nonce fresh). |
| `inc/handler.php` | `admin-post.php` submission handler (validate → save → email → sync). |
| `inc/admin.php` | "Tryout Registrants" list + CSV export. |
| `inc/fields-admin.php` | "Form Fields" editor (writes `ch_tryout_fields_config`, runs dbDelta). |
| `inc/update.php` | Self-update from GitHub releases (see `RELEASE.md`). |

Adding a new `inc/` file? It won't load until you add it to the require list. Adding
front-end CSS/JS? Register/enqueue it in `inc/form.php`.

## The invariants — don't break these

1. **`ch_tryout_fields()` is the single source of truth.** The form markup,
   validation/sanitization, DB columns, the registrants table + CSV, the email
   mail-tags, and the Google Sheet header row are **all** derived from it. Change the
   shape of a field here and it changes everywhere. Don't hardcode a field anywhere
   else.

2. **A field's `key` *is* its database column name.** Keys must match
   `[a-z][a-z0-9_]*` and must never collide with the structural columns
   (`id`, `created_at`, `sheets_status`, `sheets_error`, `ip`, `user_agent` — see
   `ch_tryout_reserved_keys()`). **Never rename an existing key** — it orphans the old
   column and silently drops that field's data mapping. To "rename," add a new field
   and migrate data deliberately.

3. **The DB table is code-built and lazily upgraded.** `ch_tryout_install()` (dbDelta)
   runs on activation and again on `init` whenever the stored `ch_tryout_db_version`
   is behind `CH_TRYOUT_DB_VERSION`. This lazy upgrade exists **because production
   deploys are file-only rsync with no DB sync** — the table has to be able to appear
   / gain columns on its own. If you change the *default* schema (structural columns,
   a default field's `col`), **bump `CH_TRYOUT_DB_VERSION`** so existing installs
   re-run dbDelta. dbDelta is whitespace-sensitive — don't reformat
   `ch_tryout_schema_sql()` (two spaces after `PRIMARY KEY`, etc.).

4. **Field type / column SQL is never user input.** `ch_tryout_col_for_type()` is a
   fixed map; the Form Fields UI locks a field's type after creation. This is what
   guarantees stored data is never silently re-typed or dropped. Keep it that way.

5. **The form page must stay uncached.** `inc/cache.php` sets `DONOTCACHEPAGE` on any
   page containing `[ch_tryout_form]` so the security nonce regenerates per request. A
   cached page serves a stale nonce → every submission fails verification. Don't
   remove this, and never add `et-cache`/page cache back for those pages.

6. **The submission order in `handler.php` is deliberate.** nonce → honeypot →
   time-trap → rate-limit → validate → **INSERT (status `pending`)** → send emails →
   sync to Sheet → update status. The DB write happens *before* any network call, so a
   registrant is never lost if Google or email is down. Emails and the Sheet sync are
   **best-effort**: they must never block the submission or change the success
   redirect. Keep new side-effects after the insert and non-fatal.

7. **The shared secret lives in its own option.** `ch_tryout_secret` is separate from
   `ch_tryout_settings` precisely so the settings sanitize callback can't wipe it.
   Don't fold it into the settings array.

8. **Two version fields must match.** The plugin header `Version:` is what WordPress
   scans and what the updater compares against; `CH_TRYOUT_VERSION` is used elsewhere
   (e.g. the update User-Agent). The release process bumps both — keep them identical.

## Making common changes safely

**Change the registration fields**
- For one site, no code: **Tryout Registrants → Form Fields**. Saving runs dbDelta to
  add any new column. Removing a field keeps its data column.
- To change what *new installs* ship with: edit `ch_tryout_default_fields()`. Note this
  only affects sites that haven't saved custom fields (those use
  `ch_tryout_fields_config`). If you changed structural columns or a default `col`,
  bump `CH_TRYOUT_DB_VERSION`.
- **Either way:** the Google Sheet header row is written only once. After launch, update
  the sheet's header manually so columns line up.

**Change an email**
- Per site: **Settings → Tryout Registration** → edit subject/body (mail-tags +
  preview + "send test"). Mail-tags come from the fields automatically.
- To change the shipped defaults: edit `ch_tryout_email_templates()` (affects sites
  still on defaults). Body rendering order is fixed — block tags (`[details_table]`,
  `[admin_button]`) injected → `wpautop()` → escaped scalar tags. Don't escape the
  template before substituting, and keep the branded wrapper.
- Deliverability: From stays on-domain (SPF); only the From *name* is set. Reliable
  inbox delivery on Hostinger needs an SMTP plugin.

**Change the database schema** → edit the default field `col` / structural columns in
`schema.php` or the defaults, **bump `CH_TRYOUT_DB_VERSION`**, then load any admin page
to trigger the lazy upgrade and confirm the column appears.

## Deploying changes — push vs. release

This plugin lives in its own repo (`connormesec/ch-tryout-registration`) and is
deployed two ways. Know which you need:

- **File push** (`site-manager push <site>`): rsyncs the files immediately. Use during
  development, and **required for any brand-new file** — including the first time
  `inc/update.php` lands on a site (a site can't self-update to a feature that adds the
  updater itself).
- **GitHub release** (the self-update path): bump the version, push, and publish a
  release marked **Latest**. Sites then show a normal "update available" prompt. Use
  this for routine updates once every site already has `inc/update.php`. **Full steps:
  see [`RELEASE.md`](RELEASE.md).**

**Rollback:** revert the bad release/tag and either publish a previous version as Latest
or `site-manager push` the previous files.

## Don't touch / gotchas

- The dbDelta whitespace in `schema.php` (invariant 3).
- The `BEGIN/END` markers logic is theme-side, not here — but the same "don't reformat
  generated SQL" caution applies to `ch_tryout_schema_sql()`.
- `inc/google-auth.php` / `inc/sheets-api.php` — dead OAuth-era stubs, not loaded.
  Don't wire them back; the live integration is the Apps Script Web App in
  `inc/sheets-sync.php`.
- The Apps Script manually follows GitHub-style 302→GET redirects in `sheets-sync.php`
  — that's intentional (Apps Script redirects POSTs and expects a GET). Don't "fix" it
  into a plain re-POST.
- Caches: a stale GitHub update check is cached 6h per site; **Dashboard → Updates →
  "Check again"** forces a re-poll. Roster/scores transients are theme-side, not here.

## License

GPL-2.0-or-later · Author: Connor Mesec
