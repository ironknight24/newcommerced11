<?php

namespace Drupal\court_booking\Plugin\Field\FieldFormatter;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_bat\Util\DateTimeHelper;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Template\Attribute;
use Drupal\court_booking\CourtBookingRegional;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows formatted slot with edit control for lesson BAT lines on the cart.
 *
 * @FieldFormatter(
 *   id = "court_booking_cart_rental_slot",
 *   label = @Translation("Court booking slot (edit on cart)"),
 *   field_types = {
 *     "daterange"
 *   }
 * )
 */
final class CourtBookingCartRentalSlotFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    protected DateFormatterInterface $dateFormatter,
    protected AvailabilityManagerInterface $availabilityManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('date.formatter'),
      $container->get('commerce_bat.availability_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'show_edit' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];
    $entity = $items->getEntity();
    if (!$entity instanceof OrderItemInterface) {
      return $element;
    }
    $purchased = $entity->getPurchasedEntity();
    if (!$purchased) {
      return $element;
    }
    $mode = $this->availabilityManager->getModeForVariation($purchased);
    if ($mode !== 'lesson') {
      foreach ($items as $delta => $item) {
        if ($item->isEmpty()) {
          continue;
        }
        $element[$delta] = $this->viewPlainRange($item->value, $item->end_value);
      }
      return $element;
    }

    $show_edit = (bool) $this->getSetting('show_edit');
    foreach ($items as $delta => $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $start = $item->value;
      $end = $item->end_value;
      $tz = CourtBookingRegional::effectiveTimeZoneId(\Drupal::configFactory(), \Drupal::currentUser());
      try {
        $storage_name = DateTimeItemInterface::STORAGE_TIMEZONE;
        $start_dt = new \DateTimeImmutable($start, new \DateTimeZone($storage_name));
        $end_dt = new \DateTimeImmutable($end, new \DateTimeZone($storage_name));
        $start_ts = $start_dt->getTimestamp();
        $end_ts = $end_dt->getTimestamp();
      }
      catch (\Throwable $e) {
        $element[$delta] = ['#markup' => ''];
        continue;
      }
      if (!$start_ts || !$end_ts) {
        $element[$delta] = ['#markup' => ''];
        continue;
      }
      $date_label = $this->dateFormatter->format($start_ts, 'custom', 'D, d M Y', $tz);
      $time_from = $this->dateFormatter->format($start_ts, 'custom', 'g:i A', $tz);
      $time_to = $this->dateFormatter->format($end_ts, 'custom', 'g:i A', $tz);
      $buffer_minutes = max(0, min(180, (int) (\Drupal::configFactory()->get('court_booking.settings')->get('buffer_minutes') ?? 0)));
      $buffer_note = '';
      if ($buffer_minutes > 0) {
        $buffer_note = (string) $this->t('Price is for play time only; the time range includes @n minutes of buffer.', [
          '@n' => $buffer_minutes,
        ]);
      }

      $start_iso = '';
      $end_iso = '';
      try {
        $start_utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($start, FALSE));
        $end_utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($end, FALSE));
        $start_iso = DateTimeHelper::formatUtc($start_utc);
        $end_iso = DateTimeHelper::formatUtc($end_utc);
      }
      catch (\Throwable $e) {
        $start_iso = '';
        $end_iso = '';
      }

      $build = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cb-cart-slot']],
        'date_row' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['cb-cart-slot__date-row']],
          'markup' => [
            '#type' => 'markup',
            '#markup' => '<span class="cb-cart-slot__label">' . Html::escape((string) $this->t('Date')) . ':</span> <span class="cb-cart-slot__value">' . Html::escape($date_label) . '</span>',
          ],
        ],
        'time_row' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['cb-cart-slot__time-row']],
          'time_text' => [
            '#type' => 'markup',
            '#markup' => '<span class="cb-cart-slot__label cb-cart-slot__label--time">' . Html::escape((string) $this->t('Time')) . ':</span> <span class="cb-cart-slot__value cb-cart-slot__value--time">' . Html::escape($time_from . ' – ' . $time_to) . '</span>',
            '#prefix' => '<div class="cb-cart-slot__time-text">',
            '#suffix' => '</div>',
          ],
        ],
      ];
      if ($buffer_note !== '') {
        $build['time_row']['buffer_text'] = [
          '#type' => 'markup',
          '#markup' => '<div class="cb-cart-slot__buffer text-xs text-slate-500">' . Html::escape($buffer_note) . '</div>',
        ];
      }
      if ($show_edit) {
        $edit_attrs = [
          'type' => 'button',
          'class' => ['cb-cart-slot-edit'],
          'data-cb-order-item-id' => (string) $entity->id(),
          'data-cb-variation-id' => (string) $purchased->id(),
          'aria-label' => (string) $this->t('Change date and time'),
        ];
        if ($start_iso !== '') {
          $edit_attrs['data-cb-slot-start'] = $start_iso;
        }
        if ($end_iso !== '') {
          $edit_attrs['data-cb-slot-end'] = $end_iso;
        }
        $pencil = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path d="M2.695 14.295a1 1 0 00-.39 1.024l.857 3.43a1 1 0 00.97.751 1 1 0 00.97-.751l.857-3.43a1 1 0 00-.39-1.024l-2.21-2.21-2.674 2.21zm3.43-3.43l8.5-8.5a1 1 0 011.414 0l2.21 2.21a1 1 0 010 1.414l-8.5 8.5-3.624-3.624z"/></svg>';
        $attr = new Attribute($edit_attrs);
        $build['time_row']['edit'] = [
          '#type' => 'markup',
          '#markup' => '<button' . $attr . '>' . $pencil . '</button>',
        ];
      }
      $element[$delta] = $build;
    }

    return $element;
  }

  /**
   * Non-lesson lines: simple range text.
   */
  protected function viewPlainRange(?string $start, ?string $end): array {
    if ($start === NULL || $end === NULL || $start === '' || $end === '') {
      return ['#markup' => ''];
    }
    $tz = CourtBookingRegional::effectiveTimeZoneId(\Drupal::configFactory(), \Drupal::currentUser());
    try {
      $storage_name = DateTimeItemInterface::STORAGE_TIMEZONE;
      $start_dt = new \DateTimeImmutable($start, new \DateTimeZone($storage_name));
      $end_dt = new \DateTimeImmutable($end, new \DateTimeZone($storage_name));
      $start_ts = $start_dt->getTimestamp();
      $end_ts = $end_dt->getTimestamp();
    }
    catch (\Throwable $e) {
      return ['#markup' => ''];
    }
    if (!$start_ts || !$end_ts) {
      return ['#markup' => ''];
    }
    $date_label = $this->dateFormatter->format($start_ts, 'custom', 'D, d M Y', $tz);
    $time_from = $this->dateFormatter->format($start_ts, 'custom', 'g:i A', $tz);
    $time_to = $this->dateFormatter->format($end_ts, 'custom', 'g:i A', $tz);

    return [
      '#markup' => $date_label . ' · ' . $time_from . ' – ' . $time_to,
    ];
  }

}
