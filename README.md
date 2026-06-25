# MAEGC PHP Backend for LWS Perso

This folder is the PHP/MySQL replacement for the current Render Node/Prisma backend.

The current backend is intentionally left untouched. Use this PHP backend only after importing and verifying the data in MySQL.

## Requirements

- PHP 8.1+ with PDO MySQL and cURL enabled
- MySQL/MariaDB database
- Apache rewrite support (`.htaccess`)
- Existing Cloudinary credentials if logo/news uploads must keep working

## Files you must configure

1. Copy `config.example.php` to `config.php`.
2. Fill:

```php
'db' => [
  'host' => 'your-lws-mysql-host',
  'database' => 'your-db-name',
  'user' => 'your-db-user',
  'password' => 'your-db-password',
],
'jwt_secret' => 'long-random-secret',
'public_api_url' => 'https://your-api-domain.com',
```

3. Add Cloudinary credentials if uploads are used:

```php
'cloudinary' => [
  'cloud_name' => '...',
  'api_key' => '...',
  'api_secret' => '...',
],
```

## No-data-loss migration order

Do the cutover in this order:

1. Keep the current Render backend online.
2. Temporarily stop admin writes in the app:
   - turn off player creation
   - turn off edit mode
   - turn off mercato
3. Export the current PostgreSQL/Prisma data from the old backend:

```bash
cd backend
npm run export:data -- ../maegc-export.json
```

4. Create the MySQL tables on LWS by importing:

```sql
backend-php/database/schema.mysql.sql
```

5. Upload `backend-php` to LWS and create `config.php`.
6. Import the JSON export into MySQL:

```bash
php scripts/import-data.php ../maegc-export.json
```

If LWS does not provide SSH, run the import locally against the remote LWS MySQL database by putting the LWS DB credentials in `config.php`.

7. Optional but recommended: mirror old rules PDFs into the new PHP hosting:

```bash
php scripts/mirror-rules-pdfs.php
```

8. Test the PHP API before changing Vercel:

```text
GET https://your-api-domain.com/
GET https://your-api-domain.com/teams
POST https://your-api-domain.com/auth/login
```

9. In Vercel, change the frontend environment variable:

```text
VITE_API_URL=https://your-api-domain.com
```

10. Redeploy the frontend.

Only after all checks pass should you stop the Render backend.

## API compatibility

The PHP backend keeps the same frontend-facing routes:

- `/auth/*`
- `/players/*`
- `/teams/*`
- `/competitions/*`
- `/matches/*`
- `/contracts/*`
- `/mercato/*`
- `/banned/*`
- `/settings/*`
- `/standings/*`
- `/news/*`
- `/api/public/calendar-events`
- `/api/admin/calendar-events`

Both `/api/...` and non-`/api/...` route forms are accepted.

## Known difference

Contract PDFs use the original JPG contract template and render player data directly into the PDF without Chromium:

```text
GET /contracts/player/:playerId/pdf
```

The renderer embeds the template directly, so it does not require GD, Imagick, Puppeteer, or Chromium.
