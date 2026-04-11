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

  /**
   * BCP 47 locale tag for JavaScript Intl (aligns with the interface language).
   *
   * Appends a Unicode numbering-system extension (`-u-nu-*`) when that language
   * typically uses non-Latin digits, so Intl formats times and numbers natively.
   * Languages like Spanish or French keep default (Latin) digits.
   *
   * @param string $langcode
   *   Drupal language ID (e.g. en, ar, hi, es). Matches whatever the active
   *   language negotiation provides (e.g. language switcher / URL prefix).
   *
   * @return string
   *   A locale string suitable as the first argument to Intl.DateTimeFormat.
   */
  public static function intlLocaleForLangcode(string $langcode): string {
    $trimmed = trim($langcode);
    $lc = strtolower($trimmed);
    if ($lc === '' || in_array($lc, ['und', 'zxx', 'not_applicable'], TRUE)) {
      return 'en';
    }
    if (str_contains($lc, '-u-nu-')) {
      return $langcode;
    }

    $primary = strtolower(explode('-', $lc, 2)[0]);
    $numbering = self::intlNumberingSystemForPrimaryLanguage($primary);
    if ($numbering === NULL) {
      return $langcode;
    }

    return $langcode . '-u-nu-' . $numbering;
  }

  /**
   * ICU numbering system for Intl (see unicode.org reports tr35).
   *
   * @return string|null
   *   A numbering system key, or NULL to let Intl use the locale default
   *   (usually Latin digits for Western European languages).
   */
  private static function intlNumberingSystemForPrimaryLanguage(string $primary): ?string {
    return match ($primary) {
      // Arabic script locales: Eastern Arabic-Indic digits.
      'ar', 'ckb', 'dv', 'ps' => 'arab',
      // Persian / Dari: Persian digits.
      'fa' => 'arabext',
      // South Asian (examples; extend as you add languages).
      'hi', 'mr', 'ne' => 'deva',
      'bn', 'as' => 'beng',
      'ta' => 'tamldec',
      'te' => 'telu',
      'kn' => 'knda',
      'ml' => 'mlym',
      'gu' => 'gujr',
      'pa' => 'guru',
      'or' => 'orya',
      'my' => 'mymr',
      'km' => 'khmr',
      'lo' => 'laoo',
      'th' => 'thai',
      default => NULL,
    };
  }

}
