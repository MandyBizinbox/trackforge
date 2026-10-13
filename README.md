# TrackForge QM

TrackForge QM is a mobile-first PWA for Scout store-room equipment management: equipment CRUD, booking requests, QM approval, collection/return logistics, check-out/check-in, damage tracking, notifications, and offline-friendly frontend caching.

Author: ForgeWorks

## Stack

- Backend: Laravel 11 style API, PHP 8.2+, MySQL/MariaDB
- Frontend: React + Vite PWA
- Auth: Bearer token API auth
- Notifications: in-app stored notifications + toast popups + push subscription scaffold
- Offline: service worker app-shell caching + local action queue scaffold

## Folder structure

```txt
trackforge-qm/
  backend/     Laravel API
  frontend/    React PWA
```

## Backend install

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

### Initial configuration

Run the database migrations and seed equipment
categories before creating application users.

The default seeder does not create user accounts,
Scout units, or equipment records.

Create the initial administrator using a secure,
documented provisioning procedure. Never ship
default production credentials.

Initial equipment inventory is managed through
a separate, idempotent inventory seeder after
the destination Scout unit has been verified.

## Frontend install

```bash
cd frontend
cp .env.example .env
npm install
npm run dev -- --host=0.0.0.0 --port=5173
```

The frontend expects the API at:

```txt
VITE_API_BASE_URL=http://localhost:8000/api
```

## Production build

```bash
cd frontend
npm install
npm run build
```

Copy `frontend/dist` to your web root and point `VITE_API_BASE_URL` to the production Laravel API.

## VPS deploy example

```bash
sudo mkdir -p /var/www/sites/trackforge-qm
sudo chown -R $USER:www-data /var/www/sites/trackforge-qm
cd /var/www/sites/trackforge-qm

# Upload/unzip the project here
cd backend
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan config:cache
php artisan route:cache

cd ../frontend
cp .env.example .env
npm install
npm run build
```

## MVP feature coverage

- Role-based users: admin, qm, scout, viewer
- Equipment categories
- Equipment CRUD
- Individual and quantity stock modes
- Booking request creation
- QM approve/reject
- QM collection and return date/time setting
- Scout notifications for approval, collection, and return details
- Check-out/check-in workflow
- Damage reporting
- Notification bell, drawer, popup toasts
- PWA manifest and service worker
- Offline app-shell caching
- Offline queue scaffold in frontend storage

## Notes

Browser push is scaffolded through `push_subscriptions`, but actual Web Push delivery requires VAPID keys and a Laravel WebPush package such as `laravel-notification-channels/webpush`. The in-app notifications are fully implemented and ready for polling.
