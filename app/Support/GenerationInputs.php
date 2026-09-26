<?php

namespace App\Support;

final readonly class GenerationInputs
{
    public string $title;

    public string $topic;

    public string $tone;

    public string $length;

    public function __construct(?string $title, ?string $topic, ?string $tone, ?string $length)
    {
        $this->title = trim($title ?? '');
        $this->topic = trim($topic ?? '');
        $this->tone = trim($tone ?? '');
        $this->length = trim($length ?? '');
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->title, $this->topic, $this->tone, $this->length], JSON_THROW_ON_ERROR));
    }

    public function prompt(): string
    {
        return "Title: {$this->title}\nTopic: {$this->topic}\nTone: {$this->tone}\nLength: {$this->length}";
    }
}
