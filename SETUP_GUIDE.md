# LaravelAI — Setup Guide

A single, narrative walkthrough of installing the package and actually using every feature it ships, with real, copy-pasteable examples. The [README](README.md) is the full reference (config keys, edge cases, troubleshooting); this guide is the "zero to using everything" path.

Never used this package before? [TUTORIAL.md](TUTORIAL.md) teaches the same ground by building one real feature end to end, with the "why" behind each step — read that first if you want a guided course rather than a feature list.

---

## 1. Install

```bash
composer require easybdit/laraveleasyai
php artisan laravelai:install
```

The installer publishes `config/ai.php` and the chat UI's assets, runs migrations, walks you through picking a provider, writes the result to `.env`, and live-checks the connection when it can (Ollama). Safe to re-run later — it never overwrites an `.env` value you've already set.

It also asks whether you want chat attachments (and, if `imagick` is installed, PDF page-image vision) and any conversation-export formats — say yes to either and it runs the matching `composer require` for you right then, instead of you hitting a "run this command" message the first time someone actually uploads a file or clicks export. Say no to everything and it's a no-op — every one of these stays exactly as opt-in as it's always been (see [Attachments & Vision](README.md#-attachments--vision) and [Export](README.md#export-as-pdf-word-excel-or-powerpoint) in the README).

Prefer doing it by hand, or scripting it for CI?

```bash
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=ai-chat-assets
php artisan migrate
```

```env
AI_PROVIDER=ollama
AI_OLLAMA_URL=http://127.0.0.1:11434
AI_OLLAMA_MODEL=qwen2:1.5b
```

---

## 2. Pick your provider(s)

You don't have to choose just one — every provider is configured independently, and you switch between them per-call with `AI::provider('name')`. `AI_PROVIDER` just picks the default when you don't specify one.

```env
# Free, self-hosted, no API key
AI_PROVIDER=ollama
AI_OLLAMA_URL=http://127.0.0.1:11434
AI_OLLAMA_MODEL=qwen2:1.5b
```

```env
AI_OPENAI_KEY=sk-...
AI_OPENAI_MODEL=gpt-4o-mini
```

```env
AI_ANTHROPIC_KEY=sk-ant-...
AI_ANTHROPIC_MODEL=claude-sonnet-5
```

```env
AI_DEEPSEEK_KEY=sk-...
AI_DEEPSEEK_MODEL=deepseek-flash
```

```env
AI_GROQ_KEY=gsk_...
AI_GROQ_MODEL=openai/gpt-oss-120b
```

```env
AI_GEMINI_KEY=...
AI_GEMINI_MODEL=gemini-3.6-flash
```

```env
AI_TOGETHER_KEY=...
AI_TOGETHER_MODEL=meta-llama/Llama-3.3-70B-Instruct-Turbo
```

Any other OpenAI-compatible endpoint (LM Studio, vLLM, OpenRouter, an in-house gateway) — add as many named entries as you like in `config/ai.php`:

```php
'custom_providers' => [
    'lmstudio' => ['label' => 'LM Studio (local)', 'url' => 'http://127.0.0.1:1234/v1', 'api_key' => null, 'model' => 'local-model', 'timeout' => 60],
],
```
```php
AI::custom('lmstudio')->chat($messages);
```

---

## 3. Your first call

```php
use EasyAI\LaravelAI\Facades\AI;

$response = AI::chat([['role' => 'user', 'content' => 'What is Laravel?']]);
echo $response->content;
```

One-liner helper, same thing:

```php
echo ai('What is Laravel?');
```

Explicit provider + fluent builder:

```php
$response = AI::provider('ollama')
    ->model('qwen2:1.5b')
    ->temperature(0.9)
    ->maxTokens(500)
    ->systemPrompt('You are a helpful Laravel expert.')
    ->chat([['role' => 'user', 'content' => 'Explain middleware']]);
```

Sanity-check any provider from Tinker:

```bash
php artisan tinker
>>> AI::provider('ollama')->health()
=> true
>>> ai('Say hello in 3 words')
=> "Hello there, friend!"
```

---

## 4. Streaming

```php
AI::provider('ollama')->stream(
    [['role' => 'user', 'content' => 'Write a poem']],
    function (string $chunk) { echo $chunk; }
);
```

Reasoning models (Ollama's qwen3, Anthropic extended thinking, Gemini 2.5-series) can forward a distinctly-tagged "thinking" chunk as a second callback argument — a legacy single-parameter callback never receives it, so nothing breaks if you don't ask for it:

```php
AI::provider('ollama')->stream($messages, function (string $chunk, string $type = 'content') {
    $type === 'thinking' ? $this->appendThinking($chunk) : $this->appendAnswer($chunk);
});
```

---

## 5. Structured output — get data back, not prose

Works identically on **every provider** (OpenAI, Gemini, Ollama, DeepSeek, Groq, Together, Custom, and Anthropic via a transparent forced tool call under the hood — see the README for the one streaming caveat that's specific to Anthropic):

```php
$schema = [
    'type'       => 'object',
    'properties' => ['city' => ['type' => 'string'], 'temp_c' => ['type' => 'number']],
    'required'   => ['city', 'temp_c'],
];

$response = AI::provider('openai')->format($schema)->chat([
    ['role' => 'user', 'content' => 'What is the weather in Paris? Respond with city and temp_c.'],
]);

$response->getStructuredData(); // ['city' => 'Paris', 'temp_c' => 22]
$response->hasStructuredData(); // true
```

`'json'` instead of a schema array asks for loose "must be valid JSON" mode with no shape enforced.

---

## 6. Embeddings + RAG, end to end

Embeddings work on **Ollama, OpenAI, Gemini, and Together** — pick whichever your RAG setup should use via `AI_RAG_PROVIDER`:

```env
# Free/self-hosted
AI_RAG_PROVIDER=ollama
AI_RAG_EMBED_MODEL=nomic-embed-text
```
```env
# Or fully on OpenAI, no Ollama dependency at all
AI_RAG_PROVIDER=openai
AI_RAG_EMBED_MODEL=text-embedding-3-small
```

Store and query:

```php
use EasyAI\LaravelAI\Facades\AI;

AI::rag()->ingest('Refunds are accepted within 30 days of purchase.', 'policies');

$answer  = AI::rag()->ask('What is the refund window?');
$results = AI::rag()->search('refund policy'); // [['content' => '...', 'source' => 'policies', 'score' => 0.91]]

AI::rag()->flush('policies'); // wipe one source
```

Scoped to a Project (same API, just add `->source()`):

```php
$answer = AI::rag()->source('project_5')->ask('your question');
```

Or from the command line:

```bash
php artisan ai:rag:ingest storage/docs/manual.txt --source=manual
php artisan ai:rag:ingest storage/docs/ --flush
```

Raw embedding vectors directly, if you need them for something else:

```php
$vectors = AI::provider('openai')->model('text-embedding-3-small')->embed('Hello world');
$vectors[0]; // [0.0123, -0.0456, ...]

$vectors = AI::provider('openai')->embed(['first text', 'second text']); // batch
```

---

## 7. Agent module — let the model call your own PHP functions

```php
use EasyAI\LaravelAI\Agent\Tool;

$weather = Tool::make(
    name: 'get_weather',
    description: 'Get the current weather for a city.',
    parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    handler: fn (array $args) => ['temp_c' => 22, 'condition' => 'Sunny'], // call a real API here
);

$response = AI::provider('openai')->tools([$weather])->run([
    ['role' => 'user', 'content' => 'What is the weather in Paris?'],
]);

echo $response->content; // "It's currently 22°C and sunny in Paris."
```

`run()` (not `chat()`) drives the whole loop — sends your message, executes whichever tool the model asks for, feeds the result back, repeats up to 5 round-trips by default (`run($messages, maxSteps: 10)` to raise it). Works the same across OpenAI, Anthropic, Gemini, and Ollama (model-dependent there — needs a tool-calling-capable model like `qwen3`).

Want the final answer to type out token-by-token instead of arriving all at once? Pass a 4th argument:

```php
$response = AI::provider('openai')->tools([$weather])->run(
    $messages, 5, null,
    fn (string $chunk) => print($chunk),
);
```

Built-in web search tool, so an agent isn't limited to its training data:

```php
use EasyAI\LaravelAI\Agent\Tools\WebSearchTool;

$response = AI::provider('openai')->tools([WebSearchTool::make()])->run([
    ['role' => 'user', 'content' => 'What happened in the news today about Laravel?'],
]);
```
```env
AI_WEB_SEARCH_PROVIDER=tavily   # or "brave" — both have real free tiers
AI_TAVILY_API_KEY=tvly-...
```

---

## 8. Image generation

```php
$url = AI::provider('together')->generateImage('a red fox in snow'); // FLUX, hosted URL
$url = AI::provider('openai')->generateImage('a red fox in snow');   // gpt-image-2 by default — base64, not a hosted URL
$url = AI::provider('gemini')->generateImage('a red fox in snow');   // "Nano Banana" — always base64, no URL option
```

```env
AI_OPENAI_IMAGE_MODEL=gpt-image-1   # OpenAI's newer models — base64 only, no URL option
```
Same `generateImage(string): string` contract either way — a `gpt-image-1`/Gemini result just comes back as a `data:image/png;base64,...` string instead of a hosted link, so it still drops straight into `![prompt]($url)` markdown or an `<img src>`.

In the built-in chat UI, a `/image` (or `/img`) command is wired to whichever of these you turn on:
```env
AI_TOGETHER_IMAGE_ENABLED=true
AI_OPENAI_IMAGE_ENABLED=true
AI_GEMINI_IMAGE_ENABLED=true
```
```
/image a red fox in snow
```

Turn on more than one and it's provider-selectable, not hardcoded: it uses whichever of these is the chat provider you're currently talking to (and has its flag on), and falls back to whichever other one is enabled if the active provider can't generate images at all (e.g. you're chatting with Anthropic, DeepSeek, Groq, or Ollama — none of those have an image-generation API at all, not a gap, just not something those providers offer) — see README's [OpenAI section](README.md#openai-chatgpt) for the exact preference rule.

That reply is mirrored into this app's own attachment storage the moment it's generated (so it stays valid regardless of how long Together keeps the original around — see v2.11.1 in the [CHANGELOG](CHANGELOG.md)) and gets its own **⬇ PNG / ⬇ JPEG / ⬇ PDF** buttons instead of the generic "save as .txt" every other reply gets. No configuration needed — it's automatic for any reply that's *only* a generated image.

---

## 9. Audio — speech-to-text and text-to-speech

```php
$text  = AI::provider('openai')->transcribe(storage_path('app/recording.mp3'));
$audio = AI::provider('openai')->textToSpeech('Hello there!'); // raw mp3 bytes

Storage::disk('local')->put('reply.mp3', $audio);
```

```php
// override voice/model/format per call
AI::provider('openai')->textToSpeech($text, ['voice' => 'nova', 'model' => 'gpt-4o-mini-tts']);

// Groq also works here — confirmed genuinely OpenAI-compatible, and fast
AI::provider('groq')->transcribe($path, ['model' => 'whisper-large-v3-turbo']);
```

---

## 10. Reliability: retry, caching, cost tracking

All three are **opt-in, off by default** — nothing changes until you turn them on.

```env
AI_RETRY_TIMES=2      # total attempts, not "retries on top of the first"
AI_RETRY_SLEEP=1000   # milliseconds between attempts
```
```php
AI::provider('openai')->retries(3, 500)->chat($messages); // per-call override
```
A `429`/`5xx`/connection failure retries automatically; a `400`/`401`/`404` never does — it'll never succeed a second time.

```env
AI_CACHE_ENABLED=true
AI_CACHE_TTL=3600
```
Identical requests (same provider, model, messages, temperature, max tokens, system prompt) hit your cache instead of the API. Never applies to `stream()` or a `tools()`-bearing call.

```php
// config/ai.php — empty by default; fill in your own current rate from your provider's pricing page
'pricing' => [
    'openai' => [
        'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006], // USD per 1,000 tokens
        'image' => ['gpt-image-2' => 0.04],                         // flat USD per image
    ],
    'together' => [
        'image' => ['black-forest-labs/FLUX.1-schnell' => ['per_mp' => 0.0027]], // USD per megapixel
    ],
],
```
```php
$response->getEstimatedCost(); // float, or null if that exact provider/model has no configured rate
```

Want that totaled up over time instead of read off one response at a time? Turn on persisted usage logging — off by default, same opt-in posture as everything else here:

```env
AI_USAGE_LOGGING_ENABLED=true
```

Every `chat()`/`generateImage()` call (from the built-in chat UI and your own `AI::provider(...)` code alike) then appends a row to `ai_usage_logs`, using the same `pricing` rates above. A "📊 Usage & Costs" tab on `/ai-chat/settings` shows total spend, a breakdown by provider, and the most recent calls — same fail-safe posture as the rest of the Settings page: an unconfigured rate just shows as "—", never a guessed number.

Health-check + fallback across providers:

```php
foreach (['ollama', 'deepseek', 'openai'] as $provider) {
    try {
        if (!AI::provider($provider)->health()) continue;
        return AI::provider($provider)->chat($messages)->content;
    } catch (\Throwable $e) {
        Log::warning("{$provider} failed: {$e->getMessage()}");
    }
}
```

---

## 11. The built-in chat UI

Already live at `/ai-chat` once you've run the installer. Streaming, history, file attachments, exports (PDF/Word/PowerPoint), and — opt-in — tool calling:

```env
AI_CHAT_TOOLS_ENABLED=true
AI_CHAT_ENABLED_TOOLS=web_search
```

All of that (the toggle, which of Tavily/Brave, both API keys) is also editable from the Settings page's 🔎 Web Search tab below — no `.env` edit needed once you're set up.

The Settings page (`/ai-chat/settings`) is fail-closed by default — nobody can reach it until you grant access:

```bash
php artisan laravelai:make-admin your@email.com
```

Log in as that user and visit `/ai-chat/settings` — a "👤 Admin Access" panel right there lets you add or remove other admins by email afterward, no code needed. Already have your own roles system? `Gate::define('manage-ai-settings', fn ($user) => $user->hasRole('admin'));` anywhere in your app takes over completely.

Get "No user found with that email"? This grants access to an *existing* account, it doesn't create one — a fresh install has no users yet:
```bash
php artisan tinker
>>> \App\Models\User::create(['name' => 'You', 'email' => 'your@email.com', 'password' => bcrypt('choose-a-real-password')]);
```
Then re-run `laravelai:make-admin` against that same email.

If your frontend is a Vue/React SPA using Bearer-token auth rather than Laravel sessions, `$request->user()` won't see your logged-in user on a plain page navigation to `/ai-chat` — see the README's Troubleshooting section for the two fixes (a session-bridge endpoint, or `config('ai.chat.identity_resolver')`).

---

## 12. Projects (self-hosted Claude-like knowledge bases)

All UI-driven: click **＋** next to Projects in the sidebar, upload `.txt`/`.md`/`.pdf` files (auto-ingested into RAG), click the project to start a scoped chat — every message in that session only retrieves context from that project's own documents.

```bash
composer require smalot/pdfparser  # optional, for PDF ingestion
```

---

## 13. Testing your own integration

Everything above is `Http::fake()`-friendly — the pattern this package's own 227 tests use throughout:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.openai.com/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'Hello from OpenAI!']]],
        'usage'   => ['prompt_tokens' => 12, 'completion_tokens' => 6],
    ]),
]);

$response = AI::provider('openai')->chat([['role' => 'user', 'content' => 'Hi']]);
$this->assertSame('Hello from OpenAI!', $response->getContent());
```

---

## Where to go from here

- Full config reference, every `.env` key, and the complete troubleshooting guide: [README.md](README.md)
- What shipped in each version and why: [CHANGELOG.md](CHANGELOG.md)
