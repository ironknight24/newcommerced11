<?php

namespace Drupal\court_booking\Controller;

use Drupal\court_booking\BatUnitEnsurer;
use Drupal\court_booking\CourtBookingSlotBooking;
use Drupal\court_booking\CourtBookingVariationThumbnail;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST handler: validate slot with AvailabilityManager and add order item.
 */
class BookingAddController extends ControllerBase {

  public function __construct(
    protected BatUnitEnsurer $batUnitEnsurer,
    protected CartManagerInterface $cartManager,
    protected CartProviderInterface $cartProvider,
    protected CurrentStoreInterface $currentStore,
    protected LoggerInterface $logger,
    protected CourtBookingSlotBooking $slotBooking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.bat_unit_ensurer'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_store.current_store'),
      $container->get('logger.channel.court_booking'),
      $container->get('court_booking.slot_booking'),
    );
  }

  /**
   * Adds a lesson slot to the cart as JSON API for fetch().
   */
  public function add(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $variation_id = (int) ($data['variation_id'] ?? 0);
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    $quantity = max(1, (int) ($data['quantity'] ?? 1));

    if (!$variation_id || $start_raw === '' || $end_raw === '') {
      return new JsonResponse(['message' => (string) $this->t('Missing variation or time range.')], 400);
    }

    $variation = ProductVariation::load($variation_id);
    if (!$variation) {
      return new JsonResponse(['message' => (string) $this->t('Invalid product variation.')], 400);
    }

    if (!court_booking_variation_is_configured($variation)) {
      return new JsonResponse(['message' => (string) $this->t('This court is not enabled for the booking page.')], 403);
    }

    if (!court_booking_variation_has_published_court_node($variation)) {
      return new JsonResponse(['message' => (string) $this->t('This court is not available for booking.')], 403);
    }

    $validation = $this->slotBooking->validateLessonSlot(
      $variation,
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

    if (!$this->batUnitEnsurer->ensureBookableUnit($variation)) {
      $this->logger->error('No BAT unit could be resolved for variation @id.', ['@id' => $variation->id()]);
      return new JsonResponse([
        'message' => (string) $this->t('This court is not linked to a bookable unit yet. In Commerce BAT settings, confirm lesson unit bundle and unit type, then run database updates (drush updb).'),
      ], 500);
    }

    $cb_config = $this->config('court_booking.settings');
    $order_type = $cb_config->get('order_type_id') ?: 'default';
    $store = $this->currentStore->getStore();
    if (!$store) {
      return new JsonResponse(['message' => (string) $this->t('No active store is configured.')], 500);
    }

    $account = $this->currentUser();
    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart) {
      $cart = $this->cartProvider->createCart($order_type, $store, $account);
    }

    $order_item = $this->cartManager->createOrderItem($variation, (string) $quantity);
    if (!$order_item->hasField('field_cbat_rental_date')) {
      return new JsonResponse(['message' => (string) $this->t('Order items are missing the rental date field.')], 500);
    }

    $this->slotBooking->applyRentalAndPrice($order_item, $variation, $start, $end, $billing_units);

    try {
      $this->cartManager->addOrderItem($cart, $order_item, FALSE, TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('Court booking add failed: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['message' => (string) $this->t('Could not add to cart. Please try again.')], 500);
    }

    $redirect_url = Url::fromRoute('commerce_cart.page')->setAbsolute()->toString();
    $court_node = CourtBookingVariationThumbnail::courtNode($variation);
    if ($court_node && $court_node->access('view', $account)) {
      $redirect_url = $court_node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }

    return new JsonResponse([
      'status' => 'ok',
      'redirect' => $redirect_url,
    ]);
  }

}
