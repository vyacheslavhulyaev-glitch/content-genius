# Content language versions

A `ContentGroup` is one logical article owned by a user. Each `Content` belongs to
one group and stores one localized version, including all inputs, generated text,
metadata, and generation fingerprint. AI requests continue to reference Content IDs.
`User::contentGroups()`, `ContentGroup::user()`, `ContentGroup::contents()`, and
`Content::contentGroup()` expose the relationships.

## Rules and API

- `POST /api/contents` atomically creates a group and its first version. The selected
  content language defines the group's primary language; omission defaults to `en`.
- `POST /api/contents/{content}/translations` accepts `content_language` (`en`, `uk`,
  or `de`) and returns a new Content with HTTP 201. Only the owner can use a source.
  Missing/foreign sources return 404; invalid or duplicate languages return 422.
- New versions copy only title, topic, tone, and length as editable starting values.
  No text is translated automatically, no OpenAI request is made, and generated text,
  metadata, and generation fingerprint start empty. As with other fresh drafts,
  `is_generation_stale` is false until generated text exists and its inputs change.
- Editing a Content language rejects any language occupied by a sibling version.
  Editing the primary version's language updates the group's primary language.
- Deleting a version retains its siblings. Deleting the primary version promotes
  the surviving Content with the lowest ID. Deleting the final version deletes
  the empty group. Existing AI request history retains the original deletion behavior.
- Create, translation, edit, list, and generation responses include `content_group_id`,
  `primary_language`, and `translations: [{id, content_language}]`, ordered by Content ID.
  Listing remains a flat list of versions. Group relations are eager loaded in three
  content/group queries, independent of the number of rows.
- Group mutations use `ManageContent`, with transactions and consistent group-first
  row locking. A non-null foreign key and a unique `(content_group_id, content_language)`
  index enforce membership and language uniqueness in the database. Group assignment
  is not accepted from the client. Future importers should use the same domain actions.

The frontend keeps one card per group, with the selected Content ID stored independently
of the UI locale and generation state. Language buttons select a version in place without
hash navigation, scrolling, or programmatic focus. Missing-language buttons add and select
a draft without reordering groups. Generation indicators use a Set of exact Content IDs;
group busy state only disables conflicting actions. All sibling summaries update after changes.
Older in-flight list responses cannot discard locally added versions or restore deleted
ones. UI locale selection remains independent in localStorage. Article text and editable
inputs use `translate="no"` and `notranslate`; the surrounding UI remains translatable.

## Migration and deployment

Apply migrations with writes paused and a database backup available:

1. `2026_09_30_000001_create_content_groups_table` adds the groups table and a nullable
   Content group foreign key. Group primary language uses a database enum/check constraint.
2. `2026_09_30_000002_backfill_content_groups` creates a separate same-owner group for
   each unassigned existing Content, preserving its language and timestamps. Backfill
   runs in chunks of 500 with per-row transactions and skips already assigned rows.
   It then makes the foreign key mandatory and adds the unique language index.

Content IDs, fields, generated text, fingerprints, and AI request links are preserved.
No attempt is made to infer which old articles might be translations of each other.
The earlier content-language migration must run first. Run `php artisan migrate`
before serving the new application version; live writes during backfill are unsupported.

SQLite rebuilds the contents table when changing nullability and foreign keys. Allow
Laravel's migration runner to execute the schema steps outside an enclosing SQLite
transaction so its foreign-key PRAGMAs take effect. Migration tests use the same setup
and verify both AI request links and `PRAGMA foreign_key_check`. Other database engines
may take DDL locks; test the migration on a backup matching the deployment database.

Rolling back the group migrations keeps Content rows and AI request links but discards
group associations. Reapplying after a full rollback creates independent groups again.
Do not roll back grouping after creating real translations unless this loss of grouping
is acceptable. The backfill's data phase is restartable; if a database engine partially
applies its final DDL, inspect the schema before retrying.

## Verification

Run `php artisan test`, `php vendor/bin/pint --test`, and `git diff --check`.
In `frontend`, run `npm.cmd test`, `npm.cmd run lint`, and `npm.cmd run build`.
Browser verification should cover adding/switching versions without viewport jumps, editing/generating
a new draft, deleting a primary/final version, UI locale persistence, and automatic
browser translation leaving article text unchanged while translating the UI.
