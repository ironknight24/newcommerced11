<?php

namespace Drupal\court_booking\Form;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure sport → product or variation mappings and booking page options.
 */
class SettingsForm extends ConfigFormBase {

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
      '#description' => $this->t('Used to load labels for configured sport term IDs (optional display only).'),
      '#required' => TRUE,
    ];

    $form['sport_mapping_lines'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sport term → product or variations'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 8,
      '#description' => $this->t('One mapping per line. Use <code>TERM_ID|p:PRODUCT_ID</code> to book all published variations on that Commerce product (recommended: one product per sport). Use <code>TERM_ID|VID,VID</code> for explicit variation IDs. Example: <code>3|p:12</code> or <code>3|10,11</code>. Lines starting with # are ignored.'),
      '#required' => FALSE,
    ];

    $form['days_ahead'] = [
      '#type' => 'number',
      '#title' => $this->t('Days ahead on date strip'),
      '#default_value' => (int) ($config->get('days_ahead') ?: 60),
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
    ];

    $days = [
      0 => $this->t('Sunday'),
      1 => $this->t('Monday'),
      2 => $this->t('Tuesday'),
      3 => $this->t('Wednesday'),
      4 => $this->t('Thursday'),
      5 => $this->t('Friday'),
      6 => $this->t('Saturday'),
    ];
    $excluded_raw = array_values(array_unique(array_map('intval', $config->get('excluded_weekdays') ?: [])));
    $excluded_default = !empty($excluded_raw) ? array_combine($excluded_raw, $excluded_raw) : [];
    $form['excluded_weekdays'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Excluded weekdays (greyed on date strip)'),
      '#options' => $days,
      '#default_value' => $excluded_default,
    ];

    $form['booking_hours'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Daily booking hours'),
      '#description' => $this->t('Opening/closing are interpreted in each visitor’s effective timezone from <a href=":url">Regional settings</a> (default time zone, optional per-user time zone), same as the booking date strip and slot labels.', [
        ':url' => '/admin/config/regional/settings',
      ]),
    ];
    $form['booking_hours']['booking_day_start'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Opening time'),
      '#default_value' => $config->get('booking_day_start') ?: '06:00',
      '#size' => 8,
      '#maxlength' => 5,
      '#required' => TRUE,
      '#description' => $this->t('24-hour format, e.g. 06:00'),
    ];
    $form['booking_hours']['booking_day_end'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Closing time'),
      '#default_value' => $config->get('booking_day_end') ?: '23:00',
      '#size' => 8,
      '#maxlength' => 5,
      '#required' => TRUE,
      '#description' => $this->t('Latest moment the booked window may end in local time. The stored rental covers play plus any configured buffer (e.g. 22:00–23:10 with one hour play and 10 minutes buffer requires closing at or after 23:10).'),
    ];

    $form['order_type_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cart order type ID'),
      '#default_value' => $config->get('order_type_id') ?: 'default',
      '#required' => TRUE,
    ];

    $form['max_booking_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum booking duration (hours)'),
      '#default_value' => (int) ($config->get('max_booking_hours') ?: 4),
      '#min' => 1,
      '#max' => 24,
      '#required' => TRUE,
      '#description' => $this->t('Upper limit for a single add-to-cart request. The amenities page duration dropdown offers 1 through this value. The slot length comes from Commerce BAT lesson settings.'),
    ];
    $form['buffer_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Buffer time between bookings (minutes)'),
      '#default_value' => max(0, min(180, (int) ($config->get('buffer_minutes') ?? 0))),
      '#min' => 0,
      '#max' => 180,
      '#required' => TRUE,
      '#description' => $this->t('Non-billable time after each booking’s play window. The cart and BAT rental range store start through play + buffer (e.g. 1 hour play + 10 min buffer → 70 minutes blocked). First bookable start of the day is the opening time; the next starts are opening + N×(play + buffer). With buffer = 0, slots follow the Commerce BAT grid through closing. For a non-zero buffer, set Commerce BAT lesson slot length so it divides (play + buffer) in minutes (e.g. buffer 10 with 1 hour play → use a 10-minute BAT grid so staggered times like 7:10 exist in the availability calendar).'),
    ];
    $form['same_day_cutoff_hm'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Same-day booking cutoff time'),
      '#default_value' => trim((string) ($config->get('same_day_cutoff_hm') ?? '')),
      '#size' => 8,
      '#maxlength' => 5,
      '#required' => FALSE,
      '#description' => $this->t('Optional HH:MM (24-hour, site or user regional TZ). For today only: a slot is allowed only if the full block (play + buffer) ends by this time. Example: 16:00 with a 70-minute block excludes a 15:20 start. Leave empty to disable.'),
    ];
    $blackout_lines = implode("\n", (array) ($config->get('blackout_dates') ?? []));
    $form['blackout_dates'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blackout dates'),
      '#default_value' => $blackout_lines,
      '#rows' => 6,
      '#required' => FALSE,
      '#description' => $this->t('One future date per line in YYYY-MM-DD format. Bookings are blocked on these dates.'),
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
    $form['resource_closures'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Temporary resource closures'),
      '#default_value' => implode("\n", $closure_lines),
      '#rows' => 8,
      '#required' => FALSE,
      '#description' => $this->t('One line per closure: <code>VARIATION_ID|YYYY-MM-DD|YYYY-MM-DD|optional reason</code>. Ranges are merged per variation when overlapping/adjacent. Example: <code>12|2026-05-01|2026-05-10|Court resurfacing</code>.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['booking_day_start', 'booking_day_end'] as $key) {
      $raw = trim((string) $form_state->getValue($key));
      if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
        $form_state->setErrorByName($key, $this->t('Use 24-hour time as HH:MM (e.g. 06:00 or 23:00).'));
      }
    }
    $start = $this->parseHm($form_state->getValue('booking_day_start'));
    $end = $this->parseHm($form_state->getValue('booking_day_end'));
    if ($start !== NULL && $end !== NULL && $end <= $start) {
      $form_state->setErrorByName('booking_day_end', $this->t('Closing time must be after opening time.'));
    }

    $maxH = (int) $form_state->getValue('max_booking_hours');
    if ($maxH < 1 || $maxH > 24) {
      $form_state->setErrorByName('max_booking_hours', $this->t('Maximum booking duration must be between 1 and 24 hours.'));
    }
    $buffer = (int) $form_state->getValue('buffer_minutes');
    if ($buffer < 0 || $buffer > 180) {
      $form_state->setErrorByName('buffer_minutes', $this->t('Buffer time must be between 0 and 180 minutes.'));
    }
    $cutoff = trim((string) $form_state->getValue('same_day_cutoff_hm'));
    if ($cutoff !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff)) {
      $form_state->setErrorByName('same_day_cutoff_hm', $this->t('Use 24-hour time as HH:MM (e.g. 14:00). Leave empty to disable.'));
    }
    $blackout_text = trim((string) $form_state->getValue('blackout_dates'));
    if ($blackout_text !== '') {
      $tz = new \DateTimeZone(date_default_timezone_get());
      $today = new \DateTimeImmutable('today', $tz);
      foreach (preg_split('/\R/', $blackout_text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
          $form_state->setErrorByName('blackout_dates', $this->t('Invalid blackout date format: @date. Use YYYY-MM-DD.', ['@date' => $line]));
          break;
        }
        try {
          $dt = new \DateTimeImmutable($line . ' 00:00:00', $tz);
        }
        catch (\Throwable $e) {
          $form_state->setErrorByName('blackout_dates', $this->t('Invalid blackout date value: @date.', ['@date' => $line]));
          break;
        }
        if ($dt->format('Y-m-d') !== $line) {
          $form_state->setErrorByName('blackout_dates', $this->t('Invalid blackout date value: @date.', ['@date' => $line]));
          break;
        }
        if ($dt <= $today) {
          $form_state->setErrorByName('blackout_dates', $this->t('Blackout dates must be future dates: @date.', ['@date' => $line]));
          break;
        }
      }
    }
    $closure_text = trim((string) $form_state->getValue('resource_closures'));
    if ($closure_text !== '') {
      foreach (preg_split('/\R/', $closure_text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
          continue;
        }
        $parts = array_map('trim', explode('|', $line, 4));
        if (count($parts) < 3) {
          $form_state->setErrorByName('resource_closures', $this->t('Invalid closure line: @line. Use VARIATION_ID|YYYY-MM-DD|YYYY-MM-DD|optional reason.', ['@line' => $line]));
          break;
        }
        [$vid_raw, $start_raw, $end_raw] = $parts;
        if (!ctype_digit($vid_raw) || (int) $vid_raw <= 0) {
          $form_state->setErrorByName('resource_closures', $this->t('Invalid variation ID in closure line: @line', ['@line' => $line]));
          break;
        }
        $variation = ProductVariation::load((int) $vid_raw);
        if (!$variation || !$variation->isPublished()) {
          $form_state->setErrorByName('resource_closures', $this->t('Variation not found/published in closure line: @line', ['@line' => $line]));
          break;
        }
        foreach (['start' => $start_raw, 'end' => $end_raw] as $label => $value) {
          if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $form_state->setErrorByName('resource_closures', $this->t('Invalid @label date format in closure line: @line', ['@label' => $label, '@line' => $line]));
            break 2;
          }
          try {
            $dt = new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone('UTC'));
          }
          catch (\Throwable $e) {
            $form_state->setErrorByName('resource_closures', $this->t('Invalid @label date value in closure line: @line', ['@label' => $label, '@line' => $line]));
            break 2;
          }
          if ($dt->format('Y-m-d') !== $value) {
            $form_state->setErrorByName('resource_closures', $this->t('Invalid @label date value in closure line: @line', ['@label' => $label, '@line' => $line]));
            break 2;
          }
        }
        if ($end_raw < $start_raw) {
          $form_state->setErrorByName('resource_closures', $this->t('Closure end date must be on/after start date: @line', ['@line' => $line]));
          break;
        }
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

    $excluded = [];
    foreach ($form_state->getValue('excluded_weekdays') ?: [] as $wday => $on) {
      if ($on) {
        $excluded[] = (int) $wday;
      }
    }
    $excluded = array_values(array_unique($excluded));
    $blackout_dates = [];
    $blackout_text = trim((string) $form_state->getValue('blackout_dates'));
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
    $resource_closures = $this->normalizeResourceClosures((string) $form_state->getValue('resource_closures'));

    $this->config('court_booking.settings')
      ->set('sport_vocabulary', $form_state->getValue('sport_vocabulary'))
      ->set('sport_mappings', $mappings)
      ->set('days_ahead', (int) $form_state->getValue('days_ahead'))
      ->set('excluded_weekdays', $excluded)
      ->set('booking_day_start', trim((string) $form_state->getValue('booking_day_start')))
      ->set('booking_day_end', trim((string) $form_state->getValue('booking_day_end')))
      ->set('order_type_id', $form_state->getValue('order_type_id'))
      ->set('max_booking_hours', max(1, min(24, (int) $form_state->getValue('max_booking_hours'))))
      ->set('buffer_minutes', max(0, min(180, (int) $form_state->getValue('buffer_minutes'))))
      ->set('same_day_cutoff_hm', trim((string) $form_state->getValue('same_day_cutoff_hm')))
      ->set('blackout_dates', $blackout_dates)
      ->set('resource_closures', $resource_closures)
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
