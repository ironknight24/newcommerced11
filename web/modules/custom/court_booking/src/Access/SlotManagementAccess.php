<?php

namespace Drupal\court_booking\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Slot management: dedicated permission or full court booking admin.
 */
final class SlotManagementAccess {

  public static function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer court booking slot blocks') || $account->hasPermission('administer court booking')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

}
