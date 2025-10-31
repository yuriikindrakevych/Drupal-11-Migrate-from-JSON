<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_from_drupal7\Service\Drupal7ApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Форма для імпорту словників таксономії з Drupal 7.
 */
class ImportTaxonomyForm extends FormBase {

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
   *
   * @param \Drupal\migrate_from_drupal7\Service\Drupal7ApiClient $api_client
   *   API клієнт.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
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
    return 'migrate_from_drupal7_import_taxonomy';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Отримуємо таксономії з Drupal 7.
    $taxonomies = $this->apiClient->getTaxonomies();

    if (empty($taxonomies)) {
      $form['message'] = [
        '#markup' => $this->t('Не вдалося отримати таксономії з Drupal 7. Перевірте налаштування в вкладці "Налаштування".'),
      ];
      return $form;
    }

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Виберіть словники таксономії та їх поля для імпорту. Терміни таксономій будуть імпортовані пізніше.') . '</p>',
    ];

    $form['vocabularies'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($taxonomies as $machine_name => $vocabulary) {
      $form['vocabularies'][$machine_name] = [
        '#type' => 'details',
        '#title' => $vocabulary['name'] . ' (' . $machine_name . ')',
        '#open' => FALSE,
      ];

      $form['vocabularies'][$machine_name]['import'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Імпортувати цей словник'),
        '#default_value' => FALSE,
      ];

      // Формуємо інформацію про багатомовність.
      $multilingual_info = '';
      if (!empty($vocabulary['translatable'])) {
        $translation_modes = [
          'none' => $this->t('Немає'),
          'localize' => $this->t('Локалізація'),
          'fixed_language' => $this->t('Фіксована мова'),
          'translate' => $this->t('Переклад'),
        ];
        $translation_mode = $vocabulary['translation_mode'] ?? 'none';
        $multilingual_info = '<br><strong>' . $this->t('Багатомовний:') . '</strong> ' . $this->t('Так') .
                           '<br><strong>' . $this->t('Режим перекладу:') . '</strong> ' . ($translation_modes[$translation_mode] ?? $translation_mode) .
                           '<br><strong>' . $this->t('i18n режим:') . '</strong> ' . ($vocabulary['i18n_mode'] ?? '0');
        if (!empty($vocabulary['entity_translation_enabled'])) {
          $multilingual_info .= '<br><strong>' . $this->t('Entity Translation:') . '</strong> ' . $this->t('Увімкнено');
        }
      }

      $form['vocabularies'][$machine_name]['info'] = [
        '#type' => 'item',
        '#markup' => '<strong>' . $this->t('VID:') . '</strong> ' . $vocabulary['vid'] . '<br>' .
                     '<strong>' . $this->t('Опис:') . '</strong> ' . ($vocabulary['description'] ?? '') . '<br>' .
                     '<strong>' . $this->t('Ієрархія:') . '</strong> ' . ($vocabulary['hierarchy'] ?? '0') . '<br>' .
                     '<strong>' . $this->t('Вага:') . '</strong> ' . ($vocabulary['weight'] ?? '0') .
                     $multilingual_info,
      ];

      // Поля словника.
      if (!empty($vocabulary['fields'])) {
        $form['vocabularies'][$machine_name]['fields'] = [
          '#type' => 'details',
          '#title' => $this->t('Поля'),
          '#open' => TRUE,
        ];

        $form['vocabularies'][$machine_name]['fields']['select_all'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Вибрати всі поля'),
          '#default_value' => FALSE,
        ];

        foreach ($vocabulary['fields'] as $field_name => $field_info) {
          $form['vocabularies'][$machine_name]['fields'][$field_name] = [
            '#type' => 'checkbox',
            '#title' => $field_info['label'] . ' (' . $field_name . ')',
            '#description' => $this->t('Тип: @type, Обов\'язкове: @required, Кількість значень: @cardinality', [
              '@type' => $field_info['type'],
              '@required' => $field_info['required'] ? $this->t('Так') : $this->t('Ні'),
              '@cardinality' => $field_info['cardinality'] == -1 ? $this->t('Необмежено') : $field_info['cardinality'],
            ]),
            '#default_value' => FALSE,
          ];
        }
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Імпортувати'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue('vocabularies');
    $taxonomies = $this->apiClient->getTaxonomies();

    $operations = [];

    foreach ($values as $machine_name => $vocabulary_data) {
      if (!empty($vocabulary_data['import'])) {
        $selected_fields = [];

        if (!empty($vocabulary_data['fields'])) {
          foreach ($vocabulary_data['fields'] as $field_name => $selected) {
            if ($selected && $field_name !== 'select_all') {
              $selected_fields[] = $field_name;
            }
          }
        }

        $operations[] = [
          [self::class, 'batchImportVocabulary'],
          [$machine_name, $taxonomies[$machine_name], $selected_fields],
        ];
      }
    }

    if (empty($operations)) {
      $this->messenger()->addWarning($this->t('Не вибрано жодного словника для імпорту.'));
      return;
    }

    $batch = [
      'title' => $this->t('Імпорт словників таксономії'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Batch операція для імпорту словника.
   *
   * @param string $machine_name
   *   Машинне ім'я словника.
   * @param array $vocabulary_data
   *   Дані словника з Drupal 7.
   * @param array $selected_fields
   *   Вибрані поля для імпорту.
   * @param array $context
   *   Контекст batch операції.
   */
  public static function batchImportVocabulary($machine_name, array $vocabulary_data, array $selected_fields, array &$context) {
    $entity_type_manager = \Drupal::entityTypeManager();

    try {
      // Перевіряємо чи існує словник.
      $vocabulary = $entity_type_manager->getStorage('taxonomy_vocabulary')->load($machine_name);

      if (!$vocabulary) {
        // Створюємо новий словник.
        $vocabulary = $entity_type_manager->getStorage('taxonomy_vocabulary')->create([
          'vid' => $machine_name,
          'name' => $vocabulary_data['name'],
          'description' => $vocabulary_data['description'] ?? '',
          'weight' => $vocabulary_data['weight'] ?? 0,
        ]);
        $vocabulary->save();

        // Налаштування мультимовності.
        if (!empty($vocabulary_data['translatable'])) {
          self::configureMultilingual($machine_name, $vocabulary_data);
        }

        $context['results']['created'][] = $vocabulary_data['name'];
      }
      else {
        // Оновлюємо мультимовність для існуючого словника.
        if (!empty($vocabulary_data['translatable'])) {
          self::configureMultilingual($machine_name, $vocabulary_data);
        }

        $context['results']['updated'][] = $vocabulary_data['name'];
      }

      // Імпорт полів.
      if (!empty($selected_fields) && !empty($vocabulary_data['fields'])) {
        foreach ($selected_fields as $field_name) {
          if (isset($vocabulary_data['fields'][$field_name])) {
            $field_info = $vocabulary_data['fields'][$field_name];
            self::createField($machine_name, $field_name, $field_info);
          }
        }
      }

      $context['message'] = t('Імпортовано словник: @name', ['@name' => $vocabulary_data['name']]);
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка імпорту словника @name: @message', [
        '@name' => $vocabulary_data['name'],
        '@message' => $e->getMessage(),
      ]);
      $context['results']['errors'][] = $vocabulary_data['name'] . ': ' . $e->getMessage();
    }
  }

  /**
   * Налаштувати мультимовність для словника.
   *
   * @param string $vocabulary_id
   *   ID словника.
   * @param array $vocabulary_data
   *   Дані словника з Drupal 7.
   */
  protected static function configureMultilingual($vocabulary_id, array $vocabulary_data) {
    // Перевіряємо чи доступний модуль content_translation.
    $module_handler = \Drupal::moduleHandler();
    if (!$module_handler->moduleExists('content_translation')) {
      \Drupal::logger('migrate_from_drupal7')->warning(
        'Модуль content_translation не увімкнено. Мультимовність для словника @name не буде налаштована.',
        ['@name' => $vocabulary_data['name']]
      );
      return;
    }

    try {
      // Налаштування мови для taxonomy_term bundle.
      $config = \Drupal::configFactory()->getEditable('language.content_settings.taxonomy_term.' . $vocabulary_id);

      // Встановлюємо налаштування мови.
      $config->set('langcode', 'uk');
      $config->set('status', TRUE);
      $config->set('dependencies', [
        'config' => ['taxonomy.vocabulary.' . $vocabulary_id],
        'module' => ['content_translation'],
      ]);
      $config->set('third_party_settings', [
        'content_translation' => [
          'enabled' => TRUE,
        ],
      ]);
      $config->set('id', 'taxonomy_term.' . $vocabulary_id);
      $config->set('target_entity_type_id', 'taxonomy_term');
      $config->set('target_bundle', $vocabulary_id);
      $config->set('default_langcode', 'site_default');
      $config->set('language_alterable', TRUE);

      $config->save();

      \Drupal::logger('migrate_from_drupal7')->info(
        'Налаштовано мультимовність для словника @name',
        ['@name' => $vocabulary_data['name']]
      );
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error(
        'Помилка налаштування мультимовності для словника @name: @message',
        ['@name' => $vocabulary_data['name'], '@message' => $e->getMessage()]
      );
    }
  }

  /**
   * Створити поле для таксономії.
   *
   * @param string $vocabulary_id
   *   ID словника.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function createField($vocabulary_id, $field_name, array $field_info) {
    // Мапінг типів полів з Drupal 7 на Drupal 11.
    $field_type_map = [
      'text' => 'string',
      'text_long' => 'string_long',
      'text_with_summary' => 'text_with_summary',
      'number_integer' => 'integer',
      'number_decimal' => 'decimal',
      'number_float' => 'float',
      'list_text' => 'list_string',
      'list_integer' => 'list_integer',
      'list_float' => 'list_float',
      'image' => 'image',
      'file' => 'file',
      'taxonomy_term_reference' => 'entity_reference',
      'entityreference' => 'entity_reference',
      'link_field' => 'link',
      'email' => 'email',
      'date' => 'datetime',
      'datestamp' => 'timestamp',
    ];

    $field_type = $field_type_map[$field_info['type']] ?? 'string';

    // Перевіряємо чи існує field storage.
    $field_storage = FieldStorageConfig::loadByName('taxonomy_term', $field_name);

    if (!$field_storage) {
      $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'taxonomy_term',
        'type' => $field_type,
        'cardinality' => $field_info['cardinality'] == -1 ? -1 : (int) $field_info['cardinality'],
      ]);
      $field_storage->save();
    }

    // Перевіряємо чи існує field instance.
    $field = FieldConfig::loadByName('taxonomy_term', $vocabulary_id, $field_name);

    if (!$field) {
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => $vocabulary_id,
        'label' => $field_info['label'],
        'description' => $field_info['description'] ?? '',
        'required' => (bool) $field_info['required'],
      ]);
      $field->save();
    }
  }

  /**
   * Batch завершено.
   *
   * @param bool $success
   *   Чи успішно завершено batch.
   * @param array $results
   *   Результати batch операцій.
   * @param array $operations
   *   Операції, які виконувались.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      if (!empty($results['created'])) {
        $messenger->addStatus(t('Створено словників: @count', ['@count' => count($results['created'])]));
      }
      if (!empty($results['updated'])) {
        $messenger->addStatus(t('Оновлено словників: @count', ['@count' => count($results['updated'])]));
      }
      if (!empty($results['errors'])) {
        $messenger->addError(t('Помилки: @count', ['@count' => count($results['errors'])]));
        foreach ($results['errors'] as $error) {
          $messenger->addError($error);
        }
      }
    }
    else {
      $messenger->addError(t('Виникла помилка під час імпорту.'));
    }
  }

}
