<?php

namespace Drupal\court_booking\Drush;

use Drupal\commerce_order\Entity\OrderInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for court booking operations.
 */
class CourtBookingCommands extends DrushCommands {

  /**
   * Recreate Commerce BAT events from order line items (non-draft orders only).
   *
   * @param string $order_id
   *   Commerce order ID.
   *
   * @command court-booking:sync-bat-order
   * @aliases cb-sync-order
   * @usage court-booking:sync-bat-order 42
   *   Sync BAT events for all bookable line items on order 42.
   */
  public function syncBatOrder(string $order_id): void {
    if (!function_exists('commerce_bat_sync_order_events')) {
      $this->io()->error('commerce_bat module must be enabled.');
      return;
    }
    $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      $this->io()->error(sprintf('Order %s not found.', $order_id));
      return;
    }
    $state = $order->getState()->getId();
    if ($state === 'draft') {
      $this->io()->warning(sprintf('Order %s is still draft; BAT sync only applies to placed orders.', $order_id));
      return;
    }
    $count = commerce_bat_sync_order_events($order);
    $this->io()->success(sprintf('Synced %d order item(s) into BAT events.', $count));
  }

}
