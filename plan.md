# Court booking roadmap

This document summarizes the court booking enhancements: per-court detail pages, Commerce catalog structure (one product per sport), BAT event visibility, and operational notes.

## 1. Court detail pages

- Route: `/book/court/{commerce_product_variation}` (variation must appear in Court booking settings).
- Cards on `/book/amenities` link to the detail page; **Book** still adds the selected slot to the cart.
- **Book this court** on the detail page links to `/book/amenities?sport=TERM_ID&variation=VARIATION_ID` to pre-select sport (and highlight the court when slots load).

## 2. Product per sport

- Map each sport taxonomy term to a **Commerce product** with `TERM_ID|p:PRODUCT_ID` (the `p:` prefix avoids clashes with variation IDs).
- Alternatively use explicit variations: `TERM_ID|VID,VID`.

Configure at **Administration » Commerce » Configuration » Court booking**.

## 3. BAT events after checkout

- BAT blocking events are created when the order **places** (workflow transition `place` → **Completed**), not when the line item is added to the cart.
- Lesson bookings use `bat_event` bundle **`lesson_event`** (see Commerce BAT config). Filter the BAT Events list accordingly.
- If events are missing but the order shows dates on the line item, run:  
  `drush court-booking:sync-bat-order ORDER_ID`
- `commerce_bat` **OrderPlaceSubscriber** was hardened so a duplicate-key edge case no longer calls `->id()` on a non-entity value during audit logging.
- **court_booking** registers **OrderPlaceBatSyncSubscriber** (runs after Commerce BAT on `commerce_order.place.post_transition`) to reload the order and call `commerce_bat_sync_order_events()` so BAT events match line-item dates even if the first pass missed them. **BookingAddController** ensures a BAT unit exists before add-to-cart and rejects variations with no Commerce BAT **lesson** mode (misconfiguration that previously allowed paid orders without events).

## Manual QA checklist

1. **Mapping:** Set `TERM|PRODUCT_ID` for each sport; confirm all court variations appear on `/book/amenities`.
2. **Detail:** Open a court from the amenities page; confirm title, price, image, and optional unit/taxonomy info; use **Book this court** and confirm query params pre-select the sport.
3. **Checkout → BAT:** Complete a real checkout (order state **Completed**); confirm a new **lesson_event** exists and ties to the order/variation.
4. **Recovery:** On a completed order with dates but no event, run `drush court-booking:sync-bat-order {id}` and confirm the event appears.

## Staggered buffer timeslots (spec)

This project uses the **staggered buffer** model (court_booking).

- **Buffer > 0:** Billable **play** duration is unchanged. **Storage and display** use `[start, end)` where `end = start + play + buffer`. **`isAvailability`** / cart POST use that same `end`. **First start** of the day aligns to **opening**; subsequent starts add **play + buffer** each time.
- **Buffer = 0:** No buffer in the blocked window; slots follow the BAT grid up to **booking_day_end** (e.g. late hourly starts when the grid is 60 minutes and closing allows).
- **Billing:** `billing_units` = play minutes ÷ `lesson_slot_length` (buffer is never billed).
- **BAT:** Staggered clock times require a BAT lesson grid that **divides** `(play + buffer)` in minutes; see module **README** and Court booking **Settings** buffer field help.

**QA:** With buffer 10 and BAT 10, confirm slots such as 6:00–7:10 and 7:10–8:20, add-to-cart, cart edit, and BAT event span. With buffer 0 and closing 23:30, confirm late starts (e.g. 22:00) when duration fits. Re-check same-day cutoff, blackout dates, and resource closures still block bookings.
