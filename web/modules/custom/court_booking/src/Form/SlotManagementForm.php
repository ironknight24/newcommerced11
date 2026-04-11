<?php

namespace Drupal\court_booking\Form;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\court_booking\CourtBookingRegional;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin: block a lesson time range via Commerce BAT blockout events.
 */
final class SlotManagementForm extends FormBase {

  public function __construct(
    protected AvailabilityManagerInterface $availabilityManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_bat.availability_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'court_booking_slot_management';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->eligibleVariationOptions();
    $commerce_bat_blockout = Url::fromRoute('commerce_bat.blockout')->toString();

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t(
        'Blocks a time range on the selected court so it cannot be booked. This creates a Commerce BAT <em>blockout</em> event (same mechanism as <a href=":url">Create blocking event</a> under Commerce BAT). Times use the effective timezone for your account and site regional settings.',
        [':url' => $commerce_bat_blockout]
      ) . '</p>',
    ];

    $form['variation_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Court (variation)'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Only courts that appear on the public booking page (mapped, published, linked to a published court node, lesson mode).'),
    ];

    $form['block_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#required' => TRUE,
    ];

    $form['time_start'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Start time'),
      '#size' => 8,
      '#required' => TRUE,
      '#default_value' => '09:00',
      '#description' => $this->t('24-hour local time, format @format.', ['@format' => 'HH:MM']),
    ];

    $form['time_end'] = [
      '#type' => 'textfield',
      '#title' => $this->t('End time'),
      '#size' => 8,
      '#required' => TRUE,
      '#default_value' => '10:00',
      '#description' => $this->t('Must be after start time on the same calendar day.'),
    ];

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#description' => $this->t('For lesson courts, quantity maps to booking units; seats-per-qty rules apply as in Commerce BAT blockouts.'),
      '#min' => 1,
      '#default_value' => 1,
      '#required' => TRUE,
    ];

    if ($options === []) {
      $form['variation_id']['#access'] = FALSE;
      $form['block_date']['#access'] = FALSE;
      $form['time_start']['#access'] = FALSE;
      $form['time_end']['#access'] = FALSE;
      $form['quantity']['#access'] = FALSE;
      $form['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p><em>' . $this->t('No eligible courts are configured. Add sport mappings and ensure each court has a published court node.') . '</em></p>',
      ];
    }

    $tz = CourtBookingRegional::effectiveTimeZoneId($this->configFactory(), $this->currentUser());
    $form['timezone_hint'] = [
      '#type' => 'item',
      '#title' => $this->t('Timezone'),
      '#markup' => Html::escape($tz),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Block time slot'),
      '#button_type' => 'primary',
      '#access' => $options !== [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->eligibleVariationOptions() === []) {
      return;
    }

    $date = trim((string) $form_state->getValue('block_date'));
    $time_start = trim((string) $form_state->getValue('time_start'));
    $time_end = trim((string) $form_state->getValue('time_end'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      $form_state->setErrorByName('block_date', $this->t('Enter a valid date.'));
      return;
    }

    $start_parts = $this->parseHourMinute($time_start);
    $end_parts = $this->parseHourMinute($time_end);
    if ($start_parts === NULL) {
      $form_state->setErrorByName('time_start', $this->t('Use 24-hour time as @format.', ['@format' => 'HH:MM']));
      return;
    }
    if ($end_parts === NULL) {
      $form_state->setErrorByName('time_end', $this->t('Use 24-hour time as @format.', ['@format' => 'HH:MM']));
      return;
    }

    $tz_name = CourtBookingRegional::effectiveTimeZoneId($this->configFactory(), $this->currentUser());
    try {
      $tz = new \DateTimeZone($tz_name);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }

    [$sh, $sm] = $start_parts;
    [$eh, $em] = $end_parts;
    $start = new \DateTimeImmutable($date . ' ' . sprintf('%02d:%02d:00', $sh, $sm), $tz);
    $end = new \DateTimeImmutable($date . ' ' . sprintf('%02d:%02d:00', $eh, $em), $tz);

    if ($end <= $start) {
      $form_state->setErrorByName('time_end', $this->t('End time must be after start time.'));
      return;
    }

    $form_state->set('parsed_start', $start);
    $form_state->set('parsed_end', $end);

    $vid = (int) $form_state->getValue('variation_id');
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
    if (!$variation instanceof ProductVariationInterface) {
      $form_state->setErrorByName('variation_id', $this->t('Invalid court selection.'));
      return;
    }

    $quantity = max(1, (int) $form_state->getValue('quantity'));
    $capacity = $this->availabilityManager->getCapacity($variation);
    $mode = $this->availabilityManager->getModeForVariation($variation);
    $seat_quantity = $quantity;
    if ($mode === 'lesson') {
      $seats_per_qty = max(1, $this->availabilityManager->getLessonSeatsPerQty($variation));
      $seat_quantity = $quantity * $seats_per_qty;
    }
    if ($capacity > 0 && $seat_quantity > $capacity) {
      if ($mode === 'lesson') {
        $form_state->setErrorByName('quantity', $this->t(
          'Requested seats (@seats) exceed capacity (@cap). Reduce the quantity.',
          ['@seats' => $seat_quantity, '@cap' => $capacity]
        ));
      }
      else {
        $form_state->setErrorByName('quantity', $this->t(
          'Quantity (@qty) exceeds capacity (@cap). Reduce the quantity.',
          ['@qty' => $quantity, '@cap' => $capacity]
        ));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->eligibleVariationOptions() === []) {
      return;
    }

    /** @var \DateTimeImmutable $start */
    $start = $form_state->get('parsed_start');
    /** @var \DateTimeImmutable $end */
    $end = $form_state->get('parsed_end');
    if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
      $this->messenger()->addError($this->t('Could not parse the time range.'));
      return;
    }

    $vid = (int) $form_state->getValue('variation_id');
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
    if (!$variation instanceof ProductVariationInterface) {
      $this->messenger()->addError($this->t('Invalid court selection.'));
      return;
    }

    $quantity = max(1, (int) $form_state->getValue('quantity'));
    $context = [
      'source' => 'admin_blockout',
      'blockout_state' => $this->availabilityManager->getBlockoutStateForVariation($variation),
      'calendar_selection' => FALSE,
    ];

    if (!$this->availabilityManager->createBlockingEvent($variation, $start, $end, $quantity, $context)) {
      $this->messenger()->addError($this->t('Could not create the blockout. Check Commerce BAT configuration and logs.'));
      return;
    }

    $this->messenger()->addStatus($this->t('Time slot blocked for @label (@start – @end).', [
      '@label' => $variation->label(),
      '@start' => $start->format('Y-m-d H:i'),
      '@end' => $end->format('Y-m-d H:i'),
    ]));
    $form_state->setRebuild();
  }

  /**
   * @return array{0: int, 1: int}|null
   *   Hour and minute, or NULL if invalid.
   */
  private function parseHourMinute(string $hm): ?array {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
      return NULL;
    }
    $h = (int) $m[1];
    $min = (int) $m[2];
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
      return NULL;
    }

    return [$h, $min];
  }

  /**
   * Variations eligible for the public booking page, same filters as listing.
   *
   * @return array<int|string, string>
   *   Options for #type select: id => label.
   */
  private function eligibleVariationOptions(): array {
    $config = $this->config('court_booking.settings');
    $mappings = $config->get('sport_mappings') ?: [];
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $options = [];

    foreach ($mappings as $row) {
      $product_id = (int) ($row['product_id'] ?? 0);
      $legacy_vids = array_map('intval', $row['variation_ids'] ?? []);
      $variation_entities = [];
      if ($product_id > 0) {
        $product = $product_storage->load($product_id);
        if ($product instanceof ProductInterface) {
          foreach ($product->getVariations() as $v) {
            if ($v->isPublished()) {
              $variation_entities[] = $v;
            }
          }
        }
      }
      else {
        foreach ($legacy_vids as $vid) {
          $variation = $variation_storage->load($vid);
          if ($variation && $variation->isPublished()) {
            $variation_entities[] = $variation;
          }
        }
      }

      foreach ($variation_entities as $variation) {
        if (!court_booking_variation_is_configured($variation)) {
          continue;
        }
        if (!court_booking_variation_has_published_court_node($variation)) {
          continue;
        }
        if ($this->availabilityManager->getModeForVariation($variation) !== 'lesson') {
          continue;
        }
        $id = (string) $variation->id();
        if (isset($options[$id])) {
          continue;
        }
        $options[$id] = $variation->getTitle();
      }
    }

    natcasesort($options);

    return $options;
  }

}
