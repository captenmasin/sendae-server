# Sendae server

The independent Laravel 13 publishing service for Sendae. Owns authenticated sync APIs, social-account OAuth, encrypted provider credentials, publication queues, retries, analytics and OAuth-protected HTTP MCP. It has no NativePHP or Electron dependency.

## Local development

PHP 8.3+ and Node 22+ are required.

```sh
composer install
cp .env.example .env # first setup only
php artisan key:generate # first setup only
# Create database/database.sqlite if absent.
php artisan migrate
php artisan passport:keys
php artisan passport:client --personal --name='Sendae desktop' --provider=users --no-interaction
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8001
```

This checkout already has its local database, APP_KEY and Passport signing keys/client initialized. Create an account in the desktop app or at `/register`, then verify your email. Local email is captured by Herd Mail at 127.0.0.1:2525; it is not delivered to a real inbox. Preserve existing encryption keys.

In separate terminals, run:

```sh
php artisan schedule:work
php artisan queue:work --sleep=3 --tries=1 --timeout=840
```

The sibling Sendae desktop defaults to this service on port 8001. Users create and verify their own account, then sign in with email and password; they never select a publishing server or paste API tokens. The server also offers its authenticated browser workspace.

## Validate

```sh
php artisan test
npm run build
```

Provider requests are tested with fake HTTP responses. Live provider permissions, public media retrieval and publishing still need a domain and developer apps. Set up each destination:

- [X](docs/x/README.md)
- [Threads](docs/threads/README.md)
- [Facebook Pages](docs/facebook/README.md)
- [LinkedIn](docs/linkedin/README.md)

## Deployment

Follow `docs/DEPLOYMENT.md`. Deploy **this project**, set its public HTTPS `APP_URL`, configure provider credentials here, and package the desktop with `SENDAE_SERVICE_URL` set to that address. The applications have independent databases and dependencies. Never copy a desktop profile or its APP_KEY into the server.

Registration creates a private workspace per user. Every API, OAuth connection and publishing job enforces workspace ownership. Password reset links expire and are single-use; resetting a password revokes existing tokens and web sessions. Teams and billing are not implemented. Personal desktop tokens expire after six months and are revoked on sign-out. HTTP MCP uses Passport authorization-code/PKCE and the same publication operations.
