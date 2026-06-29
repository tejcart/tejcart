=== TejCart Currency Switcher (bundled module) ===
Contributors: tejcart
Tags: currency, multicurrency, ecommerce, tejcart, exchange-rate
Tested up to: 6.7
Requires PHP: 8.2
License: GPLv2 or later

Multi-currency display for TejCart with hourly auto-refreshed exchange rates, IP geolocation, psychological-pricing rounding, per-currency payment-gateway filtering and order-level FX metadata for refund accuracy.

== Description ==

Bundled with TejCart core — toggle on via **TejCart → Modules**. Once enabled:

* Visitors see prices converted to their currency (cookie or IP geolocation).
* Admin configures each currency's rate type (Auto via the Nexa exchange-rate API, or Fixed), exchange fee, decimal places, separators and symbol position.
* Hourly cron refreshes Auto rates; manual refresh from the admin UI is one click.
* Optional psychological-pricing rounding by price band (e.g. round up to the nearest 100 and set the charm to .99).
* Checkout either accepts the customer's currency (with per-currency gateway whitelisting) or forces base currency with a dual-price reference notice.
* Every order is stamped with the rate at creation so refunds and reporting reproduce the base-currency totals.

== Hooks ==

* `tejcart_csw_exchange_rate_client` — swap the HTTP client (testing, on-prem proxy).
* `tejcart_csw_exchange_rate_request_args` — adjust timeout / sslverify / headers.
* `tejcart_csw_logger` — bind a real logger callable `(level, message)`.
* `tejcart_csw_flag_url` — override flag image URLs.
* `tejcart_csw_setting_cap` — change the capability required to edit settings.
* `tejcart_csw_refund_fx_recorded` — observe parent rate inheritance for refunds.
