<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec;

use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use OpenAI\Responses\Chat\CreateResponseUsage;

/**
 * A streamed chat message iterator for GPT@EC AI.
 */
final class ChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * {@inheritdoc}
   */
  public function doIterate(): \Generator {
    foreach ($this->iterator->getIterator() as $data) {
      $usage = $data->usage ?? NULL;
      $metadata = $usage instanceof CreateResponseUsage ? $usage->toArray() : [];

      $message = $this->createStreamedChatMessage(
        $data->choices[0]->delta->role ?? '',
        $data->choices[0]->delta->content ?? '',
        $metadata,
        $data->choices[0]->delta->toolCalls ?? NULL,
        $data->toArray(),
      );

      if ($usage instanceof CreateResponseUsage) {
        $message->setInputTokenUsage($usage->promptTokens ?? 0);
        $message->setOutputTokenUsage($usage->completionTokens ?? 0);
        $message->setTotalTokenUsage($usage->totalTokens ?? 0);
        $message->setReasoningTokenUsage($usage->completionTokenDetails->reasoningTokens ?? 0);
        $message->setCachedTokenUsage($usage->completionTokenDetails->cachedTokens ?? 0);
      }

      if (isset($data->choices[0]->finishReason)) {
        $this->setFinishReason($data->choices[0]->finishReason);
      }

      yield $message;
    }
  }

}
