<?php

namespace Drupal\court_booking;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;

/**
 * Finds placed Commerce orders that have at least one BAT rental/lesson line item.
 */
final class CourtBookingIntegratedBookingsQuery {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Counts distinct orders (non-draft) with a populated lesson date field, optional filters.
   */
  public function countOrders(?IntegratedBookingsFilters $filters = NULL): int {
    $select = $this->baseSelect();
    if ($select === NULL) {
      return 0;
    }
    $this->applyFilters($select, $filters ?? new IntegratedBookingsFilters());
    $select->addExpression('COUNT(DISTINCT o.order_id)');

    return (int) $select->execute()->fetchField();
  }

  /**
   * Returns order IDs (newest first) for the integrated dashboard pager.
   *
   * @return int[]
   */
  public function getOrderIds(int $limit, int $offset, ?IntegratedBookingsFilters $filters = NULL): array {
    $select = $this->baseSelect();
    if ($select === NULL) {
      return [];
    }
    $this->applyFilters($select, $filters ?? new IntegratedBookingsFilters());
    $select->distinct();
    $select->fields('o', ['order_id']);
    $select->orderBy('o.order_id', 'DESC');
    $select->range((int) $offset, (int) $limit);

    return array_map('intval', $select->execute()->fetchCol());
  }

  /**
   * Distinct workflow states on non-draft orders (for filter dropdown).
   *
   * @return string[]
   *   Machine names, sorted.
   */
  public function getDistinctOrderStates(): array {
    if (!$this->database->schema()->tableExists('commerce_order')) {
      return [];
    }
    $select = $this->database->select('commerce_order', 'o');
    $select->fields('o', ['state']);
    $select->condition('state', 'draft', '<>');
    $select->distinct();
    $select->orderBy('state');
    $states = $select->execute()->fetchCol();
    return array_values(array_filter(array_map('strval', $states)));
  }

  /**
   * Base query: orders with at least one line item that has the lesson date field set.
   */
  protected function baseSelect(): ?Select {
    $table = $this->orderItemDateFieldTable();
    if ($table === NULL) {
      return NULL;
    }
    $query = $this->database->select('commerce_order', 'o');
    $query->join('commerce_order_item', 'oi', 'oi.order_id = o.order_id');
    $query->join($table, 'fd', 'fd.entity_id = oi.order_item_id AND fd.deleted = 0');
    $query->condition('o.state', 'draft', '<>');

    return $query;
  }

  /**
   * Applies optional filters to the base select (must still join fd for slot overlap).
   */
  protected function applyFilters(Select $query, IntegratedBookingsFilters $filters): void {
    $lesson_field = $this->lessonFieldMachineName();
    $valueCol = 'fd.' . $lesson_field . '_value';
    $endCol = 'fd.' . $lesson_field . '_end_value';

    if ($filters->order !== '') {
      $order = $filters->order;
      if (preg_match('/^\d+$/', $order)) {
        $id = (int) $order;
        $like = '%' . $this->database->escapeLike($order) . '%';
        $or = $query->orConditionGroup();
        $or->condition('o.order_id', $id);
        $or->condition('o.order_number', $like, 'LIKE');
        $query->condition($or);
      }
      else {
        $like = '%' . $this->database->escapeLike($order) . '%';
        $query->condition('o.order_number', $like, 'LIKE');
      }
    }

    if ($filters->state !== '') {
      $query->condition('o.state', $filters->state);
    }

    $placed_from_ts = $this->parseYmdToStartOfDayTimestamp($filters->placed_from);
    if ($placed_from_ts !== NULL) {
      $query->condition('o.created', $placed_from_ts, '>=');
    }
    $placed_to_ts = $this->parseYmdToEndOfDayTimestamp($filters->placed_to);
    if ($placed_to_ts !== NULL) {
      $query->condition('o.created', $placed_to_ts, '<=');
    }

    if ($filters->customer !== '') {
      $like = '%' . $this->database->escapeLike($filters->customer) . '%';
      $query->condition('o.mail', $like, 'LIKE');
    }

    if ($filters->line_item !== '') {
      $like = '%' . $this->database->escapeLike($filters->line_item) . '%';
      $query->condition('oi.title', $like, 'LIKE');
    }

    $slot_start = $this->parseYmdToUtcDatetimeString($filters->slot_from, FALSE);
    $slot_end = $this->parseYmdToUtcDatetimeString($filters->slot_to, TRUE);
    if ($slot_start !== NULL && $slot_end !== NULL) {
      $query->where("$valueCol < :ib_slot_end AND $endCol > :ib_slot_start", [
        ':ib_slot_start' => $slot_start,
        ':ib_slot_end' => $slot_end,
      ]);
    }
    elseif ($slot_start !== NULL) {
      $query->where("$endCol > :ib_slot_start", [':ib_slot_start' => $slot_start]);
    }
    elseif ($slot_end !== NULL) {
      $query->where("$valueCol < :ib_slot_end", [':ib_slot_end' => $slot_end]);
    }
  }

  /**
   * Lesson date field machine name from Commerce BAT config.
   */
  protected function lessonFieldMachineName(): string {
    return $this->configFactory->get('commerce_bat.settings')->get('lesson_order_item_date_field') ?: 'field_cbat_rental_date';
  }

  /**
   * Field data table for the Commerce BAT lesson date field on order items.
   */
  protected function orderItemDateFieldTable(): ?string {
    $field_name = $this->lessonFieldMachineName();
    $table = 'commerce_order_item__' . $field_name;
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }

    return $table;
  }

  /**
   * @return int|null
   *   Unix timestamp for start of local day, or NULL if empty/invalid.
   */
  protected function parseYmdToStartOfDayTimestamp(string $ymd): ?int {
    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
      return NULL;
    }
    try {
      $tz = new \DateTimeZone(date_default_timezone_get());
      $dt = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
      return $dt->getTimestamp();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * @return int|null
   *   Unix timestamp for end of local day, or NULL if empty/invalid.
   */
  protected function parseYmdToEndOfDayTimestamp(string $ymd): ?int {
    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
      return NULL;
    }
    try {
      $tz = new \DateTimeZone(date_default_timezone_get());
      $dt = new \DateTimeImmutable($ymd . ' 23:59:59', $tz);
      return $dt->getTimestamp();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Converts a local calendar date to UTC storage string for daterange comparison.
   *
   * @param bool $end_of_day
   *   TRUE: 23:59:59 local; FALSE: 00:00:00 local.
   */
  protected function parseYmdToUtcDatetimeString(string $ymd, bool $end_of_day): ?string {
    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
      return NULL;
    }
    try {
      $tz = new \DateTimeZone(date_default_timezone_get());
      $time = $end_of_day ? '23:59:59' : '00:00:00';
      $local = new \DateTimeImmutable($ymd . ' ' . $time, $tz);
      $utc = $local->setTimezone(new \DateTimeZone('UTC'));
      return $utc->format('Y-m-d H:i:s');
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
