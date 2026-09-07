# Threads OAuth

Credentials live **only on this server**. After setting them, reload config (`php artisan config:clear` locally, or `php artisan optimize` on a host) so Connect appears.

`APP_URL` must be the origin a browser actually hits (Herd: `https://sendae-server.test`). Register the callback **exactly**: scheme, host, path, no trailing slash. Meta often rejects `.test` hosts; if so, use a public HTTPS tunnel and set `APP_URL` to that origin.

```dotenv
THREADS_CLIENT_ID=
THREADS_CLIENT_SECRET=
```

Callback: `{APP_URL}/oauth/threads/callback`

Connect from the desktop Accounts section or `/connect/threads` while signed in on the website. Disconnecting deletes Sendae’s stored tokens; it does not revoke the Threads grant.

Threads credentials are **not** the main Meta App ID. A Threads-enabled Meta app has a separate Threads App ID and secret.

1. At [developers.facebook.com](https://developers.facebook.com/apps/) create an app and add the **Access the Threads API** use case (or add the Threads product to an existing app).
2. Use cases → Access the Threads API → **Settings**. Copy **Threads App ID** and **Threads App Secret** into `THREADS_CLIENT_ID` / `THREADS_CLIENT_SECRET`.
3. In those same Threads settings, add:
   - Valid OAuth redirect URI: `{APP_URL}/oauth/threads/callback`
   - A deauthorize callback and data-deletion URL (Meta requires both; the site root is enough until dedicated endpoints exist)
4. Add yourself (and any testers) as a Threads tester. Own-account posting stays in Development until App Review approves the permissions for the public.
5. Confirm the tester’s Threads account is linked to the Facebook account used in the portal.

Permissions Sendae requests: `threads_basic`, `threads_content_publish`, `threads_manage_insights`.

Sendae exchanges the short-lived token for a long-lived token and refreshes it before expiry. If connect returns no account, the user is not a tester or you used the Facebook App ID instead of the Threads App ID.

Confirm `APP_URL` with `php artisan config:show app.url`, connect from Accounts, then publish a test post. Signed media URLs must be reachable from Meta’s network.

[Threads getting started](https://developers.facebook.com/docs/threads/get-started/)
