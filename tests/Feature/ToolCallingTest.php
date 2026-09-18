<?php

namespace EasyAI\LaravelAI\Tests\Feature;

use EasyAI\LaravelAI\Agent\Tool;
use EasyAI\LaravelAI\Facades\AI;
use EasyAI\LaravelAI\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Covers the agent-loop (tool/function calling) pattern for OpenAI,
 * Anthropic, and Ollama — parsing a provider's tool call into a ToolCall,
 * running AbstractDriver::run()'s round-trip loop end to end, respecting
 * maxSteps, and tolerating an unknown tool name gracefully.
 */
class ToolCallingTest extends TestCase
{
    private function weatherTool(?array &$invokedWith = null): Tool
    {
        return Tool::make(
            'get_weather',
            'Gets the current weather for a city.',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            function (array $args) use (&$invokedWith) {
                $invokedWith = $args;
                return "Sunny in {$args['city']}";
            }
        );
    }

    // ─── OpenAI ─────────────────────────────────────────────────

    public function test_openai_tool_call_is_parsed_correctly(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => [[
                            'id'       => 'call_abc123',
                            'type'     => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}'],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
                'model' => 'gpt-4o-mini',
            ]),
        ]);

        $response = AI::provider('openai')
            ->tools([$this->weatherTool()])
            ->chat([['role' => 'user', 'content' => 'Weather in Paris?']]);

        $this->assertTrue($response->hasToolCalls());
        $calls = $response->getToolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('call_abc123', $calls[0]->id);
        $this->assertSame('get_weather', $calls[0]->name);
        $this->assertSame(['city' => 'Paris'], $calls[0]->arguments);
    }

    public function test_openai_run_executes_tool_and_returns_final_answer(): void
    {
        $invokedWith = null;

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [[
                                'id'       => 'call_1',
                                'type'     => 'function',
                                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}'],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
                ])
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'It is sunny in Paris.']]],
                    'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 5],
                ]),
        ]);

        $response = AI::provider('openai')
            ->tools([$this->weatherTool($invokedWith)])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']]);

        $this->assertSame('It is sunny in Paris.', $response->getContent());
        $this->assertSame(['city' => 'Paris'], $invokedWith);
        Http::assertSentCount(2);
    }

    /**
     * run() only ever returns the LAST step's AIResponse - a tool-calling
     * turn makes two real LLM calls, each with its own real usage, and
     * only the second's was ever previously observable. $onStep exposes
     * both, letting a caller independently sum usage/cost across every
     * step rather than just the final one.
     */
    public function test_openai_on_step_fires_once_per_llm_call_with_each_steps_own_usage(): void
    {
        config(['ai.pricing.openai.gpt-4o-mini' => ['input' => 0.01, 'output' => 0.03]]);

        $invokedWith = null;

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [[
                                'id'       => 'call_1',
                                'type'     => 'function',
                                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}'],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ])
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'It is sunny in Paris.']]],
                    'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ]),
        ]);

        $steps = [];

        $response = AI::provider('openai')
            ->tools([$this->weatherTool($invokedWith)])
            ->run(
                [['role' => 'user', 'content' => 'Weather in Paris?']],
                5,
                null,
                null,
                function ($stepResponse) use (&$steps) {
                    $steps[] = $stepResponse;
                }
            );

        // Fired exactly once per real LLM call - the tool-call step and the
        // final-answer step - not zero, not more.
        $this->assertCount(2, $steps);

        // Each callback received the actual AIResponse for that step, with
        // its own real, independently readable usage - not a copy/summary.
        $this->assertSame(100, $steps[0]->getPromptTokens());
        $this->assertSame(20, $steps[0]->getCompletionTokens());
        $this->assertTrue($steps[0]->hasToolCalls());

        $this->assertSame(50, $steps[1]->getPromptTokens());
        $this->assertSame(10, $steps[1]->getCompletionTokens());
        $this->assertFalse($steps[1]->hasToolCalls());
        $this->assertSame('It is sunny in Paris.', $steps[1]->getContent());

        // A caller can independently sum both steps' usage - the total that
        // was previously inaccessible through run()'s own return value.
        $this->assertSame(150, array_sum(array_map(fn ($r) => $r->getPromptTokens(), $steps)));
        $this->assertSame(30, array_sum(array_map(fn ($r) => $r->getCompletionTokens(), $steps)));

        // Same for cost - each step's own getEstimatedCost() (null unless
        // a rate is configured, same contract as always) can be summed by
        // the caller; nothing here invents or approximates a price.
        // Step 1: 100/1000*0.01 + 20/1000*0.03 = 0.0016
        // Step 2: 50/1000*0.01 + 10/1000*0.03 = 0.0008
        $this->assertEqualsWithDelta(0.0016, $steps[0]->getEstimatedCost(), 0.0001);
        $this->assertEqualsWithDelta(0.0008, $steps[1]->getEstimatedCost(), 0.0001);
        $totalCost = array_sum(array_map(fn ($r) => $r->getEstimatedCost() ?? 0, $steps));
        $this->assertEqualsWithDelta(0.0024, $totalCost, 0.0001);

        // Tool execution and the final response are both unaffected by
        // onStep being present.
        $this->assertSame(['city' => 'Paris'], $invokedWith);
        $this->assertSame('It is sunny in Paris.', $response->getContent());
        Http::assertSentCount(2);
    }

    /**
     * $onStep must observe the raw LLM response before the loop does
     * anything else with it - specifically before that step's tool
     * actually executes, so a caller's usage accounting is never
     * order-dependent on tool execution succeeding, failing, or taking
     * any particular amount of time.
     */
    public function test_openai_on_step_fires_before_that_steps_tool_executes(): void
    {
        $callOrder = [];

        $tool = Tool::make(
            'get_weather',
            'Gets the current weather for a city.',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            function (array $args) use (&$callOrder) {
                $callOrder[] = 'tool_executed';
                return "Sunny in {$args['city']}";
            }
        );

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [[
                                'id'       => 'call_1',
                                'type'     => 'function',
                                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}'],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ])
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'It is sunny in Paris.']]],
                    'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ]),
        ]);

        AI::provider('openai')
            ->tools([$tool])
            ->run(
                [['role' => 'user', 'content' => 'Weather in Paris?']],
                5,
                null,
                null,
                function ($stepResponse) use (&$callOrder) {
                    $callOrder[] = 'on_step';
                }
            );

        // on_step for the tool-call step fires before that step's tool
        // actually runs, and on_step for the final step fires after (there
        // being no further tool to run by then) - proving $onStep observes
        // the raw response, not something interleaved with or after tool
        // execution.
        $this->assertSame(['on_step', 'tool_executed', 'on_step'], $callOrder);
    }

    public function test_openai_run_respects_max_steps_without_throwing(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => null,
                        'tool_calls' => [[
                            'id'       => 'call_1',
                            'type'     => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}'],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]),
        ]);

        $response = AI::provider('openai')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']], 2);

        $this->assertTrue($response->hasToolCalls());
        Http::assertSentCount(2);
    }

    public function test_openai_unknown_tool_name_does_not_throw(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [[
                                'id'       => 'call_1',
                                'type'     => 'function',
                                'function' => ['name' => 'not_a_real_tool', 'arguments' => '{}'],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
                ])
                ->push([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Okay, done.']]],
                    'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 5],
                ]),
        ]);

        $response = AI::provider('openai')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Do the thing.']]);

        $this->assertSame('Okay, done.', $response->getContent());
    }

    // ─── Anthropic ──────────────────────────────────────────────

    public function test_anthropic_tool_call_is_parsed_correctly(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
                ],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
                'model' => 'claude-sonnet-4-20250514',
            ]),
        ]);

        $response = AI::provider('anthropic')
            ->tools([$this->weatherTool()])
            ->chat([['role' => 'user', 'content' => 'Weather in Paris?']]);

        $this->assertTrue($response->hasToolCalls());
        $calls = $response->getToolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('toolu_123', $calls[0]->id);
        $this->assertSame('get_weather', $calls[0]->name);
        $this->assertSame(['city' => 'Paris'], $calls[0]->arguments);
    }

    public function test_anthropic_run_executes_tool_and_returns_final_answer(): void
    {
        $invokedWith = null;

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
                    ],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
                ])
                ->push([
                    'content' => [['type' => 'text', 'text' => 'It is sunny in Paris.']],
                    'usage'   => ['input_tokens' => 5, 'output_tokens' => 5],
                ]),
        ]);

        $response = AI::provider('anthropic')
            ->tools([$this->weatherTool($invokedWith)])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']]);

        $this->assertSame('It is sunny in Paris.', $response->getContent());
        $this->assertSame(['city' => 'Paris'], $invokedWith);
        Http::assertSentCount(2);
    }

    public function test_anthropic_run_respects_max_steps_without_throwing(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
                ],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $response = AI::provider('anthropic')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']], 2);

        $this->assertTrue($response->hasToolCalls());
        Http::assertSentCount(2);
    }

    public function test_anthropic_unknown_tool_name_does_not_throw(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'not_a_real_tool', 'input' => []],
                    ],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
                ])
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Okay, done.']],
                    'usage'   => ['input_tokens' => 5, 'output_tokens' => 5],
                ]),
        ]);

        $response = AI::provider('anthropic')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Do the thing.']]);

        $this->assertSame('Okay, done.', $response->getContent());
    }

    /**
     * Directly covers the MessageFormatter concern: tool_use/tool_result
     * content blocks are not text/image blocks, so toProviderContent()'s
     * translation loop must pass them through unchanged rather than
     * silently dropping them, and normalize()'s consecutive-same-role
     * merge must not attempt to string-concatenate array content.
     */
    public function test_anthropic_tool_result_message_reaches_the_api_unmangled(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
                    ],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
                ])
                ->push([
                    'content' => [['type' => 'text', 'text' => 'It is sunny in Paris.']],
                    'usage'   => ['input_tokens' => 5, 'output_tokens' => 5],
                ]),
        ]);

        AI::provider('anthropic')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $messages = $body['messages'] ?? [];

            // Find the assistant tool_use turn and the following user
            // tool_result turn — both should have survived intact as
            // arrays of content blocks, not been dropped or string-merged.
            $assistantToolUse = null;
            $userToolResult = null;
            foreach ($messages as $msg) {
                if (($msg['role'] ?? null) === 'assistant' && is_array($msg['content'] ?? null)) {
                    foreach ($msg['content'] as $block) {
                        if (($block['type'] ?? null) === 'tool_use') {
                            $assistantToolUse = $block;
                        }
                    }
                }
                if (($msg['role'] ?? null) === 'user' && is_array($msg['content'] ?? null)) {
                    foreach ($msg['content'] as $block) {
                        if (($block['type'] ?? null) === 'tool_result') {
                            $userToolResult = $block;
                        }
                    }
                }
            }

            if ($assistantToolUse === null || $userToolResult === null) {
                return false;
            }

            return $assistantToolUse['id'] === 'toolu_1'
                && $assistantToolUse['name'] === 'get_weather'
                && $userToolResult['tool_use_id'] === 'toolu_1'
                && str_contains((string) $userToolResult['content'], 'Sunny in Paris');
        });
    }

    // ─── Ollama ─────────────────────────────────────────────────

    public function test_ollama_tool_call_is_parsed_correctly(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'       => 'assistant',
                    'content'    => '',
                    'tool_calls' => [
                        ['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']]],
                    ],
                ],
                'done' => true,
            ]),
        ]);

        $response = AI::provider('ollama')
            ->tools([$this->weatherTool()])
            ->chat([['role' => 'user', 'content' => 'Weather in Paris?']]);

        $this->assertTrue($response->hasToolCalls());
        $calls = $response->getToolCalls();
        $this->assertCount(1, $calls);
        $this->assertNull($calls[0]->id);
        $this->assertSame('get_weather', $calls[0]->name);
        $this->assertSame(['city' => 'Paris'], $calls[0]->arguments);
    }

    public function test_ollama_run_executes_tool_and_returns_final_answer(): void
    {
        $invokedWith = null;

        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => '',
                        'tool_calls' => [
                            ['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']]],
                        ],
                    ],
                    'done' => true,
                ])
                ->push([
                    'message' => ['role' => 'assistant', 'content' => 'It is sunny in Paris.'],
                    'done'    => true,
                ]),
        ]);

        $response = AI::provider('ollama')
            ->tools([$this->weatherTool($invokedWith)])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']]);

        $this->assertSame('It is sunny in Paris.', $response->getContent());
        $this->assertSame(['city' => 'Paris'], $invokedWith);
        Http::assertSentCount(2);
    }

    public function test_ollama_run_respects_max_steps_without_throwing(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'       => 'assistant',
                    'content'    => '',
                    'tool_calls' => [
                        ['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Paris']]],
                    ],
                ],
                'done' => true,
            ]),
        ]);

        $response = AI::provider('ollama')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Weather in Paris?']], 2);

        $this->assertTrue($response->hasToolCalls());
        Http::assertSentCount(2);
    }

    public function test_ollama_unknown_tool_name_does_not_throw(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => '',
                        'tool_calls' => [
                            ['function' => ['name' => 'not_a_real_tool', 'arguments' => []]],
                        ],
                    ],
                    'done' => true,
                ])
                ->push([
                    'message' => ['role' => 'assistant', 'content' => 'Okay, done.'],
                    'done'    => true,
                ]),
        ]);

        $response = AI::provider('ollama')
            ->tools([$this->weatherTool()])
            ->run([['role' => 'user', 'content' => 'Do the thing.']]);

        $this->assertSame('Okay, done.', $response->getContent());
    }
}
