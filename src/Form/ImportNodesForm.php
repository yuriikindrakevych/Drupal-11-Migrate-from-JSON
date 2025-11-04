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

      // Зберігаємо список nid для обробки.
      $context['sandbox']['node_list'] = $all_nodes;
      $context['sandbox']['total'] = count($all_nodes);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['errors'] = 0;
      $context['sandbox']['processed_nids'] = []; // Відстежуємо оброблені nid.
    }

    // Обробляємо по 5 nid за раз (кожен nid може мати декілька мов).
    $batch_size = 5;
    $node_list_slice = array_slice(
      $context['sandbox']['node_list'],
      $context['sandbox']['progress'],
      $batch_size
    );

    foreach ($node_list_slice as $node_preview) {
      $nid = $node_preview['nid'];

      // Перевіряємо чи цей nid вже був оброблений (як частина групи перекладів).
      if (in_array($nid, $context['sandbox']['processed_nids'])) {
        \Drupal::logger('migrate_from_drupal7')->info('Пропускаємо nid=@nid - вже оброблено', ['@nid' => $nid]);
        $context['sandbox']['progress']++;
        continue;
      }

      try {
        // Завантажуємо ноду з усіма перекладами.
        $nodes_data = $api_client->getNodeById($nid);

        if (empty($nodes_data)) {
          $context['sandbox']['errors']++;
          $log_service->logError('import', 'node', 'Не вдалося завантажити дані ноди', (string) $nid, []);
          $context['sandbox']['progress']++;
          continue;
        }

        // Перевіряємо чи це масив масивів чи один масив.
        // Якщо це один масив (одна нода без перекладів), загортаємо в масив.
        if (isset($nodes_data['nid']) && !isset($nodes_data[0])) {
          $nodes_data = [$nodes_data];
        }

        if (!is_array($nodes_data)) {
          $context['sandbox']['errors']++;
          $log_service->logError('import', 'node', 'Некоректний формат даних ноди', (string) $nid, []);
          \Drupal::logger('migrate_from_drupal7')->error('Некоректний формат даних для nid=@nid: @data', [
            '@nid' => $nid,
            '@data' => print_r($nodes_data, TRUE),
          ]);
          continue;
        }

        // Розділяємо на оригінал та переклади.
        $original_data = NULL;
        $translations_data = [];

        foreach ($nodes_data as $node_data) {
          if (!is_array($node_data) || empty($node_data['nid'])) {
            \Drupal::logger('migrate_from_drupal7')->warning('Пропущено некоректний елемент для nid=@nid', ['@nid' => $nid]);
            continue;
          }

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
          // Додаємо nid оригіналу до оброблених.
          $context['sandbox']['processed_nids'][] = $original_data['nid'];
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
          // Додаємо nid перекладу до оброблених.
          $context['sandbox']['processed_nids'][] = $translation_data['nid'];
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error('Помилка: @msg', ['@msg' => $e->getMessage()]);

        $log_service->logError(
          'import',
          'node',
          'Помилка імпорту: ' . $e->getMessage(),
          (string) $nid,
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

    foreach ($node_data['fields'] as $field_name => $field_values) {
      if (empty($field_values) || !is_array($field_values)) {
        continue;
      }

      // Обробка body (text_with_summary).
      if ($field_name === 'body') {
        $body_data = $field_values[0] ?? [];
        if (!empty($body_data['value'])) {
          $fields_data[$field_name] = [
            'value' => $body_data['value'],
            'summary' => $body_data['summary'] ?? '',
            'format' => !empty($body_data['format']) ? $body_data['format'] : 'plain_text',
          ];
        }
      }
      // Обробка зображень (field_image тощо).
      elseif (strpos($field_name, 'field_') === 0 && !empty($field_values[0]['fid'])) {
        // TODO: Додати обробку зображень - завантаження файлів.
        // Поки що пропускаємо.
      }
      // Обробка простих текстових полів.
      elseif (!empty($field_values[0]['value'])) {
        $fields_data[$field_name] = $field_values[0]['value'];
      }
    }

    return $fields_data;
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
