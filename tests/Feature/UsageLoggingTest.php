<?php

namespace EasyAI\LaravelAI\Tests\Feature;

use EasyAI\LaravelAI\Facades\AI;
use EasyAI\LaravelAI\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * UsageLogger — the ai_usage_logs ledger behind the Settings page's
 * "Usage & Costs" tab. Off by default (config('ai.usage_logging.enabled')),
 * same opt-in posture as retry/cache/pricing; see CostEstimationTest for
 * the sibling per-response (never persisted) cost estimate this extends.
 */
class UsageLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeUser(): Authenticatable
    {
        return new class implements Authenticatable {
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 7; }
            public function getAuthPasswordName() { return 'password'; }
            public function getAuthPassword() { return 'x'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };
    }

    public function test_chat_calls_are_not_logged_when_disabled(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hi']]],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'model'   => 'gpt-4o-mini',
            ]),
        ]);

        AI::provider('openai')->model('gpt-4o-mini')->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    public function test_chat_call_is_logged_with_estimated_cost_when_enabled(): void
    {
        config([
            'ai.usage_logging.enabled'         => true,
            'ai.pricing.openai.gpt-4o-mini'    => ['input' => 0.15, 'output' => 0.60],
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hi']]],
                'usage'   => ['prompt_tokens' => 1000, 'completion_tokens' => 500],
                'model'   => 'gpt-4o-mini',
            ]),
        ]);

        AI::provider('openai')->model('gpt-4o-mini')->chat([['role' => 'user', 'content' => 'Hi']]);

        $row = DB::table('ai_usage_logs')->first();
        $this->assertNotNull($row);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-4o-mini', $row->model);
        $this->assertSame('chat', $row->kind);
        $this->assertSame(1000, $row->prompt_tokens);
        $this->assertSame(500, $row->completion_tokens);
        // 1000/1000 * 0.15 + 500/1000 * 0.60 = 0.45
        $this->assertEqualsWithDelta(0.45, $row->estimated_cost, 0.0001);
    }

    public function test_chat_call_is_logged_with_null_cost_when_no_rate_configured(): void
    {
        config(['ai.usage_logging.enabled' => true]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hi']]],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'model'   => 'gpt-4o-mini',
            ]),
        ]);

        AI::provider('openai')->model('gpt-4o-mini')->chat([['role' => 'user', 'content' => 'Hi']]);

        $row = DB::table('ai_usage_logs')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->estimated_cost);
    }

    public function test_a_cached_chat_response_is_not_logged_twice(): void
    {
        config([
            'ai.usage_logging.enabled' => true,
            'ai.cache.enabled'         => true,
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hi']]],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'model'   => 'gpt-4o-mini',
            ]),
        ]);

        $messages = [['role' => 'user', 'content' => 'Hi']];
        AI::provider('openai')->model('gpt-4o-mini')->chat($messages);
        AI::provider('openai')->model('gpt-4o-mini')->chat($messages); // served from cache

        $this->assertDatabaseCount('ai_usage_logs', 1);
    }

    public function test_together_image_generation_is_logged_with_per_megapixel_pricing(): void
    {
        config([
            'ai.usage_logging.enabled' => true,
            'ai.pricing.together.image.black-forest-labs/FLUX.1-schnell' => ['per_mp' => 0.0027],
            // Pinned explicitly rather than relying on the driver's own
            // default, which this test has no reason to depend on.
            'ai.providers.together.image_model' => 'black-forest-labs/FLUX.1-schnell',
            'ai.providers.together.image_size'  => '1024x1024',
        ]);

        Http::fake([
            'api.together.xyz/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://together.example/flux-generated.png']],
            ]),
        ]);

        AI::provider('together')->generateImage('a red fox in snow');

        $row = DB::table('ai_usage_logs')->first();
        $this->assertNotNull($row);
        $this->assertSame('together', $row->provider);
        $this->assertSame('image', $row->kind);
        $this->assertSame(1, $row->image_count);
        // 1024*1024 = 1,048,576 px = 1.048576 MP * 0.0027 = 0.0028312...
        $this->assertEqualsWithDelta(0.002831, $row->estimated_cost, 0.000001);
    }

    public function test_openai_image_generation_is_logged_with_flat_pricing(): void
    {
        config([
            'ai.usage_logging.enabled'         => true,
            'ai.pricing.openai.image.gpt-image-2' => 0.04,
        ]);

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://oaidalleapi.example/generated.png']],
            ]),
        ]);

        AI::provider('openai')->generateImage('a red fox in snow');

        $row = DB::table('ai_usage_logs')->first();
        $this->assertNotNull($row);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('image', $row->kind);
        $this->assertEqualsWithDelta(0.04, $row->estimated_cost, 0.0001);
    }

    public function test_settings_page_shows_usage_tab(): void
    {
        Gate::define('manage-ai-settings', fn () => true);
        $this->actingAs($this->fakeUser());

        $this->get('/ai-chat/settings')
            ->assertOk()
            ->assertSee('Usage &amp; Costs', false)
            ->assertSee('Enable usage', false);
    }

    public function test_usage_logging_toggle_saves_from_settings_page(): void
    {
        Gate::define('manage-ai-settings', fn () => true);
        $this->actingAs($this->fakeUser());

        $this->post('/ai-chat/settings', [
            'default_provider'       => 'ollama',
            'usage_logging_enabled'  => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('ai_settings', ['key' => 'ai.usage_logging.enabled']);

        \EasyAI\LaravelAI\Chat\Support\SettingsOverlay::apply();
        $this->assertTrue(config('ai.usage_logging.enabled'));
    }
}
