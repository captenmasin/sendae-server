---
paths:
  - 'app/**'
---

# App

## Owner and workspace isolation
Each user owns multiple workspaces. The owner scope includes user_id and workspace_id; validate raw exists queries against both. Background jobs must run with the target record workspace_id. OAuth start captures owner/workspace and selection restores that context, never the currently selected workspace. Preserve the original workspace ID when migrating existing users.
