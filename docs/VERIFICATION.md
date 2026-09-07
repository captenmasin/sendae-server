# Registration and private workspaces — 6 September 2026

- Desktop: 21 passing feature tests. Server: 34 passing feature tests.
- Real HTTP integration: two separate registrations, verification-required sign-in, signed verification links, private draft sync, sign-out lock and switching back to the original account. Temporary databases were removed and test email stayed in memory.
- Cross-account tests reject reading, scheduling, cancelling, recovering, disconnecting and analytics access to another user’s data. Reusing another user’s media UUID cannot overwrite their file.
- Background publishing executes under the destination owner’s scope and restores its prior context afterward.
- Password reset links are single-use and revoke existing sessions. Verification and recovery endpoints are rate-limited.
- Saved native session tokens are sealed with NativePHP’s OS-backed encryption; browser development tokens remain protected by the local Laravel key.
- NativePHP’s bridge-secret middleware is registered explicitly so another browser cannot access the local API even while the desktop is signed in.
- Composer audits report no advisories for either PHP application. Secret scans found only ignored local signing keys and source-code false positives, not credentials in frontend source.
- Production mail delivery, public hosting, social-provider permissions, live MCP client handshakes and Mac signing/notarization still need real deployment verification. The current local build is not a production release.
