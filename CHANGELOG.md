# Changelog

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
