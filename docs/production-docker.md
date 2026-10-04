# Production Docker deployment

## Repository and runtime

Local development runs Laravel with `php artisan serve` on port 8000 and the separate
`frontend/` React/Vite app on port 5173. The root Vite package belongs to the Laravel
skeleton; production builds the actual `frontend/package-lock.json` application.
Local SQLite defaults remain unchanged. Laravel already supports PostgreSQL, database
sessions/cache, Sanctum cookie authentication and the read-only `/api/health` endpoint.

Production uses two services:

| Service | Runtime | Networks | Persistent data |
| --- | --- | --- | --- |
| `web` | PHP 8.4 / Apache, Laravel and built React assets | private `internal`, external `web` | `app_storage` |
| `db` | PostgreSQL 17 | private `internal` only | `db_data` |

Apache handles static assets and PHP in one container, avoiding an extra nginx/FPM
service for this small application. It runs as `www-data` on port 8080 with eight
maximum workers, dropped capabilities and no privilege escalation. Only `storage/`
and `bootstrap/cache/` need application write access. Source/vendor code is root-owned.
The official PostgreSQL entrypoint initializes its volume and runs the server as its
`postgres` user. Its dedicated database and user default to `contentgenius`.

`compose.prod.yml` publishes no host ports. The private network is marked
`internal: true`; PostgreSQL never joins the shared `web` network. The HTTP service
joins `web` for proxy traffic and outbound HTTPS, including future OpenAI calls.
The Compose project name scopes networks and volumes to ContentGenius. Other apps
must have their own project, environment, database, credentials and volumes.

The Dockerfile has separate Node/Composer build stages and a production runtime.
It installs the locked PHP dependencies with `--no-dev` and an authoritative
autoload map, compiles `pdo_pgsql`/OPcache and builds the locked React frontend.
Node, Composer, frontend source and dev dependencies are absent from the final
runtime. `.dockerignore` excludes env files, credential/key files, Git metadata,
local storage/databases, cached Laravel configuration and local build artifacts.
No credentials are build arguments or image environment defaults.

## One public origin and proxy contract

Apache serves `public/index.html` and Vite's hashed `/assets/` files. Non-file paths
go to Laravel's `public/index.php`, including `/api/*`, `/login`, `/logout`,
`/sanctum/csrf-cookie` and `/up`. No Vite server or Artisan development HTTP server
runs in production. The SPA currently navigates through component state, so no
additional client-side route fallback is required. Vite source maps are disabled
by its existing default.

Frontend API requests default to relative paths in production. Development retains
`http://localhost:8000`; `VITE_API_BASE_URL` can explicitly override the origin at
frontend build time. It is not a runtime Laravel setting. The Docker build excludes
all frontend env files and uses the same-origin production default.

The shared reverse proxy's target is **`contentgenius-web:8080`** on the existing
external Docker network named `web`. The alias points to the `web` Compose service.
The proxy must preserve the public Host and set trustworthy `X-Forwarded-For` and
`X-Forwarded-Proto` headers after terminating TLS. Configure `TRUSTED_PROXIES` with
the proxy's address or the inspected `web` subnet, as a comma-separated list.
The native Laravel `trustedproxy.proxies` configuration is compatible with
`config:cache`; forwarded Host/Port headers are deliberately not trusted.
Do not use `*` unless every sender that can directly reach this private HTTP service
is trusted. Inspect the network on the VPS instead of committing host addresses.

The global proxy stack remains in `/opt/apps/proxy`. This repository neither owns
its containers/configuration nor defines configuration for any other application.

## Production environment

Copy `.env.production.example` to `.env.production` on the server. Restrict the
populated file to the deploy account (`chmod 600 .env.production`). It is ignored
by Git and excluded from the Docker build. Never print resolved Compose config
with secrets; use `config --quiet` for validation.

Before startup, provide:

- A unique, persistent `APP_KEY`, a dedicated random `DB_PASSWORD`, and the actual
  public HTTPS `APP_URL`/`FRONTEND_URL`.
- `SANCTUM_STATEFUL_DOMAINS` containing the public hostname without a scheme; include
  its port when non-standard. Keep `SESSION_DOMAIN=null` for a host-only cookie,
  `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true` and `SESSION_SAME_SITE=lax`.
- The inspected `TRUSTED_PROXIES` value for the shared proxy.
- `OPENAI_API_KEY` only when enabling generation. Health/auth/list smoke verification
  works with the key empty and never calls generation endpoints.
- Dedicated demo credentials before explicitly running `DemoUserSeeder`. Never use
  normal/admin credentials for the shared demo. The default `DatabaseSeeder` uses
  development factories; do not run it in the production image.

Generate an application key without installing PHP on the host:

```sh
docker run --rm --entrypoint php php:8.4-cli-bookworm -r 'echo "base64:".base64_encode(random_bytes(32)),PHP_EOL;'
```

Put the result in `APP_KEY`; keep it stable across releases. Generate the database
password separately and keep it unchanged for an existing PostgreSQL volume.
Changing `POSTGRES_PASSWORD` only changes first initialization, not existing roles.

Compose enforces `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`,
`DB_HOST=db`, `DB_PORT=5432`, `DB_URL=` (preventing an external URL override),
`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync` and
`LOG_CHANNEL=stderr`. No worker or Redis service is needed. Database credentials
are passed at runtime; PostgreSQL has no host port mapping. The template retains
the configured locale and existing AI controls:

| Setting | Demo production default |
| --- | --- |
| `OPENAI_MODEL`, `OPENAI_MODERATION_MODEL` | `gpt-4o-mini` |
| `AI_DAILY_COST_LIMIT_USD` | `0.10` |
| `AI_MONTHLY_COST_LIMIT_USD` | `1.00` |
| `AI_DAILY_PROVIDER_CALL_LIMIT` | `50` |
| `AI_DAILY_TOKEN_LIMIT` | `75000` |
| `AI_REQUESTS_PER_HOUR`, `AI_QUOTA_WINDOW_SECONDS` | `5`, `3600` |
| `AI_MAX_OUTPUT_TOKENS`, `AI_MODERATION_MAX_OUTPUT_TOKENS` | `4500`, `256` |

The remaining input/output controls are included in the template. The existing
accounting, monthly ceiling and paid moderation pipeline are unchanged.

## First startup and repeatable releases

On Hetzner (`deploy@apps-01`), work in `/opt/apps/contentgenius`. The external `web` network
already exists on the VPS; confirm it instead of creating or replacing it.
Supply `.env.production` before Compose interpolation:

```sh
docker network inspect web
docker compose --env-file .env.production -f compose.prod.yml config --quiet
docker compose --env-file .env.production -f compose.prod.yml build
docker compose --env-file .env.production -f compose.prod.yml up -d --wait db
docker compose --env-file .env.production -f compose.prod.yml run --rm --no-deps web php artisan migrate --force --no-interaction
docker compose --env-file .env.production -f compose.prod.yml up -d --wait
docker compose --env-file .env.production -f compose.prod.yml exec web php artisan migrate:status
docker compose --env-file .env.production -f compose.prod.yml ps
```

The entrypoint creates required writable directories and rebuilds package discovery,
config, route and view caches before starting Apache. It never migrates/seeds data or
generates an application key on container restart. One-off Artisan commands bypass
HTTP startup optimization; runtime env still supplies their configuration.
`bootstrap/cache` is container-local, so cached secrets are never baked into images
or carried between releases. `storage` is a named persistent volume; application
logs go to Docker stderr with bounded rotation.

For later releases, review migrations and back up the dedicated PostgreSQL database
and application storage first. Build the new image, pause HTTP writes while applying
migrations (for example, stop only this project's `web` service), run the explicit
migration command, then recreate `web` with `up -d --wait`. Run only one deployment
at a time. Do not use `migrate:fresh`, `migrate:refresh`, implicit seeding or automatic
rollback. Migration failures stop the deployment; they must be investigated before
the new HTTP container is admitted. Never use `down --volumes` against production.
PostgreSQL major-version upgrades require a reviewed database upgrade/restore plan.

The DB health check is `pg_isready`; the HTTP health check reads `/api/health` without
Origin/session headers. Neither calls OpenAI. `/api/health` verifies the application
boot/HTTP path; migration status and authenticated content-list verification separately
check the database. Docker reports health status but does not itself restart a still
running unhealthy process.

## Local Docker verification

Use Docker with Linux containers. Create the `web` network locally only if absent.
Copy `.env.production.example` to the ignored `.env.docker.local` and configure:

```dotenv
CONTENTGENIUS_ENV_FILE=.env.docker.local
CONTENTGENIUS_IMAGE_TAG=verification
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:8080
SANCTUM_STATEFUL_DOMAINS=localhost:8080
SESSION_SECURE_COOKIE=false
TRUSTED_PROXIES=
OPENAI_API_KEY=
```

Also provide random local `APP_KEY`, `DB_PASSWORD` and dedicated `DEMO_PASSWORD`.
These are independent of the application's local-development `.env` and server
secrets. `compose.local.yml` publishes only the HTTP port on loopback for this check;
never include that override in a VPS deployment. Use a separate verification project:

```sh
docker network inspect web
# Only if the local network is absent:
docker network create web
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml -f compose.local.yml config --quiet
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml build
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml up -d --wait db
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml run --rm --no-deps web php artisan migrate --force --no-interaction
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml run --rm --no-deps web php artisan db:seed --class=DemoUserSeeder --force --no-interaction
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml -f compose.local.yml up -d --wait
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml exec web php artisan migrate:status
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml ps
node --env-file=.env.docker.local docker/smoke.mjs
```

The Node 24 smoke script runs on the verification host, not in the production image.
It checks built SPA/assets, health, guest rejection, real CSRF/cookie login, user,
content list and logout. It never calls Generate/Regenerate. Optional repeatability
check: restart `web`, rerun migrations (nothing pending), and rerun the smoke script.
Keep the OpenAI key empty for all automated Docker smoke checks.

Stop local verification containers without removing data:

```sh
docker compose --env-file .env.docker.local -p contentgenius-verification -f compose.prod.yml -f compose.local.yml down
```

Only delete the verification project's volumes if its disposable data is no longer
needed; do not prune shared Docker resources or remove the VPS proxy network.

## Checks and deployment preparation

```sh
composer validate
php artisan test
php vendor/bin/pint --test bootstrap/app.php config/trustedproxy.php tests/Feature/TrustedProxyTest.php
npm --prefix frontend test
npm --prefix frontend run lint
npm --prefix frontend run build
git diff --check
```

On Windows PowerShell, use `npm.cmd` if the execution policy blocks `npm.ps1`.
Existing frontend suites create Vite servers; if their shared optimization cache
produces a contention warning, run `npm --prefix frontend test -- --test-concurrency=1`.

Before the first Hetzner deployment: provision the checkout/release at the application
directory, supply the protected production environment, confirm dedicated DB/storage
backups, configure DNS/TLS and the shared proxy target in its separate stack, execute
the explicit build/migration/startup sequence, then verify HTTPS cookies, login and
the content list through the public domain. No legacy SQLite/MySQL data is imported
automatically; plan a separate reviewed import if existing content must be retained.
GitHub Actions validation and verified-SHA SSH deployment are configured in
[GitHub Actions deployment](github-actions-deploy.md); manual Environment/secrets
setup and the first real workflow deployment still require verification after commit.

## Verified locally

- Docker image build and production/local Compose validation passed.
- Both services became healthy; all 14 existing migrations ran on PostgreSQL 17.
- Built SPA/assets, health, CSRF login, authenticated user/content list and logout passed.
- Recreating the HTTP container retained the user/database; smoke passed again and
  rerunning migrations reported nothing pending.
- Runtime inspection confirmed `www-data`, root-owned source, required PHP extensions,
  no env/local database/source-map files and no Node/Composer/test/frontend-source payload.
- Runtime config/route caches are enabled with production/debug-off, PostgreSQL,
  database sessions/cache, synchronous jobs, mini generation and the $1 monthly ceiling.
- The OpenAI key remained empty and both AIRequest and ProviderCall counts remained zero.
- Verification containers were stopped afterward; local DB/storage volumes were retained.
- Backend: 363 tests / 3262 assertions passed. Frontend: 69 tests passed; a sequential
  run also passed without shared Vite-cache contention. Composer validation, Pint check,
  frontend lint/build and `git diff --check` passed.

## Files in this milestone

- Images/networking: `Dockerfile`, `.dockerignore`, `compose.prod.yml`, `compose.local.yml`.
- Runtime: `docker/apache.conf`, `docker/php.ini`, `docker/entrypoint.sh`.
- Configuration: `.env.production.example`, `.gitignore`, `config/trustedproxy.php`,
  `bootstrap/app.php`, `frontend/src/lib/api.js`.
- Verification: `docker/smoke.mjs`, `tests/Feature/TrustedProxyTest.php`,
  `frontend/tests/apiOrigin.test.js`.
- Documentation: `README.md`, `docs/production-docker.md`.

References: [official PHP image](https://hub.docker.com/_/php),
[Laravel deployment](https://laravel.com/docs/12.x/deployment),
[Compose startup ordering](https://docs.docker.com/compose/how-tos/startup-order/),
[Compose networks](https://docs.docker.com/reference/compose-file/networks/),
[official PostgreSQL image](https://hub.docker.com/_/postgres).
