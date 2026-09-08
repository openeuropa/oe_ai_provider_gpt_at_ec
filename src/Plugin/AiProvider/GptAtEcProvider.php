<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInputInterface;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\Component\Serialization\Json;
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

    // Normalize the input into the Responses API "input" structure. A plain
    // string or an already built array is passed through as is.
    $responses_input = $input;
    if ($input instanceof ChatInput) {
      $responses_input = $this->buildResponsesInput($input);
    }

    $payload = [
      'model' => $model_id,
      'input' => $responses_input,
    ] + $this->prepareResponsesConfiguration();

    // Pass any tools (function calling) definitions to the model.
    if ($input instanceof ChatInput && $input->getChatTools()) {
      $payload['tools'] = $this->renderResponsesTools($input->getChatTools());
    }

    // Pass any structured JSON schema (structured output) to the model. The
    // Responses API expects it under "text.format" rather than the Chat
    // Completions "response_format".
    if ($input instanceof ChatInput && $input->getChatStructuredJsonSchema()) {
      // Unset keys (e.g. a missing description) come through as NULL and are
      // dropped rather than sent.
      $payload['text']['format'] = [
        'type' => 'json_schema',
      ] + array_filter($input->getChatStructuredJsonSchema(), fn($value) => $value !== NULL);
    }

    try {
      if ($this->streamed) {
        $response = $this->client->responses()->createStreamed($payload);
        $message = new ChatMessageIterator($response);
      }
      // If we are in a fibre, we use a streamed response as the SDK doesn't
      // support direct async.
      elseif (\Fiber::getCurrent()) {
        $response = $this->client->responses()->createStreamed($payload);
        $stream = new ChatMessageIterator($response);
        // We consume the stream in a fiber, suspending after each chunk until
        // the stream signals it has finished.
        foreach ($stream as $chunk) {
          if ($chunk !== NULL && empty($stream->getFinishReason())) {
            \Fiber::suspend();
          }
        }

        // Create the final message from accumulated data. The reconstructed
        // output also carries the token usage collected from the stream.
        $reconstructed = $stream->reconstructChatOutput();
        $message = $reconstructed->getNormalized();
      }
      else {
        $response = $this->client->responses()->create($payload)->toArray();
        $message = $this->extractResponsesChatMessage($response, $input);
      }
    }
    catch (\Exception $e) {
      // @todo We currently don't know which exceptions are thrown and what are
      //   their messages, so we just rethrow the normal exception.
      //   This try/catch block is therefor useless.
      throw $e;
    }

    $chat_output = new ChatOutput($message, $response, []);

    // For streamed responses the iterator sets the usage itself; in a fiber
    // the usage was accumulated on the reconstructed output.
    if (isset($reconstructed)) {
      $chat_output->setTokenUsage($reconstructed->getTokenUsage());
    }
    elseif (!$this->streamed) {
      $this->setResponsesTokenUsage($chat_output, $response);
    }

    return $chat_output;
  }

  /**
   * Builds the Responses API "input" array from a ChatInput object.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   *
   * @return array
   *   The Responses API input items.
   */
  protected function buildResponsesInput(ChatInput $input): array {
    $items = [];

    // The system prompt set on the input takes precedence over the deprecated
    // one set on the provider.
    $system_prompt = $input->getSystemPrompt() ?: $this->chatSystemRole;
    if ($system_prompt) {
      $items[] = [
        'role' => 'system',
        'content' => $system_prompt,
      ];
    }

    /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
    foreach ($input->getMessages() as $message) {
      // A tool result is its own input item in the Responses API.
      if ($message->getToolsId()) {
        $items[] = [
          'type' => 'function_call_output',
          'call_id' => $message->getToolsId(),
          'output' => $message->getText(),
        ];
        continue;
      }

      // An assistant turn that issued tool calls becomes one message item (when
      // it also has text) plus a separate function_call item per call.
      if ($message->getTools()) {
        if ($message->getText() !== '') {
          $items[] = [
            'role' => $message->getRole(),
            'content' => $message->getText(),
          ];
        }
        foreach ($message->getTools() as $tool) {
          $rendered = $tool->getOutputRenderArray();
          $items[] = [
            'type' => 'function_call',
            'call_id' => $tool->getToolId(),
            'name' => $tool->getName(),
            'arguments' => $rendered['function']['arguments'] ?? '{}',
          ];
        }
        continue;
      }

      $items[] = $this->buildResponsesMessageItem($message);
    }

    return $items;
  }

  /**
   * Builds a single Responses API message input item from a ChatMessage.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $message
   *   The chat message.
   *
   * @return array
   *   The Responses API message item.
   */
  protected function buildResponsesMessageItem(ChatMessage $message): array {
    // Only images and PDF documents can be attached, both inlined as data
    // URIs: GPT@EC has no file upload endpoint. Verified against the gateway
    // on 2026-09-10 with gpt-5.1.
    $parts = [];
    foreach ($message->getFiles() as $file) {
      if ($file instanceof ImageFile) {
        // The gateway validates the part strictly and requires "detail".
        $parts[] = [
          'type' => 'input_image',
          'image_url' => $file->getAsBase64EncodedString(),
          'detail' => 'auto',
        ];
      }
      elseif ($file->getMimeType() === 'application/pdf') {
        $parts[] = [
          'type' => 'input_file',
          'filename' => $file->getFilename(),
          'file_data' => $file->getAsBase64EncodedString(),
        ];
      }
    }

    // Plain text messages can use the simple string content form, which the
    // Responses API accepts for any role.
    if (empty($parts)) {
      return [
        'role' => $message->getRole(),
        'content' => $message->getText(),
      ];
    }

    // Multimodal messages need typed content parts.
    $content = [
      [
        'type' => 'input_text',
        'text' => $message->getText(),
      ],
      ...$parts,
    ];

    return [
      'role' => $message->getRole(),
      'content' => $content,
    ];
  }

  /**
   * Prepares the provider configuration for the Responses endpoint.
   *
   * Translates Chat Completions configuration keys to their Responses API
   * equivalents.
   *
   * @return array
   *   The Responses-compatible configuration.
   */
  protected function prepareResponsesConfiguration(): array {
    $config = $this->configuration;

    // The Responses API uses "max_output_tokens" for the output cap. Map the
    // Chat Completions key so existing stored configuration keeps working.
    if (isset($config['max_tokens'])) {
      if (!isset($config['max_output_tokens'])) {
        $config['max_output_tokens'] = $config['max_tokens'];
      }
      unset($config['max_tokens']);
    }

    return $config;
  }

  /**
   * Renders chat tools into the flat Responses API function-tool shape.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsInputInterface $tools
   *   The chat tools.
   *
   * @return array
   *   The Responses API tools array.
   */
  protected function renderResponsesTools(ToolsInputInterface $tools): array {
    $rendered = [];
    foreach ($tools->renderToolsArray() as $tool) {
      // Chat Completions nests the definition under "function"; the Responses
      // API expects the fields flattened onto the tool itself.
      if (($tool['type'] ?? '') === 'function' && isset($tool['function'])) {
        $parameters = $tool['function']['parameters'] ?? NULL;
        $rendered[] = [
          'type' => 'function',
          'name' => $tool['function']['name'],
          'description' => $tool['function']['description'] ?? '',
          'parameters' => empty($parameters) ? [
            'type' => 'object',
            'properties' => (object) [],
          ] : $this->sanitizeResponsesToolSchema($parameters),
          'strict' => FALSE,
        ];
      }
      else {
        $rendered[] = $tool;
      }
    }

    return $rendered;
  }

  /**
   * Strips non-standard keys the Responses API rejects from a JSON schema.
   *
   * The core tool renderer adds a redundant "name" key and a boolean "required"
   * key to each property. The Chat Completions endpoint tolerated these, but
   * the Responses endpoint validates schemas strictly and rejects them (the
   * boolean "required" collides with the JSON Schema array keyword). The
   * object-level "required" array is preserved.
   *
   * @param array $schema
   *   The JSON schema fragment.
   *
   * @return array
   *   The sanitized schema fragment.
   */
  protected function sanitizeResponsesToolSchema(array $schema): array {
    unset($schema['name']);
    if (isset($schema['required']) && is_bool($schema['required'])) {
      unset($schema['required']);
    }
    if (isset($schema['properties']) && is_array($schema['properties'])) {
      foreach ($schema['properties'] as $key => $property) {
        if (is_array($property)) {
          $schema['properties'][$key] = $this->sanitizeResponsesToolSchema($property);
        }
      }
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
      $schema['items'] = $this->sanitizeResponsesToolSchema($schema['items']);
    }

    return $schema;
  }

  /**
   * Builds a ChatMessage from a non-streamed Responses API result.
   *
   * @param array $response
   *   The decoded Responses API response.
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The original chat input (used to resolve tool definitions).
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage
   *   The chat message.
   */
  protected function extractResponsesChatMessage(array $response, array|string|ChatInput $input): ChatMessage {
    $text = '';
    $tools = [];
    foreach ($response['output'] ?? [] as $item) {
      $type = $item['type'] ?? '';
      if ($type === 'message' && ($item['role'] ?? '') === 'assistant') {
        foreach ($item['content'] ?? [] as $part) {
          if (($part['type'] ?? '') === 'output_text') {
            $text .= $part['text'] ?? '';
          }
        }
      }
      elseif ($type === 'function_call') {
        $arguments = Json::decode($item['arguments'] ?? '') ?: [];
        $function = NULL;
        if ($input instanceof ChatInput && $input->getChatTools()) {
          $function = $input->getChatTools()->getFunctionByName($item['name'] ?? '');
        }
        $tools[] = new ToolsFunctionOutput($function, $item['call_id'] ?? ($item['id'] ?? ''), $arguments);
      }
    }

    $message = new ChatMessage('assistant', $text);
    if (!empty($tools)) {
      $message->setTools($tools);
    }

    return $message;
  }

  /**
   * Sets the token usage on a chat output from a Responses API result.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chat_output
   *   The chat output.
   * @param array $response
   *   The decoded Responses API response.
   */
  protected function setResponsesTokenUsage(ChatOutput $chat_output, array $response): void {
    $usage = $response['usage'] ?? [];
    $chat_output->setTokenUsage(new TokenUsageDto(
      input: $usage['input_tokens'] ?? NULL,
      output: $usage['output_tokens'] ?? NULL,
      total: $usage['total_tokens'] ?? NULL,
      reasoning: $usage['output_tokens_details']['reasoning_tokens'] ?? NULL,
      cached: $usage['input_tokens_details']['cached_tokens'] ?? NULL,
    ));
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
