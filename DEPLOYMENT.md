# Deploying the Laravel backend to Vercel

Deploy this directory as a separate Vercel project.

## 1. Database

Vercel serverless instances have ephemeral writable storage, so do not use SQLite for production. Configure a hosted MySQL or PostgreSQL database and set its credentials in Vercel Environment Variables.

Example MySQL variables:

```env
DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

Set `APP_URL` to the backend's final HTTPS URL and `CORS_ALLOWED_ORIGINS` to the exact frontend origins. Generate `APP_KEY` locally with `php artisan key:generate --show`, then copy it into Vercel.

Recommended serverless settings:

```env
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stderr
SESSION_DRIVER=cookie
CACHE_STORE=array
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
```

## 2. Deploy and migrate

The PHP entry file is `api/index.php`. After deployment, run migrations using a Vercel build hook or from a local machine with the production database credentials:

```powershell
php artisan migrate --force
```

Do not rely on local `/storage/app/public` or SQLite state persisting between deployments. Document and profile uploads require durable object storage (SFTP/S3-compatible) for production.

## 3. CORS

Set `CORS_ALLOWED_ORIGINS` without paths, for example:

```env
CORS_ALLOWED_ORIGINS=https://your-app.vercel.app
```

Preview deployments require their exact origin to be added or a separate CORS configuration that safely allows your preview domain pattern.
