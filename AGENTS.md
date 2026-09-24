# ContentGenius

- Backend: PHP 8.2+, Laravel 12, PHPUnit, Laravel Pint.
- Separate React/Vite frontend lives in `/frontend` and has its own npm scripts.
- Inspect existing project code and patterns before making changes.
- Keep changes small and focused; do not modify unrelated files.
- Do not install dependencies unless explicitly requested.
- Never expose secrets from `.env`.
- Do not weaken, delete, or bypass tests to make them pass.
- After PHP changes, run relevant tests with `php artisan test`; use `--filter` for focused tests when appropriate.
- Run Laravel Pint on changed PHP files when appropriate: `php vendor/bin/pint <changed-files>`.
- Before finishing, review `git diff` and new files for unrelated changes.
