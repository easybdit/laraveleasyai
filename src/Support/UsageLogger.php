<?php

namespace EasyAI\LaravelAI\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Appends one row to ai_usage_logs for every AI call, when
 * config('ai.usage_logging.enabled') is on — off by default, same opt-in
 * posture as retry/cache/pricing (see config/ai.php). Called from
 * AbstractDriver::chat() (every provider's chat()/stream()/run() funnels
 * through it) and individually from each driver's generateImage(), since
 * image generation has no shared base-class entry point the way chat()
 * does.
 *
 * Uses DB::table() rather than an Eloquent model deliberately — this class
 * lives in the core driver layer (src/Support), which never depends on
 * src/Chat (the optional bundled chat UI/admin layer); the Settings page
 * that reads this table back queries the same table name directly for the
 * same reason.
 *
 * Deliberately defensive, same posture as SettingsOverlay: a logging
 * failure (migration not run yet, DB briefly unavailable) must never break
 * the actual AI call it's piggybacking on.
 */
class UsageLogger
{
    /**
     * @param array{
     *     prompt_tokens?: int,
     *     completion_tokens?: int,
     *     image_count?: int,
     *     megapixels?: float,
     * } $data
     */
    public static function log(string $provider, string $model, string $kind, array $data = []): void
    {
        if (!config('ai.usage_logging.enabled', false)) {
            return;
        }

        try {
            if (!Schema::hasTable('ai_usage_logs')) {
                return;
            }

            DB::table('ai_usage_logs')->insert([
                'provider'          => $provider,
                'model'             => $model,
                'kind'              => $kind,
                'prompt_tokens'     => $data['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['completion_tokens'] ?? 0,
                'image_count'       => $data['image_count'] ?? 0,
                'estimated_cost'    => self::estimateCost($provider, $model, $kind, $data),
                'user_id'           => Auth::id(),
                'guest_token'       => $data['guest_token'] ?? null,
                'created_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let a logging problem take down the AI call it rode in on.
            Log::warning("laraveleasyai: usage logging failed for [{$provider}/{$model}]: {$e->getMessage()}");
        }
    }

    /**
     * USD estimate from config('ai.pricing.*') — same "null unless you've
     * configured an exact rate for this provider/model" contract as
     * AIResponse::getEstimatedCost(), extended here to also cover image
     * generation. Two rate shapes under a provider's 'image' sub-key:
     *   - a plain number    => USD per image (e.g. OpenAI's gpt-image-2)
     *   - ['per_mp' => x]   => USD per megapixel, needs $data['megapixels']
     *                          (e.g. Together's FLUX models)
     */
    private static function estimateCost(string $provider, string $model, string $kind, array $data): ?float
    {
        if ($kind === 'image') {
            $rate = config("ai.pricing.{$provider}.image.{$model}");

            if (is_array($rate) && isset($rate['per_mp']) && isset($data['megapixels'])) {
                return round($rate['per_mp'] * $data['megapixels'], 6);
            }
            if (is_numeric($rate)) {
                return round((float) $rate * max(1, $data['image_count'] ?? 1), 6);
            }
            return null;
        }

        $rate = config("ai.pricing.{$provider}.{$model}");
        if (!is_array($rate) || !isset($rate['input'], $rate['output'])) {
            return null;
        }

        return round(
            (($data['prompt_tokens'] ?? 0) / 1000 * $rate['input'])
            + (($data['completion_tokens'] ?? 0) / 1000 * $rate['output']),
            6
        );
    }
}
