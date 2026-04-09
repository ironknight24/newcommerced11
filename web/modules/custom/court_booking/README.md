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

## Buffer time and Commerce BAT grid

When **buffer minutes** is greater than zero:

- The **play** duration is still the selected length (e.g. one hour). **Pricing** uses play time only (`billing_units` = play minutes ÷ lesson slot length).
- **`field_cbat_rental_date`** and BAT blocking use the **full window**: `start` through `end` = start + play + buffer (e.g. 6:00–7:10 for 60 minutes play + 10 minutes buffer).
- Bookable **start times** are staggered from **opening**: opening, then opening + (play + buffer), then +2×(play + buffer), etc. Multi-hour play uses the same **step** = play + buffer.
- The availability UI uses consecutive BAT slots for that **full** window; **(play + buffer)** must be an exact multiple of Commerce BAT **Lesson slot length** for each variation.

**Hourly lesson slots + non-zero buffer:** Play time stays a multiple of the BAT lesson length (e.g. 60 minutes). The booked window stored on the line item is **play + buffer** (e.g. 70 minutes) even though the availability API still uses full lesson slots (e.g. two consecutive hours) to confirm the court is free. Start times are spaced on the lesson grid by **ceil((play + buffer) / slot length) × slot length** minutes from opening (e.g. every **two hours** for 60 + 10 on an hourly grid: 6:00, 8:00, …). The **Commerce BAT** module in this project includes a small change in `AvailabilityManager::isAvailable()` so a duration of **play + Court booking buffer** is accepted when play aligns to the lesson grid (restore that change after `composer update` if contrib is overwritten).

With **buffer = 0**, behavior matches the BAT grid through closing (dense hourly starts when the grid is hourly).
