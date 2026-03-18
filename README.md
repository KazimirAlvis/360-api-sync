# 360 API Sync

Repository: https://github.com/KazimirAlvis/360-api-sync

## Plugin Overview

360 API Sync synchronizes clinic and doctor content from PR360 API endpoints into existing WordPress `clinic` and `doctor` CPTs. It supports remote API mode and mock JSON mode.

## Installation

1. Place plugin in `wp-content/plugins/360-api-sync`.
2. Activate **360 API Sync**.
3. Open **360 API Sync → Settings**.
4. Configure API values or enable mock mode.
5. Run manual sync.

## Configuration

Settings page fields:

- API Base URL
- API Key
- Site Slug
- Enable Mock API
- Manual Sync button

Mock files:

- `mock-data/clinics.json`
- `mock-data/doctors.json`

## API Sync Architecture

- Endpoints:
    - `GET /api/{site-slug}/clinics`
    - `GET /api/{site-slug}/doctors`
- Delta sync query:
    - `updated_since=<ISO timestamp>`
- Auth headers:
    - `Authorization: Bearer API_KEY`
    - `x-api-key: API_KEY`
- State options:
    - `360_api_last_sync`
    - `360_api_sync_last_run_result`

## Clinic & Doctor Mapping

Clinic identity key: `organization_id`

- Updates post + metadata for clinic SEO/schema keys including:
    - `_cpt360_clinic_bio`
    - `_cpt360_clinic_phone`
    - `_clinic_website_url`
    - `clinic_addresses`
    - `clinic_info`
    - `clinic_reviews`
    - `google_place_id`

Doctor identity keys: `doctor_slug` then fallback `doctor_id`

- Updates post + metadata including:
    - `doctor_name`
    - `doctor_title`
    - `doctor_bio`
    - `clinic_id` (array of clinic post IDs)

## Image Handling

- Clinic logos and doctor photos are downloaded and inserted as Media Library attachments.
- Images are stored in:
    - `/uploads/360-clinics/{organization_id}/`
    - `/uploads/360-doctors/{organization_id}/`
- Canonical keys used by theme:
    - `_clinic_logo_id`
    - `_doctor_photo_id`
- Duplicate imports are skipped when source URL has not changed.

## Cron Sync

- Event hook: `360_api_sync_event`
- Interval: every 6 hours
- Order: clinics sync first, then doctors sync
- Sync log page: **360 API Sync → Sync Log**

Database log table:

- `wp_360_api_sync_log`
- columns: `id`, `sync_time`, `context`, `clinics_processed`, `doctors_processed`, `images_imported`, `errors`

## GitHub Update System

This plugin uses YahnisElsts Plugin Update Checker (v5.6) with GitHub source:

- https://github.com/KazimirAlvis/360-api-sync

Private repository support:

- define token in `wp-config.php`:

    `define('API360_SYNC_GITHUB_TOKEN', 'your_token_here');`

- or filter it:

    `add_filter('api360_sync_github_token', fn() => 'your_token_here');`

Updater tracks branch `main` and enables GitHub release assets.

## Development

- Version: `1.0.0`
- Changelog: `CHANGELOG.md`
- Main bootstrap: `360-api-sync.php`
- Lint check:
    - `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
