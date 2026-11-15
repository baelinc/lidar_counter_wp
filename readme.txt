=== Light Show Car Counter ===
Tags: christmas lights, counter, statistics, shortcode, falcon player
Requires at least: 5.3
Requires PHP: 7.2
Stable tag: 1.4.0
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
Single numbers – `[car_counter stat="..."]`:
* `[car_counter]` – cars so far (default)
* `[car_counter stat="tonight"]` – cars tonight
* `[car_counter stat="last_night"]` – cars last night
* `[car_counter stat="best_night"]` and `stat="best_night_date"` – best night's cars / date
* `[car_counter stat="average"]` – average per night
* `[car_counter stat="nights"]` – nights open
* `[car_counter stat="busiest_hour"]` – busiest hour of the evening
* `[car_counter stat="updated"]` – time of the last upload
* `[car_counter stat="date" date="2026-12-24"]` – one specific night
* `[car_counter stat="people"]`, `people_tonight`, `people_last_night`, `people_peak` (biggest crowd), `people_per_car` – estimated people from a BaldrickSignals board (only meaningful if you use one)
Options: `label`, `prefix`, `suffix`, `format="raw"`, `live="true"`, `style="card"` (show the number as a designed card) (refreshes total / tonight / last_night / nights every minute).

Cards – `[car_counter_stats]` shows the full set. Choose cards with `cards="tonight,last_night,busiest_hour"` (total, tonight, last_night, best_night, average, nights, busiest_hour, updated, people, people_tonight, people_last_night, people_peak, people_per_car). When people data exists the default set also shows People tonight and People so far. the big "Cars so far" card is used for the full set only – with your own `cards=` list you get an even grid (add `hero="true"` for the big card); `title="By the numbers"` adds a heading.

Charts – `[car_counter_chart]` (bar, last 14 nights), `[car_counter_chart days="30" type="line" height="260" title=""]`, `[car_counter_chart view="hourly"]`, `[car_counter_chart metric="people"]` (estimated people per night).

Table – `[car_counter_table]`, `[car_counter_table limit="30" order="asc"]`.

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
= Updates =
The plugin checks its GitHub repository (baelinc/lidar_counter_wp) for a newer version. When one exists you see a banner with an Update now button and the usual update notice on the Plugins page. Settings → Plugin updates has a "Check for updates now" button. To release an update, raise the Version in light-show-car-counter.php and push to main.
= Deleting data =
Car Counter → Nights: delete one night, tick several and "Delete selected", or delete a date range. Settings → Upload log: remove a log entry or delete the nights a specific upload brought in. "Reset all data" (type RESET) wipes every night and the log but keeps your settings and secret.
Your Pi re-sends its last few nights on every upload, so deleted nights are remembered as "ignored" and skipped (restore them any time). After a reset you choose: accept tonight and ignore older, start tomorrow, or accept everything again.
= Removing the plugin =
Data is kept unless you tick "On uninstall" in Settings.

== Changelog ==
= 1.4.0 =
* People (estimated) from a BaldrickSignals board connected to the FPP plugin: stored separately from cars, with new stats (people, people_tonight, people_last_night, people_peak, people_per_car), people cards, a People column in tables, and metric="people" for charts.
= 1.3.2 =
* Custom card lists (cards="...") now get an even grid, not the big hero card, so older shortcodes look right. New style="card" for single-number shortcodes.
= 1.3.1 =
* Settings page now has a complete, grouped shortcode list with Copy buttons.
= 1.3.0 =
* New card design: icons, a large "Cars so far" hero card with a trend line, tonight progress bar vs your best night, up/down vs average badge, live indicator, count-up animation. Works on dark and light themes.
* Warns admins when saved nights fall outside the Season dates (the usual reason cards show 0).
= 1.2.0 =
* Built-in updater: checks GitHub, shows an "Update now" banner and the normal WordPress update link, plus a "Check for updates now" button in Settings.
= 1.1.0 =
* Delete a night, several nights, a date range, or a whole upload; reset all data; ignored-nights list with restore; upload log management.
= 1.0.0 =
* First release.
