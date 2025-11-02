<?php

namespace Drupal\migrate_from_drupal7\Service;

use Drupal\Core\Database\Connection;

/**
 * Сервіс для роботи з маппінгом old ID -> new ID.
 */
class MappingService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructor.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Зберегти маппінг.
   *
   * @param string $entity_type
   *   Тип сутності: vocabulary, term, node.
   * @param string $old_id
   *   Старий ID з Drupal 7.
   * @param int $new_id
   *   Новий ID в Drupal 11.
   * @param string|null $vocabulary_id
   *   ID словника (для термінів).
   *
   * @return int
   *   ID створеного/оновленого запису.
   */
  public function saveMapping(string $entity_type, string $old_id, int $new_id, ?string $vocabulary_id = NULL): int {
    $time = \Drupal::time()->getRequestTime();

    // Перевіряємо чи існує маппінг.
    $existing = $this->getMapping($entity_type, $old_id, $vocabulary_id);

    if ($existing) {
      // Оновлюємо.
      $this->database->update('migrate_from_drupal7_mapping')
        ->fields([
          'new_id' => $new_id,
          'updated' => $time,
        ])
        ->condition('id', $existing['id'])
        ->execute();

      return $existing['id'];
    }
    else {
      // Створюємо новий.
      return $this->database->insert('migrate_from_drupal7_mapping')
        ->fields([
          'entity_type' => $entity_type,
          'old_id' => $old_id,
          'new_id' => $new_id,
          'vocabulary_id' => $vocabulary_id ?? '',
          'created' => $time,
          'updated' => $time,
        ])
        ->execute();
    }
  }

  /**
   * Отримати маппінг.
   *
   * @param string $entity_type
   *   Тип сутності.
   * @param string $old_id
   *   Старий ID.
   * @param string|null $vocabulary_id
   *   ID словника (для термінів).
   *
   * @return array|null
   *   Маппінг або NULL.
   */
  public function getMapping(string $entity_type, string $old_id, ?string $vocabulary_id = NULL): ?array {
    $query = $this->database->select('migrate_from_drupal7_mapping', 'm')
      ->fields('m')
      ->condition('entity_type', $entity_type)
      ->condition('old_id', $old_id);

    if ($vocabulary_id !== NULL) {
      $query->condition('vocabulary_id', $vocabulary_id);
    }

    $result = $query->execute()->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Отримати новий ID за старим.
   *
   * @param string $entity_type
   *   Тип сутності.
   * @param string $old_id
   *   Старий ID.
   * @param string|null $vocabulary_id
   *   ID словника.
   *
   * @return int|null
   *   Новий ID або NULL.
   */
  public function getNewId(string $entity_type, string $old_id, ?string $vocabulary_id = NULL): ?int {
    $mapping = $this->getMapping($entity_type, $old_id, $vocabulary_id);
    return $mapping ? (int) $mapping['new_id'] : NULL;
  }

  /**
   * Отримати старий ID за новим.
   *
   * @param string $entity_type
   *   Тип сутності.
   * @param int $new_id
   *   Новий ID.
   * @param string|null $vocabulary_id
   *   ID словника.
   *
   * @return string|null
   *   Старий ID або NULL.
   */
  public function getOldId(string $entity_type, int $new_id, ?string $vocabulary_id = NULL): ?string {
    $query = $this->database->select('migrate_from_drupal7_mapping', 'm')
      ->fields('m', ['old_id'])
      ->condition('entity_type', $entity_type)
      ->condition('new_id', $new_id);

    if ($vocabulary_id !== NULL) {
      $query->condition('vocabulary_id', $vocabulary_id);
    }

    $result = $query->execute()->fetchField();

    return $result ?: NULL;
  }

  /**
   * Отримати всі маппінги для типу сутності.
   *
   * @param string $entity_type
   *   Тип сутності.
   * @param string|null $vocabulary_id
   *   ID словника (опціонально).
   *
   * @return array
   *   Масив маппінгів [old_id => new_id].
   */
  public function getAllMappings(string $entity_type, ?string $vocabulary_id = NULL): array {
    $query = $this->database->select('migrate_from_drupal7_mapping', 'm')
      ->fields('m', ['old_id', 'new_id'])
      ->condition('entity_type', $entity_type);

    if ($vocabulary_id !== NULL) {
      $query->condition('vocabulary_id', $vocabulary_id);
    }

    $results = $query->execute()->fetchAllKeyed();

    return $results ?: [];
  }

  /**
   * Видалити маппінг.
   *
   * @param string $entity_type
   *   Тип сутності.
   * @param string $old_id
   *   Старий ID.
   * @param string|null $vocabulary_id
   *   ID словника.
   */
  public function deleteMapping(string $entity_type, string $old_id, ?string $vocabulary_id = NULL): void {
    $query = $this->database->delete('migrate_from_drupal7_mapping')
      ->condition('entity_type', $entity_type)
      ->condition('old_id', $old_id);

    if ($vocabulary_id !== NULL) {
      $query->condition('vocabulary_id', $vocabulary_id);
    }

    $query->execute();
  }

  /**
   * Видалити всі маппінги для типу сутності.
   *
   * @param string $entity_type
   *   Тип сутності.
   * @param string|null $vocabulary_id
   *   ID словника (опціонально).
   */
  public function deleteAllMappings(string $entity_type, ?string $vocabulary_id = NULL): void {
    $query = $this->database->delete('migrate_from_drupal7_mapping')
      ->condition('entity_type', $entity_type);

    if ($vocabulary_id !== NULL) {
      $query->condition('vocabulary_id', $vocabulary_id);
    }

    $query->execute();
  }

}
