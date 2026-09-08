<?php

declare(strict_types=1);

namespace Drupal\oe_ai_provider_gpt_at_ec;

/**
 * Value object for a streamed Responses API function-call fragment.
 */
final class ResponsesToolCall {

  /**
   * Constructs a ResponsesToolCall.
   *
   * @param string $id
   *   The tool call id, or an empty string for an arguments-only fragment.
   * @param string $name
   *   The function name.
   * @param string $arguments
   *   The (partial) JSON arguments string.
   */
  public function __construct(
    protected string $id,
    protected string $name,
    protected string $arguments,
  ) {}

  /**
   * Renders the fragment in the Chat Completions tool_calls delta shape.
   *
   * @return array
   *   The rendered array.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'function' => [
        'name' => $this->name,
        'arguments' => $this->arguments,
      ],
    ];
  }

}
