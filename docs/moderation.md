# Content generation moderation

Generation and regeneration use the same pipeline in `GenerateContent`:

1. Resolve the owned Content ID and check generation eligibility under a row lock.
2. Validate the stored generation fields and reserve a pending AIRequest.
3. Classify the exact input snapshot with `ContentModerator`.
4. Call the generation provider using that snapshot and its stored content language.
5. Validate the structured SEO output and classify its title, Markdown and meta fields together.
6. Persist all generated fields, fingerprint and completed request in one transaction.

Both external classifications run outside database transactions. Pending requests prevent overlapping generation for the same Content ID. No ContentGroup or language version behavior changes.

## Policy and configuration

`config/moderation.php` defines the policy. Gambling, casino and betting are explicitly allowed. Profanity, explicit sexual content, hate/harassment and clearly dangerous or illegal instructions are blocked. The gambling allowance does not exempt independently prohibited material.

The classifier uses the existing OpenAI ClientContract with a strict boolean JSON schema, rather than relying on the moderation endpoint's categories, which do not provide a separate profanity classification. See [OpenAI structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs) for the API contract. Set `OPENAI_MODERATION_MODEL` to a Chat Completions model supporting structured outputs; the default is `gpt-4o-mini`. The existing API key is reused. A successful generation adds two classification calls and their associated latency/cost. Existing AIRequest token accounting continues to represent the generation call only.

Malformed, missing, truncated or refused classifications fail closed. External exceptions are never returned or stored. Application responses use:

| HTTP | Code | Behavior |
| --- | --- | --- |
| 422 | `moderation_input_blocked` | Generation provider is not called. |
| 422 | `moderation_output_blocked` | Generated output is discarded. |
| 503 | `moderation_unavailable` | Generation cannot proceed or be saved. |

Failed attempts retain previous generated content and its fingerprint, and mark the request failed with a fixed safe message. Blocked text is never stored in AIRequest errors or returned to the browser. The frontend maps known codes to EN/UA/DE messages and retains existing content state.

## Verification boundaries

`ModerationTest` exercises the real service with fake provider decisions, failure responses, all policy categories and multilingual ContentGroup generation. Existing generation/persistence suites isolate moderation with a mock and retain their provider and transaction assertions. Frontend tests cover error-code propagation, localized messages and preservation of content/version state.

These tests verify pipeline enforcement and policy submission, not the accuracy of a live model. Before deployment, evaluate the configured model against representative allowed gambling texts, prohibited texts, obfuscation and prompt injection in EN/UA/DE. Semantic classification is probabilistic; a strict schema guarantees response shape, not classification accuracy.
