<?php

namespace Drupal\court_booking\Access;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access for post-checkout slot adjustment (non-draft orders only).
 */
final class PostCheckoutSlotAccessCheck implements AccessInterface {

  /**
   * Grants access when the user may adjust a placed order line’s booking slot.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    if (!$account->hasPermission('administer court booking post-checkout slot')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    $item = $route_match->getParameter('commerce_order_item');
    if (!$item instanceof OrderItemInterface) {
      return AccessResult::forbidden();
    }

    if (!$item->access('update', $account)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($item);
    }

    $order = $item->getOrder();
    if (!$order || !$order->getState()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($item);
    }

    if ($order->getState()->getId() === 'draft') {
      return AccessResult::forbidden()
        ->addCacheableDependency($item)
        ->addCacheableDependency($order);
    }

    return AccessResult::allowed()
      ->addCacheableDependency($item)
      ->addCacheableDependency($order);
  }

}
