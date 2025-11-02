<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Форма для фільтрації логів.
 */
class LogsFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'migrate_from_drupal7_logs_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $request = \Drupal::request();

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Фільтри'),
      '#open' => TRUE,
    ];

    $form['filters']['operation_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип операції'),
      '#options' => [
        '' => $this->t('- Всі -'),
        'import' => $this->t('Імпорт'),
        'update' => $this->t('Оновлення'),
        'cron' => $this->t('Cron'),
        'delete' => $this->t('Видалення'),
      ],
      '#default_value' => $request->query->get('operation_type', ''),
    ];

    $form['filters']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип сутності'),
      '#options' => [
        '' => $this->t('- Всі -'),
        'vocabulary' => $this->t('Словник'),
        'term' => $this->t('Термін'),
        'node' => $this->t('Матеріал'),
      ],
      '#default_value' => $request->query->get('entity_type', ''),
    ];

    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Статус'),
      '#options' => [
        '' => $this->t('- Всі -'),
        'success' => $this->t('Успішно'),
        'error' => $this->t('Помилка'),
        'warning' => $this->t('Попередження'),
      ],
      '#default_value' => $request->query->get('status', ''),
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Застосувати фільтри'),
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Скинути'),
      '#url' => Url::fromRoute('migrate_from_drupal7.logs'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Перенаправляємо з фільтрами в query параметрах.
    $query = [];

    $operation_type = $form_state->getValue('operation_type');
    if (!empty($operation_type)) {
      $query['operation_type'] = $operation_type;
    }

    $entity_type = $form_state->getValue('entity_type');
    if (!empty($entity_type)) {
      $query['entity_type'] = $entity_type;
    }

    $status = $form_state->getValue('status');
    if (!empty($status)) {
      $query['status'] = $status;
    }

    $form_state->setRedirect('migrate_from_drupal7.logs', [], ['query' => $query]);
  }

}
