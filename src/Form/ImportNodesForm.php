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
      // Завантажуємо всі ноди.
      $all_nodes = $api_client->getNodes($node_type, 9999, 0);

      if (empty($all_nodes)) {
        $context['finished'] = 1;
        return;
      }

      // Розділяємо на оригінали та переклади.
      $originals = [];
      $translations = [];

      foreach ($all_nodes as $node_preview) {
        $nid = $node_preview['nid'];
        $node_data = $api_client->getNodeById($nid);

        if (!$node_data) {
          continue;
        }

        $tnid = $node_data['tnid'] ?? $nid;
        $is_translation = !empty($tnid) && $tnid != $nid && $tnid != '0';

        if ($is_translation) {
          $translations[] = $node_data;
        }
        else {
          $originals[] = $node_data;
        }
      }

      // Спочатку оригінали, потім переклади.
      $context['sandbox']['all_nodes'] = array_merge($originals, $translations);
      $context['sandbox']['total'] = count($context['sandbox']['all_nodes']);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['errors'] = 0;
    }

    // Обробляємо по 10 за раз.
    $batch_size = 10;
    $nodes = array_slice(
      $context['sandbox']['all_nodes'],
      $context['sandbox']['progress'],
      $batch_size
    );

    foreach ($nodes as $node_data) {
      try {
        $result = self::importSingleNode($node_data);
        if ($result['success']) {
          $context['sandbox']['imported']++;
          $mapping_service->saveMapping('node', $node_data['nid'], $result['nid'], $node_type);

          // Логуємо в власну систему модуля.
          $log_service->logSuccess(
            $result['action'] ?? 'import',
            'node',
            $result['message'] ?? 'Імпорт успішний',
            (string) $result['nid'],
            [
              'old_nid' => $node_data['nid'],
              'title' => $node_data['title'],
              'language' => $node_data['language'] ?? 'uk',
            ]
          );
        }
        else {
          $context['sandbox']['errors']++;

          // Логуємо помилку.
          $log_service->logError(
            'import',
            'node',
            $result['error'] ?? 'Не вдалося імпортувати',
            (string) $node_data['nid'],
            ['title' => $node_data['title']]
          );
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error('Помилка: @msg', ['@msg' => $e->getMessage()]);

        // Логуємо помилку в власну систему.
        $log_service->logError(
          'import',
          'node',
          'Помилка імпорту: ' . $e->getMessage(),
          (string) $node_data['nid'],
          ['title' => $node_data['title']]
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
   * Імпорт однієї ноди - title + body.
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

    // Обробка поля body.
    $body_value = '';
    $body_summary = '';
    $body_format = 'plain_text';

    if (!empty($node_data['fields']['body'][0]['value'])) {
      $body_data = $node_data['fields']['body'][0];
      $body_value = $body_data['value'] ?? '';
      $body_summary = $body_data['summary'] ?? '';
      // Якщо format = null або порожній, використовуємо plain_text.
      $body_format = !empty($body_data['format']) ? $body_data['format'] : 'plain_text';
    }

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
          $translation->setTitle($title);
          if ($translation->hasField('body')) {
            $translation->set('body', [
              'value' => $body_value,
              'summary' => $body_summary,
              'format' => $body_format,
            ]);
          }
          $translation->save();
          // Встановлюємо changed після збереження.
          if (method_exists($translation, 'setChangedTime')) {
            $translation->setChangedTime($changed);
            $translation->save();
          }
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

      // Спочатку копіюємо всі поля з оригіналу (як в TranslationExample.php).
      foreach ($original->getFieldDefinitions() as $field_name => $field_definition) {
        // Пропускаємо системні поля та поля які ми перекладаємо.
        if (in_array($field_name, [
          'nid', 'uuid', 'vid', 'langcode', 'default_langcode',
          'content_translation_source', 'content_translation_outdated',
          'revision_translation_affected', 'revision_default',
          'title', 'body', 'changed'
        ])) {
          continue;
        }

        if ($original->hasField($field_name) && !$original->get($field_name)->isEmpty()) {
          $translation->set($field_name, $original->get($field_name)->getValue());
        }
      }

      // Потім встановлюємо наші перекладені поля.
      $translation->setTitle($title);
      if ($translation->hasField('body')) {
        $translation->set('body', [
          'value' => $body_value,
          'summary' => $body_summary,
          'format' => $body_format,
        ]);
      }
      $translation->setPublished($original->isPublished());
      $translation->set('default_langcode', 0);
      $translation->save();

      // Встановлюємо changed після збереження.
      if (method_exists($translation, 'setChangedTime')) {
        $translation->setChangedTime($changed);
        $translation->save();
      }

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
            $node->setTitle($title);
            if ($node->hasField('body')) {
              $node->set('body', [
                'value' => $body_value,
                'summary' => $body_summary,
                'format' => $body_format,
              ]);
            }
            $node->save();
            // Встановлюємо changed після збереження.
            if (method_exists($node, 'setChangedTime')) {
              $node->setChangedTime($changed);
              $node->save();
            }
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

      // Створюємо нову ноду - тільки тип і мова.
      $node = Node::create([
        'type' => $node_type,
        'langcode' => $language,
      ]);

      // Встановлюємо поля через методи.
      $node->setTitle($title);
      $node->setOwnerId(1);
      $node->setPublished(TRUE);
      $node->setCreatedTime($changed);

      // Додаємо body якщо є.
      if (!empty($body_value) && $node->hasField('body')) {
        $node->set('body', [
          'value' => $body_value,
          'summary' => $body_summary,
          'format' => $body_format,
        ]);
      }

      // Зберігаємо.
      $node->save();

      // Встановлюємо changed після збереження.
      if (method_exists($node, 'setChangedTime')) {
        $node->setChangedTime($changed);
        $node->save();
      }
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
