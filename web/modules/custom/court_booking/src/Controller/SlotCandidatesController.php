<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\court_booking\CourtBookingSlotBooking;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * JSON: staggered slot candidates when buffer &gt; 0 (validates each window server-side).
 */
final class SlotCandidatesController extends ControllerBase {

  public function __construct(
    protected CourtBookingSlotBooking $slotBooking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.slot_booking'),
    );
  }

  /**
   * POST JSON body: ymd, duration_hours, variation_ids[], optional quantity.
   */
  public function candidates(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $ymd = trim((string) ($data['ymd'] ?? ''));
    $duration_hours = max(1, min(24, (int) ($data['duration_hours'] ?? 1)));
    $variation_raw = $data['variation_ids'] ?? [];
    if (!is_array($variation_raw)) {
      throw new BadRequestHttpException('variation_ids must be an array.');
    }
    $variation_ids = array_values(array_unique(array_filter(array_map('intval', $variation_raw))));
    $quantity = max(1, (int) ($data['quantity'] ?? 1));

    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
      return new JsonResponse(['message' => (string) $this->t('Invalid date.')], 400);
    }
    if (!$variation_ids) {
      return new JsonResponse(['slots' => []]);
    }

    $variations = [];
    foreach ($variation_ids as $vid) {
      $v = ProductVariation::load($vid);
      if ($v) {
        $variations[$vid] = $v;
      }
    }
    if (!$variations) {
      return new JsonResponse(['slots' => []]);
    }

    $filtered_ids = [];
    foreach ($variation_ids as $vid) {
      if (isset($variations[$vid]) && court_booking_variation_is_configured($variations[$vid])) {
        $filtered_ids[] = $vid;
      }
    }

    $slots = $filtered_ids
      ? $this->slotBooking->buildBufferSlotCandidatesForDay($filtered_ids, $ymd, $duration_hours, $quantity, $this->currentUser())
      : [];

    return new JsonResponse(['slots' => $slots]);
  }

}
