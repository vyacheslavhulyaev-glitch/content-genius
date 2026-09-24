<?php

namespace Tests\Feature;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use LogicException;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AIEndpointTest extends TestCase
{
    public function test_it_returns_generated_text_and_sends_the_exact_request(): void
    {
        config(['services.openai.model' => 'test-configured-model']);

        $client = new ClientFake([
            CreateResponse::fake([
                'choices' => [['message' => ['content' => 'Hello <Laravel>!']]],
            ]),
        ]);
        $this->app->instance(ClientContract::class, $client);

        $this->get('/ai')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertContent('Hello <Laravel>!');

        $client->chat()->assertSent(1);
        $client->chat()->assertSent(fn (string $method, array $parameters): bool => $method === 'create'
            && $parameters === [
                'model' => 'test-configured-model',
                'messages' => [['role' => 'user', 'content' => 'Hello from Laravel']],
            ]);
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_return_only_a_safe_error(Exception $exception): void
    {
        config(['app.debug' => true]);
        $this->app->instance(ClientContract::class, new ClientFake([$exception]));

        $this->get('/ai')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('X-Upstream-Secret')
            ->assertExactJson(['error' => 'AI service unavailable'])
            ->assertContent('{"error":"AI service unavailable"}');
    }

    public static function providerFailures(): array
    {
        $sensitiveText = 'Authorization: Bearer sk-test-secret-do-not-expose';
        $upstream = new Response(500, ['X-Upstream-Secret' => $sensitiveText], $sensitiveText);

        return [
            'API error' => [new ErrorException(['message' => $sensitiveText], $upstream)],
            'rate limit' => [new RateLimitException($upstream->withStatus(429))],
            'server error' => [new ServerException($upstream)],
            'transport failure' => [new TransporterException(new ConnectException(
                $sensitiveText,
                new Request('POST', 'https://api.openai.com/v1/chat/completions'),
            ))],
            'unreadable response' => [new UnserializableResponse(new JsonException($sensitiveText), $upstream)],
        ];
    }

    #[DataProvider('missingContentResponses')]
    public function test_missing_or_null_content_returns_a_safe_error(array $choices): void
    {
        $client = new ClientFake([
            CreateResponse::fake(['choices' => $choices], strategy: OverrideStrategy::Replace),
        ]);
        $this->app->instance(ClientContract::class, $client);

        $this->get('/ai')
            ->assertStatus(503)
            ->assertExactJson(['error' => 'AI service unavailable'])
            ->assertContent('{"error":"AI service unavailable"}');
    }

    public static function missingContentResponses(): array
    {
        $choice = [
            'index' => 0,
            'message' => ['role' => 'assistant'],
            'finish_reason' => 'stop',
        ];

        return [
            'no choices' => [[]],
            'missing content' => [[$choice]],
            'null content' => [[array_replace($choice, [
                'message' => ['role' => 'assistant', 'content' => null],
            ])]],
        ];
    }

    public function test_unexpected_programming_errors_are_not_hidden(): void
    {
        $this->withoutExceptionHandling();
        $this->app->instance(ClientContract::class, new ClientFake([new LogicException('Programming error')]));

        $this->expectException(LogicException::class);

        $this->get('/ai');
    }
}
