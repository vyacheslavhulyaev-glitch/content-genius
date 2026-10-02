<?php

namespace App\Services;

use App\Exceptions\GenerationPurposeRejected;

class GenerationPurposeGuard
{
    public function assertAllowed(array $inputs): void
    {
        array_walk_recursive($inputs, function ($value): void {
            if (! is_string($value)) {
                return;
            }
            $text = mb_strtolower(rawurldecode($value));
            $text = preg_replace('/\p{Cf}/u', '', $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            $patterns = [
                '/\bignore\s+(?:(?:all|the)\s+)?(?:previous|prior|earlier|system|developer)\s+(?:instructions?|prompts?|rules?)\b/u',
                '/\b(?:reveal|show|print|repeat|expose|display)\b.{0,40}\b(?:system|developer)\s+(?:prompt|instructions?)\b/u',
                '/\b(?:override|disable|bypass|ignore)\b.{0,25}\b(?:moderation|content_language|target language|safety rules)\b/u',
                '/\b(?:act|behave)\s+as\s+(?:chatgpt|a general(?:-purpose)? assistant|an unrestricted assistant)\b/u',
                '/\b(?:you are now|become)\s+(?:chatgpt|an unrestricted assistant)\b/u',
                '/^(?:system|developer|assistant)\s*:|<\|im_start\|>|\[inst\]/u',
                '/(?:ігноруй|ігнорувати|забудь).{0,25}(?:попередні|системні|всі).{0,15}(?:інструкції|правила)/u',
                '/(?:покажи|розкрий|виведи).{0,25}(?:системний|системні).{0,15}(?:промпт|інструкції)/u',
                '/\b(?:ignoriere|vergiss)\b.{0,25}\b(?:vorherigen|bisherigen|system|alle)\b.{0,20}\b(?:anweisungen|regeln|prompt)\b/u',
                '/\b(?:zeige|offenbare|enthülle)\b.{0,25}\b(?:systemprompt|system-prompt|systemanweisungen)\b/u',
            ];
            // Match direct general-purpose commands, while allowing SEO articles about these topics.
            if (! preg_match('/\b(?:seo|article|blog|guide|artikel|ratgeber)\b|статт|огляд/u', $text)) {
                $patterns = [...$patterns,
                    '/^(?:write|generate|create|execute|run)\b.{0,30}\b(?:python|javascript|php|shell|sql)\s+(?:script|code|program|query)\b/u',
                    '/^(?:solve|calculate|evaluate)\s+(?:this\s+)?(?:math|equation|[0-9].*[+*=])/u',
                    '/^(?:what(?:\x{2019}|\x{0027})?s|what is)\s+the\s+(?:weather|time)\b/u',
                    '/^(?:tell me|write me|write a|compose a)\s+(?:a\s+)?(?:joke|poem|cover letter|email)\b/u',
                    '/^(?:напиши|створи|виконай).{0,30}(?:скрипт|код|програму)/u',
                    '/^(?:schreibe|erstelle|führe).{0,30}(?:skript|programm|code)/u',
                ];
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    throw new GenerationPurposeRejected('Unsupported generation purpose');
                }
            }
        });
    }
}
