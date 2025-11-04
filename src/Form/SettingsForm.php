<?php

namespace Drupal\migrate_from_drupal7\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Форма налаштувань для міграції з Drupal 7.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'migrate_from_drupal7_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['migrate_from_drupal7.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('migrate_from_drupal7.settings');

    $form['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Базова URL адреса Drupal 7 сайту'),
      '#description' => $this->t('Наприклад: https://example.com'),
      '#default_value' => $config->get('base_url') ?? '',
      '#required' => TRUE,
    ];

    $form['endpoints'] = [
      '#type' => 'details',
      '#title' => $this->t('API Endpoints'),
      '#open' => TRUE,
    ];

    $endpoints = [
      'content_types' => [
        'title' => 'Отримати типи контентів і поля',
        'default' => '/json-api/content-type',
      ],
      'fields' => [
        'title' => 'Отримати всі поля',
        'default' => '/json-api/fields',
      ],
      'taxonomies' => [
        'title' => 'Отримати таксономії',
        'default' => '/json-api/taxonomies',
      ],
      'terms' => [
        'title' => 'Отримати термін таксономії',
        'default' => '/json-api/terms',
      ],
      'term_metatags' => [
        'title' => 'Отримати метатеги терміну таксономії',
        'default' => '/json-api/metatag/term',
      ],
      'nodes' => [
        'title' => 'Отримати список node',
        'default' => '/json-api/nodes',
      ],
      'node' => [
        'title' => 'Отримати node по nid',
        'default' => '/json-api/node',
      ],
      'node_metatags' => [
        'title' => 'Отримати метатеги node',
        'default' => '/json-api/metatag/node',
      ],
      'file' => [
        'title' => 'Отримати дані про файл',
        'default' => '/json-api/file',
      ],
      'user_fields' => [
        'title' => 'Отримати дані про поля користувачів',
        'default' => '/json-api/user-fields',
      ],
      'users' => [
        'title' => 'Отримати користувачів',
        'default' => '/json-api/users',
      ],
    ];

    foreach ($endpoints as $key => $endpoint) {
      $form['endpoints']['endpoint_' . $key] = [
        '#type' => 'textfield',
        '#title' => $this->t($endpoint['title']),
        '#default_value' => $config->get('endpoints.' . $key) ?? $endpoint['default'],
        '#required' => TRUE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('migrate_from_drupal7.settings');

    $config->set('base_url', $form_state->getValue('base_url'));

    $endpoints = [
      'content_types',
      'fields',
      'taxonomies',
      'terms',
      'term_metatags',
      'nodes',
      'node',
      'node_metatags',
      'file',
      'user_fields',
      'users',
    ];

    foreach ($endpoints as $key) {
      $config->set('endpoints.' . $key, $form_state->getValue('endpoint_' . $key));
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
