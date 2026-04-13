<?php

namespace Drupal\court_booking\Form;

use Drupal\commerce_bat\Util\DateTimeHelper;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\court_booking\CourtBookingRegional;
use Drupal\court_booking\CourtBookingSlotBooking;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adjusts a lesson BAT slot on a placed order line item (HTML form).
 */
final class PostCheckoutSlotForm extends FormBase {

  public function __construct(
    protected CourtBookingSlotBooking $slotBooking,
    protected OrderRefreshInterface $orderRefresh,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.slot_booking'),
      $container->get('commerce_order.order_refresh'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'court_booking_post_checkout_slot_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderItemInterface $commerce_order_item = NULL): array {
    $commerce_order_item = $commerce_order_item
      ?: $this->getRouteMatch()->getParameter('commerce_order_item');
    if (!$commerce_order_item instanceof OrderItemInterface) {
      $form['message'] = [
        '#markup' => '<p>' . $this->t('Missing order line.') . '</p>',
      ];
      return $form;
    }

    $form_state->set('order_item', $commerce_order_item);

    $order = $commerce_order_item->getOrder();
    $form['booking_context'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Booking identifiers'),
      '#description' => $this->t('Use these values when tracing a booking in Commerce or support tickets. The slot fields below are the override parameters.'),
    ];
    $form['booking_context']['order_id'] = [
      '#type' => 'item',
      '#title' => $this->t('Commerce order ID'),
      '#markup' => $order ? (string) $order->id() : '—',
    ];
    $form['booking_context']['order_number'] = [
      '#type' => 'item',
      '#title' => $this->t('Order number'),
      '#markup' => $order ? Html::escape($order->getOrderNumber()) : '—',
    ];
    $form['booking_context']['order_item_id'] = [
      '#type' => 'item',
      '#title' => $this->t('Order line item ID'),
      '#markup' => (string) $commerce_order_item->id(),
    ];

    $date_field = $this->config('commerce_bat.settings')->get('lesson_order_item_date_field') ?: 'field_cbat_rental_date';
    $start_utc_raw = '';
    $end_utc_raw = '';
    if ($commerce_order_item->hasField($date_field) && !$commerce_order_item->get($date_field)->isEmpty()) {
      $vals = $commerce_order_item->get($date_field)->first()->getValue();
      $start_utc_raw = (string) ($vals['value'] ?? '');
      $end_utc_raw = (string) ($vals['end_value'] ?? '');
    }

    $variation = $commerce_order_item->getPurchasedEntity();
    $title = $variation instanceof ProductVariationInterface ? $variation->label() : $commerce_order_item->label();

    $site_tz = CourtBookingRegional::effectiveTimeZoneId($this->configFactory(), $this->currentUser());
    $start_default = $this->utcStorageToDrupalDateTime($start_utc_raw, $site_tz);
    $end_default = $this->utcStorageToDrupalDateTime($end_utc_raw, $site_tz);

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Court / line: @label. Enter the new start and end using the date and time fields below (shown in @timezone). Staff with the bypass permission may skip availability when conflicts require a business override.', [
        '@label' => $title,
        '@timezone' => $site_tz,
      ]) . '</p>',
    ];

    $datetime_common = [
      '#type' => 'datetime',
      '#date_timezone' => $site_tz,
      '#date_date_element' => 'date',
      '#date_time_element' => 'time',
      '#date_year_range' => '-2:+5',
    ];

    $form['start'] = $datetime_common + [
      '#title' => $this->t('Start'),
      '#required' => TRUE,
      '#default_value' => $start_default,
    ];

    $form['end'] = $datetime_common + [
      '#title' => $this->t('End'),
      '#required' => TRUE,
      '#default_value' => $end_default,
    ];

    if ($this->currentUser()->hasPermission('bypass court booking slot availability')) {
      $form['force'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Skip availability check (conflict override)'),
        '#default_value' => FALSE,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save slot'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('court_booking.integrated_bookings'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Parses Commerce BAT UTC storage into a datetime for the form (site timezone).
   */
  protected function utcStorageToDrupalDateTime(string $raw, string $site_timezone_id): ?DrupalDateTime {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    $normalized = str_replace('T', ' ', rtrim($raw, 'Z'));
    try {
      $utc = new \DateTimeImmutable($normalized, new \DateTimeZone('UTC'));
    }
    catch (\Exception $e) {
      return NULL;
    }
    $dd = new DrupalDateTime('@' . $utc->getTimestamp());
    try {
      $dd->setTimezone(new \DateTimeZone($site_timezone_id));
    }
    catch (\Exception $e) {
      $dd->setTimezone(new \DateTimeZone('UTC'));
    }
    return $dd;
  }

  /**
   * Converts a datetime picked in the site timezone to UTC strings for slot validation.
   */
  protected function drupalDateTimeToUtcStorageString(DrupalDateTime $date): string {
    $ts = $date->getTimestamp();
    $utc = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
    return DateTimeHelper::formatUtc(DateTimeHelper::normalizeUtc($utc));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface|null $item */
    $item = $form_state->get('order_item');
    if (!$item instanceof OrderItemInterface) {
      $form_state->setErrorByName('start', $this->t('Invalid order line.'));
      return;
    }

    $purchased = $item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      $form_state->setErrorByName('start', $this->t('This line item has no product variation.'));
      return;
    }

    $quantity = max(1, (int) $item->getQuantity());
    $skip = !empty($form_state->getValue('force')) && $this->currentUser()->hasPermission('bypass court booking slot availability');

    $start_val = $form_state->getValue('start');
    $end_val = $form_state->getValue('end');
    if (!$start_val instanceof DrupalDateTime || $start_val->hasErrors()) {
      $form_state->setErrorByName('start', $this->t('Enter a valid start date and time.'));
      return;
    }
    if (!$end_val instanceof DrupalDateTime || $end_val->hasErrors()) {
      $form_state->setErrorByName('end', $this->t('Enter a valid end date and time.'));
      return;
    }

    $start_raw = $this->drupalDateTimeToUtcStorageString($start_val);
    $end_raw = $this->drupalDateTimeToUtcStorageString($end_val);

    $validation = $this->slotBooking->validateLessonSlot(
      $purchased,
      $start_raw,
      $end_raw,
      $quantity,
      $this->currentUser(),
      $skip,
    );

    if (!$validation['ok']) {
      $form_state->setErrorByName('start', $validation['message']);
    }
    else {
      $form_state->set('validated_slot', $validation);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface|null $item */
    $item = $form_state->get('order_item');
    $validation = $form_state->get('validated_slot');
    if (!$item instanceof OrderItemInterface || !is_array($validation) || empty($validation['ok'])) {
      return;
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    $purchased = $item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return;
    }

    try {
      $this->slotBooking->applyRentalAndPrice($item, $purchased, $start, $end, $billing_units);
      $item->save();
      $order = $item->getOrder();
      if ($order) {
        $this->orderRefresh->refresh($order);
        $order->save();
        if (\function_exists('commerce_bat_sync_order_events')) {
          \commerce_bat_sync_order_events($order);
        }
      }
      $this->messenger()->addStatus($this->t('Booking slot updated and BAT events synced.'));
      $form_state->setRedirect('court_booking.integrated_bookings');
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not update booking. Please try again.'));
      $this->getLogger('court_booking')->error('Post-checkout slot form failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
