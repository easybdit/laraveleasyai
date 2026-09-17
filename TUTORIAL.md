# Build an AI Support Assistant with LaravelAI — A Hands-On Tutorial

> This is a *teaching* document, not a reference. It walks a Laravel developer who has **never touched an AI package before** through building one real feature — a support chatbot for a small e-commerce brand — from an empty `composer require` to a production-ready assistant with a knowledge base, tool calling, image generation, an admin panel, and cost tracking.
>
> Already know the package and just need a specific snippet? Use [SETUP_GUIDE.md](SETUP_GUIDE.md) (feature-by-feature) or [README.md](README.md) (full reference) instead — this file is meant to be read top to bottom, once, like a course.

**What you'll build:** an AI support assistant for a fictional coffee subscription brand, "BeanRoute." By the end it will: answer questions in your app's own voice, know your actual shipping/refund policy (not hallucinate one), look up a real customer's order status by calling your own PHP code, generate a product mockup image on request, and give you an admin page to manage it all — including watching what it's costing you in real dollars.

**Prerequisites:** a working Laravel 10+ app, PHP 8.1+, and 20 minutes. You do *not* need an OpenAI account to start — we'll begin completely free and local, and bring in a paid cloud provider only once you understand why you'd want one.

---

## Chapter 0 — Install

```bash
composer require easybdit/laraveleasyai
php artisan laravelai:install
```

The installer is interactive: it publishes `config/ai.php`, runs the package's migrations, asks which provider you want as your default, and writes the result straight to `.env`. Pick **Ollama** when it asks — it's the only provider here that costs nothing and needs no API key, which matters for a tutorial: you shouldn't need a credit card to learn.

Don't have Ollama installed? Grab it from [ollama.com/download](https://ollama.com/download), then pull a small model:

```bash
ollama pull qwen2:1.5b
ollama serve
```

> 💡 **Why start local instead of OpenAI?** Two reasons a senior dev cares about: zero marginal cost while you're iterating (you'll restart your test message a dozen times while wiring this up — that's free on Ollama, not on a metered API), and it forces your code to be provider-agnostic from line one, which is the entire point of a package like this. Switching to a cloud provider later is a one-line config change, not a rewrite — you'll see that in Chapter 5.

Sanity-check the connection before writing any app code:

```bash
php artisan tinker
>>> AI::provider('ollama')->health()
=> true
```

`false` or an exception here means "fix this before you write a single line of feature code" — almost always Ollama isn't running (`ollama serve`) or the model in `.env` (`AI_OLLAMA_MODEL`) was never pulled (`ollama pull <name>`).

---

## Chapter 1 — Your first response

Every call in this package goes through one facade, regardless of which of the 8 providers it's actually talking to:

```php
use EasyAI\LaravelAI\Facades\AI;

Route::get('/support-test', function () {
    $response = AI::chat([
        ['role' => 'user', 'content' => 'In one sentence, what is a coffee subscription service?'],
    ]);

    return $response->content;
});
```

Visit `/support-test`. If you see a real sentence back, the whole plumbing — config, driver resolution, the HTTP call to Ollama, response parsing — is working end to end. That's genuinely the hard part done; everything from here is composition on top of this one call.

`$response` isn't just a string — it's an `AIResponse` object carrying `content`, `promptTokens`, `completionTokens`, `model`, `provider`, and (later chapters) tool calls and structured data. Keep that in your head; it matters in Chapter 4.

> ⚠️ **Gotcha a real developer hits here:** forgetting the `role` key and passing a bare string. Every message is `['role' => 'user'|'assistant'|'system', 'content' => '...']` — the same shape OpenAI's own API popularized, which every provider driver in this package normalizes *to* internally, so you write it once and it works everywhere.

---

## Chapter 2 — Give it a personality, and a face

Right now your assistant has no context about who it's supposed to be. Fix that with a system prompt:

```php
$response = AI::provider('ollama')
    ->systemPrompt('You are BeanRoute\'s friendly support assistant. Keep answers short and warm.')
    ->chat([['role' => 'user', 'content' => 'Do you ship internationally?']]);
```

That's a real, working support bot already — but nobody wants to build a chat UI by hand, and you shouldn't have to. The package ships one, already live at `/ai-chat` the moment the installer finished — no flag to flip, no Blade code to write.

Visit `/ai-chat`. You get streaming responses, conversation history, file attachments, message export (PDF/Word/PowerPoint), and light/dark mode, out of the box. Set the personality package-wide instead of per-call:

```env
AI_CHAT_SYSTEM_PROMPT="You are BeanRoute's friendly support assistant. Keep answers short and warm."
AI_CHAT_WELCOME_ENABLED=true
AI_CHAT_WELCOME_MESSAGE="Hi! Ask me anything about your BeanRoute order or subscription."
```

> 🧪 **Try it yourself:** open `/ai-chat` in two browser tabs (one normal, one incognito) and send different messages in each. Notice they don't cross-contaminate — sessions are already isolated per visitor, cookie-based for guests and account-based once they log in. You didn't write that; you got it by installing the package.

---

## Chapter 3 — Stop it from making things up (RAG)

Ask your assistant right now: *"What's your refund window?"* It'll confidently invent an answer, because it has no idea what BeanRoute's actual policy is. This is the single most common way a support bot embarrasses a company — and the fix is called **RAG** (Retrieval-Augmented Generation): give the model your real documents, and make it answer *from* them instead of guessing.

```php
use EasyAI\LaravelAI\Facades\AI;

AI::rag()->ingest(
    'BeanRoute accepts returns within 30 days of delivery for unopened bags. ' .
    'Opened bags are eligible for a 50% refund if the reason is a quality issue.',
    'refund-policy'
);
```

That one call chunked the text, generated embeddings for it, and stored it — no vector database to install, no infrastructure to run. Now ask a grounded question:

```php
$answer = AI::rag()->ask('If I already opened the bag, can I still get money back?');
// "Yes — opened bags qualify for a 50% refund if the issue is quality-related."
```

Same idea, but from a real file instead of an inline string (exactly what you'd do with an actual policy PDF):

```bash
php artisan ai:rag:ingest storage/docs/refund-policy.pdf --source=refund-policy
```

Wire this into the chat UI and every visitor's question is now automatically checked against your real documents before the model answers — this is on by default once you've ingested anything, no extra code required in the chat controller.

> 💡 **The "why" a junior dev usually skips:** RAG doesn't fine-tune the model or change its weights — it's closer to giving an open-book exam instead of a closed-book one. That's *why* it works with any provider (Ollama, OpenAI, whatever) and *why* it's cheap to update: change the policy document, re-ingest, done. No retraining, ever.

Want this scoped per-product instead of one global knowledge base — like Claude's "Projects"? You've already seen the trick — `ingest()`'s second argument *is* the scope name, same as `'refund-policy'` and `'policies'` above. Reading it back, scoped, uses `->source()` instead:

```php
AI::rag()->ingest('The Explorer plan ships one 12oz bag every 2 weeks for $18.', 'subscription-plans');

$answer = AI::rag()->source('subscription-plans')->ask('How often does the Explorer plan ship?');
```

> ⚠️ **Easy to get backwards:** `source()` only affects *reading* (`ask()`/`search()`/`flush()`) — `ingest()` always takes its source as that second string argument, never from a preceding `->source()` call. Chaining `->source('x')->ingest(...)` compiles fine and silently ingests with **no** source at all, which is the kind of bug that only shows up later when your scoped `ask()` mysteriously finds nothing.

Or skip the code entirely — the chat UI has a **Projects** panel where a non-developer teammate can upload `.pdf`/`.txt`/`.md` files themselves, and every chat inside that project is automatically scoped to just those documents.

---

## Chapter 4 — Let it actually *do* something (Agent / Tool calling)

RAG makes the model *knowledgeable*. Tool calling makes it *capable* — able to reach into your real application and act, not just talk. Let's give it the ability to look up a real order.

```php
use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelAI\Facades\AI;

$orderLookup = Tool::make(
    name: 'get_order_status',
    description: 'Look up the shipping status of a BeanRoute order by its order number.',
    parameters: [
        'type'       => 'object',
        'properties' => ['order_number' => ['type' => 'string', 'description' => 'e.g. BR-4821']],
        'required'   => ['order_number'],
    ],
    handler: function (array $args) {
        // In a real app: Order::where('number', $args['order_number'])->firstOrFail();
        return ['status' => 'Shipped', 'carrier' => 'USPS', 'eta' => '2 business days'];
    },
);

$response = AI::provider('ollama')->model('qwen2.5:7b')->tools([$orderLookup])->run([
    ['role' => 'user', 'content' => "Where's my order BR-4821?"],
]);

echo $response->content;
// "Your order BR-4821 has shipped via USPS and should arrive within 2 business days."
```

Notice `run()`, not `chat()`. `chat()` is one request-response round trip; `run()` drives the *whole loop* — it sends your message, notices the model wants to call `get_order_status`, executes your real `handler` closure, feeds the real result back to the model, and only then returns the model's final, human-readable answer. Up to 5 round trips by default (`run($messages, maxSteps: 10)` to raise it).

> ⚠️ **A gotcha worth knowing before you hit it:** tool calling is model-dependent on Ollama specifically — not every local model supports it, which is why the example above switches to `qwen2.5:7b` (`ollama pull qwen2.5:7b` first) instead of the tiny `qwen2:1.5b` from Chapter 0 — a model that small isn't a reliable choice for tool calling. On OpenAI, Anthropic, and Gemini it just works with their standard chat models, no special selection needed.

You don't have to write the "search the web" tool yourself either — it ships built in:

```php
use EasyAI\LaravelAI\Agent\Tools\WebSearchTool;

$response = AI::provider('openai')->tools([WebSearchTool::make()])->run([
    ['role' => 'user', 'content' => 'Are there any recent coffee bean shortages in the news?'],
]);
```

(needs `AI_WEB_SEARCH_PROVIDER=tavily` or `brave` plus a free-tier API key from either).

---

## Chapter 5 — Go to production: pick a real cloud provider

Ollama got you this far for free, but for a real customer-facing product you'll usually want a hosted model's quality/latency. Here's the entire cost of switching, after everything you've built above:

```env
AI_OPENAI_KEY=sk-...
AI_OPENAI_MODEL=gpt-4o-mini
```

```php
// was: AI::provider('ollama')->tools([$orderLookup])->run($messages);
AI::provider('openai')->tools([$orderLookup])->run($messages);
```

One string changed. Every tool you defined, every RAG-ingested document, every system prompt — all of it works identically, because none of your feature code ever depended on which provider it was talking to. That's the actual point of this package, and now you've felt it rather than just read a claim about it.

Want the built-in chat UI to let *visitors* pick their own provider, or want it switchable per-conversation from an admin dropdown? That's already there too — see Chapter 7.

Anthropic, DeepSeek, Groq, Gemini, and Together AI all follow the exact same pattern — a `.env` block plus `AI::provider('name')`. See [README.md § Providers](README.md#-providers) for each one's specific env keys.

---

## Chapter 6 — Add a "wow" feature: image generation

Let's give BeanRoute's assistant the ability to mock up a product image on request — Together AI hosts FLUX, a real open-source image model, cheaply:

```env
AI_TOGETHER_KEY=your-together-api-key
AI_TOGETHER_IMAGE_ENABLED=true
```

```php
$url = AI::provider('together')->generateImage('a rustic coffee bag with a hand-drawn bean illustration, warm autumn colors');
// https://... — a real hosted image URL, ready to drop into an <img src="">
```

In the built-in chat UI, this is already wired to a slash command — type `/image a rustic coffee bag mockup` and it generates and displays inline, no extra code.

> ⚠️ **A real gotcha, straight from building this package:** Together's *free* FLUX endpoint (`black-forest-labs/FLUX.1-schnell-Free`) isn't actually live on their Serverless API as of this writing — it 400s with `model_not_available` for every account, not just yours. The package's default model is the regular **paid** `FLUX.1-schnell` instead, which costs a fraction of a cent per image (~$0.003) and just needs a positive credit balance on your Together account. This is exactly the kind of thing that eats an hour of debugging if you don't know it going in — now you do. If you ever see `model_not_available` from any provider, the model name in your config is the first thing to check, not your code.

Prefer OpenAI's DALL·E instead of Together's FLUX? Same contract, same one-line swap:

```php
$url = AI::provider('openai')->generateImage('a rustic coffee bag mockup'); // gpt-image-2 by default
```

---

## Chapter 7 — Admin control: the Settings page and cost tracking

Every `.env` value you've set so far is great for you, the developer — but a real product needs a non-technical teammate to be able to, say, turn image generation on, swap a stuck API key, or just *see what this is costing the company* without filing a deploy request. That's what the Settings page is for.

```bash
php artisan laravelai:make-admin your@email.com
```

Log in as that user and visit `/ai-chat/settings`. You'll see:

- **Providers tab** — every API key, model, and per-provider setting (including the Together image options from Chapter 6), editable from a browser, encrypted at rest, masked in the UI. Change one, click Save, it's live immediately — no redeploy, no `.env` edit.
- **Usage & Costs tab** — off by default; check the box to start logging. From that point on, *every* `chat()` and `generateImage()` call anywhere in your app — not just the chat UI — is recorded: which provider, which model, how many tokens or images, and (once you fill in a real rate) an estimated dollar cost.

Turn on cost tracking and tell it what things actually cost you:

```php
// config/ai.php
'pricing' => [
    'openai' => [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60], // USD per 1,000 tokens
    ],
    'together' => [
        'image' => ['black-forest-labs/FLUX.1-schnell' => ['per_mp' => 0.0027]], // USD per megapixel
    ],
],
```

Now the Settings page shows real running totals — total spend, this month's spend, a breakdown by provider — instead of just call counts. This is the difference between *hoping* your AI feature is affordable and *knowing*, in dollars, on a dashboard, without grepping logs.

> 💡 **Why this is opt-in, not automatic:** a package that silently starts writing a database row on every single AI call — even to users who never asked for that — is the kind of surprise behavior a senior engineer distrusts a dependency for. Nothing here is logged, and nothing costs a guessed number, until you deliberately turn it on and fill in a real rate.

Already have your own admin/roles system (Spatie permissions, a custom `is_admin` column)? The Settings gate is a plain Laravel `Gate`, so your own app takes over completely with one line, no package code to fork:

```php
Gate::define('manage-ai-settings', fn ($user) => $user->hasRole('admin'));
```

---

## Chapter 8 — Test what you built

Everything above is `Http::fake()`-friendly, the same way you'd test any HTTP-calling code in Laravel:

```php
use Illuminate\Support\Facades\Http;
use EasyAI\LaravelAI\Facades\AI;

public function test_support_bot_answers_a_shipping_question(): void
{
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Yes, we ship internationally!']]],
            'usage'   => ['prompt_tokens' => 20, 'completion_tokens' => 8],
        ]),
    ]);

    $response = AI::provider('openai')->chat([
        ['role' => 'user', 'content' => 'Do you ship internationally?'],
    ]);

    $this->assertStringContainsString('internationally', $response->getContent());
}
```

No real API call happens, no API key is needed in CI, and it runs in milliseconds. This is exactly the pattern the package's own 248-test suite uses throughout — if you're ever unsure how to fake a specific provider's response shape, its `tests/Feature/` directory is full of real, working examples for every driver.

---

## What you actually built

Look back at that list from the top of this file — a support assistant that answers in your brand's voice, refuses to make up policy, can look up a real order, can draw a product mockup on request, is switchable to any of 8 providers with a one-line change, is manageable by a non-developer through a real UI, and reports its own running cost in dollars. That's not a toy demo; that's a genuinely shippable feature, and everything above it is real, copy-pasteable code — not pseudocode.

## Where to go next

- **Every config key, every edge case, full troubleshooting:** [README.md](README.md)
- **Feature-by-feature reference** (once you know the package and just need a specific snippet): [SETUP_GUIDE.md](SETUP_GUIDE.md)
- **What shipped in each version, and why:** [CHANGELOG.md](CHANGELOG.md)
- **Security & trust knobs** (rate limiting, access restriction, GDPR consent gate, word filters) before this goes anywhere near real users: [README.md § Security & Trust](README.md#-security--trust)
