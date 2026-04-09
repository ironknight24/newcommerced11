<?php

namespace Drupal\court_booking\EventSubscriber;

use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\OrderItemComparisonFieldsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps distinct BAT time slots as separate cart lines when combine is enabled.
 */
class CartOrderItemComparisonSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CartEvents::ORDER_ITEM_COMPARISON_FIELDS => 'onComparisonFields',
    ];
  }

  /**
   * Includes rental/slot datetime in order item merge criteria.
   */
  public function onComparisonFields(OrderItemComparisonFieldsEvent $event): void {
    $order_item = $event->getOrderItem();
    if (!$order_item->hasField('field_cbat_rental_date')) {
      return;
    }
    $fields = $event->getComparisonFields();
    $fields[] = 'field_cbat_rental_date';
    $event->setComparisonFields(array_values(array_unique($fields)));
  }

}
