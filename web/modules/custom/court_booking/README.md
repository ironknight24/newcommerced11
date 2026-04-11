# Court Booking

Custom booking flow (Twig + vanilla JS) on `/book/amenities`, backed by Commerce BAT JSON availability and server-side add-to-cart validation.

## Requirements

- Drupal Commerce, Commerce Cart, Commerce BAT (lesson mode for courts).
- **Misk** theme: run Tailwind from `web/themes/custom/misk` so **court_booking** Twig and JS are scanned (`tailwind.config.js` uses `../../../modules/custom/court_booking/...` from that folder).  
  `cd web/themes/custom/misk && npm ci && npm run build`
- **Misk** loads `dist/misk.css` with **`preprocess: false`** so Drupal’s CSS aggregation does not serve a stale bundle after you rebuild Tailwind. If styles still look wrong, run `drush cr` and confirm `/themes/custom/misk/dist/misk.css` loads in the browser network tab.

## Enable

```bash
drush theme:enable misk -y
drush config:set system.theme default misk -y
drush en court_booking -y
drush cr
```

Grant **access court booking page** and **use court booking add** to roles that should book (often anonymous + authenticated). Configure mappings at **Commerce → Configuration → Court booking** (one line per sport: `TERM_ID|VARIATION_ID,VARIATION_ID`).

## Notes

- Add-to-cart forms for **lesson** BAT variations hide `field_cbat_rental_date` and link users to the booking page; products with mapped variations get a **Book a court** CTA.

## Commerce BAT profile vs Court booking settings

- **BAT availability profile** (on each variation, e.g. `field_cbat_schedule`): lesson slot length, weekly hours, allowed start times, and profile block lists. Commerce BAT uses this for availability JSON and `isAvailable()` checks.
- **`court_booking.settings`**: site-wide defaults for the **amenities** and **cart** UIs and for server validation—date strip length (`days_ahead`), local booking window (`booking_day_start` / `booking_day_end`), `max_booking_hours`, `buffer_minutes`, `same_day_cutoff_hm`, `blackout_dates`, `resource_closures`, plus `sport_mappings` and `order_type_id`.
- **Per-sport overrides**: optional rows under **Court booking** settings (vertical tabs per mapped sport) store `sport_booking_overrides` keyed by sport term ID. When enabled for a sport, those fields replace the global defaults for that sport’s variations on the booking page, cart slot editor, and validation.

## Buffer time and Commerce BAT grid

When **buffer minutes** is greater than zero:

- **Play** duration is whatever the customer selects from the duration dropdown (multiples of the lesson grid up to **Maximum booking duration**). **Pricing** uses play time only (`billing_units` = play minutes ÷ lesson slot length).
- **`field_cbat_rental_date`** and BAT blocking use the **full window**: `start` through `end` = start + play + buffer (e.g. 6:00–7:10 for 60 minutes play + 10 minutes buffer).
- Bookable **start times** with buffer use **only** the **play + buffer** cadence from **opening** through **closing** (same in PHP candidates, validation, and JS). Example: **60** min play + **10** min buffer → starts every **70** minutes from opening (**6:00**, **7:10**, **8:20**, …).
- If a sport maps variations with different BAT lesson lengths, the duration dropdown uses the **least common multiple** of those lengths as the step between options (cart editor: LCM across lesson lines in the cart).

With **buffer = 0**, the booking UI lists only starts spaced by the **selected play duration** from opening (not every BAT tile). Example: **1 hour** play → **6:00**, **7:00**, **8:00**, … when those windows are available.

The **Commerce BAT** module in this project may include a small change in `AvailabilityManager::isAvailable()` so a duration of **play + Court booking buffer** is accepted when play aligns to the lesson grid (restore that change after `composer update` if contrib is overwritten).

## Interface translation (Twig + JavaScript)

- **Twig** copy uses `|t` and appears under **Configuration → Region and language → User interface translation** (after cache clears as needed).
- **JavaScript** strings use `Drupal.t()` / `Drupal.formatPlural()` in `js/court_booking.js` and `js/cart_slot.js`. They are **not** picked up until the Locale module has run **string extraction** against the codebase (so `locales_source` is populated). Enable the core **Locale** module, then either:
  - Run Drush when available, e.g. `drush locale:check` (exact command name can differ slightly by Drupal version—use `drush list | grep locale` if unsure), or
  - Use the admin UI: **User interface translation** → run **Extract** / **Check** / **Update** (wording varies by version).
- Search the translation UI for the **exact English source** from code, including placeholders (`@time`, `@count`, etc.).
- **Weekday abbreviations** on the date strip and **Intl-based** month/time labels follow the **current interface language** via PHP `date.formatter` and `drupalSettings.courtBooking` / `courtBookingCart` keys `interfaceLangcode` and `intlLocale`.
- **Language switcher**: Booking JS reads `interfaceLangcode` / `intlLocale` from the same page request as everything else (`LanguageManagerInterface::getCurrentLanguage()`). Whatever language your switcher negotiates (Arabic, Hindi, Spanish, …) becomes the active interface language for that request—add **Interface translation** strings and **Locale** extraction for each new language you enable.
- **Numerals**: For languages that use non-Latin digits (e.g. Arabic), `intlLocale` includes a Unicode numbering extension (e.g. `-u-nu-arab`) so **Intl** formats times and prices with native numerals. Western languages (e.g. Spanish) keep Latin digits. Extend `CourtBookingRegional::intlNumberingSystemForPrimaryLanguage()` if you add a language that needs a different numbering system.
- **Product/variation titles** and taxonomy sport names are **content**; translate them with **Content translation** (or equivalent), not only Interface translation.

