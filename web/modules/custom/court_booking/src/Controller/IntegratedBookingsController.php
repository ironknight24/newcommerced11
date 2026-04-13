<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\court_booking\CourtBookingIntegratedBookingsQuery;
use Drupal\court_booking\CourtBookingUtcDaterangeFormatter;
use Drupal\court_booking\Form\IntegratedBookingsFilterForm;
use Drupal\court_booking\IntegratedBookingsFilters;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin dashboard: orders with court booking line items + BAT event links.
 */
final class IntegratedBookingsController extends ControllerBase {

  /**
   * Pager size: distinct orders per page (table may show multiple line item rows).
   */
  private const ORDERS_PER_PAGE = 10;

  public function __construct(
    protected CourtBookingIntegratedBookingsQuery $bookingsQuery,
    protected CourtBookingUtcDaterangeFormatter $utcDaterangeFormatter,
    protected DateFormatterInterface $dateFormatter,
    protected PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.integrated_bookings_query'),
      $container->get('court_booking.utc_daterange_formatter'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Builds the integrated bookings table.
   */
  public function content(Request $request): array {
    $filters = IntegratedBookingsFilters::fromRequest($request);

    $limit = self::ORDERS_PER_PAGE;
    $total = $this->bookingsQuery->countOrders($filters);
    $this->pagerManager->createPager($total, $limit);
    $page = $this->pagerManager->findPage();
    $offset = $limit * (int) $page;

    $order_ids = $this->bookingsQuery->getOrderIds($limit, $offset, $filters);
    $order_storage = $this->entityTypeManager()->getStorage('commerce_order');
    /** @var \Drupal\commerce_order\Entity\OrderInterface[] $orders */
    $orders = $order_storage->loadMultiple($order_ids);

    $config = $this->config('commerce_bat.settings');
    $lesson_field = $config->get('lesson_order_item_date_field') ?: 'field_cbat_rental_date';
    $rental_field = $config->get('rental_order_item_date_field') ?: 'field_cbat_rental_date';
    $date_fields = array_unique([$lesson_field, $rental_field]);

    $rows = [];
    foreach ($order_ids as $oid) {
      $order = $orders[$oid] ?? NULL;
      if (!$order instanceof OrderInterface) {
        continue;
      }
      $order_link = $this->plainLinkHtml(Link::fromTextAndUrl(
        '#' . $order->getOrderNumber(),
        $order->toUrl(),
      ));
      $state = $order->getState()->getLabel();
      $placed = $this->dateFormatter->format($order->getCreatedTime(), 'short');
      $customer = $order->getEmail() ?: $this->t('—');

      foreach ($order->getItems() as $item) {
        $row = $this->buildLineItemTableRow($order, $item, $date_fields, $order_link, $state, $placed, $customer);
        if ($row !== NULL) {
          $rows[] = $row;
        }
      }
    }

    $build = [
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Filter by order, state, dates, customer, line item, or booked slot. Pagination is by order (up to @n orders per page); each order may appear on several rows when it has multiple booking line items.', [
          '@n' => self::ORDERS_PER_PAGE,
        ]),
        '#attributes' => ['class' => ['description']],
      ],
      'filter_form' => $this->formBuilder()->getForm(IntegratedBookingsFilterForm::class),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Order'),
          $this->t('State'),
          $this->t('Placed'),
          $this->t('Customer'),
          $this->t('Line item'),
          $this->t('Booked slot'),
          $this->t('BAT calendar'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No placed orders with court booking line items were found.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'tags' => ['commerce_order_list'],
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];

    return $build;
  }

  /**
   * Title callback.
   */
  public function title() {
    return $this->t('Court booking: bookings & overrides');
  }

  /**
   * One table row for a booking line item, or NULL if the line has no BAT date field.
   *
   * @return list<mixed>|null
   */
  protected function buildLineItemTableRow(
    OrderInterface $order,
    OrderItemInterface $item,
    array $date_fields,
    string $order_link,
    string $state,
    string $placed,
    string $customer,
  ): ?array {
    $date_field_name = $this->firstNonEmptyDateField($item, $date_fields);
    if ($date_field_name === NULL) {
      return NULL;
    }

    $variation = $item->getPurchasedEntity();
    $line_label = $variation ? $variation->label() : $item->label();
    $slot_display = $this->utcDaterangeFormatter->formatOrderItemRentalField($item->get($date_field_name));
    $bat_cells = $this->buildBatEventCells((int) $item->id());

    $ops = [$this->plainLinkHtml(Link::fromTextAndUrl($this->t('View order'), $order->toUrl()))];
    $ops = array_merge($ops, $bat_cells['links']);
    if ($this->currentUser()->hasPermission('administer court booking post-checkout slot')) {
      $ops[] = $this->plainLinkHtml(Link::createFromRoute(
        $this->t('Adjust slot'),
        'court_booking.post_checkout_slot_form',
        ['commerce_order_item' => $item->id()],
        ['query' => $this->getDestinationArray()],
      ));
    }

    return [
      ['data' => ['#markup' => $order_link]],
      ['data' => $state],
      ['data' => $placed],
      ['data' => $customer],
      ['data' => $line_label],
      ['data' => $slot_display],
      ['data' => ['#markup' => $bat_cells['summary']]],
      ['data' => ['#markup' => implode(' · ', array_filter($ops))]],
    ];
  }

  /**
   * Link HTML only: avoids embedding GeneratedLink bubbleable metadata in #markup.
   */
  protected function plainLinkHtml(Link $link): string {
    return (string) $link->toString();
  }

  /**
   * @return string|null
   *   Field name, or NULL if none of the configured date fields are set.
   */
  protected function firstNonEmptyDateField(OrderItemInterface $item, array $date_fields): ?string {
    foreach ($date_fields as $fn) {
      if ($item->hasField($fn) && !$item->get($fn)->isEmpty()) {
        return $fn;
      }
    }

    return NULL;
  }

  /**
   * Builds BAT event summary markup and operation links for one order item.
   *
   * @return array{summary: string, links: string[]}
   */
  protected function buildBatEventCells(int $order_item_id): array {
    $storage = $this->entityTypeManager()->getStorage('bat_event');
    try {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('field_order_item_ref', $order_item_id)
        ->sort('id')
        ->execute();
    }
    catch (\Throwable $e) {
      return [
        'summary' => (string) $this->t('Could not load BAT events.'),
        'links' => [],
      ];
    }

    if (!$ids) {
      return [
        'summary' => (string) $this->t('No BAT event (run sync or check Commerce BAT).'),
        'links' => [],
      ];
    }

    $events = $storage->loadMultiple($ids);
    $parts = [];
    $links = [];
    foreach ($events as $event) {
      $formatted = $this->utcDaterangeFormatter->formatBatEventDates($event);
      $parts[] = $this->t('@id: @range', [
        '@id' => $event->id(),
        '@range' => $formatted ?: $this->t('(no dates)'),
      ]);
      if ($event->access('update', $this->currentUser())) {
        $links[] = $this->plainLinkHtml(Link::createFromRoute(
          $this->t('Edit BAT #@id', ['@id' => $event->id()]),
          'entity.bat_event.edit_form',
          ['bat_event' => $event->id()],
          ['query' => $this->getDestinationArray()],
        ));
      }
    }

    return [
      'summary' => implode('; ', $parts),
      'links' => $links,
    ];
  }

}
