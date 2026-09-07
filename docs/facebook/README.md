# Facebook Pages OAuth

Credentials live **only on this server**. After setting them, reload config (`php artisan config:clear` locally, or `php artisan optimize` on a host) so Connect appears.

`APP_URL` must be the origin a browser actually hits (Herd: `https://sendae-server.test`). Register the callback **exactly**: scheme, host, path, no trailing slash. Meta often rejects `.test` hosts; if so, use a public HTTPS tunnel and set `APP_URL` to that origin.

```dotenv
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
META_GRAPH_VERSION=v24.0
```

Callback: `{APP_URL}/oauth/facebook/callback`

Connect from the desktop Accounts section or `/connect/facebook` while signed in on the website. Disconnecting deletes Sendae’s stored tokens; it does not revoke the Facebook grant.

Sendae connects **Pages**, not personal profiles. The Facebook user must be able to create content on the Page (`CREATE_CONTENT` or `MANAGE`).

1. Create a Meta app (Business type, or use case **Manage everything on your Page**).
2. Add **Facebook Login** (or Facebook Login for Business). Sendae passes `scope` on the dialog; it does not use a Login for Business `config_id`.
3. Facebook Login → Settings → Valid OAuth Redirect URIs: `{APP_URL}/oauth/facebook/callback`.
4. Settings → Basic: copy **App ID** and **App Secret** into `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET`. Add a privacy-policy URL and the same deauthorize / data-deletion URLs Meta asks for.
5. Create or use a Facebook Page. In Development mode only people with an app role (Admin / Developer / Tester) can complete OAuth; add them under App Roles.
6. App Review is required before customers who are not testers can grant Page permissions.

Permissions Sendae requests: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `read_insights`.

If the chooser is empty, the user has no eligible Page, or the Page role cannot create content.

Confirm `APP_URL` with `php artisan config:show app.url`, connect from Accounts, then publish a test post. Signed media URLs must be reachable from Meta’s network.

[Facebook Login](https://developers.facebook.com/docs/facebook-login/)
