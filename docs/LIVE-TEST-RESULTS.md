# Live test results and checkpoint (2026-09-28, build `32c9744`)

This is a checkpoint written at the end of a session, so the next one can resume without it.
It records a run of `docs/LIVE-TEST-PLAN.md` against a **real ISPConfig panel** and **real
MySQL WHMCS tables**, what passed, what failed, which failures were independently confirmed,
and what is still open. The module code has **not** been changed since `32c9744`; the fixes
for the defects below are the next session's work.

## What was tested against, and what was not

The user's own WHMCS could not be reached from the cloud session (no host, key or credentials
for it), and the ISPConfig download sites are blocked by its network policy. So the test ran
against an environment built inside the session:

- **ISPConfig:** the panel interface and remote JSON API from the ISPConfig `develop` branch
  (the 3.3 line; a public GitHub copy at `neuroradiology/ispconfig`), backed by MariaDB 10.11
  with `sql_mode=NO_ENGINE_SUBSTITUTION`, served by Ubuntu's PHP 8.3.6 (`php -S`) with curl,
  mbstring, intl, mysqli and **soap** (the JSON API needs `SoapFault`). The ISPConfig server
  daemon (`server.sh`) does **not** run: jobs stay queued in `sys_datalog`, and assertions are
  made on what the interface writes (`client.locked`, `client.tmp_data`, `web_domain.active`,
  `ftp_user.active`, and so on). No web, mail or FTP daemons.
- **WHMCS:** WHMCS itself cannot be installed (licensed), so `tools/live-harness/harness.php`
  plays its part: Laravel's Illuminate query builder (what `WHMCS\Database\Capsule` is) over
  WHMCS's real table layouts, a `Service\Properties`-like store (current-product custom fields,
  auto-created admin-only), `$params` built as `buildServiceParams` builds them, and WHMCS's
  status change after a `success`. Two known gaps, found during verification:
  1. It changes a service's product with a bare `UPDATE tblhosting.packageid`. Real WHMCS runs
     `migrateCustomFieldsBetweenProductsOrAddons()` first, which **deletes the old product's
     value rows and creates them on the new product**. Several findings below (MOD-1, the
     upgrade slice's 2.6 items, DEF-1) were reached through the bare update; the verifiers say
     the real route is narrower. The harness should gain that migration before the next run.
  2. The service model has no `save()`/`username`, so Create's username write-back is lost, and
     `logModuleCall` JSON-encodes arrays before masking (WHMCS is believed to `print_r` them).
- Not exercised at all: WHMCS's own pages and cron, the `ispconfig-sso` add-on (not installed:
  SSO success paths), FTP/MySQL logins with rotated passwords, a vhost really stopping.

**The user's own live test on a WHMCS test install remains the final gate.** This run is the
best available proof short of it.

## Results by slice

229 checks in all: **191 PASS, 17 FAIL, 11 PARTIAL, 10 not testable here.** Every FAIL was
handed to an independent verifier that reproduced it from scratch on its own panel instance.
The verifiers finished 18 of the 22 findings before the session ended (the sharing and
lifecycle and connection slices fully; the usage/SSO/hooks and upgrade slices not at all).

| Slice (plan steps) | PASS | FAIL | PARTIAL | N/T |
|---|---|---|---|---|
| connection (4-8, meta data) | 43 | 1 | 1 | 2 |
| lifecycle (9-16b) | 49 | 5 | 2 | 1 |
| sharing (17, 18, 23, 23b, per-panel, addons, duplicates) | 35 | 3 | 1 | 3 |
| usage-sso-hooks (3, 19-22, 24) | 28 | 3 | 2 | 4 |
| upgrade (plan §2 checks, §4 rollback, old build 1ed05b3 -> new -> old -> new) | 36 | 5 | 5 | 0 |

What passed includes everything the first-round fixes were for: Create with all seven objects
and the eight stored ids; the 13-probe Test Connection and per-group grant reporting; hostname
refusals before any request; one failed login per action; redirect not followed; 120-byte
excerpt cap; entire-client suspend/unsuspend with a hand-disabled canary kept disabled;
website-only suspend; Link/Replace storing the website id; Change Package refused while locked
and re-suspending in the new mode; the shared-client refusals with no panel login; per-panel
scoping with the username rule (both directions, incl. two server records for one panel under
different addresses); Change Password rotating panel, FTP and DB credentials in the panel DB;
Terminate deleting every object and blanking every product's rows; stale-link refusal; product
addon on a linked parent refused, on an unlinked parent working; the installer (root, owner,
symlinked path, `--status`, `--uninstall`); usage import figures and the month high-water mark;
the nine metrics; Server Sync; and every plan §2 upgrade SQL query running on real MySQL.

## Confirmed module defects (verified by reproduction; none fixed yet)

Ordered by the verifier's severity. Full reporter and verifier text, with reproduction
scripts, is in `tools/live-harness/results/livetest-full.json`.

| Id | Sev | What | Fix direction (from the verifier) |
|---|---|---|---|
| **MOD-2** | medium | A real `ispconfig_suspended_as` record is hidden by a newer **blank** row on the product a suspended service moved to: the fallback reader takes the single newest row per pass and skips it if blank, so Unsuspend reads "no record" and wrongly refuses a locked client on a website-only product. | Make the record one value per service: `setSuspendedAs()` writes through the model **and** updates every existing `ispconfig_suspended_as` row of the service across products (a "fallback writer" wired like the clearer); the reader skips blank rows (`value <> ''`) in both passes, only together with the writer; fix the refusal text that claims "no record". |
| **SH-2** | medium | A client held by an ISPConfig **product addon** is invisible to the shared-client guard: the guard and `effectivePropertyValues()` look at `type='product'` rows only. Link/Terminate on a product can hit an addon's client. | Also query `type='addon'` rows (join `tblhostingaddons` on relid, status exclusion on `tblhostingaddons.status`, server from `tblhostingaddons.server`); self-exclude by holder identity (product+serviceid, or addon+addonId), not by bare relid. |
| MOD-1 | low | Unsuspend blanks the record only on the current product; an older product's `client` row comes back through the fallback reader on an Active service, and `resaveAll()` copies it forward. Real WHMCS's product-change migration makes this rarer than the harness shows. | Same writer as MOD-2; leave `KEY_SUSPENDED_AS` out of `resaveAll()` (Change Package re-records the mode when it re-suspends). |
| SH-1 | low | Duplicate `(fieldid, relid)` value rows (real WHMCS has **no unique key** there): `$model->serviceProperties->get()` and the module's newest-row queries can pick different rows, so the reader, guard and sweep disagree. | One rule everywhere: newest **non-blank** row of the current product, else newest non-blank of any product; skip blanks when filling `$current`/`$any`; guard counts a candidate if ANY non-blank current-product row matches. |
| SH-3 | low | Non-canonical stored ids (`' 4'`, `04`, `4.0`, `+4`, `4e0`) are read as client 4 by `intValue()` (`is_numeric` + cast) but the guard's SQL string equality misses them: a Terminate on the canonical holder can delete a client a non-canonical holder still uses. | One parse helper for both sides; either compare parsed ints in PHP after fetching non-blank rows, or make the reader strict (`/^[1-9][0-9]*$/`) and treat anything else as corrupt. |
| SH-4 | low | Stale link (panel client gone): only Terminate refuses. Suspend/Change Package return raw validator keys (`contact_error_empty…`), Change Password a raw sentence, and Unsuspend of the Active service reports `success`. | Call `ispconfigconnect_clientExists()` right after the session opens in Suspend, Unsuspend, Change Package and Change Password (both modes), returning `staleRefusal()`; in Suspend, restore the previous mode record on that refusal (it is written before the session). |
| SH-6 | low | An addon provisioned on a then-unlinked parent cannot be suspended or terminated after the parent is linked (refusal fires before any session; its text never names the addon's own live client). | When the parent is linked, first read the addon's own id with no parent-keyed lookup; if present and different from the parent's, let Suspend/Unsuspend/Terminate run on it; if equal, keep refusing; name the addon's client in the refusal and give recovery steps. |
| MOD-4 | low (mostly docs) | After Unlink + Link, only client id, username and website id are stored, so Change Password rotates the panel login only and says `success`. | Do **not** resolve FTP/DB users on Link (it would rotate a migrated customer's hand-made credentials). Document it in INSTALL "What a password change rotates"; optionally log the skipped objects. |
| D2 | low | Servers page: after a **connection-level** failure (wrong password, lockout, redirect, HTML page, refused) the SSO line says "Cannot tell - grant get_function_list", because GetRemoteMetaData writes `sso_state=unknown` for both "probe refused" and "never got a session". | Write `SsoAdvice::STATE_UNREACHABLE` in the `$abortedOnConnection` branch; map `unreachable` in the hook to "Not checked - the panel could not be reached or logged in"; pick the skip sentence in `RemoteMetaData::gather` by exception class. Redeploy the hook. |
| D3 | low | No `ispconfigconnect_RenderRemoteMetaData`/`_GetUserCount`. Decoded WHMCS core gates the servers-page block on **function existence**, not on bundled modules (third-party modules use it), so the hook's JS could be replaced by the supported path, and `num_accounts` is writable via `GetUserCount`. IMPLEMENTATION-NOTES §6.2/§6.3 are wrong about the reason. | Add both functions (escape every value; memoise the client count per serverid so refresh + cron do not log in twice); keep the hook as a fallback until verified on WHMCS 9.0.x; correct the notes. |
| D5 | low | Verify Permissions on a server with bad credentials/lockout/IP block returns the raw panel sentence instead of the advice Test Connection gives. | In its catch: `ispconfigconnect_isConnectionLevel($failure) ? 'Verify Permissions failed: ' . ispconfigconnect_connectionAdvice($failure) : errorString(...)`. |
| SH-5 | low (narrow) | Two ISPConfig addons on one parent with the parent's empty username collide (`username_error_unique`). Real WHMCS generates a unique username first, so only "parent with no domain, random usernames off" reaches it. | Optional: suffix the generated username with the addon id when `addonId > 0`; make the client note addon-aware; turn `username_error_unique` into a readable sentence. |

## Confirmed docs / test-plan defects

| Id | Sev | What to change |
|---|---|---|
| DOC-2 | medium | Plan 16b(c) and INSTALL: after the admin removes a hand-set lock on a client whose service the module suspended website-only, ISPConfig's unlock restores `prev_active`, which turns the website **off again** while WHMCS shows Active. Document it and say what to do (press Suspend then Unsuspend). |
| D1 | low | Plan step 6: unticking "Server functions" also removes `client_templates_get_all` and `get_function_list` (per `admin/lib/remote.conf.php`); the expectation lists `server_get` only. |
| D4 | low | Plan step 4: WHMCS runs the typed Hostname through `WHMCS\Filter\HostAddress` before the module sees it, so some malformed values never reach the module's refusal. |
| DOC-1 | low | Plan steps 12/16/16b: P2 creates no mail domain; S2 is left on P1 after 16(b); extra calls (client_get before set_status) are not listed. |
| E1 | low | The develop-branch panel writes no `auth.log` line for remote-API logins; the plan's `auth.log` checks were verified at the wire level. Check against 3.3.1p1 live. |
| SH-7 | low | Plan step 17: the forced-share INSERT and some expected texts need updating (see the results JSON). |

## Findings not yet verified (the session ended first)

The reporters' analysis is in the results JSON; each still needs the same reproduction-based
check before it is acted on. Marked with the reporter's severity/kind.

- **DEF-1** (high, docs-or-plan, upgrade slice): "old-build stale product rows are not
  *ignored*: after a product move **back**, the new build acts on the previous client,
  Terminate included." Reached through the harness's bare `packageid` update; real WHMCS's
  row migration on a product change probably prevents it. Verify with the migration modelled.
- **DEF-2** (medium, module): Unlink returns "nothing to clear" and skips the cross-product
  clearer when the service reads as unlinked, so the remedy in plan §2.6 does nothing for the
  old build's blank-current-row services; the same root cause leaves a non-numeric id in place.
- **DEF-3** (medium, docs): after a rollback to the old build, `ispconfig_suspended_as`
  records stay behind unmaintained, and a later re-upgrade trusts them. Plan §4 says "nothing
  to undo". (Mitigated by `currentSuspensionRecord()` ignoring records on Active services, but
  a service suspended by the old build during the rollback keeps a stale record.)
- **DEF-4 to DEF-7** (low, docs): plan §2.5 SQL counts stale old-product rows; §2.6 says a
  blank current row makes the module read the old value (it reads nothing, which is the safe
  direction); §2.5 says Link/Replace are refused on shared clients (on a service that already
  shares, they succeed as a no-op); §2.8 wording for non-numeric ids.
- **DEF-8** (low, module): two sharers both suspended by the old build refuse each other's
  Unsuspend, and the refusal's first suggestions are impossible to follow.
- **MOD-KEYMASK** (medium, module, usage slice): `web_domain.ssl_key` and `client.id_rsa`
  reach `logModuleCall` raw; only WHMCS's by-value `str_replace` masks them, so a multi-line
  PEM in `client.id_rsa` was stored verbatim in the harness's module log. Check what WHMCS
  really does with `$replacevars`, then project those fields out before logging.
- **PLAN-20-21** (medium, plan): step 20 deletes the seeded `web_traffic` row that step 21
  then expects ("about 500 MB"). **PLAN-21-SSLKEY** (low): "no ssl_key anywhere" contradicts
  the design (key name logged, value masked).
- **MOD-SSO-UNKNOWN-ADVICE**: the same defect as D2 (confirmed there).
- **SH-8** (environment): the develop-branch panel leaves `database_password_sha2`/postgres
  NULL on remote-API DB user create/rotation; relevant only for MySQL 8 caching_sha2 auth.
- **ENV-MODEL-SAVE**, **ENV-LOGMODULECALL**: harness gaps (see above), not module defects.

## Not done

1. **No module fix has been made for any of the above.** `32c9744` is still the build in the
   plan's digest table and in the PR.
2. The four unverified groups above.
3. The docs/plan corrections listed above.
4. The harness improvements: model `migrateCustomFieldsBetweenProductsOrAddons()` on a product
   change; give the model `save()`/`username`; make `logModuleCall` mask like WHMCS.
5. The user's own live test on their WHMCS test install (plan §3), which none of this replaces.

## Resuming

The cloud container is ephemeral; everything reusable is in `tools/live-harness/`
(`README.md` there has the exact rebuild steps: MariaDB, Ubuntu PHP 8.3 with soap, the ISPConfig
mirror clone, `make-panel.sh`, `composer install` for the harness, `smoke.php`). The five
slices' scripts are under `tools/live-harness/slices/<slice>/`, each with a `run-all.sh` or a
numbered run order in its `harness_notes` (in the results JSON). Suggested order for the next
session:

1. Fix MOD-2/MOD-1/SH-1/SH-3 together (they are one design: one value per service, one
   duplicate-robust read rule, one id parse rule), with tests in `SuspensionModeTest`,
   `SharedClientScopeTest` and `UsageUpdateTest`.
2. Fix SH-2 and SH-6 (addon-aware guard and addon self-service), SH-4 (stale check on every
   whole-client write), D2, D5; then D3 if it is verified on WHMCS 9.0.x.
3. Verify DEF-1..8, MOD-KEYMASK and the two PLAN items with the harness migration modelled;
   fix what holds.
4. Update INSTALL, the plan (DOC-1/2, D1, D4, PLAN-20-21, SH-7) and REVIEW-STATUS; recompute
   the plan's digest table; push; update the PR.
5. Re-run the five slices against the new build.
