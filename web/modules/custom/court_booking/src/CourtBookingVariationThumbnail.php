<?php

namespace Drupal\court_booking;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Thumbnail URL for cart/booking cards from the court node (field_court_image).
 */
final class CourtBookingVariationThumbnail {

  /**
   * Resolves a court node for cart display: variation field_content_ref, else product → court ER.
   */
  public static function courtNode(ProductVariationInterface $variation): ?NodeInterface {
    if ($variation->hasField('field_content_ref') && !$variation->get('field_content_ref')->isEmpty()) {
      $entity = $variation->get('field_content_ref')->entity;
      if ($entity instanceof NodeInterface && $entity->bundle() === 'court') {
        return $entity;
      }
    }

    foreach ($variation->getFieldDefinitions() as $name => $definition) {
      if ($name === 'field_content_ref' || !self::fieldReferencesCourtBundle($definition)) {
        continue;
      }
      if (!$variation->get($name)->isEmpty()) {
        $node = $variation->get($name)->entity;
        if ($node instanceof NodeInterface && $node->bundle() === 'court') {
          return $node;
        }
      }
    }

    $product = $variation->getProduct();
    if (!$product instanceof ProductInterface) {
      return NULL;
    }

    foreach ($product->getFieldDefinitions() as $name => $definition) {
      if (!self::fieldReferencesCourtBundle($definition)) {
        continue;
      }
      if (!$product->get($name)->isEmpty()) {
        $node = $product->get($name)->entity;
        if ($node instanceof NodeInterface && $node->bundle() === 'court') {
          return $node;
        }
      }
    }

    return NULL;
  }

  /**
   * Whether a field is an entity reference to node bundle "court".
   */
  private static function fieldReferencesCourtBundle(FieldDefinitionInterface $definition): bool {
    if ($definition->getType() !== 'entity_reference') {
      return FALSE;
    }
    if ($definition->getSetting('target_type') !== 'node') {
      return FALSE;
    }
    $bundles = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
    return is_array($bundles) && isset($bundles['court']);
  }

  /**
   * Absolute URL for the first image file on a court_image media entity.
   */
  public static function imageUrlFromCourtMedia(MediaInterface $media, FileUrlGeneratorInterface $file_url_generator): ?string {
    foreach (['field_media_image', 'field_image'] as $field_name) {
      if (!$media->hasField($field_name) || $media->get($field_name)->isEmpty()) {
        continue;
      }
      $file = $media->get($field_name)->entity;
      if ($file instanceof FileInterface && $file->getFileUri()) {
        return $file_url_generator->generateAbsoluteString($file->getFileUri());
      }
    }

    return NULL;
  }

  /**
   * @return array{url: string, cache_tags: string[]}
   */
  public static function data(ProductVariationInterface $variation, FileUrlGeneratorInterface $file_url_generator): array {
    $cache_tags = [];
    $node = self::courtNode($variation);
    if ($node instanceof NodeInterface) {
      $cache_tags = Cache::mergeTags($cache_tags, $node->getCacheTags());
      if ($node->hasField('field_court_image') && !$node->get('field_court_image')->isEmpty()) {
        $media = $node->get('field_court_image')->entity;
        if ($media instanceof MediaInterface) {
          $cache_tags = Cache::mergeTags($cache_tags, $media->getCacheTags());
          $url = self::imageUrlFromCourtMedia($media, $file_url_generator);
          if ($url !== NULL) {
            return ['url' => $url, 'cache_tags' => $cache_tags];
          }
        }
      }
    }

    foreach (['field_image', 'field_images'] as $field_name) {
      if (!$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($variation->get($field_name) as $item) {
        $file = $item->entity;
        if ($file instanceof FileInterface && $file->getFileUri()) {
          return [
            'url' => $file_url_generator->generateAbsoluteString($file->getFileUri()),
            'cache_tags' => $cache_tags,
          ];
        }
      }
    }

    return ['url' => '', 'cache_tags' => $cache_tags];
  }

}
