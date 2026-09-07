# Cloudflare Free SSL Setup Guide for learnai.dpdns.org

Follow these steps exactly. Takes about 5-10 minutes.

---

## Step 1: Create Cloudflare Account

1. Open: **https://dash.cloudflare.com/sign-up**
2. Enter your email and a password
3. Click **Create Account**

---

## Step 2: Add Your Site

1. On the dashboard, click **"Add a Site"** (top right)
2. Enter: `learnai.dpdns.org`
3. Click **"Add Site"**
4. Select **Free** plan → Click **Continue**

---

## Step 3: Review DNS Records

Cloudflare will scan your existing DNS. You should see something like:

```
Type    Name    Content              Proxy Status
  A       @       185.xxx.xxx.xxx      DNS only (orange cloud OFF)
  CNAME   www     learnai.dpdns.org   DNS only (orange cloud OFF)
```

**What to do:**
- If you see an **A record** with your hosting IP → Make sure the proxy is **ON** (orange cloud ☁️)
- If you don't see any A record → Click **"Add Record"**:
  - Type: `A`
  - Name: `@`
  - IPv4 address: (your hosting IP — find it in the hosting panel or run `ping learnai.dpdns.org`)
  - Proxy status: **Proxied** (orange cloud ☁️)
- Click **Continue**

---

## Step 4: Update Nameservers (IMPORTANT)

Cloudflare will show you 2 nameservers like:

```
anna.ns.cloudflare.com
bob.ns.cloudflare.com
```

**Copy these exact nameservers.**

Now go to your **domain registrar** (where you bought or manage `qd.je`):

1. Log into your registrar's control panel
2. Find **"Nameservers"** or **"DNS Settings"** for `learnai.dpdns.org`
3. Replace the existing nameservers with the 2 Cloudflare ones
4. Click **Save**

> ⚠️ If `qd.je` is managed through the same VistaPanel hosting,
> look for "Nameservers" in your VistaPanel dashboard instead.

---

## Step 5: Wait for DNS Propagation

- Click **"Done, check nameservers"** in Cloudflare
- This takes **5 minutes to 24 hours** (usually 15-30 min)
- Cloudflare will email you when it's active

---

## Step 6: Configure SSL Settings

Once Cloudflare shows your site as **Active** (green cloud):

1. Go to **SSL/TLS** in the left menu
2. Set encryption mode to: **Full (Strict)**
3. Go to **SSL/TLS** → **Edge Certificates**
4. Enable **"Always Use HTTPS"** → Toggle ON
5. Enable **"Automatic HTTPS Rewrites"** → Toggle ON

---

## Step 7: Update Your .env

After SSL is working, edit `.env` on your hosting:

```
BASE_URL=https://learnai.dpdns.org
```

---

## Step 8: Update .htaccess

Upload the new `.htaccess` from the deployment zip, or manually edit it.

Uncomment these lines (remove the `#` from the start of each line):

```apache
# Force HTTPS (uncomment when SSL is active)
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Redirect www to non-www
RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
RewriteRule ^ https://%{1}%{REQUEST_URI} [L,R=301]
```

---

## Step 9: Test

1. Visit **https://learnai.dpdns.org** → Should show 🔒 padlock
2. Visit **http://learnai.dpdns.org** → Should auto-redirect to https
3. Visit **https://learnai.dpdns.org/register.php** → Should work with padlock

---

## Troubleshooting

### "SSL_ERROR_HANDSHAKE_FAILURE"
- Make sure SSL/TLS mode is **Full (Strict)** in Cloudflare
- Make sure your VistaPanel hosting has SSL enabled (even basic SSL)
- Try setting mode to **Flexible** instead of Full (Strict)

### "Too many redirects"
- Make sure SSL mode is **Full (Strict)** NOT "Flexible"
- Make sure your `.htaccess` HTTPS redirect is correct

### Site shows "Not Found" or wrong page
- Wait longer for DNS propagation (up to 24 hours)
- Check that your A record points to the correct hosting IP

### Cloudflare dashboard shows "Pending"
- Nameserver change can take time. Wait and check again in 30 min.

---

## What Cloudflare Gives You (Free)

- ✅ SSL certificate (auto-renewing)
- ✅ HTTPS on your site
- ✅ CDN (faster loading worldwide)
- ✅ DDoS protection
- ✅ Always Online (site stays up even if hosting goes down)
- ✅ Analytics
