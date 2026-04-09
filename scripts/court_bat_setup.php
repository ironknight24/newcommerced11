<?php

/**
 * @file
 * One-time / idempotent setup: BAT courts + Commerce BAT + sample product.
 *
 * Run: ddev drush php:script ../scripts/court_bat_setup.php
 */

use Drupal\bat_unit\Entity\Unit;
use Drupal\bat_unit\Entity\UnitType;
use Drupal\commerce_bat\Entity\BatAvailabilityProfile;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\Store;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$em = \Drupal::entityTypeManager();
$log = static function (string $msg): void {
  \Drupal::logger('court_bat_setup')->notice($msg);
  echo $msg . PHP_EOL;
};

// --- 1. Default store ---
$store_storage = $em->getStorage('commerce_store');
$stores = $store_storage->loadMultiple();
if (!$stores) {
  $store = Store::create([
    'type' => 'online',
    'name' => 'Trinity Courts',
    'mail' => 'courts@example.com',
    'default_currency' => 'USD',
    'address' => [
      'country_code' => 'US',
    ],
    'billing_countries' => ['US'],
    'prices_include_tax' => FALSE,
  ]);
  $store->setDefault(TRUE);
  $store->save();
  $log('Created default Commerce store: Trinity Courts (USD).');
}
else {
  $store = reset($stores);
  $log('Using existing store: ' . $store->getName());
}

// --- 2. Commerce BAT: lesson mode on default variation type, not rental ---
$config = \Drupal::configFactory()->getEditable('commerce_bat.settings');
$config->set('rental_variation_types', []);
$config->set('lesson_variation_types', ['default']);
$config->set('lesson_slot_length_minutes', 60);
// Lesson slot picker mode disables FullCalendar grid selection; without label click
// enabled, green slots look interactive but ignore clicks (commerce_bat_fullcalendar.js).
$config->set('lesson_timeslot_picker_frontend', FALSE);
$plugins = $config->get('calendar_plugin_config') ?: [];
$plugins['fullcalendar'] = ($plugins['fullcalendar'] ?? []) + ['event_click_select' => TRUE];
$config->set('calendar_plugin_config', $plugins);
$config->save();
$log('Updated commerce_bat.settings: lesson/timeslot, grid selection + label click enabled.');

// --- 2a. Courts: one BAT unit per variation (not "Lesson pool: default" shared pool).
$preset_storage = $em->getStorage('commerce_bat_capacity_preset');
$preset = $preset_storage->load('default_lesson_capacity');
if ($preset && $preset->getCapacityMode() === 'shared') {
  $preset->set('capacity_mode', 'separate');
  $preset->save();
  $log('Set default_lesson_capacity preset to separate (per-court units).');
}

// --- 2b. Show BAT calendar on product add-to-cart form (not only admin order forms) ---
$add_to_cart_display = EntityFormDisplay::load('commerce_order_item.default.add_to_cart');
if ($add_to_cart_display) {
  $component = $add_to_cart_display->getComponent('field_cbat_rental_date');
  if (!$component || ($component['type'] ?? '') !== 'bat_date') {
    $add_to_cart_display->setComponent('field_cbat_rental_date', [
      'type' => 'bat_date',
      'weight' => 5,
      'region' => 'content',
      'settings' => [
        'calendar_plugin' => 'fullcalendar',
        'rental_mode' => 'embedded',
        'lesson_mode' => 'embedded',
      ],
      'third_party_settings' => [],
    ]);
    $add_to_cart_display->save();
    $log('Enabled bat_date widget on commerce_order_item.default.add_to_cart (FullCalendar on product page).');
  }
}

// --- 3. Availability profile: 06:00–23:00 every day (hourly slots) ---
$profile_id = 'court_open_hours';
if (!BatAvailabilityProfile::load($profile_id)) {
  $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  $weekly = [];
  foreach ($days as $day) {
    $weekly[] = [
      'day' => $day,
      'start_time' => '06:00',
      'end_time' => '23:00',
    ];
  }
  $profile = BatAvailabilityProfile::create([
    'id' => $profile_id,
    'label' => 'Courts: 6:00–23:00 daily',
    'slot_length' => 60,
    'slot_granularity' => 60,
    'weekly_rules' => $weekly,
    'allowed_times' => [],
  ]);
  $profile->save();
  $log('Created availability profile: court_open_hours (60m slots, 06:00–23:00).');
}
else {
  $log('Availability profile court_open_hours already exists.');
}

// --- 4. Taxonomy reference fields on bat_unit bundle lesson_unit ---
$bundle = 'lesson_unit';
$field_defs = [
  'field_unit_court_type' => [
    'label' => 'Court type',
    'target' => 'taxonomy_term',
    'handler_settings' => ['target_bundles' => ['court_type' => 'court_type']],
    'cardinality' => 1,
  ],
  'field_unit_court_surface' => [
    'label' => 'Court surface',
    'target' => 'taxonomy_term',
    'handler_settings' => ['target_bundles' => ['court_surface' => 'court_surface']],
    'cardinality' => 1,
  ],
  'field_unit_amenities' => [
    'label' => 'Amenities',
    'target' => 'taxonomy_term',
    'handler_settings' => ['target_bundles' => ['amenities' => 'amenities']],
    'cardinality' => -1,
  ],
];

foreach ($field_defs as $field_name => $info) {
  if (!FieldStorageConfig::loadByName('bat_unit', $field_name)) {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'bat_unit',
      'type' => 'entity_reference',
      'cardinality' => $info['cardinality'],
      'settings' => [
        'target_type' => $info['target'],
      ],
    ])->save();
    $log("Created field storage bat_unit.$field_name");
  }
  if (!FieldConfig::loadByName('bat_unit', $bundle, $field_name)) {
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('bat_unit', $field_name),
      'bundle' => $bundle,
      'label' => $info['label'],
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => $info['handler_settings'],
      ],
    ])->save();
    $log("Created field bat_unit.$bundle.$field_name");
  }
  $form_display = EntityFormDisplay::load("bat_unit.$bundle.default")
    ?: EntityFormDisplay::create([
      'targetEntityType' => 'bat_unit',
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ]);
  if (!$form_display->getComponent($field_name)) {
    $form_display->setComponent($field_name, [
      'type' => 'options_select',
      'weight' => 5,
    ])->save();
  }
  $view_display = EntityViewDisplay::load("bat_unit.$bundle.default")
    ?: EntityViewDisplay::create([
      'targetEntityType' => 'bat_unit',
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ]);
  if (!$view_display->getComponent($field_name)) {
    $view_display->setComponent($field_name, [
      'type' => 'entity_reference_label',
      'weight' => 5,
    ])->save();
  }
}

// --- 5. Unit types: tennis + padel (reuse bat_type_bundle tennis / padel) ---
$ut_storage = $em->getStorage('bat_unit_type');
$tennis_ut = NULL;
$padel_ut = NULL;
foreach ($ut_storage->loadMultiple() as $ut) {
  if ($ut->bundle() === 'tennis' && $ut->label() === 'Bookable tennis courts') {
    $tennis_ut = $ut;
  }
  if ($ut->bundle() === 'padel' && $ut->label() === 'Bookable padel courts') {
    $padel_ut = $ut;
  }
}
if (!$tennis_ut) {
  $tennis_ut = UnitType::create([
    'name' => 'Bookable tennis courts',
    'type' => 'tennis',
    'status' => TRUE,
  ]);
  $tennis_ut->save();
  $log('Created BAT unit type: Bookable tennis courts (tennis).');
}
if (!$padel_ut) {
  $padel_ut = UnitType::create([
    'name' => 'Bookable padel courts',
    'type' => 'padel',
    'status' => TRUE,
  ]);
  $padel_ut->save();
  $log('Created BAT unit type: Bookable padel courts (padel).');
}

// Term IDs from your site (Tennis=3, Padel=2, Hard=4, floodlights=7, parking=8).
$t_tennis = 3;
$t_padel = 2;
$t_surface = 4;
$amenity_ids = [7, 8];

$court_defs = [
  ['name' => 'Tennis – Court 1', 'ut' => $tennis_ut, 'court_type' => $t_tennis],
  ['name' => 'Tennis – Court 2', 'ut' => $tennis_ut, 'court_type' => $t_tennis],
  ['name' => 'Padel – Court 1', 'ut' => $padel_ut, 'court_type' => $t_padel],
  ['name' => 'Padel – Court 2', 'ut' => $padel_ut, 'court_type' => $t_padel],
];

$unit_storage = $em->getStorage('bat_unit');
$created_units = [];

foreach ($court_defs as $def) {
  $existing = $unit_storage->loadByProperties(['name' => $def['name'], 'type' => $bundle]);
  if ($existing) {
    $unit = reset($existing);
    $log('BAT unit exists: ' . $def['name'] . ' (id ' . $unit->id() . ')');
  }
  else {
    $unit = Unit::create([
      'type' => $bundle,
      'name' => $def['name'],
      'unit_type_id' => $def['ut']->id(),
      'status' => 1,
      'uid' => 1,
    ]);
    $unit->save();
    $log('Created BAT unit: ' . $def['name'] . ' (id ' . $unit->id() . ')');
  }
  $unit->set('field_unit_court_type', ['target_id' => $def['court_type']]);
  $unit->set('field_unit_court_surface', ['target_id' => $t_surface]);
  $vals = [];
  foreach ($amenity_ids as $tid) {
    $vals[] = ['target_id' => $tid];
  }
  $unit->set('field_unit_amenities', $vals);
  $unit->save();
  $created_units[$def['name']] = $unit;
}

// --- 6. One product per sport + variations + variation_ref on units ---
$product_storage = $em->getStorage('commerce_product');
$ensure_product = static function (string $title) use ($product_storage, $store, $log): \Drupal\commerce_product\Entity\Product {
  $existing = $product_storage->loadByProperties(['title' => $title]);
  if ($existing) {
    $p = reset($existing);
    $log('Using existing product "' . $title . '" (id ' . $p->id() . ').');

    return $p;
  }
  $p = Product::create([
    'type' => 'default',
    'title' => $title,
    'stores' => [$store->id()],
    'status' => 1,
  ]);
  $p->save();
  $log('Created product: ' . $title . ' (id ' . $p->id() . ').');

  return $p;
};

$tennis_product = $ensure_product('Tennis courts');
$padel_product = $ensure_product('Padel courts');

// Move variations off legacy single product "Court hire" if it still exists.
$legacy_list = $product_storage->loadByProperties(['title' => 'Court hire']);
if ($legacy_list) {
  $legacy_product = reset($legacy_list);
  foreach ($legacy_product->getVariations() as $legacy_variation) {
    $sku = (string) $legacy_variation->getSku();
    if (str_starts_with($sku, 'court-tennis-')) {
      $legacy_variation->setProductId($tennis_product->id());
      $legacy_variation->save();
      $log("Moved variation $sku to Tennis courts product.");
    }
    elseif (str_starts_with($sku, 'court-padel-')) {
      $legacy_variation->setProductId($padel_product->id());
      $legacy_variation->save();
      $log("Moved variation $sku to Padel courts product.");
    }
  }
}

$sku_map = [
  'Tennis – Court 1' => ['sku' => 'court-tennis-1', 'product' => $tennis_product],
  'Tennis – Court 2' => ['sku' => 'court-tennis-2', 'product' => $tennis_product],
  'Padel – Court 1' => ['sku' => 'court-padel-1', 'product' => $padel_product],
  'Padel – Court 2' => ['sku' => 'court-padel-2', 'product' => $padel_product],
];

$pv_storage = $em->getStorage('commerce_product_variation');
foreach ($sku_map as $court_name => $info) {
  $sku = $info['sku'];
  $product = $info['product'];
  $variations = $pv_storage->loadByProperties(['sku' => $sku]);
  if ($variations) {
    $pv = reset($variations);
    $log("Variation exists: $sku (id {$pv->id()})");
    if ((int) $pv->getProductId() !== (int) $product->id()) {
      $pv->setProductId($product->id());
      $pv->save();
      $log("Reassigned variation $sku to product {$product->getTitle()} (id {$product->id()}).");
    }
  }
  else {
    $pv = ProductVariation::create([
      'type' => 'default',
      'sku' => $sku,
      'title' => $court_name . ' (hourly)',
      'price' => new Price('35.00', 'USD'),
      'status' => 1,
    ]);
    $pv->save();
    $product->addVariation($pv);
    $product->save();
    $log("Created variation $sku (id {$pv->id()}).");
  }

  if ($pv->hasField('field_cbat_mode')) {
    $pv->set('field_cbat_mode', 'lesson');
  }
  if ($pv->hasField('field_cbat_schedule')) {
    $pv->set('field_cbat_schedule', ['target_id' => $profile_id]);
  }
  if ($pv->hasField('field_cbat_capacity_preset')) {
    $pv->set('field_cbat_capacity_preset', ['target_id' => 'default_lesson_capacity']);
  }
  if ($pv->hasField('field_lesson_slot_length')) {
    $pv->set('field_lesson_slot_length', 60);
  }
  $pv->save();

  $unit = $created_units[$court_name];
  if ($unit->hasField('field_variation_ref')) {
    $unit->set('field_variation_ref', ['target_id' => $pv->id()]);
    $unit->save();
    $log('Linked unit "' . $court_name . '" to variation ' . $pv->id());
  }
}

$log(sprintf(
  'Court booking config hint — map taxonomy terms to products (Admin » Commerce » Court booking): %d|p:%d and %d|p:%d',
  $t_tennis,
  $tennis_product->id(),
  $t_padel,
  $padel_product->id()
));

\Drupal::service('cache_tags.invalidator')->invalidateTags(['config:commerce_bat.settings']);

$log('Done. Clear caches: drush cr');
echo PHP_EOL . 'NOTE: Max 4 consecutive 1-hour slots is NOT enforced by Commerce BAT out of the box.' . PHP_EOL;
echo 'See docs/COURT_BOOKING_LIMITS.md in the project root.' . PHP_EOL;
