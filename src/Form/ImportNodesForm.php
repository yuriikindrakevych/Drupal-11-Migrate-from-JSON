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
 * Форма для імпорту матеріалів (nodes) з Drupal 7.
 */
class ImportNodesForm extends FormBase {

  /**
   * API клієнт.
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
   * Mapping service.
   *
   * @var \Drupal\migrate_from_drupal7\Service\MappingService
   */
  protected $mappingService;

  /**
   * Log service.
   *
   * @var \Drupal\migrate_from_drupal7\Service\LogService
   */
  protected $logService;

  /**
   * Конструктор.
   */
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

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('migrate_from_drupal7.api_client'),
      $container->get('entity_type.manager'),
      $container->get('migrate_from_drupal7.mapping'),
      $container->get('migrate_from_drupal7.log')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'migrate_from_drupal7_import_nodes';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Отримуємо всі типи матеріалів в Drupal 11.
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
      '#markup' => '<p>' . $this->t('Виберіть типи матеріалів для імпорту. Матеріали імпортуються порціями, існуючі оновлюються на основі поля "changed".') . '</p>',
    ];

    $form['node_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Типи матеріалів для імпорту'),
      '#options' => [],
      '#description' => $this->t('Виберіть типи матеріалів, які потрібно імпортувати з Drupal 7.'),
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
      '#description' => $this->t('Скільки матеріалів обробляти за один раз. Для великих матеріалів зменшіть значення.'),
    ];

    $form['skip_unchanged'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Пропускати незмінені матеріали'),
      '#default_value' => TRUE,
      '#description' => $this->t('Якщо увімкнено, матеріали що не змінились (за полем "changed") не будуть оновлюватися.'),
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

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node_types = array_filter($form_state->getValue('node_types'));
    $batch_size = (int) $form_state->getValue('batch_size');
    $skip_unchanged = (bool) $form_state->getValue('skip_unchanged');

    if (empty($node_types)) {
      $this->messenger()->addError($this->t('Виберіть хоча б один тип матеріалу.'));
      return;
    }

    // Створюємо batch.
    $batch = [
      'title' => $this->t('Імпорт матеріалів'),
      'operations' => [],
      'finished' => [$this, 'batchFinished'],
      'progressive' => TRUE,
    ];

    // Додаємо операції для кожного типу матеріалу.
    foreach ($node_types as $node_type) {
      $batch['operations'][] = [
        [self::class, 'batchImportNodes'],
        [$node_type, $batch_size, $skip_unchanged],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch операція: імпорт матеріалів.
   *
   * Стратегія:
   * 1. Отримуємо список NID порціями (limit/offset)
   * 2. Для кожного NID отримуємо повні дані
   * 3. Перевіряємо чи існує маппінг
   * 4. Якщо існує і skip_unchanged - порівнюємо changed
   * 5. Створюємо або оновлюємо node
   * 6. Зберігаємо маппінг та логуємо
   */
  public static function batchImportNodes($node_type, $batch_size, $skip_unchanged, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $log_service = \Drupal::service('migrate_from_drupal7.log');

    // Ініціалізація.
    if (!isset($context['sandbox']['progress'])) {
      // API повертає просто масив без total.
      // Отримуємо всі NID одним запитом для підрахунку.
      $initial_data = $api_client->getNodes($node_type, 9999, 0);

      // Логуємо отриману відповідь для діагностики.
      \Drupal::logger('migrate_from_drupal7')->info(
        'API відповідь для типу @type: отримано @count записів',
        [
          '@type' => $node_type,
          '@count' => is_array($initial_data) ? count($initial_data) : 0,
        ]
      );

      if (empty($initial_data) || !is_array($initial_data)) {
        $context['results']['errors'][$node_type] = 1;
        $context['finished'] = 1;

        $log_service->logError(
          'import',
          'node',
          "Не вдалося отримати матеріали типу $node_type або API повернув порожній масив",
          NULL,
          ['node_type' => $node_type]
        );

        return;
      }

      // Зберігаємо всі NID для обробки.
      $context['sandbox']['all_nodes'] = $initial_data;
      $context['sandbox']['total'] = count($initial_data);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['node_type'] = $node_type;
      $context['sandbox']['batch_size'] = $batch_size;
      $context['sandbox']['skip_unchanged'] = $skip_unchanged;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['updated'] = 0;
      $context['sandbox']['skipped'] = 0;
      $context['sandbox']['errors'] = 0;

      \Drupal::logger('migrate_from_drupal7')->info(
        'Початок імпорту @count матеріалів типу @type',
        ['@count' => $context['sandbox']['total'], '@type' => $node_type]
      );

      $log_service->log(
        'import',
        'node',
        'success',
        "Початок імпорту матеріалів типу $node_type",
        NULL,
        [
          'node_type' => $node_type,
          'total' => $context['sandbox']['total'],
          'batch_size' => $batch_size,
        ],
        \Drupal::currentUser()->id()
      );
    }

    // Беремо порцію з вже завантаженого масиву.
    $nodes_to_process = array_slice(
      $context['sandbox']['all_nodes'],
      $context['sandbox']['progress'],
      $batch_size
    );

    if (empty($nodes_to_process)) {
      // Зберігаємо результати.
      $context['results']['imported'][$node_type] = $context['sandbox']['imported'];
      $context['results']['updated'][$node_type] = $context['sandbox']['updated'];
      $context['results']['skipped'][$node_type] = $context['sandbox']['skipped'];
      $context['results']['errors'][$node_type] = $context['sandbox']['errors'];
      $context['finished'] = 1;
      return;
    }

    // Обробляємо кожен node.
    foreach ($nodes_to_process as $node_preview) {
      try {
        // Отримуємо повні дані node.
        $node_data = $api_client->getNodeById($node_preview['nid']);

        if (empty($node_data)) {
          $context['sandbox']['errors']++;
          $log_service->logError(
            'import',
            'node',
            "Не вдалося завантажити дані для node {$node_preview['nid']}",
            (string) $node_preview['nid'],
            ['node_type' => $node_type]
          );
          continue;
        }

        // Імпортуємо або оновлюємо node.
        $result = self::importOrUpdateNode($node_data, $skip_unchanged);

        if ($result['action'] === 'import') {
          $context['sandbox']['imported']++;
        }
        elseif ($result['action'] === 'update') {
          $context['sandbox']['updated']++;
        }
        elseif ($result['action'] === 'skip') {
          $context['sandbox']['skipped']++;
        }

        // Зберігаємо маппінг.
        if ($result['node']) {
          $mapping_service->saveMapping(
            'node',
            $node_data['nid'],
            $result['node']->id(),
            $node_type
          );

          // Логуємо.
          $log_service->logSuccess(
            $result['action'] === 'import' ? 'import' : 'update',
            'node',
            "{$result['action']}: {$node_data['title']}",
            (string) $result['node']->id(),
            [
              'old_nid' => $node_data['nid'],
              'new_nid' => $result['node']->id(),
              'node_type' => $node_type,
              'changed' => $node_data['changed'] ?? NULL,
            ]
          );
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        $log_service->logError(
          'import',
          'node',
          "Помилка імпорту node {$node_preview['nid']}: {$e->getMessage()}",
          (string) $node_preview['nid'],
          [
            'node_type' => $node_type,
            'error' => $e->getMessage(),
          ]
        );
      }

      $context['sandbox']['progress']++;
    }

    // Розрахунок прогресу.
    $context['finished'] = $context['sandbox']['total'] > 0
      ? $context['sandbox']['progress'] / $context['sandbox']['total']
      : 1;

    $context['message'] = t(
      'Тип: @type | Оброблено: @current з @total (імпорт: @import, оновлено: @update, пропущено: @skip, помилок: @errors)',
      [
        '@type' => $node_type,
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['total'],
        '@import' => $context['sandbox']['imported'],
        '@update' => $context['sandbox']['updated'],
        '@skip' => $context['sandbox']['skipped'],
        '@errors' => $context['sandbox']['errors'],
      ]
    );

    // Зберігаємо результати.
    if ($context['finished'] >= 1) {
      $context['results']['imported'][$node_type] = $context['sandbox']['imported'];
      $context['results']['updated'][$node_type] = $context['sandbox']['updated'];
      $context['results']['skipped'][$node_type] = $context['sandbox']['skipped'];
      $context['results']['errors'][$node_type] = $context['sandbox']['errors'];
    }
  }

  /**
   * Імпортувати або оновити node.
   *
   * @param array $node_data
   *   Дані node з Drupal 7.
   * @param bool $skip_unchanged
   *   Пропускати незмінені матеріали.
   *
   * @return array
   *   ['node' => Node|null, 'action' => 'import'|'update'|'skip']
   */
  protected static function importOrUpdateNode(array $node_data, bool $skip_unchanged): array {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $old_nid = $node_data['nid'];
    $node_type = $node_data['type'];

    // Перевіряємо чи існує маппінг.
    $existing_new_nid = $mapping_service->getNewId('node', $old_nid, $node_type);
    $is_update = FALSE;
    $node = NULL;

    if ($existing_new_nid) {
      // Завантажуємо існуючий node.
      $node = Node::load($existing_new_nid);

      if ($node) {
        $is_update = TRUE;

        // Перевіряємо чи потрібно оновлювати.
        if ($skip_unchanged && isset($node_data['changed'])) {
          $existing_changed = $node->getChangedTime();
          $new_changed = (int) $node_data['changed'];

          if ($new_changed <= $existing_changed) {
            // Пропускаємо - не змінювався.
            return ['node' => $node, 'action' => 'skip'];
          }
        }
      }
    }

    // Створюємо або оновлюємо node.
    if ($is_update && $node) {
      // Оновлюємо існуючий.
      self::updateNode($node, $node_data);
      $action = 'update';
    }
    else {
      // Створюємо новий.
      $node = self::createNode($node_data);
      $action = 'import';
    }

    if ($node) {
      $node->save();

      // Додаємо переклади.
      if (!empty($node_data['translations'])) {
        self::addTranslations($node, $node_data['translations']);
      }
    }

    return ['node' => $node, 'action' => $action];
  }

  /**
   * Створити новий node.
   */
  protected static function createNode(array $node_data): Node {
    $values = [
      'type' => $node_data['type'],
      'title' => $node_data['title'],
      'langcode' => $node_data['language'] ?? 'uk',
      'uid' => 1,  // Admin
      'status' => $node_data['status'] ?? 1,
      'promote' => $node_data['promote'] ?? 0,
      'sticky' => $node_data['sticky'] ?? 0,
      'created' => $node_data['created'] ?? \Drupal::time()->getRequestTime(),
      'changed' => $node_data['changed'] ?? \Drupal::time()->getRequestTime(),
    ];

    // Body може бути в fields.body або на верхньому рівні.
    $body_data = NULL;
    if (!empty($node_data['fields']['body'][0])) {
      $body_data = $node_data['fields']['body'][0];
    }
    elseif (!empty($node_data['body'])) {
      $body_data = $node_data['body'];
    }

    if ($body_data) {
      $values['body'] = [
        'value' => $body_data['value'] ?? '',
        'format' => $body_data['format'] ?? 'full_html',
      ];
    }

    $node = Node::create($values);

    // Імпортуємо додаткові поля (окрім body який вже додали).
    if (!empty($node_data['fields'])) {
      $fields_to_import = $node_data['fields'];
      unset($fields_to_import['body']);  // body вже імпортували
      self::importFields($node, $fields_to_import);
    }

    return $node;
  }

  /**
   * Оновити існуючий node.
   */
  protected static function updateNode(Node $node, array $node_data): void {
    $node->set('title', $node_data['title']);
    $node->set('status', $node_data['status'] ?? 1);
    $node->set('promote', $node_data['promote'] ?? 0);
    $node->set('sticky', $node_data['sticky'] ?? 0);
    $node->set('changed', $node_data['changed'] ?? \Drupal::time()->getRequestTime());

    // Body може бути в fields.body або на верхньому рівні.
    $body_data = NULL;
    if (!empty($node_data['fields']['body'][0])) {
      $body_data = $node_data['fields']['body'][0];
    }
    elseif (!empty($node_data['body'])) {
      $body_data = $node_data['body'];
    }

    if ($body_data) {
      $node->set('body', [
        'value' => $body_data['value'] ?? '',
        'format' => $body_data['format'] ?? 'full_html',
      ]);
    }

    // Оновлюємо додаткові поля (окрім body).
    if (!empty($node_data['fields'])) {
      $fields_to_import = $node_data['fields'];
      unset($fields_to_import['body']);  // body вже імпортували
      self::importFields($node, $fields_to_import);
    }
  }

  /**
   * Імпортувати поля node.
   */
  protected static function importFields(Node $node, array $fields_data): void {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');

    foreach ($fields_data as $field_name => $field_values) {
      if (!$node->hasField($field_name) || empty($field_values)) {
        continue;
      }

      try {
        $field_definition = $node->getFieldDefinition($field_name);
        $field_type = $field_definition->getType();

        $processed_values = [];

        foreach ($field_values as $field_value) {
          // Обробка різних типів полів.
          switch ($field_type) {
            case 'entity_reference':
              // Для таксономії - маппінг tid.
              // Drupal 7 може повертати як {"tid": "123"} так і {"target_id": "123"}
              $old_id = NULL;
              if (isset($field_value['tid'])) {
                $old_id = $field_value['tid'];
              }
              elseif (isset($field_value['target_id'])) {
                $old_id = $field_value['target_id'];
              }

              if ($old_id) {
                $target_type = $field_definition->getSetting('target_type');

                if ($target_type === 'taxonomy_term') {
                  // Шукаємо новий tid через маппінг.
                  $vocabulary = $field_definition->getSetting('handler_settings')['target_bundles'] ?? [];
                  $vocabulary = reset($vocabulary);

                  $new_tid = $mapping_service->getNewId('term', (string) $old_id, $vocabulary);

                  if ($new_tid) {
                    $processed_values[] = ['target_id' => $new_tid];
                  }
                  else {
                    // Якщо не знайдено маппінг - пропускаємо.
                    \Drupal::logger('migrate_from_drupal7')->warning(
                      'Не знайдено маппінг для tid @tid в полі @field',
                      ['@tid' => $old_id, '@field' => $field_name]
                    );
                  }
                }
                else {
                  // Інші entity_reference - просто передаємо.
                  $processed_values[] = ['target_id' => $old_id];
                }
              }
              break;

            case 'text_long':
            case 'text_with_summary':
              $processed_values[] = [
                'value' => $field_value['value'] ?? '',
                'format' => $field_value['format'] ?? 'full_html',
              ];
              break;

            case 'image':
            case 'file':
              // TODO: Імпорт файлів.
              // Поки що пропускаємо.
              break;

            case 'string':
            case 'integer':
            case 'decimal':
            case 'float':
              // Прості value поля.
              if (isset($field_value['value'])) {
                $processed_values[] = ['value' => $field_value['value']];
              }
              break;

            default:
              // Для інших типів - передаємо як є.
              if (isset($field_value['value'])) {
                $processed_values[] = ['value' => $field_value['value']];
              }
              else {
                $processed_values[] = $field_value;
              }
              break;
          }
        }

        if (!empty($processed_values)) {
          $node->set($field_name, $processed_values);
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
   * Додати переклади до node.
   */
  protected static function addTranslations(Node $node, array $translations): void {
    foreach ($translations as $langcode => $translation_data) {
      if (!$node->hasTranslation($langcode)) {
        try {
          $translation_values = [
            'title' => $translation_data['title'],
          ];

          // Body для перекладу.
          if (!empty($translation_data['body'])) {
            $translation_values['body'] = [
              'value' => $translation_data['body']['value'] ?? '',
              'format' => $translation_data['body']['format'] ?? 'basic_html',
            ];
          }

          $node->addTranslation($langcode, $translation_values);
          $node->save();
        }
        catch (\Exception $e) {
          \Drupal::logger('migrate_from_drupal7')->error(
            'Помилка додавання перекладу @lang для node @nid: @message',
            [
              '@lang' => $langcode,
              '@nid' => $node->id(),
              '@message' => $e->getMessage(),
            ]
          );
        }
      }
    }
  }

  /**
   * Batch завершено.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    $log_service = \Drupal::service('migrate_from_drupal7.log');

    if ($success) {
      // Безпечний підрахунок результатів.
      $total_imported = 0;
      $total_updated = 0;
      $total_skipped = 0;
      $total_errors = 0;

      if (!empty($results['imported']) && is_array($results['imported'])) {
        $total_imported = array_sum(array_filter($results['imported'], 'is_numeric'));
      }
      if (!empty($results['updated']) && is_array($results['updated'])) {
        $total_updated = array_sum(array_filter($results['updated'], 'is_numeric'));
      }
      if (!empty($results['skipped']) && is_array($results['skipped'])) {
        $total_skipped = array_sum(array_filter($results['skipped'], 'is_numeric'));
      }
      if (!empty($results['errors']) && is_array($results['errors'])) {
        $total_errors = array_sum(array_filter($results['errors'], 'is_numeric'));
      }

      $messenger->addStatus(t(
        'Імпорт завершено. Імпортовано: @import, Оновлено: @update, Пропущено: @skip, Помилок: @errors',
        [
          '@import' => $total_imported,
          '@update' => $total_updated,
          '@skip' => $total_skipped,
          '@errors' => $total_errors,
        ]
      ));

      // Логуємо завершення.
      $log_service->logSuccess(
        'import',
        'node',
        'Імпорт матеріалів завершено',
        NULL,
        [
          'imported' => $total_imported,
          'updated' => $total_updated,
          'skipped' => $total_skipped,
          'errors' => $total_errors,
        ]
      );
    }
    else {
      $messenger->addError(t('Виникла помилка під час імпорту матеріалів.'));
      $log_service->logError('import', 'node', 'Помилка імпорту матеріалів');
    }
  }

}
