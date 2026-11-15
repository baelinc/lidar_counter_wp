# Light Show Car Counter (WordPress plugin)

Receives nightly car counts from the **FPP Car Counter** plugin
([baelinc/lidar_counter_fpp](https://github.com/baelinc/lidar_counter_fpp)) and shows them on your site with shortcodes,
charts and an admin dashboard.

## Install
1. Download this repo as a ZIP (**Code → Download ZIP**) and upload it at *Plugins → Add New → Upload Plugin*, then activate.
   (GitHub names the folder `lidar_counter_wp-main`; that works fine.)
2. Open **Car Counter → Connection & Settings** and copy the website address and shared secret into the FPP plugin's *Website Sync* tab.
3. Put a shortcode on any page, e.g. `We've welcomed [car_counter] cars!`

## Shortcodes
| Shortcode | Shows |
|---|---|
| `[car_counter]` | cars so far. Other values: `stat="tonight"`, `last_night`, `best_night`, `best_night_date`, `average`, `nights`, `busiest_hour`, `updated`, `date` (with `date="2026-12-24"`). Options: `label`, `prefix`, `suffix`, `format="raw"`, `live="true"`, `style="card"` |
| `[car_counter_stats]` | designed cards. Choose with `cards="tonight,last_night,busiest_hour"`; `hero="true"` (big first card; default only for the full set), `title="…"` |
| `[car_counter_chart]` | bar chart of recent nights; `days="30" type="line" height="260"` |
| `[car_counter_chart view="hourly"]` | busiest hours of the evening |
| `[car_counter_table limit="30" order="asc"]` | table of nights |

Every shortcode accepts `since="YYYY-MM-DD"` and `until="YYYY-MM-DD"`. See `readme.txt` for the REST API and FAQ.

Requires WordPress 5.3+ and PHP 7.2+. License: GPL-2.0-or-later.

## Deleting data
Under **Car Counter → Nights** you can delete a single night, tick several and delete them, delete a date range, or **Reset all data** (type `RESET`). **Settings → Upload log** lets you delete the nights a given upload brought in.
Because the Pi re-sends its recent history with each upload, deleted nights are kept on an *ignored* list so they don't reappear (restore any time). A reset lets you choose whether to accept tonight, start tomorrow, or accept everything again. Settings and the shared secret are never touched.

## Updates
The plugin checks this repository for a newer `Version:` in `light-show-car-counter.php` and shows an **Update now** button (banner on the plugin pages and the normal Plugins-page notice). **Settings → Plugin updates** has *Check for updates now*. To publish an update, bump the version and push to `main`.

## Cards
`[car_counter_stats]` shows designed cards: a large **Cars so far** card with a trend line, **Tonight** with a progress bar against your best night (and a Live dot while the Pi is uploading), **Last night** with an up/down badge versus the average, plus best night, average and busiest hour. Options: `cards="total,tonight,last_night,best_night,average,busiest_hour,nights"`, `hero="true"` adds the big first card when you pick your own list. Cards only count nights inside the Season dates in Settings; admins see a note when saved nights fall outside them.
