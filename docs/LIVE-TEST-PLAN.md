# Live test plan: WHMCS test install

This plan takes the build on `claude/zen-franklin-z7oak2` through a real WHMCS and a real
ISPConfig panel. The unit and module suites prove the code against a fake panel and an
in-memory database. This plan is what proves it against the real ones, and it is the gate
before any production use.

Run it on a **test** WHMCS and a **test** ISPConfig panel only. Several steps write to the
WHMCS database with SQL, fail logins on purpose, or delete ISPConfig clients.

Paths such as `/path/to/whmcs` and names such as `<web-user>`, `<S1>` and `<N>` are
placeholders. Menu paths marked "verify path" differ between WHMCS versions.

## 0. Build identity

`whmcs.json` still says version 0.2.0, the same as the earlier build, so identify the
deployed build by checksum. From the repository root:

```sh
git rev-parse HEAD
cd modules/servers && find ispconfigconnect -type f | LC_ALL=C sort | xargs sha256sum | sha256sum
find ispconfigconnect -type f | wc -l
```

| Build | Commit | Files | Digest |
|---|---|---|---|
| This build | the head of `claude/zen-franklin-z7oak2` that carries this table (`git log -1 -- docs/LIVE-TEST-PLAN.md`) | 58 | `4fdf357f05bbb57fb9e7292a645bb387cdcc29cf3433b91416b0d090bfadc364` |
| Earlier live-verified build (`build/phase-0-1`) | `1ed05b3` | 57 | recorded in the prerequisites backup |

Per-file digests for the three files most worth checking by hand:

| File | sha256 |
|---|---|
| `ispconfigconnect.php` | `c796ad137bfb2f82a58f0192acd5f4fb4a053042001f7cdb23f611391e82404f` |
| `hooks/ispconfigconnect.php` | `7a2f7bf20dc392d92294f854799336c649a3fbb8d96b478747072b59b7cdafc1` |
| `hooks/install-hook.sh` | `36dbd86532c14755510c2bada8bbbcce7f2cc729f332f98cc5b36fb630c3dc26` |

Run the same `find | sha256sum` on the WHMCS host after deploying. It must match this table.
If a later commit changes anything under `modules/servers/ispconfigconnect/`, this table is
out of date: recompute it from that commit before testing.

## 1. Prerequisites

1. **WHMCS**: a test install on 9.0.x. The earlier build was verified on 9.0.6; 8.13 is
   untested. PHP 8.2 or 8.3. Root shell, the ability to run commands as the web user
   (`sudo -u <web-user>`), and read/write SQL access to `tblhosting`, `tblproducts`,
   `tblcustomfields`, `tblcustomfieldsvalues`, `tblservers`, `tblservers_remote` and
   `tblmodulelog`. `SHOW COLUMNS FROM tblcustomfieldsvalues LIKE 'id';` must return a row: the
   property reader orders by it.
2. **PHP extensions**, checked with `php -m` in BOTH the web SAPI (PHP-FPM) and the CLI that runs
   `crons/cron.php`: `curl` (with SSL) and `ctype` are required. `mbstring` is optional (there
   is a fallback). `intl` is recommended: without it an internationalised (IDN) server
   hostname must be entered in `xn--` form, and IDN customer domains are not converted. Know how
   to reload PHP-FPM or reset OPcache.
3. **Logging**: turn on module debug logging (Configuration > System Logs > Module Log >
   Enable Debug Logging; verify path). Know where the WHMCS activity log and the PHP-FPM error
   log are.
4. **ISPConfig test panel** on 3.3.x (3.3.1p1 matches the earlier live proof), with root SSH,
   MySQL access to `dbispconfig`, the server cron running, and `/var/log/ispconfig/auth.log`
   readable. To watch the job queue:
   `SELECT d.datalog_id,d.dbtable,d.dbidx,d.action,FROM_UNIXTIME(d.tstamp) at,(d.datalog_id>s.updated) pending FROM sys_datalog d JOIN server s ON s.server_id=d.server_id WHERE d.datalog_id > <MARK> ORDER BY d.datalog_id;`
5. **Remote user**: System > Remote Users > a dedicated user (for example `whmcs-connect`) with a
   long random password, Remote Access on, Remote Access IPs set to the WHMCS egress IP, and the
   9 function groups from `docs/REMOTE-USER.md`: Server, Client, Quota, Sites Domain, Sites
   FTP-User, Sites database, Mail domain, DNS zone and Monitor functions. Create a second,
   throwaway remote user for the negative tests. For the SSO steps, install the separate
   `ispconfig-sso` project over HTTPS and tick its grant; otherwise skip the SSO login checks.
6. **ISPConfig objects**: two master client templates with visibly different limits, "Basic"
   and "Pro"; the stock DNS template; one web/mail/DB server.
7. **WHMCS servers**:
   - `ISPC-TEST`: bare Hostname (for example `panel.test.example`), Port 8080, Secure ticked,
     group G1.
   - `ISPC-NEGATIVE`: the same panel and the same Hostname, the throwaway remote user, its own
     group G2, no products. Use it for every hostname, wrong-password and transport test, so
     the real credentials never go to a wrong host.
   - Optional, `ISPC-TEST-2`: a second, different ISPConfig panel in group G3. Needed for the
     per-panel check in step 17.
8. **WHMCS products**, all "ISPConfig Connect", group G1, set to "Do not automatically setup":
   - P1 "ISPC Entire": template Basic; Create Website, FTP, Mail Domain, DNS Zone and Database
     all on; NS1/NS2 filled; **Suspend Entire Client on**. Enable the usage-billing metrics.
   - P2 "ISPC Website-only": template Basic; Create Website and FTP on; **Suspend Entire Client
     off**.
   - P3 "ISPC Pro": as P1 with template Pro. This is the Change Package target.
9. **Test clients** C1 and C2 in WHMCS. The test domains must reach the panel's web server
   (DNS you control, or hosts-file entries) so a site can be checked with `curl`.
10. **Backups before deploying**: a WHMCS DB dump; `tar czpf ispcc-prefix-backup.tgz -C /path/to/whmcs modules/servers/ispconfigconnect includes/hooks/ispconfigconnect.php`;
    the `find | sha256sum` digest of the currently deployed module; a `mysqldump` of
    `dbispconfig`.
11. **Login lockout discipline**: failed API logins are counted per source IP, and both WHMCS
    servers share the WHMCS IP. Allow at most 3 deliberate failures per minute. Check
    `SELECT * FROM dbispconfig.attempts_login;` before each positive step. On the test panel
    only, a stuck lockout can be cleared by deleting that row.

## 2. Upgrade checks (before any live action)

These apply when upgrading a WHMCS that already runs an earlier build. On a fresh install,
skip to section 3.

1. **Deploy the whole directory**: `rsync -a --delete <repo>/modules/servers/ispconfigconnect/ /path/to/whmcs/modules/servers/ispconfigconnect/`.
   A plain `cp -r` over the old directory leaves stale files. This build adds
   `lib/Provision/ClientLockedException.php`. Chown to the WHMCS owner, reload PHP-FPM or reset
   OPcache, and recompute the digest (section 0).
2. **Old hook files**: if `modules/servers/ispconfigconnect/hooks.php` exists (from builds before
   b1d595f), delete it. `grep -l '@ispconfigconnect-hook-file@' /path/to/whmcs/includes/hooks/*.php`
   must list only `includes/hooks/ispconfigconnect.php`; delete any other copy. The installer
   exits 1 while a stray marked copy remains.
3. **Re-run the hook installer** (the hook changed). See step 3.
4. **Server Hostname fields**:
   `SELECT id,name,hostname,ipaddress,port,secure FROM tblservers WHERE type='ispconfigconnect';`
   Each Hostname (or IP Address, when Hostname is empty) must be a bare host name, IPv4 or IPv6
   address: no scheme, `:port`, path, `user@` or whitespace, and never both fields empty. The
   module refuses anything else before sending a request. The port belongs in the Port field.
   A Unicode (IDN) host name is converted when `intl` is loaded, and refused with a message
   asking for the `xn--` form when it is not.
5. **Services that share one ISPConfig client**:

   ```sql
   SELECT v.value client_id,
          GROUP_CONCAT(DISTINCT CONCAT('svc ',h.id,' srv ',h.server,' ',h.domainstatus) ORDER BY h.id SEPARATOR '; ') services,
          COUNT(DISTINCT h.id) n, COUNT(DISTINCT h.server) servers
   FROM tblcustomfieldsvalues v
   JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname='ispconfig_client_id'
   JOIN tblhosting h ON h.id=v.relid
   WHERE v.value<>'' AND h.domainstatus NOT IN ('Terminated','Cancelled','Fraud')
   GROUP BY v.value HAVING n>1;
   ```

   Rows whose services are on the same panel are real shared clients. The module now refuses
   Terminate, entire-client Suspend, Change Password, Change Package, Link and Replace on them,
   and allows entire-client Unsuspend only while no other sharer is Suspended. Decide which
   service owns each client and "Unlink from ISPConfig" the others. Rows that span two
   **different** panels are just the same number on two panels and are not refused, provided
   the two WHMCS server records share no Hostname or IP Address AND the services store
   different `ispconfig_client_username` values. Where a username is missing, store it (Replace
   ISPConfig Link with the right Username) or expect a refusal.
6. **Custom-field rows left on old products**:

   ```sql
   SELECT v.relid service_id,h.packageid current_product,h.domainstatus,f.relid row_product,f.fieldname,v.value
   FROM tblcustomfieldsvalues v
   JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname LIKE 'ispconfig\_%'
   JOIN tblhosting h ON h.id=v.relid
   WHERE v.value<>'' AND f.relid<>h.packageid ORDER BY v.relid,f.fieldname;
   ```

   Where the current product's row holds a value, the old row is ignored. Where the current
   product's row is blank or missing, the module still reads the old value. If that service
   should not be linked, press "Unlink from ISPConfig" on it: Unlink now blanks every product's
   rows.
7. **Custom-field names**: `SELECT id,relid,fieldname FROM tblcustomfields WHERE type='product' AND fieldname LIKE 'ispconfig%';`
   must return only these exact keys: `ispconfig_client_id`, `ispconfig_client_username`,
   `ispconfig_domain_id`, `ispconfig_ftp_user_id`, `ispconfig_mail_domain_id`,
   `ispconfig_dns_zone_id`, `ispconfig_database_id`, `ispconfig_database_user_id`, and
   `ispconfig_suspended_as` (new in this build; WHMCS creates it on the first Suspend). A renamed
   field (for example `ispconfig_client_id|Client ID`) is invisible to the shared-client check.
8. **Non-positive stored ids**:
   `SELECT v.relid,v.value FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid WHERE f.fieldname='ispconfig_client_id' AND v.value<>'' AND (v.value NOT REGEXP '^[0-9]+$' OR v.value='0');`
   Terminate now refuses these as stale. Unlink or relink them.
9. **Website-only services with no stored website id**:

   ```sql
   SELECT h.id,h.domain,h.domainstatus FROM tblhosting h
   JOIN tblproducts p ON p.id=h.packageid AND p.servertype='ispconfigconnect' AND p.configoption13=''
   WHERE h.domainstatus IN ('Active','Suspended') AND NOT EXISTS (
     SELECT 1 FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid
     AND f.type='product' AND f.fieldname='ispconfig_domain_id' WHERE v.relid=h.id AND v.value<>'');
   ```

   Suspend and Unsuspend now **refuse** these services instead of doing nothing (the earlier
   build) or locking the whole client. For each one, press "Replace ISPConfig Link" with the
   Username field set to its ISPConfig account: it stores the website id when the service
   domain matches exactly one of that client's websites. Services listed as `Suspended` were
   never actually suspended on the panel by the earlier build: press Suspend again after
   storing the id.
10. **Damage left by earlier package changes**: `SELECT client_id,username,locked,tmp_data FROM dbispconfig.client WHERE locked='y';`
    A client that had a Change Package while locked may have `prev_active` marking every object
    `n`; Unsuspend will then restore nothing, so plan to re-enable those objects by hand.
    Website-only suspended services that had a Change Package may have their site active again;
    check `web_domain.active`.
11. **Bandwidth figures** (billing): compare
    `SELECT id,domain,bwusage,lastupdate FROM tblhosting WHERE server=<id> AND domainstatus IN ('Active','Suspended') ORDER BY bwusage DESC;`
    with the panel's
    `SELECT hostname,SUM(traffic_bytes)/1048576 mb FROM web_traffic WHERE YEAR(traffic_date)=YEAR(CURDATE()) AND MONTH(traffic_date)=MONTH(CURDATE()) GROUP BY hostname;`.
    The earlier build could carry a previous month's peak forward. For services not suspended
    this month whose `bwusage` is well above the panel figure, set `bwusage=0` before the next
    sweep and before overage billing.
12. **Server metadata**: open Configuration > System Settings > Servers once, to refresh the
    cached panel metadata and single sign-on state.
13. **Migration expectations**: this build ships no automatic adoption resolver. Unlinked
    services are linked with "Link to ISPConfig Account", and the messages say so.

## 3. Test steps

Record each step as pass or fail, with the Module Log entries it produced.

### Step 1: Local build gate

On the workstation, from the repository root: `git status --short`, `git rev-parse HEAD`,
`vendor/bin/phpunit`, PHPStan (`php phpstan.phar analyse --no-progress --memory-limit=1G`),
`sh -n modules/servers/ispconfigconnect/hooks/install-hook.sh`, and the digest from section 0.

**Expected**: a clean tree; HEAD equals the remote branch head; PHPUnit OK; PHPStan
`[OK] No errors`; `sh -n` silent; the digest matches section 0.

### Step 2: Deploy

Take the backups (prerequisite 10), then do upgrade check 1. Lint every file with the WHMCS PHP
binary: `find /path/to/whmcs/modules/servers/ispconfigconnect -name '*.php' -exec php -l {} \;`.
Then run upgrade checks 2 to 13.

**Expected**: "No syntax errors" for every file; the host digest matches section 0; WHMCS
admin pages load; no new fatals in the PHP-FPM error log.

### Step 3: Hook installer

As root, from wherever the module was unpacked (a private checkout is fine):
`sh <path>/modules/servers/ispconfigconnect/hooks/install-hook.sh /path/to/whmcs`, then run it
again, then with `--status`.

Optional: create `/root/whmcs` as a symlink to the WHMCS directory and run the installer
with `/root/whmcs`; it must install to the physical path and print it.

**Expected**: first run prints `installed /path/to/whmcs/includes/hooks/ispconfigconnect.php`,
exit 0. Second run prints `already up to date: ...`, exit 0. `--status` prints
`installed and up to date: ...`, exit 0. `includes/hooks/ispconfigconnect.php` is owned by the
owner of `includes/hooks` (not root), mode 0644. No `.ispconfigconnect.php.XXXXXX` temp file is
left, and no "WARNING - another copy" appears.

### Step 4: Hostname validation (ISPC-NEGATIVE only)

Set ISPC-NEGATIVE's Hostname to each of these in turn, pressing Test Connection each time:
`https://panel.test.example`, `panel.test.example:8080`, `panel.test.example/`,
`user@panel.test.example`, `panel test.example`, `[2001:db8::1]:8080`. Keep the panel's
`auth.log` and the Module Log open. Restore the bare hostname afterwards.

**Expected**: each returns `Server Hostname/IP must be a bare host name or IP address (no scheme, port, path or user) - <reason>: "<value>"`,
with reasons, in order: it includes a URL scheme; it includes a port (set the port in the WHMCS
Port field); it includes a path or slash; it includes user information; it contains
whitespace; it is not a valid [IPv6] literal. `auth.log` gets no new line and the Module Log no
new ISPConfig Connect entry for these clicks: the refusal happens before any request.

### Step 5: Test Connection with all grants

On ISPC-TEST press Test Connection, then read the Module Log and `auth.log`.

**Expected**: `Connected. Single sign-on: ...`. The Module Log shows the login, then 13 read
probes (`server_get_all`, `client_templates_get_all`, `monitor_jobqueue_count`, `client_get`,
`client_get_all`, `client_get_by_username`, `client_get_sites_by_user`, `sites_web_domain_get`,
`sites_ftp_user_get`, `dns_zone_get`, `quota_get_by_user`, `mailquota_get_by_user`,
`databasequota_get_by_user`), then `get_function_list` and the logout. A "no such client"
style answer to a probe is NOT reported as a missing grant. Passwords and the session id are
masked. `auth.log` gets exactly one successful API login.

### Step 6: Reduced grants

In ISPConfig, untick ONE function group on the remote user, save, press Test Connection, and
press Verify Permissions on any service. Repeat for Quota, Client, Sites FTP-User, DNS zone,
Sites Domain and Server functions. Re-tick all groups at the end.

**Expected**: `Connected and logged in, but the remote user is missing remote_functions grants: ...`
naming only that group's probed functions (Quota: `quota_get_by_user`, `mailquota_get_by_user`,
`databasequota_get_by_user`; Client: `client_get`, `client_get_all`, `client_get_by_username`,
`client_get_sites_by_user`; FTP: `sites_ftp_user_get`; DNS: `dns_zone_get`; Sites Domain:
`sites_web_domain_get`; Server: `server_get`). Verify Permissions shows the same list. After
re-granting, both are clean.

### Step 7: Failed login (ISPC-NEGATIVE)

Give ISPC-NEGATIVE a wrong password. Press Test Connection once. Wait 30 seconds, then load the
Servers page once. No more than 3 failures per minute.

**Expected**: Test Connection shows `ISPConfig rejected the login. Check the Username and Password fields ...`.
`auth.log` gets exactly one failed API login per action, and `attempts_login.times` rises by 1
per action. The ISPC-NEGATIVE block on the Servers page reports the failed login with the
remaining probes skipped, and no second login attempt. ISPC-TEST is unaffected. Clear
`attempts_login` before continuing.

### Step 8: Transport errors (ISPC-NEGATIVE, throwaway credentials)

(a) Point ISPC-NEGATIVE at a host whose port 80 answers with a redirect (Port 80, Secure off)
and press Test Connection. (b) Point it at a plain web server that answers 200 with an HTML
page. The throwaway credentials travel over plain HTTP here; never use the real remote user.

**Expected**: (a) `Could not reach the ISPConfig API endpoint ... (HTTP status 30x from http://<host>:80/remote/json.php?login ...)`;
the redirect is not followed. (b) `Response is not the ISPConfig JSON envelope: non-JSON body, <N> bytes, starts with: <!DOCTYPE ...`,
cut at 120 bytes. The full HTML page never appears in the Module Log.

### Step 9: Create

For C1, add service S1 on P1 with domain `Test-One.Example`, Username blank and a password
set, then Module Commands > Create.

**Expected**: Module Command Success. ISPConfig shows a new client (notes
`Managed by WHMCS service #<S1>`), website `test-one.example` in lower case, an FTP user, the
mail domain, a DNS zone and a database. S1's admin-only custom fields hold the client id,
client username, website id and the other ids. Every password is masked in the Module Log. The
job queue drains within about 2 minutes. The admin service tab shows
`Linked to ISPConfig client #<N> (<user>).`

### Step 10: Admin service tab escaping

`UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='<b>B</b><img src=x onerror=alert(1)>' WHERE f.type='product' AND f.fieldname='ispconfig_client_username' AND v.relid=<S1>;`
Reload S1's admin page and view the source. Restore the real username afterwards.

**Expected**: the ISPConfig Link and Username rows show the markup literally (no bold, no
broken image, no alert); the source shows `&lt;b&gt;`.

### Step 11: Entire-client Suspend and Unsuspend with a canary

In ISPConfig, deactivate ONE object of S1's client by hand (for example the mail domain) as a
canary, and wait for the job queue. Suspend S1, then check
`SELECT locked,tmp_data FROM dbispconfig.client WHERE client_id=<N>;`. Then Unsuspend.

**Expected**: Suspend: `client_get` then `client_update` with `locked='y'`; every object
inactive; `tmp_data` `prev_active` lists only the canary; S1's `ispconfig_suspended_as` is
`client`. Unsuspend: `client_update` with `locked='n'`; everything active again except the
canary; `ispconfig_suspended_as` is blank. Re-enable the canary by hand.

### Step 12: Website-only Suspend

Create S2 on P2 for C2. Suspend, then Unsuspend.

**Expected**: Suspend sends only `sites_web_domain_set_status` with `inactive`; the client is
not locked; mail and FTP stay active; the site stops serving; `ispconfig_suspended_as` is
`website`. Unsuspend sends `sites_web_domain_set_status` with `active` and blanks
`ispconfig_suspended_as`.

### Step 13: Website-only Suspend on a linked service

Create S3 on P2 for C2 (domain `test-three.example`). Press Unlink from ISPConfig, set S3's
Username to the ISPConfig username of S3's client, then press Link to ISPConfig Account.

(a) Check the admin tab and the Module Log.
(b) Blank S3's website id:
`UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_domain_id' AND v.relid=<S3>;`
Press Suspend.
(c) Press Replace ISPConfig Link (same Username), then Suspend and Unsuspend.

**Expected**: (a) Link succeeds; the Module Log shows `client_get_by_username`,
`client_get_sites_by_user` and `sites_web_domain_get`, and the link entry records the stored
website id; the admin tab shows the ISPConfig website id. (b)
`Suspend refused: this product suspends the website only, but no ISPConfig website id is stored for this service, so there is nothing safe to suspend. Press "Replace ISPConfig Link" ...`;
no panel call is made and the client is NOT locked. (c) Replace stores the website id again;
Suspend and Unsuspend behave as in step 12. Optional: change S3's domain in WHMCS to one the
client has no website for, blank the website id again and press Replace: it answers
`Replace ISPConfig Link kept the existing link to ISPConfig client #<N>, but could not store the website id this product needs to suspend: ...`
instead of success.

### Step 14: Change Package on an active client

S1 is Active. Record `SELECT MAX(datalog_id) FROM sys_datalog;` as MARK. Start
`while :; do date +%T; curl -s -o /dev/null -w '%{http_code}\n' http://test-one.example/; sleep 0.5; done`.
Set S1's product to P3, Save Changes, then Module Commands > Change Package. Inspect the datalog
after MARK.

**Expected**: Module Command Success. The Module Log shows `client_get` then ONE `client_update`
(`template_master` = Pro, plus the addon templates) with no `locked` key. The client stays
unlocked, has no `prev_active`, and shows Pro's limits. The site keeps answering 200 throughout.
Known ISPConfig behaviour, not a failure: an object an admin disabled by hand on an active
client is re-enabled by any `client_update`.

### Step 15: Change Package on a locked (entire-client suspended) client

Suspend S1, record MARK, set its product to P1, Save Changes, press Change Package. Then
Unsuspend S1 and press Change Package again.

**Expected**: the first Change Package returns
`Change Package refused: this service's ISPConfig client is locked (suspended). ... Unsuspend the service first, then change the package. Nothing has been changed.`
The Module Log shows only `client_get` (no `client_update`); the datalog has nothing after
MARK; the client is still locked with its `prev_active` intact. After Unsuspend everything is
active, and the second Change Package succeeds with template Basic.

### Step 16: Change Package on a suspended website-only service

(a) Suspend S2 (P2, website-only). Set its product to a copy of P2 with template Pro (or keep P2
and change an addon option), Save, press Change Package. (b) With S2 still Suspended, set its
product to P1 (entire client), Save, press Change Package. Then Unsuspend S2.

**Expected**: (a) Success. The Module Log shows `client_update` followed by
`sites_web_domain_set_status` with `inactive`; the site stays offline; the client is not
locked. (b) Success. `client_update` is followed by a second `client_update` with
`locked='y'`: the client is now locked, the way P1 suspends, and `ispconfig_suspended_as` is
`client`. Unsuspend unlocks it and the site, mail and FTP are all active.

### Step 16b: Changing the suspension setting while suspended

(a) Suspend S2 (P2, website-only). Tick *Suspend Entire Client* on P2 and save the product.
Press Unsuspend on S2. Untick the box again.
(b) Suspend S1 (P1, whole client). Untick *Suspend Entire Client* on P1. Press Unsuspend on S1.
Tick the box again.
(c) Suspend S2 (P2, website-only). In ISPConfig, tick Locked on S2's client by hand. Press
Unsuspend on S2, then untick Locked in ISPConfig.
(d) Upgrades only, if a service was suspended by the earlier build under a whole-client
product: untick *Suspend Entire Client* on that product and press Unsuspend.

**Expected**: (a) Unsuspend sets the website active (`sites_web_domain_set_status` with
`active`); no `client_update`; the site serves again. (b) Unsuspend unlocks the client
(`client_update` with `locked='n'`): mail, FTP and the site are all active again, and a
following Change Package is not refused. (c) Unsuspend sets the website active but leaves the
hand-set lock in place (no `client_update`). (d) `Unsuspend refused: this service's ISPConfig client #<N> is locked, but this product suspends the website only and the module has no record of locking it ...`;
nothing changes. Tick the box again, press Unsuspend: the client is unlocked.

### Step 17: Link, Replace and shared-client refusals

Create S4 for C2 on P1 WITHOUT running Create (set it Active and save; verify path).

1. Set S4's Username to S1's ISPConfig username; press Link to ISPConfig Account, then Replace
   ISPConfig Link.
2. Set S4's Username to `nosuchuser` and press Link.
3. TEST ONLY, force a shared client: find P1's field id with
   `SELECT id FROM tblcustomfields WHERE type='product' AND fieldname='ispconfig_client_id' AND relid=<P1>;`
   and `INSERT INTO tblcustomfieldsvalues (fieldid,relid,value) VALUES (<fid>,<S4>,'<N>');`.
   On S1, press Terminate, Suspend, Change Password and Change Package, with `auth.log` open.
4. Still shared: set S4's status to Suspended in WHMCS (without running the module). Unsuspend
   S1 if it is suspended; otherwise first Unlink S4, Suspend S1, then re-insert the row from
   (3), set S4 Suspended, and press Unsuspend on S1. Then set S4 back to Active and press
   Unsuspend on S1 again.
5. Press Unlink from ISPConfig on S4.
6. Per-panel scope, if ISPC-TEST-2 exists: give a service on ISPC-TEST-2 the same numeric
   `ispconfig_client_id` as S1 (SQL as in 3) and press Change Password on S1. Then give a
   service on ISPC-NEGATIVE (same panel as ISPC-TEST) that number and press Change Password on
   S1 again. Blank both rows afterwards.

**Expected**:
1. `Link refused: this service's ISPConfig client #<N> is also linked to service #<S1> (test-one.example, WHMCS client #<C1>, Active). ... Nothing has been changed.`
   Replace gives the same text headed `Replace ISPConfig Link refused`. S4 stays unlinked.
2. A refusal saying there is no account named `nosuchuser` and that automatic adoption is not
   installed.
3. Each returns `<Operation> refused: this service's ISPConfig client #<N> is also linked to service #<S4> ...`.
   The client still exists, and `auth.log` shows NO login: the refusal comes before any
   request. If any operation proceeds here, stop testing.
4. With S4 Suspended: `Unsuspend refused: this service's ISPConfig client #<N> is also linked to service #<S4> ..., which WHMCS has suspended. ...`.
   With S4 Active: Unsuspend succeeds and the Module Log has an `UnsuspendAccount` line naming
   S4 as a live sharer.
5. S1's operations work again.
6. The ISPC-TEST-2 service does NOT block S1 (Change Password succeeds), provided both
   services store different `ispconfig_client_username` values; with a blank username on
   either side it DOES block (the safe direction). The ISPC-NEGATIVE service DOES block S1
   (`Change Password refused ... also linked to service #...`), because that record points at
   the same panel. It still blocks when ISPC-NEGATIVE's Hostname is changed to the panel's IP
   and both services store the same username.

### Step 18: Change Password

On S1, set a new password, Save Changes, then Module Commands > Change Password. Test the new
password for the panel login, FTP and `mysql -u <dbuser> -p <db>`.

**Expected**: Module Command Success; `client_change_password`, `sites_ftp_user_update` and
`sites_database_user_update` in the Module Log, all masked. The new password works everywhere;
the old one fails.

### Step 19: Single sign-on, active versus suspended

Needs `ispconfig-sso` on the panel; otherwise only check that the button is absent and the
admin tab's single sign-on row says it is not installed. Open the Servers page once, then log in
as C1's owner (Clients > C1 > Login as Owner).

(a) On S1 (Active), click Log in to ISPConfig. (b) Suspend S2 (website-only, client NOT locked):
the button must be absent; open `clientarea.php?action=productdetails&id=<S2>&dosinglesignon=1`
directly. (c) Suspend S1 (entire client) and repeat (b) for S1.

**Expected**: (a) Lands in ISPConfig as S1's client; the Module Log records success and never
the login URL. (b) and (c): no button, and the direct URL shows only
`Single sign-on is not available for this service right now...`; the Module Log detail reads
`Single sign-on is only available while the service is active; this service is Suspended.`
If (b) logs you in, WHMCS is not passing the service status as `$params['status']`: record
what it sends and report it. (d) As admin, open S2's service page (still Suspended, website
only) and press its login button: the admin IS logged in. On S1 (locked) the admin gets the
panel's `The client account is locked; single sign-on is refused.`

### Step 20: Usage import and the monthly bandwidth reset

S1 Active, site active. On the panel:
`INSERT INTO web_traffic (hostname,traffic_date,traffic_bytes) VALUES ('test-one.example',CURDATE(),524288000) ON DUPLICATE KEY UPDATE traffic_bytes=524288000;`
(a) In WHMCS: `UPDATE tblhosting SET bwusage=99999,lastupdate='<a date last month> 12:00:00' WHERE id=<S1>;`
then run the usage task (`php -q /path/to/whmcs/crons/cron.php do --UpdateServerUsage --force`;
verify the task name under Utilities > Automation Status). (b) `UPDATE tblhosting SET bwusage=99999 WHERE id=<S1>;`
and run it again. (c) Set `bwusage=0` and run it once more. Delete the seeded row afterwards.

**Expected**: (a) `bwusage=500` and `lastupdate` is now, in PHP's timezone. (b) `bwusage`
stays 99999: this month's high-water mark. (c) `bwusage=500`. The Module Log `UsageUpdate`
entry shows `updated>=1` and no error.

### Step 21: Usage-billing metrics

Create one mailbox under S1's client. Collect the metrics (the forced usage-metrics cron task,
or the metric refresh on S1's admin page; verify the control).

**Expected**: Disk Usage, Bandwidth (about 500 MB), Mailbox Storage, Database Storage,
Websites=1, Mailboxes=1, Databases=1, FTP Users=1, DNS Zones=1. Mailbox rows in the Module Log
carry no email address, name, password or autoresponder text; no `ssl_key` anywhere.

### Step 22: Servers page

Load Configuration > System Settings > Servers. Then open ISPC-TEST's edit page and a
server-group page, and view each page's source.

**Expected**: under ISPC-TEST's name, in the first column (not in the edit-icon column):
ISPConfig version, pending jobs, clients, nodes, last updated and a single sign-on line. The Accounts cell shows the live client count; if it shows 0 next to
a correct Clients figure, the row was matched by hostname text only, so report it. ISPC-NEGATIVE
shows its probe errors and the page still renders. The edit and group pages carry no ISPConfig
block or script. One successful login per ISPConfig server per page render.

### Step 23: Terminate and the stale-link check

(a) Terminate S1 (S4 unlinked in step 17). (b) Delete S3's client by hand in ISPConfig, wait
for the job queue, then Terminate S3 in WHMCS. (c) Optionally set S2's `ispconfig_client_id` to
`0` with SQL, press Terminate, then restore it and terminate normally.

**Expected**: (a) Success: every object of the client is deleted, and every `ispconfig_*` row
for S1 on every product is blank. (b) `Terminate refused` with the stale text
(`the stored ISPConfig client id #<N> no longer exists`); nothing deleted. (c) The same stale
refusal, and no `client_get` with an id of 0 or less in the Module Log.

### Step 23b: Product addons on an ISPConfig parent are refused

Create a product addon with Module = ISPConfig Connect, add it to S2 and press its Create
button (Clients > C2 > S2 > Addons; verify path). Set the addon's module back to none
afterwards.

**Expected**: `ISPConfig Connect cannot run on this product addon: its parent service #<S2> is itself linked to ISPConfig client #<N> ... Nothing has been changed.`
No panel call in the Module Log, and S2 and its ISPConfig client are unchanged.

### Step 24: Server Sync (read-only)

Configuration > System Settings > Servers > Sync Accounts on ISPC-TEST. Analyse only; import or
terminate nothing.

**Expected**: the panel's clients with their primary domains; clients with no website appear
as `client-<id>.no-domain.invalid`; no error.

### Step 25: Close-out

Confirm the job queue is 0 and `attempts_login` has no row for the WHMCS IP. Scan the WHMCS
activity log and the PHP-FPM error log for the test window. Remove the test services and
clients, the seeded rows, and ISPC-NEGATIVE; restore the remote user's grants.

**Expected**: no uncaught exceptions, no "Call to undefined function", no PHP fatals.

Covered by the automated suites only, not live: BOM or warning-wrapped response recovery,
redirect non-following beyond step 8, the two-strike transport latch during a real outage, the
no-`mbstring` fallback, and the shared-client check failing closed when the WHMCS database
query fails.

## 4. Rollback

1. Confirm the panel job queue is 0. Compare
   `SELECT client_id,username,locked FROM dbispconfig.client WHERE locked='y';` with the
   Suspended services in WHMCS.
2. Restore the files: `rm -rf /path/to/whmcs/modules/servers/ispconfigconnect && tar xzpf ispcc-prefix-backup.tgz -C /path/to/whmcs modules/servers/ispconfigconnect`.
   Without the tarball: `git -C <repo> archive 1ed05b3 modules/servers/ispconfigconnect | tar -x -C /path/to/whmcs`,
   then chown to the web user. The digest must equal the one recorded before the upgrade.
3. Restore the hook: copy the backed-up `includes/hooks/ispconfigconnect.php` back as the web
   user, or run the restored installer as the web user. `--status` must print
   `installed and up to date`.
4. Reload PHP-FPM or reset OPcache, press Test Connection, and open the Servers page once.
5. Data: there is no schema change to undo. Custom-field rows the new build blanked stay blank,
   which is also correct for the earlier build. Website ids stored by Link or Replace stay
   stored.
6. Behaviour that returns with the earlier build: a package change can corrupt the lock state;
   a website-only suspension with no website id does nothing; a shared client can be
   terminated; the SSO button ignores the service status. While rolled back, do not run Change
   Package on suspended services and do not terminate services whose client id is shared.
7. Last resort, test systems only: restore the WHMCS and `dbispconfig` dumps.
