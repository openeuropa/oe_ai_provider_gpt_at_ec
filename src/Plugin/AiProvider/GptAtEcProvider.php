<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Exception\AiAccessDeniedException;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiExceptionInterface;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiResponseErrorException;
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
use Drupal\oe_ai_provider_gpt_at_ec\ChatCompletionsMessageIterator;
use Drupal\oe_ai_provider_gpt_at_ec\ChatMessageIterator;
use OpenAI\Exceptions\ErrorException;
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

    // GPT@EC rejects the models it does not serve on the Responses endpoint,
    // so those still go through Chat Completions.
    if (!$this->supportsResponses($model_id)) {
      return $this->chatCompletions($input, $model_id);
    }

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
        $message = new ChatMessageIterator($response, $this->loggerFactory->get('oe_ai_provider_gpt_at_ec'));
      }
      // If we are in a fibre, we use a streamed response as the SDK doesn't
      // support direct async.
      elseif (\Fiber::getCurrent()) {
        $response = $this->client->responses()->createStreamed($payload);
        $stream = new ChatMessageIterator($response, $this->loggerFactory->get('oe_ai_provider_gpt_at_ec'));
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
      throw $this->convertClientException($e);
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
        $tools[] = $this->buildToolsFunctionOutput($item['call_id'] ?? ($item['id'] ?? ''), $item['name'] ?? '', $item['arguments'] ?? '', $input);
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
   * Determines whether GPT@EC serves a model on the Responses endpoint.
   *
   * Verified against the gateway on 2026-10-20: the GPT models are served,
   * while the Mistral ones are rejected as not supporting the Responses API.
   *
   * @param string $model_id
   *   The model ID.
   *
   * @return bool
   *   TRUE if the model can be used on the Responses endpoint.
   */
  protected function supportsResponses(string $model_id): bool {
    return str_starts_with(strtolower($model_id), 'gpt-');
  }

  /**
   * Runs a chat request on the Chat Completions endpoint.
   *
   * Used for the models that do not support the Responses API. File
   * attachments are not sent.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   * @param string $model_id
   *   The model ID.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output.
   */
  protected function chatCompletions(array|string|ChatInput $input, string $model_id): ChatOutput {
    $messages = $input;
    if ($input instanceof ChatInput) {
      $messages = $this->buildChatCompletionsMessages($input);
    }

    $payload = [
      'model' => $model_id,
      'messages' => $messages,
    ] + $this->prepareChatCompletionsConfiguration();

    // Pass any tools (function calling) definitions to the model.
    if ($input instanceof ChatInput && $input->getChatTools()) {
      $payload['tools'] = $input->getChatTools()->renderToolsArray();
      foreach ($payload['tools'] as $key => $tool) {
        $payload['tools'][$key]['function']['strict'] = FALSE;
      }
    }

    // Pass any structured JSON schema (structured output) to the model.
    if ($input instanceof ChatInput && $input->getChatStructuredJsonSchema()) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => $input->getChatStructuredJsonSchema(),
      ];
    }

    try {
      if ($this->streamed) {
        $response = $this->client->chat()->createStreamed($payload);
        $message = new ChatCompletionsMessageIterator($response, $this->loggerFactory->get('oe_ai_provider_gpt_at_ec'));
      }
      else {
        $response = $this->client->chat()->create($payload)->toArray();
        $message = $this->extractChatCompletionsMessage($response, $input);
      }
    }
    catch (\Exception $e) {
      throw $this->convertClientException($e);
    }

    $chat_output = new ChatOutput($message, $response, []);

    // For streamed responses the iterator sets the usage itself.
    if (!$this->streamed) {
      $usage = $response['usage'] ?? [];
      $chat_output->setTokenUsage(new TokenUsageDto(
        input: $usage['prompt_tokens'] ?? NULL,
        output: $usage['completion_tokens'] ?? NULL,
        total: $usage['total_tokens'] ?? NULL,
        reasoning: $usage['completion_tokens_details']['reasoning_tokens'] ?? NULL,
        cached: $usage['prompt_tokens_details']['cached_tokens'] ?? NULL,
      ));
    }

    return $chat_output;
  }

  /**
   * Builds the Chat Completions "messages" array from a ChatInput object.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   *
   * @return array
   *   The Chat Completions messages.
   */
  protected function buildChatCompletionsMessages(ChatInput $input): array {
    $messages = [];

    // The system prompt set on the input takes precedence over the deprecated
    // one set on the provider.
    $system_prompt = $input->getSystemPrompt() ?: $this->chatSystemRole;
    if ($system_prompt) {
      $messages[] = [
        'role' => 'system',
        'content' => $system_prompt,
      ];
    }

    /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
    foreach ($input->getMessages() as $message) {
      $item = [
        'role' => $message->getRole(),
        'content' => $message->getText(),
      ];

      // If it's a tool's response.
      if ($message->getToolsId()) {
        $item['tool_call_id'] = $message->getToolsId();
      }

      // If we want the results from some older tools call.
      if ($message->getTools()) {
        $item['tool_calls'] = $message->getRenderedTools();
      }

      $messages[] = $item;
    }

    return $messages;
  }

  /**
   * Prepares the provider configuration for the Chat Completions endpoint.
   *
   * @return array
   *   The Chat Completions compatible configuration.
   */
  protected function prepareChatCompletionsConfiguration(): array {
    $config = $this->configuration;

    // The output cap setting is named after the Responses API, Chat
    // Completions calls it "max_tokens".
    if (isset($config['max_output_tokens'])) {
      $config['max_tokens'] = $config['max_output_tokens'];
      unset($config['max_output_tokens']);
    }

    return $config;
  }

  /**
   * Builds a ChatMessage from a non-streamed Chat Completions result.
   *
   * @param array $response
   *   The decoded Chat Completions response.
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The original chat input (used to resolve tool definitions).
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage
   *   The chat message.
   */
  protected function extractChatCompletionsMessage(array $response, array|string|ChatInput $input): ChatMessage {
    $choice = $response['choices'][0]['message'] ?? [];

    $tools = [];
    foreach ($choice['tool_calls'] ?? [] as $tool_call) {
      $tools[] = $this->buildToolsFunctionOutput($tool_call['id'] ?? '', $tool_call['function']['name'] ?? '', $tool_call['function']['arguments'] ?? '', $input);
    }

    $message = new ChatMessage($choice['role'] ?? 'assistant', $choice['content'] ?? '');
    if (!empty($tools)) {
      $message->setTools($tools);
    }

    return $message;
  }

  /**
   * Builds the output of a tool call returned by the model.
   *
   * @param string $tool_id
   *   The tool call ID.
   * @param string $name
   *   The name of the called tool.
   * @param string $arguments
   *   The JSON encoded arguments.
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The original chat input (used to resolve tool definitions).
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput
   *   The tool output.
   */
  protected function buildToolsFunctionOutput(string $tool_id, string $name, string $arguments, array|string|ChatInput $input): ToolsFunctionOutput {
    $function = NULL;
    if ($input instanceof ChatInput && $input->getChatTools()) {
      $function = $input->getChatTools()->getFunctionByName($name);
    }

    // Invalid JSON arguments (seen with mistral-small) are dropped rather than
    // breaking the whole response.
    $decoded = Json::decode($arguments);
    $tool = new ToolsFunctionOutput($function, $tool_id, is_array($decoded) ? $decoded : []);
    // The model can call a tool that was not offered: the name is then only
    // known from the response.
    if ($function === NULL) {
      $tool->setName($name);
    }

    return $tool;
  }

  /**
   * Converts an exception thrown by the GPT@EC client to an AI exception.
   *
   * @param \Exception $exception
   *   The exception thrown while talking to the gateway.
   *
   * @return \Exception
   *   The AI module exception, wrapping the original one.
   */
  protected function convertClientException(\Exception $exception): \Exception {
    // Already converted, e.g. by the iterator while consuming a stream.
    if ($exception instanceof AiExceptionInterface) {
      return $exception;
    }

    $message = $exception->getMessage();
    if (!$exception instanceof ErrorException) {
      // Transport failures and unreadable responses.
      return new AiResponseErrorException($message, (int) $exception->getCode(), $exception);
    }

    // Keep the HTTP status as the exception code, for consumers that check it.
    $status = $exception->getStatusCode();
    return match (TRUE) {
      str_contains(strtolower($message), 'quota') => new AiQuotaException($message, $status, $exception),
      $status === 429 => new AiRateLimitException($message, $status, $exception),
      $status === 401, $status === 403 => new AiAccessDeniedException($message, $status, $exception),
      $status >= 400 && $status < 500 => new AiBadRequestException($message, $status, $exception),
      default => new AiResponseErrorException($message, $status, $exception),
    };
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
