<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_provider_gpt_at_ec\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider\GptAtEcProvider;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the model settings and the payload of the GPT@EC provider.
 */
#[CoversClass(GptAtEcProvider::class)]
#[Group('oe_ai_provider_gpt_at_ec')]
class GptAtEcProviderTest extends UnitTestCase {

  /**
   * The requests sent to the gateway.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  protected array $history = [];

  /**
   * Tests the settings offered per model.
   */
  public function testGetModelSettings(): void {
    $general = Yaml::parseFile(__DIR__ . '/../../../definitions/api_defaults.yml')['chat']['configuration'];
    $provider = $this->createProvider();

    // Mistral models don't reason: the settings are unchanged.
    $this->assertSame($general, $provider->getModelSettings('mistral-medium-2508', $general));

    // gpt-5.6-terra-api reasons by default and would reject the sampling
    // settings.
    $settings = $provider->getModelSettings('gpt-5.6-terra-api', $general);
    $this->assertArrayNotHasKey('temperature', $settings);
    $this->assertArrayNotHasKey('top_p', $settings);
    $this->assertSame($general['max_output_tokens'], $settings['max_output_tokens']);
    $this->assertSame(['none', 'low', 'medium', 'high', 'xhigh', 'max'], $settings['reasoning_effort']['constraints']['options']);
    $this->assertSame('medium', $settings['reasoning_effort']['default']);
    $this->assertFalse($settings['reasoning_effort']['required']);

    // gpt-5.1 doesn't reason by default, so the sampling settings stay.
    // Their descriptions say that the reasoning effort must be "none".
    $settings = $provider->getModelSettings('gpt-5.1', $general);
    $this->assertSame(['none', 'low', 'medium', 'high'], $settings['reasoning_effort']['constraints']['options']);
    $this->assertSame('none', $settings['reasoning_effort']['default']);
    foreach (['temperature', 'top_p'] as $key) {
      $this->assertSame($general[$key]['description'] . ' This setting applies only when the reasoning effort is "none".', $settings[$key]['description']);
    }

    // gpt-oss-120b accepts the sampling settings with any effort.
    $settings = $provider->getModelSettings('gpt-oss-120b', $general);
    $this->assertSame(['low', 'medium', 'high'], $settings['reasoning_effort']['constraints']['options']);
    $this->assertSame('medium', $settings['reasoning_effort']['default']);
    $this->assertSame($general['temperature'], $settings['temperature']);
    $this->assertSame($general['top_p'], $settings['top_p']);
  }

  /**
   * Tests the configuration sent to the Responses endpoint.
   *
   * @param string $model_id
   *   The model ID.
   * @param array $configuration
   *   The provider configuration.
   * @param array $expected
   *   The expected configuration in the request body.
   */
  #[DataProvider('providerChatPayload')]
  public function testChatPayload(string $model_id, array $configuration, array $expected): void {
    $provider = $this->createProvider();
    $provider->setConfiguration($configuration);

    $output = $provider->chat('Hello', $model_id);

    $this->assertSame('Hello!', $output->getNormalized()->getText());
    $this->assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('/ecgpt/v1/responses', $request->getUri()->getPath());
    $body = json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $expected = ['model' => $model_id, 'input' => 'Hello'] + $expected;
    ksort($expected);
    ksort($body);
    $this->assertSame($expected, $body);
  }

  /**
   * Data provider for ::testChatPayload().
   */
  public static function providerChatPayload(): \Generator {
    $sampling = ['temperature' => 0.2, 'top_p' => 0.9];

    yield 'gpt-5.6-terra-api drops the sampling settings' => [
      'gpt-5.6-terra-api',
      $sampling + ['max_output_tokens' => 100],
      ['max_output_tokens' => 100],
    ];
    yield 'gpt-5.6-terra-api drops the 0.0 that the forms send for empty fields' => [
      'gpt-5.6-terra-api',
      ['temperature' => 0.0, 'top_p' => 0.0],
      [],
    ];
    yield 'gpt-5.6-terra-api drops them with a reasoning effort' => [
      'gpt-5.6-terra-api',
      $sampling + ['reasoning_effort' => 'high'],
      ['reasoning' => ['effort' => 'high']],
    ];
    yield 'gpt-5.6-terra-api ignores an empty reasoning effort' => [
      'gpt-5.6-terra-api',
      $sampling + ['reasoning_effort' => ''],
      [],
    ];
    yield 'gpt-5.6-terra-api keeps them with the effort "none"' => [
      'gpt-5.6-terra-api',
      $sampling + ['reasoning_effort' => 'none'],
      $sampling + ['reasoning' => ['effort' => 'none']],
    ];
    yield 'gpt-5.6-terra-api keeps them with "reasoning.effort" set to "none"' => [
      'gpt-5.6-terra-api',
      $sampling + ['reasoning' => ['effort' => 'none']],
      $sampling + ['reasoning' => ['effort' => 'none']],
    ];
    yield '"reasoning.effort" takes precedence over the setting' => [
      'gpt-5.6-terra-api',
      $sampling + ['reasoning_effort' => 'none', 'reasoning' => ['effort' => 'low']],
      ['reasoning' => ['effort' => 'low']],
    ];
    yield 'gpt-5.1 keeps the sampling settings' => [
      'gpt-5.1',
      $sampling,
      $sampling,
    ];
    yield 'gpt-5.1 drops them with an effort other than "none"' => [
      'gpt-5.1',
      $sampling + ['reasoning_effort' => 'low'],
      ['reasoning' => ['effort' => 'low']],
    ];
    yield 'gpt-oss-120b keeps them with any effort' => [
      'gpt-oss-120b',
      $sampling + ['reasoning_effort' => 'high'],
      $sampling + ['reasoning' => ['effort' => 'high']],
    ];
  }

  /**
   * Tests the tool schema sent to both endpoints.
   *
   * @param string $model_id
   *   The model ID.
   * @param string $fixture
   *   The reply of the gateway, relative to the fixtures directory.
   * @param string $path
   *   The expected endpoint.
   */
  #[DataProvider('providerToolSchema')]
  public function testToolSchema(string $model_id, string $fixture, string $path): void {
    $input = new ChatInput([new ChatMessage('user', 'What is the weather in Brussels?')]);
    $input->setChatTools(new ToolsInput([
      new ToolsFunctionInput('get_weather', [
        'description' => 'Get the current weather for a city.',
        'properties' => [
          new ToolsPropertyInput('city', [
            'type' => 'string',
            'description' => 'The city name.',
            'required' => TRUE,
          ]),
        ],
      ]),
    ]));

    $this->createProvider($fixture)->chat($input, $model_id);

    $request = $this->history[0]['request'];
    $this->assertSame($path, $request->getUri()->getPath());
    $body = json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $tool = $body['tools'][0];
    // Chat Completions nests the definition under "function".
    $parameters = $tool['function']['parameters'] ?? $tool['parameters'];
    // The "name" and boolean "required" keys of the property are removed, the
    // "required" list of the object is kept.
    $this->assertSame([
      'type' => 'object',
      'properties' => [
        'city' => [
          'type' => 'string',
          'description' => 'The city name.',
        ],
      ],
      'required' => ['city'],
    ], $parameters);
  }

  /**
   * Data provider for ::testToolSchema().
   */
  public static function providerToolSchema(): \Generator {
    yield 'Responses' => ['gpt-5.1', 'responses/create.json', '/ecgpt/v1/responses'];
    yield 'Chat Completions' => ['mistral-medium-3.5-128b-api', 'chat/create.json', '/ecgpt/v1/chat/completions'];
  }

  /**
   * Tests a plain text input on the Chat Completions endpoint.
   */
  public function testChatCompletionsPlainText(): void {
    $output = $this->createProvider('chat/create.json')->chat('Hello', 'mistral-medium-3.5-128b-api');

    $this->assertSame('Hello!', $output->getNormalized()->getText());
    $request = $this->history[0]['request'];
    $this->assertSame('/ecgpt/v1/chat/completions', $request->getUri()->getPath());
    $body = json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame([['role' => 'user', 'content' => 'Hello']], $body['messages']);
  }

  /**
   * Tests a reply that the client can't read.
   *
   * @param string $model_id
   *   The model ID.
   * @param bool $streamed
   *   Whether the reply is streamed.
   * @param string $body
   *   The body of the reply.
   */
  #[DataProvider('providerUnreadableReply')]
  public function testUnreadableReply(string $model_id, bool $streamed, string $body): void {
    $provider = $this->createProvider(new Response(200, ['Content-Type' => $streamed ? 'text/event-stream' : 'application/json'], $body));
    $provider->streamedOutput($streamed);

    $this->expectException(AiResponseErrorException::class);
    $output = $provider->chat('Hello', $model_id);
    // A stream is read only when it is iterated.
    foreach ($output->getNormalized() as $chunk) {
    }
  }

  /**
   * Data provider for ::testUnreadableReply().
   */
  public static function providerUnreadableReply(): \Generator {
    // Each reply has a field of the wrong type, which makes the client throw a
    // \TypeError.
    $fixtures = __DIR__ . '/../../fixtures/';
    $reply = json_decode(file_get_contents($fixtures . 'responses/create.json'), TRUE);
    $reply['created_at'] = 'yesterday';
    yield 'Responses' => ['gpt-5.1', FALSE, json_encode($reply)];

    $event = [
      'type' => 'response.output_text.delta',
      'content_index' => 'first',
      'delta' => 'Hello!',
      'item_id' => 'msg_1',
      'output_index' => 0,
      'sequence_number' => 1,
    ];
    yield 'Responses stream' => [
      'gpt-5.1',
      TRUE,
      "event: response.output_text.delta\ndata: " . json_encode($event) . "\n\n",
    ];

    $reply = json_decode(file_get_contents($fixtures . 'chat/create.json'), TRUE);
    $reply['created'] = 'yesterday';
    yield 'Chat Completions' => ['mistral-medium-3.5-128b-api', FALSE, json_encode($reply)];

    $chunk = [
      'id' => 'chatcmpl-1',
      'object' => 'chat.completion.chunk',
      'created' => 'yesterday',
      'model' => 'mistral-medium-3.5-128b-api',
      'choices' => [],
    ];
    yield 'Chat Completions stream' => [
      'mistral-medium-3.5-128b-api',
      TRUE,
      'data: ' . json_encode($chunk) . "\n\ndata: [DONE]\n\n",
    ];
  }

  /**
   * Creates the provider, with a gateway that sends one reply.
   *
   * @param string|\GuzzleHttp\Psr7\Response $reply
   *   The reply of the gateway: a file relative to the fixtures directory, or
   *   the response itself.
   *
   * @return \Drupal\oe_ai_provider_gpt_at_ec\Plugin\AiProvider\GptAtEcProvider
   *   The provider.
   */
  protected function createProvider(string|Response $reply = 'responses/create.json'): GptAtEcProvider {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([['api_key', 'gpt_at_ec']]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('test-key');
    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    $key_repository->method('getKey')->willReturn($key);

    $this->history = [];
    if (is_string($reply)) {
      $reply = new Response(200, ['Content-Type' => 'application/json'], file_get_contents(__DIR__ . '/../../fixtures/' . $reply));
    }
    $handler = HandlerStack::create(new MockHandler([$reply]));
    $handler->push(Middleware::history($this->history));

    return new GptAtEcProvider(
      'gpt_at_ec',
      ['label' => 'GPT@EC'],
      new Client(['handler' => $handler]),
      $config_factory,
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(CacheBackendInterface::class),
      $key_repository,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(FileSystemInterface::class),
    );
  }

}
