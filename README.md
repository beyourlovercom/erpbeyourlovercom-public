# BYL ERP

Local interactive UI prototype for the reusable BYL ERP frontend shell. This is Phase 1 (`ERP-UI-SHELL-001`) and stops before the KOL order table.

## Requirements

PHP 8.3+, Composer, Node.js 20+ and npm. The app uses Laravel 13.8 and Vite.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
```

For the required local URL, add `127.0.0.1 erp.b` to `/etc/hosts`, then start the server on port 80 (the Mac PHP process must be allowed to bind it):

```bash
sudo sh -c 'printf "127.0.0.1 erp.b\\n" >> /etc/hosts'
php artisan serve --host=127.0.0.1 --port=80
```

Open [http://erp.b](http://erp.b). Stop with `Ctrl-C`. For an unprivileged fallback, use `php artisan serve --host=127.0.0.1 --port=8000`.

## Architecture

The permanent 78px global rail selects one of nine modules. The independent 246px module sidebar is data-driven from `config/erp_navigation.php`, scrolls independently, supports the accounting third level, and expands the workspace when collapsed. Preferences are local-only: `active_module`, `module_sidebar_collapsed`, and `expanded_menu_groups`.

Run tests with `php artisan test`; build assets with `npm run build`. No production/test deployment, databases, commerce integrations, APIs, authentication, or real ERP data are used. The main KOL order table and all business functionality are intentionally not implemented.


## Public source distribution

This repository contains a deliberately history-free source snapshot for code review and free GitHub CI. It excludes internal operating notes, customer databases, secrets, deployment credentials and private historical Git commits. Do not configure this repository to deploy directly into production without a separate operational review.

**Licensing:** This repository does not currently provide an open-source license. Public access to the source does not imply permission to copy, modify, redistribute or deploy it; request terms from the repository owner. Existing third-party dependencies retain their own licenses.
