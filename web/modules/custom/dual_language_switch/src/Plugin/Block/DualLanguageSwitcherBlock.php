<?php

namespace Drupal\dual_language_switch\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Single link toggling between default and configured secondary language.
 *
 * If the interface is in a third (or more) enabled language, the block offers
 * a link to the site default language so visitors can return to the primary UI.
 */
#[Block(
  id: 'dual_language_switcher',
  admin_label: new TranslatableMarkup('Dual language switcher'),
  category: new TranslatableMarkup('Multilingual'),
)]
final class DualLanguageSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigurableLanguageManagerInterface $languageManager,
    protected PathMatcherInterface $pathMatcher,
    protected ConfigFactoryInterface $configFactory,
    protected RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('path.matcher'),
      $container->get('config.factory'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $access = $this->languageManager->isMultilingual()
      ? AccessResult::allowed()
      : AccessResult::forbidden();
    return $access->addCacheTags(['config:configurable_language_list']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $settings = $this->configFactory->get('dual_language_switch.settings');
    $secondary = trim((string) $settings->get('secondary_langcode'));
    if ($secondary === '') {
      return $this->buildEmpty();
    }

    $default = $this->languageManager->getDefaultLanguage();
    $default_id = $default->getId();
    $languages = $this->languageManager->getLanguages();
    if (!isset($languages[$secondary]) || $secondary === $default_id) {
      return $this->buildEmpty();
    }

    $current_id = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();

    // Default ↔ secondary; any other enabled language → offer switch to default.
    if ($current_id === $default_id) {
      $target_id = $secondary;
    }
    elseif ($current_id === $secondary) {
      $target_id = $default_id;
    }
    else {
      $target_id = $default_id;
    }

    if ($this->pathMatcher->isFrontPage() || !$this->routeMatch->getRouteObject()) {
      $url = Url::fromRoute('<front>');
    }
    else {
      $url = Url::fromRouteMatch($this->routeMatch);
    }

    $switch = $this->languageManager->getLanguageSwitchLinks(LanguageInterface::TYPE_INTERFACE, $url);
    if (!$switch || empty($switch->links) || !isset($switch->links[$target_id])) {
      return $this->buildEmpty();
    }

    $link = $switch->links[$target_id];
    $fallback_title = $link['title'] ?? '';
    if (is_object($fallback_title) && method_exists($fallback_title, '__toString')) {
      $fallback_title = (string) $fallback_title;
    }
    elseif (!is_string($fallback_title)) {
      $fallback_title = isset($languages[$target_id]) ? $languages[$target_id]->getName() : '';
    }
    $link['title'] = $this->nativeLanguageDisplayName($target_id, $fallback_title);

    $one = [$target_id => $link];
    $build = [
      '#theme' => 'links__language_block',
      '#links' => $one,
      '#attributes' => [
        'class' => [
          'language-switcher-dual',
          'language-switcher-' . $switch->method_id,
        ],
      ],
      '#set_active_class' => TRUE,
    ];

    $cache = BubbleableMetadata::createFromRenderArray($build)
      ->addCacheContexts(['languages:language_interface', 'url.path', 'url.query_args', 'url.site'])
      ->addCacheTags(['config:configurable_language_list', 'config:dual_language_switch.settings']);

    foreach ($one as $link) {
      if (isset($link['url']) && $link['url'] instanceof Url) {
        $cache->addCacheableDependency($link['url']->access(NULL, TRUE));
      }
    }
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Display name for a language in its own locale (e.g. العربية, हिन्दी).
   *
   * @param string $langcode
   *   BCP 47 language code.
   * @param string $fallback
   *   Label from core negotiation if Intl is unavailable.
   */
  private function nativeLanguageDisplayName(string $langcode, string $fallback): string {
    if (extension_loaded('intl') && class_exists(\Locale::class)) {
      $canonical = \Locale::canonicalize($langcode) ?: $langcode;
      $native = \Locale::getDisplayLanguage($canonical, $canonical);
      if ($native !== '') {
        return $native;
      }
    }
    return $fallback;
  }

  /**
   * Empty render array with correct cache metadata.
   *
   * @return array<string, mixed>
   */
  private function buildEmpty(): array {
    $build = [];
    $cache = BubbleableMetadata::createFromRenderArray($build)
      ->addCacheContexts(['languages:language_interface', 'config:dual_language_switch.settings']);
    $cache->applyTo($build);
    return $build;
  }

}
