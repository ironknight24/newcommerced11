<?php

declare(strict_types=1);

namespace Drupal\court_booking;

/**
 * Shared LCM of lesson slot lengths so play durations work for every variation.
 */
final class CourtBookingPlayDurationGrid {

  public static function gcd(int $a, int $b): int {
    $a = abs($a);
    $b = abs($b);
    while ($b !== 0) {
      $t = $b;
      $b = $a % $b;
      $a = $t;
    }

    return max(1, $a);
  }

  public static function lcm(int $a, int $b): int {
    if ($a <= 0 || $b <= 0) {
      return max($a, $b, 1);
    }

    return (int) ($a / self::gcd($a, $b) * $b);
  }

  /**
   * @param int[] $positive_ints
   */
  public static function lcmMany(array $positive_ints): int {
    $vals = array_values(array_unique(array_filter(array_map('intval', $positive_ints), static fn (int $x): bool => $x > 0)));
    if ($vals === []) {
      return 60;
    }
    $l = $vals[0];
    for ($i = 1, $n = count($vals); $i < $n; $i++) {
      $l = self::lcm($l, $vals[$i]);
    }

    return max(1, $l);
  }

  /**
   * TRUE if play length is valid for every slot length (multiple of each).
   *
   * @param int[] $slot_minutes_list
   */
  public static function playMinutesValidForSlots(int $play_minutes, array $slot_minutes_list): bool {
    if ($play_minutes <= 0) {
      return FALSE;
    }
    foreach ($slot_minutes_list as $len) {
      $len = max(1, (int) $len);
      if ($play_minutes % $len !== 0) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
