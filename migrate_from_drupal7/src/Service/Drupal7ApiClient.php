<?php

namespace Drupal\migrate_from_drupal7\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Сервіс для роботи з API Drupal 7.
 */
class Drupal7ApiClient {

  /**
   * HTTP клієнт.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Конфігурація модуля.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Конструктор.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP клієнт.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Фабрика конфігурації.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Фабрика логера.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->config = $config_factory->get('migrate_from_drupal7.settings');
    $this->logger = $logger_factory->get('migrate_from_drupal7');
  }

  /**
   * Отримати дані з API.
   *
   * @param string $endpoint_key
   *   Ключ endpoint з конфігурації.
   * @param array $params
   *   Додаткові параметри запиту.
   *
   * @return array|null
   *   Дані з API або NULL у разі помилки.
   */
  public function get($endpoint_key, array $params = []) {
    $base_url = $this->config->get('base_url');
    $endpoint = $this->config->get('endpoints.' . $endpoint_key);

    if (empty($base_url) || empty($endpoint)) {
      $this->logger->error('Базова URL або endpoint не налаштовані для @key', ['@key' => $endpoint_key]);
      return NULL;
    }

    $url = rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');

    // Додаємо параметри до URL, якщо вони є.
    if (!empty($params)) {
      $url .= '?' . http_build_query($params);
    }

    try {
      $response = $this->httpClient->get($url);
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Помилка декодування JSON з @url: @error', [
          '@url' => $url,
          '@error' => json_last_error_msg(),
        ]);
        return NULL;
      }

      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Помилка запиту до @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Отримати таксономії з Drupal 7.
   *
   * @return array|null
   *   Масив таксономій або NULL у разі помилки.
   */
  public function getTaxonomies() {
    return $this->get('taxonomies');
  }

  /**
   * Отримати терміни таксономії з Drupal 7.
   *
   * @param string $vocabulary
   *   Машинне ім'я словника.
   *
   * @return array|null
   *   Масив термінів або NULL у разі помилки.
   */
  public function getTerms($vocabulary = NULL) {
    $params = [];
    if ($vocabulary) {
      $params['vocabulary'] = $vocabulary;
    }
    return $this->get('terms', $params);
  }

  /**
   * Отримати типи контенту з Drupal 7.
   *
   * @return array|null
   *   Масив типів контенту або NULL у разі помилки.
   */
  public function getContentTypes() {
    return $this->get('content_types');
  }

}
