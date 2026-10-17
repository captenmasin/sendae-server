# Sendae server

The independent Laravel 13 publishing service for Sendae. Owns authenticated sync APIs, social-account OAuth, encrypted provider credentials, publication queues, retries, analytics and OAuth-protected HTTP MCP. It has no NativePHP or Electron dependency.

## Local development

Use PHP 8.5 for this checkout. Node is only needed for the optional local development process runner. Herd already serves the site; do not start a second web server.

```sh
composer install
test -f .env || cp .env.example .env
# If APP_KEY is empty, run php artisan key:generate --no-interaction once.
# Preserve existing keys and database.
# Set APP_URL in .env to the site's Herd HTTPS URL before continuing.
# Create database/database.sqlite if absent.
php artisan migrate --no-interaction
# First setup only, if Passport signing keys and the desktop client are absent:
php artisan passport:keys --no-interaction
php artisan passport:client --personal --name='Sendae desktop' --provider=users --no-interaction
php artisan config:clear --no-interaction
```

The local Herd URL is `https://sendae-server.test`; the example environment's loopback `APP_URL` must be changed to match it. Set the desktop's `SENDAE_SERVICE_URL` to that same URL and `SENDAE_SERVICE_CA` to the absolute Herd CA file path before launching. The desktop example and config fallback target production, not this checkout. Preserve an already initialized database, APP_KEY, Passport keys and desktop client.

Create an account in the Sendae app and sign in immediately. Email verification is not required and no verification email is sent. For local password recovery mail capture, set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1` and `MAIL_PORT=2525` with Herd Mail running; `.env.example` otherwise uses the log mailer. Captured mail is not delivered to a real inbox.

In separate terminals, run:

```sh
php artisan schedule:work
php artisan queue:work --sleep=3 --tries=1 --timeout=840
```

Users create an account and sign in with email and password; they never select a publishing server or paste API tokens. Sendae owns login, password reset, social-account selection and MCP approval. The server serves JSON APIs. Its only HTML page is `/oauth/authorize`, which opens `sendae://` so the desktop app can approve the connection.

## Validate

```sh
php artisan test
```

Provider requests have automated coverage with fake HTTP responses. That does not establish live provider permissions, public media retrieval or publishing success. Confirm deployed configuration and real account behavior using the release checks in `docs/DEPLOYMENT.md`. Set up each documented destination:

- [X](docs/x/README.md)
- [Threads](docs/threads/README.md)
- [Facebook Pages](docs/facebook/README.md)
- [LinkedIn](docs/linkedin/README.md)

## Deployment

Follow `docs/DEPLOYMENT.md`. For Ploi, paste `.ploi/deploy.sh` into the site’s Deploy script. Deploy **this project**, set its public HTTPS `APP_URL`, configure provider credentials here, and package the desktop with `SENDAE_SERVICE_URL` set to that address. The applications have independent databases and dependencies. Never copy a desktop profile or its APP_KEY into the server.

Registration creates a private workspace per user. Every API, OAuth connection and publishing job enforces workspace ownership. Password reset links expire and are single-use; resetting a password revokes existing tokens and web sessions. Teams and billing are not implemented. Personal desktop tokens expire after six months and are revoked on sign-out. HTTP MCP uses Passport authorization-code/PKCE and the same publication operations.
