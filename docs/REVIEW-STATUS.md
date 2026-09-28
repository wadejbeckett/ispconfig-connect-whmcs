# Review and fix status (updated 2026-09-28)

This file records where the multi-reviewer code review, the readiness review and their fixes
stand, so the work can be picked up later without the original session. It belongs to draft PR #1
(`claude/zen-franklin-z7oak2` into `master`) and can be deleted before that PR is merged.

## Where things stand

- **Branch:** `claude/zen-franklin-z7oak2`. Its head is this commit: the readiness fixes (up to
  `742be68`), the live test plan (`45847a8`) and these documentation updates. It is
  `build/phase-0-1` plus the two fix rounds below.
- **PR:** wadejbeckett/ispconfig-connect-whmcs#1, draft. Its description holds the first round's
  "Fixed" table.
- **Verification (this commit):** 1012 tests and 4217 assertions pass, also in random order.
  The same suite passes on real PHP 8.2.34 and 8.3.35 builds (there, the two installer tests that
  need root were skipped). PHPStan 2.2.9 at level 6 reports no errors. `sh -n`, `dash -n` and
  `bash -n` are clean on the hook installer.
- **Not verified live:** nothing on this branch has been run against a live WHMCS or ISPConfig
  panel. The last live verification was of `build/phase-0-1` (head `1ed05b3`) on WHMCS 9.0.6 and
  ISPConfig 3.3.1p1, and this branch changes several of the paths it covered. Panel behaviour was
  checked against the fake server, the module's source-cited comments and, in the readiness review,
  a copy of the ISPConfig 3.3 source.
- **Live test:** [`docs/LIVE-TEST-PLAN.md`](LIVE-TEST-PLAN.md) is the plan for the live
  re-verification on a test WHMCS and a test panel, including the upgrade checks for an install
  that runs an earlier build.
- **Monitoring:** the hourly PR check-ins and the PR subscription were stopped at the first pause.

## What was done

1. **Review.** Eight independent reviewers each covered one area: API transport, provisioning
   lifecycle, admin and client surfaces with sync and SSO, usage and metrics, hooks and
   installer, security, the test suite (with targeted mutation testing), and docs against code.
   Each finding was demonstrated with a throwaway test before it was ranked. The result was
   one Critical, ten Highs, nine Mediums and a set of Lows.
2. **First fix round.** The Critical, all ten Highs, and most Mediums and Lows were fixed, each
   with a regression test. The main themes:
   - One WHMCS service maps to one ISPConfig client. Link, Replace, adoption and every
     whole-client write refuse a client that another live service holds, so terminating one
     service can no longer delete another customer's hosting.
   - Clearing a link blanks the stored ids on every product's custom-field rows, so Unlink,
     Terminate and Replace cannot bring back a previous tenant's ids after a package change.
   - Bandwidth usage resets at the month boundary instead of billing every month at the
     highest month seen.
   - Security: the admin tab output is escaped, malformed panel responses are scrubbed before
     logging, the installer's temp-file symlink attack is closed, SSO requires an active
     service, and usage-read logs drop mailbox personal data and operator secrets.
3. **Readiness review.** A second set of reviewers checked whether the fixed branch was ready to
   deploy and test (packaging, PHP runtime, the WHMCS database, ISPConfig behaviour, docs). They
   found regressions introduced by the first fix round, and a few gaps. All of these are now
   fixed, each with regression tests:
   - **SSO status key.** The "active services only" gate read `$params['domainstatus']`, which
     WHMCS does not pass, so it never fired. It now reads `$params['status']`, then
     `domainstatus`, then the service model.
   - **Website-only suspend never locks the client.** The first round fell back to a whole-client
     lock when no website id was stored. Website-only Suspend and Unsuspend now refuse instead,
     and Link / Replace ISPConfig Link resolve and store the website id from the service domain.
   - **Change Package** is one `client_update` again (the first round's lock bracket is gone),
     is refused while the client is locked, and re-suspends a Suspended service in the new
     product's mode; a failed re-suspension is reported as "live", not as success.
   - **Installer privilege drop.** Root reads and checks the shipped file; only the write to
     `includes/hooks` runs as its owner, fed on stdin, so a private checkout works again. The
     `setpriv` fallback gets the directory's group.
   - **Client area and admin tab** no longer throw on an empty or malformed server hostname.
   - **Shared-client guard.** A failing check is logged (`SharedClientGuard`) and fails closed
     for Terminate and adoption. The guard is scoped to one panel and judges each service by
     the client id the module would actually read. Entire-client Unsuspend on a shared client is
     allowed unless another sharer is Suspended.
   - **Transport circuit** opens after two consecutive transport failures, not one, so a single
     timeout no longer skips the rest of a usage sweep.
   - **Runtime:** an mbstring-free fallback for the response excerpt; Test Connection no longer
     counts a PHP-level error as a granted probe; IDN server hostnames are converted to punycode
     with ext-intl and refused with advice without it.
   - **Servers-page script** matches rows by the resolved edit link; server-group links never
     anchor a row.
   - **Usage import:** `lastupdate` is stamped with PHP's clock, and the property lookup prefers
     the current product's row and never lets a blank hide a live id.
4. **Test infrastructure.** `tests/stubs/CapsuleDouble.php` is an in-memory stand-in for
   `WHMCS\Database\Capsule`. It is disabled by default, and tests opt in with
   `Capsule::seed()`. The guards are covered in `tests/Module/OwnershipAndSafetyTest.php` and
   `tests/Module/SharedClientScopeTest.php`; the readiness fixes also in
   `ServiceStatusSourceTest`, `WebsiteOnlySuspensionTest`, `ChangePackageSafetyTest` and
   `HooksTest`.
5. **Docs.** The first round corrected test counts, INSTALL section cross-references and the
   usage-column notes. This commit brings README, INSTALL, ROADMAP, TESTING,
   IMPLEMENTATION-NOTES and REMOTE-USER in line with the code: linking is manual in this build
   (no adoption resolver ships), the live-verification claims are qualified to
   `build/phase-0-1`, INSTALL §2 has an upgrade section, the PHP extension list is complete, and
   INSTALL §6 documents the one-service-per-client rule instead of recommending shared clients.

## Deferred work (to resume)

Line numbers below are approximate, because the fixes above moved code.

| Item | Where | Suggested fix |
|---|---|---|
| Orphaned client after a `client_add` timeout (M4b) | `lib/Provision/Recipe.php` `create()` | Before failing, or on a retry, look up `client_get_by_username`. If `notes` carries this service's marker (`Mapper.php`, "Managed by WHMCS service #N"), adopt that client or roll it back. |
| Reseller claims a sub-client's domain in Server Sync | `lib/Sync/AccountLister.php` (~272-284) | Pass only the client's own `default_group` to `client_get_sites_by_user`. When several clients claim one domain, give every claimant a synthetic identifier. |
| ListAccounts makes two HTTPS calls per client on fresh handles | `lib/Sync/AccountLister.php`, `lib/Api/CurlTransport.php` | Reuse one curl handle per transport for keep-alive, and call `set_time_limit` in `ispconfigconnect_ListAccounts`. |
| DNS zone IP comes from the WHMCS server record | `lib/Provision/Mapper.php` (~239) | Use the chosen web server's IP (`server_get`/`server_ip_get`). Refuse before `client_add` when no IP can be found. |
| ChangePassword also rotates the database-user password | `lib/Provision/Recipe.php` `changePassword()` | Make it a product option that is off by default, or give the database user its own random password at create time. |
| Sites always get unlimited `hd_quota`/`traffic_quota` | `lib/Provision/Mapper.php` (~136-137) | Read the template's `limit_web_quota` and `limit_traffic_quota` after `client_add`, and apply them again on ChangePackage. |
| A blank or deleted Client Template is not refused | `lib/Support/Params.php`, `Mapper.php` | Refuse `clientTemplateId <= 0`, and `webServerId <= 0` when any stage is on. |
| Stale client id is checked only by Terminate | entry file: Suspend, Unsuspend, ChangePassword, ChangePackage | Call `ispconfigconnect_clientExists()` and return `ispconfigconnect_staleRefusal()` the same way Terminate does. |
| Suspension mode is not recorded (F10) | `lib/Provision/Recipe.php` `suspend()`/`unsuspend()` | Store the mode used at Suspend and reverse that mode at Unsuspend, in case "Suspend Entire Client" changes in between. |
| The monthly bandwidth metric can be erased by suspension or ISPConfig's over-traffic lock (U3) | `lib/Metrics/Provider.php` | This needs the stored period value from WHMCS. Pass it in from the entry file and apply the same month high-water mark as `bwusage`. |
| The last day of each month's traffic is never read (U4) | `lib/Usage/UsageParser.php` `sumBandwidth()` | Also read the previous month's final day, or document the one-day lag. |
| The SSO button is unthrottled | entry file `ispconfigconnect_singleSignOn()` | Rate-limit per service. |
| Admins never see the SSO diagnosis in the UI | entry file `ispconfigconnect_singleSignOn()` | Choose operator detail with `defined('ADMINAREA')`. |
| The "login not allowed from IP" advice misses the Remote Access checkbox (F7) | entry file `ispconfigconnect_connectionAdvice()` | Name both causes in the advice. |
| The shared-client guard matches the stored text exactly | entry file `ispconfigconnect_otherServicesLinkedToClient()` | A hand-edited value such as `07` or ` 7` is read as client 7 by the property reader but is not matched by the guard's query. Normalise the value, or compare numerically. |
| Nothing in WHMCS identifies the deployed build | `whmcs.json` (still `0.2.0`) | Bump the version for each build; until then, identify a deployment by the file digest in `docs/LIVE-TEST-PLAN.md` §0. |
| Automatic adoption design (latent until `lib/Adopt/Resolver` ships) | entry file `ispconfigconnect_serviceLinkState()`, `ispconfigconnect_adoptUnlinked()` | Show a proposed match that an admin accepts, pass `userid` and status in the batch, and have Unlink set a "do not auto-adopt" marker. |

Tooling follow-ups from the test review:
- Add CI.
- Add composer `test` and `stan` scripts.
- Fail the run on deprecations.
- Move `modules/` to PHPStan level 8, which needs about three fixes.
- In the fake server, give user ids and group ids separate counters so a swap between them is
  caught.

## Resuming

```sh
git checkout claude/zen-franklin-z7oak2
composer install --no-interaction --no-progress      # retry with --prefer-source if GitHub zip downloads fail
vendor/bin/phpunit
php phpstan.phar analyse --no-progress --memory-limit=1G   # PHPStan 2.2.9 phar, downloaded separately
for sh in sh dash bash; do $sh -n modules/servers/ispconfigconnect/hooks/install-hook.sh; done
```

In the cloud container, `composer install` could not fetch PHPStan (a GitHub API 403), so the
PHPStan 2.2.9 phar was downloaded straight from its GitHub release instead.
