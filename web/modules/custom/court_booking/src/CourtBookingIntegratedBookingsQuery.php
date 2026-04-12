<?php

namespace Drupal\court_booking;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Finds placed Commerce orders that have at least one BAT rental/lesson line item.
 */
final class CourtBookingIntegratedBookingsQuery {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Counts distinct orders (non-draft) with a populated lesson/rental date field.
   */
  public function countOrders(): int {
    $table = $this->orderItemDateFieldTable();
    if ($table === NULL) {
      return 0;
    }
    $query = $this->database->select('commerce_order', 'o');
    $query->join('commerce_order_item', 'oi', 'oi.order_id = o.order_id');
    $query->join($table, 'fd', 'fd.entity_id = oi.order_item_id AND fd.deleted = 0');
    $query->condition('o.state', 'draft', '<>');
    $query->addExpression('COUNT(DISTINCT o.order_id)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns order IDs (newest first) for the integrated dashboard pager.
   *
   * @return int[]
   */
  public function getOrderIds(int $limit, int $offset): array {
    $table = $this->orderItemDateFieldTable();
    if ($table === NULL) {
      return [];
    }

    $sub = $this->database->select('commerce_order', 'o');
    $sub->join('commerce_order_item', 'oi', 'oi.order_id = o.order_id');
    $sub->join($table, 'fd', 'fd.entity_id = oi.order_item_id AND fd.deleted = 0');
    $sub->condition('o.state', 'draft', '<>');
    $sub->distinct();
    $sub->fields('o', ['order_id']);
    $sub->orderBy('o.order_id', 'DESC');

    $sub->range((int) $offset, (int) $limit);

    return array_map('intval', $sub->execute()->fetchCol());
  }

  /**
   * Field data table for the Commerce BAT lesson date field on order items.
   */
  protected function orderItemDateFieldTable(): ?string {
    $field_name = $this->configFactory->get('commerce_bat.settings')->get('lesson_order_item_date_field') ?: 'field_cbat_rental_date';
    $table = 'commerce_order_item__' . $field_name;
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }

    return $table;
  }

}
