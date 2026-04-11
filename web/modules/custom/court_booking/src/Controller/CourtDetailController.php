<?php

namespace Drupal\court_booking\Controller;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\court_booking\CourtBookingVariationThumbnail;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Per-court information page linked from the amenities UI.
 */
class CourtDetailController extends ControllerBase {

  public function __construct(
    protected AvailabilityManagerInterface $availabilityManager,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_bat.availability_manager'),
      $container->get('commerce_price.currency_formatter'),
    );
  }

  /**
   * Access: variation must be enabled in Court booking settings.
   */
  public function access(ProductVariationInterface $commerce_product_variation, AccountInterface $account): AccessResult {
    if (!court_booking_variation_is_configured($commerce_product_variation)) {
      return AccessResult::forbidden();
    }
    if (!court_booking_variation_has_published_court_node($commerce_product_variation)) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account, 'access court booking page');
  }

  /**
   * Page render for a single court variation.
   */
  public function content(ProductVariationInterface $commerce_product_variation): array|RedirectResponse {
    $variation = $commerce_product_variation;
    $court_node = CourtBookingVariationThumbnail::courtNode($variation);
    if ($court_node && $court_node->access('view')) {
      return new RedirectResponse($court_node->toUrl()->setAbsolute()->toString(), 302);
    }
    $price_str = '';
    $p = $variation->getPrice();
    if ($p) {
      $price_str = $this->currencyFormatter->format($p->getNumber(), $p->getCurrencyCode());
    }

    $images = [];
    $file_url_generator = $this->fileUrlGenerator();
    foreach (['field_image', 'field_images'] as $field_name) {
      if (!$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($variation->get($field_name) as $item) {
        $file = $item->entity;
        if ($file && $file->getFileUri()) {
          $images[] = [
            'url' => $file_url_generator->generateString($file->getFileUri()),
            'alt' => $item->alt ?? '',
          ];
        }
      }
    }

    $description = '';
    if ($variation->hasField('body') && !$variation->get('body')->isEmpty()) {
      $body = $variation->get('body')->first();
      $description = check_markup($body->value ?? '', $body->format ?: 'basic_html');
    }

    $unit_lines = [];
    $unit = $this->availabilityManager->getUnitForVariation($variation);
    if ($unit) {
      $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
      foreach ([
        'field_unit_court_type' => $this->t('Type'),
        'field_unit_court_surface' => $this->t('Surface'),
      ] as $field => $label) {
        if (!$unit->hasField($field) || $unit->get($field)->isEmpty()) {
          continue;
        }
        $tid = (int) $unit->get($field)->target_id;
        $term = $term_storage->load($tid);
        if ($term) {
          $unit_lines[] = [
            'label' => (string) $label,
            'value' => $term->label(),
          ];
        }
      }
      if ($unit->hasField('field_unit_amenities') && !$unit->get('field_unit_amenities')->isEmpty()) {
        $names = [];
        foreach ($unit->get('field_unit_amenities') as $item) {
          $term = $term_storage->load((int) $item->target_id);
          if ($term) {
            $names[] = $term->label();
          }
        }
        if ($names) {
          $unit_lines[] = [
            'label' => (string) $this->t('Amenities'),
            'value' => implode(', ', $names),
          ];
        }
      }
    }

    $sport_tid = court_booking_sport_tid_for_variation($variation);
    $book_query = [
      'variation' => $variation->id(),
    ];
    if ($sport_tid) {
      $book_query['sport'] = (string) $sport_tid;
    }
    $book_url = Url::fromRoute('court_booking.booking_page', [], [
      'query' => $book_query,
    ])->toString();

    return [
      '#theme' => 'court_booking_court_detail',
      '#variation_label' => $variation->getTitle(),
      '#price_formatted' => $price_str,
      '#images' => $images,
      '#description' => $description,
      '#unit_lines' => $unit_lines,
      '#book_url' => $book_url,
      '#amenities_url' => Url::fromRoute('court_booking.booking_page')->toString(),
      '#attached' => [
        'library' => ['misk/global'],
      ],
      '#cache' => [
        'tags' => array_merge($variation->getCacheTags(), $this->config('court_booking.settings')->getCacheTags()),
        'contexts' => ['languages:language_interface'],
      ],
    ];
  }

  /**
   * Dynamic title callback.
   */
  public function title(EntityInterface $commerce_product_variation): string {
    if ($commerce_product_variation instanceof ProductVariationInterface) {
      return $commerce_product_variation->getTitle();
    }
    return (string) $this->t('Court');
  }

}
