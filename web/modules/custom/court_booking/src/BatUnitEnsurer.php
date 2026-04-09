<?php

namespace Drupal\court_booking;

use Drupal\bat_unit\UnitInterface;
use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;

/**
 * Ensures a Commerce BAT BAT unit exists for a variation used on the booking page.
 *
 * Anonymous users often lack "create bat_unit" permissions; Commerce BAT's
 * ensureUnitForVariation() would then fail to persist new units. We retry as
 * user 1 and repair mapped units missing unit_type_id (which breaks resolution).
 */
final class BatUnitEnsurer {

  public function __construct(
    protected AvailabilityManagerInterface $availabilityManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Returns a BAT unit for the variation, creating or fixing as needed.
   */
  public function ensureBookableUnit(ProductVariationInterface $variation): ?UnitInterface {
    $unit = $this->availabilityManager->getUnitForVariation($variation);
    if ($unit instanceof UnitInterface) {
      return $unit;
    }

    $this->repairMappedUnitTypeIfNeeded($variation);
    $unit = $this->availabilityManager->getUnitForVariation($variation);
    if ($unit instanceof UnitInterface) {
      return $unit;
    }

    $admin = $this->entityTypeManager->getStorage('user')->load(1);
    if (!$admin) {
      return NULL;
    }

    $this->accountSwitcher->switchTo($admin);
    try {
      $unit = $this->availabilityManager->getUnitForVariation($variation);
      if ($unit instanceof UnitInterface) {
        return $unit;
      }
      $this->repairMappedUnitTypeIfNeeded($variation);
      $unit = $this->availabilityManager->getUnitForVariation($variation);

      return $unit instanceof UnitInterface ? $unit : NULL;
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Sets unit_type_id on a mapped lesson unit when empty (Commerce BAT rejects it).
   */
  protected function repairMappedUnitTypeIfNeeded(ProductVariationInterface $variation): void {
    $config = \Drupal::config('commerce_bat.settings');
    $lesson_bundle = $config->get('lesson_unit_bundle') ?: 'lesson_unit';
    $lesson_ut = $config->get('lesson_unit_type') ?: 'default';
    if ($lesson_ut === '' || !$this->entityTypeManager->getStorage('bat_unit_type')->load($lesson_ut)) {
      return;
    }

    $vid = (int) $variation->id();
    if ($vid < 1 || !$this->database->schema()->tableExists('commerce_bat_variation_unit')) {
      return;
    }

    $unit_id = $this->database->select('commerce_bat_variation_unit', 'm')
      ->fields('m', ['unit_id'])
      ->condition('variation_id', $vid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (!$unit_id) {
      return;
    }

    $unit = $this->entityTypeManager->getStorage('bat_unit')->load((int) $unit_id);
    if (!$unit instanceof UnitInterface || $unit->bundle() !== $lesson_bundle) {
      return;
    }

    if (!$unit->get('unit_type_id')->isEmpty() && $unit->getUnitType()) {
      return;
    }

    $unit->setUnitTypeId($lesson_ut);
    $unit->save();
  }

}
