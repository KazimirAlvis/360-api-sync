# 360 API Sync

Repository: https://github.com/KazimirAlvis/360-api-sync

## Plugin Overview

360 API Sync synchronizes clinic and doctor content from the PR360 API into existing WordPress `clinic` and `doctor` CPTs. It supports remote API mode and mock JSON mode.

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
- GitHub Token (optional, for private-repo plugin updates)
- Site Slug
- Enable Mock API
- Dry Run Mode
- Manual Sync button

Mock file:

- `mock-data/clinics.json`

## API Sync Architecture

- Base URL:
    - `https://cmltutizsixpurslzfzl.supabase.co/functions/v1`
- Endpoint:
    - `GET /sync?site_slug={site_slug}`
- Auth headers:
    - `x-api-key: API_KEY`
    - temporary fallback: `Authorization: Bearer API_KEY` (compatibility only)
- State options:
    - `360_api_last_sync`
    - `360_api_sync_last_run_result`

API response shape:

- `site_slug`
- `condition`
- `clinics[]`
    - each clinic includes `doctors[]`

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

Doctor identity key: `doctor_id` (with `doctor_slug` used for URL/temporary compatibility)

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
- Order: fetch payload once, sync clinics, then sync nested doctors
- Payload safety guard: sync aborts if clinic count is empty or exceeds 2000
- Sync log page: **360 API Sync → Sync Log**

## Dry Run Mode

- Enable **Dry Run Mode** in plugin settings to simulate a sync.
- When enabled, the plugin:
    - fetches and parses API payloads
    - computes create/update counts
    - does **not** write posts, meta, images, or advance `360_api_last_sync`
- Useful for validating API changes safely before live writes.

## Temporary Records Handling

- Clinics without `organization_id` and doctors without `doctor_slug` are created as temporary records.
- Temporary records are marked with `_360_is_temporary = 1` and a deterministic `_360_temp_key`.
- On later syncs, when permanent IDs become available, temporary records are upgraded instead of creating duplicates.
- Missing IDs are treated as warnings (not fatal sync errors) unless a record is missing both identity and display fields.

## Publish / Draft Lifecycle Sync

- Clinics and doctors are drafted when API marks them inactive via `is_active`, `published`, or `status`.
- Doctors missing from the latest API payload are drafted during cleanup.
- Temporary clinic records missing from the latest payload are drafted during cleanup.
- Manual sync runs in full-reconciliation mode (cursor bypass) to apply lifecycle updates immediately.

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

- Version: `1.3.4`
- Changelog: `CHANGELOG.md`
- Main bootstrap: `360-api-sync.php`
- Lint check:
    - `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
