=== Light Show Car Counter ===
Tags: christmas lights, counter, statistics, shortcode, falcon player
Requires at least: 5.3
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later

Receives nightly car counts from the FPP "Car Counter" plugin (Falcon Player) and shows them on your site with shortcodes and charts.

== Description ==
Your Raspberry Pi counts cars with a lidar sensor and uploads the totals every night. This plugin stores them and gives you:

* Shortcodes for totals, tonight, last night, best night, averages, busiest hour, charts and tables
* An admin screen listing every night, with editing (and locking so the Pi can't overwrite a correction), delete (single, bulk, date range), reset-all and CSV export
* A dashboard widget and a warning if uploads stop arriving
* Optional live numbers for tonight's count on your page
* Seasons: ignore nights before/after chosen dates, add a starting offset

Uploads are authenticated with an HMAC‑SHA256 signature of the request using a shared secret, with a timestamp to stop replays. Charts are server‑rendered SVG (no JavaScript, no external services, works with page caches).

== Installation ==
1. Upload the `light-show-car-counter` folder to `/wp-content/plugins/` (or upload the zip via Plugins → Add New) and activate.
2. Open **Car Counter → Connection & Settings**. Copy the website address and shared secret.
3. In FPP: Status/Control → Car Counter → Website Sync – paste both, press *Test connection*, tick *Upload to website*.
4. Add a shortcode to any page, e.g. `We've welcomed [car_counter] cars!`

== Shortcodes ==
`[car_counter]` – season total as a plain number. Attributes:
* `stat` – total (default), tonight, last_night, best_night, best_night_date, average, nights, busiest_hour, updated, date (with `date="2026-12-24"`)
* `label`, `prefix`, `suffix` – text around the number; `format="raw"` removes thousands separators
* `live="true"` – refresh total / tonight / last_night / nights every minute on the visitor's screen
* `since`, `until` – YYYY-MM-DD, override the season dates

`[car_counter_stats cards="total,nights,average,best_night" title="By the numbers"]` – grid of cards (any stat except `date`).

`[car_counter_chart days="14" type="bar|line" height="260" title=""]` – cars per night.
`[car_counter_chart view="hourly"]` – which hours of the evening are busiest.

`[car_counter_table limit="14" order="desc"]` – table of nights.

All shortcodes accept `since` and `until`. Change the chart colour under Settings, or override CSS variable `--lscc-accent`.

== REST API ==
* `POST /wp-json/lscc/v1/ingest` and `/ping` – signed requests from FPP
* `GET /wp-json/lscc/v1/public` – totals only (used by `live="true"`; can be disabled)

Headers: `X-LSCC-Timestamp` (unix seconds) and `X-LSCC-Signature` = hex HMAC‑SHA256(secret, timestamp + "." + raw body). Requests more than 15 minutes off server time are rejected (filter `lscc_max_clock_skew`).

== Frequently Asked Questions ==
= Uploads are rejected with 401 =
The secret doesn't match (regenerating it invalidates the old one) or the Pi's clock is wrong by more than 15 minutes.
= Uploads return 404 / "rest_no_route" =
Some security plugins or hosts disable the REST API for anonymous users. Allow `/lscc/v1/` (the FPP plugin also tries `?rest_route=`).
= I corrected a night but the Pi overwrote it =
Edit it under Car Counter → Nights and keep "Lock" ticked.
= Deleting data =
Car Counter → Nights: delete one night, tick several and "Delete selected", or delete a date range. Settings → Upload log: remove a log entry or delete the nights a specific upload brought in. "Reset all data" (type RESET) wipes every night and the log but keeps your settings and secret.
Your Pi re-sends its last few nights on every upload, so deleted nights are remembered as "ignored" and skipped (restore them any time). After a reset you choose: accept tonight and ignore older, start tomorrow, or accept everything again.
= Removing the plugin =
Data is kept unless you tick "On uninstall" in Settings.

== Changelog ==
= 1.1.0 =
* Delete a night, several nights, a date range, or a whole upload; reset all data; ignored-nights list with restore; upload log management.
= 1.0.0 =
* First release.
