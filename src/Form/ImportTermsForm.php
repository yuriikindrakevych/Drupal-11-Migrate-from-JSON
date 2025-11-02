<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_from_drupal7\Service\Drupal7ApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Форма для імпорту термінів таксономії з Drupal 7.
 *
 * ПРОСТА ЛОГІКА:
 * 1. Отримуємо масив термінів з JSON (зверху вниз)
 * 2. Обробляємо по 10 термінів за раз
 * 3. Для кожного терміну:
 *    - Визначаємо parent через мапінг old_tid → new_tid
 *    - Створюємо термін з правильним parent
 *    - Одразу додаємо переклади
 *    - Зберігаємо в мапінг new tid
 */
class ImportTermsForm extends FormBase {

  /**
   * API клієнт для Drupal 7.
   *
   * @var \Drupal\migrate_from_drupal7\Service\Drupal7ApiClient
   */
  protected $apiClient;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Конструктор.
   */
  public function __construct(Drupal7ApiClient $api_client, EntityTypeManagerInterface $entity_type_manager) {
    $this->apiClient = $api_client;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('migrate_from_drupal7.api_client'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'migrate_from_drupal7_import_terms';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Отримуємо всі існуючі словники в Drupal 11.
    $vocabularies = $this->entityTypeManager
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple();

    if (empty($vocabularies)) {
      $form['message'] = [
        '#markup' => $this->t('Не знайдено жодного словника таксономії.'),
      ];
      return $form;
    }

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Виберіть словники таксономії для імпорту термінів. Терміни імпортуються зверху вниз з збереженням ієрархії.') . '</p>',
    ];

    $form['vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Словники для імпорту'),
      '#options' => [],
      '#description' => $this->t('Виберіть словники, терміни яких потрібно імпортувати.'),
    ];

    foreach ($vocabularies as $vocabulary) {
      $form['vocabularies']['#options'][$vocabulary->id()] = $vocabulary->label() . ' (' . $vocabulary->id() . ')';
    }

    $form['delete_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Видалити існуючі терміни перед імпортом'),
      '#description' => $this->t('УВАГА: Це видалить всі існуючі терміни у вибраних словниках.'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Імпортувати терміни'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_vocabularies = array_filter($form_state->getValue('vocabularies'));
    $delete_existing = $form_state->getValue('delete_existing');

    if (empty($selected_vocabularies)) {
      $this->messenger()->addWarning($this->t('Не вибрано жодного словника для імпорту.'));
      return;
    }

    $operations = [];

    // Додаємо операції видалення якщо потрібно.
    if ($delete_existing) {
      foreach ($selected_vocabularies as $vocabulary_id) {
        $operations[] = [
          [self::class, 'batchDeleteTerms'],
          [$vocabulary_id],
        ];
      }
    }

    // Додаємо операції імпорту.
    foreach ($selected_vocabularies as $vocabulary_id) {
      $operations[] = [
        [self::class, 'batchImportTerms'],
        [$vocabulary_id],
      ];
    }

    $batch = [
      'title' => $this->t('Імпорт термінів таксономії'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Batch операція: видалення існуючих термінів.
   */
  public static function batchDeleteTerms($vocabulary_id, array &$context) {
    $entity_type_manager = \Drupal::entityTypeManager();

    if (!isset($context['sandbox']['progress'])) {
      $term_ids = $entity_type_manager
        ->getStorage('taxonomy_term')
        ->getQuery()
        ->condition('vid', $vocabulary_id)
        ->accessCheck(FALSE)
        ->execute();

      $context['sandbox']['term_ids'] = array_values($term_ids);
      $context['sandbox']['total'] = count($term_ids);
      $context['sandbox']['progress'] = 0;
    }

    // Видаляємо по 20 термінів за раз.
    $ids_to_delete = array_slice($context['sandbox']['term_ids'], $context['sandbox']['progress'], 20);

    if (!empty($ids_to_delete)) {
      $terms = $entity_type_manager->getStorage('taxonomy_term')->loadMultiple($ids_to_delete);
      foreach ($terms as $term) {
        $term->delete();
        $context['sandbox']['progress']++;
      }
    }

    $context['finished'] = $context['sandbox']['total'] > 0
      ? $context['sandbox']['progress'] / $context['sandbox']['total']
      : 1;

    $context['message'] = t('Видалено термінів: @current з @total', [
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Batch операція: імпорт термінів.
   *
   * ПРОСТА ЛОГІКА:
   * 1. Отримуємо масив термінів зверху вниз
   * 2. Обробляємо по 10 за раз
   * 3. Для кожного: створюємо з parent + переклади
   */
  public static function batchImportTerms($vocabulary_id, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $entity_type_manager = \Drupal::entityTypeManager();

    // Ініціалізація при першому запуску.
    if (!isset($context['sandbox']['progress'])) {
      // Отримуємо дані з Drupal 7.
      $data = $api_client->getTermsByVocabulary($vocabulary_id);

      if (empty($data['terms'])) {
        $context['results']['skipped'][] = $vocabulary_id;
        $context['finished'] = 1;
        return;
      }

      // Зберігаємо в sandbox.
      $context['sandbox']['terms'] = $data['terms'];
      $context['sandbox']['total'] = count($data['terms']);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['tid_map'] = [];  // Мапінг: old_tid => new_tid
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['errors'] = 0;

      \Drupal::logger('migrate_from_drupal7')->info(
        'Початок імпорту @count термінів для словника @vocab',
        ['@count' => $context['sandbox']['total'], '@vocab' => $vocabulary_id]
      );
    }

    // Обробляємо по 10 термінів за раз.
    $terms_to_process = array_slice(
      $context['sandbox']['terms'],
      $context['sandbox']['progress'],
      10
    );

    foreach ($terms_to_process as $term_data) {
      try {
        // ГОЛОВНА ЛОГІКА: створюємо термін з parent + переклади.
        $new_term = self::createTermWithTranslations(
          $vocabulary_id,
          $term_data,
          $context['sandbox']['tid_map']
        );

        // Зберігаємо мапінг old_tid => new_tid.
        $context['sandbox']['tid_map'][$term_data['tid']] = $new_term->id();
        $context['sandbox']['imported']++;

        \Drupal::logger('migrate_from_drupal7')->info(
          'Імпортовано: @name (old_tid=@old, new_tid=@new, parent=@parent)',
          [
            '@name' => $term_data['name'],
            '@old' => $term_data['tid'],
            '@new' => $new_term->id(),
            '@parent' => $term_data['parent'] ?? '0',
          ]
        );
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка імпорту терміну @name: @message',
          ['@name' => $term_data['name'], '@message' => $e->getMessage()]
        );
      }

      $context['sandbox']['progress']++;
    }

    // Розрахунок прогресу.
    $context['finished'] = $context['sandbox']['total'] > 0
      ? $context['sandbox']['progress'] / $context['sandbox']['total']
      : 1;

    $context['message'] = t(
      'Імпортовано термінів: @current з @total (успішно: @imported, помилок: @errors)',
      [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['total'],
        '@imported' => $context['sandbox']['imported'],
        '@errors' => $context['sandbox']['errors'],
      ]
    );

    // Зберігаємо результати для фінального повідомлення.
    if ($context['finished'] >= 1) {
      $context['results']['imported'][$vocabulary_id] = $context['sandbox']['imported'];
      $context['results']['errors'][$vocabulary_id] = $context['sandbox']['errors'];
    }
  }

  /**
   * Створити термін з parent і перекладами.
   *
   * ПРОСТА ЛОГІКА:
   * 1. Визначаємо parent через мапінг
   * 2. Створюємо термін
   * 3. Додаємо переклади
   */
  protected static function createTermWithTranslations($vocabulary_id, array $term_data, array $tid_map) {
    // 1. Визначаємо parent.
    $parent_id = NULL;
    if (isset($term_data['parent'])) {
      // Parent може бути рядком "1" або масивом ["1"].
      $parent_value = $term_data['parent'];
      if (is_array($parent_value)) {
        $parent_value = reset($parent_value); // Беремо перший елемент.
      }

      // Якщо parent не "0" - шукаємо в мапінгу.
      if ($parent_value !== '0' && $parent_value !== 0 && $parent_value !== '') {
        $old_parent_tid = $parent_value;
        if (isset($tid_map[$old_parent_tid])) {
          $parent_id = $tid_map[$old_parent_tid];
        }
        else {
          \Drupal::logger('migrate_from_drupal7')->warning(
            'Не знайдено parent @parent для терміну @term',
            ['@parent' => $old_parent_tid, '@term' => $term_data['name']]
          );
        }
      }
    }

    // 2. Створюємо термін.
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_data['name'],
      'description' => [
        'value' => $term_data['description'] ?? '',
        'format' => 'basic_html',
      ],
      'weight' => $term_data['weight'] ?? 0,
      'langcode' => $term_data['language'] ?? 'uk',
      'parent' => $parent_id ? [$parent_id] : [0],
    ]);

    // Імпортуємо поля.
    if (!empty($term_data['fields'])) {
      self::importFields($term, $term_data['fields']);
    }

    $term->save();

    // 3. Додаємо переклади.
    if (!empty($term_data['translations'])) {
      foreach ($term_data['translations'] as $langcode => $translation_data) {
        self::addTranslation($term, $langcode, $translation_data);
      }
    }

    return $term;
  }

  /**
   * Імпортувати поля терміну.
   */
  protected static function importFields(Term $term, array $fields_data) {
    foreach ($fields_data as $field_name => $field_values) {
      if (!$term->hasField($field_name) || empty($field_values)) {
        continue;
      }

      try {
        $processed_values = [];
        foreach ($field_values as $field_value) {
          if (is_array($field_value) && isset($field_value['value'])) {
            $processed_values[] = [
              'value' => $field_value['value'],
              'format' => $field_value['format'] ?? 'basic_html',
            ];
          }
          elseif (is_array($field_value)) {
            $processed_values[] = $field_value;
          }
          else {
            $processed_values[] = ['value' => $field_value];
          }
        }

        if (!empty($processed_values)) {
          $term->set($field_name, $processed_values);
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка імпорту поля @field: @message',
          ['@field' => $field_name, '@message' => $e->getMessage()]
        );
      }
    }
  }

  /**
   * Додати переклад до терміну.
   */
  protected static function addTranslation(Term $term, $langcode, array $translation_data) {
    if (!$term->hasTranslation($langcode)) {
      $term->addTranslation($langcode, [
        'name' => $translation_data['name'],
        'description' => [
          'value' => $translation_data['description'] ?? '',
          'format' => 'basic_html',
        ],
      ]);
      $term->save();
    }
  }

  /**
   * Batch завершено.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      if (!empty($results['imported'])) {
        $total = array_sum($results['imported']);
        $messenger->addStatus(t('Імпортовано термінів: @count', ['@count' => $total]));
      }
      if (!empty($results['errors'])) {
        $total_errors = array_sum($results['errors']);
        if ($total_errors > 0) {
          $messenger->addWarning(t('Помилок: @count', ['@count' => $total_errors]));
        }
      }
    }
    else {
      $messenger->addError(t('Виникла помилка під час імпорту.'));
    }
  }

}
