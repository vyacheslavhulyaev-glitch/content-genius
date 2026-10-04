# AI usage, estimated cost and global protection

## Accounting boundaries

One logical `AIRequest` retains the existing pending/completed/failed Generate or Regenerate lifecycle and the per-user 5/hour quota. Each actual provider attempt has a separate `ProviderCall`, normally `input_moderation`, `generation`, then `output_moderation`. Both moderation stages currently use paid Chat Completions with the configured moderation model, not the separate OpenAI moderation endpoint.

`TrackedOpenAI` is the shared call boundary. It commits a pending reservation before calling the existing `ClientContract`, then persists provider model information, token usage, pricing snapshots, estimated USD cost and completion/failure status. Calls run outside database transactions. No prompts, generated text, provider exception messages or secrets are stored in this ledger.

Provider `completed` means a response was received; it does not mean the article passed moderation or application validation. A blocked or malformed response still incurred a provider call and is accounted before article validation/persistence. A network/provider exception records `failed` with null usage and cost. The previously generated article is preserved.

Input and output counts come from SDK `promptTokens` and `completionTokens`. When both are present and nonnegative, total tokens are their sum; otherwise an available nonnegative provider total is retained. Missing counts remain null, zero remains zero, and no token estimates are reported as actual usage. A cost requires both input and output counts plus valid pricing.

Legacy `AIRequest.tokens_used` remains generation-only and `AIRequest.cost` remains unchanged. Analytics use only `ProviderCall`; they never add logical request totals to provider totals. Historical calls before this migration cannot be reconstructed and are not backfilled or invented. Deleting content or logical requests nulls their ledger references without deleting accounting history.

## Pricing assumptions

`config/ai_usage.php` centralizes pricing. Rates are standard text USD per one million tokens, verified against official OpenAI documentation on 2026-10-02; `gpt-4o-mini` was rechecked on 2026-10-04:

| Requested model | Input | Output | Source |
| --- | --- | --- | --- |
| `gpt-5.6-terra` | $2.00 | $12.00 | [Official model pricing](https://developers.openai.com/api/docs/models/gpt-5.6-terra) |
| `gpt-4o-mini` | $0.15 | $0.60 | [Official model pricing](https://developers.openai.com/api/docs/models/gpt-4o-mini) |

The explicitly configured alias `gpt-4o-mini-2024-07-18` shares mini pricing. There is no guessed prefix matching or fallback price. Add an explicit pricing entry or verified alias for any additional model. Incomplete, negative or nonnumeric pricing is treated as unknown.

`estimated_cost = (input_tokens * input_price_per_million + output_tokens * output_price_per_million) / 1_000_000`, rounded to eight decimal USD places. Rates are snapshotted at reservation, so later config changes do not rewrite historical estimates. `model` records the requested model; `response_model` stores the provider-reported model separately. Pricing assumes the configured requested model's standard text tier.

Cached input is conservatively priced at the regular input rate. Completion usage already includes provider-reported reasoning tokens; they are not added again. Estimates exclude discounts, taxes, custom contracts, regional/tier surcharges, long-context pricing, cache writes, tools, images/audio, and non-application calls. This generator uses bounded short text inputs, standard Chat Completions, and no paid tools. Review rates when changing models or processing tiers. Estimates are safeguards and known subtotals, not invoices or financial-grade billing.

The recruiter demo defaults to `gpt-4o-mini` through `services.openai.model`, configurable with `OPENAI_MODEL`. Terra remains a supported pricing entry for explicit overrides and accounting regressions; it is not the default generation model. Mini's official cached-input rate is $0.075 per million tokens, but this ledger does not distinguish cached tokens, so reservations and estimates continue using the conservative $0.15 input rate.

## Global daily and monthly ceilings

The protection applies to all users, including admins and the shared demo account, across Content IDs, languages, Generate and Regenerate. It checks every provider stage independently using the UTC date at call reservation. A pipeline can pass input moderation and then hit a ceiling at generation or output moderation; it stops and keeps the saved article.

| Environment key | Config path | Default |
| --- | --- | --- |
| `OPENAI_MODEL` | `services.openai.model` | `gpt-4o-mini` |
| `AI_DAILY_COST_LIMIT_USD` | `ai_usage.budget.daily_cost` | 0.10 USD |
| `AI_MONTHLY_COST_LIMIT_USD` | `ai_usage.budget.monthly_cost` | 1.00 USD |
| `AI_DAILY_PROVIDER_CALL_LIMIT` | `ai_usage.budget.daily_calls` | 50 |
| `AI_DAILY_TOKEN_LIMIT` | `ai_usage.budget.daily_tokens` | 75000 |
| `AI_INPUT_TOKEN_RESERVATION_MARGIN` | `ai_usage.budget.input_token_margin` | 1024 |
| `AI_MODERATION_MAX_OUTPUT_TOKENS` | `ai_usage.moderation_max_output_tokens` | 256 |

Empty/null ceiling values disable that individual ceiling; zero blocks calls. Negative, nonnumeric or nonfinite ceilings fail closed. Keep the demo monthly ceiling enabled at 1.00 USD: it is the authoritative application monetary maximum, even when the daily ceiling is raised or disabled. The existing `AI_MAX_OUTPUT_TOKENS` controls generation's maximum completion tokens (4500 by default). Pricing lives only in `ai_usage.pricing`/`ai_usage.aliases`, currency is USD, and all period boundaries are UTC. Per-user Generate/Regenerate quota remains five logical requests per rolling hour, independently configurable.

Inside a short transaction, a write to the singleton `ai_budget_locks` row serializes global reservations on MySQL/PostgreSQL and acquires SQLite's write lock. The service sums the current UTC day's calls and the current UTC calendar month's cost using the existing indexed `budget_date`, then compares the next reservation with every enabled ceiling before inserting a pending call. The month range includes the first UTC date and excludes the first date of the next month. Monthly admission requires `monthly_actual_cost + monthly_pending_or_unknown_cost_reservations + new_conservative_reservation <= monthly_limit`. Reject when `used + next_reservation > ceiling`; an exact fit is allowed. Monetary sums are compared at the ledger's eight decimal USD places. Monthly rejection takes precedence if multiple ceilings are exhausted, providing the later reset. Provider-call count includes completed, failed and pending attempts. There are no automatic provider retries and no additional migrations for monthly protection.

For a pending or usage-unknown call, the token reservation is the UTF-8 byte size of the serialized provider request (including instructions/schema) plus the configured input margin and the hard output token cap. This is a deliberately conservative text estimate without a tokenizer package. Cost reservations use those input/output amounts and the same snapshotted prices. Once actual usage/cost is available, budget checks use it instead of its reservation, never both. Reconciliation uses the existing `TrackedOpenAI` mechanism for both periods without a second reservation ledger. Unknown usage, failed calls and interrupted processes retain conservative reservations for that UTC day and UTC calendar month; these reserves are excluded from reported actual analytics.

A call without known pricing is allowed only when both monetary ceilings are disabled. Enabling a cost ceiling while its period's history contains calls with neither known cost nor a cost reservation fails closed. Uncertainty does not imply a free call. Accounting database failures also stop the pipeline. SQLite contention may return a safe accounting-unavailable error rather than exceeding the ceiling.

Budget rejection returns HTTP 429, `ai_global_budget_exceeded`, `retry_after`, UTC `reset_at`, and `Retry-After`. Daily rejection resets at the next UTC midnight; monthly rejection resets at 00:00 UTC on the first of the next month. No provider call or provider usage record is created for that rejected stage. Unknown pricing/accounting failure returns HTTP 503, `ai_accounting_unavailable`. Both errors are localized in EN/UK/DE; budget messages include retry minutes and preserve the existing article. The admitted logical request becomes failed and retains its existing per-user quota slot; input validation/purpose/ownership rejections still do not reserve a logical request.

If a process terminates between committing a reservation and receiving/persisting its response, a pending call represents an uncertain admitted attempt, with null usage/cost. If post-call accounting cannot be persisted, the committed reservation is retained and no later provider stages run. This does not reconstruct usage that was never returned or saved. UTC attribution follows reservation time, including a call crossing midnight or a month boundary. Do not purge current-month ledger entries or reservations: removing ledger history would reset this safeguard. All paid stages, including the existing input and output Chat Completions moderation, share the monthly ceiling.

## Admin analytics

The existing admin gate continues to deny normal/demo users, including a demo with a corrupt admin flag. `/api/admin/dashboard` adds `provider_usage` with currency, UTC periods (`today`, `month`, `all_time`) and all-time operation breakdowns. Each period includes logical request status counts, provider call status counts, input/output/total token sums, estimated cost, unknown-usage call count and unknown-cost call count.

Only known counts and estimated costs are summed. If calls exist but every cost is unknown, the cost is null and renders as a dash; no calls yield an explicit zero. Mixed known/unknown costs render a known subtotal alongside unknown-call counts. Budget reserves are never displayed as actual cost. The frontend shows separate logical requests and provider calls and localized numbers/costs without exposing provider details.

## Migration and verification

Run `php artisan migrate` to apply `2026_10_02_000002_create_provider_calls_and_budget_lock.php`, which adds the provider ledger and the preseeded mutex row. It changes no Content/ContentGroup fields and does not rewrite existing AIRequest records.

Regression tests exercise all three call types, configurable demo defaults, mini pricing, per-model price snapshots, usage gaps/zero values, cost calculations, provider/storage failures, all budget ceilings, exact monthly boundary, daily/monthly interaction, UTC day/month reset, pending reservations across users, unchanged regeneration output, user quota, moderation/gambling and multilingual flow. Two PHP worker processes use a shared isolated SQLite snapshot and a release gate to verify concurrent monthly reservations cannot exceed $1. Admin tests cover periods, operation totals, unknown usage/cost, preserved deleted-record history and authorization. Frontend tests cover localized budget errors, article preservation and dashboard rendering. No live OpenAI calls are needed. Temporary `accounting-smoke-*.php` scripts and `storage/app/private/accounting-smoke-*.json` reports are excluded from Git.

## Changed files

- Configuration: `.env.example`, `config/services.php`, `config/ai_usage.php`, `.gitignore`.
- Schema/models: `database/migrations/2026_10_02_000002_create_provider_calls_and_budget_lock.php`, `app/Models/AIRequest.php`, `app/Models/ProviderCall.php`.
- Services/errors: `app/Services/AiPricing.php`, `app/Services/GlobalAiBudget.php`, `app/Services/TrackedOpenAI.php`, `app/Services/AiUsageAnalytics.php`, `app/Exceptions/AiBudgetExceeded.php`, `app/Exceptions/AiAccountingUnavailable.php`.
- Pipeline/admin: `app/Actions/GenerateContent.php`, `app/Services/ContentModerator.php`, `app/Http/Controllers/AdminDashboardController.php`.
- Frontend: `frontend/src/components/AiUsagePanel.jsx`, `frontend/src/pages/AdminPage.jsx`, `frontend/src/lib/api.js`, `frontend/src/locales/en.json`, `frontend/src/locales/uk.json`, `frontend/src/locales/de.json`.
- Backend tests: `tests/Feature/AiDemoConfigurationTest.php`, `tests/Support/reserveMonthlyBudget.php`, `tests/Feature/ProviderAccountingTest.php`, `tests/Feature/GlobalAiBudgetTest.php`, `tests/Feature/AiUsageAnalyticsTest.php`, `tests/Feature/AdminDashboardTest.php`, `tests/Feature/GenerateContentTest.php`, `tests/Feature/ModerationTest.php`, `tests/Feature/GenerationQuotaTest.php`.
- Frontend tests: `frontend/tests/adminAnalytics.test.js`, `frontend/tests/contentInteractions.test.js`, `frontend/tests/contentLocalization.test.js`.
- Documentation: `docs/ai-usage.md`.
