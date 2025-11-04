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

/**
 * Мінімальна форма для імпорту матеріалів - ТІЛЬКИ TITLE.
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

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Мінімальний імпорт: тільки title + переклади.') . '</p>',
    ];

    $form['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Типи матеріалів'),
      '#options' => [],
      '#required' => TRUE,
    ];

    foreach ($node_types as $node_type) {
      $form['node_types']['#options'][$node_type->id()] = $node_type->label();
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Імпорт'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node_types = array_filter($form_state->getValue('node_types'));

    $batch = [
      'title' => $this->t('Імпорт'),
      'operations' => [],
      'finished' => [$this, 'batchFinished'],
    ];

    foreach ($node_types as $node_type) {
      $batch['operations'][] = [
        [self::class, 'batchImport'],
        [$node_type],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch імпорт.
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
      \Drupal::logger('migrate_from_drupal7')->info('Завантажуємо @count нод', ['@count' => count($all_nodes)]);
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

      \Drupal::logger('migrate_from_drupal7')->info('Згруповано в @count груп перекладів', ['@count' => count($nodes_by_tnid)]);

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
        \Drupal::logger('migrate_from_drupal7')->info('=== Обробка групи з @count нод ===', ['@count' => count($nodes_data)]);

        // Розділяємо на оригінал та переклади.
        $original_data = NULL;
        $translations_data = [];

        foreach ($nodes_data as $node_data) {
          $node_nid = $node_data['nid'];
          $node_tnid = $node_data['tnid'] ?? $node_nid;
          $is_translation = !empty($node_tnid) && $node_tnid != $node_nid && $node_tnid != '0';

          \Drupal::logger('migrate_from_drupal7')->info('  nid=@nid, tnid=@tnid, is_translation=@trans, title=@title', [
            '@nid' => $node_nid,
            '@tnid' => $node_tnid,
            '@trans' => $is_translation ? 'ТАК' : 'НІ',
            '@title' => $node_data['title'] ?? '',
          ]);

          if ($is_translation) {
            $translations_data[] = $node_data;
          }
          else {
            $original_data = $node_data;
          }
        }

        \Drupal::logger('migrate_from_drupal7')->info('Розділено: оригінал=@orig, перекладів=@count', [
          '@orig' => $original_data ? $original_data['nid'] : 'немає',
          '@count' => count($translations_data),
        ]);

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
        \Drupal::logger('migrate_from_drupal7')->info('Імпорт перекладів: @count шт', ['@count' => count($translations_data)]);
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

    \Drupal::logger('migrate_from_drupal7')->info(
      'Імпорт: nid=@nid, lang=@lang, is_trans=@trans, title=@title, changed=@changed',
      [
        '@nid' => $nid,
        '@lang' => $language,
        '@trans' => $is_translation ? 'ТАК' : 'НІ',
        '@title' => $title,
        '@changed' => date('Y-m-d H:i:s', $changed),
      ]
    );

    if ($is_translation) {
      // Це переклад - шукаємо оригінал.
      $original_new_nid = $mapping_service->getNewId('node', $tnid, $node_type);

      if (!$original_new_nid) {
        \Drupal::logger('migrate_from_drupal7')->warning('Оригінал не знайдено для tnid=@tnid', ['@tnid' => $tnid]);
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
          \Drupal::logger('migrate_from_drupal7')->info('Переклад оновлено: @lang (changed: @old → @new)', [
            '@lang' => $language,
            '@old' => date('Y-m-d H:i:s', $existing_changed),
            '@new' => date('Y-m-d H:i:s', $changed),
          ]);
          return [
            'success' => TRUE,
            'nid' => $original_new_nid,
            'action' => 'update',
            'message' => "Переклад оновлено: $language (changed: " . date('Y-m-d H:i:s', $existing_changed) . ' → ' . date('Y-m-d H:i:s', $changed) . ')',
          ];
        }
        else {
          // Переклад актуальний - пропускаємо.
          \Drupal::logger('migrate_from_drupal7')->info('Переклад актуальний, пропускаємо: @lang', ['@lang' => $language]);
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

      \Drupal::logger('migrate_from_drupal7')->info('Переклад створено: @lang', ['@lang' => $language]);
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
            \Drupal::logger('migrate_from_drupal7')->info('Оновлено: nid=@nid (changed: @old → @new)', [
              '@nid' => $existing_nid,
              '@old' => date('Y-m-d H:i:s', $existing_changed),
              '@new' => date('Y-m-d H:i:s', $changed),
            ]);
            return [
              'success' => TRUE,
              'nid' => $existing_nid,
              'action' => 'update',
              'message' => "Оновлено (changed: " . date('Y-m-d H:i:s', $existing_changed) . ' → ' . date('Y-m-d H:i:s', $changed) . ')',
            ];
          }
          else {
            // Нода актуальна - пропускаємо.
            \Drupal::logger('migrate_from_drupal7')->info('Нода актуальна, пропускаємо: nid=@nid', ['@nid' => $existing_nid]);
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
          \Drupal::logger('migrate_from_drupal7')->warning('Нода @nid з маппінгу не існує, створюємо нову', ['@nid' => $existing_nid]);
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
      \Drupal::logger('migrate_from_drupal7')->info('Створено: nid=@nid', ['@nid' => $node->id()]);
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

      // Визначаємо тип поля за його структурою.
      $first_item = $field_values[0] ?? [];

      // 1. Body (text_with_summary) - має value + format + summary.
      if ($field_name === 'body' && isset($first_item['value'])) {
        $fields_data[$field_name] = [
          'value' => $first_item['value'],
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
            \Drupal::logger('migrate_from_drupal7')->warning('Термін tid=@tid не знайдено в маппінгу для поля @field', [
              '@tid' => $old_tid,
              '@field' => $field_name,
            ]);
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
      // 8. Інші типи - логуємо що пропустили.
      else {
        \Drupal::logger('migrate_from_drupal7')->info('Невідомий тип поля @field, структура: @struct', [
          '@field' => $field_name,
          '@struct' => print_r($first_item, TRUE),
        ]);
      }
    }

    return $fields_data;
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
        \Drupal::logger('migrate_from_drupal7')->info('Файл fid=@fid вже існує в системі', ['@fid' => $old_fid]);
        return $file;
      }
    }

    // Отримуємо URL для завантаження.
    $url = $file_data['absolute_url'] ?? NULL;
    if (empty($url)) {
      \Drupal::logger('migrate_from_drupal7')->warning('Відсутній absolute_url для файлу fid=@fid', ['@fid' => $old_fid]);
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
        \Drupal::logger('migrate_from_drupal7')->info('Завантажено файл @filename → @uri (old_fid=@old, new_fid=@new)', [
          '@filename' => $file_data['filename'],
          '@uri' => $uri,
          '@old' => $old_fid,
          '@new' => $file->id(),
        ]);
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
        continue;
      }

      try {
        $node->set($field_name, $field_value);
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Не вдалося встановити поле @field: @msg',
          ['@field' => $field_name, '@msg' => $e->getMessage()]
        );
      }
    }
  }

  /**
   * Batch завершено.
   */
  public static function batchFinished($success, array $results, array $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Імпорт завершено!'));
    }
    else {
      \Drupal::messenger()->addError(t('Помилка імпорту.'));
    }
  }

}
