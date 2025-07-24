<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec;

use Drupal\ai\OperationType\Chat\StreamedChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use OpenAI\Responses\Chat\CreateResponseUsage;

/**
 * A streamed chat message iterator for GPT@EC AI.
 */
final class ChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Generator {
    foreach ($this->iterator->getIterator() as $data) {
      // @todo This looks meh, rework.
      $usage = $data->usage ?? [];
      if ($usage instanceof CreateResponseUsage) {
        $usage = $usage->toArray();
      }
      elseif (!is_array($usage)) {
        $usage = [];
      }

      yield new StreamedChatMessage(
        $data->choices[0]->delta->role ?? '',
        $data->choices[0]->delta->content ?? '',
        $usage,
      );
    }
  }

}
