# Hosted service setup

## Ploi

Create a Laravel site, web directory `public`, PHP 8.3+ (8.5 if available), project root the repository. Point the document root at `public/`. Do not enable zero-downtime until storage, `.env`, and Passport keys are on a shared path.

Paste `.ploi/deploy.sh` into **Repository → Deploy script**. Ploi substitutes `{SITE_DIRECTORY}`, `{BRANCH}`, `{SITE_PHP}`, `{SITE_COMPOSER}`, and `{RELOAD_PHP_FPM}`. Create the `.env` in Ploi before the first deploy; the script will not invent an `APP_KEY`.

In **Settings → PHP**, set memory to at least 512 MB, `upload_max_filesize=100M`, and `post_max_size=140M`. Enable the Laravel scheduler (every minute). Add one daemon from the site directory, not a timestamped release folder:

```sh
{SITE_PHP} artisan queue:work --sleep=3 --tries=1 --timeout=840
```

Replace `{SITE_PHP}` with the site PHP binary shown in Ploi (for example `php8.5`). After each deploy the script runs `queue:restart` so Supervisor picks up the new code.

## Host requirements

Deploy the Sendae-server project with its own environment and database. The domain must use valid HTTPS, point its document root at `public/`, and run PHP 8.3+ with SQLite or a Laravel-supported database, mbstring, fileinfo, openssl, curl, and a persistent writable storage directory. Keep `APP_KEY`, the database, OAuth signing keys, and `storage/app/private` in backups together. Never regenerate an existing production APP_KEY: it encrypts saved credentials.

Use 512 MB PHP memory, `upload_max_filesize=100M`, `post_max_size=140M` (MCP base64 uploads need more than raw file size), and an appropriate proxy request-body limit. Larger video uploads are deliberately outside the MVP's 100 MB attachment limit. JSON parsing of base64 uploads uses memory; prefer multipart `/api/media` for video.

```sh
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Set the following before continuing:

```dotenv
APP_NAME=Sendae
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-DOMAIN
SESSION_SECURE_COOKIE=true
DB_CONNECTION=sqlite
# Use an absolute, persistent database path on the host:
DB_DATABASE=/ABSOLUTE/PERSISTENT/PATH/database.sqlite
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Create the database file if using SQLite, then:

```sh
php artisan migrate --force
php artisan passport:keys
php artisan passport:client --personal --name='Sendae desktop' --provider=users --no-interaction
php artisan optimize
```

Package the desktop with `SENDAE_SERVICE_URL=https://YOUR-DOMAIN`. Users register and verify their email, then sign in; no publishing-server or access-token controls are exposed. The desktop keeps each account’s local data in its own workspace. Desktop tokens expire after six months; sign in again to renew. Sign-out revokes the current token. Public registration, email verification and password recovery are implemented. There is no team/billing functionality. The server requires no frontend build and serves no HTML screens. Ship the updated Sendae desktop alongside this server: it registers the `sendae://` scheme and owns password reset, social-account selection and MCP consent.

Schedule Laravel once per minute:

```cron
* * * * * cd /PATH/TO/SENDAE-SERVER && php artisan schedule:run >> /dev/null 2>&1
```

Run a persistent queue worker under Supervisor, systemd or your host's process manager:

```sh
php artisan queue:work --sleep=3 --tries=1 --timeout=840
```

Set `DB_QUEUE_RETRY_AFTER=960` (also the configuration default). The scheduler dispatches one unique job per account; workers process due publications oldest first. Both processes must be running on the host. Neither relies on the Mac being awake. Restart queue workers with `php artisan queue:restart` after deployments. Inspect publication status and `storage/logs/laravel.log` for diagnostics.

A worker has a hard 14-minute job timeout. Its 15-minute account lock outlives that timeout, preventing another worker from posting concurrently. A stopped worker's stale in-flight records become `uncertain`, and require recovery instead of a blind retry. Keep the host's clock synchronized and all processes on the same cache/database. Provider media processing is bounded; an unusually long thread can hit the worker limit and require manual recovery.

## Account email delivery

Use a verified sending domain and working SMTP credentials on the production server:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=YOUR-SMTP-HOST
MAIL_PORT=587
MAIL_USERNAME=YOUR-SMTP-USERNAME
MAIL_PASSWORD=YOUR-SMTP-PASSWORD
MAIL_FROM_ADDRESS=noreply@YOUR-DOMAIN
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=sendae_server_session
```

Follow your mail provider’s TLS/port settings. Account signup is gated when a production deployment uses the log/array/failover mailers. Local development uses Herd Mail capture on port 2525; captured emails do not reach external inboxes.

Verify real inbox delivery for signup and password recovery before opening registration publicly. Verification links expire after 60 minutes. Reset links use Laravel’s configured 60-minute expiry and single-use broker; resetting a password revokes API tokens and web sessions.

Existing server records migrate to the original owner’s private workspace. Existing unverified owners must verify their email via resend verification. Back up the database and keys before applying isolation migrations; their rollback deliberately requires restoring a backup to avoid mixing customer data.

Each workspace defaults to 1 GB of attachments and 500 drafts. Configure `SENDAE_STORAGE_LIMIT_MB` and `SENDAE_DRAFT_LIMIT` on the server to fit the release’s storage plan. Limits are enforced per workspace before writes; duplicate upload retries do not consume extra capacity.

## Provider developer apps

Set credentials **only on the hosted service**, then rebuild configuration with `php artisan optimize`. Portal steps: [X](x/README.md), [Threads](threads/README.md), [Facebook Pages](facebook/README.md), [LinkedIn](linkedin/README.md).

Register exact callbacks:

| Destination | Callback | Environment |
|---|---|---|
| X | `https://YOUR-DOMAIN/oauth/x/callback` | `X_CLIENT_ID`, `X_CLIENT_SECRET` |
| Threads | `https://YOUR-DOMAIN/oauth/threads/callback` | `THREADS_CLIENT_ID`, `THREADS_CLIENT_SECRET` |
| Facebook Pages | `https://YOUR-DOMAIN/oauth/facebook/callback` | `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` |
| LinkedIn profile | `https://YOUR-DOMAIN/oauth/linkedin/callback` | `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` |
| LinkedIn Company Page | `https://YOUR-DOMAIN/oauth/linkedin_page/callback` | Same LinkedIn app; `LINKEDIN_PAGES_APPROVED=true` after approval |

Sendae lets each user choose accounts returned by OAuth. The desktop opens a short-lived connection link in the system browser and the callback returns to Sendae for account selection; no website sign-in or selection page is served. Disconnection erases stored credentials and cancels queued work; it does not revoke the provider's app grant.

Media stays private. Providers receive an HTTPS URL with a signed two-day expiry when publishing requires public retrieval. Verify those URLs are reachable from outside your network. The first release does not run a storage garbage collector: referenced files and publication snapshots are retained.

## MCP clients

The hosted endpoint is `https://YOUR-DOMAIN/mcp`. OAuth authorization-server and protected-resource metadata are available under `/.well-known/`. The client registers using `/oauth/register`, uses authorization-code PKCE, and requests `mcp:use`. The authorization endpoint opens Sendae, where the user signs in and explicitly consents; Sendae then returns the authorization code to the requesting client. There is no separate per-post approval in Sendae.

Connect that endpoint in Codex, ChatGPT and Claude's custom MCP server settings. Exact UI availability depends on each client/account. Their live OAuth handshakes remain release checks once a public domain exists. Clients that support a bearer token can use a user’s own API token instead.

Local stdio MCP is provided only by the desktop project and requires a signed-in profile. The server exposes authenticated HTTP MCP; there is no unrestricted server stdio workspace.

Tools: workspace, save_draft, attach_media, schedule_post, cancel_publication, recover_publication, refresh_analytics. `schedule_post` with mode `now` publishes at the next worker run. Workspace returns cached counts without paid provider reads. Manual analytics refresh can incur read charges.

## Live release checks

1. Connect and disconnect each requested destination through OAuth; verify roles and permissions.
2. Pair the desktop, draft and attach a photo/video offline, quit/reopen, synchronize, and confirm an agent sees the same draft. Make conflicting edits and confirm both copies survive.
3. Publish text, links, images, video and multi-part threads to test accounts. Verify each provider's returned IDs, rendered content and media.
4. Schedule exact times and weekly slots, quit the desktop, and confirm server-only delivery. Check local DST behavior in the selected account timezone.
5. Exercise a temporary rate limit, worker interruption and 24-hour expiry. Verify successful destinations and confirmed thread items are not duplicated.
6. Exercise OAuth with all three requested MCP clients, including media uploads, schedule, cancel, results and analytics.
7. Verify emailed verification/reset links and OAuth consent open the installed Sendae app from both a running and a closed state.
8. Confirm valid analytics permissions, provider-specific names, unavailable-vs-zero display and retained timestamps.
9. Check actual X API usage charges and storage/transfer costs against the £10/month target.

## Sources checked during implementation

- [NativePHP installation](https://nativephp.com/docs/desktop/2/getting-started/installation)
- [Laravel MCP](https://laravel.com/framework/docs/13.x/mcp)
- [X OAuth PKCE](https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code)
- [X media initialization](https://docs.x.com/x-api/media/initialize-media-upload) and [chunk append](https://docs.x.com/x-api/media/append-media-upload)
- [X pricing](https://docs.x.com/x-api/getting-started/pricing)
- [Official Meta Threads collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api)
- [LinkedIn Posts API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-08)
- [LinkedIn Videos API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/videos-api?view=li-lms-2026-08)
- [LinkedIn member post analytics](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/members/post-statistics?view=li-lms-2026-08)

Meta documentation was intermittently rate-limited; validate Facebook and Threads media/insights behavior against the actual approved apps before treating them as live-tested integrations.
