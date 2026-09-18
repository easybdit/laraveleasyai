# Changelog

## v2.21.0 — 2026-09-18

### 🪝 Per-step agent-loop callback (`$onStep`)

`AIProviderInterface::run()` gains a new optional 5th parameter, `?callable $onStep = null`, implemented in `AbstractDriver::run()` — the one place every driver's agent loop shares. Previously, `run()`'s return value only ever carried the *last* step's `AIResponse`; a tool-calling turn that made two or more real LLM calls had no way for a caller to observe or account for any step but the final one, silently dropping that intermediate usage.

`$onStep($response)` fires once per real LLM call the loop makes — covering every intermediate tool-calling step and the final converged (or `maxSteps`-exhausted) step alike — immediately after that step's `AIResponse` is received and before any tool call it contains executes, so a caller's accounting is never dependent on a tool succeeding, failing, or its execution time. It works identically for both streaming and non-streaming runs, since each driver's stream handler already assembles a complete `AIResponse` before control returns to the loop. Purely additive, same posture as `$onToolCall`/`$onChunk`: appended as the last parameter with a `null` default, so every existing 1-4 argument call site is unaffected.

3 new tests in `ToolCallingTest`: `$onStep` firing exactly once per LLM call with each step's own usage independently readable (and summable, including `getEstimatedCost()` via the existing config-driven pricing — nothing invented or approximated), and `$onStep` firing before that step's tool actually executes. 301/301 tests passing (6 skipped, imagick-gated).

Minor release (new backward-compatible capability): v2.21.0.

## v2.20.0 — 2026-08-15

### 📤 Public conversation share links (Phase 2 of 2)

`AI_CHAT_SHARE_ENABLED`'s Share dropdown (v2.19.0 — WhatsApp/Email, raw message text) gains a real public, read-only link for the whole conversation — this is what unlocks the two things text-only sharing genuinely couldn't do: a meaningful Facebook share (its dialog needs an actual URL, not text) and WhatsApp/Email sharing something the recipient can actually open and read, not just a pasted quote.

`ai_chat_sessions` gets a new nullable, unique `share_token` column — distinct from the existing `guest_token` (which identifies *your own browser* for ownership checks and must never leave it). Opening any message's Share menu lazily generates the link on first use (idempotent — `AIChatController::shareLink()` reuses the existing token rather than minting a new one each time, "get shareable link" UX, no separate explicit step), then all four options (WhatsApp, Email, Facebook, Copy link) point at it.

`GET /ai-chat/s/{token}` renders a new, minimal `public-share.blade.php` view — transcript only, no send box, no export/feedback/regenerate controls, `noindex` so a link someone chose to hand out deliberately doesn't also end up in Google. **Genuinely no auth or ownership check at all** on that route, by design — registered *outside* `ChatServiceProvider`'s `config('ai.chat.middleware')` group specifically so a host app's `AI_CHAT_MIDDLEWARE=auth` (which can lock down the rest of `/ai-chat`) can't accidentally lock out a link its own users explicitly generated to be public. Verified this wiring by reading the actual route registration before writing the new route, not assumed.

`POST .../sessions/{id}/share` (generate/reuse) and `DELETE .../sessions/{id}/share` (revoke) both keep the same ownership check every other session-scoped endpoint on this controller already has (`ChatIdentity::resolve()` + `isOwnedBy()`) — only the public *viewing* route skips it. Revoking isn't wired to a UI button yet (backend-only for now) — a link stays live until explicitly revoked via that endpoint.

Known limitation, honestly scoped rather than silently half-built: the public view shows message text only — attachments (images/documents) embedded in a shared conversation aren't rendered there yet.

7 new tests in `ShareLinkTest`: generating/revoking both require ownership, the public route renders with zero auth, an unknown token 404s, revoking clears the token and 404s the old link immediately, and the public view is confirmed free of send-box/export/feedback/CSRF markup. 299/299 tests passing (6 skipped, imagick-gated).

Minor release (new backward-compatible capability): v2.20.0.

## v2.19.0 — 2026-08-15

### 📤 Share a reply — WhatsApp, Email (Phase 1 of 2)

Every assistant reply now gets a **📤 Share** dropdown next to Copy/Save — opens WhatsApp's "click to chat" link or a `mailto:` draft, pre-filled with that message's text. Pure client-side, same posture as Copy/Save/Read-aloud: no new route, no migration, no external service, no API key. Reuses the exact `.export-menu`/`toggleExportMenu()` dropdown recipe already in `chat.blade.php`, adapted for a per-message context (many Share menus can exist on one page, so it can't key off a single fixed `#id` the way the one page-level export menu does — `toggleShareMenu()` finds its own dropdown via `btn.nextElementSibling` instead).

`AI_CHAT_SHARE_ENABLED` (default `true`) turns it off entirely if you don't want it — same convention as `AI_CHAT_EXPORT_ENABLED`.

Deliberately scoped to *text* sharing only, planned as the first of two phases: the codebase has no concept of a public/shareable link for a chat session at all today (confirmed via a full code search before starting), so a genuinely useful Facebook share (which needs a real URL, not raw text) and a link-based upgrade to WhatsApp/Email are a follow-up, not bundled into this release.

2 new tests in `ChatFlowTest`: the Share dropdown and its WhatsApp/Email invocations render on a reply by default, and are absent when `AI_CHAT_SHARE_ENABLED=false`. 292/292 tests passing (6 skipped, imagick-gated).

Minor release (new backward-compatible capability): v2.19.0.

## v2.18.1 — 2026-08-15

### 🐛 Fix: a failed chat turn left an orphaned message, confusing the next one

Found live: a real Ollama server had a momentary connection drop mid-reply. The turn failed as expected (an error shown to the user), but the user's message that triggered it was never paired with an assistant reply in the database — `$fullReply` stayed empty on an exception, so the save block never ran, and the shutdown-safety-net (which exists for exactly this kind of mid-stream death) also no-ops on an empty reply. The very next message then replayed that broken, unpaired-user-turn history to the model — which made an otherwise well-behaved tool-calling model (`qwen3:8b`) call the `web_search` tool for a plain "hi", because as far as it could tell the previous question was still unanswered. Verified directly: the identical prompt sent in isolation, with no broken history, correctly skipped the tool (confirmed via a raw request to the real server, tool schema included, model explicitly reasoning "No need to use any tools for this").

This wasn't just a confusing-Ollama-response issue — providers that strictly enforce alternating user/assistant roles (OpenAI, Anthropic) could plausibly reject the *next* message outright over the same gap, a worse and harder-to-diagnose failure than a merely-confused local model.

A failed turn now persists a placeholder assistant reply — the same `"⚠ {error}"` text the chat UI already renders live client-side (`resources/views/chat.blade.php`'s `'⚠ ' + d.error`), just also saved server-side now instead of vanishing the moment the page reloads. Conversation history stays correctly paired for whatever comes next, regardless of provider. The (opt-in, off by default) webhook does *not* fire for these placeholder turns — it's still only for genuine AI replies.

1 new regression test in `ChatFlowTest`: simulates a real connection failure mid-turn, confirms the SSE stream still finishes cleanly, and confirms a `⚠ `-prefixed assistant message is persisted immediately after the user's turn rather than leaving it orphaned. 290/290 tests passing (6 skipped, imagick-gated).

## v2.18.0 — 2026-08-15

### 🔎 Web search settings, manageable from the Settings UI

`AI_CHAT_TOOLS_ENABLED`, `AI_CHAT_ENABLED_TOOLS`, `AI_WEB_SEARCH_PROVIDER`, `AI_TAVILY_API_KEY`, and `AI_BRAVE_API_KEY` all required a `.env` edit and redeploy to change — every other provider-facing setting already had a Settings-page equivalent (see v2.0's Settings UI), this was the one config surface still `.env`-only.

`/ai-chat/settings` gets a new **🔎 Web Search** tab: a single on/off toggle for chat-UI tool calling, a provider select (Tavily/Brave), and a password field for each provider's key — both keys save regardless of which is currently selected, so switching providers later doesn't mean re-entering one you'd already typed in once. Same `SettingsOverlay` override mechanism as every other field on this page (immediate effect, encrypted at rest — `SettingsOverlay::isSecretKey()` already matches any key ending in `api_key`, so both `ai.agent.web_search.tavily.api_key` and `...brave.api_key` are covered with no new logic needed there), same masked-placeholder-on-redisplay behavior, same "blank clears the override, falls back to `.env` again" contract.

Doesn't touch `ai.chat.enabled_tools` itself (the allow-list of *which* built-in tools may run) — `web_search` is still the only one wired up (per the existing v1 note in `config/ai.php`), so the tab's toggle is really just `ai.chat.tools_enabled` plus the provider/key pair; a future second tool would need its own row here, not a bigger change to this one.

7 new tests in `SettingsTest`: the tab renders, enabling + saving a Tavily key overrides config immediately, the key is encrypted at rest (verified separately from provider `api_key` encryption, since web search keys go through a different save path — `updateWebSearchSettings()`, not the generic `PROVIDER_FIELDS` loop), the masked placeholder round-trips without overwriting the real key, switching to Brave saves correctly, and blanking a key deletes its override. 289/289 tests passing (6 skipped, imagick-gated).

## v2.17.0 — 2026-08-15

### 🍌 Gemini image generation ("Nano Banana")

`AI::provider('gemini')->generateImage($prompt)` — the last of the roadmap's "provider-selectable /image" work, previously blocked on actually verifying Gemini's real image-generation API rather than guessing at it. Verified live against Google's own docs (`ai.google.dev/gemini-api/docs/generate-content/image-generation`, fetched directly, cross-checked across multiple passes) rather than assumed to follow this driver's existing chat/embed conventions — two things about it are genuinely different from every other method on `GeminiDriver`:

- Authenticates with an `x-goog-api-key` header, not this driver's usual `?key=` query parameter.
- Hits the `/v1/` API base path specifically, not the `/v1beta/` base every other method uses via `config('ai.providers.gemini.url')`.

Both are followed exactly as documented rather than assumed to also work the usual way — there was no live Gemini key available in this environment to empirically confirm the query-parameter/v1beta style would also succeed against this specific endpoint, so the honestly-verified path was used instead of a convenient guess.

Gemini's image models ("Nano Banana" — `gemini-3.1-flash-image` by default, or `gemini-3.1-flash-lite-image`/`gemini-3-pro-image`/`gemini-2.5-flash-image` via `AI_GEMINI_IMAGE_MODEL`) have no hosted-URL response mode at all — every result comes back as base64 (`candidates[0].content.parts[].inlineData`), so `generateImage()` always returns a `data:image/png;base64,...` string, same contract as OpenAI's gpt-image-1 path.

`AI_GEMINI_IMAGE_ENABLED` (off by default) wires this into the chat UI's `/image`/`/img` command alongside OpenAI's and Together's own flags — `gemini` joins `AIChatController::IMAGE_PROVIDERS`, so it's picked when Gemini is the active chat provider and enabled, or used as a fallback the same way the other two already are. `image_enabled`/`image_model` added to Gemini's `SettingsController::PROVIDER_FIELDS` — same generic Settings UI machinery, no new Blade markup.

5 new tests: Gemini's data-URI response shape, its error path, its "no image part in the response" path (`ImageGenerationTest`), and the chat command actually preferring Gemini when it's the active+only enabled provider, verifying the data URI embeds directly with no attachment persisted since there's nothing external to mirror (`ImageCommandTest`). 283/283 tests passing (6 skipped, imagick-gated).

Anthropic, DeepSeek, Groq, and Ollama do not get `image_enabled` — genuinely no image-generation API exists for any of them, confirmed rather than assumed, so adding a checkbox that can never work would just be a UI lie.

## v2.16.0 — 2026-08-15

### 🖼️ The `/image` chat command is provider-selectable, not Together-only

`AI::provider('openai')->generateImage()` (dall-e-3/gpt-image-1) has worked as a plain PHP call since it shipped, but the chat UI's `/image`/`/img` command was hardcoded to `AI::provider('together')` regardless of which provider you were actually chatting with or had configured — OpenAI's own image generation was reachable from code, never from the chat window.

`config('ai.providers.openai.image_enabled')` is new (`AI_OPENAI_IMAGE_ENABLED`, off by default — same opt-in posture as Together's own flag, since image generation bills separately from chat) and now feeds the same `/image` command through a new `AIChatController::resolveImageProvider()`:

- If the chat session's **currently active provider** is image-capable (`openai` or `together`) and has its own `image_enabled` on, that's the one used — asking for an image while talking to OpenAI actually calls OpenAI, not a different provider you never picked.
- Otherwise it falls back to whichever of the other image-capable providers has `image_enabled` on — Together stays first in that fallback order, so an existing Together-only setup behaves byte-for-byte the same as before this release, no matter which provider the chat itself is using.
- If neither has it on, `/image ...` is left alone as plain chat text, same as today.

Wired into the Settings UI for free — `image_enabled`/`image_model` were added to OpenAI's existing generic `PROVIDER_FIELDS` list, so the checkbox/field render and save through the same machinery Together's already used, no new Blade markup needed.

3 new tests: active-provider preference (OpenAI wins over an also-enabled Together when OpenAI is the active chat provider), fallback to Together when the active provider can't generate images at all (Ollama), and the no-provider-enabled case staying a no-op. 279/279 tests passing (6 skipped, imagick-gated).

## v2.15.1 — 2026-08-15

### 🔧 CI: Laravel 11 leg restored to the test matrix

Laravel 11 was dropped from CI earlier this cycle after `composer require illuminate/support:11.* illuminate/http:11.* orchestra/testbench:9.*` appeared "structurally unsatisfiable" — every laravel/framework 11.x release is flagged by one or more security advisories with no 11.x-line backport, and Composer's advisory handling seemed to refuse resolving to any of them.

Re-verified live rather than assumed fixed: with this repo's existing `config.policy.advisories.block: false` / `config.audit.block-insecure: false`, the exact same `composer require ... --no-update` + `composer install` sequence the CI job runs now resolves cleanly (`laravel/framework` v11.55.1, `orchestra/testbench` v9.17.0) and the full suite passes against it — `composer audit` still reports the advisories, but nothing about install/resolution was ever actually blocked by them. Whatever produced the original failure isn't reproducible now, so the PHP 8.3 / Laravel 11 / testbench 9.* leg is back in `.github/workflows/tests.yml`.

No library code changed — CI coverage only. 276/276 tests passing (verified locally against this exact Laravel 11 / testbench 9 combination, in addition to the existing matrix).

## v2.15.0 — 2026-08-16

### 🧙 The installer now offers to install optional dependencies for you

Chat attachments and conversation export have always worked with zero forced setup — turn the flag on, or click export, and it just works once its one optional composer package is installed; until then it fails with a clear "run this command" message rather than silently. That message is good UX the first time you didn't know it was coming, but real friction for someone who already knows they want the feature and now has to go copy-paste a command they were just told about.

`php artisan laravelai:install` closes that gap — asks two new questions after picking a provider:

- **Chat attachments** — say yes and it sets `AI_CHAT_ATTACHMENTS_ENABLED=true` and runs `composer require smalot/pdfparser` for you (needed for PDF documents, in the default `allowed_docs` list). Say yes again to PDF page-image vision specifically, and it checks for the PHP `imagick` extension first — sets `AI_CHAT_PDF_VISION_ENABLED=true` only if it's actually there, otherwise warns and skips (turning the flag on without imagick would just log a warning on every upload, not do anything).
- **Conversation export** — already on by default with no flag of its own; this just offers to `composer require` whichever of `dompdf/dompdf`, `phpoffice/phpword`, `phpoffice/phpspreadsheet`, `phpoffice/phppresentation` you actually want ready to use right away.

Say no to everything and it's a complete no-op — every one of these stays exactly as opt-in as it's always been; this isn't a change to any default, only a shortcut for people who already know what they want. A failed `composer require` (no network, composer not on PATH, a version conflict) warns with the one command to run manually rather than failing the whole install — same "always leave a manual escape hatch" posture as every other step in this command.

Deliberately shells out to the *host app's own* `composer require` (via the `Illuminate\Support\Facades\Process` facade, new required dependency `illuminate/process`, part of Laravel core since 10.0) rather than vendoring a copy of any of these libraries — each is a real, independently-versioned package, and the host app's own `composer.json`/lock file is the one source of truth for what's actually compatible with it.

6 new tests covering: declining everything runs no composer command at all, attachments-on installs pdfparser, PDF vision enables only when imagick is genuinely present (environment-gated the same honest way as `PdfPageRendererTest` — skips cleanly on a machine without imagick, runs for real where it's installed), each export format is installed only when confirmed, and a failed composer command warns without failing the overall install. All 4 pre-existing installer tests updated for the two new prompts. 276/276 tests passing.

## v2.14.1 — 2026-08-16

### 🧹 Removed a dead duplicate file (`src/RAG/Console/RagIngestCommand.php`)

Flagged during a previous session as an unautoloadable copy-paste/incomplete-move leftover, not acted on until now. The real, registered `ai:rag:ingest` command has always lived at `src/Console/RagIngestCommand.php` (confirmed the only one referenced in `AIServiceProvider.php`); this second copy under `src/RAG/Console/` declared the exact same class name (`EasyAI\LaravelAI\Console\RagIngestCommand`) from a location PSR-4 could never actually resolve it from, so it was 100% dead — a stale, less-complete earlier draft (diffed against the real file to confirm no unique logic before deleting).

No behavior change for any install: the standard PSR-4 autoloader never found this file in the first place. Verified one concrete, if minor, real-world effect of removing it: `composer dump-autoload -o` (optimized/classmap autoloading, common in production) printed a "does not comply with psr-4 autoloading standard... Skipping" warning for this file on every build — gone now.

Also folds in a CI-only fix from the same batch of PRs: the `test-db-matrix` job (mysql/pgsql) was missing the Ghostscript + `imagick` setup the main `test` matrix got when PDF page-image vision (v2.13.0) shipped, so its imagick-gated tests failed there specifically with `ImagickException: Failed to read the file` instead of skipping cleanly. Fixed identically to the `test` job's own setup.

270/270 tests passing (no test changes — nothing ever exercised the dead file).

## v2.14.0 — 2026-08-16

### 🌊 Streaming the agent module's final answer

`run()`'s agent loop always made one blocking, non-streaming `chat()` call per round-trip — so a tool-using reply arrived as a single complete block once the whole loop resolved, never a live typing effect, even though a plain (no tools) reply had streamed token-by-token since v1. Flagged explicitly on the roadmap as deliberately not built (v2.7.0) because doing it right meant teaching all four drivers to reassemble a tool call from that provider's own real incremental streaming format first — not a small addition.

```php
$response = AI::provider('openai')->tools([$weather])->run(
    $messages, 5, null,
    fn (string $chunk) => print($chunk),
);
```

New 4th `run()` parameter, `$onChunk` — every step streams instead of one `chat()` call per round-trip once it's given. `hasToolCalls()`/`getToolCalls()` work exactly the same regardless of which path produced the response, so the loop keeps executing tools correctly. Omit it (or pass `null`) for `run()`'s exact previous non-streaming behavior.

Verified against each provider's own real documented (and, for Ollama, a real reported GitHub issue's) streaming format before writing a line of reassembly code — not assumed:
- **OpenAI** (+ DeepSeek/Groq/Together/Custom, inherited): `delta.tool_calls[]` fragments, keyed by `index`; `id`/`function.name` arrive complete on that call's first delta, `function.arguments` arrives as a partial JSON *string* split across many deltas — concatenated by index, decoded once the stream ends.
- **Anthropic**: `content_block_start` (id/name complete, empty `input: {}` placeholder) + one or more `content_block_delta`/`input_json_delta` events per block index, each carrying a `partial_json` string fragment — same concatenate-then-decode shape as OpenAI, different event names.
- **Gemini**: a `functionCall` part arrives *whole* in a single chunk — Gemini streams a complete response structure per SSE event, not sub-field deltas, so no fragment reassembly is needed here at all.
- **Ollama**: `message.tool_calls` also arrives whole in a single NDJSON line (`arguments` already a parsed object, not a string) — confirmed against a real reported streaming quirk (ollama/ollama#12557): the tool call lands in an earlier `done: false` line, followed by an *empty* final `done: true` line, not a gradually-built one.

The built-in chat UI's `AI_CHAT_TOOLS_ENABLED` path now passes its own SSE echo callback as `$onChunk` — a tool-enabled reply in `/ai-chat` types out token-by-token exactly like every other reply now, not as one block after the loop resolves.

6 new tests (`StreamingAgentTest.php`, one per provider plus a backward-compatibility check) using the real verified SSE/NDJSON shapes above, plus a new `ChatToolCallingTest` case locking in that the chat UI's SSE stream itself now emits several separate `text` events for one tool-using reply, not one. 270/270 tests passing (5 skip on a machine without `imagick`, unrelated to this change — see v2.13.0).

## v2.13.0 — 2026-08-16

### 🖼️ See what's *inside* a PDF — page-image vision + multi-image support

Plain text extraction (every uploaded PDF, unconditionally, since v2.0.0) is blind to a chart, diagram, scanned table, or photo embedded in a PDF — there's no text layer there to extract from. New opt-in setting closes that gap:

```env
AI_CHAT_PDF_VISION_ENABLED=true
AI_CHAT_PDF_VISION_MAX_PAGES=5
```

When on, an uploaded PDF also gets each page rendered to a PNG (`PdfPageRenderer`, via the PHP `imagick` extension — a real system-level dependency, not a composer package; needs a Ghostscript delegate for PDF support). Those page images ride along as genuine vision input the next time that PDF is attached to a message, on top of the plain text every PDF already gets regardless of this setting — no separate upload, no attachment IDs the frontend has to know about, just the one PDF the user actually picked. Off by default; a missing `imagick` when it's turned on fails clearly (upload still succeeds, extracted text still attaches, a warning is logged) rather than silently or fatally.

This needed real **multi-image vision support** first — `resolveAttachments()` previously picked at most one image per message, full stop (`MessageFormatter::withImage()`'s own signature enforced it). `withImages()` (plural) generalizes it; `withImage()` is now a one-image call to it, unchanged for every existing caller. Up to 6 images total ride along per message — a direct upload and a PDF's rendered pages can even mix together.

Deleting a PDF attachment now cascades to its rendered pages (`parent_attachment_id`) — they'd have orphaned otherwise, since the frontend never has a delete button of its own for a page it doesn't know exists.

Testing an environment-gated system dependency honestly: `PdfPageRendererTest`/`ChatAttachmentTest`'s imagick-dependent cases skip with a clear reason on a machine without it (confirmed real behavior on this session's own dev machine, which has none) and run for real in CI, where `.github/workflows/tests.yml`'s `test` matrix now installs `imagick` + Ghostscript specifically so this isn't guessed-at code. The multi-image wiring itself (`MessageFormatter`, `resolveAttachments()`) needs no real PDF rendering to verify and is fully covered on every machine.

Also fixed in passing: `smalot/pdfparser` (real PDF text extraction) was only ever in `composer.json`'s `suggest`, never `require-dev` like every other optional-feature dependency here — meaning no test had ever exercised a real PDF upload through `ChatAttachmentController` before this session's tests were the first to try. Added to `require-dev`, matching `dompdf`/`phpword`/`phpspreadsheet`/`phppresentation`.

264/264 tests passing (11 new; 5 skip on a machine without `imagick`, verified for real in CI).

## v2.12.0 — 2026-08-15

### ⬇️ Export a generated image as PNG, JPEG, or PDF

A chat reply that's *only* a generated picture (the `/image` command's output) now gets its own export buttons — ⬇ PNG, ⬇ JPEG, ⬇ PDF — instead of the generic "⬇ Save" every other reply gets, which just downloaded the raw `![prompt](url)` markdown as a `.txt` file (technically correct, not remotely what anyone wanted from an image reply).

Zero new dependencies:
- **PNG/JPEG** are re-encoded client-side through a `<canvas>` rather than linked straight to the source file — guarantees the downloaded bytes genuinely match the requested extension regardless of what the server actually stored, and works whether the image is the locally-persisted copy (v2.11.1) or, on the rare download-failure fallback, still a live external URL.
- **PDF** opens a print-only tab sized to just the picture and triggers the browser's own print dialog — "Save as PDF" is a built-in destination there on effectively every modern browser, so no PDF-writing library needed for one image.

Detection is automatic and strict: only a message whose entire content is exactly one markdown image (`^!\[...\]\(...\)$`) gets the new buttons; a reply that merely mentions or embeds a picture among other prose keeps the normal text-save behavior, unchanged.

2 new tests (`ChatFlowTest.php`) confirming a pure-image reply renders the three new buttons (and not the text-save one), and a normal text reply keeps exactly what it had before. 253/253 tests passing.

## v2.11.1 — 2026-08-15

### 🔧 Fix: `/image`-generated pictures went permanently broken after a while

The chat UI's `/image` command stored the assistant's reply as `![prompt](url)` using Together's response URL directly — a temporary pickup link, not permanent hosting. Confirmed directly: a URL from an image generated earlier in the same day already 404s. Any chat history referencing it goes broken forever, with no way to recover the image (the prompt is still there, but the picture itself is gone) — exactly what a real user hit logging back in.

Fixed by mirroring the image into this app's own attachment storage the moment it's generated — same `chat-attachments/{session}/` disk location and `/ai-chat/api/attachments/{id}` serving route a user-*uploaded* image already uses, so it's exactly as durable. Falls back to the provider's raw URL (today's behavior) if the download itself fails, so a transient network hiccup never costs the reply entirely; no-storage mode (`AI_CHAT_DISABLE_STORAGE`) is unaffected — nothing is persisted there either way, by design.

Only the built-in chat UI's `/image` command is affected — `AI::provider('together')->generateImage()` called directly from your own code is unchanged and still returns the provider's own URL, exactly as documented.

3 new tests (`ImageCommandTest.php`) covering the mirror-on-generate path, the download-failure fallback, and no-storage mode. 251/251 tests passing.

## v2.11.0 — 2026-08-15

### 📊 Persisted usage & cost tracking

`getEstimatedCost()` (v2.8) only ever answered "what did *this one* response cost?" — nothing was stored, and it never covered image generation at all. This adds a real ledger: a new "Usage & Costs" tab right next to "Providers" on the Settings page, backed by a new `ai_usage_logs` table.

Off by default (`AI_USAGE_LOGGING_ENABLED` / a checkbox on the tab itself) — a fresh install writes nothing until you turn it on. Once enabled, `UsageLogger` appends one row for every `chat()` and `generateImage()` call this package's drivers make, **package-wide, not just the bundled chat UI** — any `AI::provider(...)->chat()` call anywhere in your own code is logged the same way. Estimated cost reuses `config('ai.pricing')`, extended with a new `image` sub-key per provider supporting two rate shapes: a flat USD-per-image number (OpenAI's dall-e-3) or `['per_mp' => x]` USD-per-megapixel (Together's FLUX models, computed from the actual width×height requested). Same "null unless you've configured a real rate" contract as `getEstimatedCost()` — never a guessed number.

The tab itself shows total spend (all-time and this month), a breakdown by provider/kind, and the most recent calls — reading straight off the same table, gracefully showing an "enable this" hint if the migration hasn't run yet rather than a broken page.

Implementation notes: hooked into `AbstractDriver::chat()`'s single shared entry point (covers every provider, including a cache-hit correctly logging nothing — no new usage occurred), plus individually into `TogetherDriver`/`OpenAIDriver`'s `generateImage()` (image generation has no equivalent shared base). `UsageLogger` lives in `src/Support` (the core driver layer) and writes via `DB::table()` rather than an Eloquent model, deliberately keeping the core SDK from depending on the optional bundled chat UI's `src/Chat` namespace. Defensive throughout, same posture as `SettingsOverlay`: a logging failure never breaks the AI call it's piggybacking on.

248/248 tests passing (8 new).

## v2.10.1 — 2026-08-15

### 🔧 Fix: Together AI's default image model returned `model_not_available`

`black-forest-labs/FLUX.1-schnell-Free` — the default `image_model` for `/image`/`/img` generation — currently 400s for every account, not just misconfigured ones: `{"type": "invalid_request_error", "code": "model_not_available"}`, telling you to create a dedicated endpoint for it. Confirmed directly against Together's own model page, which lists that endpoint as "Launching soon" and states outright that it "is not available on Together's Serverless API" right now — the promotional free tier the default was written against isn't live yet.

Default changed to the regular paid `black-forest-labs/FLUX.1-schnell` — $0.0027/MP (roughly $0.003 for a 1024×1024 image), needing only a positive credit balance on the Together account rather than a dedicated endpoint. `AI_TOGETHER_IMAGE_MODEL` (or the Settings-page "Image model" field from v2.10.0) still overrides it same as before.

240/240 tests passing.

## v2.10.0 — 2026-08-15

### 🟣 Together AI image-generation settings, now editable from the admin UI

The Settings page (`/ai-chat/settings`) only ever surfaced `api_key`/`model`/`timeout` per provider. Together AI's FLUX image generation (the `/image` chat command) is gated by `config('ai.providers.together.image_enabled')`, but that flag had no field in the UI at all — an admin could save a working API key, see "Connected", and `/image` would still silently fall through to a normal text-only reply because the flag stayed false. Only `.env` could ever flip it, defeating the point of a DB-backed settings overlay for anyone managing a live install without shell access.

The Together AI card now has four more fields: **Image enabled** (checkbox), **Image model**, **Image size**, **Image steps** — saved the same way every other setting is, through `SettingsOverlay`. Checkboxes don't submit at all when left unchecked, so a lone checkbox input could never actually save "off" once turned on; paired it with a same-named hidden input ahead of it in the form so both states are always submitted and cast correctly on save.

240/240 tests passing.

## v2.9.1 — 2026-08-14

### 🔧 Fix: `chat_sessions` / `chat_messages` / `chat_attachments` renamed for namespace safety

The same real problem `2026_08_14_000002` already fixed for `projects`/`project_files` (v2.4.0) — bare, generic table names with a real chance of colliding with a host app's own tables — had three tables left unfixed: `chat_sessions`, `chat_messages` (shipped since v1.0.0), and `chat_attachments` (v2.0.0). Every other table this package creates already carries an `ai_` prefix; these three were simply missed at the time. Found while reviewing a real host app's database in phpMyAdmin and noticing the inconsistency directly.

Renamed to `ai_chat_sessions` / `ai_chat_messages` / `ai_chat_attachments`, not recreated — a fresh `Schema::create()` would silently orphan every existing install's real conversation history. `ChatSession`/`ChatMessage`/`ChatAttachment` now declare an explicit `$table` (they relied on Eloquent's convention-derived name before), and the one raw table-name reference in application code (`AIChatController`'s `session_id` validation rule, `exists:chat_sessions,id`) is fixed to match — the kind of reference a rename can silently leave broken if you only search for "am I creating the right migration" and don't grep the whole codebase for the old name.

Verified for real, not just in the test suite: ran this migration against a real MySQL database with real pre-existing conversation history (not a fresh install) — confirmed via `information_schema.KEY_COLUMN_USAGE` that the real foreign key (`chat_messages_chat_session_id_foreign`) automatically followed the rename to reference `ai_chat_sessions`, confirmed the actual row data survived untouched, and confirmed the fixed validation rule correctly rejects an invalid `session_id` with a clean `422` (proving it resolves against the renamed table — the old rule would have thrown a `QueryException` instead, referencing a table that no longer exists). Same rename-in-place pattern already proven safe across MySQL/Postgres/SQLite by `2026_08_14_000002`, now with live-database confirmation on top of that CI precedent.

240/240 tests passing (existing coverage already exercised every affected code path — passing unchanged after the rename *is* the regression proof, no new tests needed).

## v2.9.0 — 2026-08-14

### 👤 Scalable Settings-page admin access

The only way to unlock `/ai-chat/settings` used to be hand-writing `Gate::define('manage-ai-settings', fn ($user) => $user->isAdmin())` yourself — meaning every install needed its own bespoke admin check, and adding a second admin meant editing PHP and redeploying. Replaced with a real, scalable mechanism:

```bash
php artisan laravelai:make-admin your@email.com
```

Grants access instantly — no Gate to write. This package now registers its own default `manage-ai-settings` Gate (checking a new `ai_admins` table) in `ChatServiceProvider::boot()`, so a fresh install needs zero admin-access code at all. Fully backward compatible and non-breaking for every existing install: Laravel boots a host app's own `AppServiceProvider` after every package provider, so an app that already defines this Gate keeps working exactly as before — this package's default only ever applies when nothing else has claimed the ability. A regression test locks in that override behavior specifically, since the whole design depends on it.

The Settings page itself now has a "👤 Admin Access" panel — add or remove admins by email, no CLI needed for anyone after the first. The first admin is a genuine bootstrap problem (that panel is itself gated by an admin already existing), solved by the CLI command above; `php artisan laravelai:install` also offers to run it for you as part of the guided setup, so most people never need to know the detail exists at all.

`ai_admins` stores only a bare `user_id` (no foreign key, no assumption about the host app's `users` table shape) — same intentionally-loose pattern already used for `chat_sessions.user_id`. The host app's own User model is resolved dynamically via `config('auth.providers.users.model')`, never assumed to be `\App\Models\User`.

18 new tests (Gate defaults, the host-app-override regression, the CLI command's found/not-found/idempotent paths, add/remove-admin UI actions, the last-admin-removal guard, and the installer's new prompt in both the skip and enter-email paths). 240/240 passing overall.

### 🔧 Fix: `ai:rag:ingest` was never actually a real command

Found while registering the new `laravelai:make-admin` command in the same array: `RagIngestCommand` was fully implemented and documented in the README (`php artisan ai:rag:ingest storage/docs/manual.txt --source=manual`) but never once passed to `$this->commands([...])` anywhere in this package — it silently didn't exist as a runnable artisan command. Also found, while tracking this down, a duplicate copy of the same class at `src/RAG/Console/RagIngestCommand.php` declaring the wrong namespace for its file location (`EasyAI\LaravelAI\Console` instead of `EasyAI\LaravelAI\RAG\Console`) — dead, unautoloadable code left over from what looks like an incomplete file move; flagged for removal, not deleted in this pass since it wasn't part of the fix itself.

## v2.8.0 — 2026-08-14

### 🗂️ Structured output — `->format($schema)` across every provider

Closes the one real feature gap found while writing an honest comparison against Laravel's own `laravel/ai` SDK: getting the model to return actual data instead of prose was previously an Ollama-only, undocumented side effect of `->format()` (silently ignored by every other driver). Now works identically everywhere:

```php
$response = AI::provider('openai')->format($schema)->chat($messages);
$response->getStructuredData(); // ['city' => 'Paris', 'temp_c' => 22]
```

`'json'` for loose "must be valid JSON" mode, or a JSON Schema array to constrain the exact shape. Each driver maps it to its own real mechanism — OpenAI (and DeepSeek/Groq/Together/Custom, inherited) `response_format`, Gemini `responseSchema`, Ollama's native `format` param (unchanged, already worked) — and, since Anthropic has no native JSON mode, a forced tool call built transparently under the hood there, unpacked back into `getStructuredData()` rather than surfaced as a real tool call. `AIResponse::getStructuredData()`/`hasStructuredData()` are `null`/`false` for a normal response — never inferred from content that merely looks like JSON.

One honestly-documented caveat rather than a silent gap: `->format()` + `->stream()` together isn't supported on Anthropic specifically — the forced-tool-call mechanism it needs isn't decodable from a text/thinking-only stream parser, so this throws instead of returning empty content.

10 new tests (all 5 chat-capable providers + the "never invents structured data" regression + the Anthropic stream guard). 196/196 passing overall.

### 🧬 Cross-provider embeddings — `->embed()` beyond Ollama

The other real gap from that same comparison pass: RAG's whole embedding step (`AI_RAG_PROVIDER`) has been configurable since it was built, but only Ollama's driver ever actually implemented `->embed()` — every other provider inherited `AbstractDriver`'s default, which just throws. Picking `AI_RAG_PROVIDER=openai` looked supported and silently never worked.

Now real on **OpenAI, Gemini, and Together AI** (Together inherits it from the OpenAI driver, same as everything else it shares), verified against each provider's actual API reference rather than assumed — OpenAI's `/embeddings` (with results re-sorted by the response's own `index` field, not trusted as pre-ordered), Gemini's `embedContent`/`batchEmbedContents` (batch mode confirmed, from Gemini's own JSON example, to need a `model` field on *every* request item, not just once at the top level — an easy detail to get wrong from memory alone). Groq and DeepSeek don't expose an embeddings endpoint on their own APIs at all (confirmed against their docs) — calling `->embed()` on either now surfaces that provider's real error rather than the previous generic "not supported" exception, an honest difference rather than a fix. Anthropic still has no `->embed()` override — confirmed they don't offer one and explicitly point users at a third-party (Voyage AI), which is a different vendor entirely, outside this package's scope.

Every driver returns the same shape as Ollama's always did — an array of vectors, one per input — so `->embed($text)[0]` keeps working exactly as RAGManager already depended on. 8 new tests (OpenAI single + batch-reordering + error case, Together inheritance, Gemini single + batch, the Anthropic non-support regression, and RAG's `ingest()` actually working end-to-end through a non-Ollama provider). 204/204 passing overall.

### 🔁 Automatic retry with backoff

Found while working through this session's reliability pass: `config('ai.retry.times'/'sleep')` has existed since the very first v1.0 commit — but was never actually read anywhere in `src/`. Choosing `AI_RETRY_TIMES` did nothing at all; a transient `429`/`5xx` failed the whole call immediately, same as if the config didn't exist.

Now real, wired through one shared `AbstractDriver::withRetry()` so every driver's retry behavior is identical rather than reimplemented per driver — a connection failure or `429`/`500`/`502`/`503`/`504` retries automatically; a `400`/`401`/`404`/etc. never does, since retrying a request that's simply wrong wastes latency for no chance of a different outcome. Explicitly opt-in: the dormant config's default (`times=2`) would have silently turned retrying on for every existing install the moment it started working, breaking this package's own consistent "nobody's behavior changes on upgrade" pattern for every other cross-cutting feature (caching, queued ingestion, chat-UI tools) — default corrected to `times=0` (disabled) instead, with the original values kept as the documented example for turning it on.

```env
AI_RETRY_TIMES=2     # total attempts, not retries-on-top-of-the-first
AI_RETRY_SLEEP=1000  # milliseconds between attempts
```

Never applies to `stream()` — a response already partially forwarded to the caller can't be safely retried without duplicating output. A persistent connection failure after retries are exhausted still throws this package's own `ConnectionException` exactly as before (Laravel's `Http::retry(..., throw: false)` still throws its own `ConnectionException` on a connection-level failure regardless of that flag — confirmed against Laravel's own docs — which every driver's existing `catch (\Throwable $e)` already re-wraps, so no new error-handling code was needed for that path). 8 new tests (off-by-default regression, retry-then-succeed, non-retryable-status-not-retried, retries-exhausted-still-throws, config-default-honored, streaming-never-retries, connection-failure-still-wraps, and Ollama/Gemini coverage). 212/212 passing overall.

### 🧪 CI actually tests what the README claims now

Found while auditing this session's own PHP-version fix: CI only ever ran PHP 8.3/8.4 against Laravel 12 — PHP 8.1/8.2 and Laravel 10/13 were documented as supported but had never been exercised by a single CI run. Verified each new leg against the real, currently-published packages rather than assumed compatibility — fetched orchestra/testbench's own compatibility table plus the actual `composer.json` of testbench 8.x/11.x and laravel/framework 10.x/13.x directly from their repos to confirm exact PHP floors (testbench 8.x → `php:^8.1`, matching Laravel 10 itself; testbench 11.x → `php:^8.3` + `laravel/framework:^13.23.0`) before adding anything. `composer.json`'s own `require-dev` for `orchestra/testbench` didn't even allow `^11.0` yet — widened so a real contributor's `composer install` can actually reach Laravel 13 too, not just CI's per-leg override.

New matrix: PHP 8.1×Laravel 10, PHP 8.2×Laravel 12, PHP 8.3×Laravel 12 (existing), PHP 8.4×Laravel 12 (existing), PHP 8.3×Laravel 13, PHP 8.4×Laravel 13 — six legs, up from two. Laravel 11 stays dropped, unchanged from the prior session's finding: every 11.x release ever published is flagged by security advisories only ever fixed starting at 12.60.0/13.10.0, never backported, so `composer require orchestra/testbench:9.*` is structurally unsatisfiable regardless of anything this package's `composer.json` could do — not re-investigated here, that finding still holds.

### 🎨 OpenAI image generation

`->generateImage()` was Together-AI-only (FLUX) until now. Added for OpenAI, verified against OpenAI's own OpenAPI spec (grepped the raw schema directly rather than trusting a summarized fetch, after `platform.openai.com`'s docs pages themselves returned 403) rather than assumed: `dall-e-2`/`dall-e-3` return a hosted `url` when `response_format: 'url'` is requested (the default here); the newer GPT image models (`gpt-image-1`, `gpt-image-1-mini`, `gpt-image-1.5`) reject that parameter outright and always return `b64_json` instead — confirmed from the spec's own line, "This parameter isn't supported for the GPT image models, which always return base64-encoded images." Both paths return the same thing from this package's side — a single usable string, `data:image/png;base64,...` for the base64 case — so `generateImage()`'s contract stays identical to Together's regardless of which model is configured.

**Gemini image generation was investigated and deliberately not shipped this round.** Google's current docs describe image generation exclusively through a new "Interactions API" (`/v1beta/interactions`), a different request/response shape from the `generateContent`/`streamGenerateContent` pattern every other Gemini feature in this package (chat, thinking, tool-calling, structured output, embeddings) is built on — and one recent enough to sit outside what could be cross-checked with confidence the way everything else this session was. Rather than ship a guess against an API surface that couldn't be verified as thoroughly, this stays explicitly on the roadmap instead.

5 new tests (OpenAI url path, OpenAI base64 path, error handling, malformed-response handling, and one regression test for Together's pre-existing implementation, which had zero test coverage before this). 217/217 passing overall.

### 🎙️ Audio — speech-to-text and text-to-speech

The last verified gap from the `laravel/ai` comparison that started this session's work. `->transcribe()` (OpenAI's `/audio/transcriptions`, multipart file upload, `whisper-1` by default) and `->textToSpeech()` (`/audio/speech`, returns raw binary audio bytes — confirmed `application/octet-stream` per OpenAI's own spec, not a JSON wrapper) — both grepped directly from OpenAI's raw OpenAPI YAML after `platform.openai.com`'s own docs pages 403'd, same verification approach as this session's other API work.

Inherited by DeepSeek/Groq/Together/Custom the same as `embed()`/`generateImage()`, and checked per-provider rather than assumed from "OpenAI-compatible chat completions" alone: Groq's own docs confirm a genuinely OpenAI-shaped `/audio/transcriptions` *and* `/audio/speech` (their docs literally say "offering OpenAI-compatible endpoints"), Together has `/audio/speech` but no transcription endpoint at all, DeepSeek has neither.

6 new tests (transcribe success, missing-file guard, error handling, TTS byte-passthrough, TTS option overrides, Groq inheritance). 223/223 passing overall.

### 💵 Cost estimation

`AIResponse::getEstimatedCost()`, backed by a new `config('ai.pricing')` — deliberately shipped **empty**, not pre-filled with this package's own guess at current rates. AI pricing varies per model and changes often enough (this very session surfaced several 2026-dated models — `gpt-image-1.5`, `gemini-embedding-001`, `voyage-4` — that didn't exist as of this package's own knowledge before now) that a baked-in table would eventually misreport real spend with no signal that it had gone stale, which is worse than not estimating cost at all. Returns `null` — never an extrapolated or partial number — for any provider/model pair without a configured `['input' => ..., 'output' => ...]` rate, including when only one of the two keys is set. 4 new tests (unconfigured → null, real calculation, wrong-model → null, incomplete-rate → null). 227/227 passing overall.

### 🔧 PHP version requirement corrected: `^8.0` → `^8.1`

`composer.json` claimed PHP 8.0 support; the code has used constructor-promoted `readonly` properties (`Agent/Tool.php`, `Agent/ToolCall.php`, `Chat/Exceptions/ChatBlockedException.php`) since earlier releases — a PHP 8.1-only feature. A PHP 8.0 install was never actually going to work; it would `composer require` successfully and then hit a fatal parse error. README's Requirements table also overstated the other direction (said 8.2+, needlessly excluding 8.1) — both now correctly say 8.1+.

## v2.7.0 — 2026-08-14

### ⚡ Groq driver

A fifth OpenAI-compatible provider, alongside DeepSeek, Together AI, and custom endpoints — Groq's famously fast inference, same one-line switch as every other provider: `AI::provider('groq')->chat($messages)`. Gets tool-calling, health checks, and model listing for free by inheriting `OpenAIDriver`, same as DeepSeek/Together already did.

```env
AI_GROQ_KEY=gsk_...
AI_GROQ_MODEL=llama-3.3-70b-versatile
```

### 💾 Response caching

Opt-in (`AI_CACHE_ENABLED=true`, off by default). Identical requests — same provider, model, messages, temperature, max tokens, and system prompt — hit Laravel's cache instead of the AI API. Never applies to a streaming or tool-calling call (a cached response would defeat streaming's purpose, and could skip re-running a tool call's real side effects), so this is scoped purely to the plain `chat()` path. Uses whatever cache store the host app already has configured (`AI_CACHE_STORE` to point at a specific one, `AI_CACHE_TTL` for how long, default 1 hour).

### 🗄️ Pluggable vector-store backend for RAG

The built-in RAG search (an in-PHP cosine scan, memory-bounded since v2.3.0) is genuinely fine up to tens of thousands of chunks, but isn't a substitute for a real vector database at large scale. New `EasyAI\LaravelAI\RAG\Contracts\VectorStoreInterface` — bind your own implementation and `RAGManager` delegates `ingest()`/`search()`/`flush()`/a new `count()` to it entirely; nothing bound (the default, every existing install) keeps the exact same built-in DB-scan behavior, byte-for-byte.

Ships with one real, working built-in: `RAG\VectorStores\PgVectorStore` for Postgres + the `pgvector` extension, via raw SQL (no new Composer dependency — pgvector is a Postgres extension, not a PHP library). One-time setup SQL is documented directly in the class. Want Pinecone, Weaviate, Milvus, or anything else instead? Implement the same four-method contract.

One documented scope boundary: `searchAutoIndexed()` (the "Ask This Site" auto-indexer's prefix-matching search) isn't delegated — its multi-source prefix match doesn't fit a single-source `search()` contract, so it always uses the built-in table regardless of what's bound. Flagged clearly in both the interface's and the method's own docblocks so it's not a silent gap for anyone combining `auto_index` with a bound store.

### 🔧 Tool-calling in the built-in chat UI

The agent module (v2.5.0) was code-only until now — usable from your own code, but the built-in `/ai-chat` chat window had no way to actually use a tool. Opt-in (`AI_CHAT_TOOLS_ENABLED=true`, off by default): the chat window can now call the built-in web search tool mid-conversation, with a collapsible "🔧 Used N tools" status line (same visual pattern as the existing reasoning-model "Thinking…" indicator).

Honest tradeoff, documented in the config: a tool-enabled reply doesn't stream token-by-token the way a normal reply does — the agent module's `run()` loop is non-streaming by design (see below), so a tool-enabled turn arrives as one complete chunk once the loop resolves, not a live typing effect. Still real-time in every other sense — the request itself is no slower than it would be without streaming.

```env
AI_CHAT_TOOLS_ENABLED=true
AI_CHAT_ENABLED_TOOLS=web_search
```

### 🧪 CI now runs against MySQL and Postgres too

The test suite ran against SQLite only until now — meaning driver-specific SQL added over the last few releases (the MySQL-specific `ALTER ... MODIFY` in the v2.6.0 status-enum migration, `Schema::rename()` in the v2.4.0 Commerce work) had literally never been exercised by CI at all, only by hand against a real database. New GitHub Actions matrix job runs the full suite against real MySQL and Postgres service containers too, alongside (not replacing) the existing SQLite run. `tests/TestCase.php` picks up `DB_CONNECTION`/`DB_HOST`/etc. when set and falls back to today's in-memory SQLite when they aren't — confirmed the fallback is completely unaffected via a full local run.

### Not shipped this round, on purpose

Streaming the agent module's `run()` loop's final answer was investigated and deliberately not built: every driver's streaming handler currently only parses text/thinking deltas, never tool-call deltas — building this properly means teaching all four drivers to reassemble incremental tool-call fragments from a live stream (OpenAI's indexed argument chunks, Anthropic's `input_json_delta` blocks, etc.), which is a real, separate body of work, not a small addition to `run()`. Rather than ship a version that silently mishandles a tool call arriving mid-stream, this is staying on the roadmap as its own dedicated task.

28 new tests (Groq, response caching, vector-store delegation + regression proof, chat-UI tool calling). 186/186 passing overall.

---

## v2.6.0 — 2026-08-14

### 🚀 The rest of the scalability pass: queues, an installer, and two storage leaks closed

Rounding out this session's scalability audit — a batch of fixes and quality-of-life additions aimed squarely at "works the same for one user as it does for ten thousand."

**Queue-based RAG ingestion and webhook delivery** (both opt-in, both `false` by default — nobody's behavior changes on upgrade):

- Document ingestion (chunking + a real embedding API call per chunk) previously ran fully inline during the file-upload request — for a large document, the uploader's browser just sat there for however long every chunk took to embed, sequentially. `AI_RAG_QUEUE_INGESTION=true` hands it off to a new `IngestDocumentJob` instead; the file sits at a new `queued` status until the job completes.
- The fire-and-forget webhook after every AI reply ran synchronously *inside the SSE streaming closure*, delaying the browser's "done" moment by however long the webhook endpoint took to respond — even though its result was never shown to the user anyway. `AI_CHAT_WEBHOOK_QUEUE=true` dispatches a new `SendWebhookJob` instead.

**`php artisan laravelai:install`** — collapses the previous 5 manual setup steps (publish config, publish assets, migrate, hand-edit `.env`) into one guided interactive command: publishes everything (asks before overwriting anything that already exists), offers to run migrations, walks through picking a provider and entering its key/URL, writes the result to `.env` without ever clobbering a value you'd already set, and live-checks the connection when it can (Ollama).

**Two real storage leaks, closed:** deleting a chat session or a project cascade-deleted their database rows via the foreign key, same as always — but the actual *files* on disk (chat attachments, project documents) were never cleaned up, silently accumulating forever on every deletion. Both `deleteSession()` and `Project::destroy()` now clean up their file directories too (best-effort — a storage hiccup logs a warning rather than blocking the deletion).

**Bounded conversation-history loading:** `/ai-chat/api/stream` loaded a session's *entire* message history from the database on every single new message, just to check things like "is this the first message" or find the last reply to regenerate — a real cost for any conversation with hundreds or thousands of turns. Now bounded to the most recent `config('ai.chat.max_loaded_messages')` (default 500), ordered/limited by `id` rather than `created_at` specifically to stay correct even when several messages share an identical timestamp (routine at second-precision) — an earlier version of this fix using `created_at` for the tie-break had no guaranteed order among equal timestamps and was caught failing its own regression test before shipping.

16 new tests across all of the above (queue jobs, the installer, both GC fixes, and the bounded-history edge cases). 158/158 passing overall.

---

## v2.5.0 — 2026-08-14

### 🧰 Agent Module — tool/function calling across every provider

The biggest gap versus other AI packages: until now this was a chat wrapper, not something that could act. `AI::provider(...)->tools([...])->run($messages)` gives any provider the ability to call real PHP functions mid-conversation and keep going until it has an actual answer — one `Tool` shape (`EasyAI\LaravelAI\Agent\Tool`), translated into each provider's own wire format under the hood.

- Works identically across **OpenAI, Anthropic, Gemini, and Ollama** — plus DeepSeek, Together AI, and any custom OpenAI-compatible endpoint, which inherit it for free from the OpenAI driver.
- `run()` drives the whole loop non-streaming: sends the conversation with the tools attached, and as long as the model keeps asking to call one, executes the matching handler and feeds the result back — up to a configurable `maxSteps` (default 5) so an agent that can't converge doesn't loop forever.
- A tool handler that throws doesn't crash the run — the model sees `{"error": "..."}` and can try something else or explain the failure, same "never let one flaky piece take down the whole request" philosophy as the RAG/Commerce work.
- Found and fixed a real bug while building this: `MessageFormatter::toProviderContent()` silently **dropped** any content block whose type wasn't `text` or `image` — which would have corrupted Anthropic's `tool_use`/`tool_result` blocks on every round after the first tool call. Caught by a dedicated test asserting the actual JSON body sent to the API, not just the response handling.

### 🔍 Built-in web search tool

The one tool most agents need first. `EasyAI\LaravelAI\Agent\Tools\WebSearchTool::make()` is ready to hand straight to `->tools([...])`, backed by a pluggable `WebSearchProvider` contract — bind your own backend, or use one of two built-ins that both ship with genuinely free tiers:

- **Tavily** (default) — built specifically for AI-agent tool use, 1,000 free searches/month, no card required.
- **Brave Search API** — a solid alternative, also has a free tier.

No key configured and nothing bound? The tool degrades to a "no results / not configured" message rather than failing the whole run.

17 new tests for the agent core across all four providers (tool-call parsing, full round-trip execution, `maxSteps` enforcement, unknown-tool graceful handling) + 10 new tests for the web search providers/tool. 142/142 passing overall.

---

## v2.4.0 — 2026-08-14

### 🛍️ Commerce Assistants — schema-agnostic Product Q&A, Order Status, Ask Your Store

Three AI endpoints for a storefront, built entirely on three PHP interfaces instead of any concrete e-commerce schema — creates **zero database tables**, so it can't collide with WooCommerce, a custom Eloquent catalog, or any other package. Host apps bind their own resolver implementation; unbound endpoints return a clear `501` instead of guessing at a schema.

- `ProductResolver` / `OrderResolver` / `StoreAnalyticsResolver` contracts — implement the one(s) you need, bind in your `AppServiceProvider`.
- `POST /ai-chat/api/commerce/products/ask`, `/orders/ask`, `/store-assistant` — all reuse `ChatGuard`'s rate limiting / message-length / word-filter / prompt-injection checks.
- The Store Assistant is **fail-closed**, same pattern as the Settings UI — refused unless the host app explicitly defines `Gate::define('view-store-assistant', ...)`.
- Order status verification is delegated entirely to the resolver — a wrong email never reveals whether an order even exists.

This recovers real, tested work that had been sitting on an unmerged branch — including a correctness fix over the original attempt: the `projects`/`project_files` tables (renamed here to `ai_projects`/`ai_project_files` for the same namespace-safety reason) get a proper `Schema::rename()` migration instead of an edited-in-place original migration, since those tables already shipped in v1.4.0 and a content edit to an already-ran migration does nothing for existing installs.

12 new tests (4 parser unit tests + 8 end-to-end commerce tests against fake in-memory resolvers) — 115/115 passing overall.

---

## v2.3.0 — 2026-08-14

### ⚡ RAG search no longer loads the entire corpus into memory

`RAGManager::search()`, `searchAutoIndexed()`, and `countMatches()` all loaded every matching row — including every row's full embedding vector — via `->get()` before scoring or counting. Harmless at a few hundred chunks, a real problem as a knowledge base grows: RAG usage compounds with every document ingested, unlike most other tables in this package.

- All three now scan in bounded batches (`chunkById(500, ...)`), keeping only the running top-K scored results in memory instead of the whole corpus. Still an O(corpus) *scan* — there's no ANN index, by design, since that's what keeps this dependency-free by default — just no longer an O(corpus) *memory allocation*.
- New `config('ai.rag.max_scan_rows')` (default 50,000) caps how much a single search will scan before returning whatever it found, logging a warning rather than letting one query run unbounded. A slower-than-ideal answer beats a request that never returns.
- Zero test coverage existed for `RAGManager` at all before this — 6 new tests now cover ranking correctness, `top_k`, source scoping, the bounded count, and the new cap actually engaging (and *not* firing under normal corpus sizes).

---

## v2.2.0 — 2026-08-14

### 🔌 Pluggable identity resolution — for SPAs that don't use Laravel sessions

`/ai-chat` is a normal server-rendered page, so it always identified the current visitor with Laravel's standard `$request->user()` session check. That's correct for classic apps, but resolves to nobody for a real, common architecture: a Vue/React SPA whose API auth is a **Bearer token kept in the SPA's own JS state** (Sanctum/Passport personal access tokens in `localStorage`, a Pinia/Redux store, etc.) rather than a Laravel session. A plain browser navigation to `/ai-chat` never carries that token — found live on exactly this setup, every session showed `user_id = NULL` the entire time the user was genuinely signed in to the SPA.

- New `config('ai.chat.identity_resolver')` — an optional callable, `fn ($request) => $userId`, called instead of the default `$request->user()` check. Lets any app plug in whatever it actually uses: a different guard, a signed token, a custom header. A resolver that throws degrades to "guest" rather than breaking the chat request.
- New **sidebar identity indicator** — a small dot + label at the bottom of the chat sidebar showing "Signed in as {name}" (green) or "Guest session" (amber), so a misconfigured identity check is visible immediately instead of silently losing chat history. Falls back to "User #{id}" when a custom resolver is used (it only returns an id, not a display name).
- New Troubleshooting entry documents both fixes for the "sidebar says Guest even though I'm logged in" symptom — the resolver above, or bridging your SPA's Bearer token into a real Laravel session on a small `/api/session-bridge`-style endpoint (recipe included) — whichever fits your app.

### 🔒 Fixed: Projects had no owner at all

Found in the same audit: `Project::index()` returned *every* project on the install to *every* visitor — no scoping, ever. `destroy()` and every `ProjectFileController` action (list/upload/delete/reprocess files) had the same gap: any anonymous visitor could read, modify, or delete any project on a default (no-auth) install, including its uploaded documents. Zero test coverage existed for Projects at all, which is exactly how this went unnoticed.

- `projects` gets the same identity columns `chat_sessions` already had (`user_id`, `guest_token`, both indexed) via a new additive migration — a project with no recorded identity (created before this migration) stays visible to everyone, same upgrade-safety rule used everywhere else in this package.
- `ProjectController` and `ProjectFileController` now resolve and enforce ownership on every action, reusing the exact same `ChatIdentity`-based pattern as chat sessions.
- The RAG `testQuery()` admin tool now checks ownership too when targeting a specific project's source — previously any visitor could inspect any project's ingested document contents through this endpoint alone.
- `index()` on both projects and chat sessions now stops at `config('ai.chat.sidebar_limit')` (default 100, same setting) instead of loading a user's *entire* history unbounded on every single page view.

11 new tests (4 identity-resolver + 7 project-ownership) — 97/97 passing overall.

---

## v2.1.2 — 2026-08-14

### 🐛 Fixed: guest chat history never persisted (reapplied)

The guest-cookie regex fix from earlier — `ChatIdentity::resolve()` validating incoming cookies against the wrong character set (`/^[a-f0-9]{40}$/`, lowercase hex) when `ensureGuestToken()` actually mints mixed-case alphanumeric tokens via `Str::random(40)` — was written, tested, and confirmed live, but committed on a branch that got built off an old point in history and was never actually merged into `main`. Every release since then shipped with the bug still live. Reapplied cleanly: `/^[A-Za-z0-9]{40}$/`, plus its regression test (`tests/Unit/ChatIdentityTest.php`).

### 🐛 Fixed: broken "view on Packagist" link in the chat sidebar

The link at the bottom of the built-in chat UI's sidebar pointed at `packagist.org/packages/muradbdinfo/laravelai` — wrong vendor *and* wrong package name, a dead page. Now correctly points at `easybdit/laraveleasyai`, the real published package.

---

## Unreleased (docs)

- Fixed the banner graphic (`art/banner.svg`) still reading "LaravelEasyAI" — the brand became "LaravelAI" everywhere else (page title, badges, nav) a while ago; the SVG's own wordmark just hadn't been updated to match.
- Split repo/package references correctly after the GitHub repo moved (`easybdit/laraveleasyai` → `easybdit/laravelai`) without the Packagist package following: GitHub-hosted links (banner image, Actions badge, Issues link) now point at the new repo; Packagist badges, the `composer require` line, and `composer.json`'s own `"name"` stay on `easybdit/laraveleasyai` — that's genuinely where the published, actively-installed package (v2.1.1, real install history) still lives. Packagist has no in-place rename; abandoning that listing wasn't the right call here.
- Added a **[বাংলা গাইড (Bangla Guide)](https://github.com/easybdit/laravelai#-বাংলা-গাইড-bangla-guide)** section — a short, real quick-start translated to Bangla (install steps, `.env`, a code example) for readers more comfortable in Bangla than English, plus a one-click Google Translate link for the whole page into any language.
- Added a pointer to the **[EasyIT AI Chat WordPress plugin](https://wordpress.org/plugins/easyit-ai-chat/)** — the same chat system, for readers on WordPress instead of Laravel.

---

## v2.1.1 — 2026-08-13

### 🐛 Fixed: assistant replies silently lost on long-running streams

Found live: a reasoning model's reply (thinking + a long answer) can run past whatever `max_execution_time` the host's `php.ini` enforces. That fires as an **uncatchable** PHP fatal at whatever line happens to be executing — which is always somewhere inside the AI call, never in the save step that comes after it. The user watches the full reply stream to their screen, then the process dies silently: nothing gets written to the database, and the reply is gone forever on the next page reload. Confirmed against a real deployment — a request died with `Maximum execution time of 300 seconds exceeded` exactly 300 seconds after the user's message, and the assistant's already-fully-rendered reply was never in the `chat_messages` table.

- `/ai-chat/api/stream` and the `/image` generation stream now call `set_time_limit(0)` before starting — this is a long-lived SSE response by design, not a normal request, and shouldn't be capped by a general-purpose php.ini setting at all.
- Added a `register_shutdown_function` safety net that persists whatever content was generated if the script still dies unexpectedly for any *other* reason (memory limit, a proxy/web-server timeout PHP never even sees, etc.) — this closes the whole class of bug, not just the one specific trigger that was found.
- The assistant-message save is now itself wrapped in a try/catch, so a transient DB error there can no longer become an uncaught exception that kills the response mid-stream.

New regression test covers the catchable half of this (a DB-level failure on the save); the uncatchable PHP-fatal half was confirmed by reproducing it against a live server and re-running the same request after the fix. Also confirmed directly — logging `ini_get('max_execution_time')` immediately before and after the new `set_time_limit(0)` call on the same server showed `300` → `0` for that request, proving the fix actually neutralizes the exact setting that caused the original failure, not just that the retry happened to finish in time.

---

## v2.1.0 — 2026-08-13

### 📤 Multi-format conversation export (PDF, Word, Excel, PowerPoint)

Beyond the existing client-side `.txt` export, a conversation can now be downloaded server-side as PDF, Word (`.docx`), Excel (`.xlsx`), or PowerPoint (`.pptx`) from a new dropdown next to the existing export button. Each format is its own optional dependency, gracefully degrading with a clear `composer require ...` message rather than a fatal error when not installed — same pattern as `smalot/pdfparser` for RAG's PDF ingestion:

- **PDF** — `dompdf/dompdf`. Full markdown rendering (headings, lists, code blocks, bold/italic) via a new small `PdfMarkdown` converter — deliberately not a full CommonMark implementation, just enough of what AI replies actually produce, so this doesn't pull in a whole markdown package on top of dompdf.
- **Word** — `phpoffice/phpword`. Reuses the same `PdfMarkdown` output through PhpWord's own HTML importer, so message formatting isn't duplicated per format.
- **Excel** — `phpoffice/phpspreadsheet`. One row per message (#, role, timestamp, content) — the natural shape for filtering/searching a long conversation rather than a document layout.
- **PowerPoint** — `phpoffice/phppresentation`. One slide per message. Documented honestly as the odd fit of the four: slides don't paginate long text the way a document does, so this suits short-to-medium conversations best — PDF/Word read far better for a long transcript.

All four routes are ownership-checked exactly like every other session-scoped endpoint. 6 new tests verify each format actually produces valid output (real PDF/zip magic bytes), not just "didn't crash."

### 🔒 Settings encryption at rest

Provider API keys saved through the new Settings UI are now encrypted before being written to the database (Laravel's `Crypt`/`APP_KEY`), not stored in plaintext — the masked-in-the-UI guarantee was already there, this closes the matching gap at the storage layer. Corrupted/undecryptable rows (e.g. after an `APP_KEY` rotation) are skipped rather than crashing boot or leaking ciphertext into `config()`. Also audited every provider driver's `health()` method to confirm none of them can leak a credential through an exception message (all fail safe internally already) — the Settings page's test-connection endpoint now returns a fixed generic message on any resolution error as well, for defense in depth.

### 💬 Claude/ChatGPT-style message layout

User turns are now a right-aligned bubble with no avatar; assistant turns stay left-aligned, full-width, with the avatar — matching the familiar Claude.ai/ChatGPT layout instead of the previous symmetric two-column chat-log look. Pure CSS on the existing markup (`flex-direction: row-reverse` for `.msg-row.user`), so both server-rendered history and the JS-appended live message use the same rules with no HTML changes.

### ⚙️ Provider settings from the UI + auth guard

Providers/API keys could previously only be changed by editing `.env`. New opt-in `/ai-chat/settings` admin page — change the default provider, every provider's API key/model/timeout, and test each connection live, without redeploying.

- **`ai_settings` table** (new migration) is a generic key → value override store applied on top of `config()`/`.env` at boot (`SettingsOverlay`) — .env stays the source of truth for anyone who never opens the page; zero rows means zero behavior change.
- **Fail-closed by design**, independent of the main chat's own access settings: refused unless the host app defines `Gate::define('manage-ai-settings', ...)` — there is no "everyone" mode for editing API keys, same pattern as the Commerce Store Assistant.
- Secrets are **masked** in the UI (last 4 characters only) and never overwritten unless you actually type a new value; blanking a field deletes the override and falls back to `.env` again.
- **`AI_CHAT_MIDDLEWARE`** — new config for the *main chat area* separately: empty (public page) by default, set to `auth` to require login for the whole `/ai-chat` app instead of only gating individual actions.

7 new tests.

### 📱 Responsive chat UI

The built-in chat UI was desktop-only — a fixed 260px sidebar with no mobile breakpoints, genuinely broken on a phone. Now:

- Sidebar becomes an off-canvas drawer below 768px (hamburger toggle + backdrop, `Escape` or backdrop-tap to close) instead of squeezing/overflowing the page.
- Modals go full-screen on small viewports instead of a cramped fixed-width box.
- Touch-sized tap targets (inputs/send/icon buttons grow to a comfortable size) below 768px; message padding and header layout adapt down to phone widths.

### 🧠 Multi-provider "thinking" visibility

Extends Ollama's live thinking display (see below) to the other two providers with real reasoning APIs:

- **Anthropic** — extended thinking via `thinking: {type: "enabled", budget_tokens: N}`; `->think(true)` / `AI_ANTHROPIC_THINK=true`. `max_tokens` is bumped automatically to stay above `budget_tokens`, and a custom `temperature` is dropped while thinking is enabled (the API rejects both otherwise).
- **Gemini** — `generationConfig.thinkingConfig.includeThoughts`; `->think(true)` / `AI_GEMINI_THINK=true`. Reasoning parts arrive flagged `thought:true` in the same `parts` array as the answer and are routed out of both the streamed chunks and the non-streaming response content.
- **OpenAI** — not supported: its Chat Completions API (what this package uses for OpenAI) doesn't expose reasoning content for o-series models at all, by design. Would need a separate Responses API driver to ever show anything here.

Same "thinking" vs "content" chunk-type contract and reflection-based callback-arity safety as Ollama's implementation, verified with 6 new tests.

### 🧠 Reasoning-model ("thinking") visibility

Reasoning models (qwen3 and similar) stream a separate `thinking` field ahead of the real answer — often 10–30+ seconds of nothing visible at all, which reads as a hung request. Found live against a real Ollama deployment: a plain "hello" took 40 seconds end-to-end, ~22 of it silent.

- `OllamaDriver::stream()` now forwards `thinking` chunks distinctly from `content` chunks (2nd callback argument) — the built-in chat UI renders them live as a collapsible **"🧠 Thinking… Ns"** block that auto-collapses to "Thought for Ns" once the real answer starts, instead of a silent gap.
- New `->think(bool)` builder method + `AI_OLLAMA_THINK` config to skip reasoning mode entirely when you'd rather have a faster, non-reasoning response.
- Callback compatibility is arity-checked via reflection: a single-parameter callback (the documented `stream()` pattern used before this release) never receives `thinking` chunks at all, rather than silently having reasoning text merged into its content — verified by a regression test.

---

## v2.0.0 — 2026-08-13

### 🔒 Security & Trust Suite

The Laravel equivalent of `easyit-ai-chat`'s v2.0 Security Suite, config/env-driven instead of a settings database:

- **Rate limiting** — per-identity + per-IP, via Laravel's `RateLimiter` (`AI_CHAT_RATE_LIMIT_*`)
- **Access restriction** — everyone / auth / role / a named `Gate` (`AI_CHAT_ACCESS_RESTRICTION`)
- **IP blocklist**, **word filter**, **prompt-injection detection** (block or warn)
- **No-storage mode** — `AI_CHAT_DISABLE_STORAGE=true` skips all DB writes for a request
- **Math captcha** — signed, stateless, required once per fresh session
- **Abuse-alert email** on rate-limit trips (via `Mail`)
- **GDPR consent gate** — cookie-based accept banner before the chat activates
- **Lock system prompt**, **configurable message-length cap**
- **Public-safe error messages** — internal exception detail hidden from non-admins by default

### 💬 Chat UX

Stop & regenerate, message timestamps, per-message + whole-conversation export, read-aloud (TTS via `SpeechSynthesis`), voice input (`SpeechRecognition`), fullscreen mode, 👍/👎 message feedback (persisted, ownership-checked), and client-side session search.

### 🎨 Personalization & embedding

Welcome message, suggested-question chips, custom AI avatar, accent/bubble color tokens, named **bot profiles** (`config('ai.chat.bot_profiles')`), and a new embeddable **`<x-laravelai::widget />`** Blade component — a self-contained floating launcher + chat panel any host view can drop in, independent of the full `/ai-chat` app.

### 🤖 New providers

**Google Gemini**, **Together AI** (including `/image` and `/img` FLUX image generation), and named **custom OpenAI-compatible providers** (`AI::custom('lmstudio')` / `AI::provider('custom:lmstudio')`) — LM Studio, vLLM, OpenRouter, or any in-house gateway.

### 📎 Attachments & vision

Image + document uploads in chat. Documents are text-extracted and appended as context for every provider; images become vision input for OpenAI/Anthropic/Gemini via a new universal multipart message format (`MessageFormatter::withImage()` / `toProviderContent()`), with a "can't view images" fallback note for the rest.

### 📊 Analytics & webhooks

A zero-external-tracking `/ai-chat/analytics` dashboard (conversations, messages, 7-day chart, feedback stats — all from existing tables), and a fire-and-forget webhook after each AI response with an optional HMAC-SHA256 signature header.

### 🧠 RAG refinements

Full-corpus **accurate counting** for "how many X" questions (`RAGManager::countMatches()`), an admin **test-query** endpoint, **in-place reprocessing** of an uploaded file without re-uploading, and an opt-in **auto-indexer** (`config('ai.rag.auto_index')`) that keeps RAG in sync with any host-app Eloquent model on save/delete — the Laravel equivalent of "Ask This Site."

### Identity & session scoping (foundational)

Chat sessions are now scoped per identity — an authenticated user id, or a signed guest cookie (`ChatIdentity`) — mirroring how the WordPress plugin never leaks sessions across visitors. Sessions created before this migration (no identity recorded) remain visible rather than disappearing.

### Fixes

- **Routes now run inside the `web` middleware group** (`Route::middleware('web')->group(...)` in `ChatServiceProvider`) — previously `loadRoutesFrom()` registered them with no middleware at all, so `$request->session()` (used for provider switching) had no session store bound, and CSRF verification never actually ran.
- **`/ai-chat/api/stream` is now a POST**, not GET-with-a-query-string — a 4000-character message no longer risks exceeding URL length limits, and the client reads the response via `fetch()` + a manual SSE parser instead of `EventSource`, so blocked/rate-limited JSON error responses (which aren't `text/event-stream`) are actually readable instead of silently failing `onerror` with no message.

**New package files:** `src/Chat/Support/{ChatIdentity,ChatGuard,TextExtractor}.php`, `src/Chat/Exceptions/ChatBlockedException.php`, `src/Chat/Models/ChatAttachment.php`, `src/Chat/Controllers/{ChatAttachmentController,AnalyticsController}.php`, `src/Drivers/{GeminiDriver,TogetherDriver,CustomDriver}.php`, `src/RAG/AutoIndexer.php`, `resources/views/analytics.blade.php`, `resources/views/components/widget.blade.php`, plus three additive migrations (session identity, message rating, chat attachments).

---

## v1.4.0 — 2026-05-10

### 🗂️ Projects + RAG Scoping (Self-hosted Claude Projects)

A full Projects system built into the Chat UI — create knowledge bases, upload documents, and chat with RAG-powered context scoped per project. Mirrors how Claude.ai Projects works, fully self-hosted.

**New features:**
- **Projects sidebar** — create, open, and delete projects from the Chat UI
- **File manager per project** — upload `.txt`, `.md`, `.pdf` files; auto-ingested into RAG on upload
- **Scoped RAG** — `AI::rag()->source('project_5')->search($query)` retrieves only that project's documents
- **RAG auto-injection** — project chat sessions automatically prepend RAG context before streaming
- **RAG badge** — header shows `🧠 RAG ON` when chatting inside a project session
- **Project-aware sessions** — sessions linked to a project via `project_id`; normal sessions unaffected
- **Safe project delete** — deletes RAG vectors, files, and sessions cleanly
- **PDF support** — optional via `composer require smalot/pdfparser`

**Bug fixes:**
- `forgetDrivers()` after embed call — prevents `nomic-embed-text` model bleeding into chat requests
- `findOrFail()` instead of route model binding — fixes 500 error for package-namespaced models
- Explicit `->model()` on every AI call — prevents shared driver state mutation
- `ob_get_level()` check before `ob_flush()` — fixes "headers already sent" on Apache with output buffering
- `num_ctx=2048` option for qwen2 models — fixes 400 error from context size too large
- Correct migration timestamps — fixes dependency order (projects before chat_sessions FK)

**New package files:**
- `src/Chat/Models/Project.php`
- `src/Chat/Models/ProjectFile.php`
- `src/Chat/Controllers/ProjectController.php`
- `src/Chat/Controllers/ProjectFileController.php`
- `database/migrations/2026_08_05_000001_create_ai_documents_table.php`
- `database/migrations/2026_08_06_000000_create_projects_and_files_tables.php`
- `database/migrations/2026_08_06_000001_add_project_id_to_chat_sessions.php`

**Updated files:**
- `src/RAG/RAGManager.php` — `source()` scoping, `forgetDrivers()` fix, scoped `flush()`
- `src/Chat/Models/ChatSession.php` — `project_id` fillable + `project()` relation
- `src/Chat/Controllers/AIChatController.php` — RAG injection, explicit model, ob_flush fix
- `routes/chat.php` — project and file routes
- `resources/views/chat.blade.php` — Projects UI, file manager modal, RAG indicators

**RAG API additions:**
```php
AI::rag()->source('project_5')->search($query);
AI::rag()->source('project_5')->ask($question);
AI::rag()->flush('project_5');
```

---

## v1.3.0 — 2026-05-08

### 💬 Built-in Chat UI (Zero Setup)

- Built-in chat UI at `/ai-chat`
- ChatGPT-like sidebar with session management
- Full Markdown rendering with syntax-highlighted code blocks
- Streaming responses with real-time typing effect
- Live provider switcher (Ollama, OpenAI, Claude, DeepSeek)
- Database-persisted conversation history
- Auto-title on first message
- Offline-safe assets (no CDN)
- Views and assets publishable

---

## v1.2.0 — 2026-05-03

### 🧠 Built-in RAG System + Ollama Advanced Features

- `AI::rag()->ingest()`, `search()`, `ask()`, `flush()`
- No external vector DB — uses SQL database
- Artisan command: `php artisan ai:rag:ingest`
- Ollama: JSON mode, embeddings, keepAlive, model management

---

## v1.1.0 — 2026-05-02

### Laravel 12 & 13 Support

- Confirmed compatibility with Laravel 12 and 13
- Updated CI matrix PHP 8.3, 8.4

---

## v1.0.0 — 2026-05-01

### Initial Release

- Ollama, OpenAI, Anthropic, DeepSeek drivers
- Unified `AIResponse` object
- Streaming, token estimation, health check
- Laravel Facade + `ai()` helper
- Custom driver support
