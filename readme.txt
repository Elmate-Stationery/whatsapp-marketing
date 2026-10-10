=== Customer Campaigns ===
Requires at least: 6.4
Requires PHP: 7.4
WC requires at least: 7.2
Stable tag: 1.2.0

Find customers who have not ordered again for a while and remind them on WhatsApp, with or without a personal voucher.

== Description ==

Marketing → Customer Campaigns lists every customer with a counted order (default: Completed), with order count,
total value (net of refunds), AOV, last order and days since it.

Workflow: a customer's last counted order passes the reminder period (default 30 days) and they have no open order
(Pending payment, Processing, On hold) → they appear as Eligible → optionally Add Voucher → you click WhatsApp or
WhatsApp + Voucher → WhatsApp opens with their number and the message filled in → you press Send. Nothing is ever
sent automatically.

* Contact buttons (WhatsApp, Email) and Add Voucher appear only for customers who are Eligible or Follow-up due.
* Email: the admin sends a reminder email (with or without the voucher) from the list; the HTML template (Bangla
  by default) is edited under Settings → Email with a live preview and test sending. Every email has an unsubscribe
  link. Emails and WhatsApp share one reminder cycle.
* UTM tags on the offer link let WooCommerce Order Attribution record each order's source (whatsapp / email).
* Customers are identified by phone first (normalized WhatsApp number), then billing email, then account.
* A new counted order closes the reminder cycle: the reminder is Converted when the order came within the conversion
  window (default 30 days) or used its voucher; the next reminder is calculated from the new order.
* Contacted customers become "Follow-up due" after a configurable number of days without an order.
* Vouchers are unique WooCommerce coupons created by the admin (Add Voucher, pre-filled from the settings), one per
  customer at a time, with Revoke. Validity counts from the day the voucher is first sent (7 days sent on 5 October =
  valid until the end of 12 October); empty or 0 = no end date. Unsent vouchers are revoked when the customer orders again.
* {offer_url} is a personal link that records whether the customer opened it and applies their voucher to the cart.
  For a voucher, the page it opens shows a popup with the code (Copy code), the terms and, with items in the cart, the
  discount and new total. The popup is loaded separately for that visitor, so page caches never store it.
* "Contacted" means WhatsApp was opened with the message. The plugin cannot see delivery or read status.
* Works with HPOS and legacy order storage. Statistics are kept in the plugin's own indexed tables, built from orders
  in the background on activation and kept up to date by order hooks.
* Requires WooCommerce. Independent of Checkout Tracker.

== Changelog ==

= 1.2.0 =
* Reminder emails (Email / Email + Voucher) with an editable HTML template, live preview and unsubscribe.
* Test email box in Settings → Email: up to 5 recipients (remembered), with / without voucher, optional real customer
  data, test-safe links, 10-second pause between sends.
* UTM tags on offer links; order source (WooCommerce Order Attribution) in the Conversions tab; results per channel.
* Serial number column in the customer list.

= 1.1.0 =
* Vouchers are created by the admin (Add Voucher dialog) and sent with WhatsApp + Voucher; statuses Generated, Sent,
  Used, Expired, Revoked; Revoke.
* Voucher validity counts from the first send; empty or 0 = no end date. New placeholder {coupon_validity}.
* WhatsApp buttons and Add Voucher only for Eligible and Follow-up due customers.
* Offer popup after a voucher link is opened (cache-safe), and "voucher applied" messages for the Cart / Checkout blocks.

= 1.0.0 =
* First release.
