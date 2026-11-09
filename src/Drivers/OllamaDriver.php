<?php

namespace EasyAI\LaravelAI\Drivers;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelAI\Agent\ToolCall;
use EasyAI\LaravelAI\Contracts\AIResponseInterface;
use EasyAI\LaravelAI\Exceptions\ConnectionException;
use EasyAI\LaravelAI\Exceptions\ProviderException;
use EasyAI\LaravelAI\Response\AIResponse;
use Illuminate\Support\Facades\Http;

class OllamaDriver extends AbstractDriver
{
    public function getProviderName(): string
    {
        return 'ollama';
    }

    protected function doChat(array $messages): AIResponseInterface
    {
        $messages = $this->prependSystemPrompt($messages);
        $url      = rtrim($this->config['url'], '/') . '/api/chat';
        $isStream = $this->streamCallback !== null;

        $body = [
            'model'    => $this->currentModel,
            'messages' => $messages,
            'stream'   => $isStream,
            'options'  => $this->buildOptions(),
        ];

        // JSON mode or structured output schema
        if ($this->currentFormat !== null) {
            $body['format'] = $this->currentFormat;
        }

        // keep_alive — control model memory lifetime
        if ($this->currentKeepAlive !== null) {
            $body['keep_alive'] = $this->currentKeepAlive;
        }

        // Reasoning/"thinking" mode (qwen3 and similar) — an explicit
        // ->think() call wins, otherwise fall back to the provider's
        // configured default (config('ai.providers.ollama.think')); leaving
        // both unset lets Ollama use the model's own default.
        $think = $this->currentThink ?? ($this->config['think'] ?? null);
        if ($think !== null) {
            $body['think'] = $think;
        }

        if ($this->currentTools) {
            $body['tools'] = array_map(fn (Tool $t) => [
                'type'     => 'function',
                'function' => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->parameters],
            ], $this->currentTools);
        }

        $this->log('Request', ['model' => $this->currentModel, 'messages_count' => count($messages)]);

        try {
            if ($isStream) {
                return $this->handleStream($url, $body);
            }

            $response = $this->withRetry(Http::timeout($this->getTimeout()))
                ->post($url, $body);

            if (!$response->successful()) {
                throw new ProviderException(
                    "Ollama error: {$response->status()} - {$response->body()}",
                    'ollama',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json();
            $message = $data['message'] ?? [];

            $toolCalls = [];
            foreach ($message['tool_calls'] ?? [] as $tc) {
                // Ollama doesn't assign call ids, and unlike OpenAI its
                // "arguments" is already a parsed object/array, not a JSON string.
                $toolCalls[] = new ToolCall(
                    id:        null,
                    name:      $tc['function']['name'] ?? '',
                    arguments: $tc['function']['arguments'] ?? [],
                );
            }

            // json()-decoded `{}` becomes `[]`; Ollama rejects an empty array
            // for "arguments" (HTTP 400) when this message is echoed back.
            foreach ($message['tool_calls'] ?? [] as $i => $tc) {
                if (empty($tc['function']['arguments'])) {
                    $message['tool_calls'][$i]['function']['arguments'] = new \stdClass;
                }
            }

            $result = new AIResponse(
                content:             $message['content'] ?? '',
                promptTokens:        $data['prompt_eval_count'] ?? $this->estimateTokens(json_encode($messages)),
                completionTokens:    $data['eval_count'] ?? $this->estimateTokens($message['content'] ?? ''),
                model:               $data['model'] ?? $this->currentModel,
                provider:            'ollama',
                raw:                 $data,
                toolCalls:           $toolCalls,
                rawAssistantMessage: $message,
                structured:          $this->extractStructuredData($message['content'] ?? ''),
            );

            $this->log('Response', ['tokens' => $result->getTotalTokens()]);
            $this->resetOverrides();

            return $result;
        } catch (ProviderException $e) {
            $this->resetOverrides();
            throw $e;
        } catch (\Throwable $e) {
            $this->resetOverrides();
            throw new ConnectionException(
                "Ollama connection failed: {$e->getMessage()}",
                'ollama',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * @param array{call: ToolCall, result: mixed}[] $results
     */
    protected function appendToolExchange(array $messages, AIResponseInterface $response, array $results): array
    {
        $messages[] = $response->getRawAssistantMessage() ?? ['role' => 'assistant', 'content' => $response->getContent()];

        foreach ($results as $r) {
            $messages[] = [
                'role'    => 'tool',
                'content' => is_string($r['result']) ? $r['result'] : json_encode($r['result']),
            ];
        }

        return $messages;
    }

    /**
     * Build Ollama options array — merges temperature, num_predict, and custom options.
     */
    protected function buildOptions(): array
    {
        $options = array_merge(
            $this->config['options'] ?? [],
            $this->currentOptions
        );

        // Always set temperature
        $options['temperature'] = $this->getTemperature();

        // Map maxTokens → num_predict (Ollama's name for max output tokens)
        $maxTokens = $this->getMaxTokens();
        if ($maxTokens !== null) {
            $options['num_predict'] = $maxTokens;
        }

        // Remove keys that are not Ollama options
        unset($options['max_tokens']);

        return $options;
    }

    // ─── Streaming ───────────────────────────────────────────────

    /**
     * Also captures message.tool_calls — needed so AbstractDriver::run()
     * can stream every turn without losing the ability to detect and
     * execute a tool call. Confirmed against a real reported Ollama
     * streaming quirk (ollama/ollama#12557): unlike OpenAI/Anthropic,
     * Ollama does not fragment a tool call's arguments across chunks —
     * message.tool_calls arrives complete (arguments already a parsed
     * object, not a partial JSON string) in whichever chunk carries it, no
     * accumulation needed, just capture it when seen — same as the
     * non-streaming path already assumes.
     */
    protected function handleStream(string $url, array $body): AIResponseInterface
    {
        $callback = $this->streamCallback;
        $fullContent = '';
        $doneResult = null;
        $toolCalls = [];

        // Reasoning models (qwen3, etc.) stream a separate "thinking" field
        // ahead of the real "content" — often for many seconds with nothing
        // in "content" at all. Forwarded as a distinctly-tagged chunk (2nd
        // callback arg) so callers can show live progress instead of dead
        // air. A plain PHP call still *passes* that 2nd argument to a
        // 1-parameter closure (it's simply unbound, not rejected) — which
        // would silently mix raw thinking text into a caller's content
        // stream. Checked once via reflection so a legacy single-parameter
        // callback never receives thinking chunks at all, rather than
        // getting them merged in unannounced.
        $callbackAcceptsType = (new \ReflectionFunction(\Closure::fromCallable($callback)))->getNumberOfParameters() > 1;

        $handleLine = function (string $line) use ($callback, $callbackAcceptsType, &$fullContent, &$doneResult, &$toolCalls, $body) {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $json = json_decode($line, true);
            if (!$json) {
                return;
            }

            $thinkChunk = $json['message']['thinking'] ?? '';
            if ($thinkChunk !== '' && $callbackAcceptsType) {
                $callback($thinkChunk, 'thinking');
            }

            $chunk = $json['message']['content'] ?? '';
            if ($chunk !== '') {
                $fullContent .= $chunk;
                $callbackAcceptsType ? $callback($chunk, 'content') : $callback($chunk);
            }

            foreach ($json['message']['tool_calls'] ?? [] as $tc) {
                $toolCalls[] = new ToolCall(
                    id:        null,
                    name:      $tc['function']['name'] ?? '',
                    arguments: $tc['function']['arguments'] ?? [],
                );
            }

            if (!empty($json['done'])) {
                $doneResult = new AIResponse(
                    content:             $fullContent,
                    promptTokens:        $json['prompt_eval_count'] ?? $this->estimateTokens(json_encode($body['messages'])),
                    completionTokens:    $json['eval_count'] ?? $this->estimateTokens($fullContent),
                    model:               $json['model'] ?? $this->currentModel,
                    provider:            'ollama',
                    raw:                 $json,
                    toolCalls:           $toolCalls,
                    // Built from the accumulated $toolCalls, not this (the
                    // 'done') chunk's own message.tool_calls — a real Ollama
                    // stream delivers the tool call in an earlier chunk
                    // (done: false) and an empty final done:true chunk
                    // (confirmed live behavior, ollama/ollama#12557), so the
                    // done chunk itself typically has none to read.
                    rawAssistantMessage: $toolCalls ? [
                        'role'       => 'assistant',
                        'content'    => $fullContent,
                        'tool_calls' => array_map(fn (ToolCall $tc) => ['function' => ['name' => $tc->name, 'arguments' => $tc->arguments ?: new \stdClass]], $toolCalls),
                    ] : null,
                    structured:          $this->extractStructuredData($fullContent),
                );
            }
        };

        $response = Http::timeout($this->getTimeout())
            ->withOptions(['stream' => true])
            ->post($url, $body);

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (!$stream->eof() && !$doneResult) {
            $buffer .= $stream->read(1024);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // keep incomplete line

            foreach ($lines as $line) {
                $handleLine($line);
                if ($doneResult) {
                    break;
                }
            }
        }

        if ($doneResult) {
            $this->resetOverrides();
            return $doneResult;
        }

        // A final line with no trailing newline (arrives in the same read()
        // that hits EOF) would otherwise sit in $buffer and never get parsed.
        $handleLine($buffer);
        if ($doneResult) {
            $this->resetOverrides();
            return $doneResult;
        }

        $this->resetOverrides();

        return new AIResponse(
            content:          $fullContent,
            promptTokens:     $this->estimateTokens(json_encode($body['messages'])),
            completionTokens: $this->estimateTokens($fullContent),
            model:            $this->currentModel,
            provider:         'ollama',
            raw:              [],
        );
    }

    // ─── Embeddings ──────────────────────────────────────────────

    /**
     * Generate embeddings for one or more inputs.
     *
     * @param  string|array  $input  Single string or array of strings
     * @return array  Array of embedding vectors (array of floats)
     */
    public function embed(string|array $input): array
    {
        $url = rtrim($this->config['url'], '/') . '/api/embed';

        $body = [
            'model' => $this->currentModel,
            'input' => $input,
        ];

        if ($this->currentKeepAlive !== null) {
            $body['keep_alive'] = $this->currentKeepAlive;
        }

        $this->log('Embed', ['model' => $this->currentModel, 'inputs' => is_array($input) ? count($input) : 1]);

        try {
            $response = $this->withRetry(Http::timeout($this->getTimeout()))
                ->post($url, $body);

            if (!$response->successful()) {
                throw new ProviderException(
                    "Ollama embed error: {$response->status()} - {$response->body()}",
                    'ollama',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json();
            $this->resetOverrides();

            return $data['embeddings'] ?? [];
        } catch (ProviderException $e) {
            $this->resetOverrides();
            throw $e;
        } catch (\Throwable $e) {
            $this->resetOverrides();
            throw new ConnectionException(
                "Ollama embed connection failed: {$e->getMessage()}",
                'ollama',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    // ─── Model Management ────────────────────────────────────────

    /**
     * Show detailed model information (size, parameters, template, etc.)
     */
    public function showModel(?string $model = null): array
    {
        $url = rtrim($this->config['url'], '/') . '/api/show';

        try {
            $response = Http::timeout(15)
                ->post($url, ['model' => $model ?? $this->currentModel]);

            if (!$response->successful()) return [];

            return $response->json();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pull (download) a model from Ollama library.
     *
     * @param  string        $model     Model name e.g. 'llama3.1:8b'
     * @param  callable|null $callback  Progress callback: fn(array $status)
     */
    public function pullModel(string $model, ?callable $callback = null): bool
    {
        $url = rtrim($this->config['url'], '/') . '/api/pull';
        $stream = $callback !== null;

        try {
            if ($stream) {
                $response = Http::timeout(600)
                    ->withOptions(['stream' => true])
                    ->post($url, ['model' => $model, 'stream' => true]);

                $body = $response->toPsrResponse()->getBody();
                $buffer = '';

                while (!$body->eof()) {
                    $buffer .= $body->read(1024);
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines);

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        $json = json_decode($line, true);
                        if ($json) $callback($json);
                    }
                }
                return true;
            }

            $response = Http::timeout(600)
                ->post($url, ['model' => $model, 'stream' => false]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Delete a local model.
     */
    public function deleteModel(string $model): bool
    {
        $url = rtrim($this->config['url'], '/') . '/api/delete';

        try {
            $response = Http::timeout(15)
                ->delete($url, ['model' => $model]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Copy/alias a model.
     */
    public function copyModel(string $source, string $destination): bool
    {
        $url = rtrim($this->config['url'], '/') . '/api/copy';

        try {
            $response = Http::timeout(15)
                ->post($url, ['source' => $source, 'destination' => $destination]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List currently running/loaded models with VRAM usage.
     */
    public function runningModels(): array
    {
        $url = rtrim($this->config['url'], '/') . '/api/ps';

        try {
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) return [];

            return $response->json('models', []);
        } catch (\Throwable) {
            return [];
        }
    }

    // ─── Health & Models ─────────────────────────────────────────

    public function health(): bool
    {
        try {
            $url = rtrim($this->config['url'], '/');
            $response = Http::timeout(5)->get($url);
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function models(): array
    {
        try {
            $url = rtrim($this->config['url'], '/') . '/api/tags';
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) return [];

            return array_column($response->json('models', []), 'name');
        } catch (\Throwable) {
            return [];
        }
    }
}
