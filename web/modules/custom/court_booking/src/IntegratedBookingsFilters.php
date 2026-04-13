<?php

namespace Drupal\court_booking;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parsed GET filters for the integrated bookings dashboard.
 */
final class IntegratedBookingsFilters {

  /**
   * Query-string keys (prefix avoids collisions with pager `page`, etc.).
   */
  public const KEY_ORDER = 'ib_order';
  public const KEY_STATE = 'ib_state';
  public const KEY_PLACED_FROM = 'ib_placed_from';
  public const KEY_PLACED_TO = 'ib_placed_to';
  public const KEY_CUSTOMER = 'ib_customer';
  public const KEY_LINE = 'ib_line';
  public const KEY_SLOT_FROM = 'ib_slot_from';
  public const KEY_SLOT_TO = 'ib_slot_to';

  public function __construct(
    public readonly string $order = '',
    public readonly string $state = '',
    public readonly string $placed_from = '',
    public readonly string $placed_to = '',
    public readonly string $customer = '',
    public readonly string $line_item = '',
    public readonly string $slot_from = '',
    public readonly string $slot_to = '',
  ) {}

  /**
   * Builds filters from the current request query.
   */
  public static function fromRequest(Request $request): self {
    $q = $request->query;
    return new self(
      order: trim((string) $q->get(self::KEY_ORDER, '')),
      state: trim((string) $q->get(self::KEY_STATE, '')),
      placed_from: trim((string) $q->get(self::KEY_PLACED_FROM, '')),
      placed_to: trim((string) $q->get(self::KEY_PLACED_TO, '')),
      customer: trim((string) $q->get(self::KEY_CUSTOMER, '')),
      line_item: trim((string) $q->get(self::KEY_LINE, '')),
      slot_from: trim((string) $q->get(self::KEY_SLOT_FROM, '')),
      slot_to: trim((string) $q->get(self::KEY_SLOT_TO, '')),
    );
  }

}
