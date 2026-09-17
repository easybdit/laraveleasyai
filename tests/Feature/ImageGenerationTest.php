<?php

namespace EasyAI\LaravelAI\Tests\Feature;

use EasyAI\LaravelAI\Exceptions\ProviderException;
use EasyAI\LaravelAI\Facades\AI;
use EasyAI\LaravelAI\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * ->generateImage() — OpenAI's real /images/generations endpoint, verified
 * against OpenAI's own OpenAPI spec rather than assumed: dall-e-2/dall-e-3
 * return a hosted url when response_format=url is requested; the newer GPT
 * image models (gpt-image-1 etc.) reject that param entirely and always
 * return b64_json instead. Together AI's pre-existing implementation
 * (FLUX, a different request/response shape entirely) had zero test
 * coverage before this — added one regression test for it here too.
 */
class ImageGenerationTest extends TestCase
{
    public function test_openai_dalle_returns_hosted_url(): void
    {
        // dall-e-3 was removed from the API on 2026-05-12 and is no longer
        // the package's default (see gpt-image-2 default test below), but
        // the driver still supports it (and any other non-gpt-image-*
        // model) via the url-mode branch if you explicitly configure it —
        // that's what this test covers.
        config(['ai.providers.openai.image_model' => 'dall-e-3']);

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://oaidalleapi.example/generated.png']],
            ]),
        ]);

        $url = AI::provider('openai')->generateImage('a red fox in snow');

        $this->assertSame('https://oaidalleapi.example/generated.png', $url);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['model'] === 'dall-e-3'
                && $body['prompt'] === 'a red fox in snow'
                && $body['response_format'] === 'url';
        });
    }

    public function test_openai_default_image_model_is_gpt_image_2_and_returns_data_uri(): void
    {
        // No image_model override — confirms the package's actual shipped
        // default (config/ai.php), not just that gpt-image-* models work
        // when explicitly selected.
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => 'ZmFrZWJhc2U2NA==']],
            ]),
        ]);

        $result = AI::provider('openai')->generateImage('a red fox in snow');

        $this->assertSame('data:image/png;base64,ZmFrZWJhc2U2NA==', $result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['model'] === 'gpt-image-2' && !array_key_exists('response_format', $body);
        });
    }

    public function test_openai_gpt_image_model_returns_data_uri_and_omits_response_format(): void
    {
        config(['ai.providers.openai.image_model' => 'gpt-image-1']);

        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => 'ZmFrZWJhc2U2NA==']],
            ]),
        ]);

        $result = AI::provider('openai')->generateImage('a red fox in snow');

        $this->assertSame('data:image/png;base64,ZmFrZWJhc2U2NA==', $result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['model'] === 'gpt-image-1' && !array_key_exists('response_format', $body);
        });
    }

    public function test_openai_image_error_response_throws_provider_exception(): void
    {
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['error' => ['message' => 'invalid prompt']], 400),
        ]);

        $this->expectException(ProviderException::class);

        AI::provider('openai')->generateImage('a red fox in snow');
    }

    public function test_openai_image_response_missing_both_fields_throws(): void
    {
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['data' => [[]]]),
        ]);

        $this->expectException(ProviderException::class);

        AI::provider('openai')->generateImage('a red fox in snow');
    }

    public function test_together_flux_still_returns_hosted_url(): void
    {
        Http::fake([
            'api.together.xyz/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://together.example/flux-generated.png']],
            ]),
        ]);

        $url = AI::provider('together')->generateImage('a red fox in snow');

        $this->assertSame('https://together.example/flux-generated.png', $url);

        Http::assertSent(fn ($request) => $request->data()['model'] === 'black-forest-labs/FLUX.1-schnell'
            && $request->data()['prompt'] === 'a red fox in snow');
    }

    /**
     * Gemini's "Nano Banana" image models — verified against Google's own
     * docs (ai.google.dev/gemini-api/docs/generate-content/image-generation):
     * response comes back as candidates[0].content.parts[].inlineData
     * (base64 "data" + "mimeType"), no hosted-URL mode exists, so this
     * always returns a data:...;base64,... string.
     */
    public function test_gemini_nano_banana_returns_data_uri(): void
    {
        config(['ai.providers.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1/models/gemini-3.1-flash-image:generateContent' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['inlineData' => ['mimeType' => 'image/png', 'data' => 'ZmFrZWJhc2U2NA==']],
                        ],
                    ],
                ]],
            ]),
        ]);

        $result = AI::provider('gemini')->generateImage('a red fox in snow');

        $this->assertSame('data:image/png;base64,ZmFrZWJhc2U2NA==', $result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && $body['contents'][0]['parts'][0]['text'] === 'a red fox in snow'
                && $body['generationConfig']['responseModalities'] === ['TEXT', 'IMAGE'];
        });
    }

    public function test_gemini_image_error_response_throws_provider_exception(): void
    {
        config(['ai.providers.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1/models/gemini-3.1-flash-image:generateContent' => Http::response(
                ['error' => ['message' => 'invalid prompt']], 400
            ),
        ]);

        $this->expectException(ProviderException::class);

        AI::provider('gemini')->generateImage('a red fox in snow');
    }

    public function test_gemini_image_response_with_no_inline_data_throws(): void
    {
        config(['ai.providers.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/v1/models/gemini-3.1-flash-image:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'no image, sorry']]]]],
            ]),
        ]);

        $this->expectException(ProviderException::class);

        AI::provider('gemini')->generateImage('a red fox in snow');
    }
}
