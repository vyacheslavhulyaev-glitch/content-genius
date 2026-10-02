# Demo access and generation protection

## Setup

Run `php artisan migrate`, then `php artisan db:seed --class=DemoUserSeeder` (or `php artisan db:seed`). The dedicated account defaults to `demo@contentgenius.example` / `ContentGeniusDemo!2026`. These are intentionally public demo credentials, never normal/admin credentials. `DEMO_EMAIL` and `DEMO_PASSWORD` can override them. Publish only this dedicated identity when sharing the recruiter demo.

The seeder is idempotent and refuses to overwrite any existing non-demo identity. `is_demo` cannot be mass assigned; model saves force demo accounts to remain non-admin, and the admin gate denies demos even if their database admin flag is incorrect. Existing user roles and credentials are preserved.

Migration `2026_10_02_000001_add_demo_and_generation_protection.php` adds `users.is_demo`, widens `contents.topic` to text, and indexes `ai_requests(user_id, created_at)`. Rollback deliberately retains the widened topic column to avoid truncating briefs.

## Language UX

The existing login switcher uses EN/UK/DE. Empty/unsupported localStorage defaults to English. `contentgenius.ui-language` persists across reload, login and logout, independently of Content IDs and article languages. No preference cookies are added.

## Shared rolling quota

Generate and Regenerate share 5 requests per authenticated user over the preceding 3600 seconds. Configuration lives in `config/generation.php`; `AI_REQUESTS_PER_HOUR` and `AI_QUOTA_WINDOW_SECONDS` override the defaults.

The action locks the User row within the short reservation transaction, checks ownership/state and validates the immutable inputs, then checks recent AIRequest reservations and inserts the next pending reservation before committing. Provider calls happen after commit. User row locking serializes reservations across Content IDs/languages on MySQL/PostgreSQL. SQLite serializes writes and rejects conflicting writes before another provider call; contention can return a safe persistence error.

Every admitted reservation counts, including moderation blocks, provider failures and pending requests. Validation, purpose, ownership and state rejections do not reserve a slot. CRUD does not consume quota. Deleting Content does not delete quota history. The sixth valid request returns HTTP 429 with `generation_rate_limited`, `retry_after`, UTC `reset_at`, `Retry-After`, and rate-limit headers. JSON metadata is readable by the frontend without additional CORS exposed-header configuration.

Do not purge AIRequest history younger than the quota window. Visitors of the public demo share one account's quota and drafts.

## Input and output limits

Validation applies to create/edit and again to stored generation inputs. Character lengths use Unicode characters; aggregate size includes serialized JSON. Browser UX defaults mirror backend config in one module; backend overrides remain authoritative and may require updating frontend UX defaults.

| Input | Default limit |
| --- | --- |
| Title / topic | 180 / 1000 characters |
| Tone | 80 characters |
| Primary keyword | 120 characters |
| Secondary keywords | 8 entries, 80 characters each |
| Meta title / description | 60 / 160 characters |
| Links | 5 unique HTTP/HTTPS URLs |
| Anchor / URL | 80 / 1024 characters |
| Combined serialized AI inputs | 6000 characters |
| Article length | 250–1500 words, default 800 |
| Length representation | 40 characters; constrained numeric word target |
| Generation output | 4500 tokens |

These bounds support a concise SEO brief, several natural targets and contextual links while controlling per-request cost. Length accepts numeric strings, `800 words`, equivalent EN/UK/DE word forms and legacy Short/Medium/Long (250/800/1200). Free-form instructions and excessive targets are rejected. The prompt receives only the validated numeric word target. Word count remains approximate; truncation/malformed output returns a safe error and preserves previous fields.

`AI_MAX_INPUT_CHARACTERS`, `AI_MAX_ARTICLE_WORDS` and `AI_MAX_OUTPUT_TOKENS` override aggregate, word and output budgets. Generation sends `max_completion_tokens`, which includes visible and non-visible generated tokens according to [OpenAI token-counting documentation](https://developers.openai.com/api/docs/guides/token-counting). No usage analytics or cost dashboard is added.

## Purpose and prompt boundaries

`prompt`, `custom_prompt`, `system_prompt`, `messages`, `brief` and `body` request fields are prohibited. Editorial guidance belongs in the bounded topic/SEO fields.

The application supplies system instructions and sends article details as a JSON user data object. Instructions treat every controlled value as data, preserve the selected Content language, and restrict generation to the SEO JSON contract. A local guard rejects obvious instruction overrides, system prompt extraction, role delimiters and direct general-purpose commands before paid calls. Existing input/output moderation remains in place. Gambling/casino/betting are allowed.

The guard is heuristic: it cannot perfectly prevent injection or classify every intent. Obfuscated abuse may evade it; educational examples containing prohibited commands may produce false positives. Input limits, quota reservations, output budget, structured response validation and moderation provide separate protections. Markdown storage, optional H3 and the safe HTML renderer remain unchanged.

## Verification

Tests cover seeding/collision protection/admin denial, shared rolling quota/reset/user isolation/pending reservations/failure consumption, field/aggregate bounds, regeneration preservation, multilingual abuse, structured prompts/token budgets and allowed SEO/gambling. Frontend tests exercise language buttons, real login/logout handlers with fake HTTP, locale reload/content independence, bounded forms and localized quota/purpose errors.

Before sharing publicly, verify deployed config and avoid real/private content in the shared demo account. No live OpenAI calls are needed for these automated checks.

## Changed files

- Configuration and documentation: `.env.example`, `README.md`, `config/demo.php`, `config/generation.php`, `docs/demo-protection.md`, `docs/seo-articles.md`.
- Generation pipeline: `app/Actions/GenerateContent.php`, `app/Http/Controllers/ContentController.php`, `app/Http/Controllers/GenerateContentController.php`, `app/Http/Controllers/RegenerateContentController.php`, `app/Http/Requests/GenerateContentRequest.php`.
- Policies and errors: `app/Services/GenerationInputPolicy.php`, `app/Services/GenerationPurposeGuard.php`, `app/Services/GenerationQuota.php`, `app/Exceptions/GenerationPurposeRejected.php`, `app/Exceptions/GenerationRateLimited.php`.
- Input support and demo authorization: `app/Support/ArticleLength.php`, `app/Support/GenerationInputs.php`, `app/Support/SeoFields.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`.
- Database: `database/migrations/2026_10_02_000001_add_demo_and_generation_protection.php`, `database/seeders/DatabaseSeeder.php`, `database/seeders/DemoUserSeeder.php`.
- Frontend: `frontend/src/components/ContentCard.jsx`, `frontend/src/components/ContentList.jsx`, `frontend/src/components/DraftForm.jsx`, `frontend/src/components/SeoFields.jsx`, `frontend/src/lib/api.js`, `frontend/src/lib/generationLimits.js`, `frontend/src/lib/seo.js`, `frontend/src/lib/validation.js`, `frontend/src/locales/en.json`, `frontend/src/locales/uk.json`, `frontend/src/locales/de.json`.
- Backend tests: `tests/Feature/DemoAccessTest.php`, `tests/Feature/GenerationInputProtectionTest.php`, `tests/Feature/GenerationQuotaTest.php`, `tests/Feature/ContentLanguageTest.php`, `tests/Feature/CreateContentTest.php`, `tests/Feature/GenerateContentTest.php`, `tests/Feature/ManageContentTest.php`.
- Frontend tests: `frontend/tests/publicLanguage.test.js`, `frontend/tests/apiErrors.test.js`, `frontend/tests/contentInteractions.test.js`, `frontend/tests/contentLocalization.test.js`.
