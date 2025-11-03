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
use Drupal\file\Entity\File;

/**
 * Форма для імпорту матеріалів (nodes) з Drupal 7.
 *
 * Простий підхід:
 * 1. Завантажуємо всі ноди з API
 * 2. Розділяємо на оригінали (tnid == nid) та переклади (tnid != nid)
 * 3. Імпортуємо спочатку всі оригінали
 * 4. Потім імпортуємо всі переклади
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
      '#markup' => '<p>' . $this->t('Імпорт матеріалів: спочатку оригінали, потім переклади.') . '</p>',
    ];

    $form['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Типи матеріалів для імпорту'),
      '#options' => [],
      '#description' => $this->t('Виберіть типи матеріалів.'),
      '#required' => TRUE,
    ];

    foreach ($node_types as $node_type) {
      $form['node_types']['#options'][$node_type->id()] = $node_type->label() . ' (' . $node_type->id() . ')';
    }

    $form['batch_size'] = [
      '#type' => 'select',
      '#title' => $this->t('Розмір порції'),
      '#options' => [
        10 => '10',
        25 => '25',
        50 => '50',
        100 => '100',
      ],
      '#default_value' => 50,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Розпочати імпорт'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node_types = array_filter($form_state->getValue('node_types'));
    $batch_size = (int) $form_state->getValue('batch_size');

    if (empty($node_types)) {
      $this->messenger()->addError($this->t('Виберіть хоча б один тип матеріалу.'));
      return;
    }

    $batch = [
      'title' => $this->t('Імпорт матеріалів'),
      'operations' => [],
      'finished' => [$this, 'batchFinished'],
      'progressive' => TRUE,
    ];

    foreach ($node_types as $node_type) {
      $batch['operations'][] = [
        [self::class, 'batchImportNodes'],
        [$node_type, $batch_size],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch операція: імпорт матеріалів.
   */
  public static function batchImportNodes($node_type, $batch_size, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $log_service = \Drupal::service('migrate_from_drupal7.log');

    // Ініціалізація.
    if (!isset($context['sandbox']['progress'])) {
      // Завантажуємо всі ноди.
      $all_nodes_data = $api_client->getNodes($node_type, 9999, 0);

      if (empty($all_nodes_data) || !is_array($all_nodes_data)) {
        $context['results']['errors'][$node_type] = 1;
        $context['finished'] = 1;
        $log_service->logError('import', 'node', "Не вдалося отримати матеріали типу $node_type");
        return;
      }

      // Розділяємо на оригінали та переклади.
      $originals = [];
      $translations = [];

      foreach ($all_nodes_data as $node_preview) {
        $nid = $node_preview['nid'];
        $node_full = $api_client->getNodeById($nid);

        if (!$node_full) {
          continue;
        }

        $tnid = $node_full['tnid'] ?? $nid;
        $is_translation = !empty($tnid) && $tnid != $nid && $tnid != '0';

        if ($is_translation) {
          $translations[] = $node_full;
        }
        else {
          $originals[] = $node_full;
        }
      }

      // Зберігаємо в sandbox: спочатку оригінали, потім переклади.
      $context['sandbox']['all_nodes'] = array_merge($originals, $translations);
      $context['sandbox']['total'] = count($context['sandbox']['all_nodes']);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['node_type'] = $node_type;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['updated'] = 0;
      $context['sandbox']['skipped'] = 0;
      $context['sandbox']['errors'] = 0;

      $config = \Drupal::config('migrate_from_drupal7.settings');
      $context['sandbox']['base_url'] = $config->get('base_url') ?? '';

      \Drupal::logger('migrate_from_drupal7')->info(
        'Початок імпорту: @total нод (@orig оригіналів, @trans перекладів)',
        [
          '@total' => $context['sandbox']['total'],
          '@orig' => count($originals),
          '@trans' => count($translations),
        ]
      );
    }

    // Обробляємо порцію.
    $nodes_to_process = array_slice(
      $context['sandbox']['all_nodes'],
      $context['sandbox']['progress'],
      $batch_size
    );

    if (empty($nodes_to_process)) {
      $context['results']['imported'][$node_type] = $context['sandbox']['imported'];
      $context['results']['updated'][$node_type] = $context['sandbox']['updated'];
      $context['results']['skipped'][$node_type] = $context['sandbox']['skipped'];
      $context['results']['errors'][$node_type] = $context['sandbox']['errors'];
      $context['finished'] = 1;
      return;
    }

    foreach ($nodes_to_process as $node_data) {
      try {
        $result = self::importNode($node_data, $context['sandbox']['base_url']);

        if ($result['action'] === 'import') {
          $context['sandbox']['imported']++;
        }
        elseif ($result['action'] === 'update') {
          $context['sandbox']['updated']++;
        }
        elseif ($result['action'] === 'skip') {
          $context['sandbox']['skipped']++;
        }

        if ($result['node']) {
          $mapping_service->saveMapping('node', $node_data['nid'], $result['node']->id(), $node_type);
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка імпорту node @nid: @message',
          ['@nid' => $node_data['nid'], '@message' => $e->getMessage()]
        );
      }

      $context['sandbox']['progress']++;
    }

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    $context['message'] = t(
      '@type: @current/@total (імпорт: @import, оновлено: @update, пропущено: @skip)',
      [
        '@type' => $node_type,
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['total'],
        '@import' => $context['sandbox']['imported'],
        '@update' => $context['sandbox']['updated'],
        '@skip' => $context['sandbox']['skipped'],
      ]
    );

    if ($context['finished'] >= 1) {
      $context['results']['imported'][$node_type] = $context['sandbox']['imported'];
      $context['results']['updated'][$node_type] = $context['sandbox']['updated'];
      $context['results']['skipped'][$node_type] = $context['sandbox']['skipped'];
      $context['results']['errors'][$node_type] = $context['sandbox']['errors'];
    }
  }

  /**
   * Імпортувати одну ноду (оригінал або переклад).
   */
  protected static function importNode(array $node_data, string $base_url): array {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $nid = $node_data['nid'];
    $tnid = $node_data['tnid'] ?? $nid;
    $node_type = $node_data['type'];
    $language = $node_data['language'] ?? 'uk';
    $is_translation = !empty($tnid) && $tnid != $nid && $tnid != '0';

    \Drupal::logger('migrate_from_drupal7')->info(
      'Імпорт: nid=@nid, tnid=@tnid, lang=@lang, is_trans=@trans',
      [
        '@nid' => $nid,
        '@tnid' => $tnid,
        '@lang' => $language,
        '@trans' => $is_translation ? 'ТАК' : 'НІ',
      ]
    );

    if ($is_translation) {
      // Це переклад - шукаємо оригінал.
      $original_new_nid = $mapping_service->getNewId('node', $tnid, $node_type);

      if (!$original_new_nid) {
        \Drupal::logger('migrate_from_drupal7')->warning('Оригінал не знайдено для tnid=@tnid', ['@tnid' => $tnid]);
        return ['node' => NULL, 'action' => 'skip'];
      }

      $original_node = Node::load($original_new_nid);

      if (!$original_node || $original_node->hasTranslation($language)) {
        \Drupal::logger('migrate_from_drupal7')->warning('Оригінал не завантажено або переклад існує');
        return ['node' => NULL, 'action' => 'skip'];
      }

      // ВАЖЛИВО: Перевіряємо та очищуємо поля в оригіналі перед створенням перекладу.
      $skip_fields = ['nid', 'vid', 'uuid', 'langcode', 'type', 'title',
                      'revision_timestamp', 'revision_uid', 'revision_log',
                      'default_langcode', 'revision_translation_affected',
                      'created', 'changed', 'status', 'promote', 'sticky', 'uid'];

      foreach ($original_node->getFields(FALSE) as $field_name => $field_item_list) {
        if (in_array($field_name, $skip_fields)) {
          continue;
        }

        try {
          $value = $field_item_list->getValue();
          if (!is_array($value)) {
            \Drupal::logger('migrate_from_drupal7')->warning(
              'ОРИГІНАЛ має поле @field з некоректним значенням @type, виправляємо',
              ['@field' => $field_name, '@type' => gettype($value)]
            );
            $original_node->set($field_name, []);
          }
        }
        catch (\Exception $e) {
          // Ігноруємо помилки.
        }
      }

      // Створюємо переклад.
      return self::createTranslation($original_node, $node_data, $base_url);
    }
    else {
      // Це оригінал.
      return self::createOrUpdateOriginal($node_data, $base_url);
    }
  }

  /**
   * Створити або оновити оригінальну ноду.
   */
  protected static function createOrUpdateOriginal(array $node_data, string $base_url): array {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $nid = $node_data['nid'];
    $node_type = $node_data['type'];

    // Перевіряємо чи існує.
    $existing_nid = $mapping_service->getNewId('node', $nid, $node_type);
    $node = $existing_nid ? Node::load($existing_nid) : NULL;

    if ($node) {
      // Оновлюємо.
      self::setNodeFields($node, $node_data, $base_url);
      $node->save();
      return ['node' => $node, 'action' => 'update'];
    }
    else {
      // Створюємо нову.
      $node = Node::create([
        'type' => $node_type,
        'langcode' => $node_data['language'] ?? 'uk',
        'uid' => 1,
      ]);

      self::setNodeFields($node, $node_data, $base_url);
      $node->save();
      return ['node' => $node, 'action' => 'import'];
    }
  }

  /**
   * Створити переклад.
   */
  protected static function createTranslation(Node $original_node, array $node_data, string $base_url): array {
    $language = $node_data['language'] ?? 'uk';

    try {
      // Створюємо переклад простим способом.
      $translation = $original_node->addTranslation($language, []);

      // ВАЖЛИВО: Одразу після addTranslation() очищуємо всі поля.
      // addTranslation() копіює поля з оригіналу, і якесь може мати значення TRUE.
      $skip_fields = ['nid', 'vid', 'uuid', 'langcode', 'type', 'title',
                      'revision_timestamp', 'revision_uid', 'revision_log',
                      'default_langcode', 'revision_translation_affected',
                      'created', 'changed', 'status', 'promote', 'sticky', 'uid'];

      foreach ($translation->getFields(FALSE) as $field_name => $field_item_list) {
        if (in_array($field_name, $skip_fields)) {
          continue;
        }

        try {
          $value = $field_item_list->getValue();
          if (!is_array($value)) {
            \Drupal::logger('migrate_from_drupal7')->warning(
              'Поле @field має некоректне значення @type після addTranslation, очищуємо',
              ['@field' => $field_name, '@type' => gettype($value)]
            );
            $translation->set($field_name, []);
          }
        }
        catch (\Exception $e) {
          // Ігноруємо помилки.
        }
      }

      // Спочатку спробуємо зберегти ПОРОЖНІЙ переклад, щоб побачити чи проблема в addTranslation() чи в полях.
      \Drupal::logger('migrate_from_drupal7')->info('Спроба зберегти порожній переклад...');

      try {
        $translation->save();
        \Drupal::logger('migrate_from_drupal7')->info('Порожній переклад збережено успішно!');
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error('Помилка збереження ПОРОЖНЬОГО перекладу: @msg', ['@msg' => $e->getMessage()]);
        throw $e;
      }

      // Тепер встановлюємо поля.
      \Drupal::logger('migrate_from_drupal7')->info('Встановлюємо поля...');
      self::setNodeFields($translation, $node_data, $base_url);

      // Зберігаємо знову з полями.
      \Drupal::logger('migrate_from_drupal7')->info('Спроба зберегти переклад з полями...');

      try {
        $translation->save();
        \Drupal::logger('migrate_from_drupal7')->info('Переклад @lang з полями збережено успішно!', ['@lang' => $language]);
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error('Помилка збереження перекладу З ПОЛЯМИ: @msg', ['@msg' => $e->getMessage()]);
        throw $e;
      }

      return ['node' => $translation, 'action' => 'import'];
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error(
        'Помилка створення перекладу: @message',
        ['@message' => $e->getMessage()]
      );
      return ['node' => NULL, 'action' => 'skip'];
    }
  }

  /**
   * Встановити всі поля ноди з даних JSON.
   */
  protected static function setNodeFields($node, array $node_data, string $base_url): void {
    // Базові поля.
    $node->set('title', $node_data['title']);
    $node->set('status', (int) ($node_data['status'] ?? 1));
    $node->set('promote', (int) ($node_data['promote'] ?? 0));
    $node->set('sticky', (int) ($node_data['sticky'] ?? 0));
    $node->set('created', (int) ($node_data['created'] ?? time()));
    $node->set('changed', (int) ($node_data['changed'] ?? time()));

    // Body.
    if (!empty($node_data['fields']['body'][0])) {
      $body = $node_data['fields']['body'][0];
      try {
        $node->set('body', [
          'value' => $body['value'] ?? '',
          'format' => $body['format'] ?? 'full_html',
        ]);
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error('Помилка встановлення body: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    // Інші поля.
    if (!empty($node_data['fields'])) {
      $fields = $node_data['fields'];
      unset($fields['body']);

      foreach ($fields as $field_name => $field_values) {
        if (!$node->hasField($field_name) || empty($field_values)) {
          continue;
        }

        try {
          $field_definition = $node->getFieldDefinition($field_name);
          $field_type = $field_definition->getType();

          $processed_values = [];

          foreach ($field_values as $field_value) {
            if (!is_array($field_value)) {
              continue;
            }

            switch ($field_type) {
              case 'image':
              case 'file':
                $file_entity = self::importFile($field_value, $field_definition, $node, $base_url);
                if ($file_entity) {
                  $file_data = ['target_id' => $file_entity->id()];
                  if ($field_type === 'image') {
                    $file_data['alt'] = $field_value['alt'] ?? '';
                    $file_data['title'] = $field_value['title'] ?? '';
                  }
                  $processed_values[] = $file_data;
                }
                break;

              case 'entity_reference':
                if (!empty($field_value['tid'])) {
                  // Для таксономії потрібен маппінг, але зараз просто пропускаємо.
                  // TODO: Додати маппінг таксономії.
                }
                break;

              default:
                if (isset($field_value['value'])) {
                  $processed_values[] = ['value' => $field_value['value']];
                }
                break;
            }
          }

          if (!empty($processed_values)) {
            $node->set($field_name, $processed_values);
            \Drupal::logger('migrate_from_drupal7')->info('Встановлено поле @field', ['@field' => $field_name]);
          }
        }
        catch (\Exception $e) {
          \Drupal::logger('migrate_from_drupal7')->error(
            'Помилка встановлення поля @field: @msg',
            ['@field' => $field_name, '@msg' => $e->getMessage()]
          );
        }
      }
    }
  }

  /**
   * Імпортувати файл.
   */
  protected static function importFile(array $field_value, $field_definition, $node, string $base_url): ?File {
    if (empty($base_url)) {
      return NULL;
    }

    $file_url = NULL;

    // Якщо є fid - запитуємо API.
    if (!empty($field_value['fid'])) {
      $api_client = \Drupal::service('migrate_from_drupal7.api_client');
      $file_data = $api_client->getFileById($field_value['fid']);
      $file_url = $file_data['url'] ?? $file_data['uri'] ?? NULL;
    }
    else {
      $file_url = $field_value['url'] ?? $field_value['uri'] ?? NULL;
    }

    if (empty($file_url)) {
      return NULL;
    }

    // Конвертуємо Drupal схему в HTTP URL.
    if (preg_match('/^(public|private):\/\/(.+)$/', $file_url, $matches)) {
      $path = $matches[2];
      $file_url = rtrim($base_url, '/') . '/sites/default/files/' . ltrim($path, '/');
    }
    elseif (strpos($file_url, 'http') !== 0) {
      $file_url = rtrim($base_url, '/') . '/' . ltrim($file_url, '/');
    }

    // Визначаємо директорію.
    $field_settings = $field_definition->getSettings();
    $uri_scheme = $field_settings['uri_scheme'] ?? 'public';
    $file_directory = $field_settings['file_directory'] ?? '';

    if (!empty($file_directory)) {
      $file_directory = \Drupal::token()->replace($file_directory, ['node' => $node]);
    }

    $destination_directory = $uri_scheme . '://';
    if (!empty($file_directory)) {
      $destination_directory .= $file_directory . '/';
    }

    // Завантажуємо файл.
    try {
      $file_entity = self::downloadFile($file_url, $destination_directory);
      if ($file_entity) {
        $file_entity->setPermanent();
        $file_entity->save();
        return $file_entity;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка завантаження файлу: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Завантажити файл з URL.
   */
  protected static function downloadFile(string $url, string $destination_directory): ?File {
    try {
      $file_system = \Drupal::service('file_system');
      $file_system->prepareDirectory($destination_directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

      $http_client = \Drupal::httpClient();
      $response = $http_client->get($url);

      if ($response->getStatusCode() !== 200) {
        return NULL;
      }

      $file_content = $response->getBody()->getContents();
      $filename = basename(parse_url($url, PHP_URL_PATH));
      $destination = $destination_directory . $filename;
      $file_uri = $file_system->saveData($file_content, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME);

      if ($file_uri) {
        $file = File::create([
          'uri' => $file_uri,
          'status' => 1,
          'uid' => \Drupal::currentUser()->id(),
        ]);
        $file->save();
        return $file;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('downloadFile error: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Batch завершено.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      $total_imported = array_sum($results['imported'] ?? []);
      $total_updated = array_sum($results['updated'] ?? []);
      $total_skipped = array_sum($results['skipped'] ?? []);
      $total_errors = array_sum($results['errors'] ?? []);

      $messenger->addStatus(t(
        'Імпорт завершено. Імпортовано: @import, Оновлено: @update, Пропущено: @skip, Помилок: @errors',
        [
          '@import' => $total_imported,
          '@update' => $total_updated,
          '@skip' => $total_skipped,
          '@errors' => $total_errors,
        ]
      ));
    }
    else {
      $messenger->addError(t('Виникла помилка під час імпорту.'));
    }
  }

}
