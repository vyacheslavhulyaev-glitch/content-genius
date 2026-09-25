<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_create_content(): void
    {
        $this->postJson('/api/contents', ['title' => 'Draft', 'topic' => 'Laravel'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('contents', 0);
    }

    public function test_a_user_can_create_a_draft_with_only_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/contents', ['title' => 'Draft', 'topic' => 'Laravel'])
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('title', 'Draft')
            ->assertJsonPath('topic', 'Laravel')
            ->assertJsonPath('tone', null)
            ->assertJsonPath('length', null)
            ->assertJsonPath('metadata', null)
            ->assertJsonPath('generated_content', null)
            ->assertJsonStructure(['id', 'created_at', 'updated_at']);

        $this->assertDatabaseCount('contents', 1);
        $this->assertDatabaseHas('contents', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'title' => 'Draft',
            'topic' => 'Laravel',
            'generated_content' => null,
        ]);
    }

    public function test_optional_fields_are_validated_and_stored(): void
    {
        $user = User::factory()->create();
        $payload = [
            'title' => str_repeat('a', 255),
            'topic' => str_repeat('b', 255),
            'tone' => str_repeat('c', 255),
            'length' => str_repeat('d', 255),
            'metadata' => ['audience' => 'Developers', 'tags' => ['PHP', 'Laravel']],
        ];

        $response = $this->actingAs($user, 'web')->postJson('/api/contents', $payload)
            ->assertCreated()
            ->assertJsonFragment($payload);

        $content = Content::findOrFail($response->json('id'));
        $this->assertSame($payload, $content->only(array_keys($payload)));
        $this->assertTrue($content->user->is($user));
        $this->assertNull($content->generated_content);
    }

    public function test_optional_fields_can_be_null(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/contents', [
                'title' => 'Draft',
                'topic' => 'Laravel',
                'tone' => null,
                'length' => null,
                'metadata' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('tone', null)
            ->assertJsonPath('length', null)
            ->assertJsonPath('metadata', null);

        $this->assertDatabaseCount('contents', 1);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_creates_no_content(array $input, array $errors): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/contents', $input)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('contents', 0);
    }

    public static function invalidInputs(): array
    {
        $valid = ['title' => 'Draft', 'topic' => 'Laravel'];

        return [
            'missing required fields' => [[], ['title', 'topic']],
            'blank required fields' => [['title' => ' ', 'topic' => ''], ['title', 'topic']],
            'null required fields' => [['title' => null, 'topic' => null], ['title', 'topic']],
            'invalid string types' => [[
                'title' => [], 'topic' => [], 'tone' => [], 'length' => 100,
            ], ['title', 'topic', 'tone', 'length']],
            'oversized strings' => [[
                'title' => str_repeat('a', 256),
                'topic' => str_repeat('b', 256),
                'tone' => str_repeat('c', 256),
                'length' => str_repeat('d', 256),
            ], ['title', 'topic', 'tone', 'length']],
            'invalid metadata' => [$valid + ['metadata' => 'not an array'], ['metadata']],
        ];
    }

    public function test_supplied_owner_and_generated_content_are_ignored(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user, 'web')->postJson('/api/contents', [
            'title' => 'Draft',
            'topic' => 'Laravel',
            'user_id' => $otherUser->id,
            'generated_content' => 'Untrusted generated text',
        ])
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('generated_content', null);

        $this->assertDatabaseCount('contents', 1);
        $this->assertDatabaseHas('contents', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'generated_content' => null,
        ]);
        $this->assertDatabaseMissing('contents', ['user_id' => $otherUser->id]);
    }
}
