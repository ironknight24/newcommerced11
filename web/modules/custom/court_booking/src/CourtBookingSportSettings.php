<?php

namespace Drupal\court_booking;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Merges global court_booking.settings with per-sport overrides.
 */
final class CourtBookingSportSettings {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Raw global booking keys from config (no overrides).
   *
   * @return array{
   *   days_ahead: int,
   *   booking_day_start: string,
   *   booking_day_end: string,
   *   max_booking_hours: int,
   *   buffer_minutes: int,
   *   same_day_cutoff_hm: string,
   *   blackout_dates: string[],
   *   resource_closures: array<int, array<string, mixed>>
   * }
   */
  public function getGlobalBookingRules(): array {
    return $this->rulesFromConfig($this->configFactory->get('court_booking.settings'));
  }

  /**
   * @return array<string, mixed>
   *   Enabled override row from sport_booking_overrides, or empty array.
   */
  public function getSportOverrideRow(int $sport_tid): array {
    if ($sport_tid <= 0) {
      return [];
    }
    $config = $this->configFactory->get('court_booking.settings');
    $map = $config->get('sport_booking_overrides') ?: [];
    if (!is_array($map)) {
      return [];
    }
    $key = (string) $sport_tid;
    $row = $map[$key] ?? $map[$sport_tid] ?? [];
    return is_array($row) ? $row : [];
  }

  /**
   * Merged booking rules for a sport term (defaults + optional override).
   *
   * @return array<string, mixed>
   *   Same shape as getGlobalBookingRules().
   */
  public function getMergedForSport(int $sport_tid): array {
    $base = $this->getGlobalBookingRules();
    $row = $this->getSportOverrideRow($sport_tid);
    if (empty($row['enabled'])) {
      return $base;
    }
    return $this->applyOverrideRow($base, $row);
  }

  /**
   * Merged rules for a variation using sport mapping (or globals if unmapped).
   *
   * @return array<string, mixed>
   */
  public function getMergedForVariation(ProductVariationInterface $variation): array {
    $tid = court_booking_sport_tid_for_variation($variation);
    if ($tid <= 0) {
      return $this->getGlobalBookingRules();
    }
    return $this->getMergedForSport($tid);
  }

  /**
   * Builds date strip entries for drupalSettings from merged rules.
   *
   * @return list<array{ymd: string, dayNum: string, weekday: string, from: string, to: string}>
   */
  public function buildDatesBootstrap(array $rules, string $site_tz, ?string $langcode = NULL): array {
    $days_ahead = max(1, min(365, (int) ($rules['days_ahead'] ?? 60)));
    $out = [];
    try {
      $tz = new \DateTimeZone($site_tz);
      $start = new \DateTimeImmutable('today', $tz);
      for ($i = 0; $i < $days_ahead; $i++) {
        $day_local = $start->modify('+' . $i . ' days');
        $day_start_utc = $day_local->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $day_end_utc = $day_local->modify('+1 day')->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $noon_ts = $day_local->setTime(12, 0, 0)->getTimestamp();
        $weekday = $this->dateFormatter->format($noon_ts, 'custom', 'D', $site_tz, $langcode);
        $dayNum = $this->dateFormatter->format($noon_ts, 'custom', 'j', $site_tz, $langcode);
        $out[] = [
          'ymd' => $day_local->format('Y-m-d'),
          'dayNum' => $dayNum,
          'weekday' => $weekday,
          'from' => $day_start_utc->format('Y-m-d\TH:i:s\Z'),
          'to' => $day_end_utc->format('Y-m-d\TH:i:s\Z'),
        ];
      }
    }
    catch (\Throwable $e) {
      return [];
    }
    return $out;
  }

  /**
   * Shapes merged rules + date strip for court_booking.js / cart_slot.js.
   *
   * @param array<string, mixed> $rules
   *
   * @return array<string, mixed>
   */
  public function bookingRulesForJs(array $rules, string $site_tz, ?string $langcode = NULL): array {
    return [
      'bookingDayStart' => (string) ($rules['booking_day_start'] ?: '06:00'),
      'bookingDayEnd' => (string) ($rules['booking_day_end'] ?: '23:00'),
      'bufferMinutes' => max(0, min(180, (int) ($rules['buffer_minutes'] ?? 0))),
      'sameDayCutoffHm' => trim((string) ($rules['same_day_cutoff_hm'] ?? '')),
      'blackoutDates' => array_values(array_unique(array_filter(array_map('strval', (array) ($rules['blackout_dates'] ?? []))))),
      'resourceClosuresByVariation' => $this->closuresByVariationForJs((array) ($rules['resource_closures'] ?? [])),
      'dates' => $this->buildDatesBootstrap($rules, $site_tz, $langcode),
      'maxBookingHours' => max(1, min(24, (int) ($rules['max_booking_hours'] ?? 4))),
    ];
  }

  /**
   * Resource closures indexed by variation ID string for JavaScript.
   *
   * @param array<int, array<string, mixed>> $closures
   *
   * @return array<string, list<array{startDate: string, endDate: string, reason: string}>>
   */
  public function closuresByVariationForJs(array $closures): array {
    $by = [];
    foreach ($closures as $row) {
      $vid = (int) ($row['variation_id'] ?? 0);
      $start_date = trim((string) ($row['start_date'] ?? ''));
      $end_date = trim((string) ($row['end_date'] ?? ''));
      if ($vid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || $end_date < $start_date) {
        continue;
      }
      $by[(string) $vid][] = [
        'startDate' => $start_date,
        'endDate' => $end_date,
        'reason' => trim((string) ($row['reason'] ?? '')),
      ];
    }
    return $by;
  }

  /**
   * @param array<string, mixed> $base
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function applyOverrideRow(array $base, array $row): array {
    $keys = [
      'days_ahead',
      'booking_day_start',
      'booking_day_end',
      'max_booking_hours',
      'buffer_minutes',
      'same_day_cutoff_hm',
      'blackout_dates',
      'resource_closures',
    ];
    foreach ($keys as $key) {
      if (!array_key_exists($key, $row)) {
        continue;
      }
      $val = $row[$key];
      if ($key === 'same_day_cutoff_hm' && is_string($val)) {
        $base[$key] = trim($val);
        continue;
      }
      if ($key === 'blackout_dates' && is_array($val)) {
        $base[$key] = array_values(array_unique(array_filter(array_map('strval', $val))));
        continue;
      }
      if ($key === 'resource_closures' && is_array($val)) {
        $base[$key] = $val;
        continue;
      }
      if ($key === 'days_ahead') {
        $base[$key] = max(1, min(365, (int) $val));
        continue;
      }
      if ($key === 'max_booking_hours') {
        $base[$key] = max(1, min(24, (int) $val));
        continue;
      }
      if ($key === 'buffer_minutes') {
        $base[$key] = max(0, min(180, (int) $val));
        continue;
      }
      if (in_array($key, ['booking_day_start', 'booking_day_end'], TRUE) && is_string($val)) {
        $base[$key] = trim($val);
      }
    }
    return $base;
  }

  /**
   * @return array<string, mixed>
   */
  private function rulesFromConfig(ImmutableConfig $config): array {
    return [
      'days_ahead' => (int) ($config->get('days_ahead') ?: 60),
      'booking_day_start' => (string) ($config->get('booking_day_start') ?: '06:00'),
      'booking_day_end' => (string) ($config->get('booking_day_end') ?: '23:00'),
      'max_booking_hours' => max(1, min(24, (int) ($config->get('max_booking_hours') ?: 4))),
      'buffer_minutes' => max(0, min(180, (int) ($config->get('buffer_minutes') ?? 0))),
      'same_day_cutoff_hm' => trim((string) ($config->get('same_day_cutoff_hm') ?? '')),
      'blackout_dates' => array_values(array_unique(array_filter(array_map('strval', (array) ($config->get('blackout_dates') ?? []))))),
      'resource_closures' => array_values((array) ($config->get('resource_closures') ?? [])),
    ];
  }

}
