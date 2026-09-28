# Review and fix status (paused 2026-09-28)

This file records where the multi-reviewer code review and its fixes stand, so the work can be
picked up later without the original session. It belongs to draft PR #1
(`claude/zen-franklin-z7oak2` into `master`) and can be deleted before that PR is merged.

## Where things stand

- **Branch:** `claude/zen-franklin-z7oak2`, head `271cf59`, pushed. It is `build/phase-0-1`
  plus the fixes below.
- **PR:** wadejbeckett/ispconfig-connect-whmcs#1, draft, mergeable, no review activity. The PR
  description holds the full "Fixed" table.
- **Verification at pause:** 912 tests and 3572 assertions pass, also in random order. PHPStan
  level 6 reports no errors. `bash -n` is clean on the hook installer.
- **Not verified:** nothing has been run against a live ISPConfig panel. The reviewers could
  not reach the ISPConfig 3.3.1p1 source through the proxy, so panel behaviour was checked
  against the fake server and the module's own source-cited comments.
- **Monitoring:** the hourly PR check-ins and the PR subscription were stopped at pause.

## What was done

1. **Review.** Eight independent reviewers each covered one area: API transport, provisioning
   lifecycle, admin and client surfaces with sync and SSO, usage and metrics, hooks and
   installer, security, the test suite (with targeted mutation testing), and docs against code.
   Each finding was demonstrated with a throwaway test before it was ranked. The result was
   one Critical, ten Highs, nine Mediums and a set of Lows.
2. **Fixes.** The Critical, all ten Highs, and most Mediums and Lows are fixed, each with a
   regression test. The main themes:
   - One WHMCS service maps to one ISPConfig client. Link, Replace, adoption and every
     whole-client write refuse a client that another live service holds, so terminating one
     service can no longer delete another customer's hosting.
   - Clearing a link now blanks the stored ids on every product's custom-field rows, so
     Unlink, Terminate and Replace cannot bring back a previous tenant's ids after a package
     change.
   - Website-only Suspend falls back to a client lock when no website id is stored.
     ChangePackage keeps the lock state intact.
   - Bandwidth usage resets at the month boundary instead of billing every month at the
     highest month seen.
   - Security: the admin tab output is escaped, malformed panel responses are scrubbed before
     logging, the installer's temp-file symlink attack is closed, SSO requires an active
     service, and usage-read logs drop mailbox personal data and operator secrets.
3. **Test infrastructure.** `tests/stubs/CapsuleDouble.php` is an in-memory stand-in for
   `WHMCS\Database\Capsule`. It is disabled by default, and tests opt in with
   `Capsule::seed()`. The new guards are covered in `tests/Module/OwnershipAndSafetyTest.php`.
4. **Docs.** Test counts, INSTALL section cross-references and the usage-column notes were
   corrected.

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
bash -n modules/servers/ispconfigconnect/hooks/install-hook.sh
```

In the cloud container, `composer install` could not fetch PHPStan (a GitHub API 403), so the
PHPStan 2.2.9 phar was downloaded straight from its GitHub release instead.
