<?php

namespace Drupal\court_booking;

use Drupal\bat_event\EventListBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists BAT events with start/end interpreted as UTC then formatted in site TZ.
 *
 * Commerce BAT stores event_dates without a timezone designator; those values are
 * UTC instants. Core BAT formats them with PHP's default zone, which shifts times.
 *
 * Adds a Court (variation) column from Commerce BAT field_variation_ref or the unit.
 */
class BatEventListBuilder extends EventListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    ConfigFactoryInterface $config_factory,
    DateFormatterInterface $date_formatter,
    RedirectDestinationInterface $redirect_destination,
    protected CourtBookingBatEventVariationResolver $variationResolver,
  ) {
    parent::__construct($entity_type, $storage, $config_factory, $date_formatter, $redirect_destination);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('config.factory'),
      $container->get('date.formatter'),
      $container->get('redirect.destination'),
      $container->get('court_booking.bat_event_variation_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = parent::buildHeader();
    $operations = [];
    if (isset($header['operations'])) {
      $operations['operations'] = $header['operations'];
      unset($header['operations']);
    }
    $header['court_variation'] = [
      'data' => $this->t('Court (variation)'),
      'class' => [\RESPONSIVE_PRIORITY_LOW],
    ];

    return $header + $operations;
  }

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
    $row['court_variation'] = $this->variationResolver->buildCell($entity);

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
