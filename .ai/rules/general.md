---
paths:
  - '**'
---

# General

## Public release and workspace boundaries
This is the independent server for a public multi-user Sendae release. Every customer has a private workspace and must verify email before access. Never restore first-user/owner-only assumptions or remove owner scoping. Background jobs enumerate globally only to choose work, then execute within the destination owner’s context. Keep provider credentials, OAuth signing keys, publication execution and HTTP MCP here; the desktop is a fixed-service authenticated client.

## API-only server; all screens belong to Sendae
Sendae-server serves no HTML screens or hosted /local workspace routes. Sendae owns login, password reset, social-account selection and MCP consent. Email and OAuth handoffs use sendae:// links. Keep token validation, provider credentials, account/workspace isolation and publication execution on the server.

## Email verification is not required
This supersedes the earlier verify-before-access rule. Accounts can sign in immediately after registration; do not send verification emails or gate API, OAuth or MCP access on email_verified_at. Authentication, token scopes and workspace isolation remain mandatory.
