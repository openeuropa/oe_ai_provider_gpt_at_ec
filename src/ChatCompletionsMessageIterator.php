<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec;

use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use OpenAI\Responses\Chat\CreateResponseUsage;
use Psr\Log\LoggerInterface;

/**
 * A streamed chat message iterator for the GPT@EC Chat Completions endpoint.
 *
 * Used for the models that do not support the Responses API.
 */
final class ChatCompletionsMessageIterator extends StreamedChatMessageIterator {

  /**
   * Constructs a ChatCompletionsMessageIterator.
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
          $message->setInputTokenUsage($usage->promptTokens);
          $message->setOutputTokenUsage($usage->completionTokens ?? 0);
          $message->setTotalTokenUsage($usage->totalTokens);
          $message->setReasoningTokenUsage($usage->completionTokensDetails?->reasoningTokens ?? 0);
          $message->setCachedTokenUsage($usage->promptTokensDetails?->cachedTokens ?? 0);
        }

        if (isset($data->choices[0]->finishReason)) {
          $this->logUnsuccessfulFinish($data->choices[0]->finishReason);
          $this->setFinishReason($data->choices[0]->finishReason);
        }

        yield $message;
      }

      // A stream closed before the finish reason would otherwise silently end
      // with partial or empty text.
      if ($this->getFinishReason() === NULL) {
        $this->logger?->error('The GPT@EC streamed chat completion ended without a finish reason, the answer may be incomplete.');
      }
    }
    catch (\Exception $e) {
      // The request was accepted, so anything failing while the stream is read
      // (an error payload, a broken connection) is an error in the response.
      $this->logger?->error('The GPT@EC streamed chat completion failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new AiResponseErrorException($e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Logs a finish reason that cut the answer short.
   *
   * @param string $finish_reason
   *   The finish reason of the streamed choice.
   */
  protected function logUnsuccessfulFinish(string $finish_reason): void {
    if (in_array($finish_reason, ['length', 'content_filter'], TRUE)) {
      $this->logger?->warning('The GPT@EC streamed chat completion is incomplete: @reason.', [
        '@reason' => $finish_reason,
      ]);
    }
  }

}
