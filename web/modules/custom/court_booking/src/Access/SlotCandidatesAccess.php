<?php

namespace Drupal\court_booking\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Slot candidate API: booking page or cart update permission.
 */
final class SlotCandidatesAccess {

  public static function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    if ($account->hasPermission('access court booking page') || $account->hasPermission('use court booking add')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::forbidden()->cachePerPermissions();
  }

}
