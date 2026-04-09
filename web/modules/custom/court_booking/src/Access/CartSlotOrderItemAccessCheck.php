<?php

namespace Drupal\court_booking\Access;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Ensures the order item belongs to the current user's shopping cart.
 */
final class CartSlotOrderItemAccessCheck implements AccessInterface {

  public function __construct(
    protected CartProviderInterface $cartProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $item = $route_match->getParameter('commerce_order_item');
    if (!$item instanceof OrderItemInterface) {
      return AccessResult::forbidden();
    }
    $order = $item->getOrder();
    if (!$order || !$order->hasField('cart') || !$order->get('cart')->value) {
      return AccessResult::forbidden()
        ->addCacheableDependency($item)
        ->addCacheableDependency($order ?? $item);
    }
    foreach ($this->cartProvider->getCarts($account) as $cart) {
      if ((int) $cart->id() === (int) $order->id()) {
        if (!$item->access('update', $account)) {
          return AccessResult::forbidden()
            ->addCacheableDependency($item)
            ->addCacheableDependency($order);
        }
        return AccessResult::allowed()
          ->addCacheableDependency($item)
          ->addCacheableDependency($order)
          ->addCacheContexts(['user', 'session']);
      }
    }

    return AccessResult::forbidden()
      ->addCacheableDependency($item)
      ->addCacheableDependency($order);
  }

}
