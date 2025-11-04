<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_from_drupal7\Service\Drupal7ApiClient;
use Drupal\migrate_from_drupal7\Service\MappingService;
use Drupal\migrate_from_drupal7\Service\LogService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Drupal\file\Entity\File;

/**
 * Форма для імпорту користувачів.
 */
class ImportUsersForm extends FormBase {

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
    return 'migrate_from_drupal7_import_users';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#markup' => '<p>' . $this->t('Імпорт користувачів з Drupal 7. Паролі генеруються автоматично.') . '</p>',
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Кількість користувачів'),
      '#description' => $this->t('Скільки користувачів імпортувати за один раз'),
      '#default_value' => 100,
      '#min' => 1,
      '#max' => 1000,
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Імпорт'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $limit = $form_state->getValue('limit');

    $batch = [
      'title' => $this->t('Імпорт користувачів'),
      'operations' => [
        [
          [self::class, 'batchImport'],
          [$limit],
        ],
      ],
      'finished' => [self::class, 'batchFinished'],
    ];

    batch_set($batch);
  }

  /**
   * Batch імпорт користувачів.
   */
  public static function batchImport($limit, array &$context) {
    $api_client = \Drupal::service('migrate_from_drupal7.api_client');
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $log_service = \Drupal::service('migrate_from_drupal7.log');

    if (!isset($context['sandbox']['progress'])) {
      // Завантажуємо користувачів.
      $users = $api_client->getUsers($limit, 0);

      if (empty($users)) {
        $context['finished'] = 1;
        return;
      }

      $context['sandbox']['users'] = $users;
      $context['sandbox']['total'] = count($users);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['imported'] = 0;
      $context['sandbox']['errors'] = 0;
    }

    // Обробляємо по 1 користувачу за раз.
    $batch_size = 1;
    $users_slice = array_slice(
      $context['sandbox']['users'],
      $context['sandbox']['progress'],
      $batch_size
    );

    foreach ($users_slice as $user_data) {
      try {
        $result = self::importSingleUser($user_data);
        if ($result['success']) {
          $context['sandbox']['imported']++;
          $mapping_service->saveMapping('user', $user_data['uid'], $result['uid']);

          $log_service->logSuccess(
            $result['action'] ?? 'import',
            'user',
            $result['message'] ?? 'Імпорт успішний',
            (string) $result['uid'],
            [
              'old_uid' => $user_data['uid'],
              'name' => $user_data['name'],
              'mail' => $user_data['mail'],
            ]
          );
        }
        else {
          $context['sandbox']['errors']++;
          $log_service->logError(
            'import',
            'user',
            $result['error'] ?? 'Не вдалося імпортувати',
            (string) $user_data['uid'],
            ['name' => $user_data['name']]
          );
        }
      }
      catch (\Exception $e) {
        $context['sandbox']['errors']++;
        \Drupal::logger('migrate_from_drupal7')->error('Помилка імпорту користувача: @msg', ['@msg' => $e->getMessage()]);

        $log_service->logError(
          'import',
          'user',
          'Помилка імпорту: ' . $e->getMessage(),
          (string) $user_data['uid'],
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
   * Імпорт одного користувача.
   */
  protected static function importSingleUser(array $user_data): array {
    $mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
    $old_uid = $user_data['uid'];
    $username = $user_data['name'];
    $mail = $user_data['mail'];
    $created = (int) ($user_data['created'] ?? time());
    $changed = (int) ($user_data['changed'] ?? time());
    $status = (int) ($user_data['status'] ?? 1);
    $access = (int) ($user_data['access'] ?? 0);
    $login = (int) ($user_data['login'] ?? 0);

    // Перевіряємо чи користувач вже існує за маппінгом.
    $existing_uid = $mapping_service->getNewId('user', $old_uid);

    if ($existing_uid) {
      $user = User::load($existing_uid);
      if ($user) {
        // Користувач існує - перевіряємо чи потрібно оновити.
        $existing_changed = $user->getChangedTime();

        if ($changed > $existing_changed) {
          // Потрібно оновити.
          self::setUserFields($user, $user_data);
          $user->set('changed', $changed);
          $user->save();
          return [
            'success' => TRUE,
            'uid' => $existing_uid,
            'action' => 'update',
            'message' => "Оновлено користувача: $username (changed: " . date('Y-m-d H:i:s', $existing_changed) . ' → ' . date('Y-m-d H:i:s', $changed) . ')',
          ];
        }
        else {
          // Користувач актуальний - пропускаємо.
          return [
            'success' => TRUE,
            'uid' => $existing_uid,
            'action' => 'skip',
            'message' => 'Користувач актуальний, пропущено',
          ];
        }
      }
      else {
        // Маппінг є, але користувача немає - видаляємо маппінг.
        $mapping_service->deleteMapping('user', $old_uid);
      }
    }

    // Перевіряємо чи email вже використовується.
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['mail' => $mail]);

    if (!empty($existing_users)) {
      $existing_user = reset($existing_users);
      // Email вже існує - зберігаємо маппінг та перевіряємо чи потрібно оновити.
      $mapping_service->saveMapping('user', $old_uid, $existing_user->id());

      $existing_changed = $existing_user->getChangedTime();
      if ($changed > $existing_changed) {
        // Потрібно оновити.
        self::setUserFields($existing_user, $user_data);
        $existing_user->set('changed', $changed);
        $existing_user->save();
        return [
          'success' => TRUE,
          'uid' => $existing_user->id(),
          'action' => 'update',
          'message' => "Знайдено існуючого користувача з email $mail, оновлено",
        ];
      }
      else {
        // Користувач актуальний - пропускаємо.
        return [
          'success' => TRUE,
          'uid' => $existing_user->id(),
          'action' => 'skip',
          'message' => 'Користувач з таким email актуальний, пропущено',
        ];
      }
    }

    // Перевіряємо чи username вже використовується.
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);

    if (!empty($existing_users)) {
      // Username зайнятий - додаємо суфікс.
      $username = $username . '_' . $old_uid;
    }

    // Створюємо нового користувача.
    $password = self::generatePassword();

    $user = User::create([
      'name' => $username,
      'mail' => $mail,
      'pass' => $password,
      'status' => $status,
      'created' => $created,
      'changed' => $changed,
      'access' => $access,
      'login' => $login,
    ]);

    self::setUserFields($user, $user_data);
    $user->save();

    return [
      'success' => TRUE,
      'uid' => $user->id(),
      'action' => 'import',
      'message' => "Створено користувача: $username",
    ];
  }

  /**
   * Встановлення полів користувача.
   */
  protected static function setUserFields($user, array $user_data): void {
    // Обробляємо всі кастомні поля.
    foreach ($user_data as $field_name => $field_value) {
      // Пропускаємо стандартні поля.
      if (in_array($field_name, ['uid', 'name', 'mail', 'created', 'status', 'access', 'login', 'picture'])) {
        continue;
      }

      if (!$user->hasField($field_name)) {
        continue;
      }

      try {
        // Для дати потрібна спеціальна обробка.
        if ($field_name === 'field_user_birthday' && !empty($field_value)) {
          // Формат: "1983-05-26"
          $user->set($field_name, $field_value);
        }
        // Для масивів (множинні поля).
        elseif (is_array($field_value)) {
          $user->set($field_name, $field_value);
        }
        // Для простих значень.
        else {
          $user->set($field_name, $field_value);
        }
      }
      catch (\Exception $e) {
        // Не вдалося встановити поле.
      }
    }

    // Обробляємо аватар (picture).
    if (!empty($user_data['picture'])) {
      $avatar = self::downloadAvatar($user_data['picture'], $user_data['uid']);
      if ($avatar) {
        // Перевіряємо чи існує поле user_picture.
        if ($user->hasField('user_picture')) {
          try {
            $user->set('user_picture', ['target_id' => $avatar->id()]);
          }
          catch (\Exception $e) {
            // Не вдалося встановити аватар.
          }
        }
      }
    }
  }

  /**
   * Завантаження аватара користувача.
   */
  protected static function downloadAvatar($picture_url, $old_uid) {
    try {
      $filename = 'picture-' . $old_uid . '-' . time() . '.jpg';
      $directory = 'public://pictures';
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
      $response = $http_client->get($picture_url);
      $file_content = $response->getBody()->getContents();

      // Зберігаємо файл.
      $file = \Drupal::service('file.repository')->writeData(
        $file_content,
        $destination,
        \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
      );

      return $file;
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_from_drupal7')->error('Помилка завантаження аватара @url: @msg', [
        '@url' => $picture_url,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Генерація випадкового пароля.
   */
  protected static function generatePassword($length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $chars_length = strlen($chars);

    for ($i = 0; $i < $length; $i++) {
      $password .= $chars[random_int(0, $chars_length - 1)];
    }

    return $password;
  }

  /**
   * Batch завершено.
   */
  public static function batchFinished($success, array $results, array $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Імпорт користувачів завершено!'));
    }
    else {
      \Drupal::messenger()->addError(t('Помилка імпорту користувачів.'));
    }
  }

}
