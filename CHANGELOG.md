# Changelog

## v1.0.0-rc2 - 2026-06-01

- Added defensive redaction for sensitive values in Przelewy24 log context.

## v1.0.0-rc1 - 2026-05-30

Release candidate after successful sandbox payment testing.

- Marked the plugin as a release candidate for production preparation.
- Documented sandbox test status and production domain whitelist requirement.
- Documented final live payment verification checklist.

## v0.1.10 - 2026-05-30

- Added a plugin list `Settings` action linking directly to Przelewy24 gateway settings.

## v0.1.9 - 2026-05-30

- Added explicit webhook validation for Przelewy24 merchant ID and POS ID.
- Added a per-donation webhook processing lock with stale-lock recovery to avoid duplicate processing during concurrent callbacks.
- Improved Przelewy24 API error handling with HTTP status and response context.
- Added the native WordPress `Requires Plugins: give` dependency header.
- Registered the webhook REST route only when Give is active.
- Reused the plugin version for the Visual Donation Form Builder script.

## v0.1.8 - 2026-05-30

- Added activation guard requiring the Give plugin to be active.
- Prevented Give integration hooks from registering when Give is inactive.

## v0.1.7 - 2026-05-30

- Added webhook validation for session ID, amount and currency.
- Added idempotent handling for repeated successful Przelewy24 webhooks.
- Added warning log when a duplicate webhook is ignored.

## v0.1.6 - 2026-05-30

First working test release.

- Added Przelewy24 sandbox and production modes.
- Added Give Visual Donation Form Builder support.
- Added offsite Przelewy24 payment registration.
- Added REST webhook endpoint for Przelewy24 status notifications.
- Added automatic Przelewy24 transaction verification.
- Added donation status update after successful payment.
- Added Przelewy24 API connection test in Give settings.
- Added Polish translation and translation template.
- Added transaction descriptions based on Give form title.
- Added donation note with visible Przelewy24 transaction ID.
- Added Give logs using native log types, category `Payment`, source `Przelewy24`.
