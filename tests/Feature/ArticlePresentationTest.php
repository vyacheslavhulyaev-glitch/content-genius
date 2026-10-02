<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Support\ArticleMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_api_exposes_formatted_markdown_without_changing_storage(): void
    {
        $markdown = "# Article title\n\n## Section\n\nA paragraph with a [guide](<https://example.com/guide>).\n\n### Details\n\n- First item\n- Second item\n\n1. Ordered item";
        $content = Content::factory()->create(['generated_content' => $markdown]);
        $before = $content->refresh()->getAttributes();
        $html = $this->actingAs($content->user, 'web')->getJson('/api/contents')->assertOk()
            ->assertJsonPath('0.generated_content', $markdown)->json('0.generated_content_html');
        foreach (['<h1>Article title</h1>', '<h2>Section</h2>', '<h3>Details</h3>', '<p>A paragraph',
            '<a href="https://example.com/guide">guide</a>', '<ul>', '<ol>', '<li>First item</li>'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertArrayNotHasKey('generated_content_html', $content->getAttributes());
    }

    public function test_provider_html_and_unsafe_links_are_never_active_html(): void
    {
        $markdown = "# Safe heading\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n"
            ."Inline <svg onload=alert(1)>unsafe</svg>.\n\n"
            .'[JS](javascript:alert(1)) [Encoded](jav&#x61;script:alert(1)) [VB](vbscript:alert(1)) '
            .'[Data](data:text/html;base64,AAAA) [Safe](https://example.com/guide)';
        $content = Content::factory()->create(['generated_content' => $markdown]);
        $html = $this->actingAs($content->user, 'web')->getJson('/api/contents')->assertOk()->json('0.generated_content_html');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertDoesNotMatchRegularExpression('/<(?:script|img|svg)\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/href="(?:javascript|vbscript|data):/i', $html);
        $this->assertStringContainsString('<a href="https://example.com/guide">Safe</a>', $html);
        $this->assertSame($markdown, $content->refresh()->generated_content);
    }

    public function test_drafts_and_legacy_plain_text_remain_supported(): void
    {
        $renderer = app(ArticleMarkdown::class);
        $this->assertNull($renderer->render(null));
        $this->assertSame("<p>Existing plain text.</p>\n", $renderer->render('Existing plain text.'));
    }
}
