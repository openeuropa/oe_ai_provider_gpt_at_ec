<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec;

use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Psr\Log\LoggerInterface;

/**
 * A streamed chat message iterator for GPT@EC AI.
 */
final class ChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * Constructs a ChatMessageIterator.
   *
   * @param \Traversable $iterator
   *   The streamed response.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger used to report failed streams. Optional, as the interface
   *   defines the constructor.
   */
  public function __construct(
    \Traversable $iterator,
    protected ?LoggerInterface $logger = NULL,
  ) {
    parent::__construct($iterator);
  }

  /**
   * {@inheritdoc}
   */
  public function doIterate(): \Generator {
    try {
      $error_reported = FALSE;
      foreach ($this->iterator->getIterator() as $data) {
        $chunk = $data->toArray();
        $event = $chunk['event'] ?? '';
        $payload = $chunk['data'] ?? [];

        switch ($event) {
          // Streamed assistant text.
          case 'response.output_text.delta':
          case 'response.refusal.delta':
            yield $this->createStreamedChatMessage('assistant', $payload['delta'] ?? '', [], NULL, $chunk);
            break;

          // A new output item: register the start of a function (tool) call.
          case 'response.output_item.added':
            $item = $payload['item'] ?? [];
            if (($item['type'] ?? '') === 'function_call') {
              $tool = new ResponsesToolCall($item['call_id'] ?? ($item['id'] ?? ''), $item['name'] ?? '', '');
              yield $this->createStreamedChatMessage('assistant', '', [], [$tool], $chunk);
            }
            break;

          // Streamed function (tool) call arguments. The empty id marks this as
          // an append to the current tool call.
          case 'response.function_call_arguments.delta':
            $tool = new ResponsesToolCall('', '', $payload['delta'] ?? '');
            yield $this->createStreamedChatMessage('assistant', '', [], [$tool], $chunk);
            break;

          // Terminal events: capture token usage and the finish reason.
          case 'response.completed':
          case 'response.incomplete':
          case 'response.failed':
            $response = $payload['response'] ?? [];
            $this->logUnsuccessfulResponse($event, $response);
            $usage = $response['usage'] ?? [];
            $message = $this->createStreamedChatMessage('assistant', '', [], NULL, $chunk);
            if (!empty($usage)) {
              $message->setInputTokenUsage($usage['input_tokens'] ?? 0);
              $message->setOutputTokenUsage($usage['output_tokens'] ?? 0);
              $message->setTotalTokenUsage($usage['total_tokens'] ?? 0);
              $message->setReasoningTokenUsage($usage['output_tokens_details']['reasoning_tokens'] ?? 0);
              $message->setCachedTokenUsage($usage['input_tokens_details']['cached_tokens'] ?? 0);
            }
            $this->setFinishReason($response['status'] ?? 'completed');
            yield $message;
            break;

          // An error raised by the server while streaming.
          case 'error':
            $this->logger?->error('The GPT@EC streamed response reported an error: @message (code: @code).', [
              '@message' => $payload['message'] ?? 'unknown error',
              '@code' => $payload['code'] ?? 'none',
            ]);
            $error_reported = TRUE;
            break;

          default:
            // Other lifecycle events (created, in_progress, content_part.*,
            // *.done, etc.) are not surfaced to the consumer.
            break;
        }
      }

      // GPT@EC sometimes closes the stream before the terminal event, which
      // would otherwise silently end with partial or empty text.
      if ($this->getFinishReason() === NULL && !$error_reported) {
        $this->logger?->error('The GPT@EC streamed response ended without a terminal event, the answer may be incomplete.');
      }
    }
    catch (\Exception $e) {
      // The request was accepted, so anything failing while the stream is read
      // (an error payload, a broken connection, an unknown event) is an error
      // in the response.
      $this->logger?->error('The GPT@EC streamed response failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new AiResponseErrorException($e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Logs a terminal response event that did not complete successfully.
   *
   * Without this, a failed or truncated stream silently ends with partial or
   * empty text.
   *
   * @param string $event
   *   The terminal event name.
   * @param array $response
   *   The response carried by the event.
   */
  protected function logUnsuccessfulResponse(string $event, array $response): void {
    if ($event === 'response.failed') {
      $this->logger?->error('The GPT@EC streamed response @id failed: @message (code: @code).', [
        '@id' => $response['id'] ?? '',
        '@message' => $response['error']['message'] ?? 'unknown error',
        '@code' => $response['error']['code'] ?? 'none',
      ]);
    }
    elseif ($event === 'response.incomplete') {
      $this->logger?->warning('The GPT@EC streamed response @id is incomplete: @reason.', [
        '@id' => $response['id'] ?? '',
        '@reason' => $response['incomplete_details']['reason'] ?? 'unknown reason',
      ]);
    }
  }

}
