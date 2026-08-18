-- Test database, created on first container start alongside the dev database.
-- Kept separate so `php artisan test` (RefreshDatabase) never truncates dev data.
CREATE DATABASE IF NOT EXISTS ev_charging_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON ev_charging_test.* TO 'ev_charging'@'%';
FLUSH PRIVILEGES;
