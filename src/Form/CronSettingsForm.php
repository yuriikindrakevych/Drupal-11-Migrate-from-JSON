<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Форма налаштувань автоматичного імпорту через cron.
 */
class CronSettingsForm extends ConfigFormBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Конструктор.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['migrate_from_drupal7.cron'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'migrate_from_drupal7_cron_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('migrate_from_drupal7.cron');

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Налаштуйте автоматичний імпорт через cron. Імпорт буде оновлювати існуючі терміни та додавати нові.') . '</p>',
    ];

    // Загальні налаштування.
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('Загальні налаштування'),
      '#open' => TRUE,
    ];

    $form['general']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Увімкнути автоматичний імпорт через cron'),
      '#default_value' => $config->get('enabled') ?? FALSE,
      '#description' => $this->t('Якщо увімкнено, імпорт буде запускатися автоматично при спрацюванні cron.'),
    ];

    $form['general']['run_hour'] = [
      '#type' => 'select',
      '#title' => $this->t('Година запуску'),
      '#options' => $this->getHourOptions(),
      '#default_value' => $config->get('run_hour') ?? 3,
      '#description' => $this->t('В яку годину доби запускати імпорт (за серверним часом).'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['general']['interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Інтервал запуску'),
      '#options' => [
        'daily' => $this->t('Щодня'),
        'weekly' => $this->t('Щотижня'),
        'monthly' => $this->t('Щомісяця'),
      ],
      '#default_value' => $config->get('interval') ?? 'daily',
      '#description' => $this->t('Як часто запускати імпорт.'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Налаштування таксономій.
    $form['taxonomies'] = [
      '#type' => 'details',
      '#title' => $this->t('Таксономії для імпорту'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $vocabularies = $this->entityTypeManager
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple();

    if (empty($vocabularies)) {
      $form['taxonomies']['message'] = [
        '#markup' => '<p>' . $this->t('Не знайдено жодного словника таксономії.') . '</p>',
      ];
    }
    else {
      $form['taxonomies']['vocabularies'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Словники для автоматичного імпорту'),
        '#options' => [],
        '#default_value' => $config->get('vocabularies') ?? [],
        '#description' => $this->t('Виберіть словники, які потрібно оновлювати автоматично через cron.'),
      ];

      foreach ($vocabularies as $vocabulary) {
        $form['taxonomies']['vocabularies']['#options'][$vocabulary->id()] = $vocabulary->label() . ' (' . $vocabulary->id() . ')';
      }
    }

    // Статус останнього запуску.
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Статус'),
      '#open' => FALSE,
    ];

    $last_run = $config->get('last_run');
    if ($last_run) {
      $form['status']['last_run'] = [
        '#markup' => '<p><strong>' . $this->t('Останній запуск:') . '</strong> ' .
          \Drupal::service('date.formatter')->format($last_run, 'long') . '</p>',
      ];
    }
    else {
      $form['status']['last_run'] = [
        '#markup' => '<p>' . $this->t('Cron імпорт ще не запускався.') . '</p>',
      ];
    }

    $next_run = $this->calculateNextRun($config);
    if ($next_run) {
      $form['status']['next_run'] = [
        '#markup' => '<p><strong>' . $this->t('Наступний запуск:') . '</strong> ' .
          \Drupal::service('date.formatter')->format($next_run, 'long') . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('migrate_from_drupal7.cron');

    // Зберігаємо налаштування.
    $config
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('run_hour', (int) $form_state->getValue('run_hour'))
      ->set('interval', $form_state->getValue('interval'))
      ->set('vocabularies', array_filter($form_state->getValue('vocabularies', [])))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Отримати опції для вибору години.
   */
  protected function getHourOptions(): array {
    $options = [];
    for ($i = 0; $i < 24; $i++) {
      $options[$i] = sprintf('%02d:00', $i);
    }
    return $options;
  }

  /**
   * Розрахувати час наступного запуску.
   */
  protected function calculateNextRun($config): ?int {
    if (!$config->get('enabled')) {
      return NULL;
    }

    $last_run = $config->get('last_run');
    $run_hour = $config->get('run_hour') ?? 3;
    $interval = $config->get('interval') ?? 'daily';

    $now = \Drupal::time()->getRequestTime();

    // Якщо ще не запускався - розраховуємо від поточного часу.
    if (!$last_run) {
      $base_time = $now;
    }
    else {
      $base_time = $last_run;
    }

    // Розраховуємо наступний запуск.
    $next_run = strtotime("today {$run_hour}:00", $base_time);

    switch ($interval) {
      case 'daily':
        $next_run = strtotime('+1 day', $next_run);
        break;
      case 'weekly':
        $next_run = strtotime('+7 days', $next_run);
        break;
      case 'monthly':
        $next_run = strtotime('+1 month', $next_run);
        break;
    }

    // Якщо розрахований час в минулому - додаємо інтервал.
    while ($next_run < $now) {
      switch ($interval) {
        case 'daily':
          $next_run = strtotime('+1 day', $next_run);
          break;
        case 'weekly':
          $next_run = strtotime('+7 days', $next_run);
          break;
        case 'monthly':
          $next_run = strtotime('+1 month', $next_run);
          break;
      }
    }

    return $next_run;
  }

}
