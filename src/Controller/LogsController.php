<?php

namespace Drupal\migrate_from_drupal7\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\migrate_from_drupal7\Service\LogService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller для перегляду логів імпорту.
 */
class LogsController extends ControllerBase {

  /**
   * Log service.
   *
   * @var \Drupal\migrate_from_drupal7\Service\LogService
   */
  protected $logService;

  /**
   * Конструктор.
   */
  public function __construct(LogService $log_service) {
    $this->logService = $log_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('migrate_from_drupal7.log')
    );
  }

  /**
   * Сторінка зі списком логів.
   */
  public function listLogs(Request $request) {
    // Отримуємо фільтри з query параметрів.
    $filters = [
      'operation_type' => $request->query->get('operation_type'),
      'entity_type' => $request->query->get('entity_type'),
      'status' => $request->query->get('status'),
    ];

    // Пагінація.
    $page = $request->query->get('page', 0);
    $limit = 50;
    $offset = $page * $limit;

    // Отримуємо логи.
    $logs = $this->logService->getLogs($filters, $limit, $offset);
    $total = $this->logService->getLogsCount($filters);

    // Статистика.
    $stats = $this->logService->getStatistics();

    // Форма фільтрів.
    $filter_form = \Drupal::formBuilder()->getForm('Drupal\migrate_from_drupal7\Form\LogsFilterForm');

    // Таблиця логів.
    $rows = [];
    foreach ($logs as $log) {
      $details = !empty($log['details']) ? json_decode($log['details'], TRUE) : [];

      $rows[] = [
        'data' => [
          'created' => \Drupal::service('date.formatter')->format($log['created'], 'short'),
          'operation_type' => $this->getOperationTypeLabel($log['operation_type']),
          'entity_type' => $this->getEntityTypeLabel($log['entity_type']),
          'status' => [
            'data' => [
              '#markup' => '<span class="badge badge-' . $log['status'] . '">' . $this->getStatusLabel($log['status']) . '</span>',
            ],
          ],
          'message' => $log['message'],
          'entity_id' => $log['entity_id'] ?: '-',
          'details' => !empty($details) ? '<pre>' . print_r($details, TRUE) . '</pre>' : '-',
        ],
        'class' => ['log-status-' . $log['status']],
      ];
    }

    $build = [];

    // Статистика.
    $build['statistics'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['log-statistics']],
    ];

    $build['statistics']['content'] = [
      '#markup' => '<div class="log-stats">' .
        '<span class="stat"><strong>' . $this->t('Всього:') . '</strong> ' . $stats['total'] . '</span> ' .
        '<span class="stat stat-success"><strong>' . $this->t('Успішно:') . '</strong> ' . $stats['success'] . '</span> ' .
        '<span class="stat stat-error"><strong>' . $this->t('Помилок:') . '</strong> ' . $stats['error'] . '</span> ' .
        '<span class="stat stat-warning"><strong>' . $this->t('Попереджень:') . '</strong> ' . $stats['warning'] . '</span>' .
        '</div>',
    ];

    // Фільтри.
    $build['filters'] = $filter_form;

    // Таблиця.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Час'),
        $this->t('Операція'),
        $this->t('Тип'),
        $this->t('Статус'),
        $this->t('Повідомлення'),
        $this->t('ID'),
        $this->t('Деталі'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Логи відсутні.'),
      '#attributes' => ['class' => ['migrate-logs-table']],
    ];

    // Пагінація.
    if ($total > $limit) {
      $build['pager'] = [
        '#type' => 'pager',
        '#quantity' => ceil($total / $limit),
      ];
    }

    // CSS для стилізації.
    $build['#attached']['library'][] = 'migrate_from_drupal7/logs';

    return $build;
  }

  /**
   * Отримати мітку для типу операції.
   */
  protected function getOperationTypeLabel($type): string {
    $labels = [
      'import' => $this->t('Імпорт'),
      'update' => $this->t('Оновлення'),
      'cron' => $this->t('Cron'),
      'delete' => $this->t('Видалення'),
    ];
    return $labels[$type] ?? $type;
  }

  /**
   * Отримати мітку для типу сутності.
   */
  protected function getEntityTypeLabel($type): string {
    $labels = [
      'vocabulary' => $this->t('Словник'),
      'term' => $this->t('Термін'),
      'node' => $this->t('Матеріал'),
    ];
    return $labels[$type] ?? $type;
  }

  /**
   * Отримати мітку для статусу.
   */
  protected function getStatusLabel($status): string {
    $labels = [
      'success' => $this->t('Успішно'),
      'error' => $this->t('Помилка'),
      'warning' => $this->t('Попередження'),
    ];
    return $labels[$status] ?? $status;
  }

}
