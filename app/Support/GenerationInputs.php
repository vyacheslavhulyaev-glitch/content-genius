<?php

namespace App\Support;

use App\Enums\ContentLanguage;

final readonly class GenerationInputs
{
    public string $title;

    public string $topic;

    public string $tone;

    public string $length;

    public function __construct(?string $title, ?string $topic, ?string $tone, ?string $length, public ContentLanguage $contentLanguage = ContentLanguage::English)
    {
        $this->title = trim($title ?? '');
        $this->topic = trim($topic ?? '');
        $this->tone = trim($tone ?? '');
        $this->length = trim($length ?? '');
    }

    public function fingerprint(): string
    {
        $inputs = [$this->title, $this->topic, $this->tone, $this->length];

        // English is the legacy default: preserve existing fingerprints and staleness.
        if ($this->contentLanguage !== ContentLanguage::English) {
            $inputs[] = $this->contentLanguage->value;
        }

        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    public function languageInstruction(): string
    {
        return "The generated content must be written in {$this->contentLanguage->label()}.";
    }

    public function prompt(): string
    {
        return "Title: {$this->title}\nTopic: {$this->topic}\nTone: {$this->tone}\nLength: {$this->length}";
    }
}
