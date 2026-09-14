# Production Deployment Checklist — Manual Upload (cPanel / FTP)

## Security fixes in this deploy — `.htaccess` is CRITICAL

The updated `.htaccess` fixes two **confirmed vulnerabilities** that exist in the current production code:

1. **The database was publicly downloadable** — old code allowed `GET /data/demo_class.db` to return the entire DB (user emails, phones, payments).
2. **Secrets were publicly downloadable** — the old `<Files ".env">` rule did not match `.env.local` or other dotenv variants.

The new `.htaccess` adds:

```apache
RewriteRule ^data/ - [F,L]           # SQLite DB, logs, rate-limit store
RewriteRule ^organizer/data/ - [F,L]
RewriteRule ^tools/ - [F,L]          # dev-only (incl. UPI callback simulator)
RewriteRule ^tests/ - [F,L]
<FilesMatch "^\.env">               # .env, .env.local, .env.production, ...
    Require all denied
</FilesMatch>
```

⚠️ **Upload the new `.htaccess` FIRST** and verify the blocks (Step 5.1) before anything else. If `.htaccess` is not applied (e.g. `AllowOverride Off`), do not deploy — ask hosting support to enable it.

Also in this deploy: session cookies get `HttpOnly`/`SameSite=Lax`/`Secure` flags (`secureSessionStart()`), all organizer endpoints accept the session login with constant-time key comparison, and every PHP file now declares `strict_types=1`.

**New file to upload: `includes/security_headers.php`** — sends CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy` from PHP on every page, so the protections hold even where `.htaccess` is ignored. CSP allows only self + YouTube (thumbnails `i.ytimg.com`, embeds `youtube-nocookie.com`) — if you later add another external resource (fonts, CDN scripts), update the policy in that file.

## Why your existing data is safe

All schema changes are **idempotent, additive migrations** that run automatically on the first request after upload (`runStartup()` in `includes/db.php`):

| Migration | Effect on existing production data |
|---|---|
| `migratePaidClasses()` | ADDs `demo_classes.is_paid` (default **0**) + `price` (nullable). Existing rows untouched — every existing class becomes a **free class** (correct default). Widens `registration_status` ENUM to add `'pending'`; existing values stay valid. |
| `migratePayments()` | CREATEs a new empty `payments` table. Touches nothing existing. |
| `migrateTrainingResources()` | CREATEs a new empty `training_resources` table. Touches nothing existing. |

Nothing is dropped, truncated, or rewritten. Old registrants, registrations, and classes remain exactly as they are; user dashboards keep working for existing emails. The backup (Step 1) is the guarantee on top.

---

## Step 1 — Backups (REQUIRED, do both)

1. **Database**: phpMyAdmin → select your DB → Export → Quick/SQL → download `backup-<date>.sql`.
2. **Current files**: in File Manager, zip the whole app folder and download it (`site-backup-<date>.zip`).

## Step 2 — Files to upload

Upload into the **same paths** on the server, preserving folder structure. Overwrite when asked.

### Modified files

```
config.php
index.php
register.php
success.php
trainers.php
certification.php    (new page — put at root)
training-calendar.php
dashboard.php
payment.php
resources.php
download.php
login.php            (new page — put at root)
logout.php           (new page — put at root)
.htaccess            (⚠️ CRITICAL security update — see top of this file)
assets/css/style.css
assets/js/organizer.js
includes/db.php
includes/functions.php
includes/class_management_service.php
includes/registration_service.php
organizer/dashboard.php
README.md            (optional docs)
.env.example         (optional docs)
```

### New files / folders

```
includes/upi_service.php
includes/dashboard_service.php
includes/admin_dashboard_service.php
includes/training_resource_service.php
includes/lib/qr_encoder.php
organizer/upi-callback.php
organizer/api_payment_status.php
organizer/api_payment_reconcile.php
organizer/api_training_resources.php
organizer/export_csv.php
assets/js/resources.js
includes/security_headers.php
sql/schema.sql ← **required** — initDB() fatals every page if missing (fresh hosts only; already present on production)
robots.txt
sitemap.xml
```

### NEVER upload

- `.env.local` — your local secrets; production keeps its own `.env`
- `data/` — local SQLite DB; **overwriting the production database is the one unrecoverable mistake**
- `organizer/data/`, `.freebuff/`
- `tests/`, `tools/` — dev-only (`tools/simulate_upi_callback.php` must not sit on a public server)
- `start-dev.bat`, `stop-dev.bat` — local-only dev-server helpers (double-click launchers; production is served by Apache, not the PHP dev server)

## Step 3 — Production `.env` additions

Open the server's `.env` in File Manager → Edit. **Keep all existing DB lines unchanged** (that is what preserves the data). Add:

```env
# ── UPI Payments ──
UPI_MERCHANT_VPA=8700046741@kotak
UPI_MERCHANT_NAME="Code & AI"
UPI_CALLBACK_SECRET=<fresh random: php -r "echo bin2hex(random_bytes(32));">
UPI_CALLBACK_URL=https://learnai.dpdns.org/organizer/upi-callback.php
UPI_PAYMENT_TIMEOUT_MINUTES=15

# ── Organizer access ──
# Used at /login.php and as X-API-Key / ?api_key= for scripts.
# Generate a long random key for production:
#   php -r "echo bin2hex(random_bytes(24));"
ORGANIZER_API_KEY=<long random key>

# ── Email (optional) ──
ENABLE_EMAIL=false
```

## Step 4 — Migrations run themselves

Visit `https://learnai.dpdns.org/health.php` once. Verify in phpMyAdmin:

```sql
SHOW COLUMNS FROM demo_classes LIKE 'is_paid';   -- exists, default 0
SHOW TABLES LIKE 'payments';                     -- exists, 0 rows
SHOW TABLES LIKE 'training_resources';           -- exists, 0 rows
SELECT COUNT(*) FROM registrants;                -- SAME number as before deploy
```

**About `install.php` (optional):** you do NOT need to run it — `runStartup()` applies the same migrations automatically on every page load. Running `/install.php` once is a nice *visual* confirmation instead of health.php (it shows PHP version, extensions, DB connection, and a green tick per migration). It is safe on an existing database: every step is `IF NOT EXISTS` / add-column-if-missing / seed-marker-guarded, so nothing is duplicated or overwritten. Its header says to delete the file after installation — it was already run on production long ago, so deleting it after this deploy is good hygiene (optional).

**cPanel tip:** dotfiles like `.htaccess` are hidden by default in File Manager — enable *Settings → Show Hidden Files* before uploading, or you'll think the upload failed.

## Step 5 — Post-deploy verification (~10 minutes)

1. **Blocked paths (do this FIRST)** — all must return **403/404**, never 200:
   ```
   https://learnai.dpdns.org/data/demo_class.db      → 403  (previously LEAKED the DB!)
   https://learnai.dpdns.org/.env.local              → 403  (previously LEAKED secrets!)
   https://learnai.dpdns.org/.env                    → 403
   https://learnai.dpdns.org/includes/db.php         → 403
   https://learnai.dpdns.org/tools/simulate_upi_callback.php → 403
   ```
   If any returns 200, STOP — the `.htaccess` did not apply (check `AllowOverride`) and the site is leaking data.
2. **Public pages still work**: `/`, `/register.php`, `/training-calendar.php`, `/resources.php`, `/trainers.php`, `/certification.php`, `/robots.txt`, `/sitemap.xml` → all **200**.
3. **Organizer access**: open `/login.php`, log in with your `ORGANIZER_API_KEY` → dashboard opens with no key in the URL. Check the footer **Log out** link works. Old registrations all still listed (now with the "Registered" date column).
4. **User dashboard** for one existing email: old enrollments still show.
5. **Regression**: existing classes show as **Free** on the register page and instant confirmation still works.
6. **Paid flow**: make one class Paid (₹999) → register with a real email → QR page → pay via real UPI (or "Mark Paid" in reconciliation) → receipt + dashboards show it.
7. **Security**: `curl -X POST https://learnai.dpdns.org/organizer/upi-callback.php -d '{}'` → **401**.
8. **Resources**: add a YouTube recording + Drive link in the organizer dashboard → check `/resources.php`.

## Step 6 — Rollback (only if needed)

- **Files**: re-upload the zip from Step 1 (or just the affected files from it).
- **Database**: import `backup-<date>.sql` in phpMyAdmin. Surgical alternative (keeps newer data): run `rollbackPaidClassesAndPayments()` / `rollbackTrainingResources()` from `includes/db.php` via a temporary CLI script — drops only the new tables/columns.
