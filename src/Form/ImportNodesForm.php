<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_from_drupal7\Service\Drupal7ApiClient;
use Drupal\migrate_from_drupal7\Service\MappingService;
use Drupal\migrate_from_drupal7\Service\LogService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Форма для імпорту матеріалів.
 */
class ImportNodesForm extends FormBase {

  protected $apiClient;
  protected $entityTypeManager;
  protected $mappingService;
  protected $logService;

  public function __construct(
    Drupal7ApiClient $api_client,
    EntityTypeManagerInterface $entity_type_manager,
    MappingService $mapping_service,
    LogService $log_service
  ) {
    $this->apiClient = $api_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->mappingService = $mapping_service;
    $this->logService = $log_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('migrate_from_drupal7.api_client'),
      $container->get('entity_type.manager'),
      $container->get('migrate_from_drupal7.mapping'),
      $container->get('migrate_from_drupal7.log')
    );
  }

  public function getFormId() {
    return 'migrate_from_drupal7_import_nodes';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $node_types = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();

    if (empty($node_types)) {
      $form['message'] = [
        '#markup' => $this->t('Не знайдено жодного типу матеріалу.'),
      ];
      return $form;
    }

    $form['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Типи матеріалів'),
      '#options' => [],
      '#required' => TRUE,
    ];

    foreach ($node_types as $node_type) {
      $form['node_types']['#options'][$node_type->id()] = $node_type->label();
    }

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Кількість матеріалів за один batch'),
      '#description' => $this->t('Рекомендовано: 10-50. Менше значення = повільніше, але надійніше. Більше значення = швидше, але може призвести до помилок.'),
      '#default_value' => 20,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Максимальна кількість матеріалів для імпорту'),
      '#description' => $this->t('Залиште порожнім для імпорту всіх матеріалів. Корисно для тестування (наприклад, імпортувати тільки перші 50 нод).'),
      '#default_value' => '',
      '#min' => 1,
      '#required' => FALSE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Імпорт'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node_types = array_filter($form_state->getValue('node_types'));
    $batch_size = (int) $form_state->getValue('batch_size');
    $limit = $form_state->getValue('limit');
    $limit = !empty($limit) ? (int) $limit : NULL;

    $batch = [
      'title' => $this->t('Імпорт матеріалів'),
      'operations' => [],
      'finished' => [self::class, 'batchFinished'],
    ];

    foreach ($node_types as $node_type) {
      $batch['operations'][] = [
        [self::class, 'batchImportNodes'],
        [$node_type, $batch_size, $limit],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch імпорт нод з offset (новий метод).
   *
   * @param string $node_type
   *   Тип матеріалу.
   * @param int $batch_size
   *   Кількість нод за один batch.
   * @param int|null $limit
   *   Максимальна кількість матеріалів для імпорту (NULL = без обмежень).
   * @param array $context
   *   Контекст batch.
   */
  public static function batchImportNodes($node_type, $batch_size, $limit, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $log_service = \Drupal::service('migrate_from_drupal7.log');

    // Ініціалізація.
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['offset'] = 0;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['errors'] = 0;
      $context['sandbox']['batch_size'] = $batch_size;
      $context['sandbox']['node_type'] = $node_type;
      $context['sandbox']['limit'] = $limit;

      // Отримуємо загальну кількість нод (приблизно).
      $context['sandbox']['max'] = 999999; // Будемо зменшувати поступово

      $limit_msg = $limit !== NULL ? " (ліміт: $limit)" : '';
      $log_service->logSuccess(
        'import_start',
        'node',
        "Початок імпорту типу $node_type (batch size: $batch_size$limit_msg)",
        $node_type,
        []
      );
    }

    // Перевіряємо чи досягли ліміту.
    $limit = $context['sandbox']['limit'];
    if ($limit !== NULL && $context['sandbox']['imported'] >= $limit) {
      $context['finished'] = 1;
      $context['message'] = t('Досягнуто ліміт: @limit матеріалів', ['@limit' => $limit]);

      $log_service->logSuccess(
        'import_limit_reached',
        'node',
        "Досягнуто ліміт імпорту типу $node_type. Імпортовано: {$context['sandbox']['imported']}, Помилок: {$context['sandbox']['errors']}",
        $node_type,
        [
          'imported' => $context['sandbox']['imported'],
          'errors' => $context['sandbox']['errors'],
          'limit' => $limit,
        ]
      );
      return;
    }

    // Завантажуємо порцію нод.
    $offset = $context['sandbox']['offset'];

    // Якщо є ліміт, коригуємо batch_size щоб не перевищити ліміт.
    $effective_batch_size = $batch_size;
    if ($limit !== NULL) {
      $remaining = $limit - $context['sandbox']['imported'];
      $effective_batch_size = min($batch_size, $remaining);
    }

    $nodes = $api_client->getNodes($node_type, $effective_batch_size, $offset);

    if (empty($nodes)) {
      // Більше немає нод - завершуємо.
      $context['finished'] = 1;
      $context['message'] = t('Імпорт типу @type завершено. Імпортовано: @imported, Помилок: @errors', [
        '@type' => $node_type,
        '@imported' => $context['sandbox']['imported'],
        '@errors' => $context['sandbox']['errors'],
      ]);

      $log_service->logSuccess(
        'import_finished',
        'node',
        "Завершено імпорт типу $node_type. Імпортовано: {$context['sandbox']['imported']}, Помилок: {$context['sandbox']['errors']}",
        $node_type,
        [
          'imported' => $context['sandbox']['imported'],
          'errors' => $context['sandbox']['errors'],
        ]
      );
      return;
    }

    // Групуємо за tnid для обробки перекладів.
    $nodes_by_tnid = [];
    foreach ($nodes as $node_preview) {
      $nid = $node_preview['nid'];

      try {
        $node_data = $api_client->getNodeById($nid);

        if (empty($node_data) || !is_array($node_data)) {
          continue;
        }

        $tnid = $node_data['tnid'] ?? $nid;
        if (!isset($nodes_by_tnid[$tnid])) {
          $nodes_by_tnid[$tnid] = [];
        }
        $nodes_by_tnid[$tnid][] = $node_data;
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка завантаження ноди @nid: @message',
          ['@nid' => $nid, '@message' => $e->getMessage()]
        );
        $context['sandbox']['errors']++;
      }
    }

    // Обробляємо кожну групу (оригінал + переклади).
    foreach ($nodes_by_tnid as $nodes_data) {
      try {
        // Розділяємо на оригінал та переклади.
        $original_data = NULL;
        $translations_data = [];

        foreach ($nodes_data as $node_data) {
          $node_nid = $node_data['nid'];
          $node_tnid = $node_data['tnid'] ?? $node_nid;
          $is_translation = !empty($node_tnid) && $node_tnid != $node_nid && $node_tnid != '0';

          if ($is_translation) {
            $translations_data[] = $node_data;
          }
          else {
            $original_data = $node_data;
          }
        }

        // Спочатку імпортуємо оригінал.
        if ($original_data) {
          $result = self::importSingleNode($original_data);
          if ($result['success']) {
            $context['sandbox']['imported']++;
            $mapping_service->saveMapping('node', $original_data['nid'], $result['nid'], $node_type);

            $log_service->logSuccess(
              $result['action'] ?? 'import',
              'node',
              $result['message'] ?? 'Імпорт успішний',
              (string) $result['nid'],
              [
                'old_nid' => $original_data['nid'],
                'title' => $original_data['title'],
                'language' => $original_data['language'] ?? 'uk',
              ]
            );
          }
          else {
            $context['sandbox']['errors']++;
            $log_service->logError(
              'import',
              'node',
              $result['error'] ?? 'Не вдалося імпортувати',
              (string) $original_data['nid'],
              ['title' => $original_data['title']]
            );
          }
        }

        // Потім імпортуємо переклади.
        foreach ($translations_data as $translation_data) {
          $result = self::importSingleNode($translation_data);
          if ($result['success']) {
            $context['sandbox']['imported']++;
            $mapping_service->saveMapping('node', $translation_data['nid'], $result['nid'], $node_type);

            $log_service->logSuccess(
              $result['action'] ?? 'import',
              'node',
              $result['message'] ?? 'Імпорт успішний',
              (string) $result['nid'],
              [
                'old_nid' => $translation_data['nid'],
                'title' => $translation_data['title'],
                'language' => $translation_data['language'] ?? 'uk',
              ]
            );
          }
          else {
            $context['sandbox']['errors']++;
            $log_service->logError(
              'import',
              'node',
              $result['error'] ?? 'Не вдалося імпортувати',
              (string) $translation_data['nid'],
              ['title' => $translation_data['title']]
            );
          }
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error('Помилка імпорту групи: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    // Оновлюємо прогрес.
    $context['sandbox']['progress'] += count($nodes);
    $context['sandbox']['offset'] += $batch_size;

    // Якщо отримали менше ніж batch_size - це останній batch.
    if (count($nodes) < $batch_size) {
      $context['finished'] = 1;
    }
    else {
      // Обчислюємо приблизний прогрес (не можемо знати точну кількість заздалегідь).
      $context['finished'] = min(0.99, $context['sandbox']['progress'] / ($context['sandbox']['progress'] + $batch_size));
    }

    // Формуємо повідомлення з урахуванням ліміту.
    if ($limit !== NULL) {
      $context['message'] = t('Імпорт @type: імпортовано @imported з @limit (ліміт), помилок: @errors', [
        '@type' => $node_type,
        '@imported' => $context['sandbox']['imported'],
        '@limit' => $limit,
        '@errors' => $context['sandbox']['errors'],
      ]);
    }
    else {
      $context['message'] = t('Імпорт @type: оброблено @progress нод (імпортовано: @imported, помилок: @errors)', [
        '@type' => $node_type,
        '@progress' => $context['sandbox']['progress'],
        '@imported' => $context['sandbox']['imported'],
        '@errors' => $context['sandbox']['errors'],
      ]);
    }

    // Зберігаємо результати для фінального повідомлення.
    if ($context['finished'] == 1) {
      $context['results']['imported'] = ($context['results']['imported'] ?? 0) + $context['sandbox']['imported'];
      $context['results']['errors'] = ($context['results']['errors'] ?? 0) + $context['sandbox']['errors'];
    }
  }

  /**
   * Batch імпорт (старий метод для сумісності з cron).
   */
  public static function batchImport($node_type, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $log_service = \Drupal::service('migrate_from_drupal7.log');

    if (!isset($context['sandbox']['progress'])) {
      // Завантажуємо всі ноди (тільки nid + changed).
      $all_nodes = $api_client->getNodes($node_type, 9999, 0);

      if (empty($all_nodes)) {
        $context['finished'] = 1;
        return;
      }

      // Завантажуємо повні дані для всіх нод і групуємо за tnid.
      $nodes_by_tnid = [];

      foreach ($all_nodes as $node_preview) {
        $nid = $node_preview['nid'];
        $node_data = $api_client->getNodeById($nid);

        if (empty($node_data) || !is_array($node_data)) {
          continue;
        }

        $tnid = $node_data['tnid'] ?? $nid;
        if (!isset($nodes_by_tnid[$tnid])) {
          $nodes_by_tnid[$tnid] = [];
        }
        $nodes_by_tnid[$tnid][] = $node_data;
      }

      // Зберігаємо список груп для обробки.
      $context['sandbox']['node_groups'] = array_values($nodes_by_tnid);
      $context['sandbox']['total'] = count($context['sandbox']['node_groups']);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['errors'] = 0;
    }

    // Обробляємо по 1 групі за раз.
    $batch_size = 1;
    $groups_slice = array_slice(
      $context['sandbox']['node_groups'],
      $context['sandbox']['progress'],
      $batch_size
    );

    foreach ($groups_slice as $nodes_data) {
      try {
        // Розділяємо на оригінал та переклади.
        $original_data = NULL;
        $translations_data = [];

        foreach ($nodes_data as $node_data) {
          $node_nid = $node_data['nid'];
          $node_tnid = $node_data['tnid'] ?? $node_nid;
          $is_translation = !empty($node_tnid) && $node_tnid != $node_nid && $node_tnid != '0';

          if ($is_translation) {
            $translations_data[] = $node_data;
          }
          else {
            $original_data = $node_data;
          }
        }

        // Спочатку імпортуємо оригінал.
        if ($original_data) {
          $result = self::importSingleNode($original_data);
          if ($result['success']) {
            $context['sandbox']['imported']++;
            $mapping_service->saveMapping('node', $original_data['nid'], $result['nid'], $node_type);

            $log_service->logSuccess(
              $result['action'] ?? 'import',
              'node',
              $result['message'] ?? 'Імпорт успішний',
              (string) $result['nid'],
              [
                'old_nid' => $original_data['nid'],
                'title' => $original_data['title'],
                'language' => $original_data['language'] ?? 'uk',
              ]
            );
          }
          else {
            $context['sandbox']['errors']++;
            $log_service->logError(
              'import',
              'node',
              $result['error'] ?? 'Не вдалося імпортувати',
              (string) $original_data['nid'],
              ['title' => $original_data['title']]
            );
          }
        }

        // Потім імпортуємо переклади.
        foreach ($translations_data as $translation_data) {
          $result = self::importSingleNode($translation_data);
          if ($result['success']) {
            $context['sandbox']['imported']++;
            $mapping_service->saveMapping('node', $translation_data['nid'], $result['nid'], $node_type);

            $log_service->logSuccess(
              $result['action'] ?? 'import',
              'node',
              $result['message'] ?? 'Імпорт успішний',
              (string) $result['nid'],
              [
                'old_nid' => $translation_data['nid'],
                'title' => $translation_data['title'],
                'language' => $translation_data['language'] ?? 'uk',
              ]
            );
          }
          else {
            $context['sandbox']['errors']++;
            $log_service->logError(
              'import',
              'node',
              $result['error'] ?? 'Не вдалося імпортувати',
              (string) $translation_data['nid'],
              ['title' => $translation_data['title']]
            );
          }
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error('Помилка: @msg', ['@msg' => $e->getMessage()]);

        $log_service->logError(
          'import',
          'node',
          'Помилка імпорту групи: ' . $e->getMessage(),
          '',
          []
        );
      }

      $context['sandbox']['progress']++;
    }

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    $context['message'] = t('Оброблено @current з @total', [
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Імпорт однієї ноди - зі всіма полями.
   */
  protected static function importSingleNode(array $node_data): array {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $nid = $node_data['nid'];
    $tnid = $node_data['tnid'] ?? $nid;
    $node_type = $node_data['type'];
    $language = $node_data['language'] ?? 'uk';
    $title = $node_data['title'];
    $changed = (int) ($node_data['changed'] ?? time());
    $is_translation = !empty($tnid) && $tnid != $nid && $tnid != '0';

    // Підготовка даних полів для імпорту.
    $fields_data = self::prepareFieldsData($node_data, $title);

    if ($is_translation) {
      // Це переклад - шукаємо оригінал.
      $original_new_nid = $mapping_service->getNewId('node', $tnid, $node_type);

      if (!$original_new_nid) {
        return [
          'success' => FALSE,
          'error' => "Оригінал не знайдено для tnid=$tnid",
        ];
      }

      $original = Node::load($original_new_nid);

      if (!$original) {
        return [
          'success' => FALSE,
          'error' => "Оригінальна нода #$original_new_nid не знайдена",
        ];
      }

      if ($original->hasTranslation($language)) {
        // Переклад існує - перевіряємо чи потрібно оновити.
        $translation = $original->getTranslation($language);
        $existing_changed = $translation->getChangedTime();

        if ($changed > $existing_changed) {
          // Потрібно оновити переклад.
          $translation->set('title', $title);
          self::setFieldsToNode($translation, $fields_data);
          $translation->set('changed', $changed);
          $translation->save();
          return [
            'success' => TRUE,
            'nid' => $original_new_nid,
            'action' => 'update',
            'message' => "Переклад оновлено: $language (changed: " . date('Y-m-d H:i:s', $existing_changed) . ' → ' . date('Y-m-d H:i:s', $changed) . ')',
          ];
        }
        else {
          // Переклад актуальний - пропускаємо.
          return [
            'success' => TRUE,
            'nid' => $original_new_nid,
            'action' => 'skip',
            'message' => "Переклад актуальний, пропущено: $language",
          ];
        }
      }

      // Переклад не існує - створюємо.
      $translation = $original->addTranslation($language);
      $translation->set('title', $title);
      self::setFieldsToNode($translation, $fields_data);
      $translation->set('changed', $changed);
      $translation->set('default_langcode', 0);
      $translation->save();

      return [
        'success' => TRUE,
        'nid' => $original_new_nid,
        'action' => 'import',
        'message' => "Переклад створено: $language",
      ];
    }
    else {
      // Це оригінал.
      $existing_nid = $mapping_service->getNewId('node', $nid, $node_type);

      if ($existing_nid) {
        // Перевіряємо чи існує нода.
        $node = Node::load($existing_nid);

        if ($node) {
          // Нода існує - перевіряємо чи потрібно оновити.
          $existing_changed = $node->getChangedTime();

          if ($changed > $existing_changed) {
            // Потрібно оновити.
            $node->set('title', $title);
            self::setFieldsToNode($node, $fields_data);
            $node->set('changed', $changed);
            $node->save();
            return [
              'success' => TRUE,
              'nid' => $existing_nid,
              'action' => 'update',
              'message' => "Оновлено (changed: " . date('Y-m-d H:i:s', $existing_changed) . ' → ' . date('Y-m-d H:i:s', $changed) . ')',
            ];
          }
          else {
            // Нода актуальна - пропускаємо.
            return [
              'success' => TRUE,
              'nid' => $existing_nid,
              'action' => 'skip',
              'message' => 'Нода актуальна, пропущено',
            ];
          }
        }
        else {
          // Нода не існує (видалена) - видаляємо маппінг і створюємо нову.
          $mapping_service->deleteMapping('node', $nid);
        }
      }

      // Створюємо нову ноду.
      $node = Node::create([
        'type' => $node_type,
        'title' => $title,
        'langcode' => $language,
        'uid' => 1,
        'status' => 1,
        'created' => $changed,
        'changed' => $changed,
      ]);
      self::setFieldsToNode($node, $fields_data);
      $node->save();
      return [
        'success' => TRUE,
        'nid' => $node->id(),
        'action' => 'import',
        'message' => "Створено нову ноду #" . $node->id(),
      ];
    }
  }

  /**
   * Підготовка даних полів з JSON.
   *
   * @param array $node_data
   *   Дані ноди з JSON.
   * @param string $title
   *   Заголовок ноди (для alt тексту зображень).
   *
   * @return array
   *   Масив підготовлених даних полів.
   */
  protected static function prepareFieldsData(array $node_data, string $title): array {
    $fields_data = [];

    if (empty($node_data['fields']) || !is_array($node_data['fields'])) {
      return $fields_data;
    }

    $bundle = $node_data['type'];

    foreach ($node_data['fields'] as $field_name => $field_values) {
      if (empty($field_values) || !is_array($field_values)) {
        continue;
      }

      // ВАЖЛИВО: Для одиничних полів (cardinality = 1) Drupal 7 може повертати
      // об'єкт замість масиву: {"value": "...", "format": "..."}
      // Перевіряємо чи це об'єкт (асоціативний масив) з value/format.
      if (isset($field_values['value']) && !isset($field_values[0])) {
        // Це одиничне поле у форматі об'єкта - перетворюємо в масив з одним елементом.
        $field_values = [$field_values];
      }

      // Визначаємо тип поля за його структурою.
      $first_item = $field_values[0] ?? [];

      // 1. Body (text_with_summary) - має value + format + summary.
      if ($field_name === 'body' && isset($first_item['value'])) {
        $body_value = $first_item['value'];

        // Обробляємо inline зображення в body.
        $body_value = self::processInlineImages($body_value, $title);

        $fields_data[$field_name] = [
          'value' => $body_value,
          'summary' => $first_item['summary'] ?? '',
          'format' => !empty($first_item['format']) ? $first_item['format'] : 'plain_text',
        ];
      }
      // 2. Зображення (image) - має fid + alt + title.
      elseif (isset($first_item['fid']) && isset($first_item['filemime']) && strpos($first_item['filemime'], 'image/') === 0) {
        $images = [];
        $image_counter = 1;

        foreach ($field_values as $image_data) {
          $file = self::downloadFile($image_data, $field_name, $bundle);
          if ($file) {
            $alt = $image_data['alt'] ?? '';
            // Якщо alt порожній, використовуємо title ноди.
            if (empty($alt)) {
              // Якщо множинне поле, додаємо номер.
              $alt = count($field_values) > 1 ? "$title - фото $image_counter" : $title;
            }

            $images[] = [
              'target_id' => $file->id(),
              'alt' => $alt,
              'title' => $image_data['title'] ?? '',
            ];
            $image_counter++;
          }
        }

        if (!empty($images)) {
          $fields_data[$field_name] = $images;
        }
      }
      // 3. Файли (file) - має fid + filename + uri.
      elseif (isset($first_item['fid']) && isset($first_item['filename'])) {
        $files = [];

        foreach ($field_values as $file_data) {
          $file = self::downloadFile($file_data, $field_name, $bundle);
          if ($file) {
            $files[] = [
              'target_id' => $file->id(),
              'description' => $file_data['description'] ?? '',
              'display' => $file_data['display'] ?? 1,
            ];
          }
        }

        if (!empty($files)) {
          $fields_data[$field_name] = $files;
        }
      }
      // 4. Таксономія (entity_reference) - має tid.
      elseif (isset($first_item['tid'])) {
        $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
        $term_ids = [];

        foreach ($field_values as $term_data) {
          $old_tid = $term_data['tid'];
          // Шукаємо новий tid в маппінгу.
          $new_tid = $mapping_service->getNewId('taxonomy_term', $old_tid);
          if ($new_tid) {
            $term_ids[] = ['target_id' => $new_tid];
          }
          else {
            // Термін не знайдено в маппінгу.
          }
        }

        if (!empty($term_ids)) {
          $fields_data[$field_name] = $term_ids;
        }
      }
      // 5. Entity reference (node reference) - має target_id.
      elseif (isset($first_item['target_id'])) {
        $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
        $node_ids = [];

        foreach ($field_values as $ref_data) {
          $old_nid = $ref_data['target_id'];
          // Шукаємо новий nid в маппінгу.
          $new_nid = $mapping_service->getNewId('node', $old_nid);
          if ($new_nid) {
            $node_ids[] = ['target_id' => $new_nid];
          }
        }

        if (!empty($node_ids)) {
          $fields_data[$field_name] = $node_ids;
        }
      }
      // 6. Текстові поля з форматом (text_long) - має value + format.
      elseif (isset($first_item['value']) && isset($first_item['format'])) {
        if (count($field_values) == 1) {
          // Одне значення.
          $fields_data[$field_name] = [
            'value' => $first_item['value'],
            'format' => !empty($first_item['format']) ? $first_item['format'] : 'plain_text',
          ];
        }
        else {
          // Множинні значення.
          $values = [];
          foreach ($field_values as $item) {
            $values[] = [
              'value' => $item['value'],
              'format' => !empty($item['format']) ? $item['format'] : 'plain_text',
            ];
          }
          $fields_data[$field_name] = $values;
        }
      }
      // 7. Прості текстові поля (string, string_long) - тільки value.
      elseif (isset($first_item['value'])) {
        if (count($field_values) == 1) {
          // Одне значення.
          $fields_data[$field_name] = $first_item['value'];
        }
        else {
          // Множинні значення.
          $values = [];
          foreach ($field_values as $item) {
            if (isset($item['value'])) {
              $values[] = $item['value'];
            }
          }
          $fields_data[$field_name] = $values;
        }
      }
      // 8. Field Collections → Paragraphs - має field_type + items.
      elseif (is_array($field_values) && isset($field_values['field_type']) && $field_values['field_type'] === 'field_collection') {
        try {
          \Drupal::logger('migrate_from_drupal7')->info(
            'Початок конвертації field collection @field (@bundle), items: @count',
            [
              '@field' => $field_name,
              '@bundle' => $field_values['target_bundle'] ?? 'unknown',
              '@count' => count($field_values['items'] ?? []),
            ]
          );

          $paragraphs = self::convertFieldCollectionsToParagraphs($field_values, $title, $bundle);

          if (!empty($paragraphs)) {
            $fields_data[$field_name] = $paragraphs;
            \Drupal::logger('migrate_from_drupal7')->info(
              'Створено @count paragraphs для поля @field',
              ['@count' => count($paragraphs), '@field' => $field_name]
            );
          }
        }
        catch (\Exception $e) {
          \Drupal::logger('migrate_from_drupal7')->error(
            'Помилка конвертації field collection @field: @message. Trace: @trace',
            [
              '@field' => $field_name,
              '@message' => $e->getMessage(),
              '@trace' => $e->getTraceAsString(),
            ]
          );
          // Пропускаємо це поле, але продовжуємо імпорт інших
        }
      }
      // 9. Інші типи - пропускаємо.
      else {
        // Невідомий тип поля, пропускаємо.
      }
    }

    return $fields_data;
  }

  /**
   * Обробка inline зображень в HTML тексті.
   *
   * @param string $html
   *   HTML текст з body.
   * @param string $node_title
   *   Заголовок ноди (для alt тексту).
   *
   * @return string
   *   Оброблений HTML з замінами зображень.
   */
  protected static function processInlineImages(string $html, string $node_title): string {
    if (empty($html) || strpos($html, '<img') === FALSE) {
      return $html;
    }

    // Отримуємо базову URL Drupal 7.
    $config = \Drupal::config('migrate_from_drupal7.settings');
    $base_url = rtrim($config->get('base_url'), '/');

    if (empty($base_url)) {
      return $html;
    }

    // Використовуємо DOMDocument для парсингу HTML.
    libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $images = $dom->getElementsByTagName('img');
    $image_counter = 1;
    $images_to_process = [];

    // Збираємо всі зображення для обробки.
    foreach ($images as $img) {
      $images_to_process[] = $img;
    }

    // Обробляємо кожне зображення.
    foreach ($images_to_process as $img) {
      $src = $img->getAttribute('src');
      if (empty($src)) {
        continue;
      }

      // Створюємо абсолютний URL.
      if (strpos($src, 'http') !== 0) {
        $src = ltrim($src, '/');
        $absolute_url = $base_url . '/' . $src;
      }
      else {
        $absolute_url = $src;
      }

      // Завантажуємо файл.
      $file = self::downloadInlineImage($absolute_url, $src);
      if (!$file) {
        $image_counter++;
        continue;
      }

      // Оновлюємо атрибути зображення.
      $alt = $img->getAttribute('alt');
      if (empty($alt)) {
        $alt = "$node_title - фото $image_counter";
      }

      // Встановлюємо нові атрибути.
      $img->setAttribute('data-entity-uuid', $file->uuid());
      $img->setAttribute('data-entity-type', 'file');
      $img->setAttribute('alt', $alt);
      $img->setAttribute('loading', 'lazy');

      // Оновлюємо src на новий шлях Drupal 11.
      $file_uri = $file->getFileUri();
      $file_url = \Drupal::service('file_url_generator')->generateString($file_uri);
      $img->setAttribute('src', $file_url);

      $image_counter++;
    }

    // Повертаємо оновлений HTML.
    $html = $dom->saveHTML();

    // Видаляємо доданий XML declaration.
    $html = preg_replace('/^<!DOCTYPE.+?>/', '', $html);
    $html = str_replace(['<html>', '</html>', '<body>', '</body>'], '', $html);
    $html = trim($html);

    return $html;
  }

  /**
   * Завантаження inline зображення з Drupal 7.
   *
   * @param string $url
   *   Абсолютний URL зображення.
   * @param string $original_src
   *   Оригінальний src (для визначення імені файлу).
   *
   * @return \Drupal\file\FileInterface|null
   *   File entity або NULL у разі помилки.
   */
  protected static function downloadInlineImage(string $url, string $original_src) {
    try {
      // Визначаємо ім'я файлу.
      $filename = basename($original_src);

      // Перевіряємо чи файл вже існує за URL (можна зробити маппінг по URL).
      // Для простоти завантажуємо в inline-images.
      $directory = 'public://inline-images';
      \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

      $destination = $directory . '/' . $filename;

      // Перевіряємо чи файл вже існує.
      $existing_files = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $destination]);

      if (!empty($existing_files)) {
        return reset($existing_files);
      }

      // Завантажуємо файл.
      $http_client = \Drupal::httpClient();
      $response = $http_client->get($url);
      $file_content = $response->getBody()->getContents();

      // Зберігаємо файл.
      $file = \Drupal::service('file.repository')->writeData(
        $file_content,
        $destination,
        \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
      );

      if ($file) {
        return $file;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка завантаження inline зображення @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Завантаження файлу з Drupal 7.
   *
   * @param array $file_data
   *   Дані файлу з JSON (fid, filename, uri, absolute_url тощо).
   * @param string $field_name
   *   Назва поля.
   * @param string $bundle
   *   Тип контенту (bundle).
   *
   * @return \Drupal\file\FileInterface|null
   *   File entity або NULL у разі помилки.
   */
  protected static function downloadFile(array $file_data, string $field_name, string $bundle) {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $old_fid = $file_data['fid'];

    // Перевіряємо чи файл вже імпортований.
    $new_fid = $mapping_service->getNewId('file', $old_fid);
    if ($new_fid) {
      $file = \Drupal\file\Entity\File::load($new_fid);
      if ($file) {
        return $file;
      }
    }

    // Отримуємо URL для завантаження.
    $url = $file_data['absolute_url'] ?? NULL;
    if (empty($url)) {
      return NULL;
    }

    // Отримуємо налаштування поля для визначення директорії.
    $field_config = \Drupal\field\Entity\FieldConfig::loadByName('node', $bundle, $field_name);
    $file_directory = '';
    $uri_scheme = 'public';

    if ($field_config) {
      $settings = $field_config->getSettings();
      $file_directory = $settings['file_directory'] ?? '';
      $uri_scheme = $settings['uri_scheme'] ?? 'public';

      // Обробляємо токени в file_directory.
      if (!empty($file_directory)) {
        $token_service = \Drupal::token();
        $file_directory = $token_service->replace($file_directory, [], ['clear' => TRUE]);
      }
    }

    // Формуємо URI з урахуванням налаштувань поля.
    $filename = $file_data['filename'];
    if (!empty($file_directory)) {
      $uri = $uri_scheme . '://' . trim($file_directory, '/') . '/' . $filename;
    }
    else {
      $uri = $uri_scheme . '://' . $filename;
    }

    $directory = dirname($uri);

    // Створюємо директорію якщо не існує.
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

    try {
      // Завантажуємо файл.
      $http_client = \Drupal::httpClient();
      $response = $http_client->get($url);
      $file_content = $response->getBody()->getContents();

      // Зберігаємо файл.
      $file = \Drupal::service('file.repository')->writeData($file_content, $uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

      if ($file) {
        // Зберігаємо маппінг.
        $mapping_service->saveMapping('file', $old_fid, $file->id());
        return $file;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка завантаження файлу @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Встановлення полів в ноду.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Нода.
   * @param array $fields_data
   *   Підготовлені дані полів.
   */
  protected static function setFieldsToNode($node, array $fields_data): void {
    foreach ($fields_data as $field_name => $field_value) {
      if (!$node->hasField($field_name)) {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Поле @field не існує в типі контенту @type (nid: @nid). Поле пропущено. Можливо це поле не було імпортоване при створенні типу контенту.',
          [
            '@field' => $field_name,
            '@type' => $node->bundle(),
            '@nid' => $node->id() ?? 'новий',
          ]
        );
        continue;
      }

      try {
        $node->set($field_name, $field_value);
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка встановлення поля @field в ноду @nid (тип: @type): @message',
          [
            '@field' => $field_name,
            '@nid' => $node->id() ?? 'новий',
            '@type' => $node->bundle(),
            '@message' => $e->getMessage(),
          ]
        );
      }
    }
  }

  /**
   * Конвертація Field Collections в Paragraphs.
   *
   * @param array $field_collection_data
   *   Дані field collection з JSON (має items array).
   * @param string $node_title
   *   Заголовок ноди (для alt тексту зображень).
   * @param string $bundle
   *   Bundle ноди (для шляху файлів).
   *
   * @return array
   *   Масив paragraph references для прив'язки до ноди.
   */
  protected static function convertFieldCollectionsToParagraphs(array $field_collection_data, string $node_title, string $bundle): array {
    if (empty($field_collection_data['items']) || !is_array($field_collection_data['items'])) {
      return [];
    }

    $paragraph_references = [];
    $target_bundle = $field_collection_data['target_bundle'] ?? NULL;

    // Сортуємо items за delta.
    $items = $field_collection_data['items'];
    usort($items, function($a, $b) {
      return ($a['delta'] ?? 0) <=> ($b['delta'] ?? 0);
    });

    foreach ($items as $index => $item) {
      // Пропускаємо архівовані items.
      if (!empty($item['archived'])) {
        \Drupal::logger('migrate_from_drupal7')->info(
          'Пропущено архівований item @item_id (delta: @delta)',
          ['@item_id' => $item['item_id'] ?? 'unknown', '@delta' => $item['delta'] ?? $index]
        );
        continue;
      }

      try {
        \Drupal::logger('migrate_from_drupal7')->info(
          'Створення paragraph @index/@total (item_id: @item_id, bundle: @bundle)',
          [
            '@index' => $index + 1,
            '@total' => count($items),
            '@item_id' => $item['item_id'] ?? 'unknown',
            '@bundle' => $target_bundle,
          ]
        );

        $paragraph = self::createParagraphFromItem($item, $target_bundle, $node_title, $bundle);

        if ($paragraph) {
          $paragraph_references[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];

          \Drupal::logger('migrate_from_drupal7')->info(
            'Створено paragraph ID @pid для item_id @item_id',
            ['@pid' => $paragraph->id(), '@item_id' => $item['item_id'] ?? 'unknown']
          );

          // Зберігаємо маппінг field_collection_item_id → paragraph_id.
          if (!empty($item['item_id'])) {
            $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
            $mapping_service->saveMapping(
              'field_collection_item',
              $item['item_id'],
              $paragraph->id(),
              $target_bundle
            );
          }
        }
        else {
          \Drupal::logger('migrate_from_drupal7')->warning(
            'Не вдалося створити paragraph для item_id @item_id',
            ['@item_id' => $item['item_id'] ?? 'unknown']
          );
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка створення paragraph (item_id: @item_id, delta: @delta): @message. Trace: @trace',
          [
            '@item_id' => $item['item_id'] ?? 'unknown',
            '@delta' => $item['delta'] ?? $index,
            '@message' => $e->getMessage(),
            '@trace' => $e->getTraceAsString(),
          ]
        );
        // Продовжуємо обробку інших items
      }
    }

    return $paragraph_references;
  }

  /**
   * Створити Paragraph з field collection item.
   *
   * @param array $item
   *   Field collection item data.
   * @param string $paragraph_type
   *   Тип paragraph.
   * @param string $node_title
   *   Заголовок ноди.
   * @param string $bundle
   *   Bundle ноди.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph|null
   *   Створений paragraph або NULL.
   */
  protected static function createParagraphFromItem(array $item, string $paragraph_type, string $node_title, string $bundle): ?Paragraph {
    if (empty($paragraph_type)) {
      \Drupal::logger('migrate_from_drupal7')->warning('createParagraphFromItem: порожній paragraph_type');
      return NULL;
    }

    try {
      // Створюємо paragraph.
      $paragraph = Paragraph::create([
        'type' => $paragraph_type,
      ]);

      \Drupal::logger('migrate_from_drupal7')->info(
        'Створено paragraph entity типу @type, обробка @count полів',
        ['@type' => $paragraph_type, '@count' => count($item)]
      );

      $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');

    // Встановлюємо всі поля з item.
    foreach ($item as $field_name => $value) {
      // Пропускаємо службові поля.
      if (in_array($field_name, ['item_id', 'delta', 'archived', 'bundle'])) {
        continue;
      }

      // Перевіряємо чи paragraph має це поле.
      if (!$paragraph->hasField($field_name)) {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Поле @field не існує в типі параграфа @type (item_id: @item_id). Поле пропущено. Можливо це поле не було імпортоване при створенні Paragraph Type.',
          [
            '@field' => $field_name,
            '@type' => $paragraph_type,
            '@item_id' => $item['item_id'] ?? 'unknown',
          ]
        );
        continue;
      }

      try {
        // Перевіряємо чи це вкладена field collection.
        if (is_array($value) && isset($value['field_type']) && $value['field_type'] === 'field_collection') {
          // Рекурсивна обробка вкладених field collections.
          $nested_paragraphs = self::convertFieldCollectionsToParagraphs($value, $node_title, $bundle);
          if (!empty($nested_paragraphs)) {
            $paragraph->set($field_name, $nested_paragraphs);
          }
        }
        // Обробка файлів/зображень.
        elseif (is_array($value) && isset($value['fid']) && isset($value['filename'])) {
          $file = self::downloadFile($value, $field_name, $bundle);
          if ($file) {
            // Перевіряємо чи це зображення.
            if (isset($value['filemime']) && strpos($value['filemime'], 'image/') === 0) {
              $alt = $value['alt'] ?? $node_title;
              $paragraph->set($field_name, [
                'target_id' => $file->id(),
                'alt' => $alt,
                'title' => $value['title'] ?? '',
              ]);
            }
            else {
              $paragraph->set($field_name, [
                'target_id' => $file->id(),
                'description' => $value['description'] ?? '',
                'display' => $value['display'] ?? 1,
              ]);
            }
          }
        }
        // Обробка taxonomy term reference.
        elseif (is_array($value) && isset($value['tid'])) {
          $new_tid = $mapping_service->getNewId('taxonomy_term', $value['tid']);
          if ($new_tid) {
            $paragraph->set($field_name, ['target_id' => $new_tid]);
          }
        }
        // Обробка node reference.
        elseif (is_array($value) && isset($value['nid'])) {
          $new_nid = $mapping_service->getNewId('node', $value['nid']);
          if ($new_nid) {
            $paragraph->set($field_name, ['target_id' => $new_nid]);
          }
        }
        // Обробка текстових полів з форматом.
        elseif (is_array($value) && isset($value['value'])) {
          if (isset($value['format'])) {
            $paragraph->set($field_name, [
              'value' => $value['value'],
              'format' => !empty($value['format']) ? $value['format'] : 'plain_text',
            ]);
          }
          else {
            $paragraph->set($field_name, $value['value']);
          }
        }
        // Прості значення.
        else {
          $paragraph->set($field_name, $value);
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка встановлення поля @field в paragraph типу @type: @message',
          [
            '@field' => $field_name,
            '@type' => $paragraph_type,
            '@message' => $e->getMessage(),
          ]
        );
        // Продовжуємо обробку інших полів
      }
    }

    // Зберігаємо paragraph.
    \Drupal::logger('migrate_from_drupal7')->info('Збереження paragraph типу @type', ['@type' => $paragraph_type]);
    $paragraph->save();

    \Drupal::logger('migrate_from_drupal7')->info('Paragraph збережено з ID @id', ['@id' => $paragraph->id()]);
    return $paragraph;
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error(
        'Критична помилка в createParagraphFromItem для типу @type: @message. Trace: @trace',
        [
          '@type' => $paragraph_type,
          '@message' => $e->getMessage(),
          '@trace' => $e->getTraceAsString(),
        ]
      );
      return NULL;
    }
  }

  /**
   * Batch завершено.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      $imported = $results['imported'] ?? 0;
      $errors = $results['errors'] ?? 0;

      if ($imported > 0) {
        $messenger->addStatus(t('Імпорт завершено! Імпортовано матеріалів: @imported', ['@imported' => $imported]));
      }

      if ($errors > 0) {
        $messenger->addWarning(t('Під час імпорту виникло помилок: @errors. Перевірте логи для деталей.', ['@errors' => $errors]));
      }

      if ($imported === 0 && $errors === 0) {
        $messenger->addWarning(t('Не знайдено матеріалів для імпорту або всі матеріали вже актуальні.'));
      }
    }
    else {
      $messenger->addError(t('Виникла критична помилка під час імпорту. Перевірте логи.'));
    }
  }

}
