<?php

namespace Drupal\court_booking;

/**
 * Resolves the effective timezone from regional settings (system.date + user rules).
 */
trait BookingTimezoneTrait {

  /**
   * Effective IANA timezone for the current request (Kolkata, etc.).
   */
  protected function displayTimeZoneId(): string {
    // ControllerBase has no configFactory() method; use the global factory.
    return CourtBookingRegional::effectiveTimeZoneId(\Drupal::configFactory(), \Drupal::currentUser());
  }

}
