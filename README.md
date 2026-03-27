# Wizarr Reset Password

A lightweight self-hosted password reset page for [Wizarr](https://github.com/wizarrrr/wizarr) — since Wizarr doesn't ship a built-in forgot-password flow.

When a user submits their username or email, this page:
1. Looks them up via the Wizarr API
2. Requests a password reset token from Wizarr
3. Sends the reset link to the user's email via [Brevo](https://www.brevo.com/)

Includes configurable bot protection (Cloudflare Turnstile, hCaptcha, Google reCAPTCHA v2/v3, or none), validated server-side so the check can't be bypassed by removing it from the frontend.

## Requirements

- PHP 7.4+ with `curl` extension
- A Wizarr instance with API access
- A [Brevo](https://www.brevo.com/) account (free tier works)
- A captcha provider account (see below) — or set `CAPTCHA_PROVIDER` to `none` to disable

## Setup

**1. Clone the repo**

```bash
git clone https://github.com/your-username/Wizarr-ResetPassword.git
cd Wizarr-ResetPassword
```

**2. Create your config**

```bash
cp config.php.example config.php
```

Then edit `config.php` and fill in all values:

| Constant | Description |
|---|---|
| `WIZARR_INTERNAL_URL` | Internal URL of your Wizarr instance (used for API calls, e.g. `http://192.168.1.10:5690`) |
| `WIZARR_EXTERNAL_URL` | Public-facing URL of your Wizarr instance (used in emailed reset links) |
| `WIZARR_API_KEY` | Wizarr API key — found in Wizarr → Settings → API |
| `BREVO_API_KEY` | Brevo API key — found in Brevo → Settings → API Keys |
| `MAIL_FROM_EMAIL` | Sender email address shown on reset emails |
| `MAIL_FROM_NAME` | Sender name shown on reset emails |
| `MAIL_SUBJECT` | Subject line for reset emails |
| `CAPTCHA_PROVIDER` | Which captcha to use: `turnstile`, `hcaptcha`, `recaptcha_v2`, `recaptcha_v3`, or `none` |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile keys |
| `HCAPTCHA_SITE_KEY` / `HCAPTCHA_SECRET_KEY` | hCaptcha keys |
| `RECAPTCHA_V2_SITE_KEY` / `RECAPTCHA_V2_SECRET_KEY` | Google reCAPTCHA v2 keys |
| `RECAPTCHA_V3_SITE_KEY` / `RECAPTCHA_V3_SECRET_KEY` | Google reCAPTCHA v3 keys |
| `RECAPTCHA_V3_THRESHOLD` | Minimum reCAPTCHA v3 score to pass (default `0.5`, range `0.0`–`1.0`) |

**3. Set up your captcha provider**

Only fill in the keys for the provider you've set in `CAPTCHA_PROVIDER` — the others are ignored.

| Provider | Dashboard |
|---|---|
| Cloudflare Turnstile | https://dash.cloudflare.com/?to=/:account/turnstile |
| hCaptcha | https://dashboard.hcaptcha.com/ |
| Google reCAPTCHA v2 / v3 | https://www.google.com/recaptcha/admin |

**4. Configure nginx**

A ready-to-use example is in `nginx.conf.example`. Copy it to your nginx sites directory and replace the `ALL_CAPS` placeholders:

| Placeholder | Example value |
|---|---|
| `DOMAIN` | `wizarr.example.com` |
| `WIZARR_HOST` | `127.0.0.1:5690` |
| `RESET_PATH` | `/var/www/reset-password` |
| `PHP_FPM_SOCK` | `unix:/run/php/php8.3-fpm.sock` |
| `CERT_FILE` / `KEY_FILE` | paths to your TLS certificate and key |

The config serves the reset page at `/reset-password` as PHP-FPM, proxies everything else to Wizarr, and blocks direct access to `config.php`.

**5. Deploy**

Drop the files onto any PHP-capable web server or container. `config.php` is listed in `.gitignore` so your secrets won't be committed.

## How it works

```
User submits form
  → Captcha token verified server-side against provider API
  → Username/email looked up via Wizarr API
  → Reset token requested from Wizarr API
  → Reset link emailed to user via Brevo
```

User-existence is never revealed in the response — if no account is found, the page still shows the "check your inbox" message to prevent enumeration.

## Security notes

- Captcha secret keys are never sent to the browser
- Every POST is verified server-side before any backend calls are made
- No session state, no database — stateless PHP
- `config.php` is blocked at the nginx level and also excluded from git
