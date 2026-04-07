# Changelog

## 1.5.0

- Clinics with a valid `organization_id` but no `clinic_name` are now skipped — prevents `Temporary Clinic xxxxxxxx` records from being created.

## 1.4.9

- Clinics missing `organization_id` are now skipped entirely instead of being created as temporary placeholder records.

## 1.4.8

- Added temporary address debug logging to `error_log` to capture the exact API payload shape for address fields.

## 1.4.7

- Fixed address composition when the API returns `addresses` array items as plain strings — each line is now converted to an array and merged with top-level clinic fields (city/state/zip/lat/lng) before normalization.
- `clinic_address` and `clinic_addresses` now include full city/state/zip across all API payload shapes, resolving missing map pins on state pages.

## 1.4.6

- Updated address backfill logic so unchanged clinics are reprocessed when saved `clinic_addresses` differs from normalized incoming API data.
- Allows partial legacy addresses to be replaced with full street/city/state/zip values on sync.

## 1.4.5

- Added fallback address mapping from top-level API fields (`street/city/state/zip`) into `clinic_addresses`.
- Merges missing city/state/zip/coordinates into partial address rows so the single WordPress `clinic_address` value is fully composed for map pin usage.

## 1.4.4

- Fixed temporary clinic fallback matching by name to use a valid WordPress query path and exact title comparison.
- Prevents duplicate temporary clinic creation when records are missing `organization_id` but clinic names are unchanged.

## 1.4.3

- Filtered placeholder/empty About repeater rows so unused entries no longer render on clinic pages.
- Improved clinic info backfill detection so legacy placeholder-only values are replaced on the next sync.

## 1.4.2

- Fixed image importer extension handling to preserve `.svg` files instead of forcing unsupported URLs to `.jpg`.
- Added MIME fallback mapping so SVG imports are saved with `image/svg+xml` when WordPress cannot infer the type.

## 1.4.1

- Removed the `Enable Mock API` and `Dry Run Mode` checkboxes from plugin settings.
- Disabled mock and dry-run behavior at runtime to prevent hidden legacy settings from affecting sync behavior.

## 1.4.0

- Replaced GitHub Plugin Update Checker dependency with a manifest-based updater that matches the 360 Global Blocks workflow.
- Added `plugin-manifest.json` and updater integration for plugin info + update checks from the main-branch ZIP.
- Added package-folder rename handling during update so WordPress keeps the `360-api-sync` plugin directory stable.

## 1.3.4

- Added optional GitHub token field in plugin settings for private-repo update checks on live servers.
- Added admin action button to force-refresh plugin update metadata from GitHub.
- Added updater fallback to read `github_token` from saved plugin settings when constant/filter token is not provided.

## 1.3.3

- Switched GitHub updater to branch-based metadata (main) instead of release-assets mode.
- Improves update detection in live WordPress admin when changes are pushed without creating a GitHub Release.

## 1.3.2

- Reduced duplicate temporary clinic creation by normalizing phone values used in temporary key generation.
- Added fallback matching for temporary clinics by clinic name when `organization_id` is missing.

## 1.3.1

- Prefilled settings `API Base URL` with the PR360 Supabase Functions endpoint by default.
- Added fallback normalization so an empty saved base URL automatically reverts to the default endpoint.

## 1.3.0

- Added clinic/doctor active-state handling (`is_active`, `published`, `status`) to sync publish/draft state from API.
- Added cleanup pass to draft stale temporary clinics no longer present in API payload.
- Added cleanup pass to draft doctors missing from API payload.
- Strengthened duplicate doctor protection when names/slugs collide within the same sync run.
- Switched doctor identity matching to strict `doctor_id` when available and improved compatibility for legacy records.
- Fixed doctor image collisions by including post ID in generated media filenames.
- Improved manual sync behavior to bypass cursor filtering and force full reconciliation.
- Expanded clinic address normalization and coordinate extraction to support additional payload shapes.

## 1.2.1

- Added temporary-record counts widget on settings page (clinics/doctors)
- Improved visibility for data-quality backlog during sync rollout

## 1.2.0

- Added temporary record support for clinics without `organization_id` and doctors without `doctor_slug`
- Implemented temp-key matching and automatic upgrade path when permanent IDs become available
- Added duplicate prevention across permanent-key and temp-key matching paths
- Converted missing-ID handling from sync errors to warnings
- Added admin edit-screen indicator for temporary records

## 1.1.2

- Added payload-shape compatibility for clinic records nested under `clinic`/`details`
- Added organization ID key fallbacks (`organizationId`, `org_id`, nested `organization.id`)
- Added doctor payload compatibility for `providers`, `slug`, and alternate name fields
- Fixed dry-run false negatives caused by missing `organization_id` in variant payloads

## 1.1.1

- Added temporary bearer-header fallback (x-api-key remains primary)
- Added runtime warning logging when bearer fallback is used
- Added payload safety guard for unusually large clinic responses (>2000)
- Added dry-run mode to simulate sync without writing posts/meta/images
- Prevented last-sync cursor updates during dry-run mode

## 1.1.0

- Integrated PR360 production API
- Switched to /sync endpoint
- Nested doctor processing
- x-api-key authentication

## 1.0.0

Initial release

- PR360 API integration
- Clinic and doctor sync
- Image import
- Cron syncing
- Delta updates
- Sync logging
- GitHub private-repo update support
