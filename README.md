# Give Przelewy24 Gateway

Przelewy24 payment gateway for GiveWP/Give donations.

## Status

MVP implementation for one-time offsite donations:

- Give payment gateway ID: `przelewy24`
- Visual Donation Form Builder support
- sandbox and production modes
- Przelewy24 transaction registration
- REST webhook endpoint for transaction verification
- English source strings with Polish translation

## Installation

Copy this directory to:

```text
wp-content/plugins/give-p24
```

Then activate **Give Przelewy24 Gateway** in WordPress.

## Configuration

Go to:

```text
Donations > Settings > Payment Gateways > Przelewy24
```

Set:

- mode: sandbox or production
- merchant ID
- POS ID
- API key / secretId
- CRC key

## Webhook

The plugin registers this REST endpoint:

```text
/wp-json/give-p24/v1/status
```

This URL is sent to Przelewy24 as `urlStatus`. Payment completion is based on the Przelewy24 notification plus transaction verification, not on the return URL alone.

## Local Test Environment

For local WordPress testing with Docker:

```bash
docker-compose up -d
```

WordPress runs at:

```text
http://localhost:8080
```

Default local test credentials:

```text
admin / admin
```

