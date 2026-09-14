# How to Use This Application

A complete guide for both **organizers** (admins) and **participants** (users) of the Code & AI training platform.

---

## For Participants (Users)

### 1. Explore the site

| Page | URL | What it's for |
|---|---|---|
| Home | `/` | Overview of the next open training + quick links |
| Training Calendar | `/training-calendar.php` | All upcoming and past trainings, with "Add to Google Calendar" buttons |
| Trainers | `/trainers.php` | Meet the trainer team |
| Training Resources | `/resources.php` | Recordings (YouTube) and slides/PDFs (Google Drive) from past sessions |
| Certification Support | `/certification.php` | Certification guidance + WhatsApp community group |
| Register | `/register.php` | Sign up for the currently open training |
| My Dashboard | `/dashboard.php?email=you@example.com` | Your enrollments and payment receipts |

Tip: use the ☀/🌙 button (top-right on every page) to switch between light and dark mode — your choice is remembered.

### 2. Register for a class

1. Go to **Register** (or click *Reserve Your Spot* on the home page).
2. Fill in your **full name**, **email**, and **phone number** (with country code).
3. Accept the WhatsApp consent if you'd like updates.
4. Submit — you'll get a **success page** with your booking details and an *Add to Google Calendar* button.
5. A confirmation email arrives if email delivery is enabled.

Free classes confirm instantly. Paid classes continue to payment.

### 3. Pay for a paid class (UPI)

1. After registering you're taken to the **payment page** showing a **QR code**, the amount, and an order reference.
2. Pay by scanning the QR with any UPI app (GPay, PhonePe, Paytm, etc.), or pay manually to the displayed UPI ID.
3. The page **polls automatically** — when your payment is confirmed, you're redirected to the receipt.
4. You have a **payment window** (default 15 minutes). If it expires, seats are released — use the *Retry* button to start a new attempt.
5. Paid but not confirmed? Don't re-pay immediately — the organizer can reconcile your payment manually (see below).

### 4. Check your dashboard

Go to `dashboard.php?email=your@email.com` (the same email you registered with) to see:
- **Your enrolled classes** with status (confirmed / pending payment) and Teams links when available
- **Payment history** with downloadable receipts

On phones, the tables scroll sideways inside their own box — the page itself stays put.

### 5. Download training materials

On **Training Resources**:
- Free-class materials: open to everyone
- Paid-class materials: enter the **email you registered with** to unlock downloads (verified against the attendee list)
- Downloads are counted so organizers can see how often each file is used

---

## For Organizers (Admins)

### 1. Log in

- Go to **`/login.php`** and enter your **organizer API key** (configured in `.env` as `ORGANIZER_API_KEY`).
- You land on the dashboard — no key in the URL needed. Click **Log out** in the footer when done.
- Old-style links with `?api_key=...` still work for scripts/bookmarks.
- Wrong attempts are rate-limited (10 per 10 minutes) to block brute force.

### 2. The organizer dashboard (`/organizer/dashboard.php`)

Every section is **collapsible** — click a section heading (or its ▼ chevron) to fold/unfold. Your open/closed choices are remembered.

#### Training Details
Create a new training (title, topic, trainer, date/time + timezone, Teams link, capacity). Toggle **Is Paid** to set a price (₹). Rules to know:
- Capacity can't be set below the number of active registrations
- Price can't change once paid registrations/pending payments exist
- Classes with registrations can be **archived** but not deleted

Each class row has **status buttons**: Open / Close registration, Cancel, Archive, and **Edit** (loads it into the form above — change any field, e.g. switch the time AM↔PM, then **Save**).

#### Registrations Filter + Registrations (N)
- Filter by class, status, or search; the table shows name, contact, class, **registration date**, status, and consent
- Actions per row: **Follow-up** (log a callback/WhatsApp touch with notes) and **Delete**
- **⬇ Export CSV** exports exactly what you've filtered

#### Payments & Revenue
- All payment attempts with status (initiated / pending / success / failed / expired), payer VPA, and amounts
- Filter by status, date range, or search; **⬇ Export CSV** honors the same filters
- **Manual reconcile** buttons per payment: *Mark SUCCESS* (confirms the registration) or *Mark FAILED* (releases the seat) — for missed UPI callbacks
- Revenue summary by class and overall

#### Training Resources
Add materials for any past class:
1. Pick the class, choose type — **recording** (paste a YouTube link) or **slides/pdf** (paste a Google Drive link; optional filename/size label)
2. Toggle **Published** (hidden resources aren't shown publicly)
3. Attendee gating is automatic: paid-class materials require the attendee's registered email on the public resources page
4. Download counts update as participants download

### 3. Monitoring & routine jobs

- **`/health.php`** — quick health check (also applies pending DB migrations when visited)
- **Expired payments** are cleaned automatically; pending seats are released
- Check the payments section for payments stuck in *initiated/pending* — reconcile those manually if the participant paid but the callback was missed

On mobile: sections collapse to save space, and wide tables scroll horizontally inside their own container.

---

## Configuration cheat-sheet (`.env`)

| Key | What it controls |
|---|---|
| `ORGANIZER_API_KEY` | Organizer login key — use a long random string in production |
| `BASE_URL` | Public URL (must be https in production; used in QR/links) |
| `DEBUG` | Keep **false** in production (error details hidden) |
| `WHATSAPP_GROUP_INVITE_URL` / `WHATSAPP_GROUP_NAME` | Community group used across CTAs |
| `WHATSAPP_CERT_GROUP_URL` / `WHATSAPP_CERT_GROUP_NAME` | Certification group (falls back to the general group) |
| `UPI_MERCHANT_VPA` / `UPI_MERCHANT_NAME` | Your UPI ID & name shown on payment QR |
| `UPI_CALLBACK_SECRET` | Secret validating payment callbacks |
| `SMTP_*` / `ENABLE_EMAIL` | Confirmation emails |

See `README.md` for the full list and `DEPLOYMENT.md` for deployment/rollback procedures.
