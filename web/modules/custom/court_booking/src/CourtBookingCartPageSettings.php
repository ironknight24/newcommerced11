<?php

namespace Drupal\court_booking;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
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
  ): ?array {
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
        ];
      }
    }

    if (!$variations_out) {
      return NULL;
    }

    $cb_config = $config_factory->get('court_booking.settings');
    $commerce_bat = $config_factory->get('commerce_bat.settings');
    $days_ahead = (int) ($cb_config->get('days_ahead') ?: 60);
    $excluded = $cb_config->get('excluded_weekdays') ?: [];
    $slot_minutes = (int) ($commerce_bat->get('lesson_slot_length_minutes') ?: 60);
    $site_tz = CourtBookingRegional::effectiveTimeZoneId($config_factory, $account);
    $booking_day_start = $cb_config->get('booking_day_start') ?: '06:00';
    $booking_day_end = $cb_config->get('booking_day_end') ?: '23:00';
    $max_booking_hours = max(1, min(24, (int) ($cb_config->get('max_booking_hours') ?: 4)));
    $buffer_minutes = max(0, min(180, (int) ($cb_config->get('buffer_minutes') ?? 0)));
    $same_day_cutoff_hm = trim((string) ($cb_config->get('same_day_cutoff_hm') ?? ''));
    $blackout_dates = array_values(array_unique(array_filter(array_map('strval', (array) ($cb_config->get('blackout_dates') ?? [])))));
    $resource_closures = (array) ($cb_config->get('resource_closures') ?? []);
    $resource_closures_by_variation = [];
    foreach ($resource_closures as $row) {
      $vid = (int) ($row['variation_id'] ?? 0);
      $start_date = trim((string) ($row['start_date'] ?? ''));
      $end_date = trim((string) ($row['end_date'] ?? ''));
      if ($vid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || $end_date < $start_date) {
        continue;
      }
      $resource_closures_by_variation[(string) $vid][] = [
        'startDate' => $start_date,
        'endDate' => $end_date,
        'reason' => trim((string) ($row['reason'] ?? '')),
      ];
    }
    $interval = 'PT' . max(1, $slot_minutes) . 'M';
    $csrf_token = \Drupal::csrfToken()->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    $dates_bootstrap = [];
    try {
      $tz = new \DateTimeZone($site_tz);
      $excluded_w = array_map('intval', (array) $excluded);
      $start = new \DateTimeImmutable('today', $tz);
      for ($i = 0; $i < $days_ahead; $i++) {
        $day_local = $start->modify('+' . $i . ' days');
        $wday = (int) $day_local->format('w');
        if (in_array($wday, $excluded_w, TRUE)) {
          continue;
        }
        $day_start_utc = $day_local->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $day_end_utc = $day_local->modify('+1 day')->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $dates_bootstrap[] = [
          'ymd' => $day_local->format('Y-m-d'),
          'dayNum' => $day_local->format('j'),
          'weekday' => $day_local->format('D'),
          'from' => $day_start_utc->format('Y-m-d\TH:i:s\Z'),
          'to' => $day_end_utc->format('Y-m-d\TH:i:s\Z'),
        ];
      }
    }
    catch (\Throwable $e) {
      $dates_bootstrap = [];
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
      'countryCode' => CourtBookingRegional::defaultCountryCode($config_factory),
      'firstDayOfWeek' => CourtBookingRegional::firstDayOfWeek($config_factory),
      'bookingDayStart' => $booking_day_start,
      'bookingDayEnd' => $booking_day_end,
      'bufferMinutes' => $buffer_minutes,
      'sameDayCutoffHm' => $same_day_cutoff_hm,
      'blackoutDates' => $blackout_dates,
      'resourceClosuresByVariation' => $resource_closures_by_variation,
      'dates' => $dates_bootstrap,
      'csrfToken' => $csrf_token,
      'slotCandidatesUrl' => \Drupal\Core\Url::fromRoute('court_booking.slot_candidates')->toString(),
      'maxBookingHours' => $max_booking_hours,
    ];

    return [
      'settings' => $settings,
      'cache_tags' => $cache_tags,
      'cache_contexts' => ['languages:language_interface', 'session', 'user', 'timezone'],
    ];
  }

}
