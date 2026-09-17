<?php
return [
    'default' => env('AI_PROVIDER', 'ollama'),

    'providers' => [
        'ollama' => [
            'driver'     => 'ollama',
            'url'        => env('AI_OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model'      => env('AI_OLLAMA_MODEL', 'qwen2:1.5b'),
            'timeout'    => (int) env('AI_OLLAMA_TIMEOUT', 120),
            'keep_alive' => env('AI_OLLAMA_KEEP_ALIVE'),
            // Reasoning models (qwen3, etc.) "think" before answering by
            // default — often adding many seconds of latency for zero
            // visible benefit on simple questions. null = model's own
            // default; true/false explicitly turns it on/off for every
            // request against this provider (override per-call with
            // ->think()).
            'think'      => filter_var(env('AI_OLLAMA_THINK'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'options'    => ['temperature' => 0.7],
        ],
        'openai' => [
            'driver'  => 'openai',
            'api_key' => env('AI_OPENAI_KEY'),
            'url'     => env('AI_OPENAI_URL', 'https://api.openai.com/v1'),
            'model'   => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('AI_OPENAI_TIMEOUT', 60),
            'vision'  => true,
            'options' => ['temperature' => 0.7, 'max_tokens' => 2000],
            // dall-e-3 (this default until v2.21.0) was removed from the API
            // entirely on 2026-05-12 — confirmed via OpenAI's own deprecation
            // notice, not assumed; every install still on that default was
            // getting a hard failure on every image request. gpt-image-2 is
            // the current model. Unlike dall-e-3's lightweight hosted URL
            // (valid 60 minutes, matching Together's generateImage()
            // contract), every gpt-image-* model returns only base64
            // (confirmed against OpenAI's own OpenAPI spec — no url option
            // exists for them at all) — OpenAIDriver::generateImage() already
            // branches on the model name prefix to wrap that as a
            // data:image/...;base64 string instead, meaningfully larger if
            // you store the resulting chat message.
            'image_model' => env('AI_OPENAI_IMAGE_MODEL', 'gpt-image-2'),
            // Off by default, same posture as Together's own image_enabled —
            // image generation is billed separately from chat and shouldn't
            // turn on just because an OpenAI key is configured. Once on, the
            // chat UI's /image and /img commands use OpenAI's own model
            // whenever OpenAI is the active chat provider (see
            // AIChatController::resolveImageProvider()), or as the fallback
            // when it isn't but Together's image_enabled is off.
            'image_enabled' => (bool) env('AI_OPENAI_IMAGE_ENABLED', false),
            // ->transcribe() / ->textToSpeech()
            'transcribe_model' => env('AI_OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),
            'tts_model'        => env('AI_OPENAI_TTS_MODEL', 'tts-1'),
            'tts_voice'        => env('AI_OPENAI_TTS_VOICE', 'alloy'),
        ],
        'anthropic' => [
            'driver'  => 'anthropic',
            'api_key' => env('AI_ANTHROPIC_KEY'),
            'url'     => env('AI_ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            // claude-sonnet-4-20250514 (this default until v2.21.0) was
            // retired by Anthropic on 2026-06-15 09:00 PT — API calls to it
            // error outright since then. claude-sonnet-5 is the current
            // flagship Sonnet model (also cheaper: $2/$10 per MTok vs the
            // retired model's $3/$15).
            'model'   => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-5'),
            'version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),
            'timeout' => (int) env('AI_ANTHROPIC_TIMEOUT', 60),
            'vision'  => true,
            // Extended thinking — off unless explicitly enabled (adds cost
            // and latency by design). budget_tokens must stay below max_tokens;
            // the driver bumps max_tokens automatically when needed.
            'think'              => filter_var(env('AI_ANTHROPIC_THINK'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'think_budget_tokens' => (int) env('AI_ANTHROPIC_THINK_BUDGET', 10000),
            'options' => ['max_tokens' => 2000],
        ],
        'deepseek' => [
            'driver'  => 'deepseek',
            'api_key' => env('AI_DEEPSEEK_KEY'),
            'url'     => env('AI_DEEPSEEK_URL', 'https://api.deepseek.com/v1'),
            // The 'deepseek-chat' alias (this default until v2.21.0) was
            // retired as a name after 2026-07-24, though DeepSeek still
            // routes legacy requests to the current model rather than
            // erroring — not a hard break, but 'deepseek-flash' is the real
            // current name and avoids depending on a retired alias staying
            // routable indefinitely.
            'model'   => env('AI_DEEPSEEK_MODEL', 'deepseek-flash'),
            'timeout' => (int) env('AI_DEEPSEEK_TIMEOUT', 60),
            'vision'  => false,
            'options' => ['temperature' => 0.7, 'max_tokens' => 2000],
        ],
        'groq' => [
            'driver'  => 'groq',
            'api_key' => env('AI_GROQ_KEY'),
            'url'     => env('AI_GROQ_URL', 'https://api.groq.com/openai/v1'),
            // llama-3.3-70b-versatile (this default until v2.21.0) was
            // decommissioned by Groq on 2026-08-16 — confirmed via Groq's
            // own model-deprecation docs, not assumed; every install still
            // on that default was getting a hard failure on every chat
            // request. openai/gpt-oss-120b is Groq's own migration
            // recommendation for it.
            'model'   => env('AI_GROQ_MODEL', 'openai/gpt-oss-120b'),
            'timeout' => (int) env('AI_GROQ_TIMEOUT', 60),
            'vision'  => false,
            'options' => ['temperature' => 0.7, 'max_tokens' => 2000],
        ],
        'gemini' => [
            'driver'  => 'gemini',
            'api_key' => env('AI_GEMINI_KEY'),
            'url'     => env('AI_GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model'   => env('AI_GEMINI_MODEL', 'gemini-2.0-flash'),
            'timeout' => (int) env('AI_GEMINI_TIMEOUT', 60),
            'vision'  => true,
            // null = model's own default; true/false explicitly requests
            // (or suppresses) visible reasoning parts for 2.5-series models.
            'think'   => filter_var(env('AI_GEMINI_THINK'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'options' => ['temperature' => 0.7, 'max_tokens' => 2000],
            // "Nano Banana" model family — GeminiDriver::generateImage()
            // always returns a data:...;base64,... URI (no hosted-URL mode
            // exists for these models, confirmed against Google's own
            // docs), so the chat UI's persisted attachment ends up larger
            // than OpenAI/Together's hosted-link results, same tradeoff as
            // setting AI_OPENAI_IMAGE_MODEL to a gpt-image-* model. Off by
            // default, same posture as every other provider's image_enabled.
            'image_enabled' => (bool) env('AI_GEMINI_IMAGE_ENABLED', false),
            'image_model'   => env('AI_GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'),
        ],
        'together' => [
            'driver'  => 'together',
            'api_key' => env('AI_TOGETHER_KEY'),
            'url'     => env('AI_TOGETHER_URL', 'https://api.together.xyz/v1'),
            'model'   => env('AI_TOGETHER_MODEL', 'meta-llama/Llama-3.3-70B-Instruct-Turbo'),
            'timeout' => (int) env('AI_TOGETHER_TIMEOUT', 60),
            'vision'  => false,
            'options' => ['temperature' => 0.7, 'max_tokens' => 2000],
            // Together AI also serves image generation (FLUX) — used by /image and /img
            // commands in the chat UI when 'image_enabled' is on.
            //
            // The promotional "-Free" endpoint (black-forest-labs/FLUX.1-schnell-Free)
            // is not currently live on Together's Serverless API (confirmed against
            // Together's own model page, which lists it "Launching soon" and returns
            // a 400 model_not_available for every account until it does) — so the
            // default here is the regular paid FLUX.1-schnell instead. It's
            // inexpensive ($0.0027/MP — a fraction of a cent per image) and requires
            // only a positive credit balance on the Together account, not a
            // dedicated endpoint.
            'image_enabled' => (bool) env('AI_TOGETHER_IMAGE_ENABLED', false),
            'image_model'   => env('AI_TOGETHER_IMAGE_MODEL', 'black-forest-labs/FLUX.1-schnell'),
            'image_size'    => env('AI_TOGETHER_IMAGE_SIZE', '1024x1024'),
            'image_steps'   => (int) env('AI_TOGETHER_IMAGE_STEPS', 4),
        ],
    ],

    /**
     * Any OpenAI-compatible endpoint — LM Studio, vLLM, OpenRouter, a proxy,
     * a second in-house gateway. Add as many named entries as you like and
     * reach them with AI::custom('lmstudio') or, in the chat UI, the
     * "custom:lmstudio" provider key.
     */
    'custom_providers' => [
        // 'lmstudio' => [
        //     'label'   => 'LM Studio (local)',
        //     'url'     => 'http://127.0.0.1:1234/v1',
        //     'api_key' => null,
        //     'model'   => 'local-model',
        //     'timeout' => 60,
        //     'vision'  => false,
        // ],
    ],

    'rag' => [
        'embed_provider' => env('AI_RAG_PROVIDER', 'ollama'),
        'embed_model'    => env('AI_RAG_EMBED_MODEL', 'nomic-embed-text'),
        'chat_provider'  => env('AI_RAG_CHAT_PROVIDER', null),
        'chunk_size'     => (int) env('AI_RAG_CHUNK_SIZE', 2000),
        'top_k'          => (int) env('AI_RAG_TOP_K', 3),
        'table'          => env('AI_RAG_TABLE', 'ai_documents'),
        'system_prompt'  => env('AI_RAG_SYSTEM_PROMPT', 'Answer using ONLY the context below. If unsure, say so.'),

        /**
         * The built-in RAG search is an in-PHP cosine scan — no external
         * vector database required, which is the whole point for a
         * zero-setup default. It scores every stored chunk (in bounded
         * batches, not all at once) rather than using an approximate
         * nearest-neighbor index, so it's genuinely fine up to a few tens
         * of thousands of chunks but isn't a substitute for a real vector
         * store at large scale. This caps how many rows a single search
         * will scan before stopping — a slower-than-ideal answer beats a
         * request that never returns. A warning is logged (once per
         * request) whenever a scan actually hits this ceiling.
         */
        'max_scan_rows'  => (int) env('AI_RAG_MAX_SCAN_ROWS', 50000),

        /**
         * Opt-in: run document ingestion (chunking + per-chunk embedding
         * calls) on the queue instead of inline during the upload request.
         * Off by default — nobody's synchronous behavior changes on
         * upgrade. When enabled, ProjectFileController dispatches
         * RAG\Jobs\IngestDocumentJob and marks the file 'queued' instead of
         * 'ingested' until the job completes. Requires a configured queue
         * worker (queue.php) to actually process the job.
         */
        'queue_ingestion' => (bool) env('AI_RAG_QUEUE_INGESTION', false),

        /**
         * Auto-index arbitrary Eloquent models into RAG on save/delete — the
         * Laravel equivalent of the WordPress plugin's "Ask This Site". Empty
         * by default (opt-in): indexing nothing until you list a model here
         * avoids surprising anyone with unexpected embedding calls.
         *
         * 'posts' => [
         *     'model'  => \App\Models\Post::class,
         *     'source' => 'site_posts',
         *     'text'   => fn ($post) => $post->title . "\n\n" . strip_tags($post->body),
         *     'when'   => fn ($post) => $post->is_published,   // optional
         * ],
         */
        'auto_index' => [],
    ],

    /**
     * Chat UI — everything the WordPress plugin exposes through wp-admin
     * settings screens, expressed as config/env by default. .env stays the
     * source of truth for anyone who never opens the Settings page — the
     * ai_settings table (SettingsOverlay) is a purely additive, opt-in
     * override layer for admins who'd rather change providers/keys from
     * /ai-chat/settings than redeploy .env.
     */
    'chat' => [
        'system_prompt'      => env('AI_CHAT_SYSTEM_PROMPT', 'You are a helpful AI assistant.'),
        'lock_system_prompt' => (bool) env('AI_CHAT_LOCK_SYSTEM_PROMPT', false),
        'max_message_length' => (int) env('AI_CHAT_MAX_MESSAGE_LENGTH', 4000),
        'context_messages'   => (int) env('AI_CHAT_CONTEXT_MESSAGES', 10),
        'disable_storage'    => (bool) env('AI_CHAT_DISABLE_STORAGE', false),

        /**
         * Opt-in: let the built-in /ai-chat UI use the agent module's
         * tool/function calling (v2.5.0) instead of always calling the
         * plain streaming chat()/stream() path. Off by default —
         * byte-identical existing behavior when disabled, same bar as
         * every other opt-in feature in this file.
         *
         * When on, AIChatController::stream() builds the tool list from
         * enabled_tools below and uses AbstractDriver::run() instead of
         * stream(). Only built-in tools are wireable here for now (v1) —
         * a host app's own custom Tool instances registered specifically
         * for the chat UI (as opposed to their own code calling
         * AI::provider()->tools([...])->run() directly, which always
         * worked) is a bigger design question, left for a follow-up.
         *
         * run() streams every step (v2.14, AbstractDriver::run()'s $onChunk
         * param) — a request that ends up using a tool keeps the same
         * token-by-token typing effect as the plain stream() path, for the
         * final answer and for any text a turn produces before it decides
         * to call a tool. Each driver's stream handler reassembles
         * tool_calls from that provider's own incremental streaming format
         * (previously the reason this only ever called the non-streaming
         * chat() under the hood) — see AbstractDriver::run()'s own docblock.
         */
        'tools_enabled' => (bool) env('AI_CHAT_TOOLS_ENABLED', false),

        // Allow-list of which built-in tools the chat UI may use when
        // tools_enabled is true. Unknown names are skipped, not fatal.
        // Currently only 'web_search' (EasyAI\LaravelAI\Agent\Tools\WebSearchTool) is wired up.
        'enabled_tools' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CHAT_ENABLED_TOOLS', 'web_search'))))),

        // How many of a user's most recent sessions/projects the sidebar
        // loads at once — without this, a long-time user's entire history
        // (potentially thousands of rows) gets fetched and rendered on
        // every single page load. The client-side sidebar search only
        // searches what's loaded, so raise this if you need deep history
        // search rather than just recency.
        'sidebar_limit'      => (int) env('AI_CHAT_SIDEBAR_LIMIT', 100),

        // How many of a single conversation's most recent messages get
        // fetched from the DB per request in /ai-chat/api/stream — not the
        // same thing as context_messages above (which further trims *this*
        // down to what's actually sent to the AI). Without this, a very
        // long-running conversation would get its entire history loaded on
        // every single new message, just to check things like "is this the
        // first message" or find the last reply to regenerate. 500 is
        // generous for virtually every real conversation; raise it only if
        // you genuinely expect single conversations with thousands of turns.
        'max_loaded_messages' => (int) env('AI_CHAT_MAX_LOADED_MESSAGES', 500),

        'show_internal_errors' => env('AI_CHAT_SHOW_INTERNAL_ERRORS'), // null = fall back to app.debug

        /**
         * How LaravelAI figures out "who is chatting", beyond the default.
         * Out of the box it calls $request->user() — the standard Laravel
         * session guard — which is all classic server-rendered apps need.
         *
         * That default resolves to nobody for an entire class of real,
         * common apps: a Vue/React SPA whose API is guarded by a Sanctum
         * or Passport *Bearer token* kept in the SPA's own JS state
         * (localStorage, a Pinia/Redux store, etc.), never in a Laravel
         * session. A plain browser navigation to /ai-chat — a normal
         * server-rendered page — never attaches that token, so the
         * default check sees a guest even for a genuinely logged-in user.
         * Confirmed live on exactly that kind of install: every /ai-chat
         * session showed user_id = NULL despite the user being signed in
         * to the SPA the whole time.
         *
         * Two ways to fix that, and this config is the second, generic one
         * (the first — bridging the SPA's Bearer token into a real Laravel
         * session on your own /api/session-bridge-style endpoint before
         * navigating to /ai-chat — works too, and is what the README's
         * Troubleshooting section walks through). If you'd rather not
         * stand up a session bridge at all, bind a resolver here instead:
         * it's called with the current Request and must return an
         * authenticated user id (or null for "not signed in"), so you can
         * check whatever your app actually uses — a different guard, a
         * signed query-string token, a custom header, anything:
         *
         *   'identity_resolver' => fn ($request) => $request->user('sanctum')?->id,
         *
         * null (default) = just use $request->user().
         */
        'identity_resolver' => null,

        // Extra middleware for the whole /ai-chat area — empty (public
        // page) by default. Set AI_CHAT_MIDDLEWARE=auth to require login
        // for everything under /ai-chat, redirecting guests to your normal
        // login page, rather than just refusing individual actions.
        'middleware' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CHAT_MIDDLEWARE', ''))))),

        // The Settings page (provider keys, default provider) is
        // fail-closed regardless of the above — refused unless the host
        // app explicitly defines this Gate, same pattern as the Commerce
        // Store Assistant. There is no "everyone" mode for changing API keys.
        'settings_gate' => env('AI_CHAT_SETTINGS_GATE', 'manage-ai-settings'),

        'access' => [
            'allow_guest' => (bool) env('AI_CHAT_ALLOW_GUEST', true),
            // everyone | auth | role | gate
            'restriction' => env('AI_CHAT_ACCESS_RESTRICTION', 'everyone'),
            'roles'       => array_filter(array_map('trim', explode(',', (string) env('AI_CHAT_ACCESS_ROLES', '')))),
            'role_method' => env('AI_CHAT_ACCESS_ROLE_METHOD', 'hasAnyRole'),
            'gate'        => env('AI_CHAT_ACCESS_GATE', 'use-ai-chat'),
        ],

        'rate_limit' => [
            'enabled' => (bool) env('AI_CHAT_RATE_LIMIT_ENABLED', true),
            'window'  => (int) env('AI_CHAT_RATE_LIMIT_WINDOW', 60),   // seconds
            'max'     => (int) env('AI_CHAT_RATE_LIMIT_MAX', 20),      // per identity, per window
            'ip_max'  => (int) env('AI_CHAT_RATE_LIMIT_IP_MAX', 60),   // secondary hard cap per IP
        ],

        'ip_blocklist' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CHAT_IP_BLOCKLIST', ''))))),

        'word_filter' => [
            'enabled' => (bool) env('AI_CHAT_WORD_FILTER_ENABLED', false),
            'words'   => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CHAT_WORD_FILTER_WORDS', ''))))),
            'action'  => env('AI_CHAT_WORD_FILTER_ACTION', 'block'), // block | warn
        ],

        'prompt_injection' => [
            'enabled' => (bool) env('AI_CHAT_PROMPT_INJECTION_DETECT', false),
            'action'  => env('AI_CHAT_PROMPT_INJECTION_ACTION', 'block'), // block | warn
        ],

        'captcha' => [
            'enabled' => (bool) env('AI_CHAT_CAPTCHA_ENABLED', false),
        ],

        'abuse_alert' => [
            'enabled' => (bool) env('AI_CHAT_ABUSE_ALERT_ENABLED', false),
            'email'   => env('AI_CHAT_ABUSE_ALERT_EMAIL'), // falls back to mail.from if empty
        ],

        'gdpr' => [
            'enabled' => (bool) env('AI_CHAT_GDPR_GATE_ENABLED', false),
            'text'    => env('AI_CHAT_GDPR_GATE_TEXT', 'This chat uses third-party AI services. By continuing, you agree to our Privacy Policy.'),
            'button'  => env('AI_CHAT_GDPR_GATE_BUTTON', 'I Accept & Continue'),
        ],

        'webhook' => [
            'url'    => env('AI_CHAT_WEBHOOK_URL'),
            'secret' => env('AI_CHAT_WEBHOOK_SECRET'),
            // Opt-in: dispatch webhook delivery via Chat\Jobs\SendWebhookJob
            // instead of calling Http::post() inline inside the SSE stream.
            // Off by default (unchanged synchronous behavior) — the webhook
            // result is never shown to the user either way, so queueing it
            // is a pure latency win once a queue worker is running.
            'queue'  => (bool) env('AI_CHAT_WEBHOOK_QUEUE', false),
        ],

        'attachments' => [
            'enabled'          => (bool) env('AI_CHAT_ATTACHMENTS_ENABLED', false),
            'max_image_mb'     => (int) env('AI_CHAT_ATTACHMENTS_MAX_IMAGE_MB', 5),
            'max_doc_mb'       => (int) env('AI_CHAT_ATTACHMENTS_MAX_DOC_MB', 10),
            'allowed_images'   => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
            'allowed_docs'     => ['txt', 'md', 'pdf'],

            /**
             * Opt-in, off by default, and requires the PHP `imagick`
             * extension (with a Ghostscript delegate) — neither is a normal
             * PHP install default, so this stays off until you actually
             * have it. When on, an uploaded PDF also gets rendered to a
             * page image per page (up to pdf_vision_max_pages) via
             * PdfPageRenderer; those images ride along as real vision
             * input for VISION_PROVIDERS the next time this PDF is
             * attached to a message — on top of the plain-text extraction
             * every PDF already gets regardless of this setting. Genuinely
             * useful for a PDF where the actual content lives in a chart,
             * diagram, scanned table, or photo, not the extractable text
             * layer — text extraction alone is blind to exactly that.
             * PdfPageRenderer throws a clear "composer/extension needed"
             * message rather than failing silently if imagick isn't
             * actually installed when this is turned on.
             */
            'pdf_vision_enabled'   => (bool) env('AI_CHAT_PDF_VISION_ENABLED', false),
            'pdf_vision_max_pages' => (int) env('AI_CHAT_PDF_VISION_MAX_PAGES', 5),
        ],

        /**
         * Presentation — welcome message, suggested prompts, avatar, colors,
         * floating widget defaults. Read by the chat view and the
         * <x-laravelai::widget /> Blade component.
         */
        'ui' => [
            'title'                => env('AI_CHAT_TITLE', 'AI Chat'),
            'placeholder'          => env('AI_CHAT_PLACEHOLDER', 'Ask me anything...'),
            'welcome_enabled'      => (bool) env('AI_CHAT_WELCOME_ENABLED', false),
            'welcome_message'      => env('AI_CHAT_WELCOME_MESSAGE', 'Hello! How can I help you today?'),
            'suggested_questions'  => array_values(array_filter(array_map('trim', explode('|', (string) env('AI_CHAT_SUGGESTED_QUESTIONS', ''))))),
            'avatar_url'           => env('AI_CHAT_AVATAR_URL'),
            'color_accent'         => env('AI_CHAT_COLOR_ACCENT', '#6366f1'),
            'color_user_bg'        => env('AI_CHAT_COLOR_USER_BG', '#1a56db'),
            'color_bot_bg'         => env('AI_CHAT_COLOR_BOT_BG', '#f3f4f6'),
            'voice_input_enabled'  => (bool) env('AI_CHAT_VOICE_INPUT_ENABLED', true),
            'tts_enabled'          => (bool) env('AI_CHAT_TTS_ENABLED', true),
            'export_enabled'       => (bool) env('AI_CHAT_EXPORT_ENABLED', true),
            // Per-message "Share" dropdown (WhatsApp/Email today — see
            // chat.blade.php's .share-menu; a public share-link and
            // Facebook are a planned follow-up, not yet wired to this flag).
            'share_enabled'        => (bool) env('AI_CHAT_SHARE_ENABLED', true),
        ],

        'floating_widget' => [
            'enabled'  => (bool) env('AI_CHAT_WIDGET_ENABLED', false),
            'position' => env('AI_CHAT_WIDGET_POSITION', 'bottom-right'), // bottom-right | bottom-left
            'label'    => env('AI_CHAT_WIDGET_LABEL', 'Chat with us'),
            'profile'  => env('AI_CHAT_WIDGET_PROFILE'), // optional bot_profiles key
        ],

        /**
         * Named presets — provider, title, system prompt, attachments — that
         * an embed can load instead of the global defaults above:
         *   <x-laravelai::widget profile="support" />
         *
         * 'support' => [
         *     'label'          => 'Support Bot',
         *     'provider'       => 'openai',
         *     'title'          => 'Support',
         *     'system_prompt'  => 'You are a friendly support agent for Acme Inc.',
         *     'attachments'    => true,
         * ],
         */
        'bot_profiles' => [],
    ],

    /**
     * Schema-agnostic commerce assistants — "Ask Your Store", product
     * Q&A/finder, and order status. This package creates no e-commerce
     * tables and assumes no particular catalog/order shape; bind your own
     * implementation of the three interfaces in src/Commerce/Contracts to
     * an app service provider:
     *
     *   $this->app->bind(\EasyAI\LaravelAI\Commerce\Contracts\ProductResolver::class, MyProductResolver::class);
     *
     * Every endpoint returns a clear 501 until its resolver is bound.
     */
    'commerce' => [
        'provider'             => env('AI_COMMERCE_PROVIDER'), // null = use ai.default
        // Store Assistant is fail-closed: define this Gate yourself
        // (Gate::define('view-store-assistant', fn ($user) => $user->isAdmin());)
        // — until you do, every request is refused regardless of auth state.
        'gate'                 => env('AI_COMMERCE_GATE', 'view-store-assistant'),
        'product_search_limit' => (int) env('AI_COMMERCE_PRODUCT_LIMIT', 4),
    ],

    /**
     * The agent module — tool/function calling. Any provider driver can
     * take a list of \EasyAI\LaravelAI\Agent\Tool instances via ->tools()
     * and run an agentic loop via ->run($messages) instead of a single
     * ->chat() call: as long as the model keeps asking to call a tool,
     * the matching Tool's handler is executed and its result fed back,
     * up to a configurable number of round-trips.
     *
     *   use EasyAI\LaravelAI\Agent\Tool;
     *   use EasyAI\LaravelAI\Agent\Tools\WebSearchTool;
     *
     *   $weather = Tool::make('get_weather', 'Get current weather for a city',
     *       ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
     *       fn (array $args) => MyWeatherService::lookup($args['city'])
     *   );
     *
     *   $result = AI::provider('openai')->tools([$weather, WebSearchTool::make()])->run($messages);
     */
    'agent' => [
        'max_steps' => (int) env('AI_AGENT_MAX_STEPS', 5),

        /**
         * Built-in web search tool (EasyAI\LaravelAI\Agent\Tools\WebSearchTool)
         * — gives any provider real-time web results as a tool call. Backed by
         * a pluggable EasyAI\LaravelAI\Agent\Contracts\WebSearchProvider; bind
         * your own implementation to use a different backend entirely:
         *
         *   $this->app->bind(WebSearchProvider::class, MySearchProvider::class);
         *
         * Two built-in providers ship out of the box, both with genuinely free
         * tiers — pick whichever you already have a key for. Neither is
         * required: with no key configured (and nothing bound), the tool
         * degrades to "no results / not configured" rather than failing the
         * whole agent run.
         */
        'web_search' => [
            'provider' => env('AI_WEB_SEARCH_PROVIDER', 'tavily'), // 'tavily' or 'brave'
            'limit'    => (int) env('AI_WEB_SEARCH_LIMIT', 5),

            'tavily' => [
                'api_key' => env('AI_TAVILY_API_KEY'),
                'url'     => env('AI_TAVILY_URL', 'https://api.tavily.com/search'),
            ],
            'brave' => [
                'api_key' => env('AI_BRAVE_API_KEY'),
                'url'     => env('AI_BRAVE_URL', 'https://api.search.brave.com/res/v1/web/search'),
            ],
        ],
    ],

    'logging' => [
        'enabled' => (bool) env('AI_LOG_ENABLED', false),
        'channel' => env('AI_LOG_CHANNEL', 'stack'),
    ],

    /**
     * Opt-in response cache for the plain chat() path — off by default,
     * byte-identical existing behavior when disabled (same bar as
     * ai.rag.queue_ingestion / ai.chat.webhook.queue). When enabled, a
     * non-streaming, non-tool-calling chat() call is cached (keyed by
     * provider + model + messages + temperature + max tokens + system
     * prompt) via Laravel's own Cache facade, so it respects whatever
     * cache backend the host app already has configured — this package
     * never invents its own storage. Streaming (stream()) and tool-calling
     * (tools()->chat()/run()) requests are never cached: a cached
     * token-by-token stream makes no sense, and a cached tool-calling
     * response would skip whatever side effects the tool call implies.
     */
    'cache' => [
        'enabled' => (bool) env('AI_CACHE_ENABLED', false),
        'ttl'     => (int) env('AI_CACHE_TTL', 3600),
        // null = use the app's default cache store (config('cache.default')).
        'store'   => env('AI_CACHE_STORE', null),
    ],

    /**
     * Opt-in, off by default (times=0 — nobody's behavior changes on
     * upgrade, same as every other cross-cutting feature this package
     * ships). When enabled, a connection failure or a 429/5xx response
     * automatically retries (see AbstractDriver::withRetry()) before this
     * package's own ProviderException/ConnectionException is thrown — a
     * 400/401/404 etc. is never retried, since retrying a request that
     * will never succeed just adds latency for no benefit. Streaming
     * requests never retry, regardless of this setting: a partially
     * streamed response has already reached the caller, so retrying would
     * either duplicate output or require throwing it away mid-flight.
     *
     * 'times' is the total number of attempts (Laravel's own Http::retry()
     * semantics) — 2 means "try, and if that fails, try once more," not
     * 2 retries on top of the first attempt.
     */
    'retry' => [
        'times' => (int) env('AI_RETRY_TIMES', 0),
        'sleep' => (int) env('AI_RETRY_SLEEP', 1000),
    ],

    /**
     * Deliberately empty by default — AI provider pricing changes often
     * enough, and varies enough per model, that shipping a hardcoded price
     * table here would go stale and quietly misreport real spend, which is
     * worse than not estimating cost at all. AIResponse::getEstimatedCost()
     * (chat) and UsageLogger (chat + image, see 'usage_logging' below)
     * return/store null for any provider/model with no entry here rather
     * than guessing — check your provider's own current pricing page and
     * fill in rates yourself:
     *
     * 'pricing' => [
     *     'openai' => [
     *         // Chat: USD per 1,000 tokens.
     *         'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
     *         'image' => [
     *             // Flat USD per image (OpenAI's gpt-image-* pricing).
     *             'gpt-image-2' => 0.04,
     *         ],
     *     ],
     *     'together' => [
     *         'image' => [
     *             // USD per megapixel (Together's FLUX pricing) — verified
     *             // 2026-08-15 against together.ai/models/flux-1-schnell;
     *             // re-check before relying on this long-term.
     *             'black-forest-labs/FLUX.1-schnell' => ['per_mp' => 0.0027],
     *         ],
     *     ],
     * ],
     */
    'pricing' => [],

    /**
     * Opt-in, off by default — persists one row to ai_usage_logs (via
     * UsageLogger) for every chat()/generateImage() call this package's
     * drivers make, using the 'pricing' rates above to estimate cost
     * (null when no rate is configured, never a guessed number). Powers
     * the Settings page's "Usage & Costs" card. Applies package-wide, not
     * just to the bundled chat UI — any AI::provider(...)->chat() call
     * anywhere in the host app is logged the same way once this is on.
     * Requires the ai_usage_logs migration to have run; UsageLogger no-ops
     * silently (never breaks the underlying AI call) if it hasn't.
     */
    'usage_logging' => [
        'enabled' => (bool) env('AI_USAGE_LOGGING_ENABLED', false),
    ],
];
