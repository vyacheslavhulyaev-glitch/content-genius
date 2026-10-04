# Recruiter admin demo

The recruiter account uses `is_admin_demo=true`, `is_admin=false`, `is_demo=false`.
This flag is not mass assignable. The account can sign in/out and view the admin
dashboard, but the `mutate-content` gate rejects all six content mutation routes
with HTTP 403 before request validation or OpenAI client resolution. Real admins
and normal demo users retain their existing permissions and generation limits.

For this account, recent AI requests contain `User #<id>` and `Content #<id>` labels
instead of names, emails and titles. The backend does not load identifying fields
for the public view. Request IDs, statuses, token counts and timestamps remain.
Real admins retain the detailed response.

The dashboard includes exactly two charts: a seven-day UTC provider-call/token
trend with separate axes, and all-time provider call counts for generation and
both moderation stages. `provider_usage.trend_7_days` is aggregated by the existing
UTC `budget_date` in one database query and includes zero days. Unknown tokens
and reservation estimates are not substituted for recorded usage. Existing
period totals, numeric cards and operation tables remain available. Recharts
is loaded with the admin page rather than on the initial login page.

## Production release

These commands are for the existing checkout at `/opt/apps/contentgenius` on
`apps-01`, after the reviewed release files have been supplied. They do not deploy
or commit anything automatically. Use the same Compose project and environment
file as the current deployment so the existing volumes are retained.

1. Add the following to the protected `.env.production` using the server editor.
   Choose a dedicated password; no default password is supplied. Do not reuse
   normal demo or real admin credentials, or publish private credentials.

   ```dotenv
   ADMIN_DEMO_EMAIL=admin-demo@contentgenius.hideas.dev
   ADMIN_DEMO_PASSWORD=<dedicated-recruiter-demo-password>
   ```

2. Build the release, apply the additive user-flag migration, seed only the
   dedicated recruiter account, then recreate the web service with its new env.
   Seeding is explicit and idempotent: rerunning updates that account's password.
   It refuses missing credentials, the normal demo email, and collisions with
   unrelated identities or conflicting admin/demo flags. Do not run the general
   `DatabaseSeeder` in production.

   ```sh
   cd /opt/apps/contentgenius
   docker compose --env-file .env.production -f compose.prod.yml config --quiet
   docker compose --env-file .env.production -f compose.prod.yml build web
   docker compose --env-file .env.production -f compose.prod.yml run --rm --no-deps web php artisan migrate --force --no-interaction
   docker compose --env-file .env.production -f compose.prod.yml run --rm --no-deps web php artisan db:seed --class=AdminDemoUserSeeder --force --no-interaction
   docker compose --env-file .env.production -f compose.prod.yml up -d --no-deps --force-recreate --wait web
   docker compose --env-file .env.production -f compose.prod.yml exec web php artisan migrate:status
   docker compose --env-file .env.production -f compose.prod.yml ps
   ```

   The existing HTTP entrypoint rebuilds runtime config and route caches. No
   Docker, PostgreSQL, Caddy, TLS, queue or AI-budget configuration changes are
   needed. The DB service is already running in this production deployment.

3. Verify `https://contentgenius.hideas.dev/api/health`, then sign in as the
   recruiter account. Confirm the read-only notice, analytics and sanitized
   recent requests in EN/UK/DE, and absence of content-mutation navigation.
   Through the authenticated browser session, confirm dashboard GET returns 200
   and content POST/PATCH/DELETE, translations, Generate and Regenerate return
   the read-only 403 response. Use a valid CSRF token for mutations so CSRF does
   not mask the authorization result. These recruiter requests cannot call AI.
   Confirm real admin still receives detailed recent requests and the normal
   demo still opens its existing content dashboard. Do not trigger generation
   from these other accounts during this verification.

## Validation and changed files

Local validation passed: `composer validate`, `php artisan test` (379 tests,
3357 assertions), `php vendor/bin/pint --test`, `npm --prefix frontend test`
(73 tests), `npm --prefix frontend run lint`, `npm --prefix frontend run build`
and `git diff --check`. Frontend tests also passed sequentially. The initial
JavaScript chunk is 93.80 kB gzip; the admin chunk is 109.94 kB gzip. No real
OpenAI calls, commits or production deployment were performed.

Changed files (25):

- Environment/config: `.env.example`, `.env.production.example`, `config/admin_demo.php`.
- Roles/routes/backend: `app/Models/User.php`, `app/Providers/AppServiceProvider.php`,
  `routes/api.php`, `app/Http/Controllers/AdminDashboardController.php`,
  `app/Services/AiUsageAnalytics.php`.
- Database: `database/migrations/2026_10_04_000000_add_is_admin_demo_to_users_table.php`,
  `database/seeders/AdminDemoUserSeeder.php`.
- Frontend: `frontend/package.json`, `frontend/package-lock.json`,
  `frontend/src/App.jsx`, `frontend/src/App.css`, `frontend/src/components/AppHeader.jsx`,
  `frontend/src/components/AiUsageCharts.jsx`, `frontend/src/pages/AdminPage.jsx`,
  `frontend/src/locales/en.json`, `frontend/src/locales/uk.json`, `frontend/src/locales/de.json`.
- Tests: `tests/Feature/AdminDemoTest.php`, `tests/Feature/AiUsageAnalyticsTest.php`,
  `frontend/tests/adminAnalytics.test.js`, `frontend/tests/publicLanguage.test.js`.
- Documentation: `docs/recruiter-admin-demo.md`.

Chart references: [responsive sizing](https://recharts.github.io/api/ResponsiveContainer/)
and [keyboard accessibility](https://github.com/recharts/recharts/blob/main/storybook/stories/API/Accessibility.mdx).
