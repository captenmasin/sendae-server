---
paths:
  - '**'
---

# General

## Public release and workspace boundaries
This is the independent server for a public multi-user Sendae release. Every customer has a private workspace and must verify email before access. Never restore first-user/owner-only assumptions or remove owner scoping. Background jobs enumerate globally only to choose work, then execute within the destination owner’s context. Keep provider credentials, OAuth signing keys, publication execution and HTTP MCP here; the desktop is a fixed-service authenticated client.
