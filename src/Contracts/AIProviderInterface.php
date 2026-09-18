<?php

namespace EasyAI\LaravelAI\Contracts;

interface AIProviderInterface
{
    public function chat(array $messages): AIResponseInterface;

    public function stream(array $messages, callable $callback): AIResponseInterface;

    public function health(): bool;

    public function models(): array;

    public function model(string $model): static;

    public function temperature(float $temp): static;

    public function maxTokens(int $tokens): static;

    public function systemPrompt(string $prompt): static;

    public function timeout(int $seconds): static;

    public function keepAlive(string|int $duration): static;

    public function format(string|array $format): static;

    public function options(array $options): static;

    /**
     * Toggle reasoning/"thinking" mode on models that support it (currently
     * Ollama only — e.g. qwen3). Silently ignored by drivers that don't
     * support it, same as format()/keepAlive() on non-Ollama drivers.
     */
    public function think(bool $think): static;

    public function embed(string|array $input): array;

    public function getProviderName(): string;

    /**
     * Sets the tools available for the *next* call — chat()/run() will
     * include them on the outgoing request in this provider's own wire
     * format. Cleared after each call, same lifecycle as every other
     * per-request override (model, temperature, etc.).
     *
     * @param \EasyAI\LaravelAI\Agent\Tool[] $tools
     */
    public function tools(array $tools): static;

    /**
     * The agent loop: sends $messages with the tools set via tools(),
     * and — as long as the model keeps asking to call one — executes the
     * matching Tool's handler and feeds the result back, up to $maxSteps
     * round-trips. Returns the first response with no further tool calls
     * (or the last response, with whatever it has, if $maxSteps is hit —
     * an agent that can't finish shouldn't loop forever, but it also
     * shouldn't throw away a partial answer).
     *
     * $onToolCall, when given, is called as $onToolCall($call, $result)
     * right after each individual tool call executes — purely an
     * observability hook (e.g. surfacing "a tool was just called" to a
     * caller), never changes what run() returns. Omit for the exact same
     * behavior as before this parameter existed.
     *
     * $onChunk, when given, streams every step (using the same wire
     * protocol as stream(), including its 2-arg $chunk/$type thinking
     * callback shape) instead of making a single non-streaming call per
     * step — a turn that ends up requesting a tool still gets detected
     * correctly (each driver's stream handler reassembles tool calls from
     * the provider's own incremental format), so the loop's tool-executing
     * behavior is unchanged; the only difference is that text a turn does
     * produce (a final answer, or a model "thinking out loud" before
     * calling a tool) reaches the caller token-by-token as it's generated
     * instead of only once the whole turn completes. Omit for the exact
     * same non-streaming behavior as before this parameter existed.
     *
     * $onStep, when given, is called as $onStep($response) once per
     * agent-loop iteration, immediately after that step's AIResponse is
     * received (streamed or not) and before it's checked for tool calls or
     * any tool executes — a step that ends up requesting a tool and the
     * final converged (or maxSteps-exhausted) step both trigger it, so a
     * caller can observe every real AIResponse the loop produces, not just
     * the one run() ultimately returns. This is the only way to see an
     * intermediate step's own usage (getPromptTokens()/getCompletionTokens()/
     * getEstimatedCost()) — run()'s return value only ever carries the last
     * step's. Purely an observability hook, same posture as $onToolCall:
     * never changes what run() returns or how the loop proceeds. Omit for
     * the exact same behavior as before this parameter existed.
     */
    public function run(array $messages, int $maxSteps = 5, ?callable $onToolCall = null, ?callable $onChunk = null, ?callable $onStep = null): AIResponseInterface;
}
