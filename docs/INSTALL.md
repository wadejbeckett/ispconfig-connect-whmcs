# Installing ISPConfig Connect for WHMCS

ISPConfig Connect is a WHMCS provisioning (server) module that manages hosting
accounts on an ISPConfig 3 panel over its remote JSON API. It is plain PHP with
no runtime dependencies beyond PHP ≥ 8.2 and ext-curl — no `composer install`
is needed on the WHMCS server.

## 1. Prepare the ISPConfig panel

1. **Enable the remote API.** The switch is `remote_api_allowed=yes` in the
   `[permissions]` section of `/usr/local/ispconfig/security/security_settings.ini`
   on the ISPConfig server (it is a server-side file; there is no panel form
   for it). Stock installs ship with it enabled. When it is off, every request
   is answered with *"Remote API is disabled in security settings."*
2. **Create a dedicated remote user** under **System > Remote Users** in
   ISPConfig. Never use a panel administrator login — the module authenticates
   as a remote user only.
   - Give it a strong unique password.
   - Enable the `remote_functions` grants listed in
     [`docs/REMOTE-USER.md`](REMOTE-USER.md). The module's
     **Verify Permissions** button (admin service tab) and **Test Connection**
     both report any grant that is still missing, by name.
   - Optionally restrict the user's **Remote Access IPs** field to your WHMCS
     server's public address (recommended). If the field is set and does not
     include the address WHMCS connects *from*, logins fail with
     *"The login is not allowed from &lt;ip&gt;"* — that IP is the one to add.

## 2. Install the module files

Copy the module directory into your WHMCS installation:

```
cp -r modules/servers/ispconfigconnect  /path/to/whmcs/modules/servers/
```

The directory name must remain exactly `ispconfigconnect`. Provisioning,
suspension, usage import, metrics, sync and single sign-on all work from this
one directory — WHMCS autoloads the module's `lib/` classes automatically.

### The admin hook file (optional, one extra step)

One feature needs a second file: the **ISPConfig block on the servers page**
(panel version, pending jobs, client count — see §7.4). It is a WHMCS *hook*,
and WHMCS **does not load hook files from inside a provisioning module** — that
was verified on a live WHMCS 9.0.6 install, where a marker at the top of
`modules/servers/ispconfigconnect/hooks.php` never once fired, including on the
Servers page itself. Hooks are only ever loaded from `includes/hooks/`.

So the file ships in the module and is copied into place:

```sh
modules/servers/ispconfigconnect/hooks/install-hook.sh /path/to/whmcs
```

which installs it as `/path/to/whmcs/includes/hooks/ispconfigconnect.php`. The
installer is idempotent, refuses to touch a file it did not write, and checks
the file parses before installing it. Two more modes:

```sh
modules/servers/ispconfigconnect/hooks/install-hook.sh --status    /path/to/whmcs
modules/servers/ispconfigconnect/hooks/install-hook.sh --uninstall /path/to/whmcs
```

The equivalent by hand is a plain copy:

```sh
cp modules/servers/ispconfigconnect/hooks/ispconfigconnect.php  /path/to/whmcs/includes/hooks/
```

Notes:

- **Re-run it after every module upgrade.** The copy in `includes/hooks/` is a
  copy: updating `modules/servers/ispconfigconnect/` does not update it.
  `--status` reports when the installed file differs from the shipped one.
- Skipping this step costs only that block. Nothing else in the module depends
  on it.
- Remove it with `--uninstall` when you uninstall the module (§8).

## 3. Add the server in WHMCS

**System Settings > Servers > Add New Server**, then:

| Field | Value |
|---|---|
| Module | ISPConfig Connect |
| Hostname | The panel host, e.g. `panel.example.com` (falls back to the IP Address field when empty) |
| Port | `8080` (the ISPConfig default; the module defaults to 8080 when the field is empty) |
| Secure | Tick for HTTPS (recommended and the ISPConfig default) |
| Username | The **remote user** name from step 1 |
| Password | The remote user's password |
| Access Hash | TLS trust configuration — see below |

### The Access Hash field (TLS trust)

WHMCS has no per-server "custom CA / disable verification" fields, so the
module overloads the Access Hash box. TLS certificate verification is **on by
default**; the field controls the exceptions:

| Access Hash value | Meaning |
|---|---|
| *(empty)* | Verify the panel's certificate against the system CA store (default) |
| `/path/to/ca.pem` | Verify against this CA bundle file on the WHMCS server |
| A literal PEM block (`-----BEGIN CERTIFICATE-----…`) | Verify against this pasted CA certificate |
| `insecure:` | **Disable TLS verification entirely.** Escape hatch for self-signed panels — use only on trusted networks, and prefer installing a proper certificate or pasting the panel's CA instead |

Anything after the `insecure:` prefix is treated as an optional CA bundle
(path or PEM) for the rare verify-off-but-pin case; normally the value is just
`insecure:` alone.

Click **Test Connection** before saving. Its error messages are designed to be
actionable: they name the missing grant, the IP to allowlist, the lockout
back-off, or the disabled-API setting, depending on what failed.

A **successful** test also reports single sign-on — `Connected. Single sign-on:
…` followed by a sentence about the optional `ispconfig-sso` panel add-on. On a
stock panel that sentence says the add-on is **not installed**, and **that is the
expected answer, not a fault**: single sign-on is optional and nothing else in
the module depends on it (§7.5). The same line is written to **Utilities > Logs >
Module Log**, so you can still read it if your WHMCS theme shows only a bare
success tick.

## 4. Create the product

**System Settings > Products/Services**, create a product of type *Shared
Hosting* (or similar), Module Settings tab → Module Name **ISPConfig
Connect**, then work through the options (their order is fixed):

1. **Client Template** — dropdown loaded live from the panel: the ISPConfig
   *master* client template that defines the account's limits. Create your
   templates in ISPConfig first (**Client > Client Templates**, type
   *Master*).
2. **Web Server** — dropdown of the panel's servers; the created website,
   mail domain, DNS zone and database are all placed on this server.
3. **Create Website** — provision a vhost website for the service domain
   (default on).
4. **Create FTP User** — provision an FTP login for the website (default on;
   requires Create Website — without a website there is no directory or
   system user for FTP to attach to).
5. **Create Mail Domain** — provision the service domain as a mail domain
   (default off). Mailboxes themselves are not created in this version.
6. **Create DNS Zone** — provision a DNS zone from an ISPConfig DNS template
   (default off).
7. **DNS Template ID** — the ISPConfig DNS template to use (default `1`, the
   stock *Default* template).
8. **NS1** / **9. NS2** — nameservers substituted into the zone. Required
   when Create DNS Zone is on.
10. **DNS Admin Email** — SOA contact for the zone; empty means
    `admin@<service domain>`.
11. **Create Database** — provision one MySQL database plus database user
    (default off). Names are derived deterministically:
    `c<ispconfig-client-id>_<domain>` truncated to 16 characters.
12. **PHP Mode** — PHP handler for created websites (default PHP-FPM).
13. **Suspend Entire Client** — chooses what a WHMCS suspension does in
    ISPConfig (default on = entire client):
    - **Checked:** the module locks the ISPConfig client. Be aware of the
      blast radius: ISPConfig's lock disables **all** of the client's
      objects — websites, mail domains, FTP users, databases, cron and
      shell — and unsuspension restores exactly what the lock disabled
      (objects that were already inactive before the lock stay inactive).
    - **Unchecked:** website-only suspension — only this service's website
      is set inactive; mail, FTP and databases keep working, and the client
      can still log into the panel.
14. **Panel URL Override** — the URL shown to clients as their control-panel
    link. Empty derives `https://<hostname>:<port>/` from the server
    settings. Set it when clients should use a different address (e.g. a
    vanity hostname or a reverse proxy).

### Addon client templates via configurable options

A configurable option named exactly `ISPConfig <Template-Name>` (e.g.
`ISPConfig Extra Mailboxes`) binds to the same-named *addon* client template
(type *Additional*) in ISPConfig. When the option is enabled on an order, the
template is assigned at creation and reconciled on package changes. An enabled
option with no matching addon template fails the operation rather than
silently under-provisioning.

## 5. Migrating customers who already have an ISPConfig account

Everything above assumes the module *creates* the hosting account. This section
is the other case: the WHMCS service already exists, the ISPConfig account
already exists, and something other than this module made them — a migration
off another control panel, an import, or accounts built by hand before the
module was installed.

**The whole job is: point the service at the ISPConfig server and give it an
ISPConfig product. The module links it to the right panel account by itself.**

### The migration sequence

1. **Move the hosting data** onto ISPConfig however you normally would, and
   create (or keep) the customer's ISPConfig client account. The module does
   not move data and does not care how it got there.
2. In WHMCS, open the service and set its **Server** to the ISPConfig server
   and its **Product** to an ISPConfig Connect product.
3. **Do nothing else.** The next time the module talks to the panel about that
   service, it finds the matching ISPConfig account and stores the link itself.

There is deliberately **no step where you type an ISPConfig client id
anywhere**. An internal id is opaque, cannot be checked by eye, and one wrong
digit points the module at a different customer's account — after which a
suspend or a terminate acts on the wrong hosting.

### Where the linking happens

| Moment | What it does |
|---|---|
| **The nightly usage sweep** (§7.1) | Resolves every unlinked service on the server in one batch, links the confident matches, and imports their usage in the same run. This is the one that needs nothing from you. |
| **Opening the service's *Module* tab** | Resolves that one service on the spot, so a service you have just pointed at the server is usually already linked by the time you look at it. |

Both are read-only against the panel — nothing is created, changed or deleted
there. Both record what they did in **Utilities > Logs > Module Log** under the
action `Adopt`, together with the evidence that justified the link.

### What it will not do

These are safety rules, not limitations to work around:

- **It links only on an exact, unambiguous match.** If no panel client owns the
  service's domain, or more than one does, the service is left unmanaged and
  the reason is recorded. It never picks the most likely candidate.
- **It never links inside a destructive operation.** Terminate, Suspend,
  Unsuspend, Change Password and Change Package all *refuse* an unlinked
  service and tell you how to link it. A resolver mistake inside a terminate
  would delete a live customer's hosting, and no amount of confidence in a
  match makes that recoverable.
- **It never overwrites a link that already exists.** A service with a stored
  id is not even offered to the matcher.
- **A stale link is reported, never repaired.** If the stored client id no
  longer exists on the panel, the *Module* tab says **STALE** and destructive
  operations refuse — the module will not go looking for a replacement account,
  because the one it found could belong to somebody else.

### The manual override

Three buttons sit on the service's *Module* tab next to *Verify Permissions*.
**None of them touches the panel** — they only change what WHMCS has stored, so
none of them can damage a customer's hosting.

| Button | What it does |
|---|---|
| **Link to ISPConfig Account** | Links this service to the ISPConfig account named in the service's **Username** field, after checking with the panel that the account exists. Refuses if the service already carries a *different* id. |
| **Replace ISPConfig Link** | The same check, but it deliberately overwrites an existing link. Both the old and the new id go to the module log. |
| **Unlink from ISPConfig** | Clears the stored ids so a wrong link is reversible without hand-editing custom fields. The panel account is untouched. |

> **The Username field is the stale one on a migrated service.** This is worth
> knowing before you use *Link to ISPConfig Account*: a service that came from
> another control panel still carries **that panel's** username in WHMCS, and
> ISPConfig has never heard of it, so linking by name will simply not find it.
> **The domain is the reliable key** — and the domain is what automatic adoption
> matches on, which is why the automatic path usually works on exactly the
> services where the manual button does not. If the button reports no such
> account, either correct the Username field to the ISPConfig account name and
> press it again, or leave it alone and let the nightly sweep do it.

### Checking that it worked

The service's *Module* tab has an **ISPConfig Link** row that answers in plain
English: the account it is linked to, or why it is not linked (no match, or
ambiguous with the candidate accounts named), or that the link is stale. The
morning after a sweep, the `UsageUpdate` entry in the module log carries an
`adopted` list (what was linked, and on what evidence) and an `unlinked` list
(what was left alone, and why).

## 6. Operational notes

### What suspension touches (the lock cascade)

With **Suspend Entire Client** checked, suspension uses ISPConfig's client
lock, which cascades over *everything* the client owns — every website, mail
domain, FTP user, database, cron job and shell user is set inactive, not just
the objects this WHMCS service created. Unsuspension restores exactly the set
the lock disabled; anything an admin had disabled beforehand stays disabled.
The module also guards both directions: it never re-locks an already-locked
client and never "unlocks" a client that is not locked (either would corrupt
ISPConfig's restore bookkeeping or re-enable deliberately disabled objects).
If you resell multiple WHMCS services into one ISPConfig client, or manage
extra objects under the same client by hand, prefer the website-only mode.

### What a password change rotates

WHMCS's Change Password rotates every credential the module provisioned with
the service password: the ISPConfig panel login, the FTP user's password and
the database user's password (the latter two only when the product created
them). Credentials you added manually in ISPConfig are not touched. An empty
password is refused before any API call.

### Provisioning is eventually consistent

The ISPConfig API is asynchronous by design: an accepted API call writes the
change to the panel's datalog, and each server's cron applies it — typically
within a minute. "Success" in WHMCS therefore means *accepted by the panel*,
not *already live on the web server*. The admin service tab shows the panel's
current **job queue** depth; a non-zero value means changes are still being
rolled out.

### What the admin service tab shows

The first row is **ISPConfig Link**, which answers in plain English whether
this service is linked to a panel account, and if it is not, why not — see §5.

Alongside the stored ISPConfig ids, the service's *Module* tab reads four
things live from the panel, each degrading to a placeholder rather than
failing the page: the job-queue depth, the client's current **disk usage** and
**this month's bandwidth** (both against the panel's own limits, so they are
current even between nightly imports), and whether **single sign-on** is
available on this panel (that row has four possible answers — §7.6 decodes
them). All four share one login, and the probes stop
immediately if the panel cannot be reached — an admin page must never spend
failed logins against the panel's lockout counter.

### Login rate limit and NAT (lockout caveat)

ISPConfig locks out a source IP for 5 minutes after **10 failed logins within
5 minutes** — and expired/invalid session lookups count as failures too. The
module logs in once per operation, retries an expired session exactly once,
and stops calling entirely for the rest of the request once a lockout is
seen, so normal operation stays far from the limit.

Be aware that the counter is **per source IP as the panel sees it**: if your
WHMCS server shares a NAT gateway or outbound proxy with other API consumers
(or with people logging into the panel), *their* failures count against the
same limit and can lock WHMCS out too. During a lockout, wait the full 5
minutes without retrying — every further attempt extends the window.

### Module logging

Every API call is recorded in **Utilities > Logs > Module Log** (enable
debug logging in WHMCS to see it). Two things happen to that entry before it is
stored, because the module log is readable by every WHMCS admin:

- **Credentials and key material are masked** via WHMCS's replaceVars
  mechanism: panel and service passwords, the stored password hashes ISPConfig
  echoes back (`client.password`, `sys_user.passwort`, `otp_recovery`), session
  ids, SSO tokens, and the private keys that ride along on record reads
  (`ssl_key`, `ssl_request`, `id_rsa`, `dkim_private`, `relay_pass`).
- **Customer records are cut down before they are logged at all.** ISPConfig's
  reads are `SELECT *`, so `client_get` and `client_get_by_username` answer with
  the whole customer row — bank account and IBAN, VAT id, PayPal address, postal
  address, notes, two-factor columns. Server Sync reads *every* client on the
  panel, so the module keeps only the columns it actually uses (id, username,
  e-mail, template, lock state, limits) and drops the rest before handing the
  entry to WHMCS. Dropped columns are counted in the log entry, not silently
  removed.

## 7. Usage, metrics, sync and single sign-on

Everything in this section works with no extra configuration beyond the grants
in [`docs/REMOTE-USER.md`](REMOTE-USER.md). Nothing here is required for
provisioning — skip any part you do not want.

### 7.1 Nightly disk and bandwidth import

WHMCS's daily cron run calls the module once **per server** and the module
writes the four usage columns of `tblhosting` for every service on it:
`diskusage`, `disklimit`, `bwusage`, `bwlimit` (all in **megabytes**), plus
`lastupdate`. The figures come from the panel's own accounting:

| WHMCS column | ISPConfig source |
|---|---|
| `diskusage` | `quota_get_by_user` — the repquota block count for each of the client's websites |
| `disklimit` | `web_domain.hd_quota` (MB; `-1`/`0` = unlimited, reported to WHMCS as a limit of `0`) |
| `bwusage` | `trafficquota_get_by_user` — this calendar month's `web_traffic` bytes |
| `bwlimit` | `web_domain.traffic_quota` (MB, same unlimited convention) |

Requirements and behaviour worth knowing:

- The server must be **enabled** in WHMCS (a disabled server is never visited)
  and only services in the **Active** or **Suspended** status are read —
  terminated services have no panel client left to ask about.
- A service is readable once it is **linked** to a panel account. The sweep
  begins by trying to link the ones that are not, in a single batch, and then
  imports usage for everything including whatever it just linked (§5). A
  service it could not link confidently is **left alone and named** in the log,
  with the reason, rather than silently ignored.
- The whole sweep uses **one login**. It begins with a single reachability
  probe, so an unreachable or mis-credentialed panel costs one failed login
  for the entire run instead of one per service — which matters because ten
  failed logins lock the WHMCS server's IP out of the panel for five minutes.
- One unreadable service never costs the others their import.

**Where to look afterwards:** *Utilities > Logs > Module Log*, action
`UsageUpdate`. Each run records `updated` (how many services were written),
`skipped` (service id → reason) and, if the sweep aborted, `error`.

### 7.2 Usage-billing metrics

The module also publishes nine metrics to WHMCS's usage-billing feature. The
system names are stable — renaming one would orphan its stored history — and
usage is keyed by the ISPConfig username:

| System name | Shown as | Unit | Type |
|---|---|---|---|
| `diskusage` | Disk Usage | MB | snapshot |
| `bandwidth` | Bandwidth | MB | monthly period |
| `mailquota` | Mailbox Storage | MB | snapshot |
| `databasequota` | Database Storage | MB | snapshot |
| `websites` | Websites | accounts | snapshot |
| `mailboxes` | Mailboxes | accounts | snapshot |
| `databases` | Databases | accounts | snapshot |
| `ftpusers` | FTP Users | accounts | snapshot |
| `dnszones` | DNS Zones | domains | snapshot |

`bandwidth` is a *monthly period* metric because ISPConfig sums `web_traffic`
per calendar month; each collection overwrites that month's record. The other
eight are snapshots of what exists right now.

Collecting all nine costs up to six API calls per client (against two for the
disk/bandwidth import above), so a panel with many services will spend
noticeably longer on a metrics collection than on the nightly usage import.

### 7.3 Server Sync ("Sync Accounts")

**Configuration > System Settings > Servers**, then **Sync Accounts** on an
ISPConfig server, lists every client the panel holds and compares it against
WHMCS's service records — accounts on the panel with no WHMCS service, WHMCS
services missing from the panel, and rows whose details differ.

How this module fills the comparison in:

- **Unique identifier** — the client's **primary website domain**. WHMCS can
  only match a sync row against a service's *Domain* field (it rejects every
  other choice), so a client that owns no website — or whose domain is already
  claimed by another client — is listed under a placeholder of the form
  `client-<ispconfig client id>.no-domain.invalid` instead. `.invalid` is a
  reserved suffix that can never be a real domain, which is the point: such a
  row is reported as *an account WHMCS does not know about*, and can never be
  paired with the wrong service. Expect those rows if you sell a product that
  creates a client but no website.
- **Product** — the ISPConfig **master client template id**, which is exactly
  what the product's *Client Template* option stores. A WHMCS product matches
  a panel account when both point at the same template.
- **Status** — *Suspended* when the ISPConfig client is locked **or** its
  primary website is inactive (the module's website-only suspension mode), and
  *Active* otherwise.
- **Created** — `client.created_at`, which ISPConfig writes once at creation.
  It is empty for clients created before that column was populated.

The listing reads the whole client table in one call and the template list
once for the panel, then two lookups per client to find its primary website.
On a panel with hundreds of clients the sync therefore takes a while; it is an
on-demand admin action, not something that runs on a schedule.

### 7.4 Reading the servers page

**Configuration > System Settings > Servers** shows several numbers per server,
from three different sources. They are easy to mistake for each other, so:

| What you see | Where it comes from | What it means |
|---|---|---|
| **WHMCS Usage Stats**, e.g. `1/200` | WHMCS itself | The number of **WHMCS services assigned to this server**, over the server's own **Maximum No. of Accounts** field (Servers > Edit). Pure WHMCS arithmetic — it says nothing about the panel, and no module can change it. A perfectly healthy panel reads `0/200` until WHMCS services point at it. |
| **Accounts: N** | This module, via the admin hook (§2) | The **live ISPConfig client count** from the last metadata refresh — the same figure as *Clients* below. Core's own value for this cell is `tblservers_remote.num_accounts`, a column no returned key populates for this module (tested on WHMCS 9.0.6), so core would show **0** forever; with the admin hook installed the block rewrites the cell client-side to the real count when the page renders. **Without the hook it reads 0**, and no server sync will change that — do not run *Sync Accounts* to fill it. |
| **Services: N** | This module | The number of **ISPConfig clients** on the panel — the same figure as *Clients* below, because this module provisions exactly one ISPConfig client per WHMCS service. Refreshed every time the page renders. (It used to be a website count; counting vhosts meant a bulk read that dragged every site's TLS private key across the API, so that number was removed rather than approximated.) |
| **ISPConfig Version / Pending Jobs / Clients / ISPConfig Nodes / Last Updated** | This module, via the admin hook (§2) | Panel version; the depth of ISPConfig's datalog job queue (mutations accepted but not yet applied — a number that keeps climbing means the panel's cron is not running); the number of **ISPConfig clients**, which is this module's own always-fresh answer to "how many accounts"; how many servers the panel fronts; and when WHMCS last refreshed the block. |
| **Probe Errors** | This module | Shown instead of a missing value when a probe fails: the page shows *why*, rather than breaking or quietly reading zero. |

Two consequences worth knowing:

- **Clients vs Accounts.** With the admin hook installed, *Accounts* is rewritten
  to the very same live client count as *Clients*, so the two always agree.
  Without the hook, *Clients* is not shown at all and *Accounts* reads 0 — a
  server sync will not change that.
- If the **ISPConfig Version / Pending Jobs / Clients** lines are missing
  entirely, the admin hook file is not installed — see §2.

Rendering this page refreshes each ISPConfig server's block once (about seven
API requests to that panel per server per render); a panel that is down costs
the block, never the page.

### 7.5 Single sign-on (optional, needs the separate `ispconfig-sso` project)

Out of the box there is **no login button**. ISPConfig core has no
one-time-login API, and this module will not store or replay a customer's panel
password to fake one — so customers get the panel URL and their username, and
nothing more. That is the normal, supported state: **skip this section** and
everything else works.

A password-free **Log in to ISPConfig** button needs one extra piece of software
**on the ISPConfig server**, and it is not in this repository:
[**`ispconfig-sso`**](https://github.com/wadejbeckett/ispconfig-sso) — a
standalone GPLv3 ISPConfig add-on by the same author, deliberately not
WHMCS-specific (any billing system can drive it). This module never installs it,
never bundles it and does not require it. It only detects it.

**Set it up there, not here.** The whole procedure — a three-step path covering
both the scripted and the by-hand route — is that project's own README:

> **[`ispconfig-sso` → *3. Install and remove*](https://github.com/wadejbeckett/ispconfig-sso#3-install-and-remove)**

That page is the single source for panel-side setup, including the HTTPS
requirement (a token is a password substitute travelling in a URL, so both
halves refuse on a plaintext request) and what to declare when a reverse proxy
terminates TLS for you.

**What this module needs from you: one grant, ticked in ISPConfig.**

> **System > Remote Users > (your API user) > Functions** → tick
> **Panel single sign-on (one-time login token)** → **Save**.

That checkbox is contributed by the add-on, so it exists only on a panel that
has it; the grant itself is the function name `connect_sso_token_create`. It is
deliberately **not** in this module's required grant list — a stock panel has no
such function — so a missing SSO grant is never treated as a broken connection
by Test Connection or Verify Permissions.
[`docs/REMOTE-USER.md`](REMOTE-USER.md) §2a records what a holder of that grant
can do — every non-administrator account on the panel, **resellers included** —
and is worth reading before you tick it.

**What WHMCS then does: nothing you configure.** Detection is automatic and per
server — one `get_function_list` call, cached in WHMCS's own server metadata.
The cache is refreshed whenever WHMCS renders
**Configuration > System Settings > Servers**, so **open that page once** after
installing the add-on. From the next page load on, services on that panel carry
the *Log in to ISPConfig* action.
There is no SSO setting in the server form, the product options or
anywhere else in WHMCS, by design: detection is the truth, and a manual toggle
could only lie about what the panel actually has.

Three consequences worth knowing before you go looking for them:

- **Detection needs the Server functions grant** (`get_function_list`), which
  this module already requires — see [`docs/REMOTE-USER.md`](REMOTE-USER.md) §2.
  Without it the probe is refused and the module cannot tell either way.
- **Detection cannot see the panel's TLS gate.** `get_function_list` is a core
  function and answers over plain HTTP, so on an HTTP-only panel the button is
  advertised and the *click* is what fails. Serve the panel over HTTPS (or
  declare the reverse proxy) before enabling SSO.
- **The login is per service.** There is deliberately no "log in as admin"
  auto-login: `ispconfig-sso` refuses to mint tokens for administrator accounts,
  because such a token would be a full panel takeover. WHMCS's server-level
  **Login to Control Panel** button therefore just opens the panel's login page
  in a new tab — the address built from the server row, or the product's *Panel
  URL Override* where WHMCS passes one — so you sign in as yourself. (Where
  WHMCS presses that button with a service in scope, you get the real one-time
  login for that service's client instead.)

### 7.6 Troubleshooting single sign-on

Keyed to what you actually see. Each row below is identified by **the words the
message names** rather than by its full sentence: the module's phrasing is
version-specific, but the identifiers in it — `ispconfig-sso`,
`connect_sso_token_create`, `get_function_list`, *Server functions* — are what
tell the cases apart, and those do not move. Messages shown in `code` are the
**panel's** own, and are fixed by the `ispconfig-sso` project.

> **Whose screen are you reading?** A **customer** never sees any of the wording
> below. Every single sign-on failure in the client area renders one fixed
> sentence — *"Single sign-on is not available for this service right now…"* —
> which names no host, no grant and no diagnosis, deliberately. The diagnostic
> wording the tables below are keyed to is what an **admin** sees on an
> admin-side login attempt, and what is always written to **Utilities > Logs >
> Module Log** (filter on `ServiceSingleSignOn`). So when a
> customer reports the generic sentence, **read the module log** — the row you
> need is there, not on their screen.

#### "The *Log in to ISPConfig* button is missing"

Check the service's **Module** tab (Products/Services > the service) — its
*ISPConfig Single Sign-On* row is the diagnostic, and it has four answers:

| The row says | Cause | Fix |
|---|---|---|
| **available** | The add-on is installed and visible | Nothing — if the button is still absent, WHMCS has not re-read the module's metadata: open **Configuration > System Settings > Servers** once, then reload the service |
| **not installed**, naming `ispconfig-sso` | The panel answered, and the add-on is not there. **This is the normal state of a stock panel, not a fault** | Install it (§7.5) — or leave it; nothing else is affected |
| that it **cannot tell**, naming `get_function_list` and *Server functions* | The remote user may not run the presence probe, so presence is genuinely undeterminable — neither "yes" nor "no" | Tick **Server functions** for that remote user ([`docs/REMOTE-USER.md`](REMOTE-USER.md) §2) |
| that the panel **could not be reached**, or nothing at all | The probes never ran: no connection, or no login | Fix the connection first — **Test Connection** on the server names the reason |

The servers-page block (§7.4) has a *Single Sign-On* line too, but it is written
from a boolean and so reads only **Available** or **Not installed**. When the
probe was *refused*, that block shows **no *Single Sign-On* line at all**: the
module declines to cache a "no" it was never allowed to establish, so the flag
the block renders is simply absent. A missing line therefore means *could not
look* — never *not installed*. **The service tab is the surface that names the
difference in words**, so when you need it spelled out, read it there.

#### "It says SSO cannot be determined"

That is the *cannot tell* row above, and it has exactly one cause: the remote
user may not call `get_function_list`, so the panel cannot be asked what
functions it has. Detection then fails closed — the button stays hidden, and the
servers-page block drops its *Single Sign-On* line entirely rather than publish a
"no" it could not establish, even on a panel that does have the add-on. **Never
read a hidden button or a missing line as "the add-on is not installed":** the
module could not look.

The grant lives in the **Server functions** checkbox (System > Remote Users >
your API user > Functions), which this module requires anyway — so **Test
Connection** on the server is the corroborating check. It reports that the
remote user is missing `remote_functions` grants and names them; `server_get`
(needed by `server_get_all`) and `get_function_list` both live in that one
checkbox, so a report of either means the same tick fixes both. Tick it, save,
and re-open **Configuration > System Settings > Servers**.

#### "I installed the add-on but nothing changed"

Expected, until WHMCS re-probes. The presence flag is cached per server and is
only refreshed when the servers page renders:

1. Open **Configuration > System Settings > Servers**. That page load is the
   refresh.
2. Reload the service. The button appears from the next page load on.

If it still does not: on the ISPConfig side, confirm the grant checkbox is
actually there and ticked. If the checkbox is missing from the Remote Users
form, the add-on's module assignment was dropped — that is a known ISPConfig
behaviour with its own fix in the
[`ispconfig-sso` README → *Known caveat*](https://github.com/wadejbeckett/ispconfig-sso#known-caveat).

#### "The button is there, but clicking it errors"

The message names the cause:

| The message says | Cause | Fix |
|---|---|---|
| the panel **does not have** `ispconfig-sso` | **A mixed fleet.** WHMCS asks the module whether it supports SSO with **no server in scope**, so the *label* is unavoidably module-global. The module's own client-area panel no longer acts on it blindly — it offers the button only for a service whose **own** server was probed as having the add-on — but any button WHMCS itself renders from that global label is outside the module's reach | Nothing is broken: such a click fails safely with the one customer sentence, and the reason lands in the module log. Install the add-on on that panel too if you want the button to work there |
| the remote user **may not call** `connect_sso_token_create` | The add-on is installed but not granted to this server's remote user | Tick the grant — §7.5 |
| `Single sign-on requires an encrypted (https) connection to the panel.` | The panel's own TLS gate. Detection cannot see it, so the button is advertised and the click is what fails | Serve the panel over HTTPS, or declare the reverse proxy (`reverse_proxy_panel_allowed`) — [`ispconfig-sso` README, top box](https://github.com/wadejbeckett/ispconfig-sso#-the-panel-must-be-served-over-https) |
| `The client account is locked; single sign-on is refused.` | The service is suspended in WHMCS, so the ISPConfig client is locked | Working as intended: a suspension cannot be walked around by clicking the login button |
| the service has **no stored `ispconfig_client_id`** | An imported or hand-made WHMCS service with no link to a panel client | Nothing to fix in SSO — the service needs provisioning (or its `ispconfig_client_id` property set) first |
| ISPConfig has **no administrator single sign-on** | You pressed a server-level login button with no service in scope | By design; sign in at the panel URL the message quotes, or use the button on a customer's service |

Everything else on that click is an ordinary connection failure and reports as
one (lockout, IP allowlist, TLS trust, disabled remote API) — §6 and
[`docs/REMOTE-USER.md`](REMOTE-USER.md) §5 cover those.

#### "The panel refuses, but the message is not in the table above"

Panel-side refusals are passed through verbatim **to an admin and to the module
log** (a customer still gets only the one fixed sentence — see the box at the
top of §7.6), so the wording will be one of the rows in
[`ispconfig-sso` README §4.5, *Faults*](https://github.com/wadejbeckett/ispconfig-sso#45-faults)
— that table is the authoritative list, with the cause of each.

A note on the log while you are diagnosing: *Utilities > Logs > Module Log*
records the SSO **outcome** only. The redirect URL carries a one-time token in
its query string and is deliberately never logged at all, so an empty-looking
entry is the privacy rule working, not a lost record.

## 8. Uninstalling

Remove the product/server assignments in WHMCS, remove the admin hook file if
you installed it (§2):

```sh
modules/servers/ispconfigconnect/hooks/install-hook.sh --uninstall /path/to/whmcs
```

then delete `modules/servers/ispconfigconnect/`. The module stores its state as
WHMCS service properties (`ispconfig_client_id`, `ispconfig_domain_id`, …); it
creates no extra database tables.

If you installed the `ispconfig-sso` project on the panel, remove it separately
on the ISPConfig server with that project's own `uninstall.sh`. It is
independent of this module: removing one does not affect the other.
