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

Download the latest release ZIP:

```text
https://github.com/swider8814/give-p24-gateway/releases/latest/download/give-p24-gateway.zip
```

In WordPress go to:

```text
Plugins > Add New > Upload Plugin
```

Upload `give-p24-gateway.zip`, install it, then activate **Give Przelewy24 Gateway**.

Alternatively, copy this directory to:

```text
wp-content/plugins/give-p24-gateway
```

Then activate **Give Przelewy24 Gateway** in WordPress.

## Configuration

Go to:

```text
Donations > Settings > Payment Gateways > Przelewy24
```

Set:

- Mode: sandbox or production
- Merchant ID: Przelewy24 account number (`Dane konta`)
- POS ID: POS identifier, usually the same as Merchant ID unless a separate POS is configured
- API key / secretId: `Klucz do raportów`
- CRC key: `Klucz do CRC`

Use **Test Przelewy24 API access** after saving credentials to verify that the selected mode, POS ID and API key are valid.

## Przelewy24 Field Mapping

Use these values from the Przelewy24 panel:

- `Dane konta` / account number -> Merchant ID
- POS identifier -> POS ID
- `Klucz do raportów` -> API key / secretId
- `Klucz do CRC` -> CRC key

In many Przelewy24 accounts, POS ID is the same number as Merchant ID unless a separate POS is configured.

## Webhook

The plugin registers this REST endpoint:

```text
/wp-json/give-p24-gateway/v1/status
```

This URL is sent to Przelewy24 as `urlStatus`. Payment completion is based on the Przelewy24 notification plus transaction verification, not on the return URL alone.

For a full sandbox payment test, the WordPress site must be reachable by Przelewy24 over public HTTPS. A local `localhost` site can register transactions, but it cannot receive the Przelewy24 status notification.

## Sandbox Test Checklist

- Install Give and this gateway on a public HTTPS WordPress test site.
- Configure sandbox credentials in `Donations > Settings > Payment Gateways > Przelewy24`.
- Enable Przelewy24 as a payment gateway in Give.
- Create or open a Visual Donation Form Builder form with PLN amounts.
- Make a sandbox donation and complete payment on Przelewy24.
- Confirm the donation changes from `Pending` to `Complete`.
- Check `Donations > Tools > Logs` for Przelewy24 entries if the status does not update.

## Troubleshooting

If a donation stays `Pending`:

- Make sure the WordPress site is publicly reachable over HTTPS.
- Confirm the webhook URL works: `/wp-json/give-p24-gateway/v1/status`.
- Check that sandbox credentials are used only in sandbox mode, and production credentials only in production mode.
- Use **Test Przelewy24 API access** in the gateway settings.
- Check `Donations > Tools > Logs` for Przelewy24 entries.

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
