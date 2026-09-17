<?php

namespace EasyAI\LaravelAI\Drivers;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelAI\Agent\ToolCall;
use EasyAI\LaravelAI\Contracts\AIResponseInterface;
use EasyAI\LaravelAI\Exceptions\ConnectionException;
use EasyAI\LaravelAI\Exceptions\ProviderException;
use EasyAI\LaravelAI\Response\AIResponse;
use EasyAI\LaravelAI\Support\MessageFormatter;
use EasyAI\LaravelAI\Support\UsageLogger;
use Illuminate\Support\Facades\Http;

class OpenAIDriver extends AbstractDriver
{
    public function getProviderName(): string
    {
        return 'openai';
    }

    protected function doChat(array $messages): AIResponseInterface
    {
        $messages = $this->prependSystemPrompt($messages);
        $formatted = MessageFormatter::normalize($messages, 'openai');
        $formatted['messages'] = MessageFormatter::toProviderContent($formatted['messages'], 'openai');
        $url = rtrim($this->config['url'], '/') . '/chat/completions';
        $isStream = $this->streamCallback !== null;

        $body = [
            'model'       => $this->currentModel,
            'messages'    => $formatted['messages'],
            'temperature' => $this->getTemperature(),
            'stream'      => $isStream,
        ];

        if ($maxTokens = $this->getMaxTokens()) {
            $body['max_tokens'] = $maxTokens;
        }

        if ($this->currentTools) {
            $body['tools'] = array_map(fn (Tool $t) => [
                'type'     => 'function',
                'function' => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->parameters],
            ], $this->currentTools);
        }

        // Structured output — 'json' asks for loose JSON-object mode, a
        // schema array asks for strict schema-constrained mode. Support for
        // the strict json_schema mode varies by model on non-OpenAI
        // OpenAI-compatible backends (DeepSeek/Groq/Together/Custom) the
        // same way tool-calling support already does — a model that
        // doesn't support it returns its own error, surfaced as normal via
        // ProviderException below rather than this driver guessing.
        if ($this->currentFormat !== null) {
            $body['response_format'] = is_array($this->currentFormat)
                ? ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'strict' => true, 'schema' => $this->currentFormat]]
                : ['type' => 'json_object'];
        }

        $this->log('Request', ['model' => $this->currentModel, 'messages_count' => count($messages)]);

        try {
            if ($isStream) {
                return $this->handleStream($url, $body);
            }

            $response = $this->withRetry(
                Http::timeout($this->getTimeout())->withToken($this->config['api_key'])
            )->post($url, $body);

            if (!$response->successful()) {
                throw new ProviderException(
                    "{$this->getProviderName()} error: {$response->status()} - {$response->body()}",
                    $this->getProviderName(),
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json();
            $message = $data['choices'][0]['message'] ?? [];

            $toolCalls = [];
            foreach ($message['tool_calls'] ?? [] as $tc) {
                $toolCalls[] = new ToolCall(
                    id:        $tc['id'] ?? null,
                    name:      $tc['function']['name'] ?? '',
                    arguments: json_decode($tc['function']['arguments'] ?? '', true) ?: [],
                );
            }

            $result = new AIResponse(
                content:             $message['content'] ?? '',
                promptTokens:        $data['usage']['prompt_tokens'] ?? 0,
                completionTokens:    $data['usage']['completion_tokens'] ?? 0,
                model:               $data['model'] ?? $this->currentModel,
                provider:            $this->getProviderName(),
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
                "{$this->getProviderName()} connection failed: {$e->getMessage()}",
                $this->getProviderName(),
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * Also reassembles tool_calls from delta.tool_calls fragments — needed
     * so AbstractDriver::run() can stream every turn (not just detect a
     * final text-only answer after the fact) without losing the ability to
     * detect and execute a tool call. Confirmed against OpenAI's own docs:
     * each delta.tool_calls entry carries an 'index' identifying which
     * call it belongs to (parallel tool calls share one stream, distinguished
     * by index); id/type/function.name arrive complete in that call's first
     * delta, function.arguments arrives as a partial JSON *string* split
     * across many deltas — concatenate by index, JSON-decode only once the
     * stream ends. Same "arrives as fragments" characteristic Anthropic's
     * input_json_delta has, different field names.
     */
    protected function handleStream(string $url, array $body): AIResponseInterface
    {
        $callback = $this->streamCallback;
        $fullContent = '';
        $model = $this->currentModel;
        /** @var array<int, array{id: ?string, name: string, arguments: string}> $toolCallAccum */
        $toolCallAccum = [];

        $response = Http::timeout($this->getTimeout())
            ->withToken($this->config['api_key'])
            ->withOptions(['stream' => true])
            ->post($url, $body);

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        $handleLine = function (string $line) use ($callback, &$fullContent, &$model, &$toolCallAccum) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ')) {
                return;
            }
            $data = substr($line, 6);
            if ($data === '[DONE]') {
                return;
            }
            $json = json_decode($data, true);
            if (!$json) {
                return;
            }

            $model = $json['model'] ?? $model;
            $delta = $json['choices'][0]['delta'] ?? [];

            $chunk = $delta['content'] ?? '';
            if ($chunk !== '') {
                $fullContent .= $chunk;
                $callback($chunk);
            }

            foreach ($delta['tool_calls'] ?? [] as $tc) {
                $i = $tc['index'] ?? 0;
                $toolCallAccum[$i] ??= ['id' => null, 'name' => '', 'arguments' => ''];
                if (isset($tc['id'])) {
                    $toolCallAccum[$i]['id'] = $tc['id'];
                }
                $toolCallAccum[$i]['name']      .= $tc['function']['name'] ?? '';
                $toolCallAccum[$i]['arguments'] .= $tc['function']['arguments'] ?? '';
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

        // A final "data: ..." line with no trailing newline (arrives in the
        // same read() that hits EOF) would otherwise sit in $buffer unparsed.
        $handleLine($buffer);

        $toolCalls = [];
        $rawAssistantMessage = null;
        if ($toolCallAccum) {
            ksort($toolCallAccum); // preserve call order, not accumulation-completion order
            $wireToolCalls = [];
            foreach ($toolCallAccum as $tc) {
                $toolCalls[] = new ToolCall(
                    id:        $tc['id'],
                    name:      $tc['name'],
                    arguments: json_decode($tc['arguments'], true) ?: [],
                );
                // Same shape doChat()'s non-streaming $message would have had —
                // appendToolExchange() replays this back verbatim, and OpenAI's
                // wire format expects function.arguments as the raw JSON string,
                // not the decoded array ToolCall itself carries.
                $wireToolCalls[] = [
                    'id'       => $tc['id'],
                    'type'     => 'function',
                    'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']],
                ];
            }
            $rawAssistantMessage = [
                'role'       => 'assistant',
                'content'    => $fullContent !== '' ? $fullContent : null,
                'tool_calls' => $wireToolCalls,
            ];
        }

        // Must read before resetOverrides() below clears currentFormat.
        $structured = $this->extractStructuredData($fullContent);
        $this->resetOverrides();

        return new AIResponse(
            content:             $fullContent,
            promptTokens:        $this->estimateTokens(json_encode($body['messages'])),
            completionTokens:    $this->estimateTokens($fullContent),
            model:               $model,
            provider:            $this->getProviderName(),
            raw:                 [],
            toolCalls:           $toolCalls,
            rawAssistantMessage: $rawAssistantMessage,
            structured:          $structured,
        );
    }

    /**
     * @param array{call: ToolCall, result: mixed}[] $results
     */
    protected function appendToolExchange(array $messages, AIResponseInterface $response, array $results): array
    {
        $messages[] = $response->getRawAssistantMessage() ?? ['role' => 'assistant', 'content' => $response->getContent()];

        foreach ($results as $r) {
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $r['call']->id,
                'content'      => is_string($r['result']) ? $r['result'] : json_encode($r['result']),
            ];
        }

        return $messages;
    }

    /**
     * OpenAI's real /embeddings endpoint — inherited as-is by every driver
     * that extends this one (DeepSeek, Groq, Together, Custom). Actual
     * support varies by backend the same way response_format/tool-calling
     * support already does: Together has a real, OpenAI-shaped /embeddings
     * endpoint (confirmed against its own docs), Groq and DeepSeek do not
     * expose one at all as of this writing — calling ->embed() against
     * either surfaces that backend's own error via ProviderException below,
     * exactly like any other unsupported request, rather than this driver
     * guessing or faking a result.
     */
    public function embed(string|array $input): array
    {
        $url = rtrim($this->config['url'], '/') . '/embeddings';

        $body = [
            'model' => $this->currentModel,
            'input' => $input,
        ];

        $this->log('Embed', ['model' => $this->currentModel, 'inputs' => is_array($input) ? count($input) : 1]);

        try {
            $response = $this->withRetry(
                Http::timeout($this->getTimeout())->withToken($this->config['api_key'])
            )->post($url, $body);

            if (!$response->successful()) {
                throw new ProviderException(
                    "{$this->getProviderName()} embed error: {$response->status()} - {$response->body()}",
                    $this->getProviderName(),
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $data = $response->json();
            $this->resetOverrides();

            // Sorted by "index" rather than trusted as pre-ordered — the
            // field exists specifically to map a result back to its input
            // position, so use it rather than assume array order.
            $items = $data['data'] ?? [];
            usort($items, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            return array_map(fn ($item) => $item['embedding'] ?? [], $items);
        } catch (ProviderException $e) {
            $this->resetOverrides();
            throw $e;
        } catch (\Throwable $e) {
            $this->resetOverrides();
            throw new ConnectionException(
                "{$this->getProviderName()} embed connection failed: {$e->getMessage()}",
                $this->getProviderName(),
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * OpenAI's real /images/generations endpoint. Same generateImage(string
     * $prompt): string contract as TogetherDriver's — returns something
     * directly usable as an <img src> or markdown image, regardless of
     * which underlying model actually ran: dall-e-2/dall-e-3 return a
     * hosted url (requested explicitly below), while the newer GPT image
     * models (gpt-image-1, gpt-image-1-mini, gpt-image-1.5) return only
     * base64 and reject the response_format param outright — confirmed
     * against OpenAI's own OpenAPI spec, not assumed — so this branches on
     * whichever field the response actually contains rather than guessing
     * from the configured model name, and wraps base64 as a data: URI to
     * keep the same "one usable string" contract either way.
     *
     * Inherited by DeepSeek/Groq/Together/Custom the same as embed() — an
     * image-generation endpoint isn't part of the general "OpenAI-compatible
     * chat completions" convention those backends follow, so this will
     * likely 404 on DeepSeek/Groq specifically; TogetherDriver already
     * overrides this method with its own real implementation, and Custom
     * depends entirely on what the user's own endpoint actually supports.
     */
    public function generateImage(string $prompt): string
    {
        $url   = rtrim($this->config['url'], '/') . '/images/generations';
        $model = $this->config['image_model'] ?? 'gpt-image-2';

        $body = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
        ];

        if (!str_starts_with((string) $model, 'gpt-image')) {
            $body['response_format'] = 'url';
        }

        try {
            $response = $this->withRetry(
                Http::timeout($this->getTimeout())->withToken($this->config['api_key'])
            )->post($url, $body);

            if (!$response->successful()) {
                throw new ProviderException(
                    "{$this->getProviderName()} image error: {$response->status()} - {$response->body()}",
                    $this->getProviderName(),
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            $image = $response->json('data.0');

            if (!empty($image['url']) || !empty($image['b64_json'])) {
                UsageLogger::log($this->getProviderName(), $model, 'image', ['image_count' => 1]);
            }

            if (!empty($image['url'])) {
                return $image['url'];
            }

            if (!empty($image['b64_json'])) {
                return 'data:image/png;base64,' . $image['b64_json'];
            }

            throw new ProviderException(
                "{$this->getProviderName()} image error: response had neither url nor b64_json",
                $this->getProviderName()
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "{$this->getProviderName()} image connection failed: {$e->getMessage()}",
                $this->getProviderName(),
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * OpenAI's /audio/transcriptions endpoint — multipart file upload,
     * verified against OpenAI's own OpenAPI spec. Takes a path to an
     * already-on-disk audio file (flac/mp3/mp4/mpeg/mpga/m4a/ogg/wav/webm
     * per that spec) and returns the transcribed text.
     *
     * Inherited by DeepSeek/Groq/Together/Custom the same as embed()/
     * generateImage() — real support genuinely varies here, checked
     * against each provider's own docs rather than assumed: Groq's
     * transcription endpoint is confirmed OpenAI-compatible (same path
     * shape, `whisper-large-v3-turbo` as its model), Together's docs show
     * no transcription endpoint at all, and DeepSeek exposes no audio API
     * whatsoever. Calling this against either of the latter two surfaces
     * their own real error rather than this driver faking a result.
     */
    public function transcribe(string $audioFilePath, array $options = []): string
    {
        if (!is_file($audioFilePath)) {
            throw new \InvalidArgumentException("Audio file not found: {$audioFilePath}");
        }

        $url = rtrim($this->config['url'], '/') . '/audio/transcriptions';

        try {
            $response = $this->withRetry(
                Http::timeout($this->getTimeout())->withToken($this->config['api_key'])
            )->attach('file', file_get_contents($audioFilePath), basename($audioFilePath))
                ->post($url, array_filter([
                    'model'    => $options['model'] ?? $this->config['transcribe_model'] ?? 'whisper-1',
                    'language' => $options['language'] ?? null,
                    'prompt'   => $options['prompt'] ?? null,
                ], fn ($v) => $v !== null));

            if (!$response->successful()) {
                throw new ProviderException(
                    "{$this->getProviderName()} transcribe error: {$response->status()} - {$response->body()}",
                    $this->getProviderName(),
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            return $response->json('text') ?? '';
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "{$this->getProviderName()} transcribe connection failed: {$e->getMessage()}",
                $this->getProviderName(),
                ['url' => $url],
                0,
                $e
            );
        }
    }

    /**
     * OpenAI's /audio/speech endpoint — returns the raw binary audio bytes
     * (confirmed: response content-type is application/octet-stream per
     * OpenAI's own OpenAPI spec, not a JSON wrapper), so the caller decides
     * what to do with them (save via Storage::put(), stream back in an
     * HTTP response, etc.) rather than this driver assuming a destination.
     *
     * Inherited by DeepSeek/Groq/Together/Custom the same as transcribe()
     * above — Groq and Together both confirmed OpenAI-compatible
     * /audio/speech endpoints of their own; DeepSeek has none.
     */
    public function textToSpeech(string $text, array $options = []): string
    {
        $url = rtrim($this->config['url'], '/') . '/audio/speech';

        try {
            $response = $this->withRetry(
                Http::timeout($this->getTimeout())->withToken($this->config['api_key'])
            )->post($url, [
                'model'           => $options['model'] ?? $this->config['tts_model'] ?? 'tts-1',
                'input'           => $text,
                'voice'           => $options['voice'] ?? $this->config['tts_voice'] ?? 'alloy',
                'response_format' => $options['format'] ?? 'mp3',
            ]);

            if (!$response->successful()) {
                throw new ProviderException(
                    "{$this->getProviderName()} speech error: {$response->status()} - {$response->body()}",
                    $this->getProviderName(),
                    ['status' => $response->status()],
                    $response->status()
                );
            }

            return $response->body();
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ConnectionException(
                "{$this->getProviderName()} speech connection failed: {$e->getMessage()}",
                $this->getProviderName(),
                ['url' => $url],
                0,
                $e
            );
        }
    }

    public function health(): bool
    {
        try {
            $url = rtrim($this->config['url'], '/') . '/models';
            $response = Http::timeout(10)
                ->withToken($this->config['api_key'])
                ->get($url);
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function models(): array
    {
        try {
            $url = rtrim($this->config['url'], '/') . '/models';
            $response = Http::timeout(10)
                ->withToken($this->config['api_key'])
                ->get($url);

            if (!$response->successful()) return [];

            return array_column($response->json('data', []), 'id');
        } catch (\Throwable) {
            return [];
        }
    }
}
