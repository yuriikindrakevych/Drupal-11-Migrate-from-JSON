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
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Форма для імпорту Paragraph Types з Field Collections (Drupal 7).
 */
class ImportParagraphTypesForm extends FormBase {

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
    return 'migrate_from_drupal7_import_paragraph_types';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Перевіряємо чи встановлено модуль paragraphs.
    $module_handler = \Drupal::service('module_handler');
    if (!$module_handler->moduleExists('paragraphs')) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' .
                     $this->t('Модуль Paragraphs не встановлено! Встановіть модуль перед імпортом: composer require drupal/paragraphs') .
                     '</div>',
      ];
      return $form;
    }

    // Отримуємо типи контенту з Drupal 7.
    $content_types = $this->apiClient->getContentTypes();

    if (empty($content_types)) {
      $form['message'] = [
        '#markup' => $this->t('Не вдалося отримати типи контенту з Drupal 7. Перевірте налаштування.'),
      ];
      return $form;
    }

    // Збираємо всі field collections з усіх типів контенту.
    $field_collections = $this->extractFieldCollections($content_types);

    if (empty($field_collections)) {
      $form['message'] = [
        '#markup' => '<p>' . $this->t('Field Collections не знайдено в жодному типі контенту.') . '</p>' .
                     '<p>' . $this->t('Field Collection - це поля з типом "field_collection" в структурі JSON типів контенту.') . '</p>',
      ];
      return $form;
    }

    $form['#attached']['library'][] = 'migrate_from_drupal7/import-taxonomy';

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Знайдено @count Field Collections. Виберіть які конвертувати в Paragraph Types.', [
        '@count' => count($field_collections),
      ]) . '</p>' .
      '<p><strong>' . $this->t('Важливо:') . '</strong> ' .
      $this->t('Field Collections з Drupal 7 будуть конвертовані в Paragraph Types для Drupal 11. Всі вкладені поля будуть збережені.') .
      '</p>',
    ];

    $form['collections'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($field_collections as $bundle => $collection_data) {
      $form['collections'][$bundle] = [
        '#type' => 'details',
        '#title' => $collection_data['label'] . ' (' . $bundle . ')',
        '#open' => FALSE,
      ];

      $form['collections'][$bundle]['import'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Конвертувати в Paragraph Type'),
        '#default_value' => TRUE,
      ];

      $form['collections'][$bundle]['info'] = [
        '#type' => 'item',
        '#markup' => '<strong>' . $this->t('Використовується в:') . '</strong> ' .
                     implode(', ', $collection_data['used_in']) . '<br>' .
                     '<strong>' . $this->t('Кількість полів:') . '</strong> ' .
                     count($collection_data['fields'] ?? []),
      ];

      // Показуємо поля field collection.
      if (!empty($collection_data['fields'])) {
        $form['collections'][$bundle]['fields'] = [
          '#type' => 'details',
          '#title' => $this->t('Поля (@count)', ['@count' => count($collection_data['fields'])]),
          '#open' => TRUE,
        ];

        $form['collections'][$bundle]['fields']['select_all'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Вибрати всі поля'),
          '#default_value' => TRUE,
        ];

        foreach ($collection_data['fields'] as $field_name => $field_info) {
          $description = $this->t('Тип: @type, Обов\'язкове: @required, Кількість: @cardinality', [
            '@type' => $field_info['type'] ?? 'unknown',
            '@required' => !empty($field_info['required']) ? $this->t('Так') : $this->t('Ні'),
            '@cardinality' => isset($field_info['cardinality']) && $field_info['cardinality'] == -1 ?
                              $this->t('Необмежено') : ($field_info['cardinality'] ?? '1'),
          ]);

          // Додаємо інформацію про vocabulary для taxonomy_term_reference.
          if (isset($field_info['type']) && $field_info['type'] == 'taxonomy_term_reference') {
            if (!empty($field_info['vocabularies']) && is_array($field_info['vocabularies'])) {
              $vocab_names = array_map(function($vocab) {
                return $vocab['name'] . ' (' . $vocab['machine_name'] . ')';
              }, $field_info['vocabularies']);
              $description .= '<br><strong>' . $this->t('Словники: @vocabularies', [
                '@vocabularies' => implode(', ', $vocab_names)
              ]) . '</strong>';
            }
          }

          // Інформація про вкладені field collections.
          if (isset($field_info['type']) && $field_info['type'] == 'field_collection') {
            $nested_fields_count = !empty($field_info['collection']['fields']) ?
                                   count($field_info['collection']['fields']) : 0;
            $description .= '<br><strong style="color: #e09800;">' .
                           $this->t('⚠ Вкладена Field Collection! Полів всередині: @count', [
                             '@count' => $nested_fields_count
                           ]) . '</strong>';
          }

          $form['collections'][$bundle]['fields'][$field_name] = [
            '#type' => 'checkbox',
            '#title' => ($field_info['label'] ?? $field_name) . ' (' . $field_name . ')',
            '#description' => $description,
            '#default_value' => TRUE,
          ];
        }
      }
      else {
        $form['collections'][$bundle]['no_fields'] = [
          '#markup' => '<em>' . $this->t('Поля не знайдено') . '</em>',
        ];
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Створити Paragraph Types'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Витягти всі field collections з типів контенту.
   *
   * @param array $content_types
   *   Типи контенту з Drupal 7.
   *
   * @return array
   *   Масив field collections з їх полями.
   */
  protected function extractFieldCollections(array $content_types) {
    $collections = [];

    foreach ($content_types as $type_id => $content_type) {
      if (empty($content_type['fields'])) {
        continue;
      }

      foreach ($content_type['fields'] as $field_name => $field_info) {
        // Шукаємо поля типу field_collection.
        if (isset($field_info['type']) && $field_info['type'] === 'field_collection') {
          $bundle = $field_info['collection']['bundle'] ?? $field_name;

          // Ініціалізуємо якщо це перше використання цього bundle.
          if (!isset($collections[$bundle])) {
            $collections[$bundle] = [
              'label' => $field_info['label'] ?? $field_name,
              'bundle' => $bundle,
              'fields' => $field_info['collection']['fields'] ?? [],
              'used_in' => [],
            ];
          }

          // Додаємо тип контенту де використовується.
          $collections[$bundle]['used_in'][] = $content_type['name'] . ' (' . $type_id . ')';

          // Об'єднуємо поля якщо вони різні (може бути кілька uses).
          if (!empty($field_info['collection']['fields'])) {
            foreach ($field_info['collection']['fields'] as $cf_name => $cf_info) {
              if (!isset($collections[$bundle]['fields'][$cf_name])) {
                $collections[$bundle]['fields'][$cf_name] = $cf_info;
              }
            }
          }
        }
      }
    }

    return $collections;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue('collections');
    $content_types = $this->apiClient->getContentTypes();
    $field_collections = $this->extractFieldCollections($content_types);

    $operations = [];

    foreach ($values as $bundle => $collection_data) {
      if (!empty($collection_data['import']) && isset($field_collections[$bundle])) {
        $selected_fields = [];

        if (!empty($collection_data['fields'])) {
          foreach ($collection_data['fields'] as $field_name => $selected) {
            if ($selected && $field_name !== 'select_all') {
              $selected_fields[] = $field_name;
            }
          }
        }

        $operations[] = [
          [self::class, 'batchImportParagraphType'],
          [$bundle, $field_collections[$bundle], $selected_fields],
        ];
      }
    }

    if (empty($operations)) {
      $this->messenger()->addWarning($this->t('Не вибрано жодної Field Collection для конвертації.'));
      return;
    }

    $batch = [
      'title' => $this->t('Створення Paragraph Types'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Batch операція для створення Paragraph Type.
   *
   * @param string $bundle
   *   ID paragraph type (bundle).
   * @param array $collection_data
   *   Дані field collection з Drupal 7.
   * @param array $selected_fields
   *   Вибрані поля для імпорту.
   * @param array $context
   *   Контекст batch операції.
   */
  public static function batchImportParagraphType($bundle, array $collection_data, array $selected_fields, array &$context) {
    $entity_type_manager = \Drupal::entityTypeManager();

    try {
      // Перевіряємо чи існує paragraph type.
      $paragraph_type = ParagraphsType::load($bundle);

      if (!$paragraph_type) {
        // Створюємо новий paragraph type.
        $paragraph_type = ParagraphsType::create([
          'id' => $bundle,
          'label' => $collection_data['label'],
          'description' => t('Конвертовано з Field Collection (@used)', [
            '@used' => implode(', ', $collection_data['used_in']),
          ]),
        ]);
        $paragraph_type->save();

        $context['results']['created'][] = $collection_data['label'] . ' (' . $bundle . ')';
      }
      else {
        $context['results']['updated'][] = $collection_data['label'] . ' (' . $bundle . ')';
      }

      // Імпорт полів.
      if (!empty($selected_fields) && !empty($collection_data['fields'])) {
        foreach ($selected_fields as $field_name) {
          if (isset($collection_data['fields'][$field_name])) {
            $field_info = $collection_data['fields'][$field_name];
            self::createField($bundle, $field_name, $field_info);
          }
        }
      }

      $context['message'] = t('Створено Paragraph Type: @label', ['@label' => $collection_data['label']]);
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка створення Paragraph Type @bundle: @message', [
        '@bundle' => $bundle,
        '@message' => $e->getMessage(),
      ]);
      $context['results']['errors'][] = $collection_data['label'] . ': ' . $e->getMessage();
    }
  }

  /**
   * Створити поле для paragraph type.
   *
   * @param string $bundle
   *   ID paragraph type.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function createField($bundle, $field_name, array $field_info) {
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
      'datetime' => 'datetime',
      'datestamp' => 'timestamp',
      'field_collection' => 'entity_reference_revisions',  // Вкладені collections → paragraphs
    ];

    $field_type = $field_type_map[$field_info['type']] ?? 'string';

    // Якщо text_long має text_processing:1 або default_format, то це форматоване поле.
    // В Drupal 11 форматовані текстові поля мають тип 'text_long', а не 'string_long'.
    if ($field_info['type'] == 'text_long') {
      $has_text_processing = !empty($field_info['text_processing']);
      $has_default_format = !empty($field_info['default_format']);

      if ($has_text_processing || $has_default_format) {
        $field_type = 'text_long';  // Форматоване текстове поле
        $field_info['_is_formatted'] = TRUE;  // Позначаємо для використання в configureFormDisplay
      }
    }

    // Перевіряємо чи існує field storage.
    $field_storage = FieldStorageConfig::loadByName('paragraph', $field_name);
    $cardinality = isset($field_info['cardinality']) && $field_info['cardinality'] == -1 ?
                   -1 : (int) ($field_info['cardinality'] ?? 1);

    if (!$field_storage) {
      $storage_settings = [
        'field_name' => $field_name,
        'entity_type' => 'paragraph',
        'type' => $field_type,
        'cardinality' => $cardinality,
      ];

      // Для entity_reference (taxonomy_term_reference) встановлюємо target_type.
      if ($field_type == 'entity_reference' && $field_info['type'] == 'taxonomy_term_reference') {
        $storage_settings['settings'] = [
          'target_type' => 'taxonomy_term',
        ];
      }

      // Для вкладених field collections (entity_reference_revisions).
      if ($field_type == 'entity_reference_revisions') {
        $storage_settings['settings'] = [
          'target_type' => 'paragraph',
        ];
      }

      // Для string полів встановлюємо max_length.
      if ($field_type == 'string' && !empty($field_info['max_length'])) {
        $storage_settings['settings'] = [
          'max_length' => (int) $field_info['max_length'],
        ];
      }
      elseif ($field_type == 'string' && !empty($field_info['field_settings']['max_length'])) {
        $storage_settings['settings'] = [
          'max_length' => (int) $field_info['field_settings']['max_length'],
        ];
      }

      $field_storage = FieldStorageConfig::create($storage_settings);
      $field_storage->save();
    }
    else {
      // Оновлюємо cardinality якщо змінилось.
      if ($field_storage->getCardinality() != $cardinality) {
        $field_storage->setCardinality($cardinality);
        $field_storage->save();
      }
    }

    // Перевіряємо чи існує field instance.
    $field = FieldConfig::loadByName('paragraph', $bundle, $field_name);

    if (!$field) {
      $field_settings = [
        'field_storage' => $field_storage,
        'bundle' => $bundle,
        'label' => $field_info['label'] ?? $field_name,
        'description' => $field_info['description'] ?? '',
        'required' => (bool) ($field_info['required'] ?? FALSE),
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
          }
        }
      }

      // Для вкладених field collections налаштовуємо target paragraph type.
      if ($field_info['type'] == 'field_collection' && !empty($field_info['collection']['bundle'])) {
        $nested_bundle = $field_info['collection']['bundle'];
        $field_settings['settings'] = [
          'handler' => 'default:paragraph',
          'handler_settings' => [
            'target_bundles' => [$nested_bundle => $nested_bundle],
            'negate' => 0,
            'target_bundles_drag_drop' => [
              $nested_bundle => ['enabled' => TRUE],
            ],
          ],
        ];
      }

      // Для image та file полів налаштовуємо дозволені розширення та інші параметри.
      if (in_array($field_type, ['image', 'file'])) {
        if (!isset($field_settings['settings'])) {
          $field_settings['settings'] = [];
        }

        // Дозволені розширення.
        if (!empty($field_info['allowed'])) {
          $extensions = is_array($field_info['allowed']) ?
                        implode(' ', $field_info['allowed']) :
                        $field_info['allowed'];
          $field_settings['settings']['file_extensions'] = $extensions;
        }

        // Директорія для файлів.
        if (!empty($field_info['file_directory'])) {
          $field_settings['settings']['file_directory'] = $field_info['file_directory'];
        }

        // Максимальний розмір файлу.
        if (!empty($field_info['max_filesize'])) {
          $field_settings['settings']['max_filesize'] = $field_info['max_filesize'];
        }

        // Для зображень додаємо resolution settings та alt/title fields.
        if ($field_type == 'image') {
          if (!empty($field_info['max_resolution'])) {
            $field_settings['settings']['max_resolution'] = $field_info['max_resolution'];
          }
          if (!empty($field_info['min_resolution'])) {
            $field_settings['settings']['min_resolution'] = $field_info['min_resolution'];
          }
          if (isset($field_info['alt_field'])) {
            $field_settings['settings']['alt_field'] = (bool) $field_info['alt_field'];
            $field_settings['settings']['alt_field_required'] = (bool) $field_info['alt_field'];
          }
          if (isset($field_info['title_field'])) {
            $field_settings['settings']['title_field'] = (bool) $field_info['title_field'];
            $field_settings['settings']['title_field_required'] = FALSE;
          }
          // Default image з field_settings.
          if (!empty($field_info['field_settings']['default_image'])) {
            $field_settings['settings']['default_image'] = [
              'uuid' => NULL,
              'alt' => '',
              'title' => '',
              'width' => NULL,
              'height' => NULL,
            ];
          }
        }

        // URI scheme з field_settings.
        if (!empty($field_info['field_settings']['uri_scheme'])) {
          $field_settings['settings']['uri_scheme'] = $field_info['field_settings']['uri_scheme'];
        }
      }

      // Для text полів з форматом додаємо default_format.
      if (in_array($field_info['type'], ['text_long', 'text_with_summary'])) {
        if (!isset($field_settings['settings'])) {
          $field_settings['settings'] = [];
        }
        // З JSON може бути default_format.
        if (!empty($field_info['default_format'])) {
          // Зберігаємо для використання в configureFormDisplay.
          $field_info['_default_format'] = $field_info['default_format'];
        }
      }

      $field = FieldConfig::create($field_settings);
      $field->save();

      // Налаштовуємо відображення поля у формі (Form Display).
      self::configureFormDisplay($bundle, $field_name, $field_info);

      // Налаштовуємо відображення поля при перегляді (View Display).
      self::configureViewDisplay($bundle, $field_name, $field_info);
    }
  }

  /**
   * Налаштувати відображення поля у формі.
   *
   * @param string $bundle
   *   ID paragraph type.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function configureFormDisplay($bundle, $field_name, array $field_info) {
    $form_display = EntityFormDisplay::load('paragraph.' . $bundle . '.default');

    if (!$form_display) {
      $form_display = EntityFormDisplay::create([
        'targetEntityType' => 'paragraph',
        'bundle' => $bundle,
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
      'file_mfw' => 'file_generic',
      'dragndrop_upload_image' => 'image_image',
      'dragndrop_upload_file' => 'file_generic',
      'taxonomy_autocomplete' => 'entity_reference_autocomplete',
      'entityreference_autocomplete' => 'entity_reference_autocomplete',
      'link_field' => 'link_default',
      'email_textfield' => 'email_default',
      'date_select' => 'datetime_default',
      'date_popup' => 'datetime_default',
      'date_text' => 'datetime_default',
      'field_collection_embed' => 'paragraphs',  // Для вкладених collections
    ];

    $widget_type = $widget_map[$field_info['widget'] ?? ''] ?? 'string_textfield';

    // Якщо поле форматоване (text_long з text_processing або default_format),
    // використовуємо text_textarea замість string_textarea.
    if (!empty($field_info['_is_formatted']) && $widget_type == 'string_textarea') {
      $widget_type = 'text_textarea';
    }

    // Для вкладених field collections використовуємо paragraphs widget.
    if (isset($field_info['type']) && $field_info['type'] == 'field_collection') {
      $widget_type = 'paragraphs';
    }

    // Налаштування віджету.
    $widget_settings = [];

    // Використовуємо widget_settings з JSON якщо є.
    if (!empty($field_info['widget_settings']) && is_array($field_info['widget_settings'])) {
      $widget_settings = $field_info['widget_settings'];
    }

    // Додаємо специфічні налаштування для різних типів віджетів.
    if ($widget_type == 'image_image' && !empty($field_info['widget_settings'])) {
      // progress_indicator, preview_image_style вже в widget_settings.
    }

    if ($widget_type == 'string_textarea' && !empty($field_info['widget_settings']['rows'])) {
      $widget_settings['rows'] = (int) $field_info['widget_settings']['rows'];
    }

    if ($widget_type == 'string_textfield' && !empty($field_info['widget_settings']['size'])) {
      $widget_settings['size'] = (int) $field_info['widget_settings']['size'];
    }

    // Для text_textarea_with_summary додаємо rows.
    if ($widget_type == 'text_textarea_with_summary' && !empty($field_info['widget_settings']['rows'])) {
      $widget_settings['rows'] = (int) $field_info['widget_settings']['rows'];
      $widget_settings['summary_rows'] = 3;
    }

    // Для форматованих текстових полів додаємо налаштування rows.
    if ($widget_type == 'text_textarea' && !empty($field_info['widget_settings']['rows'])) {
      $widget_settings['rows'] = (int) $field_info['widget_settings']['rows'];
    }

    $form_display->setComponent($field_name, [
      'type' => $widget_type,
      'weight' => 10,
      'settings' => $widget_settings,
      'third_party_settings' => [],
    ]);

    $form_display->save();
  }

  /**
   * Налаштувати відображення поля при перегляді.
   *
   * @param string $bundle
   *   ID paragraph type.
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function configureViewDisplay($bundle, $field_name, array $field_info) {
    $view_display = EntityViewDisplay::load('paragraph.' . $bundle . '.default');

    if (!$view_display) {
      $view_display = EntityViewDisplay::create([
        'targetEntityType' => 'paragraph',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    // Мапінг форматерів.
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
      'entity_reference_revisions' => 'entity_reference_revisions_entity_view',
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
      'field_collection' => 'entity_reference_revisions',
      'link_field' => 'link',
      'email' => 'email',
      'date' => 'datetime',
      'datetime' => 'datetime',
      'datestamp' => 'timestamp',
    ];

    $field_type = $field_type_map[$field_info['type']] ?? 'string';
    $formatter_type = $formatter_map[$field_type] ?? 'string';

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
        $messenger->addStatus(t('Створено Paragraph Types: @count', ['@count' => count($results['created'])]));
        foreach ($results['created'] as $name) {
          $messenger->addStatus('✓ ' . $name);
        }
      }
      if (!empty($results['updated'])) {
        $messenger->addStatus(t('Оновлено Paragraph Types: @count', ['@count' => count($results['updated'])]));
      }
      if (!empty($results['errors'])) {
        $messenger->addError(t('Помилки: @count', ['@count' => count($results['errors'])]));
        foreach ($results['errors'] as $error) {
          $messenger->addError($error);
        }
      }

      if (!empty($results['created']) || !empty($results['updated'])) {
        $messenger->addStatus(t('Наступний крок: оновіть типи контенту, замінивши field_collection поля на entity_reference_revisions поля з посиланням на створені Paragraph Types.'));
      }
    }
    else {
      $messenger->addError(t('Виникла помилка під час створення Paragraph Types.'));
    }
  }

}
