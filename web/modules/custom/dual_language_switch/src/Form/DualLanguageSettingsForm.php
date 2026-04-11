<?php

namespace Drupal\dual_language_switch\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure secondary language for the dual-language switcher block.
 */
final class DualLanguageSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ConfigurableLanguageManagerInterface $languageManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dual_language_switch_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dual_language_switch.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    $language_manager = $this->languageManager;
    $default_id = $language_manager->getDefaultLanguage()->getId();
    $languages = $language_manager->getLanguages();

    $options = [];
    foreach ($languages as $langcode => $language) {
      if ($langcode === $default_id) {
        continue;
      }
      $options[$langcode] = $language->getName() . ' (' . $langcode . ')';
    }

    $config = $this->config('dual_language_switch.settings');
    $form['secondary_langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Secondary language'),
      '#description' => $this->t(
        'The site default language is @default. The dual-language block shows a single link: from default to this language, or from this language back to default. Other enabled languages are not listed by this block.',
        ['@default' => $languages[$default_id]->getName() . ' (' . $default_id . ')'],
      ),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $config->get('secondary_langcode') ?: '',
      '#required' => FALSE,
    ];

    if ($options === []) {
      $form['secondary_langcode']['#disabled'] = TRUE;
      $form['secondary_langcode']['#description'] = $this->t('Add at least one language besides the default at <a href=":url">Languages</a> before choosing a secondary language.', [
        ':url' => '/admin/config/regional/language',
      ]);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $secondary = (string) $form_state->getValue('secondary_langcode');
    if ($secondary === '') {
      return;
    }

    $language_manager = $this->languageManager;
    $default_id = $language_manager->getDefaultLanguage()->getId();
    if ($secondary === $default_id) {
      $form_state->setErrorByName('secondary_langcode', $this->t('Secondary language must differ from the site default language.'));
    }

    $languages = $language_manager->getLanguages();
    if (!isset($languages[$secondary])) {
      $form_state->setErrorByName('secondary_langcode', $this->t('The selected language is not enabled.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dual_language_switch.settings')
      ->set('secondary_langcode', (string) $form_state->getValue('secondary_langcode'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}

