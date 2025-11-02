<?php

namespace Drupal\migrate_from_drupal7\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Сервіс для логування операцій імпорту.
 */
class LogService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructor.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * Додати запис в лог.
   *
   * @param string $operation_type
   *   Тип операції: import, update, cron, delete.
   * @param string $entity_type
   *   Тип сутності: vocabulary, term, node.
   * @param string $status
   *   Статус: success, error, warning.
   * @param string $message
   *   Повідомлення.
   * @param string|null $entity_id
   *   ID сутності (опціонально).
   * @param array|null $details
   *   Додаткові деталі (будуть закодовані в JSON).
   * @param int|null $user_id
   *   User ID (якщо NULL - використається поточний користувач).
   *
   * @return int
   *   ID створеного запису.
   */
  public function log(
    string $operation_type,
    string $entity_type,
    string $status,
    string $message,
    ?string $entity_id = NULL,
    ?array $details = NULL,
    ?int $user_id = NULL
  ): int {
    $time = \Drupal::time()->getRequestTime();

    return $this->database->insert('migrate_from_drupal7_log')
      ->fields([
        'operation_type' => $operation_type,
        'entity_type' => $entity_type,
        'entity_id' => $entity_id ?? '',
        'status' => $status,
        'message' => $message,
        'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : NULL,
        'created' => $time,
        'user_id' => $user_id ?? $this->currentUser->id(),
      ])
      ->execute();
  }

  /**
   * Логувати успішну операцію.
   */
  public function logSuccess(
    string $operation_type,
    string $entity_type,
    string $message,
    ?string $entity_id = NULL,
    ?array $details = NULL
  ): int {
    return $this->log($operation_type, $entity_type, 'success', $message, $entity_id, $details);
  }

  /**
   * Логувати помилку.
   */
  public function logError(
    string $operation_type,
    string $entity_type,
    string $message,
    ?string $entity_id = NULL,
    ?array $details = NULL
  ): int {
    return $this->log($operation_type, $entity_type, 'error', $message, $entity_id, $details);
  }

  /**
   * Логувати попередження.
   */
  public function logWarning(
    string $operation_type,
    string $entity_type,
    string $message,
    ?string $entity_id = NULL,
    ?array $details = NULL
  ): int {
    return $this->log($operation_type, $entity_type, 'warning', $message, $entity_id, $details);
  }

  /**
   * Отримати логи з фільтрацією.
   *
   * @param array $filters
   *   Фільтри: operation_type, entity_type, status.
   * @param int $limit
   *   Ліміт записів.
   * @param int $offset
   *   Offset для пагінації.
   *
   * @return array
   *   Масив логів.
   */
  public function getLogs(array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('migrate_from_drupal7_log', 'l')
      ->fields('l')
      ->orderBy('created', 'DESC')
      ->range($offset, $limit);

    // Додаємо фільтри.
    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Отримати кількість логів.
   *
   * @param array $filters
   *   Фільтри.
   *
   * @return int
   *   Кількість записів.
   */
  public function getLogsCount(array $filters = []): int {
    $query = $this->database->select('migrate_from_drupal7_log', 'l')
      ->fields('l', ['id']);

    // Додаємо фільтри.
    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Видалити старі логи.
   *
   * @param int $days
   *   Видалити логи старіші за вказану кількість днів.
   *
   * @return int
   *   Кількість видалених записів.
   */
  public function deleteOldLogs(int $days = 30): int {
    $time = \Drupal::time()->getRequestTime() - ($days * 86400);

    return $this->database->delete('migrate_from_drupal7_log')
      ->condition('created', $time, '<')
      ->execute();
  }

  /**
   * Отримати статистику логів.
   *
   * @return array
   *   Статистика: total, success, error, warning.
   */
  public function getStatistics(): array {
    $stats = [
      'total' => 0,
      'success' => 0,
      'error' => 0,
      'warning' => 0,
    ];

    $query = $this->database->select('migrate_from_drupal7_log', 'l')
      ->fields('l', ['status'])
      ->groupBy('status');
    $query->addExpression('COUNT(*)', 'count');

    $results = $query->execute()->fetchAllKeyed();

    foreach ($results as $status => $count) {
      $stats[$status] = (int) $count;
      $stats['total'] += (int) $count;
    }

    return $stats;
  }

}
