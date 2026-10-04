<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ProviderCall;
use App\Services\AiPricing;
use App\Services\ContentModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeoArticleResponse;
use Tests\TestCase;

class AiDemoConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_defaults_and_environment_overrides_are_configurable(): void
    {
        $repository = Env::getRepository();
        $overrides = ['OPENAI_MODEL' => 'gpt-4o-mini-2024-07-18', 'AI_DAILY_COST_LIMIT_USD' => '0.20',
            'AI_MONTHLY_COST_LIMIT_USD' => '2.00', 'AI_DAILY_PROVIDER_CALL_LIMIT' => '60', 'AI_DAILY_TOKEN_LIMIT' => '90000',
            'AI_REQUESTS_PER_HOUR' => '7'];
        $original = [];
        try {
            foreach ($overrides as $key => $value) {
                $original[$key] = $repository->get($key);
                $repository->clear($key);
            }
            $services = require config_path('services.php');
            $usage = require config_path('ai_usage.php');
            $generation = require config_path('generation.php');
            $this->assertSame('gpt-4o-mini', $services['openai']['model']);
            $this->assertSame(0.10, $usage['budget']['daily_cost']);
            $this->assertSame(1.00, $usage['budget']['monthly_cost']);
            $this->assertSame(50, $usage['budget']['daily_calls']);
            $this->assertSame(75000, $usage['budget']['daily_tokens']);
            $this->assertSame(5, $generation['quota']['requests']);
            foreach ($overrides as $key => $value) {
                $this->assertTrue($repository->set($key, $value));
            }
            $services = require config_path('services.php');
            $usage = require config_path('ai_usage.php');
            $generation = require config_path('generation.php');
            $this->assertSame('gpt-4o-mini-2024-07-18', $services['openai']['model']);
            $this->assertSame('0.20', $usage['budget']['daily_cost']);
            $this->assertSame('2.00', $usage['budget']['monthly_cost']);
            $this->assertSame('60', $usage['budget']['daily_calls']);
            $this->assertSame('90000', $usage['budget']['daily_tokens']);
            $this->assertSame(7, $generation['quota']['requests']);
        } finally {
            foreach ($original as $key => $value) {
                $repository->clear($key);
                if ($value !== null) {
                    $repository->set($key, $value);
                }
            }
        }
    }

    public function test_mini_pricing_is_centralized_and_existing_terra_pricing_is_preserved(): void
    {
        $pricing = app(AiPricing::class);
        $rates = $pricing->rates('gpt-4o-mini');
        $this->assertSame(['input' => 0.15, 'output' => 0.60], $rates);
        $this->assertSame('0.75000000', $pricing->estimate($rates, 1000000, 1000000));
        $this->assertSame($rates, $pricing->rates('gpt-4o-mini-2024-07-18'));
        $this->assertSame(['input' => 2.00, 'output' => 12.00], $pricing->rates('gpt-5.6-terra'));
    }

    public static function languages(): array
    {
        return [['en'], ['uk'], ['de']];
    }

    #[DataProvider('languages')]
    public function test_mini_generation_and_regeneration_keep_three_accounted_calls_in_each_language(string $language): void
    {
        $this->assertSame('gpt-4o-mini', config('services.openai.model'));
        $content = Content::factory()->create(['content_language' => $language,
            'topic' => 'SEO article about casino games, gambling and sports betting']);
        $decision = CreateResponse::fake(['choices' => [['message' => ['content' => json_encode(array_fill_keys(ContentModerator::CATEGORIES, false))]]]]);
        $client = new ClientFake([$decision, SeoArticleResponse::fake(), $decision, $decision, SeoArticleResponse::fake(), $decision]);
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($content->user, 'web');
        foreach (['generate', 'regenerate'] as $operation) {
            $this->postJson("/api/contents/{$content->id}/{$operation}")->assertOk()->assertJsonPath('content.content_language', $language);
        }
        $client->chat()->assertSent(6);
        $client->chat()->assertSent(fn ($method, $parameters) => $parameters['model'] === 'gpt-4o-mini'
            && isset($parameters['response_format']['json_schema'])
            && str_contains($parameters['messages'][1]['content'], 'sports betting'));
        $this->assertSame(['gpt-4o-mini'], ProviderCall::distinct()->pluck('model')->all());
        $this->assertSame(['completed'], ProviderCall::distinct()->pluck('status')->all());
        $this->assertDatabaseCount('ai_requests', 2);
        $this->assertDatabaseCount('provider_calls', 6);
        $this->assertNotNull($content->refresh()->generated_content);
    }
}
