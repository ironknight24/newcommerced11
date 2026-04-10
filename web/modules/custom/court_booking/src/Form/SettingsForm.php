<?php

namespace Drupal\court_booking\Form;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\court_booking\CourtBookingSportSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure sport → product or variation mappings and booking page options.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CourtBookingSportSettings $sportSettings,
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
      $container->get('entity_type.manager'),
      $container->get('court_booking.sport_settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'court_booking_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['court_booking.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('court_booking.settings');
    $overrides_map = $config->get('sport_booking_overrides') ?: [];
    if (!is_array($overrides_map)) {
      $overrides_map = [];
    }

    $lines = [];
    foreach ($config->get('sport_mappings') ?: [] as $row) {
      $tid = (int) ($row['sport_tid'] ?? 0);
      $pid = (int) ($row['product_id'] ?? 0);
      $vids = array_map('intval', $row['variation_ids'] ?? []);
      if ($tid && $pid > 0) {
        $lines[] = $tid . '|p:' . $pid;
      }
      elseif ($tid && $vids) {
        $lines[] = $tid . '|' . implode(',', $vids);
      }
    }

    $form['sport_vocabulary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sport vocabulary machine name'),
      '#default_value' => $config->get('sport_vocabulary') ?: 'court_type',
      '#description' => $this->t('Machine name of the taxonomy vocabulary whose terms represent sports (e.g. Tennis, Padel). Used to show friendly names next to term IDs. The numeric term ID in each mapping line below is what the booking page actually uses.'),
      '#required' => TRUE,
    ];

    $form['sport_mapping_lines'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sport term → product or variations'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 8,
      '#description' => $this->t('Each sport that appears on the booking page needs one line. Format: <code>TERM_ID|p:PRODUCT_ID</code> (all published variations on that Commerce product—recommended) or <code>TERM_ID|VID,VID</code> (specific variation IDs only). Examples: <code>2|p:15</code> or <code>2|5,6</code>. Comment lines may start with <code>#</code>. After saving, a “Booking rules” tab is added per mapped term so you can optionally override defaults for that sport only.'),
      '#required' => FALSE,
    ];

    $form['order_type_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cart order type ID'),
      '#default_value' => $config->get('order_type_id') ?: 'default',
      '#description' => $this->t('Commerce order type ID for carts that contain court bookings. Leave as <code>default</code> unless you created a separate order type for bookings.'),
      '#required' => TRUE,
    ];

    $form['sport_tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Booking rules'),
    ];

    $form['booking_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Default booking rules (all sports)'),
      '#description' => $this->t('These settings shape the date strip, time choices, and validation on the booking page and cart. They apply to every mapped sport unless you enable “Use custom booking rules” on that sport’s tab. <strong>Weekly open hours and slot length</strong> for each court still come from Commerce BAT (each variation’s availability profile and Commerce BAT configuration); this form adds rules on top (daily window, max length, buffer, blackouts, etc.).'),
      '#group' => 'sport_tabs',
      '#open' => TRUE,
      // Required so nested field #parents stay booking_defaults[...]; without
      // #tree, root #tree FALSE flattens children and getValue([booking_defaults,
      // ...]) returns NULL (validation then sees empty times and max hours 0).
      '#tree' => TRUE,
    ];

    $form['booking_defaults']['days_ahead'] = [
      '#type' => 'number',
      '#title' => $this->t('Days ahead on date strip'),
      '#default_value' => (int) ($config->get('days_ahead') ?: 60),
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
      '#description' => $this->t('Number of calendar days starting from “today” that customers can choose on the booking date strip (1–365).'),
    ];

    $form['booking_defaults']['booking_hours'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Daily booking hours'),
      '#description' => $this->t('Extra window on top of each court’s BAT profile: only slots between opening and closing (in the visitor’s timezone) are offered. Times use <a href=":url">Regional settings</a> (site default and optional per-user timezone), same as labels on the booking page.', [
        ':url' => '/admin/config/regional/settings',
      ]),
    ];
    $form['booking_defaults']['booking_hours']['booking_day_start'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Opening time'),
      '#default_value' => $config->get('booking_day_start') ?: '06:00',
      '#size' => 8,
      '#maxlength' => 5,
      '#required' => TRUE,
      '#description' => $this->t('24-hour clock, exactly five characters: <code>HH:MM</code> with a leading zero if needed (e.g. <code>06:00</code>, not <code>6:00</code>). Hours 00–23.'),
    ];
    $form['booking_defaults']['booking_hours']['booking_day_end'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Closing time'),
      '#default_value' => $config->get('booking_day_end') ?: '23:00',
      '#size' => 8,
      '#maxlength' => 5,
      '#required' => TRUE,
      '#description' => $this->t('Latest local time the <em>entire</em> booked block may end—include buffer (e.g. one hour play + 10 min buffer needs closing at or after the end of that 70-minute window). Same <code>HH:MM</code> format as opening.'),
    ];

    $form['booking_defaults']['max_booking_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum booking duration (hours)'),
      '#default_value' => (int) ($config->get('max_booking_hours') ?: 4),
      '#min' => 1,
      '#max' => 24,
      '#required' => TRUE,
      '#description' => $this->t('Upper cap on <em>play</em> time in one booking (1–24 hours). The duration dropdown lists play lengths that are multiples of the lesson slot grid (LCM of mapped variations’ BAT lesson lengths) up to this cap—not only whole hours. Commerce BAT still defines slot length per variation.'),
    ];
    $form['booking_defaults']['buffer_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Buffer time between bookings (minutes)'),
      '#default_value' => max(0, min(180, (int) ($config->get('buffer_minutes') ?? 0))),
      '#min' => 0,
      '#max' => 180,
      '#required' => TRUE,
      '#description' => $this->t('Extra blocked minutes after play for turnover (0 = none). Pricing is for play time; the stored rental end is play + buffer. With buffer enabled, offered start times repeat every <em>play + buffer</em> minutes from opening until closing. See README.'),
    ];
    $form['booking_defaults']['same_day_cutoff_hm'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Same-day booking cutoff time'),
      '#default_value' => trim((string) ($config->get('same_day_cutoff_hm') ?? '')),
      '#size' => 8,
      '#maxlength' => 5,
      '#required' => FALSE,
      '#description' => $this->t('Optional. For <em>today</em> only: the full block (play + buffer) must end by this local time, <code>HH:MM</code>. Example: cutoff <code>16:00</code> with a 70-minute block disallows a 15:20 start. Leave empty to allow same-day booking until closing.'),
    ];
    $blackout_lines = implode("\n", (array) ($config->get('blackout_dates') ?? []));
    $form['booking_defaults']['blackout_dates'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blackout dates'),
      '#default_value' => $blackout_lines,
      '#rows' => 6,
      '#required' => FALSE,
      '#description' => $this->t('One date per line, <code>YYYY-MM-DD</code>. Each date must be <em>after</em> today when you save. Whole-site closure days for this module (separate from BAT profile blocks).'),
    ];
    $closure_lines = [];
    foreach ((array) ($config->get('resource_closures') ?? []) as $row) {
      $vid = (int) ($row['variation_id'] ?? 0);
      $start_date = trim((string) ($row['start_date'] ?? ''));
      $end_date = trim((string) ($row['end_date'] ?? ''));
      $reason = trim((string) ($row['reason'] ?? ''));
      if ($vid <= 0 || $start_date === '' || $end_date === '') {
        continue;
      }
      $line = $vid . '|' . $start_date . '|' . $end_date;
      if ($reason !== '') {
        $line .= '|' . $reason;
      }
      $closure_lines[] = $line;
    }
    $form['booking_defaults']['resource_closures'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Temporary resource closures'),
      '#default_value' => implode("\n", $closure_lines),
      '#rows' => 8,
      '#required' => FALSE,
      '#description' => $this->t('Per-court date ranges when a specific variation is unavailable. One line: <code>VARIATION_ID|START|END|optional reason</code> (dates <code>YYYY-MM-DD</code>). <code>VARIATION_ID</code> is the numeric Commerce product variation ID. Example: <code>7|2026-04-30|2026-05-01|Court resurfacing</code>. Overlapping or adjacent ranges for the same variation are merged on save.'),
    ];

    $global_rules = $this->sportSettings->getGlobalBookingRules();
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($config->get('sport_mappings') ?: [] as $map_row) {
      $tid = (int) ($map_row['sport_tid'] ?? 0);
      if ($tid <= 0) {
        continue;
      }
      $term = $term_storage->load($tid);
      $label = $term ? $term->getName() : (string) $this->t('Term @id', ['@id' => $tid]);
      $key = (string) $tid;
      $row = isset($overrides_map[$key]) && is_array($overrides_map[$key]) ? $overrides_map[$key] : ($overrides_map[$tid] ?? []);
      $enabled = !empty($row['enabled']);
      $base_for_form = $enabled ? array_merge($global_rules, $row) : $global_rules;

      $form['sport_booking_' . $tid] = [
        '#type' => 'details',
        '#title' => $this->t('Sport: @label (ID @tid)', ['@label' => $label, '@tid' => $tid]),
        '#description' => $this->t('Leave “Use custom booking rules” unchecked to use the default tab for this sport. Enable it only when this sport needs different days ahead, hours, buffer, blackouts, or closures than everyone else.'),
        '#group' => 'sport_tabs',
        // Mirror booking_defaults: keep sport_override[tid][...] structured in
        // FormState so validate/submit receive nested hours, buffer, etc.
        '#tree' => TRUE,
      ];
      $d = 'sport_booking_' . $tid;
      $p = ['sport_override', $tid];

      $form[$d]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use custom booking rules for this sport'),
        '#default_value' => $enabled,
        '#description' => $this->t('When checked, every field below overrides the “Default booking rules” tab for bookings in this sport only.'),
        '#parents' => array_merge($p, ['enabled']),
      ];

      $form[$d]['days_ahead'] = [
        '#type' => 'number',
        '#title' => $this->t('Days ahead on date strip'),
        '#default_value' => (int) ($base_for_form['days_ahead'] ?? 60),
        '#min' => 1,
        '#max' => 365,
        '#required' => TRUE,
        '#description' => $this->t('Same as default tab: how many days from today appear on the date strip (1–365).'),
        '#parents' => array_merge($p, ['days_ahead']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];

      $form[$d]['booking_day_start'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Opening time'),
        '#default_value' => $base_for_form['booking_day_start'] ?? '06:00',
        '#size' => 8,
        '#maxlength' => 5,
        '#required' => TRUE,
        '#description' => $this->t('24-hour clock, exactly five characters: <code>HH:MM</code> with a leading zero if needed (e.g. <code>06:00</code>, not <code>6:00</code>). Hours 00–23.'),
        '#parents' => array_merge($p, ['booking_hours', 'booking_day_start']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];
      $form[$d]['booking_day_end'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Closing time'),
        '#default_value' => $base_for_form['booking_day_end'] ?? '23:00',
        '#size' => 8,
        '#maxlength' => 5,
        '#required' => TRUE,
        '#description' => $this->t('Latest local time the <em>entire</em> booked block may end—include buffer (e.g. one hour play + 10 min buffer needs closing at or after the end of that 70-minute window). Same <code>HH:MM</code> format as opening.'),
        '#parents' => array_merge($p, ['booking_hours', 'booking_day_end']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];

      $form[$d]['max_booking_hours'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum booking duration (hours)'),
        '#default_value' => (int) ($base_for_form['max_booking_hours'] ?? 4),
        '#min' => 1,
        '#max' => 24,
        '#required' => TRUE,
        '#description' => $this->t('Same as default tab: max play hours per booking (1–24); BAT still controls slot grid per variation.'),
        '#parents' => array_merge($p, ['max_booking_hours']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];
      $form[$d]['buffer_minutes'] = [
        '#type' => 'number',
        '#title' => $this->t('Buffer time between bookings (minutes)'),
        '#default_value' => max(0, min(180, (int) ($base_for_form['buffer_minutes'] ?? 0))),
        '#min' => 0,
        '#max' => 180,
        '#required' => TRUE,
        '#description' => $this->t('Same as default tab: non-billable minutes after play; 0 disables buffer. With buffer on, starts every play+buffer minutes from opening.'),
        '#parents' => array_merge($p, ['buffer_minutes']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];
      $form[$d]['same_day_cutoff_hm'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Same-day booking cutoff time'),
        '#default_value' => trim((string) ($base_for_form['same_day_cutoff_hm'] ?? '')),
        '#size' => 8,
        '#maxlength' => 5,
        '#required' => FALSE,
        '#description' => $this->t('Same as default tab: optional <code>HH:MM</code>; for today only, block starts where play+buffer would end after this time. Empty = no same-day cutoff.'),
        '#parents' => array_merge($p, ['same_day_cutoff_hm']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];
      $form[$d]['blackout_dates'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Blackout dates'),
        '#default_value' => implode("\n", (array) ($base_for_form['blackout_dates'] ?? [])),
        '#rows' => 6,
        '#required' => FALSE,
        '#description' => $this->t('Same as default tab: one <code>YYYY-MM-DD</code> per line, each date must be in the future when you save.'),
        '#parents' => array_merge($p, ['blackout_dates']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];
      $closure_lines_sport = [];
      foreach ((array) ($base_for_form['resource_closures'] ?? []) as $crow) {
        $cvid = (int) ($crow['variation_id'] ?? 0);
        $cs = trim((string) ($crow['start_date'] ?? ''));
        $ce = trim((string) ($crow['end_date'] ?? ''));
        $cr = trim((string) ($crow['reason'] ?? ''));
        if ($cvid <= 0 || $cs === '' || $ce === '') {
          continue;
        }
        $ln = $cvid . '|' . $cs . '|' . $ce;
        if ($cr !== '') {
          $ln .= '|' . $cr;
        }
        $closure_lines_sport[] = $ln;
      }
      $form[$d]['resource_closures'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Temporary resource closures'),
        '#default_value' => implode("\n", $closure_lines_sport),
        '#rows' => 8,
        '#required' => FALSE,
        '#description' => $this->t('Same as default tab: <code>VARIATION_ID|START|END|optional reason</code> per line; variation ID must exist and be published.'),
        '#parents' => array_merge($p, ['resource_closures']),
        '#states' => ['visible' => [':input[name="sport_override[' . $tid . '][enabled]"]' => ['checked' => TRUE]]],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $defaults = [
      'booking_day_start' => $form_state->getValue(['booking_defaults', 'booking_hours', 'booking_day_start']),
      'booking_day_end' => $form_state->getValue(['booking_defaults', 'booking_hours', 'booking_day_end']),
      'max_booking_hours' => $form_state->getValue(['booking_defaults', 'max_booking_hours']),
      'buffer_minutes' => $form_state->getValue(['booking_defaults', 'buffer_minutes']),
      'same_day_cutoff_hm' => $form_state->getValue(['booking_defaults', 'same_day_cutoff_hm']),
      'blackout_dates' => $form_state->getValue(['booking_defaults', 'blackout_dates']),
      'resource_closures' => $form_state->getValue(['booking_defaults', 'resource_closures']),
    ];
    $this->validateBookingRuleValues($form_state, $defaults, 'booking_defaults');

    $sport_override = $form_state->getValue('sport_override');
    if (is_array($sport_override)) {
      foreach ($sport_override as $tid => $row) {
        if (!is_array($row) || empty($row['enabled'])) {
          continue;
        }
        $tid = (int) $tid;
        if ($tid <= 0) {
          continue;
        }
        $nested = [
          'booking_day_start' => $row['booking_hours']['booking_day_start'] ?? '',
          'booking_day_end' => $row['booking_hours']['booking_day_end'] ?? '',
          'max_booking_hours' => $row['max_booking_hours'] ?? 4,
          'buffer_minutes' => $row['buffer_minutes'] ?? 0,
          'same_day_cutoff_hm' => $row['same_day_cutoff_hm'] ?? '',
          'blackout_dates' => $row['blackout_dates'] ?? '',
          'resource_closures' => $row['resource_closures'] ?? '',
        ];
        $this->validateBookingRuleValues($form_state, $nested, 'sport_override][' . $tid, TRUE);
      }
    }

    $text = trim((string) $form_state->getValue('sport_mapping_lines'));
    if ($text === '') {
      return;
    }
    foreach (preg_split('/\R/', $text) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      if (!str_contains($line, '|')) {
        $form_state->setErrorByName('sport_mapping_lines', $this->t('Each non-empty line must contain a pipe: TERM_ID|p:PRODUCT_ID or TERM_ID|VID,...'));
        return;
      }
      [$tid, $rest] = array_map('trim', explode('|', $line, 2));
      if (!ctype_digit($tid)) {
        $form_state->setErrorByName('sport_mapping_lines', $this->t('Invalid term ID in line: @line', ['@line' => $line]));
        return;
      }
      if (preg_match('/^p:(\d+)$/i', $rest, $m)) {
        $product = Product::load((int) $m[1]);
        if (!$product) {
          $form_state->setErrorByName('sport_mapping_lines', $this->t('Commerce product not found for line: @line', ['@line' => $line]));
          return;
        }
        continue;
      }
      foreach (preg_split('/\s*,\s*/', $rest, -1, PREG_SPLIT_NO_EMPTY) as $vid) {
        if (!ctype_digit($vid)) {
          $form_state->setErrorByName('sport_mapping_lines', $this->t('Invalid variation ID in line: @line', ['@line' => $line]));
          return;
        }
      }
    }
  }

  /**
   * @param array<string, mixed> $values
   * @param string $error_prefix
   *   e.g. booking_defaults or sport_override][3
   */
  private function validateBookingRuleValues(FormStateInterface $form_state, array $values, string $error_prefix, bool $sport = FALSE): void {
    foreach (['booking_day_start', 'booking_day_end'] as $key) {
      $raw = trim((string) ($values[$key] ?? ''));
      $name = $sport
        ? $error_prefix . '][booking_hours][' . $key
        : $error_prefix . '][booking_hours][' . $key;
      if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
        $form_state->setErrorByName($name, $this->t('Use 24-hour time as HH:MM (e.g. 06:00 or 23:00).'));
      }
    }
    $start = $this->parseHm($values['booking_day_start'] ?? '');
    $end = $this->parseHm($values['booking_day_end'] ?? '');
    if ($start !== NULL && $end !== NULL && $end <= $start) {
      $form_state->setErrorByName($error_prefix . '][booking_hours][booking_day_end', $this->t('Closing time must be after opening time.'));
    }

    $maxH = (int) ($values['max_booking_hours'] ?? 0);
    if ($maxH < 1 || $maxH > 24) {
      $form_state->setErrorByName($error_prefix . '][max_booking_hours', $this->t('Maximum booking duration must be between 1 and 24 hours.'));
    }
    $buffer = (int) ($values['buffer_minutes'] ?? 0);
    if ($buffer < 0 || $buffer > 180) {
      $form_state->setErrorByName($error_prefix . '][buffer_minutes', $this->t('Buffer time must be between 0 and 180 minutes.'));
    }
    $cutoff = trim((string) ($values['same_day_cutoff_hm'] ?? ''));
    if ($cutoff !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff)) {
      $form_state->setErrorByName($error_prefix . '][same_day_cutoff_hm', $this->t('Use 24-hour time as HH:MM (e.g. 14:00). Leave empty to disable.'));
    }
    $blackout_text = trim((string) ($values['blackout_dates'] ?? ''));
    if ($blackout_text !== '') {
      $tz = new \DateTimeZone(date_default_timezone_get());
      $today = new \DateTimeImmutable('today', $tz);
      foreach (preg_split('/\R/', $blackout_text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
          $form_state->setErrorByName($error_prefix . '][blackout_dates', $this->t('Invalid blackout date format: @date. Use YYYY-MM-DD.', ['@date' => $line]));
          break;
        }
        try {
          $dt = new \DateTimeImmutable($line . ' 00:00:00', $tz);
        }
        catch (\Throwable $e) {
          $form_state->setErrorByName($error_prefix . '][blackout_dates', $this->t('Invalid blackout date value: @date.', ['@date' => $line]));
          break;
        }
        if ($dt->format('Y-m-d') !== $line) {
          $form_state->setErrorByName($error_prefix . '][blackout_dates', $this->t('Invalid blackout date value: @date.', ['@date' => $line]));
          break;
        }
        if ($dt <= $today) {
          $form_state->setErrorByName($error_prefix . '][blackout_dates', $this->t('Blackout dates must be future dates: @date.', ['@date' => $line]));
          break;
        }
      }
    }
    $closure_text = trim((string) ($values['resource_closures'] ?? ''));
    if ($closure_text !== '') {
      foreach (preg_split('/\R/', $closure_text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        $parts = array_map('trim', explode('|', $line, 4));
        if (count($parts) < 3) {
          $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Invalid closure line: @line. Use VARIATION_ID|YYYY-MM-DD|YYYY-MM-DD|optional reason.', ['@line' => $line]));
          break;
        }
        [$vid_raw, $start_raw, $end_raw] = $parts;
        if (!ctype_digit($vid_raw) || (int) $vid_raw <= 0) {
          $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Invalid variation ID in closure line: @line', ['@line' => $line]));
          break;
        }
        $variation = ProductVariation::load((int) $vid_raw);
        if (!$variation || !$variation->isPublished()) {
          $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Variation not found/published in closure line: @line', ['@line' => $line]));
          break;
        }
        foreach (['start' => $start_raw, 'end' => $end_raw] as $label => $value) {
          if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Invalid @label date format in closure line: @line', ['@label' => $label, '@line' => $line]));
            break 2;
          }
          try {
            $dt = new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone('UTC'));
          }
          catch (\Throwable $e) {
            $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Invalid @label date value in closure line: @line', ['@label' => $label, '@line' => $line]));
            break 2;
          }
          if ($dt->format('Y-m-d') !== $value) {
            $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Invalid @label date value in closure line: @line', ['@label' => $label, '@line' => $line]));
            break 2;
          }
        }
        if ($end_raw < $start_raw) {
          $form_state->setErrorByName($error_prefix . '][resource_closures', $this->t('Closure end date must be on/after start date: @line', ['@line' => $line]));
          break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mappings = [];
    $text = trim((string) $form_state->getValue('sport_mapping_lines'));
    if ($text !== '') {
      foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        [$tid, $rest] = array_map('trim', explode('|', $line, 2));
        if (preg_match('/^p:(\d+)$/i', $rest, $m)) {
          $mappings[] = [
            'sport_tid' => (int) $tid,
            'product_id' => (int) $m[1],
            'variation_ids' => [],
          ];
          continue;
        }
        $ids = [];
        foreach (preg_split('/\s*,\s*/', $rest, -1, PREG_SPLIT_NO_EMPTY) as $vid) {
          $ids[] = (int) $vid;
        }
        $mappings[] = [
          'sport_tid' => (int) $tid,
          'product_id' => 0,
          'variation_ids' => $ids,
        ];
      }
    }

    $blackout_dates = [];
    $blackout_text = trim((string) $form_state->getValue(['booking_defaults', 'blackout_dates']));
    if ($blackout_text !== '') {
      foreach (preg_split('/\R/', $blackout_text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        $blackout_dates[] = $line;
      }
      $blackout_dates = array_values(array_unique($blackout_dates));
      sort($blackout_dates);
    }
    $resource_closures = $this->normalizeResourceClosures((string) $form_state->getValue(['booking_defaults', 'resource_closures']));

    $sport_booking_overrides = [];
    $sport_override = $form_state->getValue('sport_override');
    if (is_array($sport_override)) {
      foreach ($sport_override as $tid => $row) {
        if (!is_array($row) || empty($row['enabled'])) {
          continue;
        }
        $tid = (int) $tid;
        if ($tid <= 0) {
          continue;
        }
        $bd = [];
        $bt = trim((string) ($row['blackout_dates'] ?? ''));
        if ($bt !== '') {
          foreach (preg_split('/\R/', $bt) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
              continue;
            }
            $bd[] = $line;
          }
          $bd = array_values(array_unique($bd));
          sort($bd);
        }
        $rc = $this->normalizeResourceClosures((string) ($row['resource_closures'] ?? ''));
        $sport_booking_overrides[(string) $tid] = [
          'enabled' => TRUE,
          'days_ahead' => max(1, min(365, (int) ($row['days_ahead'] ?? 60))),
          'booking_day_start' => trim((string) ($row['booking_hours']['booking_day_start'] ?? '06:00')),
          'booking_day_end' => trim((string) ($row['booking_hours']['booking_day_end'] ?? '23:00')),
          'max_booking_hours' => max(1, min(24, (int) ($row['max_booking_hours'] ?? 4))),
          'buffer_minutes' => max(0, min(180, (int) ($row['buffer_minutes'] ?? 0))),
          'same_day_cutoff_hm' => trim((string) ($row['same_day_cutoff_hm'] ?? '')),
          'blackout_dates' => $bd,
          'resource_closures' => $rc,
        ];
      }
    }

    $this->config('court_booking.settings')
      ->set('sport_vocabulary', $form_state->getValue('sport_vocabulary'))
      ->set('sport_mappings', $mappings)
      ->set('days_ahead', (int) $form_state->getValue(['booking_defaults', 'days_ahead']))
      ->set('booking_day_start', trim((string) $form_state->getValue(['booking_defaults', 'booking_hours', 'booking_day_start'])))
      ->set('booking_day_end', trim((string) $form_state->getValue(['booking_defaults', 'booking_hours', 'booking_day_end'])))
      ->set('order_type_id', $form_state->getValue('order_type_id'))
      ->set('max_booking_hours', max(1, min(24, (int) $form_state->getValue(['booking_defaults', 'max_booking_hours']))))
      ->set('buffer_minutes', max(0, min(180, (int) $form_state->getValue(['booking_defaults', 'buffer_minutes']))))
      ->set('same_day_cutoff_hm', trim((string) $form_state->getValue(['booking_defaults', 'same_day_cutoff_hm'])))
      ->set('blackout_dates', $blackout_dates)
      ->set('resource_closures', $resource_closures)
      ->set('sport_booking_overrides', $sport_booking_overrides)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Parses HH:MM to minutes from midnight.
   */
  private function parseHm(mixed $value): ?int {
    $raw = trim((string) $value);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
      return NULL;
    }
    [$h, $m] = array_map('intval', explode(':', $raw, 2));

    return $h * 60 + $m;
  }

  /**
   * Normalizes closure rows and merges overlapping/adjacent ranges per variation.
   *
   * @return array<int, array{variation_id: int, start_date: string, end_date: string, reason: string}>
   */
  private function normalizeResourceClosures(string $text): array {
    $rows = [];
    foreach (preg_split('/\R/', trim($text)) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $parts = array_map('trim', explode('|', $line, 4));
      if (count($parts) < 3) {
        continue;
      }
      [$vid_raw, $start_date, $end_date] = $parts;
      $reason = trim((string) ($parts[3] ?? ''));
      if (!ctype_digit($vid_raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        continue;
      }
      if ($end_date < $start_date) {
        continue;
      }
      $rows[] = [
        'variation_id' => (int) $vid_raw,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'reason' => $reason,
      ];
    }
    if (!$rows) {
      return [];
    }
    usort($rows, static function (array $a, array $b): int {
      $cmp = $a['variation_id'] <=> $b['variation_id'];
      if ($cmp !== 0) {
        return $cmp;
      }
      $cmp = strcmp($a['start_date'], $b['start_date']);
      if ($cmp !== 0) {
        return $cmp;
      }
      return strcmp($a['end_date'], $b['end_date']);
    });
    $merged = [];
    foreach ($rows as $row) {
      if (!$merged) {
        $merged[] = $row;
        continue;
      }
      $last_i = count($merged) - 1;
      $last = $merged[$last_i];
      $adjacent_or_overlap = $row['variation_id'] === $last['variation_id'] && strtotime($row['start_date']) <= strtotime($last['end_date'] . ' +1 day');
      if (!$adjacent_or_overlap) {
        $merged[] = $row;
        continue;
      }
      if ($row['end_date'] > $last['end_date']) {
        $merged[$last_i]['end_date'] = $row['end_date'];
      }
      if ($merged[$last_i]['reason'] === '' && $row['reason'] !== '') {
        $merged[$last_i]['reason'] = $row['reason'];
      }
    }

    return array_values($merged);
  }

}
