# Uketsuke

Digistore24 IPN receiver for Kirby 5. It checks the signature of each Instant Payment Notification, stores the purchase in a small SQLite ledger, and fires Kirby hooks so the site can react, for example by sending an access e-mail.

*Uketsuke* (受付) is the reception desk: it takes the notification, writes it down, and passes the purchase on.

## Requirements

- PHP 8.2 or newer, with `ext-json`, `ext-pdo` and `ext-pdo_sqlite`
- Kirby 5 (`^5.0`)
- A storage directory outside the web root

## Installation

With Composer, into the site's root:

```sh
composer require sayamaapps/kirby-uketsuke
```

The installer places the plugin in `site/plugins/uketsuke/`. Without Composer, copy the folder there.

## Setup at Digistore24

1. Digistore24: **Settings → Integrations → IPN → Generic**.
2. URL: `https://example.com/uketsuke/digistore24` (your domain and the `path` option).
3. Passphrase: choose one, and enter the same value as the `passphrase` option.
4. Events: `on_payment`, `on_refund`, `on_chargeback`.
5. Press **test connection**. It must answer `OK`.

Digistore24 counts a notification as delivered only when it receives the text `OK`. Any other answer, or no answer within 30 seconds, makes it retry: at least 20 times over 10 days, the first retry after 3 minutes, the interval growing to 24 hours after 10 tries. Hooks therefore have to finish well within 30 seconds. The module answers `OK` once the notification is stored, even if a hook fails.

Answers of the IPN route:

| Answer | Meaning |
|---|---|
| `200 OK` | Received and stored. Also duplicates, connection tests and events without a purchase. |
| `403 INVALID_SIGNATURE` | Signature missing or wrong. Nothing is stored. |
| `503 STORAGE_ERROR` | The database cannot be written. Digistore24 sends the notification again. |
| `503 NOT_CONFIGURED` | `active` is true, but no passphrase is set. |
| 404 | `active` is false. |

## Usage

Uketsuke only receives and records. The site decides what a purchase means, with hooks:

```php
// site/config/config.php
use Sayamaapps\Uketsuke\Notification;
use Sayamaapps\Uketsuke\Purchase;
use Sayamaapps\Uketsuke\Uketsuke;

return [
    'sayamaapps.uketsuke.active' => true,
    // passphrase and token: in site/config/env.php, see Options

    'hooks' => [
        'uketsuke.payment:after' => function (Purchase $purchase, Notification $notification, bool $retry) {
            // First payment of the order. $retry is true when the route wiederholen fired it again.
            // Runs inside the request: keep it short (Digistore24 waits 30 seconds).
            if ($purchase->test) {
                return;
            }
            // send the e-mail here, then record the outcome:
            Uketsuke::ledger()->recordDelivery($purchase->orderId, 'mail', 'sent', null, ['message_id' => 'abc']);
        },
    ],
];
```

Hooks and their arguments (Kirby matches the arguments by parameter name, so a hook may declare only the ones it needs):

| Hook | Arguments | Fired when |
|---|---|---|
| `uketsuke.payment:after` | `Purchase $purchase`, `Notification $notification`, `bool $retry` | First `on_payment` of an order. Also by the retry route, with `$retry = true`. |
| `uketsuke.payment:repeat` | `Purchase $purchase`, `Notification $notification` | Another `on_payment` for an order that is already paid (instalments). |
| `uketsuke.refund:after` | `Purchase $purchase`, `Notification $notification` | `on_refund`. The purchase status is `refunded`. |
| `uketsuke.chargeback:after` | `Purchase $purchase`, `Notification $notification` | `on_chargeback`. The purchase status is `chargeback`. |
| `uketsuke.notification:after` | `Notification $notification`, `?Purchase $purchase` | Every valid, non-duplicate notification, except the silent events below. |

Silent events (`on_payment_missed`, `on_rebill_cancelled`, `on_rebill_resumed`, `last_paid_day`, `customform`, `eticket`, `on_affiliation`) are stored and answered `OK` without any hook. Unknown events get `uketsuke.notification:after` only.

A failing hook is recorded in `notifications.hook_error` with the exception class name, and logged. The request still answers `OK`.

### PHP API for the site

```php
use Sayamaapps\Uketsuke\Uketsuke;

$ledger = Uketsuke::ledger();
$ledger->purchase('ORD-1');                         // ?Purchase
$ledger->purchases($since, $until);                 // list<Purchase>, paid in [since, until)
$ledger->delivery('ORD-1', 'mail');             // ?array: status, attempts, error, sent_at, meta
$ledger->recordDelivery('ORD-1', 'mail', 'failed', 'SMTP timeout', ['attempt' => 2]); // attempts + 1
```

`recordDelivery()` accepts the statuses `pending`, `sent`, `failed`, `skipped` and `simulated`. E-mail addresses in `$error` are removed before storage.

`Purchase` is a readonly class with `orderId`, `productId`, `productName`, `productNameIntern`, `email`, `firstName`, `lastName`, `status`, `paidAt`, `refundedAt`, `test`, `language`, `country`, `affiliate`, `campaignKey`, `amount`, `currency`, `custom`, `buyerId`, and `toArray()`.

## Routes

`{path}` is the `path` option, `uketsuke/digistore24` by default.

| Route | Answer |
|---|---|
| `POST {path}` | IPN receiver, see the table above. |
| `GET {path}` | `200 OK` while active. Reveals nothing else. |
| `GET {path}/protokoll?since=YYYY-MM-DD&until=YYYY-MM-DD` | JSON list of purchases with their deliveries, without buyer data. `since` defaults to 30 days ago, `until` is inclusive, dates are Europe/Berlin. |
| `POST {path}/wiederholen` | Fires `uketsuke.payment:after` again with `retry = true`, for paid orders. Give `order_id`, or `channel` and `status` to retry every paid order with such a delivery. Answers `{"triggered": [...]}`. |

The last two need the `token` option and `Authorization: Bearer <token>`. Without a token option they answer 404; a wrong or missing token answers `401 UNAUTHORIZED`.

```sh
curl -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  "https://example.com/uketsuke/digistore24/protokoll?since=2026-10-01&until=2026-10-31"

curl -X POST -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  -d "order_id=ORD-1" "https://example.com/uketsuke/digistore24/wiederholen"

curl -X POST -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  -d "channel=mail&status=failed" "https://example.com/uketsuke/digistore24/wiederholen"
```

## Options

All options are read as `sayamaapps.uketsuke.<key>`.

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | `false`: all routes answer 404 and nothing is written. |
| `passphrase` | `null` | The IPN passphrase from Digistore24. Required while active. |
| `token` | `null` | Bearer token for the protocol and retry routes. `null` disables both routes. |
| `path` | `'uketsuke/digistore24'` | Route prefix. |
| `retention` | `90` | Days after which notifications are deleted (at least 1). Purchases and deliveries are kept. |
| `storage` | `null` | Storage directory. `null` means `<storage root>/uketsuke`. It must lie outside the web root, also through symlinks; otherwise the module refuses to write. |

Put the passphrase and the token in `site/config/env.php`. Kirby loads that file per environment, and it stays out of the repository, for example `'sayamaapps.uketsuke.passphrase' => '...'`.

## Data and retention

- Database: `<storage>/uketsuke/uketsuke.sqlite` (WAL mode; the `-wal` and `-shm` files sit next to it). The directory is created with mode `0700`.
- `purchases`: one row per order with buyer e-mail, name, country and order data.
- `deliveries`: one row per order and channel, written by the site through `recordDelivery()`.
- `notifications`: every received notification, with an allow-list of non-personal fields only. Rows older than `retention` days are deleted on each request.
- The module makes no outbound requests and sends no data anywhere.

To remove one order, stop the module or open the database with `sqlite3` and delete from `deliveries` and `purchases` where `order_id` matches. See `docs/DATENSCHUTZ.md` (German) and `PRIVACY.md` (English).

## Maintenance

- Logs go to `error_log()` with the prefix `Uketsuke:`. They contain order ids and exception class names, never e-mail addresses, names or the passphrase.
- The schema version is kept in `PRAGMA user_version`.
- The ledger uses only SQL that SQLite 3.7.17 understands, because some shared hosters still ship it.

## Development

```sh
composer install
composer test                     # PHPUnit
composer analyze:psalm            # Psalm
composer analyze:phpmd            # PHP Mess Detector
composer analyze:unused           # unused Composer packages
composer analyze:require-checker  # undeclared dependencies
composer normalize:check          # composer.json layout
```

Code style follows Kirby's rule set (`getkirby/php-cs`, binary `kirby-cs`). See `.github/CONTRIBUTING.md`.

## Repository layout

```
index.php           registration only
autoload.php        class map for F::loadClasses()
config/             options, routes
src/                one class per file, namespace Sayamaapps\Uketsuke
tests/              PHPUnit, boots a real Kirby app per test class
docs/               German privacy text (not in the package)
PRIVACY.md          English privacy notes
CHANGELOG.md        version history
```

## License

MIT, see `LICENSE.md`.
