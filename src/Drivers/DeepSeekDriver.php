<?php

namespace EasyAI\LaravelAI\Drivers;

/**
 * DeepSeek uses OpenAI-compatible API format.
 * Only the provider name and model listing differ.
 */
class DeepSeekDriver extends OpenAIDriver
{
    public function getProviderName(): string
    {
        return 'deepseek';
    }

    public function models(): array
    {
        // DeepSeek's /models endpoint works like OpenAI
        try {
            $url = rtrim($this->config['url'], '/') . '/models';
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withToken($this->config['api_key'])
                ->get($url);

            if (!$response->successful()) {
                // deepseek-flash is the current name; deepseek-chat is kept
                // here too since DeepSeek still routes that retired alias to
                // the current model rather than erroring on it.
                return ['deepseek-flash', 'deepseek-chat', 'deepseek-coder', 'deepseek-reasoner'];
            }

            return array_column($response->json('data', []), 'id');
        } catch (\Throwable) {
            return ['deepseek-flash', 'deepseek-chat', 'deepseek-coder', 'deepseek-reasoner'];
        }
    }
}
