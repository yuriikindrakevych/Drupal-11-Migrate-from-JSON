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
use GuzzleHttp\ClientInterface;

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

      // Розділяємо на оригінали та переклади для двопроходної обробки.
      $originals = [];
      $translations = [];

      foreach ($initial_data as $node_preview) {
        // Отримуємо базову інформацію про ноду.
        $nid = $node_preview['nid'];

        // Для визначення чи це переклад, потрібно завантажити повні дані.
        $node_full_data = $api_client->getNodeById($nid);

        if (!empty($node_full_data)) {
          $tnid = $node_full_data['tnid'] ?? $nid;
          $is_translation = !empty($tnid) && $tnid != $nid && $tnid != '0';

          if ($is_translation) {
            $translations[] = $node_preview;
          }
          else {
            $originals[] = $node_preview;
          }
        }
        else {
          // Якщо не вдалося завантажити дані, додаємо до оригіналів.
          $originals[] = $node_preview;
        }
      }

      // Об'єднуємо: спочатку оригінали, потім переклади.
      $context['sandbox']['all_nodes'] = array_merge($originals, $translations);
      $context['sandbox']['total'] = count($context['sandbox']['all_nodes']);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['node_type'] = $node_type;
      $context['sandbox']['batch_size'] = $batch_size;
      $context['sandbox']['skip_unchanged'] = $skip_unchanged;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['updated'] = 0;
      $context['sandbox']['skipped'] = 0;
      $context['sandbox']['errors'] = 0;
      $context['sandbox']['originals_count'] = count($originals);
      $context['sandbox']['translations_count'] = count($translations);

      // Зберігаємо base_url для використання в файлових операціях.
      $config = \Drupal::config('migrate_from_drupal7.settings');
      $context['sandbox']['base_url'] = $config->get('base_url') ?? '';

      \Drupal::logger('migrate_from_drupal7')->info(
        'Збережено base_url в sandbox: @url',
        ['@url' => $context['sandbox']['base_url']]
      );

      \Drupal::logger('migrate_from_drupal7')->info(
        'Початок імпорту @count матеріалів типу @type (оригіналів: @originals, перекладів: @translations)',
        [
          '@count' => $context['sandbox']['total'],
          '@type' => $node_type,
          '@originals' => $context['sandbox']['originals_count'],
          '@translations' => $context['sandbox']['translations_count'],
        ]
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
          'originals' => $context['sandbox']['originals_count'],
          'translations' => $context['sandbox']['translations_count'],
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
        $result = self::importOrUpdateNode($node_data, $skip_unchanged, $context['sandbox']['base_url'] ?? '');

        if ($result['action'] === 'import') {
          $context['sandbox']['imported']++;
        }
        elseif ($result['action'] === 'update') {
          $context['sandbox']['updated']++;
        }
        elseif ($result['action'] === 'skip') {
          $context['sandbox']['skipped']++;
        }
        elseif ($result['action'] === 'translation') {
          $context['sandbox']['imported']++;
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
   * @param string $base_url
   *   Base URL Drupal 7 сайту.
   *
   * @return array
   *   ['node' => Node|null, 'action' => 'import'|'update'|'skip']
   */
  protected static function importOrUpdateNode(array $node_data, bool $skip_unchanged, string $base_url): array {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $old_nid = $node_data['nid'];
    $node_type = $node_data['type'];
    $language = $node_data['language'] ?? 'uk';

    // Перевіряємо чи це переклад (tnid != nid).
    $tnid = $node_data['tnid'] ?? $old_nid;
    $is_translation = !empty($tnid) && $tnid != $old_nid && $tnid != '0';

    \Drupal::logger('migrate_from_drupal7')->info(
      'importOrUpdateNode: nid=@nid, tnid=@tnid, is_translation=@is_trans, language=@lang',
      [
        '@nid' => $old_nid,
        '@tnid' => $tnid,
        '@is_trans' => $is_translation ? 'YES' : 'NO',
        '@lang' => $language,
      ]
    );

    if ($is_translation) {
      // Це переклад - шукаємо оригінальну ноду.
      $original_new_nid = $mapping_service->getNewId('node', $tnid, $node_type);

      \Drupal::logger('migrate_from_drupal7')->info(
        'Шукаємо оригінал для перекладу: tnid=@tnid, new_nid=@new_nid',
        ['@tnid' => $tnid, '@new_nid' => $original_new_nid ?? 'NULL']
      );

      if ($original_new_nid) {
        $original_node = Node::load($original_new_nid);

        if ($original_node && !$original_node->hasTranslation($language)) {
          // Додаємо переклад до оригінальної ноди.
          try {
            $translation_values = [
              'title' => $node_data['title'],
              'status' => $node_data['status'] ?? 1,
              'promote' => $node_data['promote'] ?? 0,
              'sticky' => $node_data['sticky'] ?? 0,
              'created' => $node_data['created'] ?? \Drupal::time()->getRequestTime(),
              'changed' => $node_data['changed'] ?? \Drupal::time()->getRequestTime(),
            ];

            // Body для перекладу.
            $body_data = NULL;
            if (!empty($node_data['fields']['body'][0])) {
              $body_data = $node_data['fields']['body'][0];
            }
            elseif (!empty($node_data['body'])) {
              $body_data = $node_data['body'];
            }

            if ($body_data) {
              $body_value = $body_data['value'] ?? '';
              $body_format = $body_data['format'] ?? 'full_html';

              if ($body_format === 'full_html' && !empty($body_value)) {
                $body_value = self::processImagesInHtml($body_value, $base_url);
              }

              $translation_values['body'] = [
                'value' => $body_value,
                'format' => $body_format,
              ];
            }

            $translation = $original_node->addTranslation($language, $translation_values);

            // Імпортуємо поля для перекладу.
            if (!empty($node_data['fields'])) {
              $fields_to_import = $node_data['fields'];
              unset($fields_to_import['body']);
              self::importFields($translation, $fields_to_import, $base_url);
            }

            $translation->save();

            // Зберігаємо маппінг для перекладу.
            $mapping_service->saveMapping('node', $old_nid, $original_new_nid, $node_type);

            \Drupal::logger('migrate_from_drupal7')->info(
              'Додано переклад @lang для ноди @nid (original nid: @orig)',
              ['@lang' => $language, '@nid' => $old_nid, '@orig' => $original_new_nid]
            );

            return ['node' => $translation, 'action' => 'translation'];
          }
          catch (\Exception $e) {
            \Drupal::logger('migrate_from_drupal7')->error(
              'Помилка додавання перекладу @lang для node @nid: @message',
              ['@lang' => $language, '@nid' => $old_nid, '@message' => $e->getMessage()]
            );
          }
        }
        else {
          \Drupal::logger('migrate_from_drupal7')->warning(
            'Не знайдено оригінальну ноду або переклад вже існує: nid=@nid, tnid=@tnid, lang=@lang',
            ['@nid' => $old_nid, '@tnid' => $tnid, '@lang' => $language]
          );
        }
      }
      else {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Не знайдено mapping для оригінальної ноди: tnid=@tnid',
          ['@tnid' => $tnid]
        );
      }

      // Якщо це переклад і ми не змогли його додати, не створюємо окрему ноду.
      return ['node' => NULL, 'action' => 'skip'];
    }

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
      self::updateNode($node, $node_data, $base_url);
      $action = 'update';
    }
    else {
      // Створюємо новий.
      $node = self::createNode($node_data, $base_url);
      $action = 'import';
    }

    if ($node) {
      $node->save();
    }

    return ['node' => $node, 'action' => $action];
  }

  /**
   * Створити новий node.
   */
  protected static function createNode(array $node_data, string $base_url): Node {
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
      $body_value = $body_data['value'] ?? '';
      $body_format = $body_data['format'] ?? 'full_html';

      // Обробляємо зображення в HTML body.
      if ($body_format === 'full_html' && !empty($body_value)) {
        $body_value = self::processImagesInHtml($body_value, $base_url);
      }

      $values['body'] = [
        'value' => $body_value,
        'format' => $body_format,
      ];
    }

    $node = Node::create($values);

    // Імпортуємо додаткові поля (окрім body який вже додали).
    if (!empty($node_data['fields'])) {
      $fields_to_import = $node_data['fields'];
      unset($fields_to_import['body']);  // body вже імпортували
      self::importFields($node, $fields_to_import, $base_url);
    }

    return $node;
  }

  /**
   * Оновити існуючий node.
   */
  protected static function updateNode(Node $node, array $node_data, string $base_url): void {
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
      $body_value = $body_data['value'] ?? '';
      $body_format = $body_data['format'] ?? 'full_html';

      // Обробляємо зображення в HTML body.
      if ($body_format === 'full_html' && !empty($body_value)) {
        $body_value = self::processImagesInHtml($body_value, $base_url);
      }

      $node->set('body', [
        'value' => $body_value,
        'format' => $body_format,
      ]);
    }

    // Оновлюємо додаткові поля (окрім body).
    if (!empty($node_data['fields'])) {
      $fields_to_import = $node_data['fields'];
      unset($fields_to_import['body']);  // body вже імпортували
      self::importFields($node, $fields_to_import, $base_url);
    }
  }

  /**
   * Імпортувати поля node.
   */
  protected static function importFields(Node $node, array $fields_data, string $base_url): void {
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
              $text_value = $field_value['value'] ?? '';
              $text_format = $field_value['format'] ?? 'full_html';

              // Обробляємо зображення в HTML.
              if ($text_format === 'full_html' && !empty($text_value)) {
                $text_value = self::processImagesInHtml($text_value, $base_url);
              }

              $processed_values[] = [
                'value' => $text_value,
                'format' => $text_format,
              ];
              break;

            case 'image':
            case 'file':
              $file_entity = self::importFile($field_value, $field_definition, $node, $base_url);
              if ($file_entity) {
                $file_data = ['target_id' => $file_entity->id()];

                // Для image додаємо alt та title.
                if ($field_type === 'image') {
                  $alt_text = $field_value['alt'] ?? '';

                  // Якщо alt порожній, генеруємо з назви ноди.
                  if (empty($alt_text)) {
                    $node_title = $node->getTitle();
                    $current_count = count($processed_values) + 1;

                    // Якщо поле множинне та вже є значення.
                    if ($field_definition->getFieldStorageDefinition()->getCardinality() !== 1 && $current_count > 1) {
                      $alt_text = $node_title . ' - зображення ' . $current_count;
                    }
                    else {
                      $alt_text = $node_title;
                    }
                  }

                  $file_data['alt'] = $alt_text;
                  $file_data['title'] = $field_value['title'] ?? '';
                }

                $processed_values[] = $file_data;
              }
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
   * Імпортувати файл з Drupal 7.
   *
   * @param array $field_value
   *   Дані поля з Drupal 7.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Визначення поля.
   * @param \Drupal\node\Entity\Node $node
   *   Node до якого додається файл.
   * @param string $base_url
   *   Base URL Drupal 7 сайту.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity або NULL.
   */
  protected static function importFile(array $field_value, $field_definition, Node $node, string $base_url): ?File {
    if (empty($base_url)) {
      \Drupal::logger('migrate_from_drupal7')->warning('importFile: base_url порожній');
      return NULL;
    }

    \Drupal::logger('migrate_from_drupal7')->info('importFile: field_value = @value', [
      '@value' => print_r($field_value, TRUE),
    ]);

    // Якщо є fid - робимо запит до API за даними файлу.
    $file_data = NULL;
    if (!empty($field_value['fid'])) {
      $api_client = \Drupal::service('migrate_from_drupal7.api_client');
      $file_data = $api_client->getFileById($field_value['fid']);

      \Drupal::logger('migrate_from_drupal7')->info('importFile: file_data з API = @data', [
        '@data' => print_r($file_data, TRUE),
      ]);
    }

    // Отримуємо URL файлу.
    $file_url = NULL;
    if ($file_data) {
      $file_url = $file_data['url'] ?? $file_data['uri'] ?? NULL;
    }
    else {
      $file_url = $field_value['url'] ?? $field_value['uri'] ?? NULL;
    }

    if (empty($file_url)) {
      \Drupal::logger('migrate_from_drupal7')->warning('importFile: file_url порожній');
      return NULL;
    }

    \Drupal::logger('migrate_from_drupal7')->info('importFile: file_url = @url', ['@url' => $file_url]);

    // Конвертуємо Drupal схему (public://, private://) в HTTP URL.
    if (preg_match('/^(public|private):\/\/(.+)$/', $file_url, $matches)) {
      $scheme = $matches[1];
      $path = $matches[2];
      $file_url = rtrim($base_url, '/') . '/sites/default/files/' . ltrim($path, '/');
    }
    // Якщо це відносний шлях, додаємо base URL.
    elseif (strpos($file_url, 'http') !== 0) {
      $file_url = rtrim($base_url, '/') . '/' . ltrim($file_url, '/');
    }

    \Drupal::logger('migrate_from_drupal7')->info('importFile: остаточний URL = @url', ['@url' => $file_url]);

    // Отримуємо налаштування поля для визначення директорії.
    $field_settings = $field_definition->getSettings();
    $uri_scheme = $field_settings['uri_scheme'] ?? 'public';
    $file_directory = $field_settings['file_directory'] ?? '';

    // Замінюємо токени в шляху.
    if (!empty($file_directory)) {
      $file_directory = \Drupal::token()->replace($file_directory, ['node' => $node]);
    }

    // Формуємо шлях збереження.
    $destination_directory = $uri_scheme . '://';
    if (!empty($file_directory)) {
      $destination_directory .= $file_directory . '/';
    }

    // Завантажуємо файл.
    try {
      $file_entity = self::downloadFileFromUrl($file_url, $destination_directory);

      if ($file_entity) {
        $file_entity->setPermanent();
        $file_entity->save();
        return $file_entity;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error(
        'Помилка завантаження файлу @url: @message',
        ['@url' => $file_url, '@message' => $e->getMessage()]
      );
    }

    return NULL;
  }

  /**
   * Завантажити файл з URL.
   *
   * @param string $url
   *   URL файлу.
   * @param string $destination_directory
   *   Директорія призначення.
   *
   * @return \Drupal\file\Entity\File|null
   *   File entity або NULL.
   */
  protected static function downloadFileFromUrl(string $url, string $destination_directory): ?File {
    try {
      // Створюємо директорію якщо не існує.
      $file_system = \Drupal::service('file_system');
      $file_system->prepareDirectory($destination_directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

      // Завантажуємо файл через HTTP.
      $http_client = \Drupal::httpClient();
      $response = $http_client->get($url);

      if ($response->getStatusCode() !== 200) {
        return NULL;
      }

      $file_content = $response->getBody()->getContents();

      // Отримуємо ім'я файлу з URL.
      $filename = basename(parse_url($url, PHP_URL_PATH));

      // Зберігаємо файл.
      $destination = $destination_directory . $filename;
      $file_uri = $file_system->saveData($file_content, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME);

      if ($file_uri) {
        // Створюємо file entity.
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
      \Drupal::logger('migrate_from_drupal7')->error(
        'Помилка завантаження файлу з URL @url: @message',
        ['@url' => $url, '@message' => $e->getMessage()]
      );
    }

    return NULL;
  }

  /**
   * Обробити зображення в HTML тексті.
   *
   * @param string $html
   *   HTML текст.
   * @param string $base_url
   *   Base URL Drupal 7 сайту.
   *
   * @return string
   *   Оброблений HTML текст.
   */
  protected static function processImagesInHtml(string $html, string $base_url): string {
    if (empty($html) || empty($base_url)) {
      \Drupal::logger('migrate_from_drupal7')->info('processImagesInHtml: html або base_url порожній');
      return $html;
    }

    // Знаходимо всі <img> теги.
    $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

    preg_match_all($pattern, $html, $matches);

    \Drupal::logger('migrate_from_drupal7')->info('processImagesInHtml: знайдено @count img тегів', [
      '@count' => count($matches[0]),
    ]);

    if (empty($matches[0])) {
      return $html;
    }

    foreach ($matches[1] as $index => $img_src) {
      $original_src = $img_src;

      \Drupal::logger('migrate_from_drupal7')->info('processImagesInHtml: обробка src = @src', [
        '@src' => $img_src,
      ]);

      // Конвертуємо Drupal схему в HTTP URL.
      if (preg_match('/^(public|private):\/\/(.+)$/', $img_src, $matches_scheme)) {
        $path = $matches_scheme[2];
        $img_src = rtrim($base_url, '/') . '/sites/default/files/' . ltrim($path, '/');
      }

      // Пропускаємо зовнішні URL (не з Drupal 7).
      if (strpos($img_src, 'http') === 0 && strpos($img_src, $base_url) === FALSE) {
        \Drupal::logger('migrate_from_drupal7')->info('processImagesInHtml: пропущено зовнішній URL');
        continue;
      }

      // Формуємо повний URL.
      if (strpos($img_src, 'http') !== 0) {
        $full_url = rtrim($base_url, '/') . '/' . ltrim($img_src, '/');
      }
      else {
        $full_url = $img_src;
      }

      \Drupal::logger('migrate_from_drupal7')->info('processImagesInHtml: завантаження з @url', [
        '@url' => $full_url,
      ]);

      // Завантажуємо зображення.
      try {
        $file_entity = self::downloadFileFromUrl($full_url, 'public://inline-images/');

        if ($file_entity) {
          // Отримуємо новий URL.
          $new_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file_entity->getFileUri());

          \Drupal::logger('migrate_from_drupal7')->info('processImagesInHtml: заміна @old на @new', [
            '@old' => $original_src,
            '@new' => $new_url,
          ]);

          // Замінюємо старий src на новий.
          $html = str_replace($original_src, $new_url, $html);
        }
        else {
          \Drupal::logger('migrate_from_drupal7')->warning('processImagesInHtml: downloadFileFromUrl повернув NULL');
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Не вдалося завантажити зображення з HTML: @url - @error',
          ['@url' => $full_url, '@error' => $e->getMessage()]
        );
      }
    }

    return $html;
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
