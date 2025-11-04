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

/**
 * Форма для імпорту полів користувачів з Drupal 7.
 */
class ImportUserFieldsForm extends FormBase {

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
    return 'migrate_from_drupal7_import_user_fields';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Отримуємо поля користувачів з Drupal 7.
    $user_fields = $this->apiClient->getUserFields();

    if (empty($user_fields)) {
      $form['message'] = [
        '#markup' => $this->t('Не вдалося отримати поля користувачів з Drupal 7. Перевірте налаштування в вкладці "Налаштування".'),
      ];
      return $form;
    }

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Виберіть поля користувачів для імпорту.') . '</p>',
    ];

    $form['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Поля користувачів'),
      '#open' => TRUE,
    ];

    $form['fields']['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Вибрати всі поля'),
      '#default_value' => FALSE,
    ];

    foreach ($user_fields as $field_info) {
      $field_name = $field_info['field_name'];

      // Пропускаємо core user picture якщо потрібно.
      if ($field_info['type'] === 'core_user_picture') {
        continue;
      }

      $description = $this->t('Тип: @type, Обов\'язкове: @required, Кількість значень: @cardinality', [
        '@type' => $field_info['type'],
        '@required' => $field_info['required'] ? $this->t('Так') : $this->t('Ні'),
        '@cardinality' => $field_info['cardinality'] == -1 ? $this->t('Необмежено') : $field_info['cardinality'],
      ]);

      // Додаткова інформація для list_text полів.
      if ($field_info['type'] == 'list_text' && !empty($field_info['options']['allowed_values'])) {
        $values_count = count($field_info['options']['allowed_values']);
        $description .= '<br><strong>' . $this->t('Варіантів відповідей: @count', ['@count' => $values_count]) . '</strong>';
      }

      $form['fields'][$field_name] = [
        '#type' => 'checkbox',
        '#title' => $field_info['label'] . ' (' . $field_name . ')',
        '#description' => $description,
        '#default_value' => FALSE,
      ];
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
    $user_fields = $this->apiClient->getUserFields();
    $selected_fields = [];

    foreach ($user_fields as $field_info) {
      $field_name = $field_info['field_name'];
      if ($form_state->getValue($field_name)) {
        $selected_fields[] = $field_info;
      }
    }

    if (empty($selected_fields)) {
      $this->messenger()->addWarning($this->t('Не вибрано жодного поля для імпорту.'));
      return;
    }

    $operations = [];
    foreach ($selected_fields as $field_info) {
      $operations[] = [
        [self::class, 'batchImportField'],
        [$field_info],
      ];
    }

    $batch = [
      'title' => $this->t('Імпорт полів користувачів'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Batch операція для імпорту поля користувача.
   *
   * @param array $field_info
   *   Дані поля з Drupal 7.
   * @param array $context
   *   Контекст batch операції.
   */
  public static function batchImportField(array $field_info, array &$context) {
    try {
      self::createUserField($field_info);
      $context['results']['created'][] = $field_info['label'];
      $context['message'] = t('Імпортовано поле: @name', ['@name' => $field_info['label']]);
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка імпорту поля користувача @name: @message', [
        '@name' => $field_info['label'],
        '@message' => $e->getMessage(),
      ]);
      $context['results']['errors'][] = $field_info['label'] . ': ' . $e->getMessage();
    }
  }

  /**
   * Створити поле для користувача.
   *
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function createUserField(array $field_info) {
    $field_name = $field_info['field_name'];

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
      'link_field' => 'link',
      'email' => 'email',
      'date' => 'datetime',
      'datetime' => 'datetime',
      'datestamp' => 'timestamp',
    ];

    $field_type = $field_type_map[$field_info['type']] ?? 'string';
    $cardinality = $field_info['cardinality'] == -1 ? -1 : (int) $field_info['cardinality'];

    // Перевіряємо чи існує field storage.
    $field_storage = FieldStorageConfig::loadByName('user', $field_name);

    if (!$field_storage) {
      $storage_settings = [
        'field_name' => $field_name,
        'entity_type' => 'user',
        'type' => $field_type,
        'cardinality' => $cardinality,
      ];

      // Налаштування для list_text полів.
      if ($field_type == 'list_string' && !empty($field_info['options']['allowed_values'])) {
        $allowed_values = [];
        foreach ($field_info['options']['allowed_values'] as $option) {
          $allowed_values[$option['value']] = $option['label'];
        }
        $storage_settings['settings'] = [
          'allowed_values' => $allowed_values,
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
    $field = FieldConfig::loadByName('user', 'user', $field_name);

    if (!$field) {
      $field_settings = [
        'field_storage' => $field_storage,
        'bundle' => 'user',
        'label' => $field_info['label'],
        'description' => $field_info['description'] ?? '',
        'required' => (bool) $field_info['required'],
      ];

      // Додаткові налаштування для text полів.
      if (in_array($field_type, ['string', 'string_long']) && !empty($field_info['options']['max_length'])) {
        $field_settings['settings'] = [
          'max_length' => $field_info['options']['max_length'],
        ];
      }

      $field = FieldConfig::create($field_settings);
      $field->save();

      // Налаштовуємо відображення поля у формі.
      self::configureFormDisplay($field_name, $field_info);

      // Налаштовуємо відображення поля при перегляді.
      self::configureViewDisplay($field_name, $field_info);
    }
  }

  /**
   * Налаштувати відображення поля у формі.
   *
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function configureFormDisplay($field_name, array $field_info) {
    // Завантажуємо або створюємо form display.
    $form_display = EntityFormDisplay::load('user.user.default');

    if (!$form_display) {
      $form_display = EntityFormDisplay::create([
        'targetEntityType' => 'user',
        'bundle' => 'user',
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
      'link_field' => 'link_default',
      'email_textfield' => 'email_default',
      'date_select' => 'datetime_default',
      'date_popup' => 'datetime_default',
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
   * @param string $field_name
   *   Назва поля.
   * @param array $field_info
   *   Інформація про поле.
   */
  protected static function configureViewDisplay($field_name, array $field_info) {
    // Завантажуємо або створюємо view display.
    $view_display = EntityViewDisplay::load('user.user.default');

    if (!$view_display) {
      $view_display = EntityViewDisplay::create([
        'targetEntityType' => 'user',
        'bundle' => 'user',
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
      'link_field' => 'link',
      'email' => 'email',
      'date' => 'datetime',
      'datetime' => 'datetime',
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
        $messenger->addStatus(t('Створено полів користувачів: @count', ['@count' => count($results['created'])]));
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
