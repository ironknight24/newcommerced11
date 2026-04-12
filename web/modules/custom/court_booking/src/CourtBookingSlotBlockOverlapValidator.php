<?php

namespace Drupal\court_booking;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_bat\Util\DateTimeHelper;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Detects overlapping admin blockout BAT events for slot management validation.
 *
 * Commerce BAT may merge overlapping blockouts on save; this validator surfaces
 * a clear form error when a block already exists for the requested window.
 */
final class CourtBookingSlotBlockOverlapValidator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AvailabilityManagerInterface $availabilityManager,
  ) {}

  /**
   * TRUE when at least one admin blockout overlaps the local time window.
   */
  public function hasOverlappingBlockout(
    ProductVariationInterface $variation,
    \DateTimeInterface $startLocal,
    \DateTimeInterface $endLocal,
  ): bool {
    return $this->getOverlappingBlockouts($variation, $startLocal, $endLocal) !== [];
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Overlapping bat_event entities that qualify as admin blockouts.
   */
  public function getOverlappingBlockouts(
    ProductVariationInterface $variation,
    \DateTimeInterface $startLocal,
    \DateTimeInterface $endLocal,
  ): array {
    $unit = $this->availabilityManager->getUnitForVariation($variation);
    if (!is_object($unit) || !method_exists($unit, 'id')) {
      return [];
    }
    $unit_id = (int) $unit->id();
    if ($unit_id <= 0) {
      return [];
    }

    $mode = $this->availabilityManager->getModeForVariation($variation);
    if ($mode === NULL) {
      return [];
    }

    $event_type = $this->availabilityManager->getEventBundle($mode);
    if ($event_type === NULL || $event_type === '') {
      return [];
    }

    $utc = new \DateTimeZone('UTC');
    $start_utc = DateTimeHelper::normalizeUtc(
      \DateTimeImmutable::createFromInterface($startLocal)->setTimezone($utc)
    );
    $end_utc = DateTimeHelper::normalizeUtc(
      \DateTimeImmutable::createFromInterface($endLocal)->setTimezone($utc)
    );

    $storage = $this->entityTypeManager->getStorage('bat_event');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $event_type)
      ->condition('event_bat_unit_reference', $unit_id)
      ->condition('event_dates.value', $end_utc->format('Y-m-d\TH:i:s'), '<')
      ->condition('event_dates.end_value', $start_utc->format('Y-m-d\TH:i:s'), '>')
      ->execute();

    if (!$ids) {
      return [];
    }

    $events = $storage->loadMultiple($ids);
    $blockout_state = $this->availabilityManager->getBlockoutStateForVariation($variation);
    $blockouts = [];
    foreach ($events as $event) {
      if ($this->isAdminBlockoutEvent($event, $blockout_state)) {
        $blockouts[] = $event;
      }
    }

    return $blockouts;
  }

  /**
   * Mirrors commerce_bat AvailabilityManager::isBlockoutEvent heuristics.
   */
  protected function isAdminBlockoutEvent(EntityInterface $event, ?string $blockout_state): bool {
    if ($event->hasField('field_cbat_source') && !$event->get('field_cbat_source')->isEmpty()) {
      if ($event->get('field_cbat_source')->value === 'admin_blockout') {
        return TRUE;
      }
    }
    if ($blockout_state && $event->hasField('event_state_reference') && !$event->get('event_state_reference')->isEmpty()) {
      if ((string) $event->get('event_state_reference')->target_id === (string) $blockout_state) {
        return TRUE;
      }
    }
    $label = (string) $event->label();

    return str_starts_with($label, 'Blockout:');
  }

}
