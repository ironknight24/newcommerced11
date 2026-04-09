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
 * Updates BAT rental slot on a cart order item (JSON API).
 */
final class CartSlotUpdateController extends ControllerBase {

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
   * POST JSON body: { "start": "...", "end": "..." } (UTC ISO, same as add).
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
    );
    if (!$validation['ok']) {
      return new JsonResponse(['message' => $validation['message']], $validation['status']);
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    if (!$commerce_order_item->hasField('field_cbat_rental_date')) {
      return new JsonResponse(['message' => (string) $this->t('Order items are missing the rental date field.')], 500);
    }

    try {
      $this->slotBooking->applyRentalAndPrice($commerce_order_item, $purchased, $start, $end, $billing_units);
      $commerce_order_item->save();
      $order = $commerce_order_item->getOrder();
      if ($order) {
        $this->orderRefresh->refresh($order);
        $order->save();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Cart slot update failed: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['message' => (string) $this->t('Could not update booking. Please try again.')], 500);
    }

    return new JsonResponse(['status' => 'ok']);
  }

}
