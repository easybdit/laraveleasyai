<?php

namespace EasyAI\LaravelAI\Drivers;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelAI\Agent\ToolCall;
use EasyAI\LaravelAI\Contracts\AIResponseInterface;
use EasyAI\LaravelAI\Exceptions\ConnectionException;
use EasyAI\LaravelAI\Exceptions\ProviderException;
use EasyAI\LaravelAI\Response\AIResponse;
use EasyAI\LaravelAI\Support\MessageFormatter;
use Illuminate\Support\Facades\Http;

class AnthropicDriver extends AbstractDriver
{
    /**
     * Name of the synthetic forced tool used to implement ->format()
     * structured output — see doChat()'s "Structured output" comment.
     * Deliberately distinctive so it can never collide with a real
     * caller-defined Tool name.
     */
    private const STRUCTURED_TOOL_NAME = '__laravelai_structured_response';

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    protected function doChat(array $messages): AIResponseInterface
    {
        $messages  = $this->prependSystemPrompt($messages);
        $formatted = MessageFormatter::normalize($messages, 'anthropic');
        $formatted['messages'] = MessageFormatter::toProviderContent($formatted['messages'], 'anthropic');
        $url       = rtrim($this->config['url'], '/') . '/messages';
        $isStream  = $this->streamCallback !== null;

        // ->format() is implemented here as a forced tool call (see below),
        // and handleStream() only decodes text/thinking deltas — it has no
        // parser for a streamed tool call's input_json_delta events. Rather
        // than silently returning empty content for this combination, fail
        // loudly: use ->format() with chat() (or drop ->stream() for this
        // call) on Anthropic specifically.
        if ($isStream && $this->currentFormat !== null) {
            throw new \BadMethodCallException(
                'Structured output (->format()) is not supported together with ->stream() on the Anthropic driver — the forced tool call this uses to get structured data isn\'t decodable from a text/thinking-only stream parser. Use ->format() with chat() instead.'
            );
        }

        $maxTokens = $this->getMaxTokens() ?? 2000;

        $body = [
            'model'    => $this->currentModel,
            'messages' => $formatted['messages'],
            'stream'   => $isStream,
        ];

        if ($formatted['system']) {
            $body['system'] = $formatted['system'];
        }

        // Extended thinking. Anthropic requires max_tokens to exceed
        // budget_tokens, and rejects a custom temperature while thinking is
        // enabled (must be left at the API default) — both handled here so
        // ->think(true) alone is enough, no extra tuning required.
        $think = $this->currentThink ?? ($this->config['think'] ?? null);
        if ($think) {
            $budgetTokens = (int) ($this->config['think_budget_tokens'] ?? 10000);
            $maxTokens    = max($maxTokens, $budgetTokens + 1024);
            $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budgetTokens];
        } elseif ($this->currentTemp !== null) {
            $body['temperature'] = $this->getTemperature();
        }

        $body['max_tokens'] = $maxTokens;

        if ($this->currentTools) {
            $body['tools'] = array_map(fn (Tool $t) => [
                'name'         => $t->name,
                'description'  => $t->description,
                'input_schema' => $t->parameters,
            ], $this->currentTools);
        }

        // Structured output — Anthropic has no native JSON-mode/response_format,
        // so this is built as a single forced tool call instead: a synthetic
        // tool whose input_schema is the requested shape (or a bare "object"
        // for a plain 'json' request, same guarantee as OpenAI's json_object
        // mode), with tool_choice pinned to it so the model can't do anything
        // but call it. Deliberately overrides $currentTools above rather than
        // merging with them — combining a real agent tool set with a forced
        // structured-output call isn't a coherent request (the model
        // couldn't choose a real tool if one is being forced), so schema
        // mode wins when both are set. The resulting tool_use block is
        // unpacked back into plain content/structured below, not surfaced
        // to the caller as a real tool call.
        if ($this->currentFormat !== null) {
            $schema = is_array($this->currentFormat) ? $this->currentFormat : ['type' => 'object'];
            $body['tools'] = [[
                'name'         => self::STRUCTURED_TOOL_NAME,
                'description'  => 'Return the response as structured data in this exact shape.',
                'input_schema' => $schema,
            ]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => self::STRUCTURED_TOOL_NAME];
        }

        $this->log('Request', ['model' => $this->currentModel, 'messages_count' => count($formatted['messages'])]);

        try {
            if ($isStream) {
                return $this->handleStream($url, $body);
            }

            $response = $this->withRetry(
                Http::timeout($this->getTimeout())->withHeaders([
                    'x-api-key'         => $this->config['api_key'],
                    'anthropic-version' => $this->config['version'] ?? '2023-06-01',
                    'content-type'      => 'application/json',
                ])
            )->post($url, $body);

            if (!$response->successful()) {
                throw new ProviderException(
                    "Anthropic error: {$response->status()} - {$response->body()}",
                    'anthropic',
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json();

            // Extract text and tool_use blocks. content can hold both (e.g. a
            // model "thinking out loud" in text before calling a tool), and
            // there can be more than one tool_use block (parallel tool calls).
            $content = '';
            $toolCalls = [];
            $structured = null;
            foreach ($data['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= $block['text'];
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    // The forced structured-output call above (this ->format()
                    // request's own synthetic tool, never a real caller tool)
                    // — unpack its input as the structured payload instead of
                    // surfacing it as a tool call the caller has to handle.
                    if (($block['name'] ?? '') === self::STRUCTURED_TOOL_NAME) {
                        $structured = $block['input'] ?? [];
                        $content = json_encode($structured);
                        continue;
                    }
                    $toolCalls[] = new ToolCall(
                        id:        $block['id'] ?? null,
                        name:      $block['name'] ?? '',
                        arguments: $block['input'] ?? [],
                    );
                }
            }

            $result = new AIResponse(
                content:             $content,
                promptTokens:        $data['usage']['input_tokens'] ?? 0,
                completionTokens:    $data['usage']['output_tokens'] ?? 0,
                model:               $data['model'] ?? $this->currentModel,
                provider:            'anthropic',
                raw:                 $data,
                toolCalls:           $toolCalls,
                rawAssistantMessage: $data['content'] ?? [],
                structured:          $structured,
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
                "Anthropic connection failed: {$e->getMessage()}",
                'anthropic',
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * Also reassembles tool_use blocks from content_block_start/_delta/_stop
     * events — needed so AbstractDriver::run() can stream every turn without
     * losing the ability to detect and execute a tool call. Confirmed
     * against Anthropic's own streaming docs: each content block (text OR
     * tool_use) gets its own index; a tool_use block's content_block_start
     * carries id/name complete with an empty input:{} placeholder, then one
     * or more content_block_delta/input_json_delta events each carry a
     * partial_json *string* fragment — concatenated by index, JSON-decoded
     * only once the stream ends (mirrors OpenAI's delta.tool_calls, in
     * Anthropic's own block/event shape rather than OpenAI's flatter one).
     */
    protected function handleStream(string $url, array $body): AIResponseInterface
    {
        $callback = $this->streamCallback;
        $fullContent = '';
        $inputTokens = 0;
        $outputTokens = 0;
        /** @var array<int, array{type: string, text?: string, id?: ?string, name?: string, input?: string}> $blocks */
        $blocks = [];

        $response = Http::timeout($this->getTimeout())
            ->withHeaders([
                'x-api-key'         => $this->config['api_key'],
                'anthropic-version' => $this->config['version'] ?? '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->withOptions(['stream' => true])
            ->post($url, $body);

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        // Extended thinking streams a separate thinking_delta ahead of the
        // real text_delta. Forwarded as a distinctly-tagged chunk (2nd
        // callback arg), gated by reflection the same way as Ollama's
        // thinking support — a legacy single-parameter callback never
        // receives it, rather than silently getting reasoning text merged
        // into its content.
        $callbackAcceptsType = (new \ReflectionFunction(\Closure::fromCallable($callback)))->getNumberOfParameters() > 1;

        $handleLine = function (string $line) use ($callback, $callbackAcceptsType, &$fullContent, &$inputTokens, &$outputTokens, &$blocks) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ')) {
                return;
            }
            $json = json_decode(substr($line, 6), true);
            if (!$json) {
                return;
            }

            $type  = $json['type'] ?? '';
            $index = $json['index'] ?? 0;

            if ($type === 'content_block_start') {
                $block = $json['content_block'] ?? [];
                $blocks[$index] = ($block['type'] ?? '') === 'tool_use'
                    ? ['type' => 'tool_use', 'id' => $block['id'] ?? null, 'name' => $block['name'] ?? '', 'input' => '']
                    : ['type' => 'text', 'text' => ''];
            }

            if ($type === 'content_block_delta') {
                $deltaType = $json['delta']['type'] ?? '';

                if ($deltaType === 'thinking_delta') {
                    $thinkChunk = $json['delta']['thinking'] ?? '';
                    if ($thinkChunk !== '' && $callbackAcceptsType) {
                        $callback($thinkChunk, 'thinking');
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $blocks[$index]['input'] = ($blocks[$index]['input'] ?? '') . ($json['delta']['partial_json'] ?? '');
                } else {
                    $chunk = $json['delta']['text'] ?? '';
                    if ($chunk !== '') {
                        $fullContent .= $chunk;
                        $blocks[$index]['text'] = ($blocks[$index]['text'] ?? '') . $chunk;
                        $callbackAcceptsType ? $callback($chunk, 'content') : $callback($chunk);
                    }
                }
            }
            if ($type === 'message_delta') {
                $outputTokens = $json['usage']['output_tokens'] ?? $outputTokens;
            }
            if ($type === 'message_start') {
                $inputTokens = $json['message']['usage']['input_tokens'] ?? 0;
            }
        };

        while (!$stream->eof()) {
            $buffer .= $stream->read(1024);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $handleLine($line);
            }
        }
        // A final line with no trailing newline (arrives in the same read()
        // that hits EOF) would otherwise sit in $buffer unparsed.
        $handleLine($buffer);

        $toolCalls = [];
        $rawContentBlocks = [];
        ksort($blocks); // preserve block order, not accumulation-completion order
        foreach ($blocks as $block) {
            // A delta can in principle arrive for an index this parser never
            // saw a content_block_start for (defensive, not expected from a
            // real Anthropic stream, which always sends one first) — treat
            // it as a text block rather than crashing on a missing 'type'.
            if (($block['type'] ?? 'text') === 'tool_use') {
                $input = json_decode($block['input'] ?: '{}', true) ?: [];
                $toolCalls[] = new ToolCall(id: $block['id'], name: $block['name'], arguments: $input);
                $rawContentBlocks[] = ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => $input];
            } elseif (($block['text'] ?? '') !== '') {
                $rawContentBlocks[] = ['type' => 'text', 'text' => $block['text']];
            }
        }

        $this->resetOverrides();

        return new AIResponse(
            content:             $fullContent,
            promptTokens:        $inputTokens,
            completionTokens:    $outputTokens ?: $this->estimateTokens($fullContent),
            model:               $this->currentModel,
            provider:            'anthropic',
            raw:                 [],
            toolCalls:           $toolCalls,
            rawAssistantMessage: $rawContentBlocks ?: null,
        );
    }

    /**
     * @param array{call: ToolCall, result: mixed}[] $results
     */
    protected function appendToolExchange(array $messages, AIResponseInterface $response, array $results): array
    {
        $messages[] = ['role' => 'assistant', 'content' => $response->getRawAssistantMessage() ?? []];

        $toolResultBlocks = [];
        foreach ($results as $r) {
            $toolResultBlocks[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $r['call']->id,
                'content'     => is_string($r['result']) ? $r['result'] : json_encode($r['result']),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResultBlocks];

        return $messages;
    }

    public function health(): bool
    {
        // Anthropic has no free health endpoint; attempt a minimal call
        try {
            $url = rtrim($this->config['url'], '/') . '/messages';
            $response = Http::timeout(10)
                ->withHeaders([
                    'x-api-key'         => $this->config['api_key'],
                    'anthropic-version' => $this->config['version'] ?? '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post($url, [
                    'model'      => $this->currentModel,
                    'max_tokens' => 1,
                    'messages'   => [['role' => 'user', 'content' => 'Hi']],
                ]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function models(): array
    {
        // Anthropic doesn't have a public models list endpoint. This list
        // used to return the claude-*-4-... snapshots — Sonnet 4 and Opus 4
        // were both confirmed retired by Anthropic on 2026-06-15 (API calls
        // to them error), so this is the current Claude 5 family instead.
        return [
            'claude-opus-5',
            'claude-sonnet-5',
            'claude-haiku-4-5-20251001',
        ];
    }
}
