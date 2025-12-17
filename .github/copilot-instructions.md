# Shup - Self-Hosted Upload Platform

## Architecture Overview
Shup is a Laravel 11 application for self-hosted file uploads, URL shortening, and paste bins. Uses SQLite by default but supports all Laravel-compatible databases.

**Core Resource Types** (each with short_code identifier):
- **Files** (`/f/{code}`) - File uploads with encryption, password protection, expiration
- **Short URLs** (`/s/{code}`) - URL shortener
- **Paste Bins** (`/p/{code}`) - Text snippet storage
- **Upload Links** (`/ul/{code}`) - One-time use links for anonymous file uploads to user accounts

Files, Short URLs, and Paste Bins implement the `Expireable` interface ([app/Expireable.php](../app/Expireable.php)) for automatic cleanup via `deleteExpired()` and `expire()` methods. Upload Links also implement `Expireable` but with single-use logic via `isValid()` and `markUsed()` methods.

## Key Patterns

### Authentication & Authorization
- **API Token Auth**: All POST/DELETE endpoints support `Authorization` header with bearer token (user's `api_token` field)
- **Role System**: Hierarchical roles via constants in [User.php](../app/Models/User.php):
  - `ROLE_USER` (0), `ROLE_CONTENT_MODERATOR` (2), `ROLE_ADMIN` (1)
  - Use `isRole($role, $exact)` method - checks hierarchy unless `$exact=true`
  - First registered user automatically gets admin role
- **Middleware**: Routes use `auth` middleware and `isAdmin` for admin-only routes ([routes/web.php](../routes/web.php))

### File Encryption Pattern
Password-protected files/pastes are encrypted at rest using Laravel's `Crypt` facade:
1. Store password hash: `Hash::check($input, $stored)`
2. Encrypt content with password before storage
3. Decrypt on retrieval (see [FileController::show](../app/Http/Controllers/FileController.php))

### Storage Tracking
Users have `storage_limit` and `storage_used` fields (bytes):
- Call `User::calculateStorage()` to recalculate from File/PasteBin/ShortURL size columns
- Decrement on deletion via `Expireable::expire()` method
- Use `File::reduceFileSize()` / `expandFileSize()` for human-readable conversions

### Configuration System
[Configuration model](../app/Models/Configuration.php) stores app-wide key-value settings:
```php
Configuration::getBool("allow_signup", false);  // Typed getters
Configuration::set("key", "value");
```

### Anonymous/Guest Uploads
Controllers check for `Authorization` header OR authenticated session. Unauthenticated uploads allowed when enabled, with optional expiration.

### Upload Links Pattern
Single-use upload links ([UploadLinkController](../app/Http/Controllers/UploadLinkController.php)):
- Created by authenticated users via `POST /ul`
- Anonymous users access via `GET /ul/{code}` and upload via `POST /ul/{code}`
- Link expires after single upload via `markUsed()` method
- Files uploaded through links belong to the link creator
- Uploaders can optionally password-protect files during upload

## Development Workflow

### Quick Start
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev  # Starts all services concurrently
```

### The `dev` Script
`composer dev` runs 4 concurrent services (see [composer.json](../composer.json)):
1. **Server**: `php artisan serve` (web server)
2. **Queue**: `php artisan queue:listen` (async jobs)
3. **Logs**: `php artisan pail` (log viewer)
4. **Vite**: `npm run dev` (frontend build)

### Frontend Stack
- **Vite** + **Tailwind CSS** for styling
- Blade templates in `resources/views/`
- Vanilla JS in `resources/js/app.js` (clipboard handling, no framework)
- Assets: `@vite(['resources/css/app.css', 'resources/js/app.js'])`

### Testing
```bash
php artisan test           # Run all tests
./vendor/bin/phpunit       # Alternative test runner
```

## Route Patterns
- **Web UI**: `/dashboard/*` routes (auth required)
- **Admin**: `/admin/*` routes (admin role required)
- **API Endpoints**: 
  - `POST /f` - Upload file
  - `POST /s` - Create short URL  
  - `POST /p` - Create paste
  - `POST /ul` - Create upload link (auth required)
  - `POST /ul/{code}` - Upload file via link (anonymous)
  - `DELETE /{type}/{shortCode}` - Delete resource
  - `GET /{type}/{shortCode}` - Retrieve resource

All APIs accept `Authorization: <api_token>` header for authentication. Upload links allow anonymous uploads without authentication.
ploadLink, User, Configuration
- **Controllers**: [app/Http/Controllers/](../app/Http/Controllers/)
- **Routes**: [routes/web.php](../routes/web.php)
- **Migrations**: [database/migrations/](../database/migrations/)
- **Storage**: Private files in `storage/app/private/files/{shortCode}`
- **Views**: Dashboard pages in `resources/views/dashboard/`, anonymous forms in `resources/views/
- **Migrations**: [database/migrations/](../database/migrations/)
- **Storage**: Private files in `storage/app/private/files/{shortCode}`

## Conventions
- Use `short_code` for URL identifiers (not `id`) across all resource types
- File operations decrement `storage_used` on deletion via `expire()` method
- Password protection = encryption + hash storage
- Increment download/hit counters only for non-owners
- Use UuidV4 for API tokens: `use Symfony\Component\Uid\UuidV4;`
