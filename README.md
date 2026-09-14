# Freebuff Registration System (PHP)

> A campaign-driven registration flow for demo classes, built with **PHP, HTML, CSS, JavaScript, and MySQL**.

A PHP web application that captures user registrations from Facebook ad campaigns, manages WhatsApp consent, sends confirmation emails via SMTP, and provides an organizer dashboard for manual follow-up — all backed by MySQL.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [API Reference](#api-reference)
- [Data Model](#data-model)
- [Deployment](#deployment)

---

## Overview

When someone clicks a Facebook ad for a demo class, they land on a focused registration page where they enter their name, email, and phone number. The page shows the course name and date as read-only labels. On successful registration, the success page shares the Teams meeting link and they receive a **confirmation email** via SMTP. If they opted in to WhatsApp, they are redirected to a group invite link.

An organizer dashboard allows the operations team to view all registrations, filter by class and consent status, search by name/email/phone, and record manual follow-up actions.

---

## Features

### User-Facing
- **Mobile-first registration form** — name, email, phone with inline validation
- **Confirmation email** — HTML email with class details, Teams link, and WhatsApp button
- **Confirmation page** — class details, Teams meeting link, and WhatsApp redirect
- **Duplicate prevention** — same contact + same class = one registration
- **Recovery messaging** — clear notice when the meeting link is unavailable

### Organizer-Facing
- **API-key-protected dashboard** — view all registrations
- **Filter by class, consent, and search** — find registrants fast
- **Record follow-up actions** — announcement, group invite, reminder, opt-out
- **Consent enforcement** — blocked from selecting non-consented registrants for WhatsApp

### Technical
- **Privacy-safe logging** — only UUIDs in logs, no personal data exposed
- **Consent audit trail** — full history of consent/withdrawal
- **Health check endpoint** — `/health.php` for uptime monitoring
- **Configurable form heading** — change via `REGISTRATION_FORM_HEADING` env var
- **Configurable course detail** — pick the live course from a config catalog via `ACTIVE_COURSE_INDEX`; shown as read-only labels on the registration page and saved with each registration
- **Training details** — organizers manage the training topic and trainer name from the dashboard
- **Training calendar** — public active and upcoming training listings at `/training-calendar.php`
- **Graceful email fallback** — registration works even if email service is down

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Language** | PHP 8.0+ |
| **Database** | MySQL 5.7+ / 8.0+ |
| **Frontend** | HTML5, CSS3, Vanilla JavaScript |
| **Email** | PHP `mail()` function (SMTP compatible) |
| **Server** | Apache with mod_rewrite (or Nginx) |
| **Deployment** | Any PHP hosting (cPanel, shared hosting, Docker) |

---

## Project Structure

```
php-registration/
├── .env.example                 # Environment variables template
├── .htaccess                    # Apache URL rewriting
├── config.php                   # Application configuration
├── index.php                    # Code & AI landing page
├── register.php                 # Registration form + submission
├── success.php                  # Confirmation page
├── health.php                   # Health check endpoint
├── api_demo_classes.php         # API: list demo classes
│
├── organizer/
│   ├── dashboard.php            # Organizer dashboard page
│   ├── api_registrations.php    # API: list registrations
│   ├── api_follow_up.php        # API: record follow-up
│   └── api_delete.php           # API: delete registration
│
├── whatsapp/
│   ├── redirect.php             # WhatsApp redirect handler
│   └── status.php               # WhatsApp status API
│
├── includes/
│   ├── db.php                   # Database connection & init
│   ├── functions.php            # Helper functions
│   ├── registration_service.php # Registration business logic
│   ├── email_service.php        # Email sending service
│   └── whatsapp_service.php     # WhatsApp service
│
├── sql/
│   └── schema.sql               # MySQL database schema
│
├── templates/
│   └── email/
│       └── registration_confirmation.html  # Email template
│
├── assets/
│   ├── css/
│   │   └── style.css            # Instagram-inspired styles
│   └── js/
│       ├── validation.js        # Client-side validation
│       └── organizer.js         # Dashboard interactivity
│
└── README.md                    # This file
```

---

## Getting Started

### Prerequisites

- PHP 8.0 or newer
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite (or Nginx equivalent)

### Installation

1. **Clone or download the project**
   ```bash
   git clone <repository-url>
   cd php-registration
   ```

2. **Create the database**
   ```bash
   mysql -u root -p -e "CREATE DATABASE demo_class CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env with your database credentials and settings
   ```

4. **Set up web server**
   - Point your web server's document root to the `php-registration/` directory
   - Ensure mod_rewrite is enabled (Apache)
   - Or use PHP's built-in server for testing:
     ```bash
     php -S localhost:8000
     ```

5. **Access the application**
   - Open http://localhost:8000/register in your browser
   - Database tables will be created automatically on first run

---

## Configuration

All settings are configurable via environment variables or a `.env` file.

### Core Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `Freebuff Registration` | Application title |
| `DEBUG` | `false` | Enable debug mode and SQL logging |
| `BASE_URL` | `http://localhost:8000` | Application base URL |

### Database Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `demo_class` | Database name |
| `DB_USER` | `root` | Database username |
| `DB_PASS` | (empty) | Database password |

### Security Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `SECRET_KEY` | `change-this-in-production` | Secret key for sessions |
| `ORGANIZER_API_KEY` | `organizer-secret-key` | API key for organizer endpoints — used at `/login.php` and accepted as `X-API-Key`/`?api_key=` |

### Organizer Access

- Open **`/login.php`**, enter your `ORGANIZER_API_KEY`, and you are logged in for the session (brute-force limited to 10 attempts / 10 minutes per IP).
- Session login is preferred: the key never appears in URLs, browser history, or logs.
- Log out with the footer link on the dashboard or `/logout.php`.
- Programmatic access still works with `X-API-Key: <key>` header or `?api_key=<key>` query parameter.
- For production, set a long random key (e.g. `php -r "echo bin2hex(random_bytes(24));"`).

### Registration Form

| Variable | Default | Description |
|----------|---------|-------------|
| `REGISTRATION_FORM_HEADING` | `Sign Up for Boot Camp` | Form page heading |
| `ACTIVE_COURSE_INDEX` | `0` | Index (0-based) of the active course in the `DEMO_CLASSES` catalog in `config.php`; its details are shown on the registration page and new registrations are saved against it |

The course catalog (`DEMO_CLASSES` in `config.php`) holds title, date, timezone, and Teams meeting link for every course. Change `ACTIVE_COURSE_INDEX` in `.env` to make a different course live — the registration page shows the selected course's name and date as read-only labels, and the course is saved in the database together with each registration. The Teams meeting link is shared on the success page and in the confirmation email.

### WhatsApp

| Variable | Default | Description |
|----------|---------|-------------|
| `WHATSAPP_GROUP_INVITE_URL` | (configured) | WhatsApp group invite link |
| `WHATSAPP_CERT_GROUP_URL` | falls back to general group | Certification-support WhatsApp group (certification.php) |
| `WHATSAPP_REDIRECT_BASE` | `https://wa.me` | WhatsApp redirect base |

### Email (SMTP)

| Variable | Default | Description |
|----------|---------|-------------|
| `ENABLE_EMAIL` | `false` | Set to `true` to enable emails |
| `SMTP_HOST` | `smtp.gmail.com` | SMTP server host |
| `SMTP_PORT` | `587` | SMTP server port |
| `SMTP_USER` | (empty) | SMTP username |
| `SMTP_PASS` | (empty) | SMTP password |
| `SMTP_USE_TLS` | `true` | Use TLS encryption |
| `SMTP_FROM_EMAIL` | (empty) | Sender email address |
| `SMTP_FROM_NAME` | `Freebuff Registration` | Sender display name |

### UPI Payments (Paid Classes)

Direct UPI integration — no third-party payment gateway fees.

| Variable | Default | Description |
|----------|---------|-------------|
| `UPI_MERCHANT_VPA` | `merchant@upi` | Merchant UPI ID that receives payments |
| `UPI_MERCHANT_NAME` | `Freebuff Classes` | Payee name shown in UPI apps |
| `UPI_CALLBACK_SECRET` | (change it!) | Shared secret for HMAC-SHA256 callback verification — must match your UPI provider's configuration |
| `UPI_PAYMENT_TIMEOUT_MINUTES` | `15` | Unpaid payment requests expire after this; seats are released |
| `UPI_CALLBACK_URL` | `BASE_URL/organizer/upi-callback.php` | Public URL that receives payment callbacks (use ngrok or similar for local testing) |

### Paid Classes & UPI Payment Flow

1. **Admin creates a paid class** on the organizer dashboard (tick "Is Paid", enter a price > 0) and opens registration.
2. **User registers** on `/register.php` — paid classes show the fee and a "Register & Pay via UPI" button.
3. **Payment page** (`/payment.php`) shows a scannable dynamic UPI QR code, the amount, and the order ID. The page polls for payment status automatically.
4. **User pays** with any UPI app. The UPI network POSTs a signed callback to `UPI_CALLBACK_URL`.
5. **Callback verification**: the endpoint verifies the HMAC-SHA256 signature, validates the amount, applies idempotency (duplicate `txnId` is ignored), then confirms the registration and releases the receipt.
6. **Failure / expiry**: failed, cancelled, or 15-minute-expired payments cancel the pending registration and release the seat; users can retry via "Retry Payment".
7. **Reconciliation**: for missed callbacks, admins can "Mark Paid" / "Mark Failed" directly from the payment logs table on the organizer dashboard.

**QR code generation**: self-contained pure-PHP encoder in `includes/lib/qr_encoder.php` (QR Model 2, byte mode, ECC L, versions 1–7, ≤ 156 bytes) — verified bit-identical against reference implementations. If you prefer composer, `composer require chillerlan/php-qrcode` also works: swap the encoder call in `includes/upi_service.php` (`generateUpiQrCode()`).

### Testing

```bash
# With composer:
composer install && vendor/bin/phpunit

# Without composer (fallback runner, same test classes):
php tools/run_tests.php          # all suites
php tools/run_tests.php unit
php tools/run_tests.php integration
```

Tests run on a SQLite in-memory database — no local MySQL needed.

---

## Training Resources

Recordings and materials for past trainings, shown on `/resources.php`.

- **Recordings** are hosted on your dedicated YouTube channel (unlisted works fine — only the 11-char video ID is stored). Paste any YouTube URL shape; the app extracts the ID. The public page uses a fast thumbnail facade — the player loads only on click.
- **Slides/PDF** are hosted on **Google Drive** (no load on this server). In Drive: share the file as **"Anyone with the link → Viewer"**, copy the share link, and paste it. The app converts it to a direct-download link.
- **Gating**: free-class materials are open to everyone. **Paid-class materials require the email used at registration** (must have a confirmed registration) — entered via an inline "Unlock downloads" form, verified server-side on every download. Download counts are tracked per resource.
- **Managing**: organizer dashboard → **Training Resources** section (pick class, type, paste link, publish/hidden, delete). Resources for a class become publicly visible on the resources page only after the class date has passed.
- APIs: `organizer/api_training_resources.php` (GET/POST/PUT/DELETE + `POST ?action=publish`), protected by the organizer API key.

---

## API Reference

### Public Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/register.php` | Registration form page |
| `POST` | `/register.php` | Submit registration (form data) |
| `GET` | `/payment.php?order={merchant_order_id}` | UPI payment page (QR code) for a paid class |
| `GET` | `/dashboard.php?email={email}` | User dashboard: enrolled classes + payment history |
| `GET` | `/health.php` | Health check (returns JSON) |
| `GET` | `/api_demo_classes.php` | List active demo classes (JSON) |
| `GET` | `/training-calendar.php` | View active and upcoming training details |
| `GET` | `/resources.php` | Training Resources: past-session recordings (YouTube) + slides/PDF (Google Drive) |
| `GET` | `/download.php?id={resource_id}&email={email}` | Download a resource. Paid-class files require the attendee's registration email; free-class files are open. Redirects to Google Drive |

### Payment Endpoints
| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/organizer/upi-callback.php` | UPI payment callback (HMAC-SHA256 signed, rate limited, idempotent) |
| `GET` | `/organizer/api_payment_status.php?order={merchant_order_id}` | Payment status (polled by the payment page) |
| `POST` | `/organizer/api_payment_reconcile.php?payment_id={id}` | Admin reconciliation override (`{"status":"success or failed"}`) |

### Organizer Endpoints

> **Authentication** (either works):
> 1. **Session login (recommended)** — log in once at `/login.php` with your organizer API key; the cookie authenticates the dashboard and all organizer APIs. Log out via `/logout.php` (link also in the dashboard footer).
> 2. **API key** — pass `X-API-Key` header or `?api_key=...` query parameter (kept for scripts and existing bookmarks).

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/organizer/dashboard.php` | Dashboard HTML page |
| `GET` | `/organizer/api_registrations.php` | List registrations (JSON) |
| `POST` | `/organizer/api_follow_up.php?registration_id={id}` | Record follow-up |
| `DELETE` | `/organizer/api_delete.php?registration_id={id}` | Delete registration |

**Query Parameters** for registrations API:
- `demo_class_id` — filter by class
- `whatsapp_consent=true|false` — filter by consent
- `search` — text search on name, email, phone

### WhatsApp Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/whatsapp/redirect.php?id={registration_id}` | Redirect to WhatsApp invite |
| `GET` | `/whatsapp/status.php?id={registration_id}` | Get redirect status |

---

## Data Model

Seven tables support the registration lifecycle:

| Table | Purpose |
|-------|---------|
| `registrants` | Person data (name, email, phone, consent state) |
| `demo_classes` | Scheduled classes (title, time, timezone, Teams link) |
| `registrations` | Registrant-to-class mapping (status, duplicate key) |
| `whatsapp_consent_records` | Consent/withdrawal audit trail |
| `manual_follow_up_records` | Organizer follow-up action history |
| `campaign_sources` | Facebook ad / campaign attribution |
| `whatsapp_redirect_records` | Invite URL, redirect status, errors |

---

## Deployment

### Hosting panel deployment

The hosting panel URL `https://dash.domain.digitalplat.org/domains/learnai.dpdns.org`
is the management portal, not the public application URL. Configure the domain's document
root to the uploaded project directory, then open the application at:

`https://learnai.dpdns.org/`

1. Open the domain in the hosting panel and confirm PHP 8.0+ with `mysqli` or `pdo_mysql`.
2. Upload the project contents to the domain document root. Upload `.htaccess`, but do not
   upload local `.env`, `data/`, or `vendor/` files.
3. Copy `.env.production` to `.env` on the server and replace all placeholder database,
   secret, organizer key, WhatsApp, and SMTP values.
4. Create the MySQL database and user in the panel, grant the user all required privileges,
   and put the panel-prefixed names into `.env`.
5. Visit `/install.php` once to initialize and migrate the database, then delete `install.php`.
6. Enable SSL for `learnai.dpdns.org`, then uncomment the HTTPS redirect in `.htaccess`.
7. Verify `/`, `/register.php`, `/training-calendar.php`, `/health.php`, and the organizer
   dashboard before sharing the public URL.

The Cloudflare setup checklist is in [CLOUDFLARE_SETUP.md](CLOUDFLARE_SETUP.md).

### Shared Hosting (cPanel, etc.)

1. Upload all files to your web hosting
2. Create a MySQL database via cPanel
3. Update `.env` with your database credentials
4. Ensure mod_rewrite is enabled
5. Access your domain in the browser

### Docker

```dockerfile
FROM php:8.2-apache

# Enable mod_rewrite
RUN a]2enmod rewrite

# Install MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Copy application
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/php-registration;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## Email Setup

### Gmail SMTP

1. Enable 2-Factor Authentication on your Google account
2. Generate an App Password at https://myaccount.google.com/apppasswords
3. Configure in `.env`:
   ```
   ENABLE_EMAIL=true
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USER=your-email@gmail.com
   SMTP_PASS=your-app-password
   SMTP_FROM_EMAIL=your-email@gmail.com
   ```

### SendGrid SMTP

1. Create account at https://sendgrid.com
2. Create SMTP credentials
3. Configure in `.env`:
   ```
   ENABLE_EMAIL=true
   SMTP_HOST=smtp.sendgrid.net
   SMTP_PORT=587
   SMTP_USER=apikey
   SMTP_PASS=your-sendgrid-api-key
   SMTP_FROM_EMAIL=your-verified@email.com
   ```

---

## Testing

### Automated checks

Install the development dependency with Composer, then run the PHPUnit suite:

```bash
composer install
vendor/bin/phpunit
```

Validate PHP syntax for all application and test files:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

The class-management feature also includes an end-to-end validation guide at
`specs/001-demo-class-management/quickstart.md`.

### Manual Testing

1. **Registration Flow**
   - Visit `/register.php`
   - Fill out the form and submit
   - Verify success page displays correctly
   - Check email inbox for confirmation

2. **Organizer Dashboard**
   - Visit `/organizer/dashboard.php?api_key=organizer-secret-key`
   - Verify registrations are listed
   - Test filters and search
   - Test follow-up recording
   - Test registration deletion

3. **WhatsApp Flow**
   - From success page, click "Join Group"
   - Verify redirect to WhatsApp group invite
   - Check consent is recorded in database

4. **Health Check**
   - Visit `/health.php`
   - Verify JSON response: `{"status":"ok","service":"..."}`

### Demo class management

Authorized organizers can create, edit, open, close, cancel, and archive demo classes from
the dashboard. Class details are stored in MySQL; editing `config.php` is no longer required
for dashboard-managed classes. A nullable capacity means unlimited registration. Existing
registrations remain available when a class is cancelled or archived.

The organizer class APIs are documented in
`specs/001-demo-class-management/contracts/class-management-api.md`.

---

## Differences from Python Version

| Aspect | Python Version | PHP Version |
|--------|---------------|-------------|
| **Framework** | FastAPI | Native PHP |
| **ORM** | SQLAlchemy | MySQLi (prepared statements) |
| **Templates** | Jinja2 | PHP native + HTML |
| **Database** | SQLite/PostgreSQL | MySQL |
| **Email** | SendGrid API | PHP mail() / SMTP |
| **Testing** | pytest | Manual testing |
| **Deployment** | Render | Any PHP hosting |

---

## License

This project is for demonstration purposes.
