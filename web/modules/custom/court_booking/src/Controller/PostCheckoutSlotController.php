<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\court_booking\CourtBookingSlotBooking;
use Drupal\Core\Controller\ControllerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Updates a lesson BAT rental slot on a placed (non-draft) order item.
 */
final class PostCheckoutSlotController extends ControllerBase {

  public function __construct(
    protected CourtBookingSlotBooking $slotBooking,
    protected OrderRefreshInterface $orderRefresh,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.slot_booking'),
      $container->get('commerce_order.order_refresh'),
      $container->get('logger.channel.court_booking'),
    );
  }

  /**
   * POST JSON body: { "start": "...", "end": "...", "force": false } (UTC ISO).
   */
  public function updateSlot(Request $request, OrderItemInterface $commerce_order_item): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    if ($start_raw === '' || $end_raw === '') {
      return new JsonResponse(['message' => (string) $this->t('Missing start or end time.')], 400);
    }

    $force = !empty($data['force']);
    $skip_availability = $force && $this->currentUser()->hasPermission('bypass court booking slot availability');

    $purchased = $commerce_order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return new JsonResponse(['message' => (string) $this->t('This line item has no product variation.')], 400);
    }

    $quantity = max(1, (int) $commerce_order_item->getQuantity());
    $validation = $this->slotBooking->validateLessonSlot(
      $purchased,
      (string) $start_raw,
      (string) $end_raw,
      $quantity,
      $this->currentUser(),
      $skip_availability,
    );
    if (!$validation['ok']) {
      return new JsonResponse(['message' => $validation['message']], $validation['status']);
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    $date_field = $this->config('commerce_bat.settings')->get('lesson_order_item_date_field') ?: 'field_cbat_rental_date';
    if (!$commerce_order_item->hasField($date_field) || !$commerce_order_item->get($date_field)->access('edit', $this->currentUser())) {
      return new JsonResponse(['message' => (string) $this->t('Order items are missing the rental date field.')], 500);
    }

    try {
      $this->slotBooking->applyRentalAndPrice($commerce_order_item, $purchased, $start, $end, $billing_units);
      $commerce_order_item->save();
      $order = $commerce_order_item->getOrder();
      if ($order) {
        $this->orderRefresh->refresh($order);
        $order->save();
        if (\function_exists('commerce_bat_sync_order_events')) {
          \commerce_bat_sync_order_events($order);
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Post-checkout slot update failed: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['message' => (string) $this->t('Could not update booking. Please try again.')], 500);
    }

    return new JsonResponse(['status' => 'ok']);
  }

}
