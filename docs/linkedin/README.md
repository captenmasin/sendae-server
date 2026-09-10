# LinkedIn OAuth

Credentials live **only on this server**. After setting them, reload config (`php artisan config:clear` locally, or `php artisan optimize` on a host) so Connect appears.

`APP_URL` must be the origin a browser actually hits (Herd: `https://sendae-server.test`). Register callbacks **exactly**: scheme, host, path, no trailing slash. LinkedIn requires HTTPS; it often rejects `.test` hosts. If so, use a public HTTPS tunnel and set `APP_URL` to that origin.

```dotenv
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
LINKEDIN_PAGE_CLIENT_ID=
LINKEDIN_PAGE_CLIENT_SECRET=
LINKEDIN_VERSION=202608
LINKEDIN_PERSONAL_ANALYTICS=false
LINKEDIN_PAGES_APPROVED=false
```

| Destination | Callback |
|---|---|
| Profile | `{APP_URL}/oauth/linkedin/callback` |
| Company Page | `{APP_URL}/oauth/linkedin_page/callback` |

Connect from the desktop Accounts section. Disconnecting deletes Sendae’s stored tokens; it does not revoke the LinkedIn grant.

## Profile

Personal posting is self-serve. The app must be associated with a LinkedIn Company Page (that is LinkedIn’s app-verification requirement; it does not mean you are posting as the Page).

1. Create an app at [linkedin.com/developers/apps](https://www.linkedin.com/developers/apps). Pick a Company Page you admin and complete verification.
2. Products → request **Sign In with LinkedIn using OpenID Connect** and **Share on LinkedIn**. Both are immediate.
3. Auth tab → Authorized redirect URLs: the profile callback in the table above.
4. Copy **Client ID** and **Primary Client Secret** into `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET`.

Scopes Sendae requests: `openid`, `profile`, `w_member_social`.

Personal analytics is off by default. After LinkedIn grants `r_member_postAnalytics`, set `LINKEDIN_PERSONAL_ANALYTICS=true` and reconnect so users re-consent.

## Company Page

Leave this off until LinkedIn approves organization access. The UI shows **Awaiting approval** while `LINKEDIN_PAGES_APPROVED` is false.

1. Create a separate developer app associated with the same Company Page and verify it. Apply for the **Community Management API** (development tier, then standard as required). LinkedIn requires this to be the only product when applying for development access.
2. Put this app’s Client ID and Secret in `LINKEDIN_PAGE_CLIENT_ID` / `LINKEDIN_PAGE_CLIENT_SECRET`. Keep the profile app credentials in `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET`.
3. Auth redirect URLs must include `{APP_URL}/oauth/linkedin_page/callback`.
4. Set `LINKEDIN_PAGES_APPROVED=true` and reload config.
5. The LinkedIn user must be `ADMINISTRATOR` or `CONTENT_ADMIN` on the organization.

Scopes Sendae requests: `w_organization_social`, `rw_organization_admin`, `r_organization_social`. The Page connection does not use OpenID Connect.

Confirm `APP_URL` with `php artisan config:show app.url`, connect from Accounts, then publish a test post. Signed media URLs must be reachable from LinkedIn’s network.

[LinkedIn API access](https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access)
