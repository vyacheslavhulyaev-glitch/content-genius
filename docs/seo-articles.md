# SEO article generation

Run `php artisan migrate` to apply `2026_10_02_000000_add_seo_fields_to_contents_table.php` before using the updated API/UI. The migration adds nullable columns and leaves existing text and fingerprints intact. It performs no content backfill or external requests.

## Inputs

The Content create/edit API accepts:

| Field | Representation and limits |
| --- | --- |
| `title`, `topic` | Required draft text, up to 255 characters each. |
| `primary_keyword` | Optional text, up to 120 characters. Missing/empty values use the draft title as the generation target for compatibility with existing drafts. New UI forms require a keyword. |
| `secondary_keywords` | Up to 10 nonempty strings, up to 120 characters each. Case-insensitive duplicates and repeats of the effective primary keyword are rejected. |
| `meta_title` | Optional editorial guidance, up to 60 characters. |
| `meta_description` | Optional editorial guidance, up to 160 characters. |
| `links` | Up to 10 objects containing only `anchor` and `url`. Anchors are required and at most 120 characters. URLs are required, at most 2048 characters, and must use HTTP or HTTPS. Repeated URL values after trimming are rejected, even with different anchors. |
| `content_language` | Existing `en`, `uk`, `de` values. |
| `tone`, `length` | Existing optional text fields, up to 255 characters. `length` describes the article length, for example `800 words`. Generation defaults to approximately 800 words when omitted. |

Keyword, meta and link values are trimmed. Empty lists and null lists have the same fingerprint. Secondary keyword and link order is meaningful and included in the fingerprint. Keywords are generation targets, never a meta-keywords ranking tag.

SEO inputs are stored per Content ID. New language versions copy inputs from their source as an editable starting point; they do not copy generated title, article, meta fields or fingerprint. The selected Content row's `content_language` controls all generated article and meta text, regardless of the group primary language or the language of copied inputs. Foreign-language keyword phrases use natural equivalents in the target language; phrases already in that language remain unchanged. Exact link anchors and URLs are preserved. Edit copied keywords/anchors when different language-specific targets are desired.

## Generation output

The existing OpenAI client uses Chat Completions structured output with this JSON contract:

```json
{
  "article_title": "Article H1",
  "article_markdown": "# Article H1\n\n## Section\n\nBody with [guide](<https://example.com>).\n\n### Subsection\n\nUseful details.",
  "meta_title": "Search result title",
  "meta_description": "Search result description"
}
```

`SeoArticle` validates the response shape, nonempty strings, meta length limits, exactly one meaningful matching H1, at least one meaningful H2, body text and the presence of supplied anchor/URL links. H3 subsections are optional and should be used only where structurally appropriate. Missing H3 headings do not invalidate an article. Missing, malformed, truncated or incomplete output fails with a safe 503. No partial result is saved. Keyword usage, natural placement, tone, language and target word count are instructed through the prompt; semantic quality and exact word count are not deterministically validated.

The API returns `generated_title`, `generated_content` (the full Markdown article), `generated_meta_title` and `generated_meta_description`. Inputs and outputs are separate: generated meta fields never overwrite editorial guidance. Generated fields are not writable through draft create/edit endpoints.

The API also returns a read-only `generated_content_html` presentation field, derived from stored Markdown by the existing `league/commonmark` converter with `html_input: escape` and `allow_unsafe_links: false`. This HTML is never persisted or accepted as an input. The frontend renders this server-produced field as headings, paragraphs, lists and links, with metadata separately. Provider HTML is escaped rather than enabled; unsafe link destinations are omitted. Stored `generated_content` remains Markdown. Existing plain-text generations remain displayable, and no meta-keywords tags are created.

## Persistence and moderation

The pipeline remains ownership → stored-input validation → input moderation → generation → output moderation → transactional persistence. Input moderation receives all keywords, meta guidance and links. Output moderation receives title, Markdown and both generated meta fields together. Gambling/casino/betting remain allowed under the existing policy.

Successful generation replaces all four output fields and fingerprint atomically. Blocked content, provider errors, invalid output and persistence failures preserve previous outputs. All SEO inputs contribute to staleness and per-language summary state. The reservation's immutable snapshot ensures edits during generation remain visible and mark the completed output stale when appropriate.

## Verification

`SeoContentTest` covers API validation, duplicates, migration preservation, independent language versions and SEO staleness. `SeoGenerationTest` covers the structured contract, generation/regeneration in EN/UK/DE, whole-output moderation, failure preservation and invalid response handling. Existing transaction/provider/moderation suites use updated structured response fixtures without removing their original assertions. Frontend tests cover payloads, Add/Remove, link limits, nested validation messages, localized controls, safe output and language preservation.
