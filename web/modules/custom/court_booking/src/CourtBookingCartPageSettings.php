<?php

namespace Drupal\court_booking;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Builds drupalSettings + cache metadata for the cart slot editor.
 */
final class CourtBookingCartPageSettings {

  /**
   * @return array{settings: array<string, mixed>, cache_tags: string[], cache_contexts: string[]}|null
   */
  public static function build(
    AccountInterface $account,
    CartProviderInterface $cart_provider,
    AvailabilityManagerInterface $availability_manager,
    CurrencyFormatterInterface $currency_formatter,
    FileUrlGeneratorInterface $file_url_generator,
    ConfigFactoryInterface $config_factory,
    CourtBookingSportSettings $sport_settings,
    LanguageManagerInterface $language_manager,
  ): ?array {
    $langcode = $language_manager->getCurrentLanguage()->getId();
    $carts = $cart_provider->getCarts($account);
    $variations_out = [];
    $cache_tags = [];
    foreach ($carts as $order) {
      foreach ($order->getItems() as $item) {
        $purchased = $item->getPurchasedEntity();
        if (!$purchased instanceof ProductVariationInterface) {
          continue;
        }
        if ($availability_manager->getModeForVariation($purchased) !== 'lesson') {
          continue;
        }
        if (!$item->hasField('field_cbat_rental_date') || $item->get('field_cbat_rental_date')->isEmpty()) {
          continue;
        }
        $vid = (int) $purchased->id();
        if (isset($variations_out[$vid])) {
          continue;
        }
        $price = '';
        $price_amount = '';
        $price_currency = '';
        $p = $purchased->getPrice();
        if ($p) {
          $price = $currency_formatter->format($p->getNumber(), $p->getCurrencyCode());
          $price_amount = $p->getNumber();
          $price_currency = $p->getCurrencyCode();
        }
        $thumb = CourtBookingVariationThumbnail::data($purchased, $file_url_generator);
        $cache_tags = array_merge($cache_tags, $thumb['cache_tags'], $purchased->getCacheTags());
        $slot_len = max(1, (int) $availability_manager->getLessonSlotLength($purchased));
        $merged = $sport_settings->getMergedForVariation($purchased);
        $variations_out[$vid] = [
          'id' => $vid,
          'title' => $purchased->getTitle(),
          'price' => $price,
          'priceAmount' => $price_amount,
          'priceCurrencyCode' => $price_currency,
          'image' => $thumb['url'],
          'slotMinutes' => $slot_len,
          'detailUrl' => \Drupal\Core\Url::fromRoute('court_booking.court_detail', [
            'commerce_product_variation' => $vid,
          ])->setAbsolute()->toString(),
          'booking' => $sport_settings->bookingRulesForJs($merged, CourtBookingRegional::effectiveTimeZoneId($config_factory, $account), $langcode),
        ];
      }
    }

    if (!$variations_out) {
      return NULL;
    }

    $cb_config = $config_factory->get('court_booking.settings');
    $commerce_bat = $config_factory->get('commerce_bat.settings');
    $slot_minutes = (int) ($commerce_bat->get('lesson_slot_length_minutes') ?: 60);
    $site_tz = CourtBookingRegional::effectiveTimeZoneId($config_factory, $account);
    $interval = 'PT' . max(1, $slot_minutes) . 'M';
    $csrf_token = \Drupal::csrfToken()->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    $dates_by_ymd = [];
    foreach ($variations_out as $row) {
      $bdates = $row['booking']['dates'] ?? [];
      if (!is_array($bdates)) {
        continue;
      }
      foreach ($bdates as $d) {
        if (!empty($d['ymd'])) {
          $dates_by_ymd[(string) $d['ymd']] = $d;
        }
      }
    }
    ksort($dates_by_ymd);
    $dates_bootstrap = array_values($dates_by_ymd);

    $global_js = $sport_settings->bookingRulesForJs($sport_settings->getGlobalBookingRules(), $site_tz, $langcode);

    $cart_slot_lens = array_map(static fn (array $v): int => max(1, (int) ($v['slotMinutes'] ?? 60)), array_values($variations_out));
    $duration_grid_minutes = CourtBookingPlayDurationGrid::lcmMany($cart_slot_lens);
    $max_hours_cap = 24;
    foreach ($variations_out as $row) {
      $mh = (int) ($row['booking']['maxBookingHours'] ?? 4);
      $max_hours_cap = min($max_hours_cap, max(1, min(24, $mh)));
    }

    $cache_tags = array_values(array_unique(array_merge(
      $cache_tags,
      $cb_config->getCacheTags(),
      $commerce_bat->getCacheTags(),
      $config_factory->get('system.date')->getCacheTags(),
    )));

    $settings = [
      'variations' => array_values($variations_out),
      'variationsById' => $variations_out,
      'slotInterval' => $interval,
      'slotMinutes' => max(1, $slot_minutes),
      'timezone' => $site_tz,
      'interfaceLangcode' => $langcode,
      'intlLocale' => CourtBookingRegional::intlLocaleForLangcode($langcode),
      'countryCode' => CourtBookingRegional::defaultCountryCode($config_factory),
      'firstDayOfWeek' => CourtBookingRegional::firstDayOfWeek($config_factory),
      'bookingDayStart' => $global_js['bookingDayStart'],
      'bookingDayEnd' => $global_js['bookingDayEnd'],
      'bufferMinutes' => $global_js['bufferMinutes'],
      'sameDayCutoffHm' => $global_js['sameDayCutoffHm'],
      'blackoutDates' => $global_js['blackoutDates'],
      'resourceClosuresByVariation' => $global_js['resourceClosuresByVariation'],
      'dates' => $dates_bootstrap,
      'csrfToken' => $csrf_token,
      'slotCandidatesUrl' => \Drupal\Core\Url::fromRoute('court_booking.slot_candidates')->toString(),
      'maxBookingHours' => $max_hours_cap,
      'durationGridMinutes' => $duration_grid_minutes,
    ];

    return [
      'settings' => $settings,
      'cache_tags' => $cache_tags,
      'cache_contexts' => ['languages:language_interface', 'session', 'user', 'timezone'],
    ];
  }

}
