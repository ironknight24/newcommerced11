<?php

namespace Drupal\court_booking;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Regional settings from system.date (Administration » Configuration » Region and language).
 *
 * Mirrors core regional form: default timezone, optional per-user timezone, default country,
 * first day of week.
 */
final class CourtBookingRegional {

  /**
   * Timezone for displaying and interpreting local booking times.
   *
   * Order: user timezone (if configurable and set), site default timezone, PHP default, UTC.
   */
  public static function effectiveTimeZoneId(?ConfigFactoryInterface $config_factory = NULL, ?AccountInterface $account = NULL): string {
    $config_factory = $config_factory ?? \Drupal::configFactory();
    $account = $account ?? \Drupal::currentUser();
    $date = $config_factory->get('system.date');
    $candidates = [];

    if ($date->get('timezone.user.configurable')) {
      $user_tz = trim((string) $account->getTimeZone());
      if ($user_tz !== '') {
        $candidates[] = $user_tz;
      }
    }

    $site_default = trim((string) ($date->get('timezone.default') ?: ''));
    if ($site_default !== '') {
      $candidates[] = $site_default;
    }

    $runtime = trim((string) date_default_timezone_get());
    if ($runtime !== '' && !in_array($runtime, $candidates, TRUE)) {
      $candidates[] = $runtime;
    }

    foreach ($candidates as $name) {
      try {
        return (new \DateTimeZone($name))->getName();
      }
      catch (\Throwable $e) {
      }
    }

    return 'UTC';
  }

  /**
   * Default country code from regional settings (e.g. IN for India).
   */
  public static function defaultCountryCode(?ConfigFactoryInterface $config_factory = NULL): string {
    $config_factory = $config_factory ?? \Drupal::configFactory();
    $code = trim((string) ($config_factory->get('system.date')->get('country.default') ?: ''));

    return strtoupper($code);
  }

  /**
   * First day of week: 0 = Sunday … 6 = Saturday (system.date:first_day).
   */
  public static function firstDayOfWeek(?ConfigFactoryInterface $config_factory = NULL): int {
    $config_factory = $config_factory ?? \Drupal::configFactory();
    $day = $config_factory->get('system.date')->get('first_day');
    if (!is_numeric($day)) {
      return 0;
    }

    return max(0, min(6, (int) $day));
  }

}
