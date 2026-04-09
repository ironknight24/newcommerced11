<?php

namespace Drupal\court_booking;

use Drupal\bat_event\EventListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists BAT events with start/end interpreted as UTC then formatted in site TZ.
 *
 * Commerce BAT stores event_dates without a timezone designator; those values are
 * UTC instants. Core BAT formats them with PHP's default zone, which shifts times.
 */
class BatEventListBuilder extends EventListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $date_format = $this->configFactory->get('bat.settings')->get('bat_date_format') ?: 'Y-m-d H:i';
    $tz_name = CourtBookingRegional::effectiveTimeZoneId($this->configFactory);
    try {
      $display_tz = new \DateTimeZone($tz_name);
    }
    catch (\Exception $e) {
      $display_tz = new \DateTimeZone('UTC');
    }
    $utc = new \DateTimeZone('UTC');

    $row['id'] = $entity->id();
    $row['start_date'] = $this->formatStoredRangeInstant($entity, 'value', $utc, $display_tz, $date_format);
    $row['end_date'] = $this->formatStoredRangeInstant($entity, 'end_value', $utc, $display_tz, $date_format);
    $row['type'] = bat_event_type_load($entity->bundle())->label();

    return $row + parent::buildRow($entity);
  }

  /**
   * Formats one daterange component: parse as UTC, display in $display_tz.
   */
  protected function formatStoredRangeInstant(
    EntityInterface $entity,
    string $key,
    \DateTimeZone $storage_tz,
    \DateTimeZone $display_tz,
    string $php_format,
  ): string {
    $field = $entity->get('event_dates');
    if ($field->isEmpty()) {
      return '';
    }
    $values = $field->first()->getValue();
    $raw = (string) ($values[$key] ?? '');
    if ($raw === '') {
      return '';
    }
    try {
      $instant = new \DateTimeImmutable($raw, $storage_tz);
    }
    catch (\Exception $e) {
      return $raw;
    }

    return $instant->setTimezone($display_tz)->format($php_format);
  }

}
