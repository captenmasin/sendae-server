# X OAuth

Credentials live **only on this server**. After setting them, reload config (`php artisan config:clear` locally, or `php artisan optimize` on a host) so Connect appears.

`APP_URL` must be the origin a browser actually hits (Herd: `https://sendae-server.test`). Register the callback **exactly**: scheme, host, path, no trailing slash. If X rejects a `.test` host, use a public HTTPS tunnel and set `APP_URL` to that origin. X also accepts `http://127.0.0.1` for local callbacks.

```dotenv
X_CLIENT_ID=
X_CLIENT_SECRET=
```

Callback: `{APP_URL}/oauth/x/callback`

Connect from the desktop Accounts section or `/connect/x` while signed in on the website. Disconnecting deletes Sendae’s stored tokens; it does not revoke the X app grant.

Sendae uses OAuth 2.0 with PKCE **and** a confidential client secret (`Authorization: Basic` on the token request).

1. Open [developer.x.com](https://developer.x.com/en/portal/dashboard) and create a Project + App.
2. Subscribe the project to a paid API access tier that includes posting and media upload (Free is not enough).
3. App settings → **User authentication settings** → Set up.
4. App permissions: **Read and write**.
5. Type of App: **Web App, Automated App or Bot** (confidential). Do not choose Native App; Sendae needs the client secret.
6. Callback URI: `{APP_URL}/oauth/x/callback`. Website URL: your public site or `APP_URL`.
7. Save, then copy **OAuth 2.0 Client ID** and **Client Secret** into `X_CLIENT_ID` / `X_CLIENT_SECRET`. These are not the OAuth 1.0a API Key and Secret.

Scopes Sendae requests: `tweet.read`, `tweet.write`, `users.read`, `offline.access`, `media.write`.

If token exchange fails with `unauthorized_client`, the app type is probably Native (public) instead of Web. If media upload is denied, confirm `media.write` is available on your access tier.

Confirm `APP_URL` with `php artisan config:show app.url`, connect from Accounts, then publish a test post. Signed media URLs must be reachable from X’s network.

[X OAuth 2 PKCE](https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code)
