<p align="center">
  <img src="https://raw.githubusercontent.com/easybdit/laraveleasyai/main/art/banner.svg" width="100%" alt="LaravelAI Banner">
</p>

<h1 align="center">LaravelAI</h1>

<p align="center">
  <strong>One interface, any AI.</strong><br>
  Unified AI chat for Laravel — Ollama, OpenAI (ChatGPT), Anthropic (Claude), DeepSeek
</p>

<p align="center">
  <sub>👨‍💻 Full Stack Laravel Vue Developer and DevOps Engineer</sub>
</p>

<p align="center">
  <a href="https://packagist.org/packages/easybdit/laraveleasyai"><img src="https://img.shields.io/packagist/v/easybdit/laraveleasyai.svg?style=flat-square&label=version" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/easybdit/laraveleasyai"><img src="https://img.shields.io/packagist/dt/easybdit/laraveleasyai.svg?style=flat-square&label=downloads" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/easybdit/laraveleasyai"><img src="https://img.shields.io/packagist/l/easybdit/laraveleasyai.svg?style=flat-square" alt="License"></a>
  <a href="https://packagist.org/packages/easybdit/laraveleasyai"><img src="https://img.shields.io/packagist/php-v/easybdit/laraveleasyai.svg?style=flat-square" alt="PHP Version"></a>
  <a href="https://github.com/easybdit/laraveleasyai/actions"><img src="https://img.shields.io/github/actions/workflow/status/easybdit/laraveleasyai/tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
</p>

<p align="center">
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-built-in-chat-ui">Chat UI</a> •
  <a href="#-projects--knowledge-bases">Projects</a> •
  <a href="#-rag-built-in">RAG</a> •
  <a href="#-security--trust">Security</a> •
  <a href="#%EF%B8%8F-provider-settings-ui--auth-guard">Settings UI</a> •
  <a href="#-chat-ux--personalization">UX &amp; Widget</a> •
  <a href="#-commerce-assistants">Commerce</a> •
  <a href="#-providers">Providers</a> •
  <a href="#-agent-module--tool--function-calling">Agent &amp; Tools</a> •
  <a href="#-api-reference">API Reference</a> •
  <a href="#%EF%B8%8F-configuration">Configuration</a> •
  <a href="#-troubleshooting">Troubleshooting</a>
</p>

<p align="center">
  <a href="https://www.facebook.com/easybdit">📘 Facebook Page</a> •
  <a href="https://www.facebook.com/groups/eitbd">👥 Facebook Group</a> •
  <a href="https://chat.whatsapp.com/E3VV0K6lkrqEgXdngrt2Rk">💬 WhatsApp Group</a>
</p>

<p align="center">
  <sub>🔌 Not a Laravel site? The same chat, self-hosted on <strong>WordPress</strong>: <a href="https://wordpress.org/plugins/easyit-ai-chat/">EasyIT AI Chat plugin</a> — free, no Pro tier.</sub>
</p>

---

## Why LaravelAI?

Building AI features in Laravel normally means separate SDKs, different formats, and custom error handling for every provider. **LaravelAI eliminates all of that.**

```php
// Same code. Any provider. Just change the name.
$response = AI::provider('ollama')->chat($messages);    // Self-hosted, free
$response = AI::provider('openai')->chat($messages);    // ChatGPT
$response = AI::provider('anthropic')->chat($messages); // Claude
$response = AI::provider('deepseek')->chat($messages);  // DeepSeek
```

Built on **Laravel's driver pattern** — same architecture as Mail, Cache, and Queue.

### What you get, all in one package

- **8 providers, one interface** — Ollama (free, self-hosted), OpenAI, Anthropic, DeepSeek, Groq, Gemini, Together AI, and any custom OpenAI-compatible endpoint. Switch providers by changing one string, never your calling code.
- **A ready-made chat UI** — `/ai-chat` is live the moment you install it. No frontend to build.
- **RAG built in** — ingestion, search, and a pluggable vector store (`pgvector` included), no separate vector-database service to stand up first.
- **Tool-calling and structured output**, working the same way across every provider above — including Anthropic, which has no native JSON mode, handled transparently via a forced tool call under the hood.
- **Response caching and automatic retry with backoff**, both opt-in, one `.env` flag each.
- **Conversation export** to PDF, Word, and PowerPoint, built in.
- **PHP 8.1+, Laravel 10–13** — and free to try right now: point `AI_PROVIDER=ollama` at a local model, no API key needed.

**Three real scenarios where that adds up:**

**1. You want to try AI in your app without spending anything or waiting on an API key.**
```bash
composer require easybdit/laraveleasyai
php artisan laravelai:install   # pick "Ollama" — free, self-hosted, no key needed
```
```php
echo ai('What is Laravel?'); // running against a model on your own machine
```

**2. You need a working chat screen, not just an API client.**
```bash
php artisan laravelai:install   # publishes /ai-chat, migrates, done
```
Visit `/ai-chat` — a full ChatGPT-style window (streaming, history, file uploads, exports) is already there. No React/Vue build step, no writing a chat frontend from scratch.

**3. You want the model to answer from your own documents, not just its training data.**
```php
use EasyAI\LaravelAI\Facades\AI;

AI::rag()->ingest('Refunds are accepted within 30 days of purchase.', 'policies');
$answer = AI::rag()->ask('What is the refund window?');
// "Refunds are accepted within 30 days of purchase."
```
No separate vector-database service to stand up first — the built-in scan backend works out of the box on your existing SQL database; swap in `pgvector` later only if you outgrow it.

---

## 📦 Installation

> 📘 Prefer a single narrative walkthrough of every feature with real examples, install to done? See the **[Setup Guide](SETUP_GUIDE.md)**. This README is the full reference.
>
> 🎓 **Never used an AI package before?** Start with the **[Tutorial](TUTORIAL.md)** instead — it walks you through building one real, complete feature (a support chatbot with a knowledge base, tool calling, image generation, and cost tracking) step by step, taught the way a senior dev would pair with you on it. The Setup Guide above is for once you already know the package and just need a specific snippet.

**Step 1:** Install via Composer

```bash
composer require easybdit/laraveleasyai
```

**Step 2:** Run the guided installer — publishes config/assets, runs migrations, and walks you through picking a provider (Ollama, OpenAI, Anthropic, DeepSeek, or Gemini), all in one interactive command:

```bash
php artisan laravelai:install
```

It asks before overwriting anything that already exists, and never clobbers an `.env` value you've already set — safe to run again later if you just want to add a second provider's key.

**Step 3:** Visit `/ai-chat` in your browser ✅

<details>
<summary>Prefer doing it manually, or scripting the install for CI? (click to expand)</summary>

```bash
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=ai-chat-assets
php artisan migrate
```

Then add to `.env` yourself:

```env
AI_PROVIDER=ollama
AI_OLLAMA_URL=http://127.0.0.1:11434
AI_OLLAMA_MODEL=qwen2:1.5b
```

</details>

### Requirements

| Requirement | Version |
|-------------|---------|
| PHP         | 8.1+    |
| Laravel     | 10, 11, 12, 13 |

---

## 🚀 Quick Start

```php
use EasyAI\LaravelAI\Facades\AI;

$response = AI::chat([['role' => 'user', 'content' => 'What is Laravel?']]);
echo $response->content;
```

### One-Liner Helper

```php
$answer = ai('What is Laravel?');
```

### Test in Tinker

```bash
php artisan tinker
>>> AI::provider('ollama')->health()
=> true
>>> ai('Say hello in 3 words')
=> "Hello there, friend!"
```

### 📋 How every example below works

New to Laravel, or just want to paste-and-run instead of piecing snippets together? Three things to know before you go further — everything below assumes them without repeating them every time:

**1. Where to actually paste this code.** Tinker above is the fastest way to try anything on this page — paste a snippet in, press Enter, see the result immediately. For real app code, a route or a Controller method works exactly like any other Laravel code:

```php
// routes/web.php — good enough for trying things out, no view/controller needed
use EasyAI\LaravelAI\Facades\AI;

Route::get('/test-ai', function () {
    $response = AI::chat([['role' => 'user', 'content' => 'What is Laravel?']]);
    return $response->content;
});
```

Visit `/test-ai` in your browser and that's it.

**2. The `use` line.** Every PHP snippet assumes `use EasyAI\LaravelAI\Facades\AI;` at the top of the file (shown once above, not repeated in every example below). Add it once per file — or once per class if it's inside a Controller — and every `AI::...` call in that file works.

**3. `$messages` is always this same shape**, wherever it's used without being redefined right there — a plain array of `['role' => ..., 'content' => ...]` pairs, `role` being `'user'`, `'assistant'`, or `'system'`:

```php
$messages = [
    ['role' => 'user', 'content' => 'What is Laravel?'],
];
```

A real back-and-forth conversation is just more entries in the same array, oldest first — each of the AI's own past replies goes in with `'role' => 'assistant'`.

Want the guided, step-by-step version of all this instead of a reference page? **[TUTORIAL.md](TUTORIAL.md)** builds one real feature end to end from a blank Laravel install, explaining the "why" at every step.

---

## 💬 Built-in Chat UI

> **New in v1.3.0** — A full ChatGPT-like chat app included. Zero setup required.

### What you get out of the box

| Feature | Description |
|---------|-------------|
| 💬 Chat UI | ChatGPT-like sidebar with session history |
| ⚡ Streaming | Real-time typing effect |
| 📝 Markdown | Full rendering with syntax-highlighted code |
| 📋 Copy buttons | Per message and per code block |
| 🔄 Provider switcher | Switch Ollama / OpenAI / Claude / DeepSeek live |
| 💾 DB persistence | History survives page refresh |
| 🏷️ Auto-title | First message becomes session title |
| 📁 Projects | RAG-powered knowledge bases (v1.4.0) |
| 📦 Offline assets | No CDN dependency |
| 🔒 Security suite | Rate limiting, access control, captcha, GDPR gate (v2.0.0) |
| 📎 Attachments | Vision + document uploads mid-chat (v2.0.0) |
| 🧩 Floating widget | `<x-laravelai::widget />` — embeddable anywhere (v2.0.0) |
| 📊 Analytics | Usage dashboard, zero external tracking (v2.0.0) |
| 📱 Responsive | Off-canvas sidebar drawer, full-screen modals, and touch-sized controls below 768px |
| 🧠 Live thinking | Collapsible "Thinking…" block for reasoning models (Ollama qwen3, Anthropic extended thinking, Gemini 2.5) instead of dead air |

### Customize the view

```bash
php artisan vendor:publish --tag=ai-chat-views
# → resources/views/vendor/laravelai/chat.blade.php
```

### Routes registered automatically

| Method | URL | Description |
|--------|-----|-------------|
| GET    | `/ai-chat` | Chat UI |
| GET    | `/ai-chat/analytics` | Usage dashboard |
| POST   | `/ai-chat/api/sessions` | Create session |
| DELETE | `/ai-chat/api/sessions/{id}` | Delete session |
| POST   | `/ai-chat/api/stream` | SSE streaming (POST — see note below) |
| POST   | `/ai-chat/api/provider` | Switch provider |
| GET    | `/ai-chat/api/captcha` | Fetch a math captcha challenge |
| POST   | `/ai-chat/api/messages/{id}/feedback` | 👍/👎 a message |
| POST   | `/ai-chat/api/attachments` | Upload a chat attachment (image/document) |
| GET    | `/ai-chat/api/attachments/{id}` | Stream an attachment back |
| DELETE | `/ai-chat/api/attachments/{id}` | Delete an attachment |
| GET    | `/ai-chat/api/projects` | List projects |
| POST   | `/ai-chat/api/projects` | Create project |
| DELETE | `/ai-chat/api/projects/{id}` | Delete project |
| GET    | `/ai-chat/api/projects/{id}/files` | List project files |
| POST   | `/ai-chat/api/projects/{id}/files` | Upload & ingest file |
| POST   | `/ai-chat/api/projects/{id}/files/{fid}/reprocess` | Re-chunk a file in place |
| DELETE | `/ai-chat/api/projects/{id}/files/{fid}` | Delete file |
| POST   | `/ai-chat/api/rag/test` | Inspect matched chunks for a query |

> `/ai-chat/api/stream` moved from GET to POST in v2.0.0 — a 4000-character message no longer risks the URL length limit, and the built-in view reads the response via `fetch()` + a manual SSE parser (not `EventSource`) so blocked/rate-limited JSON error responses are actually readable.

> **v2.1.1** — `/ai-chat/api/stream` now survives replies that run long (a reasoning model thinking + writing a full answer can easily take a few minutes). Before this fix, a slow reply could hit your server's `max_execution_time` and vanish *after* the user had already watched it finish streaming — nothing showed as an error, it just wasn't there on the next page load. See [Troubleshooting → A reply appears while streaming, then disappears after reload](#a-reply-appears-while-streaming-then-disappears-after-reload) for the real example and the fix.

---

## 🗂️ Projects & Knowledge Bases

> **New in v1.4.0** — Self-hosted Claude-like Projects. Create knowledge bases, upload documents, and get RAG-powered answers scoped per project.

### How it works

```
Create Project → Upload Files → Chat Inside Project → RAG answers from your docs
```

1. Click **＋** next to **Projects** in the sidebar
2. Upload `.txt`, `.md`, or `.pdf` files — auto-ingested into RAG on upload
3. Click the project to start a new RAG-powered chat session
4. Every message retrieves relevant context from **that project's documents only**
5. Normal chats outside projects are completely unaffected

### What you see in the UI

- 📁 **Projects section** in sidebar with file count badge
- 🧠 **RAG ON** badge in chat header when inside a project session
- 📎 **Manage Files** button — upload, view ingestion status, delete files
- 🟢 Status per file: `pending` → `ingested` → `failed`
- **Project context active** indicator in the input footer

### PDF support (optional)

```bash
composer require smalot/pdfparser
```

### RAG Scoping API

```php
$results = AI::rag()->source('project_5')->search('your query');
$answer  = AI::rag()->source('project_5')->ask('your question');
AI::rag()->flush('project_5');
```

---

## 🧠 RAG (Built-in)

No external vector database required — uses your existing SQL database.

### Setup

Free/self-hosted via Ollama (the default):

```bash
ollama pull nomic-embed-text
php artisan migrate
```

```env
AI_RAG_PROVIDER=ollama
AI_RAG_EMBED_MODEL=nomic-embed-text
```

Or point RAG's embedding step at OpenAI or Gemini instead — no Ollama dependency at all, useful if your app already only talks to a paid provider:

```env
AI_RAG_PROVIDER=openai
AI_RAG_EMBED_MODEL=text-embedding-3-small
```

```env
AI_RAG_PROVIDER=gemini
AI_RAG_EMBED_MODEL=gemini-embedding-001
```

`AI_RAG_PROVIDER` just selects which driver's `->embed()` RAG calls internally — same config key regardless of provider. Not every provider has a real embeddings API to switch to: Anthropic doesn't offer one at all (they point to a third-party, Voyage AI, entirely outside this package's scope), and among the OpenAI-compatible drivers, Together AI has a genuine one but Groq and DeepSeek don't — pick from Ollama, OpenAI, Gemini, or Together.

### Usage

```php
// Store
AI::rag()->ingest('Laravel is a PHP framework using MVC.', 'docs');

// Ask
$answer = AI::rag()->ask('What is Laravel?');

// Search
$results = AI::rag()->search('MVC pattern');
// [['content' => '...', 'source' => 'docs', 'score' => 0.91]]

// Scoped
$results = AI::rag()->source('project_5')->search('your query');

// Flush
AI::rag()->flush();
AI::rag()->flush('project_5');
```

### Artisan

```bash
php artisan ai:rag:ingest storage/docs/manual.txt --source=manual
php artisan ai:rag:ingest storage/docs/ --flush
```

### Accurate counting, test queries, reprocessing

> **New in v2.0.0**

```php
// Full-corpus keyword scan for "how many X" questions — top-K semantic
// search alone under-counts when relevant chunks rank outside K.
AI::rag()->countMatches('invoice', 'project_5');
```

```
POST /ai-chat/api/rag/test          { "query": "refund policy", "source": "project_5" }
POST /ai-chat/api/projects/5/files/12/reprocess   # re-chunk in place, no re-upload
```

### Auto-index your own models ("Ask This Site")

Keep RAG in sync with any Eloquent model on save/delete — opt-in, empty by default:

```php
// config/ai.php
'rag' => [
    'auto_index' => [
        'posts' => [
            'model'  => \App\Models\Post::class,
            'source' => 'site_posts',
            'text'   => fn ($post) => $post->title . "\n\n" . strip_tags($post->body),
            'when'   => fn ($post) => $post->is_published, // optional
        ],
    ],
],
```

Chat sessions with no project attached automatically pull context from every auto-indexed source once at least one entry is configured.

### RAG Configuration

| `.env` Key | Default | Description |
|------------|---------|-------------|
| `AI_RAG_PROVIDER` | `ollama` | Embedding provider |
| `AI_RAG_EMBED_MODEL` | `nomic-embed-text` | Embedding model |
| `AI_RAG_CHUNK_SIZE` | `2000` | Max chars per chunk |
| `AI_RAG_TOP_K` | `3` | Chunks retrieved per query |
| `AI_RAG_TABLE` | `ai_documents` | Database table |
| `AI_RAG_MAX_SCAN_ROWS` | `50000` | Safety cap on rows scanned per search — raise it if your knowledge base is genuinely bigger and you'd rather wait than get a capped answer |

### Outgrown the built-in scan? Bring your own vector store

The built-in RAG search is genuinely fine up to tens of thousands of chunks (an in-PHP cosine scan, memory-bounded — see `AI_RAG_MAX_SCAN_ROWS` above), but it's not a substitute for a real vector database at large scale. Bind your own `VectorStoreInterface` implementation and `RAGManager` delegates ingestion/search/flush to it entirely — every existing install (nothing bound) keeps the exact same built-in behavior.

```php
interface VectorStoreInterface
{
    public function upsert(string $content, string $source, array $embedding): void;
    public function search(array $queryEmbedding, ?string $source, int $topK): array;
    public function delete(?string $source): void;
    public function count(?string $source): int;
}
```

A working Postgres + [pgvector](https://github.com/pgvector/pgvector) implementation ships out of the box — no new Composer dependency, just raw SQL against the extension:

```php
$this->app->bind(
    \EasyAI\LaravelAI\RAG\Contracts\VectorStoreInterface::class,
    \EasyAI\LaravelAI\RAG\VectorStores\PgVectorStore::class
);
```

One-time setup (`CREATE EXTENSION vector;` + the table DDL) is documented directly in `PgVectorStore`'s class docblock. Want Pinecone, Weaviate, Milvus, or anything else instead? Implement the same four methods.

> One scope boundary worth knowing: the "Ask This Site" auto-indexer's search (`searchAutoIndexed()`) isn't delegated — its multi-source prefix match doesn't fit this contract's single-source `search()`, so it always uses the built-in table regardless of what's bound.

---

## 🔒 Security & Trust

> **New in v2.0.0** — everything below is config/env-driven (`config/ai.php` → `chat`); there's no settings database or admin panel, by design — your app already has its own way to manage config.

| Setting | `.env` key | Default |
|---|---|---|
| Rate limit (per identity) | `AI_CHAT_RATE_LIMIT_MAX` / `_WINDOW` | 20 / 60s |
| Rate limit (per IP, hard cap) | `AI_CHAT_RATE_LIMIT_IP_MAX` | 60 |
| Access restriction | `AI_CHAT_ACCESS_RESTRICTION` | `everyone` (also: `auth`, `role`, `gate`) |
| IP blocklist | `AI_CHAT_IP_BLOCKLIST` | — (comma-separated) |
| Word filter | `AI_CHAT_WORD_FILTER_ENABLED` / `_WORDS` | off |
| Prompt-injection detection | `AI_CHAT_PROMPT_INJECTION_DETECT` | off |
| No-storage mode | `AI_CHAT_DISABLE_STORAGE` | off |
| Math captcha (first message) | `AI_CHAT_CAPTCHA_ENABLED` | off |
| Abuse-alert email | `AI_CHAT_ABUSE_ALERT_ENABLED` / `_EMAIL` | off |
| GDPR consent gate | `AI_CHAT_GDPR_GATE_ENABLED` | off |
| Lock system prompt | `AI_CHAT_LOCK_SYSTEM_PROMPT` | off |
| Max message length | `AI_CHAT_MAX_MESSAGE_LENGTH` | 4000 |

"Role" restriction looks for `$user->hasAnyRole([...])` (configurable method name — works with Spatie permission out of the box) or a `roles` collection/array on the user model. "Gate" restriction checks a named Laravel `Gate` you define yourself (`AI_CHAT_ACCESS_GATE`, defaults to `use-ai-chat`).

Internal exception detail (e.g. "No API key configured for OpenAI") is only shown to callers who pass the same gate — everyone else gets a generic "temporarily unavailable" message. Set `AI_CHAT_SHOW_INTERNAL_ERRORS=true` to always show real errors (e.g. in a staging environment).

## ⚙️ Provider Settings UI & Auth Guard

Change providers/API keys from `/ai-chat/settings` instead of editing `.env` and redeploying. `.env` stays the source of truth for everything you never touch in the UI — this is a purely additive override layer, not a replacement.

**Fail-closed by default** — nobody can reach it until you grant access, and there's no "everyone" mode for editing API keys, regardless of how the main chat's own access restriction is configured. Grant the first admin from the CLI:

```bash
php artisan laravelai:make-admin your@email.com
```

Log in as that user and `/ai-chat/settings` just works — a "👤 Admin Access" panel right there lets that admin add or remove other admins by email, no code changes or redeploys needed for every admin after the first. `php artisan laravelai:install` also offers to do this for you as part of the guided setup.

**Why it might say "No user found"**: this command *grants* access to an existing account, it doesn't create one — and a fresh Laravel install has no users yet. Create one first, then re-run the command:

```bash
php artisan tinker
```
```php
\App\Models\User::create([
    'name'     => 'Your Name',
    'email'    => 'your@email.com',
    'password' => bcrypt('choose-a-real-password'),
]);
```

Or, if you'd rather register through a real UI, scaffold one first (e.g. `composer require laravel/breeze --dev && php artisan breeze:install`), sign up at `/register`, then run `laravelai:make-admin` against that email.

Already have your own roles/permissions system? Define the Gate yourself anywhere in your app (`AppServiceProvider::boot()` is the usual place) and it takes over completely — this package's own default only ever applies when nothing else has claimed the ability:

```php
Gate::define('manage-ai-settings', fn ($user) => $user->hasRole('admin'));
```

Secrets are masked in the UI (last 4 characters only) and never overwritten unless you actually type a new value; blanking a field deletes the override and falls back to `.env` again.

**Storage:** API keys are encrypted (Laravel's `Crypt`, your app's `APP_KEY`) before ever touching the database — never stored in plaintext. Every provider driver's `health()` (used by the page's "Test connection" button) is also audited to confirm none of them can leak a credential through an exception message.

**Auth-gating the chat itself** is a separate, independent knob — public by default:

```env
AI_CHAT_MIDDLEWARE=auth   # require login for the whole /ai-chat area; leave unset for a public page
```

### 📊 Usage & Cost Tracking

A "Usage & Costs" tab lives right next to "Providers" on the same Settings page — off by default, flip it on from `.env` **or** with the checkbox right there on the tab itself (saved through the same `SettingsOverlay` as everything else):

```env
AI_USAGE_LOGGING_ENABLED=true
```

Upgrading from an older version? This adds one new table — run `php artisan migrate` first. The tab itself still renders even before you have (with a short "run migrate" hint instead of blank stats), so nothing breaks if you flip the checkbox on ahead of it.

Once on, every `chat()`/`generateImage()` call this package's drivers make — from the bundled chat UI *and* from your own PHP code calling `AI::provider(...)` directly — appends a row to `ai_usage_logs`: provider, model, chat vs. image, token/image counts, and an estimated USD cost. The tab shows total spend (all-time and this month), a breakdown by provider, and the most recent calls.

Cost estimation reuses the same `config('ai.pricing')` rates as [`getEstimatedCost()`](#cost-estimation) — empty by default, so cost shows as "—" until you fill in a rate for the exact model you're using. Image generation adds a second rate shape under each provider's `image` key:

```php
'pricing' => [
    'openai' => [
        'image' => ['dall-e-3' => 0.04],              // flat USD per image
    ],
    'together' => [
        'image' => ['black-forest-labs/FLUX.1-schnell' => ['per_mp' => 0.0027]], // USD per megapixel
    ],
    'gemini' => [
        'image' => ['gemini-3.1-flash-image' => 0.04], // flat USD per image, same shape as OpenAI's
    ],
],
```

Same fail-safe posture as everything else on this page: no rate configured just means that row's cost shows as unconfigured, never a guessed number, and a logging problem (migration not yet run, DB briefly down) is swallowed rather than breaking the AI call it rode in on.

## 💬 Chat UX & Personalization

> **New in v2.0.0**

Stop & regenerate, message timestamps, per-message and whole-conversation export, read-aloud (browser `SpeechSynthesis`), voice input (browser `SpeechRecognition` — Chrome/Edge/Safari), fullscreen mode, 👍/👎 feedback, and client-side session search all ship in the default `resources/views/chat.blade.php` — no configuration needed, some gated by `AI_CHAT_VOICE_INPUT_ENABLED` / `AI_CHAT_TTS_ENABLED` / `AI_CHAT_EXPORT_ENABLED`.

Welcome message, suggested-question chips, a custom AI avatar, and accent/bubble colors are all `config('ai.chat.ui')` / env-driven:

```env
AI_CHAT_WELCOME_ENABLED=true
AI_CHAT_WELCOME_MESSAGE="Hello! How can I help you today?"
AI_CHAT_SUGGESTED_QUESTIONS="What can you help with?|Summarize a document|Write me an email"
AI_CHAT_AVATAR_URL=https://example.com/bot-avatar.png
AI_CHAT_COLOR_ACCENT=#6366f1
```

### Bot profiles

Named presets — provider, title, system prompt — loadable per session:

```php
// config/ai.php
'bot_profiles' => [
    'support' => [
        'label'         => 'Support Bot',
        'provider'      => 'openai',
        'system_prompt' => 'You are a friendly support agent for Acme Inc.',
    ],
],
```

```php
POST /ai-chat/api/sessions  { "profile": "support" }
```

### Floating widget (embeddable anywhere)

A self-contained launcher + chat panel — talks to the same session/stream API, ships its own scoped CSS and JS, and works on any Blade view without loading the full `/ai-chat` app:

```blade
<x-laravelai::widget position="bottom-right" label="Chat with us" profile="support" />
```

### Export as PDF, Word, Excel, or PowerPoint

The export button next to the chat header is a dropdown — plain text works with zero setup (client-side), the other four are optional server-side dependencies, install only the ones you want:

```bash
composer require dompdf/dompdf              # PDF
composer require phpoffice/phpword          # Word (.docx)
composer require phpoffice/phpspreadsheet   # Excel (.xlsx) — one row per message
composer require phpoffice/phppresentation  # PowerPoint (.pptx) — one slide per message
```

`php artisan laravelai:install` offers to run whichever of these you want right then, so you never have to come back and run them by hand later. Not installed yet, and skipped the prompt? The download attempt shows exactly which command to run instead of failing silently. PowerPoint is the odd one out of the four — slides don't paginate long text the way a document does, so it suits short conversations best; PDF or Word read better for a long transcript.

### Exporting a generated image — PNG, JPEG, or PDF

A reply that's *only* a generated picture (the `/image` command's output — see [Image generation](SETUP_GUIDE.md#8-image-generation)) gets its own export buttons instead of the generic "save as .txt": ⬇ PNG, ⬇ JPEG, ⬇ PDF, right on that message. Zero extra dependencies — PNG/JPEG are re-encoded client-side through a `<canvas>` (so the download always genuinely matches the extension, whatever format the server actually stored), and PDF opens the browser's own print dialog pre-sized to just the image — "Save as PDF" is a built-in destination there on effectively every modern browser, no PDF-writing library needed for one picture.

### 📤 Sharing a conversation — WhatsApp, Email, Facebook, or a link

> **New in v2.19.0** (WhatsApp/Email), **v2.20.0** (public link, Facebook)

Every assistant reply gets a **📤 Share** dropdown right next to Copy. Opening it (from any message — it's a convenient access point, not a per-message share) generates a public, read-only link to the *whole conversation* the first time you use it — same "get shareable link" idea as Google Docs, no separate explicit step:

- **WhatsApp** / **Email** — pre-filled with the link
- **Facebook** — opens Facebook's share dialog pointed at the link (this needed a real URL to exist first, which is why it wasn't possible in the WhatsApp/Email-only v2.19.0)
- **Copy link** — clipboard

The link (`/ai-chat/s/{token}`) renders a minimal, read-only transcript — no send box, no export/feedback controls, no login required to view it, and it's excluded from search-engine indexing (`noindex`). It stays live until you explicitly revoke it (`DELETE /ai-chat/api/sessions/{id}/share` — not yet in the UI as a button, the link simply doesn't expire on its own). Turn the whole Share feature off with:

```env
AI_CHAT_SHARE_ENABLED=false
```

Sharing a conversation makes its full content visible to anyone with the link — nothing is shared until you deliberately open the Share menu on it.

## 📎 Attachments & Vision

> **New in v2.0.0** — `AI_CHAT_ATTACHMENTS_ENABLED=true`, or say "yes" when `php artisan laravelai:install` asks — it sets the flag and runs `composer require smalot/pdfparser` for you.

Upload images and documents (`.txt`/`.md`/`.pdf`) mid-chat. Documents are text-extracted and appended as context for **every** provider; images become real vision input for OpenAI, Anthropic, and Gemini (via a universal multipart message format translated per-provider), with a "this provider can't view images" fallback note for everyone else. More than one image can ride along on a single message — a direct upload and a PDF's rendered pages (below) can even be mixed together, up to 6 total.

### See what's *inside* a PDF, not just its text — `pdf_vision_enabled`

Plain text extraction is blind to a chart, diagram, scanned table, or photo embedded in a PDF — there's no text layer there to extract. Turn this on and an uploaded PDF also gets each page rendered to an image (via the PHP `imagick` extension), which rides along as real vision input the next time that PDF is attached to a message — on top of the plain text every PDF already gets regardless of this setting:

```env
AI_CHAT_PDF_VISION_ENABLED=true
AI_CHAT_PDF_VISION_MAX_PAGES=5   # cap — a long PDF renders only its first N pages
```

```bash
# A real system-level dependency, not a composer package — install at the OS level:
apt-get install php-imagick ghostscript   # Debian/Ubuntu; Ghostscript is Imagick's PDF delegate
```

Off by default, and fails clearly rather than silently — if `imagick` isn't actually installed when this is turned on, the upload still succeeds (the PDF's extracted text is unaffected), a warning is logged, and there are simply no page images that time. No code changes needed to use it once it's on: ask the AI to "extract the values from this chart and generate new questions with different numbers" and a vision-capable provider (OpenAI, Anthropic, Gemini) can genuinely see the page, not just whatever text happened to be extractable from it.

## 📊 Analytics & Webhooks

Visit `/ai-chat/analytics` for a zero-external-tracking dashboard — total conversations/messages, messages today, active chats (7d), most-used provider, a 7-day bar chart, and feedback stats — computed entirely from your own `ai_chat_sessions`/`ai_chat_messages` tables.

```env
AI_CHAT_WEBHOOK_URL=https://your-endpoint.example.com/hook
AI_CHAT_WEBHOOK_SECRET=optional-hmac-secret
```

Fires a best-effort POST after every AI response with `session_id`, `user_message`, `ai_response`, `provider`, `timestamp` — and an `X-LaravelAI-Signature: sha256=...` header when a secret is set. Compatible with Zapier, Make, n8n, or any HTTP endpoint.

## 🛍️ Commerce Assistants

Three AI endpoints for a storefront — **Ask Your Store** (admin analytics), **product Q&A/finder**, and **order status** — built entirely on three PHP interfaces. This package creates **zero e-commerce tables** and assumes **no particular catalog/order shape**: it can't collide with WooCommerce, a custom Eloquent catalog, a remote API, or any other package, because it never queries your commerce data directly. You bind a small resolver class that does, in your own app.

Every endpoint is a clear `501 Not Implemented` until you bind its resolver — nothing "half-works" against a guessed schema.

### How it works, in three steps

**1. Implement one interface** for whichever assistant you want (each is independent — use one, two, or all three):

```php
// app/Services/MyProductResolver.php
use EasyAI\LaravelAI\Commerce\Contracts\ProductResolver;

class MyProductResolver implements ProductResolver
{
    public function search(array $criteria): array
    {
        // $criteria: ['keyword' => 'red dress', 'min_price' => 20, 'max_price' => 50, 'limit' => 4]
        return Product::query()
            ->when($criteria['keyword'] ?? null, fn ($q, $kw) => $q->where('name', 'like', "%{$kw}%"))
            ->when($criteria['max_price'] ?? null, fn ($q, $p) => $q->where('price', '<=', $p))
            ->limit($criteria['limit'] ?? 4)
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'currency' => 'USD'])
            ->all();
    }

    public function find(int|string $productId): ?array
    {
        $p = Product::find($productId);
        return $p ? ['id' => $p->id, 'name' => $p->name, 'price' => $p->price] : null;
    }
}
```

**2. Bind it** in your `AppServiceProvider`:

```php
public function register(): void
{
    $this->app->bind(
        \EasyAI\LaravelAI\Commerce\Contracts\ProductResolver::class,
        \App\Services\MyProductResolver::class
    );
}
```

**3. Call the endpoint** from your storefront's chat widget or your own frontend:

```bash
curl -X POST /ai-chat/api/commerce/products/ask \
  -H "Content-Type: application/json" \
  -d '{"question": "red dress under $50"}'
# → {"reply": "Here are a few options!", "products": [{"id": 1, "name": "Red Summer Dress", "price": 42.0}]}
```

### The three assistants

| Assistant | Endpoint | Contract to implement | Notes |
|---|---|---|---|
| 🛒 Product Q&A / finder | `POST /ai-chat/api/commerce/products/ask` | `ProductResolver` | Public by default — it's just product search |
| 📦 Order status | `POST /ai-chat/api/commerce/orders/ask` | `OrderResolver` | Guest path requires an order number **and** matching email — the resolver, not the package, verifies real ownership |
| 📊 Ask Your Store | `POST /ai-chat/api/commerce/store-assistant` | `StoreAnalyticsResolver` | **Fail-closed** — refused for everyone until you `Gate::define('view-store-assistant', ...)`, same pattern as the Settings UI |

**Order status example** — the guest verification path:

```bash
curl -X POST /ai-chat/api/commerce/orders/ask \
  -d '{"question": "Where is my order?", "order_number": "1001", "email": "jane@example.com"}'
# → {"reply": "Order #1001 has shipped! It contains 1x Red Dress."}
# A wrong email never reveals whether the order even exists:
# → {"reply": "I couldn't find an order matching those details."}
```

**Ask Your Store example** — once you've defined the Gate:

```php
// app/Providers/AppServiceProvider.php
Gate::define('view-store-assistant', fn ($user) => $user->isAdmin());
```

```bash
curl -X POST /ai-chat/api/commerce/store-assistant \
  -d '{"question": "How much revenue this month, and what is running low on stock?"}'
# → {"answer": "Revenue this period was $12,340 across 210 orders. 3 items are low on stock: ..."}
```

```env
# .env — optional overrides
AI_COMMERCE_PROVIDER=       # blank = use AI_PROVIDER
AI_COMMERCE_GATE=view-store-assistant
AI_COMMERCE_PRODUCT_LIMIT=4
```

## 🤖 Providers

### Ollama — Self-Hosted & Free

```env
AI_PROVIDER=ollama
AI_OLLAMA_URL=http://127.0.0.1:11434
AI_OLLAMA_MODEL=qwen2:1.5b
AI_OLLAMA_TIMEOUT=120
```

> **Note for small models (qwen2, qwen2.5):** If you get 400 errors with RAG context, set `num_ctx` to match your model's context window:
> ```bash
> ollama show qwen2:1.5b --modelfile > /tmp/modelfile
> echo "PARAMETER num_ctx 2048" >> /tmp/modelfile
> ollama create qwen2-fixed -f /tmp/modelfile
> ```
> Then use `AI_OLLAMA_MODEL=qwen2-fixed` in `.env`.

**Reasoning models (qwen3 and similar) "think" before answering** — often adding 10–30+ seconds of latency for a simple question, with *nothing* visible while it happens. The built-in chat UI shows this live as a collapsible "🧠 Thinking… Ns" block instead of dead air, auto-collapsing to "Thought for Ns" once the real answer starts — same idea as Claude.ai's/ChatGPT's extended-thinking UI. Supported on Ollama, Anthropic, and Gemini (see their sections below); OpenAI's o-series reasoning models don't expose visible reasoning through the Chat Completions API this package uses, so there's nothing to show for OpenAI. If you'd rather skip the wait entirely on Ollama:

```env
AI_OLLAMA_THINK=false
```

```php
// or per-call
AI::provider('ollama')->think(false)->chat($messages);
```

### OpenAI (ChatGPT)

```env
AI_OPENAI_KEY=sk-your-api-key
AI_OPENAI_MODEL=gpt-4o-mini
```

**Image generation** (DALL·E / GPT image models):

```php
$url = AI::provider('openai')->generateImage('a red fox in snow');
```

Defaults to `dall-e-3`, returning a hosted URL (valid 60 minutes) — same `generateImage(string): string` contract as Together's FLUX support below, so either drops straight into `![prompt]($url)` markdown or an `<img src>`. Set `AI_OPENAI_IMAGE_MODEL=gpt-image-1` (or `gpt-image-1-mini`/`gpt-image-1.5`) for OpenAI's newer models — they return only base64, no URL option at all, so this returns a `data:image/png;base64,...` string instead: still one usable string, just meaningfully larger if you store the resulting chat message.

> **New in v2.16.0** — `AI_OPENAI_IMAGE_ENABLED=true` also wires this into the chat UI's `/image`/`/img` command, same as Together's flag below. The command isn't hardcoded to one provider: it uses whichever image-capable provider (`openai`, `together`) is both currently the active chat provider *and* has its own `image_enabled` on; if the active provider can't generate images (or doesn't have it enabled), it falls back to whichever of the other one does — so a Together-only setup keeps working exactly as before, and turning both on lets `/image` "just follow" whichever provider you're actually talking to.

```env
AI_OPENAI_IMAGE_ENABLED=true
```

**Speech-to-text and text-to-speech:**

```php
$text  = AI::provider('openai')->transcribe(storage_path('app/recording.mp3'));
$audio = AI::provider('openai')->textToSpeech('Hello there!'); // raw mp3 bytes

Storage::disk('local')->put('reply.mp3', $audio);
```

`transcribe()` defaults to `whisper-1`; `textToSpeech()` defaults to voice `alloy` on model `tts-1` — override either per-call (`->textToSpeech($text, ['voice' => 'nova', 'model' => 'gpt-4o-mini-tts'])`) or via `AI_OPENAI_TRANSCRIBE_MODEL`/`AI_OPENAI_TTS_MODEL`/`AI_OPENAI_TTS_VOICE`. Both are inherited by Groq (confirmed genuinely OpenAI-compatible — Groq's own docs show the identical endpoint shape, with `whisper-large-v3-turbo` as a fast transcription model) and, for `textToSpeech()` only, Together (Together has no transcription endpoint of its own). DeepSeek exposes no audio API at all.

### Anthropic (Claude)

```env
AI_ANTHROPIC_KEY=sk-ant-your-api-key
AI_ANTHROPIC_MODEL=claude-sonnet-4-20250514
```

Extended thinking is off by default (it costs extra tokens and time). Turn it on and the chat UI shows the same live "🧠 Thinking…" block as Ollama:

```env
AI_ANTHROPIC_THINK=true
AI_ANTHROPIC_THINK_BUDGET=10000
```

### DeepSeek

```env
AI_DEEPSEEK_KEY=sk-your-api-key
AI_DEEPSEEK_MODEL=deepseek-chat
```

### Groq

Famously fast inference, OpenAI-compatible API — same driver family as DeepSeek/Together, gets tool-calling and everything else for free.

```env
AI_GROQ_KEY=gsk_your-api-key
AI_GROQ_MODEL=llama-3.3-70b-versatile
```

### Google Gemini

```env
AI_GEMINI_KEY=your-api-key
AI_GEMINI_MODEL=gemini-2.0-flash
```

2.5-series models support the same live thinking display:

```env
AI_GEMINI_THINK=true
```

**Image generation** (the "Nano Banana" model family):

```php
$result = AI::provider('gemini')->generateImage('a red fox in snow');
```

Always returns a `data:image/png;base64,...` string — Gemini's image models have no hosted-URL response mode the way OpenAI's dall-e-3 or Together's FLUX do, so every result is base64 regardless of which model you pick. Defaults to `gemini-3.1-flash-image`; override with `AI_GEMINI_IMAGE_MODEL` (`gemini-3.1-flash-lite-image` for cheaper/faster, `gemini-3-pro-image` for the higher-quality tier, or `gemini-2.5-flash-image` for the original "Nano Banana").

```env
AI_GEMINI_IMAGE_ENABLED=true
```

Wires the same way into the chat UI's `/image`/`/img` command as OpenAI's and Together's flags — see [Image generation](SETUP_GUIDE.md#8-image-generation) for the full active-provider preference rule.

### Together AI

```env
AI_TOGETHER_KEY=your-api-key
AI_TOGETHER_MODEL=meta-llama/Llama-3.3-70B-Instruct-Turbo

# Optional: FLUX image generation via "/image a red fox in snow" in chat
AI_TOGETHER_IMAGE_ENABLED=true
```

### Custom (any OpenAI-compatible endpoint)

LM Studio, vLLM, OpenRouter, an in-house gateway — add as many named entries as you like in `config/ai.php`:

```php
'custom_providers' => [
    'lmstudio' => [
        'label'   => 'LM Studio (local)',
        'url'     => 'http://127.0.0.1:1234/v1',
        'api_key' => null,
        'model'   => 'local-model',
        'timeout' => 60,
    ],
],
```

```php
AI::custom('lmstudio')->chat($messages);
// or, in the chat UI provider selector: "custom:lmstudio"
```

---

## 🧰 Agent Module — Tool / Function Calling

Give any provider the ability to call your own PHP functions mid-conversation — look something up, run a calculation, hit an API — and keep going until it has a real answer. Works identically across **OpenAI, Anthropic, Gemini, and Ollama** (plus DeepSeek/Together/any custom OpenAI-compatible endpoint, which inherit it from the OpenAI driver): one `Tool` shape, translated into each provider's own wire format under the hood.

### A tool in three lines

```php
use EasyAI\LaravelAI\Agent\Tool;

$weather = Tool::make(
    name: 'get_weather',
    description: 'Get the current weather for a city.',
    parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    handler: fn (array $args) => ['temp_c' => 22, 'condition' => 'Sunny'], // call a real API here
);
```

### Run it

```php
$response = AI::provider('openai')->tools([$weather])->run([
    ['role' => 'user', 'content' => 'What is the weather in Paris?'],
]);

echo $response->content;
// "It's currently 22°C and sunny in Paris."
```

`run()` (not `chat()`) drives the whole loop: sends your message, and — as long as the model keeps asking to call a tool — executes the matching handler and feeds the result back, up to 5 round-trips by default (`AI::provider('openai')->tools([$weather])->run($messages, maxSteps: 10)` to raise it). A handler that throws doesn't crash the run; the model just sees `{"error": "..."}` and can try something else or explain the failure.

### Multiple tools, and inspecting what happened

A second tool, same three-line shape as `$weather` above:

```php
$calculator = Tool::make(
    name: 'calculate',
    description: 'Evaluate a basic arithmetic expression, e.g. "12 * (4 + 3)".',
    parameters: ['type' => 'object', 'properties' => ['expression' => ['type' => 'string']], 'required' => ['expression']],
    handler: fn (array $args) => ['result' => eval("return {$args['expression']};")], // demo only — see the note below
);
```

Give the model both, and it picks whichever (if any) a given message actually needs:

```php
$response = AI::provider('anthropic')->tools([$weather, $calculator])->run([
    ['role' => 'user', 'content' => 'What is 12 times 7?'],
]);

echo $response->content;                 // the final answer
$response->hasToolCalls();               // false on the final turn (that's how run() knows to stop)
```

> ⚠️ `eval()` above is only to keep this example to one line — never `eval()` a string built from model or user input in real code. A real calculator tool should parse the expression with a proper math-expression library instead.

### 🔍 Built-in web search tool

The one tool most agents actually need first — give the model real, current information instead of only what was in its training data.

```php
use EasyAI\LaravelAI\Agent\Tools\WebSearchTool;

$response = AI::provider('openai')
    ->tools([WebSearchTool::make()])
    ->run([['role' => 'user', 'content' => 'What happened in the news today about Laravel?']]);
```

Backed by a pluggable `WebSearchProvider` contract — two work out of the box, both with genuinely free tiers, pick whichever you already have a key for:

```env
AI_WEB_SEARCH_PROVIDER=tavily   # or "brave"
AI_TAVILY_API_KEY=tvly-...      # https://tavily.com — 1,000 free searches/month
# AI_BRAVE_API_KEY=...          # https://brave.com/search/api — also has a free tier
```

No key configured and nothing bound? The tool degrades to a "no results / not configured" message rather than failing the whole run — an agent missing one capability should say so, not crash. Want your own backend entirely (a self-hosted search index, a different vendor)? Bind it like any other resolver in this package:

```php
$this->app->bind(
    \EasyAI\LaravelAI\Agent\Contracts\WebSearchProvider::class,
    \App\Services\MySearchProvider::class // implements search(string $query, int $limit): array
);
```

### What each provider actually supports

| Provider | Tool calling | Notes |
|---|---|---|
| OpenAI | ✅ | Also covers DeepSeek, Together AI, Groq, and any custom OpenAI-compatible endpoint |
| Anthropic (Claude) | ✅ | Supports parallel tool calls (multiple in one turn) |
| Google Gemini | ✅ | |
| Ollama | ✅ | Model-dependent — needs a tool-calling-capable model (e.g. `qwen3`, `llama3.1`); older/smaller models may ignore the tools list entirely |

**Streaming the loop:** pass a 4th argument and every step streams instead of one blocking `chat()` call per round-trip — the final answer (and any text a turn produces before it decides to call a tool) reaches you token-by-token, same as `stream()`:

```php
$response = AI::provider('openai')->tools([$weather])->run(
    $messages,
    maxSteps: 5,
    onToolCall: null,                         // still available — see below
    onChunk: fn (string $chunk) => print($chunk),
);
```

Detecting a tool call is unaffected either way — each driver's stream handler reassembles `hasToolCalls()`/`getToolCalls()` from that provider's own real incremental format (OpenAI's indexed `delta.tool_calls` argument fragments, Anthropic's `input_json_delta` blocks keyed by content-block index, Gemini's whole `functionCall` parts, Ollama's whole `message.tool_calls`), so the loop keeps executing tools exactly the same regardless of which path produced the response. Omit `onChunk` (or pass `null`) for the exact non-streaming behavior `run()` always had.

**Observing every step, not just the final answer — `$onStep`:** `run()`'s return value only ever carries the *last* step's `AIResponse` — once the loop converges to a plain-text answer, everything an earlier step produced (including the usage/cost of the step that decided to call a tool in the first place) is otherwise lost. A 5th argument, `onStep`, fires once per real LLM call the loop makes — every intermediate tool-calling step and the final step alike — each time handing you that step's actual `AIResponse`:

```php
$totalPromptTokens = $totalCompletionTokens = 0;

$response = AI::provider('openai')->tools([$weather])->run(
    $messages,
    maxSteps: 5,
    onToolCall: null,
    onChunk: null,
    onStep: function ($stepResponse) use (&$totalPromptTokens, &$totalCompletionTokens) {
        $totalPromptTokens += $stepResponse->getPromptTokens();
        $totalCompletionTokens += $stepResponse->getCompletionTokens();
    },
);
```

It fires immediately after that step's response is received — streamed or not, `onStep` always receives a fully-formed `AIResponse` either way, since `stream()`'s handler already assembles one before control returns to the loop — and *before* any tool call that response contains actually executes, so your accounting is never dependent on a tool succeeding, failing, or how long it takes to run. An ordinary turn with no tool calls at all still fires it exactly once, with the very same response `run()` itself returns. Purely additive and fully backward compatible: every existing 1-4 argument call site is unaffected, and `onStep: null` (the default) is the exact behavior `run()` always had. The use case this unlocks: accumulating real per-step token usage and `getEstimatedCost()` across a multi-step tool-calling turn — something `run()`'s own return value alone could never give you, since it only ever reflects the final call.

### Using tools in the built-in chat UI

The agent module works from your own code regardless of any of this, but `/ai-chat` (the built-in chat window) can use it too — opt-in, currently wired up for the built-in web search tool:

```env
AI_CHAT_TOOLS_ENABLED=true
AI_CHAT_ENABLED_TOOLS=web_search
```

The chat window shows a collapsible "🔧 Used N tools" line (same visual pattern as the reasoning-model "Thinking…" indicator) when a reply used one, and — same as any other reply — the final answer types out token-by-token rather than arriving all at once.

> **New in v2.18.0** — all four of these (the on/off toggle, provider choice, and both keys) are also editable from `/ai-chat/settings`' **🔎 Web Search** tab, no `.env` edit or redeploy needed. Same override mechanism as every other Settings-page field — takes effect immediately, falls back to `.env` again if you clear a field.

---

## ✨ Features

### Fluent Builder API

```php
$response = AI::provider('ollama')
    ->model('qwen2:1.5b')
    ->temperature(0.9)
    ->maxTokens(500)
    ->systemPrompt('You are a helpful Laravel expert.')
    ->chat([['role' => 'user', 'content' => 'Explain middleware']]);
```

### Streaming

```php
AI::provider('ollama')->stream(
    [['role' => 'user', 'content' => 'Write a poem']],
    function (string $chunk) { echo $chunk; }
);
```

### Health Check + Fallback

```php
use Illuminate\Support\Facades\Log;

foreach (['ollama', 'deepseek', 'openai'] as $provider) {
    try {
        if (!AI::provider($provider)->health()) continue;
        return AI::provider($provider)->chat($messages)->content;
    } catch (\Throwable $e) {
        Log::warning("{$provider} failed: {$e->getMessage()}");
    }
}
```

### Response Caching

Opt-in — identical requests (same provider, model, messages, temperature, max tokens, and system prompt) hit your app's cache instead of the AI API. Never applies to `stream()` or a `tools()`-bearing call (caching either would defeat the point of one or skip a tool's real side effects).

```env
AI_CACHE_ENABLED=true
AI_CACHE_TTL=3600          # seconds
AI_CACHE_STORE=            # blank = your app's default cache store
```

### Automatic Retry with Backoff

Opt-in, off by default — a connection failure or a `429`/`5xx` response automatically retries before this package's own exception is raised. A `400`/`401`/`404`/etc. is never retried (it'll never succeed a second time, so retrying just adds latency). Never applies to `stream()`, regardless of this setting — a stream that's already sent partial output to the caller can't be safely retried without duplicating it.

```env
AI_RETRY_TIMES=2          # total attempts, not "retries on top of the first" — 2 means try once more after a failure
AI_RETRY_SLEEP=1000       # milliseconds between attempts
```

```php
// or per-call, same "total attempts" meaning
AI::provider('openai')->retries(3, 500)->chat($messages);
```

### Token Estimation

```php
$tokens = AI::estimateTokens('Hello world');
$tokens = AI::estimateTokens($messagesArray);
```

### Cost Estimation

`getEstimatedCost()` is `null` by default — deliberately. AI pricing changes often enough, and varies enough per model, that shipping a baked-in price table here would eventually misreport real spend without any way to know it had gone stale. You supply the rate yourself (USD per 1,000 tokens, from your provider's own current pricing page):

```php
// config/ai.php
'pricing' => [
    'openai' => [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
    ],
],
```

```php
$response = AI::provider('openai')->model('gpt-4o-mini')->chat($messages);
$response->getEstimatedCost(); // 0.0007 (float, USD) — or null if that exact provider/model pair has no configured rate
```

Want that cost persisted and totaled up somewhere, instead of read off one response at a time? See [Usage & Cost Tracking](#-usage--cost-tracking) — same `pricing` config, plus a dashboard.

### Structured Output

`->format()` gets the model to return actual data instead of prose — every provider, one call site. `'json'` asks for "valid JSON, no shape enforced"; a JSON Schema array additionally constrains the exact fields.

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

Works the same way on **OpenAI, Gemini, Ollama** (plus DeepSeek/Groq/Together/Custom, inherited from the OpenAI driver), and on **Anthropic** too — which has no native JSON mode, so this package builds it there as a forced tool call under the hood, transparently. `getStructuredData()` is `null` for a normal response (never invented from content that merely *looks* like JSON), and `getContent()` still has the raw text either way if a response fails to decode. One real caveat, honestly documented rather than silently broken: on Anthropic specifically, `->format()` isn't supported together with `->stream()` — it throws rather than returning empty content, since the forced-tool-call mechanism that provider needs isn't decodable from a token stream.

### Embeddings

```php
$vectors = AI::provider('openai')->model('text-embedding-3-small')->embed('Hello world');
$vectors[0]; // [0.0123, -0.0456, ...] — always an array of vectors, one per input

$vectors = AI::provider('openai')->embed(['first text', 'second text']); // batch
```

Real native support on **Ollama, OpenAI, Gemini, and Together AI** (Together inherits it from the OpenAI driver, same as everything else it shares — DeepSeek and Groq don't expose an embeddings endpoint at all, so calling `->embed()` on either surfaces that provider's own error rather than this package faking a result). This is also exactly what powers [RAG](#-rag-built-in)'s `AI_RAG_PROVIDER` setting — same method, same drivers.

### Ollama Advanced Features

```php
AI::provider('ollama')->keepAlive('10m')->chat($messages);
AI::provider('ollama')->options(['num_ctx' => 2048])->chat($messages);
AI::provider('ollama')->pullModel('llama3.1:8b');
AI::provider('ollama')->runningModels();
AI::provider('ollama')->deleteModel('old-model');
```

### Error Handling

```php
use EasyAI\LaravelAI\Exceptions\ConnectionException;
use EasyAI\LaravelAI\Exceptions\ProviderException;
use Illuminate\Support\Facades\Log;

try {
    $response = AI::provider('openai')->chat($messages);
} catch (ConnectionException $e) {
    Log::error("Connection failed: " . $e->getMessage());
} catch (ProviderException $e) {
    Log::error("Provider [{$e->getProvider()}]: " . $e->getMessage());
}
```

---

## 📖 API Reference

### Facade Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `AI::chat(array $messages)` | `AIResponse` | Chat with default provider |
| `AI::provider(string $name)` | `AIProvider` | Switch provider — accepts `"custom:slug"` too |
| `AI::custom(string $key)` | `CustomDriver` | Resolve a named custom OpenAI-compatible provider |
| `AI::estimateTokens(string\|array)` | `int` | Estimate token count |
| `AI::rag()` | `RAGManager` | Access RAG system |

### Provider Methods (Chainable)

| Method | Description |
|--------|-------------|
| `->model($name)` | Set the model |
| `->temperature($float)` | Creativity (0–2) |
| `->maxTokens($int)` | Max response tokens |
| `->systemPrompt($text)` | Set instructions |
| `->timeout($seconds)` | Request timeout |
| `->chat(array $messages)` | Send and get response |
| `->stream(array $messages, callable)` | Stream token by token |
| `->health()` | Check provider reachable |
| `->models()` | List available models |

### RAG Methods

| Method | Description |
|--------|-------------|
| `->ingest($text, $source)` | Store as embeddings |
| `->search($query)` | Similarity search |
| `->ask($question)` | RAG-powered Q&A |
| `->source($name)` | Scope to one source |
| `->flush($source?)` | Delete documents |
| `->countMatches($term, $source?)` | Full-corpus keyword count for "how many X" questions |
| `->searchAutoIndexed($query)` | Search everything AutoIndexer has indexed |

### Ollama-Only Methods

| Method | Description |
|--------|-------------|
| `->format('json')` | Force JSON output |
| `->embed($text)` | Generate embedding |
| `->keepAlive($duration)` | Keep in memory |
| `->options($array)` | Raw Ollama options (e.g. `num_ctx`) |
| `->think($bool)` | Toggle reasoning mode on models that support it (qwen3, etc.) |
| `->pullModel($name)` | Download model |
| `->showModel($name)` | Model details |
| `->deleteModel($name)` | Remove model |
| `->copyModel($src, $dst)` | Copy model |
| `->runningModels()` | List loaded models |

### AIResponse Object

| Property | Type | Description |
|----------|------|-------------|
| `$response->content` | `string` | AI reply text |
| `$response->model` | `string` | Model used |
| `$response->promptTokens` | `int` | Input tokens |
| `$response->replyTokens` | `int` | Output tokens |
| `$response->totalTokens` | `int` | Total tokens |
| `$response->provider` | `string` | Provider name |
| `$response->getRaw()` | `array` | Raw API response |
| `(string) $response` | `string` | Cast to string |

### Helper Function

```php
ai('Your question')
ai('Your question', 'openai')
ai('Your question', 'anthropic', 'claude-haiku-...')
```

---

## ⚙️ Configuration

```php
// config/ai.php
return [
    'default' => env('AI_PROVIDER', 'ollama'),
    'providers' => [
        'ollama'    => ['driver' => 'ollama',    'url'     => env('AI_OLLAMA_URL'),    'model' => env('AI_OLLAMA_MODEL', 'qwen2:1.5b'),      'timeout' => env('AI_OLLAMA_TIMEOUT', 120)],
        'openai'    => ['driver' => 'openai',    'api_key' => env('AI_OPENAI_KEY'),    'model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),      'timeout' => 60],
        'anthropic' => ['driver' => 'anthropic', 'api_key' => env('AI_ANTHROPIC_KEY'), 'model' => env('AI_ANTHROPIC_MODEL'),                  'timeout' => 60],
        'deepseek'  => ['driver' => 'deepseek',  'api_key' => env('AI_DEEPSEEK_KEY'),  'model' => env('AI_DEEPSEEK_MODEL', 'deepseek-chat'),  'timeout' => 60],
    ],
    'rag' => [
        'embed_provider' => env('AI_RAG_PROVIDER', 'ollama'),
        'embed_model'    => env('AI_RAG_EMBED_MODEL', 'nomic-embed-text'),
        'chat_provider'  => env('AI_RAG_CHAT_PROVIDER', null),
        'chunk_size'     => (int) env('AI_RAG_CHUNK_SIZE', 2000),
        'top_k'          => (int) env('AI_RAG_TOP_K', 3),
        'table'          => env('AI_RAG_TABLE', 'ai_documents'),
        'system_prompt'  => env('AI_RAG_SYSTEM_PROMPT', 'Answer using ONLY the context below. If unsure, say so.'),
    ],
];
```

### Complete `.env` Reference

```env
# Provider
AI_PROVIDER=ollama

# Ollama (self-hosted, free)
AI_OLLAMA_URL=http://127.0.0.1:11434
AI_OLLAMA_MODEL=qwen2:1.5b
AI_OLLAMA_TIMEOUT=120
# AI_OLLAMA_THINK=false   # skip reasoning-model "thinking" latency (qwen3, etc.)

# OpenAI
AI_OPENAI_KEY=sk-proj-xxxx
AI_OPENAI_MODEL=gpt-4o-mini

# Anthropic (Claude)
AI_ANTHROPIC_KEY=sk-ant-xxxx
AI_ANTHROPIC_MODEL=claude-sonnet-4-20250514

# DeepSeek
AI_DEEPSEEK_KEY=sk-xxxx
AI_DEEPSEEK_MODEL=deepseek-chat

# Groq
AI_GROQ_KEY=gsk_xxxx
AI_GROQ_MODEL=llama-3.3-70b-versatile

# Gemini
AI_GEMINI_KEY=your-api-key
AI_GEMINI_MODEL=gemini-2.0-flash

# Together AI
AI_TOGETHER_KEY=your-api-key
AI_TOGETHER_MODEL=meta-llama/Llama-3.3-70B-Instruct-Turbo
AI_TOGETHER_IMAGE_ENABLED=false

# RAG
AI_RAG_PROVIDER=ollama
AI_RAG_EMBED_MODEL=nomic-embed-text
AI_RAG_CHUNK_SIZE=500
AI_RAG_TOP_K=1
AI_RAG_TABLE=ai_documents

# RAG for small models — reduce chunk size and limit context
# AI_OLLAMA_NUM_CTX=2048

# Security & Trust (v2.0.0) — see the Security & Trust section above for the full list
AI_CHAT_RATE_LIMIT_ENABLED=true
AI_CHAT_RATE_LIMIT_MAX=20
AI_CHAT_RATE_LIMIT_WINDOW=60
AI_CHAT_ACCESS_RESTRICTION=everyone
AI_CHAT_DISABLE_STORAGE=false
AI_CHAT_CAPTCHA_ENABLED=false
AI_CHAT_GDPR_GATE_ENABLED=false
AI_CHAT_MAX_MESSAGE_LENGTH=4000

# Chat UX & personalization (v2.0.0)
AI_CHAT_WELCOME_ENABLED=false
AI_CHAT_VOICE_INPUT_ENABLED=true
AI_CHAT_TTS_ENABLED=true
AI_CHAT_EXPORT_ENABLED=true
AI_CHAT_SHARE_ENABLED=true
AI_CHAT_ATTACHMENTS_ENABLED=false

# Webhook (v2.0.0)
AI_CHAT_WEBHOOK_URL=
AI_CHAT_WEBHOOK_SECRET=

# Commerce Assistants (v2.4.0)
AI_COMMERCE_PROVIDER=
AI_COMMERCE_GATE=view-store-assistant
AI_COMMERCE_PRODUCT_LIMIT=4

# Agent module — tool/function calling + built-in web search (v2.5.0)
AI_AGENT_MAX_STEPS=5
AI_WEB_SEARCH_PROVIDER=tavily
AI_WEB_SEARCH_LIMIT=5
AI_TAVILY_API_KEY=
AI_BRAVE_API_KEY=

# Scalability (v2.6.0) — queueing is opt-in, off by default
AI_RAG_QUEUE_INGESTION=false
AI_CHAT_WEBHOOK_QUEUE=false
AI_CHAT_MAX_LOADED_MESSAGES=500

# Response caching (v2.7.0) — opt-in, off by default
AI_CACHE_ENABLED=false
AI_CACHE_TTL=3600
AI_CACHE_STORE=

# Tool-calling in the built-in chat UI (v2.7.0) — opt-in, off by default
AI_CHAT_TOOLS_ENABLED=false
AI_CHAT_ENABLED_TOOLS=web_search
```

---

## 🧪 Testing

```bash
vendor/bin/phpunit
vendor/bin/phpunit --filter=test_ollama_chat
```

Uses `Http::fake()` — no real API calls needed. CI runs the full suite against SQLite, MySQL, and Postgres (real GitHub Actions service containers for the latter two) — driver-specific SQL, like the raw `ALTER` in one of the migrations, only gets genuinely exercised on the database engines it's actually written for.

---

## 🩹 Troubleshooting

Real problems, found and fixed on real installs — kept here with the actual example so the fix makes sense.

### A reply appears while streaming, then disappears after reload

**Symptom:** you ask a question, watch the AI's answer type itself out fully in the chat window — then reload the page (or come back later) and the reply is gone. Only your message is still there.

**Real example this was found from** — a chat asking a local Ollama reasoning model (`qwen3:8b`) to explain "how to setup laravel 12 on windows xampp". The full answer streamed to the screen normally. The server's log told the real story:

```
[2026-08-13 23:42:01] local.ERROR: Maximum execution time of 300 seconds exceeded
```

...timestamped exactly 300 seconds after the question was sent. A reasoning model's reply (thinking + a long, detailed answer) can genuinely take that long, and PHP's `max_execution_time` (300s is XAMPP's typical default) doesn't care that the browser is happily still receiving data — once the clock runs out, PHP kills the script with an error that **cannot be caught in code**, wherever it happens to be running. That's always somewhere inside the AI request, never in the step right after it that saves the reply to your database. The user sees a complete answer; the database never gets it.

**Fixed in v2.1.1** — `/ai-chat/api/stream` now lifts PHP's execution-time limit for itself (`set_time_limit(0)`) since it's a long-lived streaming response by design, not a normal page request, plus a backup save that still catches the reply even if the process dies for some *unrelated* reason. Nothing to configure — this is automatic as of v2.1.1.

**One thing still outside the package's control:** if you're behind Nginx or Apache as a reverse proxy (not just XAMPP/`php artisan serve` directly), *the proxy itself* can also time out a slow request before PHP does, since removing PHP's own limit doesn't touch the web server's. If replies still cut off after upgrading, raise the proxy's read timeout too:

```nginx
# nginx — inside your site's location block
fastcgi_read_timeout 600s;
proxy_read_timeout   600s;
```

```apache
# Apache — httpd.conf or a vhost config
ProxyTimeout 600
```

### `/ai-chat` routes return HTML instead of JSON, or the chat window never loads

Usually means a catch-all route in *your own* `routes/web.php` is registered before the package's routes and swallowing everything, `/ai-chat/*` included:

```php
// ❌ This shadows every package route, including its JSON APIs
Route::get('/{any}', fn () => view('app'))->where('any', '.*');

// ✅ Use fallback() instead — it only matches when nothing else did
Route::fallback(fn () => view('app'));
```

This is common in SPA setups (Vue/React front ends living inside a Laravel app) where a wildcard route serves the SPA shell for client-side routing.

### The chat sidebar says "Guest session" even though I'm logged in

**Symptom:** the sidebar's identity line (bottom of the sidebar) shows a yellow dot and "Guest session," chat history isn't tied to your account, and `/ai-chat/settings` refuses you with a 403 even as an admin — all while you're genuinely signed in elsewhere in the same app.

**Cause:** `/ai-chat` is a normal server-rendered page. LaravelAI figures out who's chatting by calling `$request->user()` — Laravel's standard session-based check. That's correct for classic apps, but it resolves to nobody for a real and common architecture: a Vue/React SPA whose API auth is a **Bearer token kept in the SPA's own JS state** (`localStorage`, a Pinia/Redux store, Sanctum/Passport personal access tokens) rather than a Laravel session. A plain browser navigation to `/ai-chat` never attaches that token — nothing about a full-page `<a href>` visit runs your SPA's axios interceptor — so the request is genuinely anonymous as far as Laravel is concerned, no matter how logged-in you are in the SPA itself. Confirmed live on exactly this setup: every `/ai-chat` session showed `user_id = NULL` the whole time the user was signed in.

Two ways to fix it, pick whichever fits your app:

**Option A — bridge your SPA's token into a real session** (closest to "just make it work exactly like the rest of my app"). Add one endpoint your SPA calls (with its existing Bearer token) right before it links to `/ai-chat`:

```php
// routes/api.php, inside your existing auth:sanctum group
Route::post('/session-bridge', function (Request $request) {
    Auth::guard('web')->login($request->user());
    $request->session()->regenerate();
    return response()->json(['ok' => true]);
});
```

```js
// before navigating to /ai-chat
await api.post('/session-bridge')
window.location.href = '/ai-chat'
```

**Option B — plug in your own identity check**, no session bridge needed. `config('ai.chat.identity_resolver')` is called with the current `Request` and returns a user id (or `null` for guest) — check whatever your app actually uses:

```php
// config/ai.php
'chat' => [
    'identity_resolver' => fn ($request) => $request->user('sanctum')?->id,
],
```

Either way, the sidebar's identity line will pick up the resolved identity automatically. Option A also fixes `$request->user()` everywhere else on `/ai-chat`, not just chat ownership — the safer default if you're not sure which you need. Note that the Settings page's `manage-ai-settings` Gate specifically still checks `$request->user()` directly (see [Provider Settings UI & Auth Guard](#-provider-settings-ui--auth-guard)), not `identity_resolver` — a token-auth SPA visitor needs a real bridged session (Option A) to reach `/ai-chat/settings`, not just a resolved identity for chat ownership.

---

## 🗺️ Roadmap

| Version | Feature | Status |
|---------|---------|--------|
| v1.0 | Ollama, OpenAI, Anthropic, DeepSeek | ✅ Released |
| v1.1 | Laravel 12 & 13 support | ✅ Released |
| v1.2 | Built-in RAG system + Ollama advanced | ✅ Released |
| v1.3 | Built-in Chat UI | ✅ Released |
| v1.4 | Projects + RAG scoping (self-hosted Claude Projects) | ✅ Released |
| v2.0 | Security & Trust Suite (rate limiting, access control, captcha, GDPR gate...) | ✅ Released |
| v2.0 | Vision / Image input (OpenAI, Anthropic, Gemini) | ✅ Released |
| v2.0 | Google Gemini + Together AI + custom OpenAI-compatible drivers | ✅ Released |
| v2.0 | Chat attachments (images + documents) | ✅ Released |
| v2.0 | Analytics dashboard + webhooks | ✅ Released |
| v2.0 | Bot profiles + embeddable floating widget | ✅ Released |
| v2.0 | Image generation (Together AI / FLUX, via `/image`) | ✅ Released |
| v2.1 | Live "thinking" visibility for reasoning models (Ollama, Anthropic, Gemini) | ✅ Released |
| v2.1 | Provider Settings UI + auth guard for the chat area | ✅ Released |
| v2.1 | Claude/ChatGPT-style message layout + responsive mobile UI | ✅ Released |
| v2.1 | Multi-format export — PDF, Word, Excel, PowerPoint | ✅ Released |
| v2.1 | Settings encryption at rest for provider API keys | ✅ Released |
| v2.1.1 | Fix — assistant replies no longer lost on long-running streams | ✅ Released |
| v2.1.2 | Fix — reapplied guest-cookie persistence fix + broken sidebar link | ✅ Released |
| v2.2 | Pluggable identity resolution (`identity_resolver`) for token-auth SPAs | ✅ Released |
| v2.2 | Fix — Projects had no ownership scoping at all | ✅ Released |
| v2.3 | RAG search scans in bounded batches instead of loading the entire corpus | ✅ Released |
| v2.4 | Commerce assistants — schema-agnostic Product Q&A, Order Status, Ask Your Store | ✅ Released |
| v2.5 | Agent module — function/tool calling (OpenAI, Anthropic, Gemini, Ollama) | ✅ Released |
| v2.5 | Built-in web search tool (Tavily / Brave, pluggable) | ✅ Released |
| v2.6 | `php artisan laravelai:install` — guided one-command setup | ✅ Released |
| v2.6 | Opt-in queued RAG ingestion + webhook delivery | ✅ Released |
| v2.6 | Fix — attachment/project files leaked on disk after deletion | ✅ Released |
| v2.6 | Bounded conversation-history loading for very long chats | ✅ Released |
| v2.7 | Groq driver | ✅ Released |
| v2.7 | Response caching (opt-in) | ✅ Released |
| v2.7 | Pluggable vector-store backend for RAG (`VectorStoreInterface` + a working pgvector implementation) | ✅ Released |
| v2.7 | Tool-calling in the built-in chat UI | ✅ Released |
| v2.7 | CI matrix — full suite against real MySQL and Postgres, not just SQLite | ✅ Released |
| v2.8 | Structured output (`->format($schema)`) across every provider | ✅ Released |
| v2.8 | Cross-provider embeddings — `->embed()` on OpenAI, Gemini, Together, not just Ollama | ✅ Released |
| v2.8 | Automatic retry with backoff for transient failures (opt-in) | ✅ Released |
| v2.8 | CI matrix — real coverage for PHP 8.1/8.2 and Laravel 10/13, not just 8.3/8.4 on Laravel 12 | ✅ Released |
| v2.8 | OpenAI image generation (`->generateImage()`, DALL·E and GPT image models) | ✅ Released |
| v2.8 | Gemini image generation | ⏭️ Deferred at the time — Google's docs described a newer request/response shape than every other Gemini feature here used, not implemented without being able to verify its exact schema as confidently as the rest of this release. Shipped later, verified live, as **v2.17** below |
| v2.8 | Audio — `->transcribe()` / `->textToSpeech()` (OpenAI, inherited by Groq/Together where each genuinely supports it) | ✅ Released |
| v2.8 | Cost estimation (`getEstimatedCost()`, config-driven — no built-in prices, by design) | ✅ Released |
| v2.8 | PHP requirement corrected to the true minimum (`^8.1`) | ✅ Released |
| v2.9 | Scalable Settings-page admin access — `laravelai:make-admin`, an "Admin Access" UI panel, no hand-written Gate required | ✅ Released |
| v2.9 | Fix — `ai:rag:ingest` was fully built and documented but never actually registered as a runnable command | ✅ Released |
| v2.9.1 | Fix — `chat_sessions`/`chat_messages`/`chat_attachments` renamed to `ai_chat_*` for namespace-collision safety, verified live against real MySQL data | ✅ Released |
| v2.10 | Together AI's `image_enabled`/model/size/steps fields exposed in the Settings UI, not just `.env` | ✅ Released |
| v2.10.1 | Fix — Together's default image model (`FLUX.1-schnell-Free`) 400s on every account; the promotional endpoint isn't live on Together's Serverless API yet | ✅ Released |
| v2.11 | Persisted usage & cost tracking (`ai_usage_logs`) — a "Usage & Costs" tab on the Settings page, covering every provider's chat *and* image calls | ✅ Released |
| v2.11.1 | Fix — `/image` replies mirrored into local attachment storage on generation, so they no longer go permanently broken once Together's temporary URL expires | ✅ Released |
| v2.12 | Per-image export — ⬇ PNG / ⬇ JPEG / ⬇ PDF buttons on any reply that's just a generated image | ✅ Released |
| v2.13 | PDF page-image vision (`pdf_vision_enabled`) — a PDF's actual pages, not just its extracted text, become real vision input for OpenAI/Anthropic/Gemini; multi-image vision support (was one image per message, max) | ✅ Released |
| v2.14 | Streaming the agent module's final answer after the tool-call loop resolves — `run()`'s new `$onChunk` param, every driver's stream handler now reassembles tool calls from that provider's own real incremental format | ✅ Released |
| v2.14.1 | Removed a dead duplicate file (`src/RAG/Console/RagIngestCommand.php`, unautoloadable, flagged in an earlier session); CI fix — the DB-matrix job was missing the Ghostscript + `imagick` setup needed to actually test PDF page-image vision, not just skip it | ✅ Released |
| v2.15 | `laravelai:install` now offers to run `composer require` for whichever optional features you say yes to (chat attachments, PDF vision, each export format) instead of you hitting a "run this command" message on first use | ✅ Released |
| v2.15.1 | CI fix — restored the Laravel 11 leg to the test matrix (its earlier "structurally unsatisfiable" dependency conflict no longer reproduces) | ✅ Released |
| v2.16 | The chat UI's `/image`/`/img` command is provider-selectable — `AI_OPENAI_IMAGE_ENABLED` alongside Together's own flag, following whichever image-capable provider is actually active | ✅ Released |
| v2.17 | Gemini image generation ("Nano Banana" model family) — `AI::provider('gemini')->generateImage()` + `AI_GEMINI_IMAGE_ENABLED` wired into the same `/image` command | ✅ Released |
| v2.18 | Web search settings (on/off, provider, both API keys) manageable from `/ai-chat/settings`' new 🔎 Web Search tab — no `.env` edit needed | ✅ Released |
| v2.19 | 📤 Share a reply via WhatsApp/Email — first of two share phases; a real public share-link (and a genuinely useful Facebook share) is next | ✅ Released |
| v2.20 | 📤 Public, read-only conversation share links (`/ai-chat/s/{token}`) — unlocks Facebook sharing + link-based WhatsApp/Email, second of two share phases | ✅ Released |
| v2.21 | Per-step agent-loop observability — `run()`'s new `$onStep` param fires once per real LLM call (every intermediate tool-calling step and the final one), handing you each step's own `AIResponse` before that step's tool executes, so a caller can accumulate true per-step token usage/cost across a multi-step tool-calling turn | ✅ Released |

---

## ❤️ Support

<p align="center">
  <a href="https://easyit.com.bd/donate">
    <img src="https://img.shields.io/badge/Donate-Support%20This%20Project-blue?style=for-the-badge&logo=heart&logoColor=white" alt="Donate">
  </a>
  &nbsp;
  <a href="https://github.com/sponsors/muradbdinfo">
    <img src="https://img.shields.io/badge/GitHub%20Sponsors-EA4AAA?style=for-the-badge&logo=github-sponsors&logoColor=white" alt="GitHub Sponsors">
  </a>
</p>

- ⭐ **Star** this repo on GitHub
- 🐛 **Report bugs** via [Issues](https://github.com/easybdit/laraveleasyai/issues)
- 🔀 **Submit a PR** — contributions welcome
- 📢 **Share** with your developer friends

---

## 👤 Credits

**Md Murad Hosen** — Full-Stack Laravel Vue Developer and DevOps Engineer from Chittagong, Bangladesh 🇧🇩

<table>
  <tr>
    <td>🌐 Website</td><td><a href="https://www.easyit.com.bd">easyit.com.bd</a></td>
    <td>📺 YouTube</td><td><a href="https://youtube.com/@easybdit">EasyBD IT</a></td>
  </tr>
  <tr>
    <td>📘 Facebook</td><td><a href="https://facebook.com/muradhosenofficial">Murad Hosen</a></td>
    <td>📱 WhatsApp</td><td><a href="https://wa.me/8801827517700">+8801827517700</a></td>
  </tr>
  <tr>
    <td>💻 GitHub</td><td><a href="https://github.com/muradbdinfo">muradbdinfo</a></td>
    <td>👥 FB Group</td><td><a href="https://www.facebook.com/groups/eitbd">EITBD</a></td>
  </tr>
</table>

---

## 📄 License

MIT License — free to use in personal and commercial projects. See [LICENSE](LICENSE) for details.

<p align="center">
  <sub>Made with ❤️ in Bangladesh 🇧🇩 · Built for the Laravel community worldwide</sub>
</p>
