<?php

namespace App\Support;

use App\Enums\ContentLanguage;

final readonly class GenerationInputs
{
    public string $title;

    public string $topic;

    public string $tone;

    public string $length;

    public array $seo;

    public function __construct(?string $title, ?string $topic, ?string $tone, ?string $length, public ContentLanguage $contentLanguage = ContentLanguage::English, array $seo = [])
    {
        $this->title = trim($title ?? '');
        $this->topic = trim($topic ?? '');
        $this->tone = trim($tone ?? '');
        $this->length = trim($length ?? '');
        $seo = SeoFields::normalize($seo);
        $this->seo = [
            'primary_keyword' => $seo['primary_keyword'] ?? '',
            'secondary_keywords' => $seo['secondary_keywords'] ?? [],
            'meta_title' => $seo['meta_title'] ?? '',
            'meta_description' => $seo['meta_description'] ?? '',
            'links' => $seo['links'] ?? [],
        ];
    }

    public function fingerprint(): string
    {
        $inputs = [$this->title, $this->topic, $this->tone, $this->length];

        // English is the legacy default: preserve existing fingerprints and staleness.
        if ($this->contentLanguage !== ContentLanguage::English) {
            $inputs[] = $this->contentLanguage->value;
        }

        // Empty SEO fields preserve fingerprints of existing multilingual drafts.
        if (array_filter($this->seo)) {
            $inputs[] = $this->seo;
        }

        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    public function languageInstruction(): string
    {
        return "The generated content must be written in {$this->contentLanguage->label()}. "
            .'The target language takes precedence over the language of the supplied draft and SEO inputs. '
            .'Write article_title, headings, body, meta_title and meta_description in the target language. '
            .'Adapt copied titles, topics and meta guidance to that language; their wording does not select the output language. '
            .'Exact supplied link anchors and proper names may remain in their original language without changing the article language.';
    }

    public function prompt(): string
    {
        $seo = [...$this->seo, 'primary_keyword' => $this->seo['primary_keyword'] ?: $this->title];

        return "Title: {$this->title}\nTopic: {$this->topic}\nTone: {$this->tone}\nLength: {$this->length}\nSEO inputs: "
            .json_encode($seo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function systemInstruction(): string
    {
        return $this->languageInstruction().' Generate a complete SEO article using the supplied draft details and SEO inputs. '
            .'Return the JSON object with article_title, article_markdown, meta_title and meta_description. '
            .'The Markdown must start with exactly one meaningful H1 matching article_title, and contain at least one meaningful H2 section and useful body paragraphs. '
            .'H3 headings are optional: use them only where structurally appropriate as subsections under an H2. Do not add unnecessary subsections to short articles. '
            .'Use the primary keyword and each secondary keyword naturally. Keep phrases unchanged when already in the target language; otherwise use natural target-language equivalents. Avoid keyword stuffing. '
            .'Insert every supplied link naturally in the relevant body paragraph, preserving its anchor and URL. Use Markdown [anchor](<URL>) syntax and escape Markdown punctuation in anchors. '
            .'Use the requested tone and article length. Plan the sections to keep the body close to the requested word count without padding; when length is absent, write approximately 800 words. '
            .'Return a concise meta_title of at most 60 characters and a meta_description of at most 160 characters, separately from the article. '
            .'Use supplied meta fields as editorial guidance, adapting them to the content language. '
            .'Keywords are generation targets; do not create a meta-keywords tag. Use Markdown, never raw HTML. '
            .'Before returning, verify the Markdown includes exactly one meaningful # heading, at least one meaningful ## heading, all supplied links and both meta fields within their character limits. '
            .$this->languageInstruction();
    }
}
