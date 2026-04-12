<?php

namespace Drupal\court_booking;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_bat\Util\DateTimeHelper;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Validates lesson slots and applies rental + scaled unit price to order items.
 */
final class CourtBookingSlotBooking {

  use StringTranslationTrait;

  public function __construct(
    protected AvailabilityManagerInterface $availabilityManager,
    protected ConfigFactoryInterface $configFactory,
    protected CourtBookingSportSettings $sportSettings,
  ) {}

  /**
   * Parses and validates a lesson slot for booking (add-to-cart or cart update).
   *
   * Request end is the full blocked window (play + buffer). Returned diff_minutes is play only.
   *
   * @return array{
   *   ok: bool,
   *   status: int,
   *   message: string,
   *   start?: \DateTimeImmutable,
   *   end?: \DateTimeImmutable,
   *   diff_minutes?: int,
   *   billing_units?: int,
   *   slot_len_minutes?: int
   * }
   */
  public function validateLessonSlot(
    ProductVariationInterface $variation,
    string $start_raw,
    string $end_raw,
    int $quantity,
    AccountInterface $account,
    bool $skip_availability_check = FALSE,
  ): array {
    $fail = function (int $status, string $message) {
      return [
        'ok' => FALSE,
        'status' => $status,
        'message' => $message,
      ];
    };

    $bat_mode = $this->availabilityManager->getModeForVariation($variation);
    if ($bat_mode !== 'lesson') {
      if ($bat_mode === NULL) {
        return $fail(400, (string) $this->t('This court variation is not configured for BAT lesson booking.'));
      }
      return $fail(400, (string) $this->t('This product does not use lesson booking.'));
    }

    try {
      $start = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($start_raw, FALSE));
      $end = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($end_raw, FALSE));
    }
    catch (\Throwable $e) {
      return $fail(400, (string) $this->t('Invalid date format.'));
    }

    $rules = $this->sportSettings->getMergedForVariation($variation);
    $slot_len_minutes = max(1, (int) $this->availabilityManager->getLessonSlotLength($variation));
    $buffer_minutes = max(0, min(180, (int) ($rules['buffer_minutes'] ?? 0)));
    $same_day_cutoff_hm = trim((string) ($rules['same_day_cutoff_hm'] ?? ''));
    $diff_sec = $end->getTimestamp() - $start->getTimestamp();
    if ($diff_sec <= 0) {
      return $fail(400, (string) $this->t('End time must be after start time.'));
    }
    if ($diff_sec % 60 !== 0) {
      return $fail(400, (string) $this->t('Booking times must align to whole minutes.'));
    }
    $block_minutes = (int) ($diff_sec / 60);
    $max_hours = max(1, min(24, (int) ($rules['max_booking_hours'] ?? 4)));
    $max_play_minutes = $max_hours * 60;

    if ($buffer_minutes > 0) {
      $play_minutes = $block_minutes - $buffer_minutes;
      if ($play_minutes <= 0) {
        return $fail(400, (string) $this->t('The selected window is too short for the configured buffer plus play time.'));
      }
      if ($play_minutes % $slot_len_minutes !== 0) {
        return $fail(400, (string) $this->t('Play duration must be a multiple of @m minutes.', ['@m' => $slot_len_minutes]));
      }
      if ($play_minutes > $max_play_minutes) {
        return $fail(400, (string) $this->t('Booking cannot exceed @h hours.', ['@h' => $max_hours]));
      }
    }
    else {
      $play_minutes = $block_minutes;
      if ($play_minutes % $slot_len_minutes !== 0) {
        return $fail(400, (string) $this->t('Booking length must be a multiple of @m minutes.', ['@m' => $slot_len_minutes]));
      }
      if ($play_minutes > $max_play_minutes) {
        return $fail(400, (string) $this->t('Booking cannot exceed @h hours.', ['@h' => $max_hours]));
      }
    }

    $site_tz = CourtBookingRegional::effectiveTimeZoneId($this->configFactory, $account);
    if ($buffer_minutes > 0) {
      $open_hm = (string) ($rules['booking_day_start'] ?: '06:00');
      $open_m = $this->parseHmToMinutes($open_hm);
      if ($open_m !== NULL) {
        $start_m = $this->localMinutesFromMidnight($start, $site_tz);
        $step = $play_minutes + $buffer_minutes;
        if ($start_m < $open_m || ($start_m - $open_m) % $step !== 0) {
          return $fail(400, (string) $this->t('That start time does not match the booking schedule for your buffer and duration.'));
        }
      }
    }
    if (!$this->slotWithinConfiguredHours(
      $start,
      $end,
      $site_tz,
      (string) ($rules['booking_day_start'] ?: '06:00'),
      (string) ($rules['booking_day_end'] ?: '23:00'),
    )) {
      return $fail(400, (string) $this->t('That time is outside the allowed booking hours.'));
    }
    if ($same_day_cutoff_hm !== '' && $this->sameDayBookingEndsAfterCutoff($start, $end, $site_tz, $same_day_cutoff_hm)) {
      return $fail(400, (string) $this->t('Same-day bookings must finish by @time.', ['@time' => $same_day_cutoff_hm]));
    }
    $closure_message = $this->resourceClosureMessage(
      $start,
      $site_tz,
      (int) $variation->id(),
      (array) ($rules['resource_closures'] ?? []),
    );
    if ($closure_message !== NULL) {
      return $fail(400, $closure_message);
    }
    if ($this->isBlackoutDate($start, $site_tz, (array) ($rules['blackout_dates'] ?? []))) {
      return $fail(400, (string) $this->t('Bookings are closed on this date.'));
    }

    if (!$skip_availability_check) {
      $is_available = $this->availabilityManager->isAvailable($variation, $start, $end, $quantity);
      if (!$is_available) {
        return $fail(409, (string) $this->t('That slot is no longer available.'));
      }
    }

    $billing_units = (int) ($play_minutes / $slot_len_minutes);

    return [
      'ok' => TRUE,
      'status' => 200,
      'message' => '',
      'start' => $start,
      'end' => $end,
      'diff_minutes' => $play_minutes,
      'billing_units' => $billing_units,
      'slot_len_minutes' => $slot_len_minutes,
    ];
  }

  /**
   * Buffer-mode slot candidates: starts every (play + buffer) minutes from opening until close.
   *
   * @param int[] $variation_ids
   * @param int $play_minutes
   *   Billable play length in minutes (must align to each variation’s lesson slot length).
   *
   * @return list<array{start: string, end: string, variationIds: int[]}>
   */
  public function buildBufferSlotCandidatesForDay(
    array $variation_ids,
    string $ymd,
    int $play_minutes,
    int $quantity,
    AccountInterface $account,
  ): array {
    $first_vid = (int) reset($variation_ids);
    $first_variation = $first_vid > 0 ? \Drupal\commerce_product\Entity\ProductVariation::load($first_vid) : NULL;
    $rules = $first_variation instanceof ProductVariationInterface
      ? $this->sportSettings->getMergedForVariation($first_variation)
      : $this->sportSettings->getGlobalBookingRules();
    $buffer_minutes = max(0, min(180, (int) ($rules['buffer_minutes'] ?? 0)));
    if ($buffer_minutes <= 0 || $variation_ids === []) {
      return [];
    }
    $max_hours = max(1, min(24, (int) ($rules['max_booking_hours'] ?? 4)));
    $max_play_cap = $max_hours * 60;
    $play_minutes = max(1, min($max_play_cap, $play_minutes));
    $slot_lens = [];
    foreach ($variation_ids as $vid) {
      $vid = (int) $vid;
      if ($vid <= 0) {
        continue;
      }
      $v = \Drupal\commerce_product\Entity\ProductVariation::load($vid);
      if ($v instanceof ProductVariationInterface) {
        $slot_lens[] = max(1, (int) $this->availabilityManager->getLessonSlotLength($v));
      }
    }
    if ($slot_lens === [] || !CourtBookingPlayDurationGrid::playMinutesValidForSlots($play_minutes, $slot_lens)) {
      return [];
    }
    $open_hm = (string) ($rules['booking_day_start'] ?: '06:00');
    $close_hm = (string) ($rules['booking_day_end'] ?: '23:00');
    $open_m = $this->parseHmToMinutes($open_hm);
    $close_m = $this->parseHmToMinutes($close_hm);
    $site_tz_id = CourtBookingRegional::effectiveTimeZoneId($this->configFactory, $account);
    try {
      $tz = new \DateTimeZone($site_tz_id);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }

    if ($open_m === NULL || $close_m === NULL || $close_m <= $open_m) {
      $open_m = 0;
      $close_m = 24 * 60;
    }

    $block = $play_minutes + $buffer_minutes;
    $step = $block;
    $slots_out = [];
    $variation_ids = array_values(array_unique(array_filter(array_map('intval', $variation_ids))));

    for ($t = $open_m; $t + $block <= $close_m; $t += $step) {
      $local_start = new \DateTimeImmutable($ymd . sprintf(' %02d:%02d:00', intdiv($t, 60), $t % 60), $tz);
      $start_utc = DateTimeHelper::normalizeUtc($local_start->setTimezone(new \DateTimeZone('UTC')));
      $end_utc = $start_utc->add(new \DateInterval('PT' . $block . 'M'));
      $start_raw = DateTimeHelper::formatUtc($start_utc);
      $end_raw = DateTimeHelper::formatUtc($end_utc);

      $ok_ids = [];
      foreach ($variation_ids as $vid) {
        $variation = \Drupal\commerce_product\Entity\ProductVariation::load($vid);
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }
        $val = $this->validateLessonSlot($variation, $start_raw, $end_raw, $quantity, $account, FALSE);
        if (!empty($val['ok'])) {
          $ok_ids[] = $vid;
        }
      }

      if ($ok_ids !== []) {
        $slots_out[] = [
          'start' => $start_raw,
          'end' => $end_raw,
          'variationIds' => array_values(array_unique($ok_ids)),
        ];
      }
    }

    return $slots_out;
  }

  /**
   * Minutes from local midnight for a UTC instant in the given time zone ID.
   */
  public function localMinutesFromMidnight(\DateTimeImmutable $utc, string $timezone_id): int {
    try {
      $tz = new \DateTimeZone($timezone_id);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }
    $local = $utc->setTimezone($tz);

    return (int) $local->format('G') * 60 + (int) $local->format('i');
  }

  /**
   * Sets rental field and overridden unit price from slot length.
   */
  public function applyRentalAndPrice(
    OrderItemInterface $order_item,
    ProductVariationInterface $variation,
    \DateTimeImmutable $start,
    \DateTimeImmutable $end,
    int $billing_units,
  ): void {
    if ($order_item->hasField('field_cbat_rental_date')) {
      $order_item->set('field_cbat_rental_date', [
        'value' => DateTimeHelper::formatUtc($start),
        'end_value' => DateTimeHelper::formatUtc($end),
      ]);
    }
    $base_price = $variation->getPrice();
    if ($base_price && $billing_units >= 1) {
      $scaled = $base_price->multiply((string) $billing_units);
      $order_item->setUnitPrice($scaled, TRUE);
    }
  }

  /**
   * TRUE if local start/end fall inside the configured HH:MM window (site TZ).
   */
  public function slotWithinConfiguredHours(
    \DateTimeImmutable $start,
    \DateTimeImmutable $end,
    string $timezone_id,
    string $open_hm,
    string $close_hm,
  ): bool {
    $open_m = $this->parseHmToMinutes($open_hm);
    $close_m = $this->parseHmToMinutes($close_hm);
    if ($open_m === NULL || $close_m === NULL || $close_m <= $open_m) {
      return TRUE;
    }
    try {
      $tz = new \DateTimeZone($timezone_id);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }
    $start_local = $start->setTimezone($tz);
    $end_local = $end->setTimezone($tz);
    $start_m = (int) $start_local->format('G') * 60 + (int) $start_local->format('i');
    $end_m = (int) $end_local->format('G') * 60 + (int) $end_local->format('i');
    if ($end_m < $start_m) {
      return FALSE;
    }

    return $start_m >= $open_m && $end_m <= $close_m;
  }

  /**
   * Parses HH:MM to minutes from midnight, or NULL if invalid.
   */
  public function parseHmToMinutes(string $value): ?int {
    $raw = trim($value);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
      return NULL;
    }
    [$h, $m] = array_map('intval', explode(':', $raw, 2));

    return $h * 60 + $m;
  }

  /**
   * TRUE when booking starts today (local) and the block end is strictly after cutoff that day.
   *
   * Same-day cutoff limits when the court must be free (play + buffer), not only when the slot starts.
   */
  public function sameDayBookingEndsAfterCutoff(\DateTimeImmutable $start, \DateTimeImmutable $end, string $timezone_id, string $cutoff_hm): bool {
    $cutoff_hm = trim($cutoff_hm);
    if ($cutoff_hm === '' || $this->parseHmToMinutes($cutoff_hm) === NULL) {
      return FALSE;
    }
    try {
      $tz = new \DateTimeZone($timezone_id);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }
    $start_local = $start->setTimezone($tz);
    $today_local = new \DateTimeImmutable('today', $tz);
    if ($start_local->format('Y-m-d') !== $today_local->format('Y-m-d')) {
      return FALSE;
    }
    $end_local = $end->setTimezone($tz);
    $cutoff_local = new \DateTimeImmutable($start_local->format('Y-m-d') . ' ' . $cutoff_hm . ':00', $tz);

    return $end_local > $cutoff_local;
  }

  /**
   * TRUE when local booking date is configured as blackout date.
   *
   * @param string[] $blackout_dates
   */
  public function isBlackoutDate(\DateTimeImmutable $start, string $timezone_id, array $blackout_dates): bool {
    if (!$blackout_dates) {
      return FALSE;
    }
    try {
      $tz = new \DateTimeZone($timezone_id);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }
    $local_ymd = $start->setTimezone($tz)->format('Y-m-d');
    $blackout_set = array_values(array_unique(array_filter(array_map('strval', $blackout_dates))));

    return in_array($local_ymd, $blackout_set, TRUE);
  }

  /**
   * Returns closure error message when variation is closed on the booking date.
   *
   * @param array<int, array<string, mixed>> $closures
   */
  public function resourceClosureMessage(
    \DateTimeImmutable $start,
    string $timezone_id,
    int $variation_id,
    array $closures,
  ): ?string {
    if ($variation_id <= 0 || !$closures) {
      return NULL;
    }
    try {
      $tz = new \DateTimeZone($timezone_id);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }
    $local_ymd = $start->setTimezone($tz)->format('Y-m-d');
    foreach ($closures as $row) {
      $vid = (int) ($row['variation_id'] ?? 0);
      if ($vid !== $variation_id) {
        continue;
      }
      $start_date = trim((string) ($row['start_date'] ?? ''));
      $end_date = trim((string) ($row['end_date'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        continue;
      }
      if ($local_ymd < $start_date || $local_ymd > $end_date) {
        continue;
      }
      $reason = trim((string) ($row['reason'] ?? ''));
      if ($reason !== '') {
        return (string) $this->t('This court is temporarily closed: @reason', ['@reason' => $reason]);
      }
      return (string) $this->t('This court is temporarily closed on this date.');
    }

    return NULL;
  }

}
