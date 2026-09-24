# ContentGenius

- Backend: PHP 8.2+, Laravel 12, PHPUnit tests, and Laravel Pint formatting.
- The separate React/Vite frontend lives in `/frontend` and has its own npm scripts.
- Inspect existing code and patterns before making changes.
- Keep changes small and focused; do not modify unrelated files.
- Do not install dependencies unless explicitly requested.
- Never expose secrets from `.env`.
- Do not weaken or delete tests to make them pass.
- After PHP changes, run relevant tests with `php artisan test` (use `--filter` for focused tests).
- When appropriate, run Laravel Pint on changed PHP files: `vendor/bin/pint <changed-files>`.
- Report files changed, commands executed, test results, and assumptions.
