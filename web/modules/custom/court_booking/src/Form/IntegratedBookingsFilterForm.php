<?php

namespace Drupal\court_booking\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\court_booking\CourtBookingIntegratedBookingsQuery;
use Drupal\court_booking\IntegratedBookingsFilters;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET filters for the bookings & overrides dashboard.
 */
final class IntegratedBookingsFilterForm extends FormBase {

  public function __construct(
    protected CourtBookingIntegratedBookingsQuery $bookingsQuery,
    protected Request $request,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.integrated_bookings_query'),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'court_booking_integrated_bookings_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $q = $this->request->query;
    $defaults = [
      IntegratedBookingsFilters::KEY_ORDER => (string) $q->get(IntegratedBookingsFilters::KEY_ORDER, ''),
      IntegratedBookingsFilters::KEY_STATE => (string) $q->get(IntegratedBookingsFilters::KEY_STATE, ''),
      IntegratedBookingsFilters::KEY_PLACED_FROM => (string) $q->get(IntegratedBookingsFilters::KEY_PLACED_FROM, ''),
      IntegratedBookingsFilters::KEY_PLACED_TO => (string) $q->get(IntegratedBookingsFilters::KEY_PLACED_TO, ''),
      IntegratedBookingsFilters::KEY_CUSTOMER => (string) $q->get(IntegratedBookingsFilters::KEY_CUSTOMER, ''),
      IntegratedBookingsFilters::KEY_LINE => (string) $q->get(IntegratedBookingsFilters::KEY_LINE, ''),
      IntegratedBookingsFilters::KEY_SLOT_FROM => (string) $q->get(IntegratedBookingsFilters::KEY_SLOT_FROM, ''),
      IntegratedBookingsFilters::KEY_SLOT_TO => (string) $q->get(IntegratedBookingsFilters::KEY_SLOT_TO, ''),
    ];

    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#action'] = Url::fromRoute('court_booking.integrated_bookings')->toString();
    $form['#attributes']['class'][] = 'court-booking-integrated-bookings-filter';

    // Top-level keys so GET query strings are ib_order=… (not nested under filters[…]).
    $form[IntegratedBookingsFilters::KEY_ORDER] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order'),
      '#size' => 12,
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_ORDER],
    ];

    $state_options = ['' => $this->t('- Any -')];
    foreach ($this->bookingsQuery->getDistinctOrderStates() as $state) {
      $state_options[$state] = $state;
    }
    $form[IntegratedBookingsFilters::KEY_STATE] = [
      '#type' => 'select',
      '#title' => $this->t('State'),
      '#options' => $state_options,
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_STATE],
    ];

    $form[IntegratedBookingsFilters::KEY_PLACED_FROM] = [
      '#type' => 'date',
      '#title' => $this->t('Placed from'),
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_PLACED_FROM],
    ];
    $form[IntegratedBookingsFilters::KEY_PLACED_TO] = [
      '#type' => 'date',
      '#title' => $this->t('Placed to'),
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_PLACED_TO],
    ];

    $form[IntegratedBookingsFilters::KEY_CUSTOMER] = [
      '#type' => 'textfield',
      '#title' => $this->t('Customer'),
      '#size' => 24,
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_CUSTOMER],
    ];

    $form[IntegratedBookingsFilters::KEY_LINE] = [
      '#type' => 'textfield',
      '#title' => $this->t('Line item'),
      '#size' => 24,
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_LINE],
    ];

    $form[IntegratedBookingsFilters::KEY_SLOT_FROM] = [
      '#type' => 'date',
      '#title' => $this->t('Booked slot from'),
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_SLOT_FROM],
    ];
    $form[IntegratedBookingsFilters::KEY_SLOT_TO] = [
      '#type' => 'date',
      '#title' => $this->t('Booked slot to'),
      '#default_value' => $defaults[IntegratedBookingsFilters::KEY_SLOT_TO],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply filters'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('court_booking.integrated_bookings'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET form: submission is not used; query string is built by the browser.
  }

}
