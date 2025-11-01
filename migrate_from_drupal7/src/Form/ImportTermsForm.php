<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_from_drupal7\Service\Drupal7ApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Форма для імпорту термінів таксономії з Drupal 7.
 */
class ImportTermsForm extends FormBase {

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
    return 'migrate_from_drupal7_import_terms';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Отримуємо всі існуючі словники в Drupal 11.
    $vocabularies = $this->entityTypeManager
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple();

    if (empty($vocabularies)) {
      $form['message'] = [
        '#markup' => $this->t('Не знайдено жодного словника таксономії. Спочатку імпортуйте словники у вкладці "Імпорт словників таксономії".'),
      ];
      return $form;
    }

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Виберіть словники таксономії для імпорту термінів. Терміни будуть імпортовані з збереженням ієрархії, мови та перекладів.') . '</p>',
    ];

    $form['vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Словники для імпорту'),
      '#options' => [],
      '#description' => $this->t('Виберіть словники, терміни яких потрібно імпортувати.'),
    ];

    foreach ($vocabularies as $vocabulary) {
      $form['vocabularies']['#options'][$vocabulary->id()] = $vocabulary->label() . ' (' . $vocabulary->id() . ')';
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Імпортувати терміни'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_vocabularies = array_filter($form_state->getValue('vocabularies'));

    if (empty($selected_vocabularies)) {
      $this->messenger()->addWarning($this->t('Не вибрано жодного словника для імпорту.'));
      return;
    }

    $operations = [];

    foreach ($selected_vocabularies as $vocabulary_id) {
      $operations[] = [
        [self::class, 'batchImportTerms'],
        [$vocabulary_id],
      ];
    }

    $batch = [
      'title' => $this->t('Імпорт термінів таксономії'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Batch операція для імпорту термінів словника.
   *
   * @param string $vocabulary_id
   *   Machine name словника.
   * @param array $context
   *   Контекст batch операції.
   */
  public static function batchImportTerms($vocabulary_id, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $entity_type_manager = \Drupal::entityTypeManager();

    try {
      // Завантажуємо словник.
      $vocabulary = $entity_type_manager
        ->getStorage('taxonomy_vocabulary')
        ->load($vocabulary_id);

      if (!$vocabulary) {
        throw new \Exception('Словник не знайдено: ' . $vocabulary_id);
      }

      // Отримуємо терміни з Drupal 7.
      $data = $api_client->getTermsByVocabulary($vocabulary_id);

      if (empty($data['terms'])) {
        $context['results']['skipped'][] = $vocabulary->label() . ' (немає термінів)';
        $context['message'] = t('Словник @name не має термінів', ['@name' => $vocabulary->label()]);
        return;
      }

      // Ініціалізуємо sandbox для зберігання мапінгу старих tid на нові.
      if (!isset($context['sandbox']['tid_map'])) {
        $context['sandbox']['tid_map'] = [];
        $context['sandbox']['terms'] = $data['terms'];
        $context['sandbox']['total'] = count($data['terms']);
        $context['sandbox']['current'] = 0;
        $context['sandbox']['vocabulary_name'] = $vocabulary->label();
      }

      // Обробляємо терміни партіями по 10 штук за раз.
      $terms_to_process = array_slice(
        $context['sandbox']['terms'],
        $context['sandbox']['current'],
        10
      );

      foreach ($terms_to_process as $term_data) {
        try {
          // Створюємо або оновлюємо термін.
          $term = self::createTerm($vocabulary_id, $term_data, $context['sandbox']['tid_map']);

          // Зберігаємо мапінг старого tid на новий.
          $context['sandbox']['tid_map'][$term_data['tid']] = $term->id();

          // Додаємо переклади.
          if (!empty($term_data['translations'])) {
            foreach ($term_data['translations'] as $langcode => $translation_data) {
              self::createTermTranslation($term, $langcode, $translation_data);
            }
          }

          if (!isset($context['results']['imported'][$vocabulary_id])) {
            $context['results']['imported'][$vocabulary_id] = 0;
          }
          $context['results']['imported'][$vocabulary_id]++;
        }
        catch (\Exception $e) {
          \Drupal::logger('migrate_from_drupal7')->error(
            'Помилка імпорту терміну @name: @message',
            ['@name' => $term_data['name'], '@message' => $e->getMessage()]
          );
          $context['results']['errors'][] = $term_data['name'] . ': ' . $e->getMessage();
        }

        $context['sandbox']['current']++;
      }

      // Після імпорту всіх термінів налаштовуємо ієрархію.
      if ($context['sandbox']['current'] >= $context['sandbox']['total']) {
        self::setupHierarchy($data['terms'], $context['sandbox']['tid_map'], $vocabulary_id);
        $context['finished'] = 1;
      }
      else {
        $context['finished'] = $context['sandbox']['current'] / $context['sandbox']['total'];
      }

      $context['message'] = t(
        'Імпортовано термінів: @current з @total (@vocabulary)',
        [
          '@current' => $context['sandbox']['current'],
          '@total' => $context['sandbox']['total'],
          '@vocabulary' => $context['sandbox']['vocabulary_name'],
        ]
      );
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error(
        'Помилка імпорту термінів словника @vocab: @message',
        ['@vocab' => $vocabulary_id, '@message' => $e->getMessage()]
      );
      $context['results']['errors'][] = 'Словник ' . $vocabulary_id . ': ' . $e->getMessage();
    }
  }

  /**
   * Створити термін таксономії.
   *
   * @param string $vocabulary_id
   *   ID словника.
   * @param array $term_data
   *   Дані терміну з Drupal 7.
   * @param array $tid_map
   *   Мапінг старих tid на нові.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   Створений термін.
   */
  protected static function createTerm($vocabulary_id, array $term_data, array $tid_map) {
    // Перевіряємо чи термін вже існує за назвою.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary_id,
        'name' => $term_data['name'],
        'langcode' => $term_data['language'] ?? 'uk',
      ]);

    if (!empty($existing_terms)) {
      $term = reset($existing_terms);
      return $term;
    }

    // Створюємо новий термін.
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_data['name'],
      'description' => [
        'value' => $term_data['description'] ?? '',
        'format' => 'basic_html',
      ],
      'weight' => $term_data['weight'] ?? 0,
      'langcode' => $term_data['language'] ?? 'uk',
    ]);

    // Імпортуємо поля терміну.
    if (!empty($term_data['fields']) && is_array($term_data['fields'])) {
      self::importTermFields($term, $term_data['fields']);
    }

    $term->save();

    // Створюємо URL alias якщо є в даних.
    if (!empty($term_data['url_alias'])) {
      self::createUrlAlias($term, $term_data['url_alias'], $term_data['language'] ?? 'uk');
    }
    elseif (!empty($term_data['path'])) {
      self::createUrlAlias($term, $term_data['path'], $term_data['language'] ?? 'uk');
    }

    \Drupal::logger('migrate_from_drupal7')->info(
      'Створено термін @name (@tid) для словника @vocab',
      [
        '@name' => $term_data['name'],
        '@tid' => $term->id(),
        '@vocab' => $vocabulary_id,
      ]
    );

    return $term;
  }

  /**
   * Імпортувати поля терміну.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   Термін для якого імпортуються поля.
   * @param array $fields_data
   *   Дані полів з Drupal 7.
   */
  protected static function importTermFields(Term $term, array $fields_data) {
    foreach ($fields_data as $field_name => $field_values) {
      // Перевіряємо чи поле існує.
      if (!$term->hasField($field_name)) {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Поле @field не існує для терміну @term. Пропускаємо.',
          ['@field' => $field_name, '@term' => $term->getName()]
        );
        continue;
      }

      // Пропускаємо пусті значення.
      if (empty($field_values)) {
        continue;
      }

      try {
        // Обробляємо різні типи полів.
        $field_definition = $term->getFieldDefinition($field_name);
        $field_type = $field_definition->getType();

        $processed_values = [];

        foreach ($field_values as $delta => $field_value) {
          if (is_array($field_value)) {
            // Текстові поля з форматом.
            if (isset($field_value['value'])) {
              $processed_values[] = [
                'value' => $field_value['value'],
                'format' => $field_value['format'] ?? 'basic_html',
              ];
            }
            // Entity reference поля.
            elseif (isset($field_value['tid']) || isset($field_value['target_id'])) {
              $processed_values[] = [
                'target_id' => $field_value['tid'] ?? $field_value['target_id'],
              ];
            }
            // Інші поля - просто копіюємо.
            else {
              $processed_values[] = $field_value;
            }
          }
          else {
            // Прості значення (string, number).
            $processed_values[] = ['value' => $field_value];
          }
        }

        if (!empty($processed_values)) {
          $term->set($field_name, $processed_values);

          \Drupal::logger('migrate_from_drupal7')->info(
            'Імпортовано поле @field для терміну @term',
            ['@field' => $field_name, '@term' => $term->getName()]
          );
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('migrate_from_drupal7')->error(
          'Помилка імпорту поля @field для терміну @term: @message',
          [
            '@field' => $field_name,
            '@term' => $term->getName(),
            '@message' => $e->getMessage(),
          ]
        );
      }
    }
  }

  /**
   * Створити переклад терміну.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   Термін для якого створюється переклад.
   * @param string $langcode
   *   Код мови перекладу.
   * @param array $translation_data
   *   Дані перекладу.
   */
  protected static function createTermTranslation(Term $term, $langcode, array $translation_data) {
    // Перевіряємо чи модуль content_translation увімкнено.
    if (!\Drupal::moduleHandler()->moduleExists('content_translation')) {
      \Drupal::logger('migrate_from_drupal7')->warning(
        'Модуль content_translation не увімкнено. Переклади термінів не будуть імпортовані.'
      );
      return;
    }

    // Перевіряємо чи переклад вже існує.
    if ($term->hasTranslation($langcode)) {
      $translation = $term->getTranslation($langcode);
      $translation->setName($translation_data['name']);
      $translation->setDescription($translation_data['description'] ?? '');
      $translation->save();

      \Drupal::logger('migrate_from_drupal7')->info(
        'Оновлено переклад терміну @name на мову @lang',
        ['@name' => $term->getName(), '@lang' => $langcode]
      );
      return;
    }

    // Створюємо новий переклад.
    $translation = $term->addTranslation($langcode, [
      'name' => $translation_data['name'],
      'description' => [
        'value' => $translation_data['description'] ?? '',
        'format' => 'basic_html',
      ],
    ]);

    // Імпортуємо поля перекладу якщо вони є.
    if (!empty($translation_data['fields']) && is_array($translation_data['fields'])) {
      self::importTermFields($translation, $translation_data['fields']);
    }

    $translation->save();

    // Створюємо URL alias для перекладу якщо є в даних.
    if (!empty($translation_data['url_alias'])) {
      self::createUrlAlias($term, $translation_data['url_alias'], $langcode);
    }
    elseif (!empty($translation_data['path'])) {
      self::createUrlAlias($term, $translation_data['path'], $langcode);
    }

    \Drupal::logger('migrate_from_drupal7')->info(
      'Створено переклад терміну @name на мову @lang',
      ['@name' => $term->getName(), '@lang' => $langcode]
    );
  }

  /**
   * Налаштувати ієрархію термінів після імпорту.
   *
   * @param array $terms
   *   Масив термінів з Drupal 7.
   * @param array $tid_map
   *   Мапінг старих tid на нові.
   * @param string $vocabulary_id
   *   ID словника.
   */
  protected static function setupHierarchy(array $terms, array $tid_map, $vocabulary_id) {
    $entity_type_manager = \Drupal::entityTypeManager();

    foreach ($terms as $term_data) {
      // Пропускаємо терміни без батьківського елемента.
      if (empty($term_data['parent']) || $term_data['parent'] === null) {
        continue;
      }

      // Знаходимо новий tid терміну.
      if (!isset($tid_map[$term_data['tid']])) {
        continue;
      }

      $new_tid = $tid_map[$term_data['tid']];

      // Знаходимо новий tid батьківського терміну.
      $parent_tid_old = is_array($term_data['parent']) ? $term_data['parent'][0] : $term_data['parent'];

      // Пропускаємо кореневі терміни (parent = 0).
      if ($parent_tid_old == 0 || $parent_tid_old === '0') {
        \Drupal::logger('migrate_from_drupal7')->info(
          'Термін @term є кореневим (parent = 0)',
          ['@term' => $term_data['name']]
        );
        continue;
      }

      if (!isset($tid_map[$parent_tid_old])) {
        \Drupal::logger('migrate_from_drupal7')->warning(
          'Не знайдено батьківський термін @parent для терміну @term',
          ['@parent' => $parent_tid_old, '@term' => $term_data['name']]
        );
        continue;
      }

      $new_parent_tid = $tid_map[$parent_tid_old];

      // Завантажуємо термін і встановлюємо батьківський.
      $term = $entity_type_manager->getStorage('taxonomy_term')->load($new_tid);
      if ($term) {
        $term->set('parent', $new_parent_tid);
        $term->save();

        \Drupal::logger('migrate_from_drupal7')->info(
          'Встановлено батьківський термін для @term (parent: @parent)',
          ['@term' => $term_data['name'], '@parent' => $new_parent_tid]
        );
      }
    }
  }

  /**
   * Створити URL alias для терміну.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   Термін таксономії.
   * @param string $alias
   *   URL alias.
   * @param string $langcode
   *   Код мови.
   */
  protected static function createUrlAlias(Term $term, $alias, $langcode = 'uk') {
    // Перевіряємо чи alias вже існує для цього терміну.
    $path = '/taxonomy/term/' . $term->id();

    $existing_aliases = \Drupal::entityTypeManager()
      ->getStorage('path_alias')
      ->loadByProperties([
        'path' => $path,
        'langcode' => $langcode,
      ]);

    if (!empty($existing_aliases)) {
      // Оновлюємо існуючий alias.
      $path_alias = reset($existing_aliases);
      $path_alias->setAlias($alias);
      $path_alias->save();

      \Drupal::logger('migrate_from_drupal7')->info(
        'Оновлено URL alias для терміну @name: @alias',
        ['@name' => $term->getName(), '@alias' => $alias]
      );
    }
    else {
      // Створюємо новий alias.
      $path_alias = PathAlias::create([
        'path' => $path,
        'alias' => $alias,
        'langcode' => $langcode,
      ]);
      $path_alias->save();

      \Drupal::logger('migrate_from_drupal7')->info(
        'Створено URL alias для терміну @name: @alias',
        ['@name' => $term->getName(), '@alias' => $alias]
      );
    }
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
      if (!empty($results['imported'])) {
        $total = array_sum($results['imported']);
        $messenger->addStatus(t('Імпортовано термінів: @count', ['@count' => $total]));

        foreach ($results['imported'] as $vocab => $count) {
          $messenger->addStatus(t('Словник @vocab: @count термінів', ['@vocab' => $vocab, '@count' => $count]));
        }
      }
      if (!empty($results['skipped'])) {
        foreach ($results['skipped'] as $message) {
          $messenger->addWarning($message);
        }
      }
      if (!empty($results['errors'])) {
        $messenger->addError(t('Помилки: @count', ['@count' => count($results['errors'])]));
        foreach ($results['errors'] as $error) {
          $messenger->addError($error);
        }
      }
    }
    else {
      $messenger->addError(t('Виникла помилка під час імпорту термінів.'));
    }
  }

}
