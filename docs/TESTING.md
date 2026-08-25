# Testing ISPConfig Connect for WHMCS

How this module is verified, at every level, and exactly how to re-run each level yourself.

The module sits between two systems that are both hard to test against: WHMCS core is
ionCube-encoded (its classes cannot be read, only called), and ISPConfig is a live panel that
provisions real accounts on a real server. Neither can be mocked away honestly, so the verification
is layered — each layer proves something the layer below it cannot.

| # | Level | Proves | Needs | Command |
|---|-------|--------|-------|---------|
| 1 | PHPUnit suite | Every branch of the module's own logic, against an executable model of the ISPConfig wire contract | Nothing but PHP | `php vendor/bin/phpunit` |
| 2 | Fake ISPConfig server | That the model in (1) matches ISPConfig 3.3.1p1's actual source | Nothing but PHP | `php vendor/bin/phpunit --testsuite unit` |
| 3 | PHPStan level 6 | Type correctness across the module and the suite | Nothing but PHP | `php vendor/bin/phpstan analyse -c phpstan.neon.dist` |
| 4 | `ispconfig-sso` harness | The panel-side SSO code against ISPConfig's remote.d and `$app`/db contracts. **Not in this repository** — it belongs to that separate project | Nothing but PHP, in that project's checkout | `php tests/run.php` there |
| 5 | Live lifecycle harness | Provision → suspend → change password → change package → terminate, end to end on a real panel | WHMCS + ISPConfig | see §5.1 |
| 6 | Live sync harness | That `ListAccounts` output survives WHMCS's own `SyncItem` class | WHMCS + ISPConfig | see §5.2 |
| 7 | Live SSO harness | Token lifecycle and its security properties on a real panel | WHMCS + ISPConfig + the `ispconfig-sso` project on the panel | see §5.3 |
| 8 | Live metrics harness | That the usage-billing **numbers** are right, in WHMCS's units, for a real service | WHMCS + ISPConfig | see §5.4 |

Levels 1–3 run offline in this repository and should be green before every commit; level 4 is the
same kind of check but lives in the separate `ispconfig-sso` project. Levels 5–8 run against a real
install and are the only way to close the gap between "the code does what the tests say" and "the
integration does what the two products actually do".

---

## 1. The PHPUnit suite

```sh
php vendor/bin/phpunit                     # everything
php vendor/bin/phpunit --testsuite unit    # lib/ classes in isolation
php vendor/bin/phpunit --testsuite module  # the WHMCS entry points, end to end
php vendor/bin/phpunit --filter UsageParser
```

Last full run (2026-08-25): **755 tests, 3081 assertions, OK**.

Two suites, deliberately separated:

**`tests/Unit`** exercises the `lib/` classes on their own — the API stack (`CurlTransport`,
`RestClient`, `SessionManager`, `Permissions`), the provisioning `Mapper`/`Recipe`, the usage
arithmetic (`QuotaMath`, `UsageParser`, `Collector`, `Updater`), the metric definitions, the sync
mapping, the SSO client, and the support layer. These classes never touch WHMCS, which is
what makes them testable at all.

**`tests/Module`** drives the **real** WHMCS entry points — `ispconfigconnect_CreateAccount()`,
`_SuspendAccount()`, `_UsageUpdate()`, `_MetricProvider()`, `_ListAccounts()`,
`_ServiceSingleSignOn()` and the rest — through the whole stack, with only the HTTP transport
substituted. `tests/Module/ModuleTestCase.php` is the base class; it defines the `WHMCS` constant,
loads the WHMCS class doubles, requires the entry file, and swaps the transport in via the Factory's
documented seam:

```php
Factory::$transportFactory =
    fn (ServerConfig $config): HttpTransportInterface => $this->fake;
```

`Factory::$transportFactory` is the module's one and only test seam. It exists so the module tests
can run the genuine code path; production code must never assign it.

**WHMCS's own types are doubled, not mocked.** `tests/Module/WhmcsClassDoubles.php` declares
`WHMCS\UsageBilling\Metrics\Metric`, `Usage`, the `Units\*` classes, `Contracts\Metrics\*` and
`WHMCS\Service\Status` with the same public shapes the developer documentation shows — no more.
`tests/stubs/whmcs-functions.php` does the same for `logModuleCall()` and `add_hook()`. Two rules
keep these honest:

- shapes come from developers.whmcs.com only, never from guesswork or decompilation;
- `ProviderInterface`'s methods are declared **without** parameter or return types, exactly as the
  documented sample implements them, so the module's adapter cannot declare types that would be
  incompatible with the real, encoded interface.

**What a green suite does *not* prove:** that WHMCS calls these functions the way the documentation
implies, that the values are right for a real account, or that anything works on a real panel. That
is what levels 5–8 are for.

---

## 2. The fake ISPConfig server

`tests/Support/FakeIspconfigServer.php` is a behavioural double of ISPConfig 3.3.1p1's
`remote/json.php` + `remoting.inc.php` + `remote.d/*.inc.php`. It implements the module's
`HttpTransportInterface`, so the entire stack above the socket is real in every test.

It reproduces, with a source citation on each:

- the request/response envelope (`{code, message, response}`) and every protocol-level error code;
- the exact fault **messages** the panel emits, which is all the module has to classify errors by —
  the original SoapFault code is never transmitted;
- session semantics: 1800 s fixed TTL, IP binding, and the per-IP failure counter that locks an
  address out for five minutes after ten failures (failed *session lookups* count too);
- `remote_functions` grant checking, including ISPConfig's grant-name aliases;
- `remoting_lib::getDataRecord()`'s three argument modes — a positive int is one record, an array is
  a `WHERE k = v` filter returning a list, `-1` is everything;
- the quota functions' real return shapes, including the ones that are traps: `quota_get_by_user`
  returns `SELECT *` over `web_domain` (so every row carries the site's TLS private key),
  `trafficquota_get_by_user` returns a map keyed by **domain name** whose period members are
  **absent** rather than zero, and a falsy client id makes it return **panel-wide** traffic while
  `quota_get_by_user` returns nothing — the module's single largest over-billing hazard.

The double has its own test (`tests/Unit/Fake/FakeIspconfigServerTest.php`): a double that drifts
from the source is worse than no double at all.

**Ground truth.** Everything above was established by reading the ISPConfig 3.3.1p1 source, not by
observing a running panel. `docs/IMPLEMENTATION-NOTES.md` §1 records the wire contract with file and
line citations. When you change anything in this area, re-read the source — extract the release
tarball and cite it.

---

## 3. Static analysis

```sh
php vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=1G
```

Level 6 over `modules/servers/ispconfigconnect` and `tests`, with
`tests/stubs/whmcs-functions.php` as a bootstrap file so WHMCS's global functions resolve. Last run
(2026-08-25): **no errors**.

---

## 4. The panel side is a separate project

Single sign-on needs `ispconfig-sso`, a standalone GPLv3 ISPConfig add-on by the same author. **It is
not part of this repository and is not shipped by this module** — any billing system can use it, and
this module merely detects it at runtime.

So it is not tested here, and there is nothing to run from this checkout. That project carries its
own standalone harness (`php tests/run.php` in its own checkout): panel-side code needs ISPConfig's
runtime (`$app`, its `db` class, the `remoting` base class) and cannot run under this repository's
PHPUnit suite at all.

What IS tested here is this module's behaviour **without** that project, because that is the default
of every fresh install: `tests/Unit/Sso/CompanionClientTest` proves detection answers a plain "no" on
a stock panel and that absence never turns into an exception, and `tests/Module/SingleSignOnTest`
proves the probe caches that "no", that `MetaData()` then advertises no SSO label, and that pressing
the button anyway explains which separate project to install. The presence path is exercised against
the fake panel's model of that project's one remote function.

---

## 5. Live harnesses

These run on a real WHMCS install talking to a real ISPConfig panel. They are the only evidence that
the integration works, and several of the module's design decisions exist *because* one of them
failed first.

They are **not** part of the repository. A live harness is written against one specific install —
its paths, server id, client id and test domain — and shipping those would be both useless to
everyone else and a leak of live infrastructure detail. What §6 gives you instead is the bootstrap
recipe they all share, so a maintainer can rebuild any of them in a few minutes.

Ground rules for every live harness:

- **Read-only unless the harness is explicitly a lifecycle test.** Inject a fake writer rather than
  letting the module write; assert afterwards that nothing changed.
- **Never point one at a customer's service.** Use a dedicated test client and test domain.
- **Print expected vs actual for every number**, labelled in plain language. A live harness is read
  by whoever is on call, not only by whoever wrote it.
- **Exit non-zero on any mismatch**, so it can be re-run from cron or a deploy script.

### 5.1 Lifecycle harness

Drives the full provisioning lifecycle through WHMCS's own `localAPI()` — so WHMCS builds `$params`,
not the harness — and asserts the resulting panel state with direct SQL against `dbispconfig`.

Covers: create (client, website, FTP user, mail domain, DNS zone, database), suspend, unsuspend,
change password, change package (client-template switch), and terminate. It reuses its product group
and products if they already exist, and refuses to run while a previous test service is still
Active, printing how to reset.

Terminate is a separate script on purpose: the main harness leaves the account **live** so a human
can inspect it in both UIs before it is torn down.

Verified end to end on WHMCS 9.0.6 + ISPConfig 3.3.1p1.

### 5.2 Sync harness

Feeds the module's real `ispconfigconnect_ListAccounts()` output through WHMCS's **own**
`WHMCS\Admin\Utilities\Tools\ServerSync\SyncItem` class — the exact class that threw
*"Unsupported unique identifier field"* — and reports how many accounts core accepted and rejected.

This is the pattern to copy whenever you need to prove something about an encoded WHMCS class:
you cannot read it, but you can **call** it with your real output and see what it does. It is how the
module learned that `ListAccountsUniqueIdentifierField` must be `'domain'` — core rejects anything
else.

### 5.3 SSO harness

Two halves, because SSO has two sides.

**Panel side** — exercises the `ispconfig-sso` token lifecycle directly against the panel's remote API:
mint, single-use (a redeemed token is burned), expiry, IP binding, refusal to mint for a client
session, refusal to mint for an administrator (an admin token would be a full panel takeover), and
the burn race (two parallel redemptions, exactly one wins). Read-only apart from the tokens it mints
for the test client. 20/20 security checks pass on the live panel.

**WHMCS side** — bootstraps WHMCS from the CLI, calls `ispconfigconnect_GetRemoteMetaData()`,
`_MetaData()`, `_ServiceSingleSignOn()` and `_ListAccounts()` for a real service, and prints the
result with the token redacted. This is what proves the conditional `ServiceSingleSignOnLabel`
appears only once the SSO presence probe has been cached in `tblservers_remote`.

### 5.4 Metrics harness — `metrics-live-check.php`

The newest and most detailed of the live harnesses. It exists because of a specific gap: the metric
**definitions** were confirmed live (all nine render on the product's Module Settings tab, each with
a working *Configure Pricing* link), but nothing had yet proved that the **values** are correct, in
the units WHMCS expects, for a real service — or that the collection path behaves the way WHMCS's
cron drives it.

#### What it does

It compares **three independent sources** for the same figures, so no check is circular:

- **A — the raw ISPConfig API.** The harness makes the same six read calls the module makes
  (`quota_get_by_user`, `trafficquota_get_by_user`, `mailquota_get_by_user`,
  `databasequota_get_by_user`, `sites_ftp_user_get`, `dns_zone_get`) and adds the answers up
  **itself**. The expected values are therefore never produced by the code under test.
- **B — the module's real code path.** `ispconfigconnect_MetricProvider($params)->tenantUsage(...)`
  and `ispconfigconnect_UsageUpdate($params, ...)`, called exactly as WHMCS calls them.
- **C — the panel database.** The same figures read straight out of `dbispconfig` with the exact
  queries `quota_lib.inc.php` runs, including the `getAuthSQL` scoping and the PHP-serialised
  `monitor_data` blob that disk usage actually lives in.

Section by section it prints:

1. **Environment** — WHMCS version, module file and mtime, the calendar month in scope (ISPConfig
   sums traffic per calendar month, so which month it is matters), and whether WHMCS module logging
   is on.
2. **The service under test** — the `tblhosting` row, what it currently stores for
   `diskusage`/`disklimit`/`bwusage`/`bwlimit`/`lastupdate`, and the two service properties
   (`ispconfig_client_id`, `ispconfig_client_username`) that link it to the panel.
3. **Metric definitions as WHMCS sees them** — the nine `Metric` objects with system name, display
   name, type and **unit class**, checked against the module's own catalogue.
4. **Raw ISPConfig figures** — every website's `used_raw` and quotas, the traffic map, mailboxes,
   databases, FTP users and DNS zones, printed row by row.
5. **Values the module hands WHMCS** — per metric: the raw panel figure, where it came from, the
   conversion applied, the expected value, the value actually handed to WHMCS, and the unit WHMCS
   will interpret it with. Plus unit sanity (megabyte metrics must be `MegaBytes`, counts must not
   be), type sanity (bandwidth must be `TYPE_PERIOD_MONTH`, everything else `TYPE_SNAPSHOT`), and a
   sweep over every **tenant shape** — WHMCS does not document what it passes to `tenantUsage()`, so
   the harness tries a service id, an account username, a plain object and a `WHMCS\Service\Service`
   model, and reports any shape the module cannot resolve.
6. **Panel database cross-check** — website/mailbox/database/FTP/DNS counts, disk usage rebuilt from
   `monitor_data`, and this month's traffic summed from `web_traffic`, each side by side with the
   module's number. It also prints the two headline queries so a human can re-run them by hand.
7. **UsageUpdate dry run** — the real `ispconfigconnect_UsageUpdate()` entry point with a **fake
   writer**, printing the `UPDATE tblhosting` that *would* run and whether `disklimit`/`bwlimit` are
   included. Nothing is written; the harness re-reads the row afterwards and asserts it is unchanged.
   It also checks that the two collection paths agree — `MetricProvider` and `UsageUpdate` read the
   same panel numbers through different call depths and must not disagree.
8. **`--sweep` (optional)** — both entry points over **every** service on the server with no
   narrowing, which is what the nightly cron does.

#### Running it

Copy the script onto the **WHMCS server** and run it there as a user that can read WHMCS's
configuration. The panel-database cross-check additionally needs a `mysql` client that can reach
`dbispconfig`; where the panel is a separate host, either run the harness there too, point
`--mysql=` at a remote client, or pass `--no-db` and run the printed queries by hand.

```sh
php metrics-live-check.php                  # the default service
php metrics-live-check.php <service-id>
php metrics-live-check.php --service=<id> --whmcs=/path/to/whmcs
php metrics-live-check.php --sweep          # also exercise the whole-server cron path
php metrics-live-check.php --verbose        # dump the raw API responses (secrets redacted)
php metrics-live-check.php --no-db          # skip the panel-database cross-check
php metrics-live-check.php --mysql='mysql --defaults-extra-file=/root/.my.cnf dbispconfig'
```

Exit codes: `0` every check passed (warnings allowed), `1` at least one check failed, `2` the
harness could not run at all.

#### Safety

Read-only with respect to both systems. It never provisions, suspends, invoices or writes a usage
figure; every ISPConfig call it makes is a `*_get` / `*_get_by_user` read; the `UsageUpdate` step
gets a writer that prints instead of writing, and the harness asserts afterwards that the
`tblhosting` row is byte-identical. The one side effect is WHMCS's own module log: if module logging
is enabled, the module records each API call in `tblmodulelog`, exactly as it does during a normal
cron run.

`--verbose` redacts anything whose key looks like a password, key, CSR or session id — necessary,
because `quota_get_by_user` is `SELECT *` over `web_domain` and its rows carry the site's TLS private
key.

#### Reading the output

Every line is labelled, and every comparison prints expected vs actual. A conversion looks like this:

```
  Disk Usage  (diskusage)
      ISPConfig raw figure    : 412,316,672 bytes (393.22 MB)
      where it comes from     : sum of used_raw over 1 website row(s) from quota_get_by_user
      conversion applied      : bytes / 1048576, rounded to the nearest whole megabyte
      EXPECTED value          : 393
      VALUE HANDED TO WHMCS   : 393
      WHMCS unit applied      : MegaBytes labelled "MB"
      WHMCS metric type       : snapshot
  [PASS] Metric diskusage value is correct  --  expected 393, module gave 393
```

Disk and traffic figures carry a 1 MB tolerance: they can genuinely change between the harness's
read and the module's read on a busy site. A difference inside the tolerance is reported as `WARN`,
anything larger as `FAIL`. Counts have no tolerance.

#### The units, and why they are the whole risk surface

ISPConfig speaks a different unit per field; WHMCS speaks megabytes only. The conversions live in one
place (`lib/Usage/QuotaMath.php`) with their own unit test, and this harness is what proves that
place is right against a real account:

| Figure | ISPConfig's unit | WHMCS's unit |
|---|---|---|
| `web_domain` disk `used_raw` | bytes (repquota KB × 1024) | megabytes |
| `web_domain.hd_quota` / `traffic_quota` | megabytes, `-1`/`0` = unlimited | megabytes, no unlimited sentinel |
| `web_traffic.traffic_bytes` | bytes, summed per calendar month | megabytes |
| `mail_user.quota` and used | bytes, `0` = unlimited | megabytes |
| `web_database.database_quota` | megabytes, expanded to `_raw` with a **decimal** 1000×1000 factor | megabytes |

The megabyte is binary (2²⁰) throughout — ISPConfig treats its own MB that way in
`300-quota_notify.inc.php` and `apache2_plugin.inc.php`.

One behaviour the harness checks explicitly, because getting it wrong would be expensive: when an
ISPConfig quota is **unlimited** (which is the module's normal provisioned state), `UsageUpdate`
**omits** the `disklimit`/`bwlimit` columns rather than writing `0`. Those columns belong to the
WHMCS admin and drive overage billing; writing a resolved `0` would silently zero every service's
configured limit, nightly.

---

## 6. Writing a new live harness

Every live harness shares the same four moves.

**1. Bootstrap WHMCS from the CLI and load the module.**

```php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
chdir('/path/to/whmcs');
require 'init.php';
require_once '/path/to/whmcs/modules/servers/ispconfigconnect/ispconfigconnect.php';
```

`init.php` defines the `WHMCS` constant (the entry file dies without it), registers the module's
`lib/` autoloader, and gives you `WHMCS\Database\Capsule` and the global helpers.

**2. Build `$params` by hand.** WHMCS's `getServerParams()` needs a service model you do not have in
a CLI context, so build the server half yourself and decrypt the stored credentials:

```php
$server = Capsule::table('tblservers')->where('id', $serverId)->first();
$params = [
    'serverid'         => $serverId,
    'serverhostname'   => $server->hostname,
    'serverip'         => $server->ipaddress,
    'serverusername'   => $server->username,
    'serverpassword'   => decrypt($server->password),
    'serveraccesshash' => decrypt($server->accesshash),
    'serversecure'     => $server->secure,
    'serverport'       => $server->port,
];
```

Add `serviceid`, `domain`, `username`, `userid` and `model` only for the entry points that need a
service in scope (`_ServiceSingleSignOn`, `_ClientArea`, `_AdminServicesTabFields`).

**3. Inject the test seams instead of letting the module write.** The two entry points that touch the
database accept optional callables for exactly this reason:

```php
$captured = [];
ispconfigconnect_UsageUpdate(
    $params,
    fn (int $serverId): array => [/* only the rows you want collected */],
    function (int $serviceId, array $columns) use (&$captured): void { $captured[] = [$serviceId, $columns]; }
);
```

`ispconfigconnect_MetricProvider($params, $rowProvider)` takes the same row-provider seam. Then
re-read the row and assert it did not change — proving read-only beats claiming it.

**4. Cross-check against the panel database.** WHMCS and the panel each have a database; the module
sits between them, so a harness that only reads one of them proves half of nothing.

```php
function ispc(string $sql): string {
    return trim((string) shell_exec('mysql dbispconfig -N --batch -e ' . escapeshellarg($sql) . ' 2>&1'));
}
```

Reproduce ISPConfig's own scoping rather than inventing your own filter, or the cross-check will
disagree for the wrong reason. For a client id `C`:

```sql
-- the client's groups, per functions.inc.php clientid_to_groups_list()
SELECT GROUP_CONCAT(groupid) FROM sys_group
 WHERE client_id IN (SELECT client_id FROM client WHERE client_id=C OR parent_client_id=C);

-- quota_lib::get_quota_data() scoping, with the group list above as G
SELECT * FROM web_domain
 WHERE type='vhost'
   AND ((sys_groupid IN (G) AND sys_perm_group LIKE '%r%') OR sys_perm_other LIKE '%r%');

-- quota_lib::get_trafficquota_data() scoping — note this one uses default_group, not the group list
SELECT COALESCE(SUM(traffic_bytes),0) FROM web_traffic
 WHERE YEAR(traffic_date)=YEAR(CURDATE()) AND MONTH(traffic_date)=MONTH(CURDATE())
   AND hostname IN (SELECT domain FROM web_domain
                     WHERE active='y' AND type IN ('vhost','vhostsubdomain','vhostalias')
                       AND sys_groupid=(SELECT default_group FROM sys_user WHERE client_id=C));
```

Disk usage has no column: it lives PHP-serialised in `monitor_data`, keyed by the website's
`system_user`, in repquota **kilobyte** blocks.

```sql
SELECT TO_BASE64(data) FROM monitor_data WHERE type='harddisk_quota' ORDER BY created DESC;
```

Unserialise each row (`stripslashes` first — ISPConfig stores it quoted), merge them the way
`quota_lib::get_quota_data()` does, then read `['user'][$system_user]['used'] * 1024` for bytes.

A last note on group ids: the client's own group is `sys_user.default_group`, **not**
`client.sys_groupid` — the latter is the *creator's* group and is the wrong answer. A client id whose
group list spans more than one group is a **reseller**, and its quota rows include its sub-clients';
the module refuses that case rather than over-bill, and a harness should flag it too.

---

## 7. What is still unproven

Verified live on WHMCS 9.0.6 + ISPConfig 3.3.1p1: provisioning lifecycle, suspend/unsuspend,
password rotation, package change, terminate, Sync Accounts, single sign-on (20/20 security checks),
the servers-page panel block, and the metric **definitions** reaching WHMCS.

Not yet proven, in rough order of risk:

- **Invoicing.** The metrics harness proves the values and the units. It does not prove that WHMCS
  turns a collected metric into the right line on the right invoice — that needs a billing cycle to
  actually run.
- **Behaviour under WHMCS's real cron**, as opposed to the same entry points invoked from a CLI
  harness. Run the metrics harness with `--sweep`, then compare against what the scheduled run
  produces in `tblmodulelog`.
- **Scale.** Every live run so far has been against a handful of services. The sweep's cost is
  roughly six API calls per service for metrics and two for usage; a few hundred services on one
  panel is the first interesting case, and the panel's per-IP lockout (ten failures in five minutes)
  is the thing to watch.
- **WHMCS 8.13.** Supported on paper, never run.
- **ISPConfig 3.2.** The wire contract was read from 3.3.1p1; older minor versions are expected to
  work but have not been exercised.
- **Multi-server panels.** All live testing has used a single ISPConfig node.

When you close one of these, add a row here and cite the run.
