# Documentation reconciliation — 17 October 2026

This entry records source inspection, not a fresh test run or live release check. The dated September record below is historical: its test counts, audit results, email-verification behavior and release limitations must not be read as current certification.

- Current server `RegistrationTest.php`, `SessionTest.php`, `ConnectionTest.php` and `AuthorizationTest.php` cover immediate sign-in and authenticated workspace/OAuth access without email verification. Registration sends no verification email; the verification endpoints have been removed. Password recovery remains an email flow.
- Desktop `config/sendae.php` and `.env.example` target the production HTTPS service by default. Local development must explicitly select the sibling server and, for Herd HTTPS in bundled PHP, its CA file. Existing encryption and Passport keys must be preserved.
- Desktop `resources/js/workspace.js` uses a five-second sync interval and focus/visibility triggers. `app/Services/Synchronizer.php` preserves dirty local content; desktop/server workspace tests cover stale-version saves in place. Draft synchronization leaves existing publication snapshots unchanged; new conflict copies are not the current policy.
- Desktop `.github/workflows/release.yml` implements signing, notarization, both Mac architectures, artifact validation and updater publication. Local `composer desktop:build` is an ad-hoc development path. This review did not run the release workflow, install a signed artifact, or complete an installed-app update.
- Live provider publishing/analytics, public media retrieval, production recovery email and the requested MCP clients need concrete, dated evidence from those checks. No new live-success claim is made here.

---

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
