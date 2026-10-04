#!/bin/bash
set -Eeuo pipefail
umask 077

# Installed as root-owned code; execute with the existing deploy user's privileges.
readonly PATH=/usr/bin:/bin
readonly LC_ALL=C
export PATH LC_ALL
readonly APP_DIR=/opt/apps/contentgenius
if [[ $# != 0 || ! "${SSH_ORIGINAL_COMMAND:-}" =~ ^contentgenius-deploy\ ([a-f0-9]{40})$ ]]; then
    printf 'Rejected SSH command. Expected: contentgenius-deploy <full-lowercase-main-sha>\n' >&2
    exit 1
fi
readonly EXPECTED_SHA=${BASH_REMATCH[1]}
# Client input must never supply script content or input to deployment commands.
exec </dev/null

cd "$APP_DIR"
[[ "$(git rev-parse --show-toplevel)" == "$(pwd -P)" ]]
case "$(git remote get-url origin)" in
    https://github.com/vyacheslavhulyaev-glitch/content-genius|https://github.com/vyacheslavhulyaev-glitch/content-genius.git|git@github.com:vyacheslavhulyaev-glitch/content-genius.git) ;;
    *) printf 'Unexpected origin repository.\n' >&2; exit 1 ;;
esac
git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main
git cat-file -e "$EXPECTED_SHA^{commit}"
[[ "$(git cat-file -t "$EXPECTED_SHA")" == commit ]]
git merge-base --is-ancestor "$EXPECTED_SHA" origin/main

# Materialize the complete verified Git blob before execution; never use a client script.
script_file=$(mktemp /tmp/contentgenius-deploy.XXXXXXXXXX)
trap 'rm -f -- "$script_file"' EXIT
git show --no-ext-diff --no-textconv "$EXPECTED_SHA:scripts/deploy-production.sh" > "$script_file"
[[ -s "$script_file" ]]
/bin/bash -n "$script_file"
/bin/bash "$script_file" "$EXPECTED_SHA"
