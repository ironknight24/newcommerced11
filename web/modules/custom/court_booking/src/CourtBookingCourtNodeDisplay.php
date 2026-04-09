<?php

namespace Drupal\court_booking;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Variation lookup, cart-backed slot label, and URLs for Court node display.
 */
final class CourtBookingCourtNodeDisplay {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CartProviderInterface $cartProvider,
    protected CurrentStoreInterface $currentStore,
    protected CurrencyFormatterInterface $currencyFormatter,
    protected ConfigFactoryInterface $configFactory,
    protected DateFormatterInterface $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Variation IDs that represent this court node for booking/cart display.
   *
   * Includes all court_booking-mapped variations whose resolved court node
   * matches (same logic as thumbnails), plus any variation with
   * field_content_ref → this node. Avoids picking the wrong variation when
   * multiple SKUs share one court page, which left the hero slot empty.
   *
   * @return int[]
   */
  public function getVariationIdsForCourtNode(NodeInterface $node): array {
    $nid = (int) $node->id();
    if ($nid < 1) {
      return [];
    }
    $ids = [];
    foreach (\court_booking_mapped_variation_ids() as $vid) {
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
      if (!$variation instanceof ProductVariationInterface) {
        continue;
      }
      $court = CourtBookingVariationThumbnail::courtNode($variation);
      if ($court && (int) $court->id() === $nid) {
        $ids[] = (int) $variation->id();
      }
    }
    $query_ids = $this->entityTypeManager->getStorage('commerce_product_variation')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_content_ref.target_id', $nid)
      ->execute();
    foreach ($query_ids as $qid) {
      $ids[] = (int) $qid;
    }
    return array_values(array_unique(array_filter($ids)));
  }

  /**
   * First loaded variation for pricing/imagery when only IDs are needed.
   */
  protected function loadFirstVariationByIds(array $variation_ids): ?ProductVariationInterface {
    if (!$variation_ids) {
      return NULL;
    }
    $sorted = $variation_ids;
    sort($sorted);
    foreach ($sorted as $vid) {
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
      if ($variation instanceof ProductVariationInterface) {
        return $variation;
      }
    }
    return NULL;
  }

  /**
   * Data for hook_preprocess_node (bundle court): slot, price, URLs, cache meta.
   *
   * @return array{
   *   hero_slot: ?string,
   *   hero_background_type: string,
   *   hero_image_url: string,
   *   hero_video_url: string,
   *   price_formatted: string,
   *   cart_url: string,
   *   booking_back_url: string,
   *   cache_tags: string[],
   *   cache_contexts: string[]
   * }
   */
  public function buildPreprocessData(NodeInterface $node, AccountInterface $account): array {
    $cache_tags = $node->getCacheTags();
    $cache_contexts = [
      'user',
      'session',
      'languages:language_interface',
    ];

    $cart_url = Url::fromRoute('commerce_cart.page')->toString();
    $booking_back_url = Url::fromRoute('court_booking.booking_page')->toString();
    if ($node->hasField('field_sport_ref') && !$node->get('field_sport_ref')->isEmpty()) {
      $tid = (int) $node->get('field_sport_ref')->target_id;
      if ($tid > 0) {
        $booking_back_url = Url::fromRoute('court_booking.booking_page', [], [
          'query' => ['sport' => (string) $tid],
        ])->toString();
      }
    }

    $variation_ids = $this->getVariationIdsForCourtNode($node);
    $variation = $this->loadFirstVariationByIds($variation_ids);
    $hero_slot = NULL;
    $price_formatted = '';
    $header = $this->resolveCourtHeaderBackground($node);
    $cache_tags = array_merge($cache_tags, $header['cache_tags']);
    $hero_background_type = $header['type'];
    $hero_image_url = $header['image_url'];
    $hero_video_url = $header['video_url'];

    if ($hero_background_type === 'none') {
      $legacy = $this->heroImageFromNodeLegacyFields($node);
      if ($legacy !== '') {
        $hero_background_type = 'image';
        $hero_image_url = $legacy;
      }
    }

    foreach ($variation_ids as $vid) {
      $v = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
      if ($v instanceof ProductVariationInterface) {
        $cache_tags = array_merge($cache_tags, $v->getCacheTags());
        $product = $v->getProduct();
        if ($product) {
          $cache_tags = array_merge($cache_tags, $product->getCacheTags());
        }
      }
    }

    if ($variation) {
      $p = $variation->getPrice();
      if ($p) {
        $price_formatted = $this->currencyFormatter->format($p->getNumber(), $p->getCurrencyCode());
      }
      if ($hero_background_type === 'none') {
        $fallback = $this->heroImageFromVariation($variation);
        if ($fallback !== '') {
          $hero_background_type = 'image';
          $hero_image_url = $fallback;
        }
      }

      $order_type = (string) ($this->configFactory->get('court_booking.settings')->get('order_type_id') ?: 'default');
      $store = $this->currentStore->getStore();
      if ($store) {
        $cart = $this->cartProvider->getCart($order_type, $store, $account);
        if ($cart) {
          $cache_tags = array_merge($cache_tags, $cart->getCacheTags());
          $match_item = NULL;
          foreach ($cart->getItems() as $item) {
            $pe = $item->getPurchasedEntity();
            if ($pe && in_array((int) $pe->id(), $variation_ids, TRUE)) {
              $match_item = $item;
            }
          }
          if ($match_item instanceof OrderItemInterface) {
            $hero_slot = $this->formatSlotFromOrderItem($match_item, $account);
            $matched = $match_item->getPurchasedEntity();
            if ($matched instanceof ProductVariationInterface) {
              $p = $matched->getPrice();
              if ($p) {
                $price_formatted = $this->currencyFormatter->format($p->getNumber(), $p->getCurrencyCode());
              }
            }
          }
        }
      }
    }

    return [
      'hero_slot' => $hero_slot,
      'hero_background_type' => $hero_background_type,
      'hero_image_url' => $hero_image_url,
      'hero_video_url' => $hero_video_url,
      'price_formatted' => $price_formatted,
      'cart_url' => $cart_url,
      'booking_back_url' => $booking_back_url,
      'cache_tags' => array_values(array_unique($cache_tags)),
      'cache_contexts' => $cache_contexts,
    ];
  }

  /**
   * Formats BAT rental field for hero (display timezone, not URL params).
   */
  protected function formatSlotFromOrderItem(OrderItemInterface $item, AccountInterface $account): ?string {
    if (!$item->hasField('field_cbat_rental_date') || $item->get('field_cbat_rental_date')->isEmpty()) {
      return NULL;
    }
    $field_item = $item->get('field_cbat_rental_date')->first();
    $value = $field_item->value ?? '';
    $end_value = $field_item->end_value ?? '';
    if ($value === '' || $end_value === '') {
      return NULL;
    }
    $tz_id = CourtBookingRegional::effectiveTimeZoneId($this->configFactory, $account);
    $storage_tz = DateTimeItemInterface::STORAGE_TIMEZONE;
    try {
      $start_dt = new \DateTimeImmutable($value, new \DateTimeZone($storage_tz));
      $end_dt = new \DateTimeImmutable($end_value, new \DateTimeZone($storage_tz));
      $start_ts = $start_dt->getTimestamp();
      $end_ts = $end_dt->getTimestamp();
    }
    catch (\Throwable $e) {
      return NULL;
    }
    $date_part = $this->dateFormatter->format($start_ts, 'custom', 'D, d M', $tz_id);
    $time_from = $this->dateFormatter->format($start_ts, 'custom', 'g:i A', $tz_id);
    $time_to = $this->dateFormatter->format($end_ts, 'custom', 'g:i A', $tz_id);

    return $date_part . ' | ' . $time_from . ' - ' . $time_to;
  }

  /**
   * Image/video from field_court_header_background (media), else none.
   *
   * @return array{type: string, image_url: string, video_url: string, cache_tags: string[]}
   */
  protected function resolveCourtHeaderBackground(NodeInterface $node): array {
    $empty = [
      'type' => 'none',
      'image_url' => '',
      'video_url' => '',
      'cache_tags' => [],
    ];
    if (!$node->hasField('field_court_header_background') || $node->get('field_court_header_background')->isEmpty()) {
      return $empty;
    }
    $media = $node->get('field_court_header_background')->entity;
    if (!$media instanceof MediaInterface) {
      return $empty;
    }
    $tags = $media->getCacheTags();
    if ($media->hasField('field_media_video_file') && !$media->get('field_media_video_file')->isEmpty()) {
      $file = $media->get('field_media_video_file')->entity;
      if ($file instanceof FileInterface && $file->getFileUri()) {
        return [
          'type' => 'video',
          'image_url' => '',
          'video_url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          'cache_tags' => $tags,
        ];
      }
    }
    if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
      $file = $media->get('field_media_image')->entity;
      if ($file instanceof FileInterface && $file->getFileUri()) {
        return [
          'type' => 'image',
          'image_url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          'video_url' => '',
          'cache_tags' => $tags,
        ];
      }
    }
    if ($media->hasField('thumbnail') && !$media->get('thumbnail')->isEmpty()) {
      $file = $media->get('thumbnail')->entity;
      if ($file instanceof FileInterface && $file->getFileUri()) {
        return [
          'type' => 'image',
          'image_url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          'video_url' => '',
          'cache_tags' => $tags,
        ];
      }
    }

    return $empty;
  }

  /**
   * Legacy direct file fields on the court node (not media).
   */
  protected function heroImageFromNodeLegacyFields(NodeInterface $node): string {
    foreach (['field_image', 'field_hero_image'] as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }
      $file = $node->get($field_name)->entity;
      if ($file && $file->getFileUri()) {
        return $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }

    return '';
  }

  protected function heroImageFromVariation(ProductVariationInterface $variation): string {
    foreach (['field_image', 'field_images'] as $field_name) {
      if (!$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($variation->get($field_name) as $item) {
        $file = $item->entity;
        if ($file && $file->getFileUri()) {
          return $this->fileUrlGenerator->generateString($file->getFileUri());
        }
      }
    }
    return '';
  }

}
