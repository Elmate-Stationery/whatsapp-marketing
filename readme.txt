=== WooCommerce WhatsApp Customer Retention ===
Requires at least: 6.4
Requires PHP: 7.4
WC requires at least: 7.2
Stable tag: 1.0.0

Find customers who have not ordered again for a while and remind them on WhatsApp, with or without a personal voucher.

== Description ==

WooCommerce → WhatsApp Customers lists every customer with a counted order (default: Completed), with order count,
total value (net of refunds), AOV, last order and days since it.

Workflow: a customer's last counted order passes the reminder period (default 30 days) and they have no open order
(Pending payment, Processing, On hold) → they appear as Eligible → you click WhatsApp or WhatsApp + Voucher →
WhatsApp opens with their number and the message filled in → you press Send. Nothing is ever sent automatically.

* Customers are identified by phone first (normalized WhatsApp number), then billing email, then account.
* A new counted order closes the reminder cycle: the reminder is Converted when the order came within the conversion
  window (default 30 days) or used its voucher; the next reminder is calculated from the new order.
* Contacted customers become "Follow-up due" after a configurable number of days without an order.
* Vouchers are unique WooCommerce coupons, created only when you click WhatsApp + Voucher and reused within the cycle.
* {offer_url} is a personal link that records whether the customer opened it and applies their voucher to the cart.
* "Contacted" means WhatsApp was opened with the message. The plugin cannot see delivery or read status.
* Works with HPOS and legacy order storage. Statistics are kept in the plugin's own indexed tables, built from orders
  in the background on activation and kept up to date by order hooks.
* Independent of Checkout Tracker.

== Changelog ==

= 1.0.0 =
* First release.
