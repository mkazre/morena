# Deploying to cPanel (app.morenagroup.co.za) — no SSH needed

The app lives in its **own folder outside `public_html`**, so the existing www.morenagroup.co.za website is never touched.

```
/home/CPANELUSER/
├── public_html/          ← existing website — DO NOT upload anything here
└── morena-crm/           ← the Laravel app (code, .env, storage — not web-reachable)
    └── public/           ← document root of app.morenagroup.co.za (only this is on the web)
```

`CPANELUSER` = your cPanel username (cPanel home → right-hand "General Information" → *Current User*;
*Home Directory* shows `/home/CPANELUSER`).

## 1. Create the subdomain
cPanel → **Domains** → **Create A New Domain** (older cPanel: **Subdomains**)
- Domain: `app.morenagroup.co.za`
- **Untick "Share document root"**
- Document Root: `morena-crm/public`  (i.e. `/home/CPANELUSER/morena-crm/public` — *not* inside `public_html`)
- Submit.

If the domain's DNS is hosted outside this cPanel (e.g. Cloudflare or the registrar), add an **A record**
`app` → the server's IP (cPanel "General Information" → *Shared IP Address*).

## 2. HTTPS certificate
cPanel → **SSL/TLS Status** → tick `app.morenagroup.co.za` → **Run AutoSSL**. Wait until it shows a green padlock
(can take a few minutes after DNS resolves). Do not continue until https works — the app only accepts HTTPS cookies.

## 3. PHP version and limits
- cPanel → **MultiPHP Manager** → tick `app.morenagroup.co.za` → PHP **8.2 or 8.3** → Apply. Note the version.
- cPanel → **MultiPHP INI Editor** → Basic mode → domain `app.morenagroup.co.za`:
  `upload_max_filesize = 12M`, `post_max_size = 64M`, `memory_limit = 256M`, `max_execution_time = 120`.
- If your cPanel has **Select PHP Version** (CloudLinux) instead, pick 8.2/8.3 and make sure these extensions are ticked:
  `pdo_mysql, mbstring, openssl, fileinfo, tokenizer, xml, ctype, curl, zip, bcmath`.

## 4. Database
cPanel → **MySQL® Databases**
1. Create database `morena_crm` (cPanel will show it as `CPANELUSER_morena_crm`).
2. Create user `morena_app` with a strong generated password (save it).
3. *Add User To Database* → **ALL PRIVILEGES** → Make Changes.

## 5. Mail account (for invites and password resets)
cPanel → **Email Accounts** → Create `noreply@morenagroup.co.za` with a strong password.
Check *Connect Devices* for the SMTP host/port (usually `mail.morenagroup.co.za`, SSL port 465).

## 6. Upload the app
1. On your PC run `.\deploy\build-release.ps1` (or use the newest zip already in `deploy\releases\`).
2. cPanel → **File Manager** → go to `/home/CPANELUSER/morena-crm` (created in step 1).
   Delete any default files cPanel put in `morena-crm/public` (keep `cgi-bin` if present).
3. **Upload** the zip into `/home/CPANELUSER/morena-crm`, then right-click → **Extract** into that same folder.
   You should see `app/ bootstrap/ config/ public/ vendor/ artisan …` directly inside `morena-crm`.
4. Delete the zip.

## 7. Create the `.env`
File Manager → **Settings** (top-right) → tick **Show Hidden Files**. In `morena-crm`: **+ File** → `.env` → Edit, and paste
the contents of `deploy/production.env` from your PC, filling in:
`DB_DATABASE=CPANELUSER_morena_crm`, `DB_USERNAME=CPANELUSER_morena_app`, `DB_PASSWORD=…`, `MAIL_PASSWORD=…`. Save.
Then right-click `.env` → **Change Permissions** → `600`.

## 8. Run the installer once via Cron
cPanel → **Cron Jobs**
1. *Cron Email*: set your email (you'll receive the output).
2. Add New Cron Job → Common Settings: **Once Per Minute** → Command (adjust user + PHP version from step 3):

   ```
   cd /home/CPANELUSER/morena-crm && /opt/cpanel/ea-php82/root/usr/bin/php artisan morena:deploy --admin-email=YOU@morenagroup.co.za --admin-name="Your Name" >> storage/logs/deploy.log 2>&1
   ```
   (PHP 8.3 → `ea-php83`. On CloudLinux "Select PHP Version" servers use `/opt/alt/php82/usr/bin/php`.)
3. Wait ~2 minutes, open `morena-crm/storage/logs/deploy.log` in File Manager and look for `== Done ==`.
4. **Delete the cron job.** (Re-running it is harmless, but it should not stay on.)

## 9. Sign in
Open https://app.morenagroup.co.za → check the admin inbox (and spam) for the set-password email → set a password → sign in.
No email? Fix `MAIL_*` in `.env`, run step 8 again (without changing anything else), then use **Forgot password?**.
Then: **Settings** (company details, branches) → **Staff Directory → + Add staff member** / **Users & Roles → + Invite user**.

Finally open https://www.morenagroup.co.za to confirm the main website is unchanged.

## Updating later
Build a new zip → upload into `morena-crm` → Extract (overwrite) → run the step-8 cron once again (without `--admin-*`)
→ delete the cron. `.env` and `storage/` are never in the zip, so settings and uploaded documents are preserved.

## Troubleshooting
| Symptom | Fix |
|---|---|
| 500 error page | Read the newest `morena-crm/storage/logs/laravel-*.log`. Common: wrong DB name/user prefix in `.env`. |
| deploy.log says *Composer detected issues in your platform* / PHP version | The cron used an older PHP. Use the exact `ea-php8x` path matching step 3. |
| deploy.log empty / "No such file" | Wrong `CPANELUSER` or folder name in the cron command. |
| Sign-in loops back to login | HTTPS not active yet, or `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` not `app.morenagroup.co.za`. |
| "Storage link" warning | Host blocks symlinks; listing photos won't display — ask the host to allow `symlink()`, everything else works. |
| Uploads fail over ~2MB | Step 3 INI limits not applied to the subdomain. |
