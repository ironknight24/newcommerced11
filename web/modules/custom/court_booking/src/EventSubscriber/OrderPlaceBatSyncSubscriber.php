<?php

namespace Drupal\court_booking\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Re-syncs Commerce BAT events after checkout (safety net for missing blockouts).
 *
 * Runs after commerce_bat's OrderPlaceSubscriber. Reloads the order from storage
 * and calls commerce_bat_sync_order_events(), which recreates events from
 * order line item date fields with skip_availability.
 */
class OrderPlaceBatSyncSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Negative priority runs after Commerce BAT's subscriber (default 0).
      'commerce_order.place.post_transition' => ['onOrderPlace', -100],
    ];
  }

  /**
   * Mirrors paid order lines into BAT events when dates + lesson mode are set.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    if (!function_exists('commerce_bat_sync_order_events')) {
      return;
    }
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface || !$order->id()) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $fresh = $storage->load($order->id());
    if (!$fresh instanceof OrderInterface) {
      return;
    }
    $synced = commerce_bat_sync_order_events($fresh);
    if ($synced > 0) {
      $this->logger->info('Court booking: synced @n BAT order line(s) for order @id.', [
        '@n' => $synced,
        '@id' => $fresh->id(),
      ]);
    }
  }

}
