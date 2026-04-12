<?php

namespace Drupal\court_booking\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access for the integrated bookings dashboard (view permission or settings admin).
 */
final class IntegratedBookingsAccessCheck implements AccessInterface {

  /**
   * Grants access when the user may view the dashboard.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    if ($account->hasPermission('view court booking admin bookings')
      || $account->hasPermission('administer court booking')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

}
