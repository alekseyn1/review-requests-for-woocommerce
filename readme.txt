=== Review Requests for WooCommerce ===
Contributors: relit
Author URI: https://relit.ca
Tags: woocommerce, reviews, google, email, reputation
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
WC requires at least: 8.0
WC tested up to: 11.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Asks customers for a Google review once their order is complete, with per-order control,
automatic follow-ups and one-click unsubscribe.

== Description ==

Sends a review request a configurable number of days after an order is marked complete —
timed from the completion date, not from when the order was enrolled, so adding an older
order by hand does not restart the clock.

* Per-order box on the order screen: current state, send history, an include/exclude
  checkbox and a Send now button
* Column and bulk include/exclude actions on the Orders list
* Automatic enrolment on completion, or fully manual selection
* Configurable reminders, spacing, daily cap and a minimum order total
* One-click unsubscribe with RFC 8058 headers, and a suppression list keyed on the email
  address so an opt-out outlives the order it was clicked from
* Click-through and self-reported "I left a review" tracking
* Request log with state filters on the settings screen
* Built on Action Scheduler; HPOS-safe

= What this plugin will not do =

It will not gate reviews. There is no setting to ask only happy customers, and there will
not be one — soliciting selectively, or screening by rating before deciding whether to
ask, violates Google's policies and risks the listing. Every eligible order gets the same
request.

= Consent =

Sending rests on the existing business relationship with a past customer, not on a consent
checkbox. A tick bundled into terms and conditions is not valid consent under GDPR anyway.

Two things the plugin cannot do for you:

* Add an opt-out notice at checkout. CASL and ePrivacy soft opt-in both expect the
  opportunity to refuse at the point the address is collected.
* Supply your postal address, which is legally required in the footer of a commercial
  email. The settings screen warns until it is filled in.

The consent window is capped at 730 days and cannot be raised. Implied consent from a
purchase expires two years after the transaction under Canada's CASL, the strictest of the
common regimes, so honouring it keeps you safe under CAN-SPAM and ePrivacy too.

== Installation ==

1. Install and activate.
2. WooCommerce > Settings > Emails > "Review request" — enable it there. The plugin's own
   master switch does not override this.
3. WooCommerce > Review Requests — set your Google Place ID and postal address.
4. Leave "Dry run" on and complete a test order. The order notes record what would have
   been sent.
5. Turn dry run off when the wording and timing look right.

Find your Place ID with Google's Place ID Finder. Customers are sent to
`search.google.com/local/writereview?placeid=...`, which is the most durable of the
review-link forms.

== Customising the email ==

No code needed. WooCommerce > Review Requests has an **Email wording** section with the
body of the first request and of the reminder. Blank lines start new paragraphs, and
{customer_name}, {order_number} and {site_title} are substituted. Leave a field blank to
use the wording the plugin ships with.

Subject and heading live where WooCommerce keeps them: Settings > Emails > Review request.

= Different wording for different products =

**Wording by product category**, at the bottom of the same screen, takes any number of
rules. Each is a product category plus its own subject, first-request body and reminder
body. Orders containing anything from that category (child categories included) get that
version. Any field left blank falls back to the wording above.

Rules are checked top to bottom and **the first match wins**, so if a product could sit in
two categories, put the more specific rule first.

Typical use: a shop selling one headline product alongside accessories. "Hope you have had
a chance to fire it up" reads well for a barbecue and oddly for a set of tongs.

The check is category-based, never name-based — a product called "Barbecue Cover" is an
accessory, not a barbecue, and only the category knows that.

= Template overrides =

For layout changes rather than wording, copy either template into your theme:

* `yourtheme/woocommerce/emails/customer-review-request.php`
* `yourtheme/woocommerce/emails/plain/customer-review-request.php`

Templates receive `$order`, `$body_text` (already resolved, variant included), `$item_rows`,
`$attempt`, the three signed URLs and `$email`. For conditions beyond a single category,
`$email->order_has_product_in_category( $order, 'slug' )` is public, and
`woocommerce_email_subject_rrfw_review_request` filters the subject.

The order-screen panel suggesting a possibly matching review reads WP Review Slider Pro's
table when that plugin is present. Point it elsewhere with `rrfw_review_lookup_table`, or
return an empty string to hide it.

== Frequently Asked Questions ==

= Can it tell whether a customer actually left a review? =

No, and neither can anything else. Google exposes no reviewer email address and abbreviates
surnames, so a review cannot be reliably matched to an order. The plugin tracks what it can
know first-hand: who was asked, who clicked through, and who said they had left one.

= Why did nothing send immediately after I enabled it? =

Enrolment happens when an order is marked complete, and the first request goes out after
the configured delay. Existing completed orders are not enrolled retroactively.

= Where do I see what it has done? =

WooCommerce > Review Requests lists every request with state filters, plus the unsubscribe
list. Every send, skip and hold also writes an order note.

== Data stored ==

Order meta prefixed `_rrfw_`, a `{prefix}rrfw_suppressions` table, and the options
`rrfw_settings`, `rrfw_secret`, `rrfw_db_version`, `rrfw_daily_counter`.

`rrfw_secret` signs the unsubscribe and click links. Do not regenerate it: links in emails
already delivered are HMACs over that key, and replacing it breaks every outstanding
unsubscribe link.

Uninstalling keeps all of it unless `RRFW_REMOVE_ALL_DATA` is defined as true. Suppression
records are preserved deliberately — dropping them would mean re-emailing people who asked
not to be contacted.

== Changelog ==

= 1.0.0 =
* First release.
