<?php

namespace Drupal\court_booking;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Resolves Commerce product variation for a BAT event (event field or BAT unit).
 */
final class CourtBookingBatEventVariationResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds a table cell: linked variation title, plain title, or em dash.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function buildCell(EntityInterface $event): array {
    $variation = $this->loadVariationForEvent($event);
    if (!$variation instanceof ProductVariationInterface) {
      // Table cells must use a 'data' key or the whole array is treated as HTML attributes.
      return ['data' => ['#markup' => '—']];
    }
    $title = $variation->getTitle();
    if ($variation->access('update')) {
      return [
        'data' => Link::fromTextAndUrl($title, $variation->toUrl('edit-form'))->toRenderable(),
      ];
    }

    return [
      'data' => ['#markup' => Html::escape($title)],
    ];
  }

  /**
   * Loads the product variation for this event, if any.
   */
  public function loadVariationForEvent(EntityInterface $event): ?ProductVariationInterface {
    $vid = $this->getVariationId($event);
    if ($vid <= 0) {
      return NULL;
    }
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

  /**
   * Variation ID from event field_variation_ref, else from linked BAT unit.
   */
  public function getVariationId(EntityInterface $event): int {
    if ($event->hasField('field_variation_ref') && !$event->get('field_variation_ref')->isEmpty()) {
      return (int) $event->get('field_variation_ref')->target_id;
    }
    if ($event->hasField('event_bat_unit_reference') && !$event->get('event_bat_unit_reference')->isEmpty()) {
      $unit_id = (int) $event->get('event_bat_unit_reference')->target_id;
      if ($unit_id > 0) {
        $unit = $this->entityTypeManager->getStorage('bat_unit')->load($unit_id);
        if ($unit && $unit->hasField('field_variation_ref') && !$unit->get('field_variation_ref')->isEmpty()) {
          return (int) $unit->get('field_variation_ref')->target_id;
        }
      }
    }

    return 0;
  }

}
