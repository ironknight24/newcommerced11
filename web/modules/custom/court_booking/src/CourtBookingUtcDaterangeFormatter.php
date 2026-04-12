<?php

namespace Drupal\court_booking;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Formats Commerce BAT stored UTC daterange values in the site display timezone.
 *
 * Matches the interpretation used by BatEventListBuilder for event_dates.
 */
final class CourtBookingUtcDaterangeFormatter {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Formats a daterange field value (stored as UTC without designator) for display.
   */
  public function formatOrderItemRentalField(FieldItemListInterface $field): string {
    if ($field->isEmpty()) {
      return '';
    }
    $values = $field->first()->getValue();
    $start_raw = (string) ($values['value'] ?? '');
    $end_raw = (string) ($values['end_value'] ?? '');
    if ($start_raw === '') {
      return '';
    }
    if ($end_raw === '' || $end_raw === $start_raw) {
      $end_raw = $start_raw;
    }

    return $this->formatUtcRangeStrings($start_raw, $end_raw);
  }

  /**
   * Formats a bat_event entity's event_dates field like BatEventListBuilder.
   */
  public function formatBatEventDates(EntityInterface $entity): string {
    $field = $entity->get('event_dates');
    if ($field->isEmpty()) {
      return '';
    }
    $values = $field->first()->getValue();
    $start_raw = (string) ($values['value'] ?? '');
    $end_raw = (string) ($values['end_value'] ?? '');
    if ($start_raw === '') {
      return '';
    }
    if ($end_raw === '' || $end_raw === $start_raw) {
      $end_raw = $start_raw;
    }

    return $this->formatUtcRangeStrings($start_raw, $end_raw);
  }

  /**
   * Two-column style: start — end using bat_date_format or a sensible default.
   */
  protected function formatUtcRangeStrings(string $start_raw, string $end_raw): string {
    $date_format = $this->configFactory->get('bat.settings')->get('bat_date_format') ?: 'Y-m-d H:i';
    $tz_name = CourtBookingRegional::effectiveTimeZoneId($this->configFactory);
    try {
      $display_tz = new \DateTimeZone($tz_name);
    }
    catch (\Exception $e) {
      $display_tz = new \DateTimeZone('UTC');
    }
    $utc = new \DateTimeZone('UTC');

    try {
      $start_inst = new \DateTimeImmutable($start_raw, $utc);
      $end_inst = new \DateTimeImmutable($end_raw, $utc);
    }
    catch (\Exception $e) {
      return $start_raw . ' — ' . $end_raw;
    }

    $start_fmt = $start_inst->setTimezone($display_tz)->format($date_format);
    $end_fmt = $end_inst->setTimezone($display_tz)->format($date_format);

    return $start_fmt . ' — ' . $end_fmt;
  }

}
