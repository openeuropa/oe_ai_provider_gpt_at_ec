<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec;

use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;

/**
 * A streamed chat message iterator for GPT@EC AI.
 */
final class ChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * {@inheritdoc}
   */
  public function doIterate(): \Generator {
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

        default:
          // Other lifecycle events (created, in_progress, content_part.*,
          // *.done, etc.) are not surfaced to the consumer.
          break;
      }
    }
  }

}
