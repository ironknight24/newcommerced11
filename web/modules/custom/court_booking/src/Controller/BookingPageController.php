<?php

namespace Drupal\court_booking\Controller;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\court_booking\BookingTimezoneTrait;
use Drupal\court_booking\CourtBookingVariationThumbnail;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the booking shell and bootstrap data for drupalSettings.
 */
class BookingPageController extends ControllerBase {

  use BookingTimezoneTrait;

  public function __construct(
    protected AvailabilityManagerInterface $availabilityManager,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_bat.availability_manager'),
      $container->get('commerce_price.currency_formatter'),
    );
  }

  /**
   * Booking page render array.
   */
  public function content(): array {
    $config = $this->config('court_booking.settings');
    $mappings = $config->get('sport_mappings') ?: [];
    $days_ahead = (int) ($config->get('days_ahead') ?: 60);
    $excluded = $config->get('excluded_weekdays') ?: [];
    $commerce_bat = $this->config('commerce_bat.settings');
    $slot_minutes = (int) ($commerce_bat->get('lesson_slot_length_minutes') ?: 60);
    $site_tz = $this->displayTimeZoneId();
    $booking_day_start = $config->get('booking_day_start') ?: '06:00';
    $booking_day_end = $config->get('booking_day_end') ?: '23:00';
    $max_booking_hours = max(1, min(24, (int) ($config->get('max_booking_hours') ?: 4)));
    $buffer_minutes = max(0, min(180, (int) ($config->get('buffer_minutes') ?? 0)));
    $same_day_cutoff_hm = trim((string) ($config->get('same_day_cutoff_hm') ?? ''));
    $blackout_dates = array_values(array_unique(array_filter(array_map('strval', (array) ($config->get('blackout_dates') ?? [])))));
    $resource_closures = (array) ($config->get('resource_closures') ?? []);
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

    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $variation_storage = $this->entityTypeManager()->getStorage('commerce_product_variation');
    $product_storage = $this->entityTypeManager()->getStorage('commerce_product');
    /** @var \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator */
    $file_url_generator = \Drupal::service('file_url_generator');

    $sports = [];
    $booking_page_cache_tags = [];
    foreach ($mappings as $row) {
      $tid = (int) ($row['sport_tid'] ?? 0);
      if (!$tid) {
        continue;
      }
      $product_id = (int) ($row['product_id'] ?? 0);
      $legacy_vids = array_map('intval', $row['variation_ids'] ?? []);
      $variation_entities = [];
      if ($product_id > 0) {
        $product = $product_storage->load($product_id);
        if ($product) {
          foreach ($product->getVariations() as $v) {
            if ($v->isPublished()) {
              $variation_entities[] = $v;
            }
          }
        }
      }
      else {
        foreach ($legacy_vids as $vid) {
          $variation = $variation_storage->load($vid);
          if ($variation && $variation->isPublished()) {
            $variation_entities[] = $variation;
          }
        }
      }
      if (!$variation_entities) {
        continue;
      }
      $term = $term_storage->load($tid);
      $label = $term ? $term->getName() : (string) $tid;
      $variations_out = [];
      foreach ($variation_entities as $variation) {
        $price = '';
        $price_amount = '';
        $price_currency = '';
        $p = $variation->getPrice();
        if ($p) {
          $price = $this->currencyFormatter->format($p->getNumber(), $p->getCurrencyCode());
          $price_amount = $p->getNumber();
          $price_currency = $p->getCurrencyCode();
        }
        $card = CourtBookingVariationThumbnail::data($variation, $file_url_generator);
        $thumb = $card['url'];
        $booking_page_cache_tags = array_merge($booking_page_cache_tags, $card['cache_tags']);
        $booking_page_cache_tags = array_merge($booking_page_cache_tags, $variation->getCacheTags());
        $slot_len = max(1, (int) $this->availabilityManager->getLessonSlotLength($variation));
        $variations_out[] = [
          'id' => (int) $variation->id(),
          'title' => $variation->getTitle(),
          'price' => $price,
          'priceAmount' => $price_amount,
          'priceCurrencyCode' => $price_currency,
          'image' => $thumb,
          'slotMinutes' => $slot_len,
          'detailUrl' => Url::fromRoute('court_booking.court_detail', [
            'commerce_product_variation' => $variation->id(),
          ])->setAbsolute()->toString(),
        ];
      }
      if ($variations_out) {
        $sports[] = [
          'id' => (string) $tid,
          'label' => $label,
          'variations' => $variations_out,
        ];
      }
    }

    $interval = 'PT' . max(1, $slot_minutes) . 'M';
    // Must match \Drupal\Core\Access\CsrfRequestHeaderAccessCheck (route _csrf_request_header_token).
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

    $settings_config = $this->config('court_booking.settings');
    $country_code = \Drupal\court_booking\CourtBookingRegional::defaultCountryCode(\Drupal::configFactory());
    $first_day = \Drupal\court_booking\CourtBookingRegional::firstDayOfWeek(\Drupal::configFactory());
    $request = \Drupal::request();
    $initial_sport = $request->query->get('sport');
    $initial_variation = $request->query->get('variation');

    return [
      '#theme' => 'court_booking_page',
      '#sports_configured' => $sports !== [],
      '#attached' => [
        // Ensure theme Tailwind loads with this page (deduped if already global).
        'library' => ['misk/global', 'court_booking/booking'],
        'drupalSettings' => [
          'courtBooking' => [
            'sports' => $sports,
            'slotInterval' => $interval,
            'slotMinutes' => max(1, $slot_minutes),
            'timezone' => $site_tz,
            'countryCode' => $country_code,
            'firstDayOfWeek' => $first_day,
            'bookingDayStart' => $booking_day_start,
            'bookingDayEnd' => $booking_day_end,
            'bufferMinutes' => $buffer_minutes,
            'sameDayCutoffHm' => $same_day_cutoff_hm,
            'blackoutDates' => $blackout_dates,
            'resourceClosuresByVariation' => $resource_closures_by_variation,
            'dates' => $dates_bootstrap,
            'addUrl' => Url::fromRoute('court_booking.add')->toString(),
            'slotCandidatesUrl' => Url::fromRoute('court_booking.slot_candidates')->toString(),
            'csrfToken' => $csrf_token,
            'initialSportId' => $initial_sport !== NULL && $initial_sport !== '' ? (string) $initial_sport : '',
            'initialVariationId' => $initial_variation !== NULL && $initial_variation !== '' ? (string) $initial_variation : '',
            'maxBookingHours' => $max_booking_hours,
          ],
        ],
      ],
      '#cache' => [
        'tags' => array_values(array_unique(array_merge(
          $settings_config->getCacheTags(),
          $commerce_bat->getCacheTags(),
          $this->config('system.date')->getCacheTags(),
          $booking_page_cache_tags,
        ))),
        // CSRF in drupalSettings must be per-session (and user); without this,
        // page cache can serve another visitor's token → invalid X-CSRF-Token on POST.
        'contexts' => [
          'languages:language_interface',
          'session',
          'user',
          // drupalSettings.timezone must match date strip + booking window math.
          'timezone',
          'url.query_args:sport',
          'url.query_args:variation',
        ],
      ],
    ];
  }

}

