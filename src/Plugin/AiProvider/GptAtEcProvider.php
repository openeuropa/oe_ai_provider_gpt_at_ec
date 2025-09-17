<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_provider_gpt_at_ec\ChatMessageIterator;
use Openeuropa\GptAtEcPhpClient\Client;
use Openeuropa\GptAtEcPhpClient\Factory;
use Symfony\Component\Yaml\Yaml;

/**
 * Implementation of an AI provider that uses GPT@EC.
 */
#[AiProvider(
  id: 'gpt_at_ec',
  label: new TranslatableMarkup('GPT@EC')
)]
class GptAtEcProvider extends AiProviderClientBase implements ContainerFactoryPluginInterface, ChatInterface {

  use ChatTrait;

  public const string CONFIG_NAME = 'oe_ai_provider_gpt_at_ec.settings';

  /**
   * The GPT@EC PHP client.
   *
   * @var \Openeuropa\GptAtEcPhpClient\Client
   */
  protected Client $client;

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== 'chat' && $operation_type !== NULL) {
      // @todo Since only chat is supported, do we need to filter by capabilities?
      throw new \RuntimeException('Operation not supported.');
    }

    $this->loadClient();

    return $this->getAvailableModels();
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    $cid = 'oe_ai_provider_gpt_at_ec_api_definitions';
    if ($cache = $this->cacheBackend->get($cid)) {
      return $cache->data;
    }

    $data = Yaml::parseFile($this->moduleHandler->getModule('oe_ai_provider_gpt_at_ec')->getPath() . '/definitions/api_defaults.yml');
    $this->cacheBackend->set($cid, $data);

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }

    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    throw new \RuntimeException('This method is currently not supported as we don\'t store the API key as property in the class.');
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->loadClient();

    $chat_input = $input;
    if ($input instanceof ChatInput) {
      $chat_input = [];

      if ($this->chatSystemRole) {
        $chat_input[] = [
          'role' => 'system',
          'content' => $this->chatSystemRole,
        ];
      }

      /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
      foreach ($input->getMessages() as $message) {
        $chat_input[] = [
          'role' => $message->getRole(),
          'content' => $message->getText(),
        ];
      }
    }

    $payload = [
      'model' => $model_id,
      'messages' => $chat_input,
    ] + $this->configuration;

    try {
      if ($this->streamed) {
        $response = $this->client->chat()->createStreamed($payload);
        $message = new ChatMessageIterator($response);
      }
      else {
        $response = $this->client->chat()->create($payload)->toArray();
        $message = new ChatMessage(
          role: $response['choices'][0]['message']['role'],
          text: $response['choices'][0]['message']['content'] ?? '',
        );
      }
    }
    catch (\Exception $e) {
      // @todo We currently don't know which exceptions are thrown and what are
      //   their messages, so we just rethrow the normal exception.
      //   This try/catch block is therefor useless.
      throw $e;
    }

    return new ChatOutput($message, $response, []);
  }

  /**
   * Loads the API client.
   */
  protected function loadClient(): void {
    if (!empty($this->client)) {
      return;
    }

    $this->client = (new Factory())
      ->withApiKey($this->loadApiKey())
      ->withHttpClient($this->httpClient)
      ->make();
  }

  /**
   * Fetches the available models from the AI provider.
   *
   * @todo Since only chat is supported, do we need to filter by capabilities?
   *
   * @return array<string, string>
   *   The list of available models.
   */
  protected function getAvailableModels(): array {
    $models = [];
    $list = $this->client->models()->list()->toArray();
    foreach ($list['data'] as $model) {
      $models[$model['id']] = $model['id'];
    }

    asort($models);

    return $models;
  }

}
