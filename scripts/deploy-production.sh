#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

readonly APP_DIR=/opt/apps/contentgenius
readonly EXPECTED_SHA=${1:-}
if [[ $# != 1 || ! "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]]; then
    printf 'Usage: bash scripts/deploy-production.sh <full-verified-main-sha>\n' >&2
    exit 1
fi
trap 'printf "Deployment failed at line %s. Inspect the server state; no automatic rollback was performed.\n" "$LINENO" >&2' ERR

cd "$APP_DIR"
[[ "$(git rev-parse --show-toplevel)" == "$(pwd -P)" ]]
mkdir -p backups
chmod 700 backups
exec 9>backups/.deployment.lock
flock --exclusive --wait 900 9

[[ "$(git symbolic-ref --quiet --short HEAD)" == main ]]
[[ -z "$(git status --porcelain --untracked-files=no)" ]]
[[ -f .env.production ]]
case "$(git remote get-url origin)" in
    https://github.com/vyacheslavhulyaev-glitch/content-genius|https://github.com/vyacheslavhulyaev-glitch/content-genius.git|git@github.com:vyacheslavhulyaev-glitch/content-genius.git) ;;
    *) printf 'Unexpected origin repository.\n' >&2; exit 1 ;;
esac

previous_sha=$(git rev-parse HEAD)
git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
git cat-file -e "$EXPECTED_SHA^{commit}"
git merge-base --is-ancestor "$EXPECTED_SHA" origin/main
if ! git merge-base --is-ancestor HEAD "$EXPECTED_SHA"; then
    printf 'Refusing an older or divergent deployment. Use the documented manual rollback procedure.\n' >&2
    exit 1
fi
git merge --ff-only "$EXPECTED_SHA"
[[ "$(git rev-parse HEAD)" == "$EXPECTED_SHA" ]]
printf 'Deploying %s; previous checkout %s\n' "$EXPECTED_SHA" "$previous_sha"

compose=(docker compose --env-file .env.production -f compose.prod.yml)
"${compose[@]}" config --quiet
"${compose[@]}" build web

# Pause HTTP writes only after a successful build. PostgreSQL stays running.
"${compose[@]}" stop web
backup="backups/pre-deploy-$(date -u +%Y%m%dT%H%M%S-%N)-$EXPECTED_SHA.dump"
partial="$backup.partial"
# Expand database variables inside the container, never in the deploy shell.
# shellcheck disable=SC2016
"${compose[@]}" exec -T db sh -c 'exec pg_dump --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --format=custom --no-password' > "$partial"
[[ -s "$partial" ]]
"${compose[@]}" exec -T db pg_restore --list < "$partial" > /dev/null
mv -- "$partial" "$backup"
printf 'Pre-deploy database backup verified: %s\n' "$backup"

"${compose[@]}" run --rm --no-deps web php artisan migrate --force --no-interaction
"${compose[@]}" up -d --no-deps --force-recreate --wait --wait-timeout 180 web
"${compose[@]}" ps
container=$("${compose[@]}" ps -q web)
[[ -n "$container" ]]
[[ "$(docker inspect --format '{{.State.Health.Status}}' "$container")" == healthy ]]
health=$(curl --fail --silent --show-error --retry 5 --retry-all-errors --retry-delay 3 \
    --connect-timeout 10 --max-time 30 https://contentgenius.hideas.dev/api/health)
[[ "$(printf '%s' "$health" | tr -d '[:space:]')" == '{"status":"ok"}' ]]
printf 'Deployment and public health verification succeeded for %s\n' "$EXPECTED_SHA"
