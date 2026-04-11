<?php

/**
 * @file
 * Registers JavaScript Drupal.t() / formatPlural() sources with Locale storage.
 */

use Drupal\Component\Gettext\PoItem;

/**
 * Inserts JS translatable sources into locales_source so they appear in the UI.
 *
 * Mirrors strings from js/court_booking.js and js/cart_slot.js (same literals as
 * core's _locale_parse_js_file() would extract after scanning).
 */
function _court_booking_register_js_interface_strings(): void {
  if (!\Drupal::moduleHandler()->moduleExists('locale')) {
    return;
  }
  $storage = \Drupal::service('locale.storage');
  $base = \Drupal::service('extension.list.module')->getPath('court_booking');
  $court = $base . '/js/court_booking.js';
  $cart = $base . '/js/cart_slot.js';

  $p_min = '1 minute' . PoItem::DELIMITER . '@count minutes';
  $p_hour = '1 hour' . PoItem::DELIMITER . '@count hours';

  $by_file = [
    $court => [
      $p_min,
      $p_hour,
      '@h h @m min',
      'Price is for play time only; the listed time includes @n min buffer.',
      'Bookings are closed on this date.',
      'No bookable dates in the current range.',
      'No dates in this month. Use the arrows or calendar.',
      'All courts are temporarily closed on this date.',
      'Loading availability…',
      'Could not load availability. Please refresh.',
      'No time slots for this day.',
      'Same-day booking is closed after @time.',
      'No open times match your opening time, buffer spacing on the lesson grid, or booking window. Try another date or adjust booking hours.',
      'No slots for this duration within your booking hours.',
      'Fully booked or past time.',
      'No pitches to show for this sport on the selected date.',
      'View details',
      'Book',
      'No courts available for this time and date (temporary closure or no availability).',
      'Booking failed.',
      'Network error. Try again.',
      'Book @title',
    ],
    $cart => [
      $p_min,
      $p_hour,
      '@h h @m min',
      'Change date and time',
      'Close',
      'Prev',
      'Next',
      'Duration',
      'Cancel',
      'Update',
      '1 Hour',
      '@n Hours',
      'This court is temporarily closed on this date.',
      'This court is temporarily closed: @reason',
      'Loading availability…',
      'Could not load availability. Please refresh.',
      'No time slots for this day.',
      'Same-day booking is closed after @time.',
      'No open times match your opening time, buffer spacing on the lesson grid, or booking window. Try another date or adjust booking hours.',
      'No slots for this duration within your booking hours.',
      'Could not update booking.',
      'Network error. Try again.',
      'No bookable dates in range.',
      'Bookings are closed on this date.',
    ],
  ];

  foreach ($by_file as $js_path => $sources) {
    foreach ($sources as $source) {
      $match = [
        'source' => $source,
        'context' => '',
      ];
      $string = $storage->findString($match);
      if (!$string) {
        $string = $storage->createString($match);
      }
      $string->addLocation('javascript', $js_path);
      $string->save();
    }
  }

  \Drupal::service('string_translation')->reset();
}
