<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_from_drupal7\Service\Drupal7ApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\node\Entity\NodeType;
use Drupal\language\Entity\ContentLanguageSettings;

/**
 * Форма для імпорту типів матеріалів з Drupal 7.
 */
class ImportContentTypesForm extends FormBase {

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
    return 'migrate_from_drupal7_import_content_types';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Отримуємо типи контенту з Drupal 7.
    $content_types = $this->apiClient->getContentTypes();

    if (empty($content_types)) {
      $form['message'] = [
        '#markup' => $this->t('Не вдалося отримати типи контенту з Drupal 7. Перевірте налаштування в вкладці "Налаштування".'),
      ];
      return $form;
    }

    // Прикріплюємо JavaScript бібліотеку.
    $form['#attached']['library'][] = 'migrate_from_drupal7/import-taxonomy';

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Виберіть типи матеріалів та їх поля для імпорту.') . '</p>',
    ];

    $form['content_types'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($content_types as $type_id => $content_type) {
      $form['content_types'][$type_id] = [
        '#type' => 'details',
        '#title' => $content_type['name'] . ' (' . $type_id . ')',
        '#open' => FALSE,
      ];

      $form['content_types'][$type_id]['import'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Імпортувати цей тип матеріалу'),
        '#default_value' => FALSE,
      ];

      $form['content_types'][$type_id]['info'] = [
        '#type' => 'item',
        '#markup' => '<strong>' . $this->t('Опис:') . '</strong> ' . ($content_type['description'] ?? '') . '<br>' .
                     '<strong>' . $this->t('Базовий тип:') . '</strong> ' . ($content_type['base'] ?? '') . '<br>' .
                     '<strong>' . $this->t('Кастомний:') . '</strong> ' . ($content_type['custom'] ? $this->t('Так') : $this->t('Ні')),
      ];

      // Поля типу контенту.
      if (!empty($content_type['fields'])) {
        $form['content_types'][$type_id]['fields'] = [
          '#type' => 'details',
          '#title' => $this->t('Поля'),
          '#open' => TRUE,
        ];

        $form['content_types'][$type_id]['fields']['select_all'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Вибрати всі поля'),
          '#default_value' => FALSE,
        ];

        foreach ($content_type['fields'] as $field_name => $field_info) {
          $description = $this->t('Тип: @type, Обов\'язкове: @required, Кількість значень: @cardinality', [
            '@type' => $field_info['type'],
            '@required' => $field_info['required'] ? $this->t('Так') : $this->t('Ні'),
            '@cardinality' => $field_info['cardinality'] == -1 ? $this->t('Необмежено') : $field_info['cardinality'],
          ]);

          // Додаємо інформацію про vocabulary для taxonomy_term_reference.
          if ($field_info['type'] == 'taxonomy_term_reference') {
            if (!empty($field_info['vocabularies']) && is_array($field_info['vocabularies'])) {
              $vocab_names = array_map(function($vocab) {
                return $vocab['name'] . ' (' . $vocab['machine_name'] . ')';
              }, $field_info['vocabularies']);
              $description .= '<br><strong>' . $this->t('Словники: @vocabularies', ['@vocabularies' => implode(', ', $vocab_names)]) . '</strong>';
            }
            else {
              $description .= '<br><span style="color: red;">' . $this->t('⚠ УВАГА: Vocabularies не знайдено! Поле не буде прив\'язане до словника. Переконайтесь що в JSON є поле "vocabularies" з масивом словників.') . '</span>';
            }
          }

          $form['content_types'][$type_id]['fields'][$field_name] = [
            '#type' => 'checkbox',
            '#title' => $field_info['label'] . ' (' . $field_name . ')',
            '#description' => $description,
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
    $values = $form_state->getValue('content_types');
    $content_types = $this->apiClient->getContentTypes();

    $operations = [];

    foreach ($values as $type_id => $content_type_data) {
      if (!empty($content_type_data['import'])) {
        $selected_fields = [];

        if (!empty($content_type_data['fields'])) {
          foreach ($content_type_data['fields'] as $field_name => $selected) {
            if ($selected && $field_name !== 'select_all') {
              $selected_fields[] = $field_name;
            }
          }
        }

        $operations[] = [
          [self::class, 'batchImportContentType'],
          [$type_id, $content_types[$type_id], $selected_fields],
        ];
      }
    }

    if (empty($operations)) {
      $this->messenger()->addWarning($this->t('Не вибрано жодного типу матеріалу для імпорту.'));
      return;
    }

    $batch = [
      'title' => $this->t('Імпорт типів матеріалів'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Batch операція для імпорту типу контенту.
   *
   * @param string $type_id
   *   ID типу контенту.
   * @param array $content_type_data
   *   Дані типу контенту з Drupal 7.
   * @param array $selected_fields
   *   Вибрані поля для імпорту.
   * @param array $context
   *   Контекст batch операції.
   */
  public static function batchImportContentType($type_id, array $content_type_data, array $selected_fields, array &$context) {
    $entity_type_manager = \Drupal::entityTypeManager();

    try {
      // Перевіряємо чи існує тип контенту.
      $node_type = NodeType::load($type_id);
      $is_new = FALSE;

      if (!$node_type) {
        // Створюємо новий тип контенту.
        $node_type = NodeType::create([
          'type' => $type_id,
          'name' => $content_type_data['name'],
          'description' => $content_type_data['description'] ?? '',
        ]);
        $node_type->save();
        $is_new = TRUE;

        $context['results']['created'][] = $content_type_data['name'];
      }
      else {
        $context['results']['updated'][] = $content_type_data['name'];
      }

      // ВАЖЛИВО: Налаштовуємо багатомовність для типу контенту.
      self::configureContentTranslation($type_id, $content_type_data);

      // Імпорт полів.
      if (!empty($selected_fields) && !empty($content_type_data['fields'])) {
        foreach ($selected_fields as $field_name) {
          if (isset($content_type_data['fields'][$field_name])) {
            $field_info = $content_type_data['fields'][$field_name];
            self::createField($type_id, $field_name, $field_info);
          }
        }
      }

      $context['message'] = t('Імпортовано тип матеріалу: @name', ['@name' => $content_type_data['name']]);
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка імпорту типу контенту @name: @message', [
        '@name' => $content_type_data['name'],
        '@message' => $e->getMessage(),
      ]);
      $context['results']['errors'][] = $content_type_data['name'] . ': ' . $e->getMessage();
    }
  }

  /**
   * Налаштувати багатомовність для типу контенту.
   *
   * @param string $type_id
   *   ID типу контенту.
   * @param array $content_type_data
   *   Дані типу контенту з Drupal 7.
   */
  protected static function configureContentTranslation($type_id, array $content_type_data) {
    $multilingual_enabled = $content_type_data['multilingual_enabled'] ?? false;
    $translation_mode = $content_type_data['translation_mode'] ?? '';

    if (!$multilingual_enabled || $translation_mode !== 'enabled_with_translation') {
      return;
    }

    $module_handler = \Drupal::service('module_handler');
    if (!$module_handler->moduleExists('content_translation')) {
      return;
    }

    try {
      $config = ContentLanguageSettings::loadByEntityTypeBundle('node', $type_id);
      if (!$config) {
        $config = ContentLanguageSettings::create([
          'target_entity_type_id' => 'node',
          'target_bundle' => $type_id,
        ]);
      }

      $config->setDefaultLangcode('site_default');
      $config->setLanguageAlterable(TRUE);
      $config->save();

      $ctm = \Drupal::service('content_translation.manager');
      $ctm->setEnabled('node', $type_id, TRUE);
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error(
        'Помилка налаштування багатомовності для типу @type: @message',
        ['@type' => $type_id, '@message' => $e->getMessage()]
      );
    }
  }

  /**
   * Створити поле для типу контенту.
   *
   * @param string $type_id
   *   ID типу контенту.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function createField($type_id, $field_name, array $field_info) {
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
    $field_storage = FieldStorageConfig::loadByName('node', $field_name);

    if (!$field_storage) {
      $storage_settings = [
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $field_type,
        'cardinality' => $field_info['cardinality'] == -1 ? -1 : (int) $field_info['cardinality'],
      ];

      // Для entity_reference (taxonomy_term_reference) встановлюємо target_type.
      if ($field_type == 'entity_reference' && $field_info['type'] == 'taxonomy_term_reference') {
        $storage_settings['settings'] = [
          'target_type' => 'taxonomy_term',
        ];
      }

      $field_storage = FieldStorageConfig::create($storage_settings);
      $field_storage->save();
    }

    // Робимо поле перекладним (translatable).
    if (!$field_storage->isTranslatable()) {
      $field_storage->setTranslatable(TRUE);
      $field_storage->save();
    }

    // Перевіряємо чи існує field instance.
    $field = FieldConfig::loadByName('node', $type_id, $field_name);

    if (!$field) {
      $field_settings = [
        'field_storage' => $field_storage,
        'bundle' => $type_id,
        'label' => $field_info['label'],
        'description' => $field_info['description'] ?? '',
        'required' => (bool) $field_info['required'],
      ];

      // Для taxonomy_term_reference налаштовуємо vocabulary.
      if ($field_info['type'] == 'taxonomy_term_reference') {
        if (!empty($field_info['vocabularies']) && is_array($field_info['vocabularies'])) {
          $target_bundles = [];
          foreach ($field_info['vocabularies'] as $vocab) {
            if (!empty($vocab['machine_name'])) {
              $target_bundles[$vocab['machine_name']] = $vocab['machine_name'];
            }
          }

          if (!empty($target_bundles)) {
            $field_settings['settings'] = [
              'handler' => 'default:taxonomy_term',
              'handler_settings' => [
                'target_bundles' => $target_bundles,
              ],
            ];

            \Drupal::logger('migrate_from_drupal7')->info(
              'Налаштовано прив\'язку поля @field до словників: @vocabularies',
              ['@field' => $field_name, '@vocabularies' => implode(', ', array_keys($target_bundles))]
            );
          }
          else {
            \Drupal::logger('migrate_from_drupal7')->warning(
              'Для поля @field типу taxonomy_term_reference не знайдено machine_name в vocabularies',
              ['@field' => $field_name]
            );
          }
        }
        else {
          // Логуємо попередження якщо vocabularies не знайдено.
          \Drupal::logger('migrate_from_drupal7')->warning(
            'Для поля @field типу taxonomy_term_reference не знайдено масив vocabularies. Дані поля: @data',
            ['@field' => $field_name, '@data' => print_r($field_info, TRUE)]
          );
        }
      }

      // Для image та file полів налаштовуємо дозволені розширення.
      if (in_array($field_type, ['image', 'file']) && !empty($field_info['allowed'])) {
        if (!isset($field_settings['settings'])) {
          $field_settings['settings'] = [];
        }

        // Перетворюємо масив розширень в рядок через пробіл.
        $extensions = is_array($field_info['allowed']) ? implode(' ', $field_info['allowed']) : $field_info['allowed'];
        $field_settings['settings']['file_extensions'] = $extensions;

        \Drupal::logger('migrate_from_drupal7')->info(
          'Налаштовано дозволені розширення для поля @field: @extensions',
          ['@field' => $field_name, '@extensions' => $extensions]
        );
      }

      $field = FieldConfig::create($field_settings);
      $field->save();

      // Налаштовуємо відображення поля у формі (Form Display).
      self::configureFormDisplay($type_id, $field_name, $field_info);

      // Налаштовуємо відображення поля при перегляді (View Display).
      self::configureViewDisplay($type_id, $field_name, $field_info);
    }

    // Робимо поле перекладним.
    if ($field && \Drupal::moduleHandler()->moduleExists('content_translation')) {
      // translation_sync має бути масивом, а не булевим значенням!
      // Для різних типів полів - різні ключі для синхронізації.
      $sync_settings = [];

      $field_type = $field_storage->getType();
      if (in_array($field_type, ['string', 'string_long', 'integer', 'decimal', 'float', 'email'])) {
        $sync_settings = ['value' => 'value'];
      }
      elseif (in_array($field_type, ['text_with_summary'])) {
        $sync_settings = ['value' => 'value', 'format' => 'format', 'summary' => 'summary'];
      }
      elseif ($field_type == 'image') {
        $sync_settings = ['alt' => 'alt', 'title' => 'title'];
      }
      elseif ($field_type == 'file') {
        $sync_settings = ['description' => 'description', 'display' => 'display'];
      }
      elseif ($field_type == 'link') {
        $sync_settings = ['uri' => 'uri', 'title' => 'title', 'options' => 'options'];
      }

      $field->setThirdPartySetting('content_translation', 'translation_sync', $sync_settings);
      $field->save();
    }
  }

  /**
   * Налаштувати відображення поля у формі.
   *
   * @param string $type_id
   *   ID типу контенту.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function configureFormDisplay($type_id, $field_name, array $field_info) {
    // Завантажуємо або створюємо form display.
    $form_display = EntityFormDisplay::load('node.' . $type_id . '.default');

    if (!$form_display) {
      $form_display = EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $type_id,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    // Мапінг віджетів з Drupal 7 на Drupal 11.
    $widget_map = [
      'text_textfield' => 'string_textfield',
      'text_textarea' => 'string_textarea',
      'text_textarea_with_summary' => 'text_textarea_with_summary',
      'number' => 'number',
      'options_select' => 'options_select',
      'options_buttons' => 'options_buttons',
      'image_image' => 'image_image',
      'file_generic' => 'file_generic',
      'taxonomy_autocomplete' => 'entity_reference_autocomplete',
      'entityreference_autocomplete' => 'entity_reference_autocomplete',
      'link_field' => 'link_default',
      'email_textfield' => 'email_default',
      'date_select' => 'datetime_default',
      'date_text' => 'datetime_default',
    ];

    $widget_type = $widget_map[$field_info['widget'] ?? ''] ?? 'string_textfield';

    // Додаємо поле до form display.
    $form_display->setComponent($field_name, [
      'type' => $widget_type,
      'weight' => 10,
      'settings' => [],
      'third_party_settings' => [],
    ]);

    $form_display->save();
  }

  /**
   * Налаштувати відображення поля при перегляді.
   *
   * @param string $type_id
   *   ID типу контенту.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function configureViewDisplay($type_id, $field_name, array $field_info) {
    // Завантажуємо або створюємо view display.
    $view_display = EntityViewDisplay::load('node.' . $type_id . '.default');

    if (!$view_display) {
      $view_display = EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $type_id,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    // Мапінг форматерів з типів полів.
    $formatter_map = [
      'string' => 'string',
      'string_long' => 'basic_string',
      'text_with_summary' => 'text_default',
      'integer' => 'number_integer',
      'decimal' => 'number_decimal',
      'float' => 'number_decimal',
      'list_string' => 'list_default',
      'list_integer' => 'list_default',
      'list_float' => 'list_default',
      'image' => 'image',
      'file' => 'file_default',
      'entity_reference' => 'entity_reference_label',
      'link' => 'link',
      'email' => 'basic_string',
      'datetime' => 'datetime_default',
      'timestamp' => 'timestamp',
    ];

    // Визначаємо тип поля для вибору форматера.
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
    $formatter_type = $formatter_map[$field_type] ?? 'string';

    // Додаємо поле до view display.
    $view_display->setComponent($field_name, [
      'type' => $formatter_type,
      'weight' => 10,
      'label' => 'above',
      'settings' => [],
      'third_party_settings' => [],
    ]);

    $view_display->save();
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
        $messenger->addStatus(t('Створено типів матеріалів: @count', ['@count' => count($results['created'])]));
      }
      if (!empty($results['updated'])) {
        $messenger->addStatus(t('Оновлено типів матеріалів: @count', ['@count' => count($results['updated'])]));
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
