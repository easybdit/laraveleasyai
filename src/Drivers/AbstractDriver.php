<?php

namespace EasyAI\LaravelAI\Drivers;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelAI\Contracts\AIProviderInterface;
use EasyAI\LaravelAI\Contracts\AIResponseInterface;
use EasyAI\LaravelAI\Exceptions\ConnectionException;
use EasyAI\LaravelAI\Support\TokenEstimator;
use EasyAI\LaravelAI\Support\UsageLogger;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractDriver implements AIProviderInterface
{
    /**
     * Status codes worth retrying — transient/rate-limit conditions where a
     * second attempt has a real chance of succeeding. Deliberately excludes
     * every 4xx except 429: a 400/401/404/etc. means this exact request is
     * wrong or unauthorized, and retrying it wastes time without any
     * chance of a different outcome.
     */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    protected array  $config;
    protected string $currentModel;
    protected ?float $currentTemp   = null;
    protected ?int   $currentMaxTokens = null;
    protected ?string $currentSystemPrompt = null;
    protected ?int   $currentTimeout = null;
    protected ?\Closure $streamCallback = null;
    protected string|int|null $currentKeepAlive = null;
    protected string|array|null $currentFormat = null;
    protected array $currentOptions = [];
    protected ?bool $currentThink = null;
    protected ?int $currentRetries = null;
    protected ?int $currentRetrySleep = null;

    /** @var Tool[] */
    protected array $currentTools = [];

    public function __construct(array $config)
    {
        $this->config       = $config;
        $this->currentModel = $config['model'] ?? '';
    }

    public function model(string $model): static
    {
        $this->currentModel = $model;
        return $this;
    }

    public function temperature(float $temp): static
    {
        $this->currentTemp = $temp;
        return $this;
    }

    public function maxTokens(int $tokens): static
    {
        $this->currentMaxTokens = $tokens;
        return $this;
    }

    public function systemPrompt(string $prompt): static
    {
        $this->currentSystemPrompt = $prompt;
        return $this;
    }

    public function timeout(int $seconds): static
    {
        $this->currentTimeout = $seconds;
        return $this;
    }

    public function keepAlive(string|int $duration): static
    {
        $this->currentKeepAlive = $duration;
        return $this;
    }

    /**
     * Request structured output: 'json' for "must be valid JSON, no schema
     * enforced", or a JSON Schema array to constrain the actual shape.
     * Every provider translates this into its own native mechanism — OpenAI
     * (and DeepSeek/Groq/Together/Custom, which inherit its driver)
     * response_format, Gemini responseSchema, Anthropic a forced tool call,
     * Ollama's native format param — so the same call site works regardless
     * of provider. See extractStructuredData() and each driver's doChat().
     */
    public function format(string|array $format): static
    {
        $this->currentFormat = $format;
        return $this;
    }

    /**
     * Decodes $content as the structured-output payload when a schema/format
     * was requested for this call — null otherwise, or if $content didn't
     * decode to a JSON object/array (getContent() still has the raw text
     * either way, so a malformed response is never silently swallowed).
     */
    protected function extractStructuredData(string $content): ?array
    {
        if ($this->currentFormat === null || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function options(array $options): static
    {
        $this->currentOptions = $options;
        return $this;
    }

    public function think(bool $think): static
    {
        $this->currentThink = $think;
        return $this;
    }

    public function tools(array $tools): static
    {
        $this->currentTools = $tools;
        return $this;
    }

    /**
     * Per-call override of config('ai.retry.*') (off by default — see that
     * config's own docblock). $times is the total number of attempts, same
     * semantics as Laravel's own Http::retry() that this wraps — ->retries(2)
     * means "try, and if that fails, try once more" (2 attempts total), not
     * 2 retries on top of the first. $sleepMilliseconds is the delay between
     * attempts; null keeps the configured/default sleep. $times < 1 disables
     * retrying entirely for this call (a plain single attempt, same as the
     * config default of 0).
     */
    public function retries(int $times, ?int $sleepMilliseconds = null): static
    {
        $this->currentRetries = max(0, $times);
        if ($sleepMilliseconds !== null) {
            $this->currentRetrySleep = max(0, $sleepMilliseconds);
        }
        return $this;
    }

    protected function getRetryTimes(): int
    {
        return $this->currentRetries ?? (int) config('ai.retry.times', 0);
    }

    protected function getRetrySleep(): int
    {
        return $this->currentRetrySleep ?? (int) config('ai.retry.sleep', 1000);
    }

    /**
     * Applies config('ai.retry.*')/->retries() to a driver's non-streaming
     * PendingRequest before it's sent — one shared implementation so every
     * driver's retry behavior (which statuses qualify, connection failures
     * always qualifying) is identical rather than reimplemented per driver.
     * A no-op (returns $request unchanged) when retries are 0/disabled,
     * the common case. Never called from a driver's streaming path — see
     * config('ai.retry')'s own docblock for why.
     *
     * throw:false means a persistently-failing response is returned
     * normally after retries are exhausted rather than thrown — each
     * driver's own `if (!$response->successful())` check right after this
     * still catches it and raises this package's ProviderException exactly
     * as it always has. A persistent connection failure still throws
     * Illuminate's ConnectionException regardless of throw:false (Laravel's
     * own documented behavior), which every driver's existing catch
     * (\Throwable $e) block already re-wraps into this package's
     * ConnectionException — no new error handling needed for this.
     */
    protected function withRetry(PendingRequest $request): PendingRequest
    {
        $times = $this->getRetryTimes();
        if ($times < 1) {
            return $request;
        }

        return $request->retry($times, $this->getRetrySleep(), function (\Throwable $exception) {
            return $exception instanceof HttpConnectionException
                || ($exception instanceof RequestException
                    && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true));
        }, throw: false);
    }

    /**
     * Shared entry point for every driver's chat() — the actual wire call
     * still lives in each driver's doChat() (same shape as
     * appendToolExchange(): the provider-specific work stays in the
     * subclass, the cross-cutting behavior lives here once).
     *
     * Wraps doChat() with an opt-in response cache
     * (config('ai.cache.enabled'), off by default — when off, or when
     * this call is streaming or tool-calling, this is a pure passthrough
     * to doChat() with no extra behavior). See shouldCache() for the exact
     * conditions.
     */
    public function chat(array $messages): AIResponseInterface
    {
        if (!$this->shouldCache()) {
            $response = $this->doChat($messages);
            $this->logUsage($response);
            return $response;
        }

        $key   = $this->cacheKey($messages);
        $store = Cache::store(config('ai.cache.store'));

        $cached = $store->get($key);
        if ($cached instanceof AIResponseInterface) {
            return $cached; // served from cache — no new usage to log
        }

        $response = $this->doChat($messages);
        $store->put($key, $response, (int) config('ai.cache.ttl', 3600));
        $this->logUsage($response);

        return $response;
    }

    /**
     * config('ai.usage_logging.enabled')-gated (see UsageLogger) — a no-op
     * call when it's off, the default. Reads token counts off the response
     * that already came back from doChat(), so this never adds a request.
     */
    protected function logUsage(AIResponseInterface $response): void
    {
        UsageLogger::log($this->getProviderName(), $this->currentModel, 'chat', [
            'prompt_tokens'     => $response->getPromptTokens(),
            'completion_tokens' => $response->getCompletionTokens(),
        ]);
    }

    /**
     * Caching only ever applies to a plain, non-streaming, non-tool-calling
     * chat() call — a cached response for a streamed request defeats the
     * point of streaming, and a cached response for a tool-calling request
     * would skip re-running whatever side effects the tool call implies.
     * Read here (before doChat() runs and resetOverrides() clears them),
     * not inside doChat() itself.
     */
    protected function shouldCache(): bool
    {
        return (bool) config('ai.cache.enabled', false)
            && $this->streamCallback === null
            && empty($this->currentTools);
    }

    /**
     * Cache key covers everything that affects the actual request: provider,
     * model, message content, temperature, max tokens, and system prompt.
     * Deliberately excludes ai.cache.* config itself (ttl/store don't affect
     * the request) and per-call knobs that don't apply to the plain chat()
     * path this covers (format/keepAlive/think/options).
     */
    protected function cacheKey(array $messages): string
    {
        return 'laraveleasyai:cache:' . md5(json_encode([
            'provider'      => $this->getProviderName(),
            'model'         => $this->currentModel,
            'messages'      => $messages,
            'temperature'   => $this->getTemperature(),
            'max_tokens'    => $this->getMaxTokens(),
            'system_prompt' => $this->currentSystemPrompt,
        ]));
    }

    /**
     * The actual provider-specific wire call — what used to be each
     * driver's own public chat() before response caching (v2.7.0) needed a
     * single shared place to intercept every chat() call. Still exactly
     * one implementation per driver (or inherited, e.g. DeepSeek/Together/
     * Groq/Custom all reuse OpenAIDriver's), same as before.
     */
    abstract protected function doChat(array $messages): AIResponseInterface;

    public function stream(array $messages, callable $callback): AIResponseInterface
    {
        $this->streamCallback = $callback;
        $response = $this->chat($messages);
        $this->streamCallback = null;
        return $response;
    }

    /**
     * The agent loop — see AIProviderInterface::run() for the contract.
     * Provider-agnostic: repeatedly calls chat() (which each driver still
     * implements its own wire format for) and, when the model asks to
     * call a tool, executes it and hands the result back via
     * appendToolExchange() — the one method each driver DOES implement,
     * since how a tool call/result gets replayed into the next request is
     * genuinely different per provider (OpenAI's tool_calls + role:"tool"
     * messages, Anthropic's tool_use/tool_result content blocks, Gemini's
     * functionCall/functionResponse parts, Ollama's native tool_calls).
     *
     * $onToolCall (optional, added v2.7.0) is called as
     * $onToolCall($call, $result) right after each tool executes — lets a
     * caller observe the agent loop's intermediate steps (e.g. the chat
     * UI echoing an SSE "a tool was just called" status event) without
     * changing run()'s own return value or control flow. Never called for
     * an unknown-tool-name step's error result any differently than a
     * normal one — the caller gets the same ['error' => ...] shape execute()
     * would otherwise catch. Purely additive: omitted (null, the default),
     * this is a no-op and run() behaves exactly as before.
     *
     * $onChunk (optional): when given, every step streams instead of a
     * single non-streaming chat() call — see AIProviderInterface::run()'s
     * own docblock for why a turn that requests a tool is still detected
     * correctly even while streaming. Each driver's stream handler already
     * reassembles tool_calls from that provider's own incremental format
     * (delta.tool_calls for OpenAI-family, content_block_delta/
     * input_json_delta for Anthropic, functionCall parts for Gemini,
     * message.tool_calls for Ollama) — hasToolCalls() below works exactly
     * the same regardless of which path produced the response.
     *
     * $onStep (optional): called as $onStep($response) once per loop
     * iteration, right after that step's AIResponse is received and before
     * it's even checked for tool calls — so it fires for every intermediate
     * tool-call step and the final step alike, the only place a caller can
     * see each step's own usage rather than just the one response run()
     * returns. Purely additive, same posture as $onToolCall/$onChunk:
     * omitted (null, the default), this is a no-op and run() behaves
     * exactly as before.
     */
    public function run(array $messages, int $maxSteps = 5, ?callable $onToolCall = null, ?callable $onChunk = null, ?callable $onStep = null): AIResponseInterface
    {
        $tools    = $this->currentTools;
        $maxSteps = max(1, $maxSteps);
        $response = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $this->currentTools = $tools; // chat()/stream() reset this via resetOverrides() each call
            $response = $onChunk !== null ? $this->stream($messages, $onChunk) : $this->chat($messages);

            if ($onStep !== null) {
                $onStep($response);
            }

            if (!$response->hasToolCalls()) {
                return $response;
            }

            $results = [];
            foreach ($response->getToolCalls() as $call) {
                $tool   = $this->findTool($tools, $call->name);
                $result = $tool
                    ? $tool->execute($call->arguments)
                    : ['error' => "Unknown tool requested: {$call->name}"];

                $results[] = ['call' => $call, 'result' => $result];

                if ($onToolCall !== null) {
                    $onToolCall($call, $result);
                }
            }

            $messages = $this->appendToolExchange($messages, $response, $results);
        }

        // Ran out of steps without converging — return the last response
        // as-is rather than throwing it away. Its content may be empty (a
        // pure tool-call turn); that's a legitimate signal to the caller
        // that the agent didn't finish, not something to paper over here.
        return $response;
    }

    /** @param Tool[] $tools */
    private function findTool(array $tools, string $name): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Appends this turn's tool-call exchange (the assistant's tool call(s)
     * plus each tool's result) onto $messages in this provider's own wire
     * format, ready for the next chat() call in the loop. $results is a
     * list of ['call' => ToolCall, 'result' => mixed] in the same order as
     * $response->getToolCalls().
     */
    abstract protected function appendToolExchange(array $messages, AIResponseInterface $response, array $results): array;

    /**
     * Default embed — override in drivers that support it.
     */
    public function embed(string|array $input): array
    {
        throw new \BadMethodCallException(
            "Embeddings are not supported by the [{$this->getProviderName()}] provider."
        );
    }

    protected function getTimeout(): int
    {
        return $this->currentTimeout ?? ($this->config['timeout'] ?? 60);
    }

    protected function getTemperature(): float
    {
        return $this->currentTemp ?? ($this->config['options']['temperature'] ?? 0.7);
    }

    protected function getMaxTokens(): ?int
    {
        return $this->currentMaxTokens ?? ($this->config['options']['max_tokens'] ?? null);
    }

    protected function prependSystemPrompt(array $messages): array
    {
        if ($this->currentSystemPrompt) {
            array_unshift($messages, [
                'role'    => 'system',
                'content' => $this->currentSystemPrompt,
            ]);
        }
        return $messages;
    }

    protected function log(string $message, array $context = []): void
    {
        if (config('ai.logging.enabled', false)) {
            Log::channel(config('ai.logging.channel', 'stack'))
                ->info("[LaravelAI:{$this->getProviderName()}] {$message}", $context);
        }
    }

    protected function estimateTokens(string $text): int
    {
        return TokenEstimator::estimate($text);
    }

    /**
     * Reset per-request overrides after each call.
     */
    protected function resetOverrides(): void
    {
        $this->currentTemp         = null;
        $this->currentMaxTokens    = null;
        $this->currentSystemPrompt = null;
        $this->currentTimeout      = null;
        $this->streamCallback      = null;
        $this->currentKeepAlive    = null;
        $this->currentFormat       = null;
        $this->currentOptions      = [];
        $this->currentThink        = null;
        $this->currentTools        = [];
        $this->currentRetries      = null;
        $this->currentRetrySleep   = null;
    }
}
