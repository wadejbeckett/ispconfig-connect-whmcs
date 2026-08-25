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
(panel version, pending jobs, client count — see §6.4). It is a WHMCS *hook*,
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
- Remove it with `--uninstall` when you uninstall the module (§7).

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

## 5. Operational notes

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

Alongside the stored ISPConfig ids, the service's *Module* tab reads four
things live from the panel, each degrading to a placeholder rather than
failing the page: the job-queue depth, the client's current **disk usage** and
**this month's bandwidth** (both against the panel's own limits, so they are
current even between nightly imports), and whether **single sign-on** is
available on this panel. All four share one login, and the probes stop
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

## 6. Usage, metrics, sync and single sign-on

Everything in this section works with no extra configuration beyond the grants
in [`docs/REMOTE-USER.md`](REMOTE-USER.md). Nothing here is required for
provisioning — skip any part you do not want.

### 6.1 Nightly disk and bandwidth import

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
- Only services this module provisioned are readable: the import needs the
  `ispconfig_client_id` service property. Imported or hand-made services are
  **skipped and named** in the log rather than silently ignored.
- The whole sweep uses **one login**. It begins with a single reachability
  probe, so an unreachable or mis-credentialed panel costs one failed login
  for the entire run instead of one per service — which matters because ten
  failed logins lock the WHMCS server's IP out of the panel for five minutes.
- One unreadable service never costs the others their import.

**Where to look afterwards:** *Utilities > Logs > Module Log*, action
`UsageUpdate`. Each run records `updated` (how many services were written),
`skipped` (service id → reason) and, if the sweep aborted, `error`.

### 6.2 Usage-billing metrics

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

### 6.3 Server Sync ("Sync Accounts")

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

### 6.4 Reading the servers page

**Configuration > System Settings > Servers** shows several numbers per server,
from three different sources. They are easy to mistake for each other, so:

| What you see | Where it comes from | What it means |
|---|---|---|
| **WHMCS Usage Stats**, e.g. `1/200` | WHMCS itself | The number of **WHMCS services assigned to this server**, over the server's own **Maximum No. of Accounts** field (Servers > Edit). Pure WHMCS arithmetic — it says nothing about the panel, and no module can change it. A perfectly healthy panel reads `0/200` until WHMCS services point at it. |
| **Accounts: N** | WHMCS's cache (`tblservers_remote.num_accounts`) | WHMCS's own cached count of accounts on the server. Nothing the module reports while the page renders reaches this figure — that was tested on WHMCS 9.0.6 — so it stays **blank on a freshly added server**. The run that hands WHMCS an account list is **Sync Accounts** (§6.3); run that once if you want a number here. |
| **Services: N** | This module | The number of **ISPConfig clients** on the panel — the same figure as *Clients* below, because this module provisions exactly one ISPConfig client per WHMCS service. Refreshed every time the page renders. (It used to be a website count; counting vhosts meant a bulk read that dragged every site's TLS private key across the API, so that number was removed rather than approximated.) |
| **ISPConfig Version / Pending Jobs / Clients / ISPConfig Nodes / Last Updated** | This module, via the admin hook (§2) | Panel version; the depth of ISPConfig's datalog job queue (mutations accepted but not yet applied — a number that keeps climbing means the panel's cron is not running); the number of **ISPConfig clients**, which is this module's own always-fresh answer to "how many accounts"; how many servers the panel fronts; and when WHMCS last refreshed the block. |
| **Probe Errors** | This module | Shown instead of a missing value when a probe fails: the page shows *why*, rather than breaking or quietly reading zero. |

Two consequences worth knowing:

- **Clients vs Accounts.** *Clients* is live; *Accounts* is a cached sync
  result. They can legitimately disagree until the next *Sync Accounts* run.
- If the **ISPConfig Version / Pending Jobs / Clients** lines are missing
  entirely, the admin hook file is not installed — see §2.

Nothing on this page contacts the panel more than once per render, and a panel
that is down costs the block, never the page.

### 6.5 Single sign-on (optional, needs a separate panel-side project)

ISPConfig core has **no one-time-login API**: `client_login_get()` only
validates a password, and the remote API's `login()` mints an API session, not
a panel session. Every older WHMCS/ISPConfig module solved this by storing and
replaying the customer's panel password. This module does not, so out of the
box there is **no login button** — customers get the panel URL and their
username, and nothing more. That is the normal, supported state: **you can skip
this whole section** and everything else in the module works.

To offer a real login button you need one extra piece of software **on the
ISPConfig server**, and it is not part of this repository:

> **`ispconfig-sso`** — a standalone ISPConfig 3.x add-on by the same author,
> GPLv3, installed on the panel and maintained as its own project. It adds one
> remote function that mints a single-use, short-lived panel-login token and one
> page that redeems it, and it modifies no ISPConfig core file. It is
> deliberately **not WHMCS-specific**: any billing system can drive the same
> remote function.

Get that project, read its README, and run its installer **on the ISPConfig
server** (it ships its own `install.sh` / `uninstall.sh`). This module never
installs it, never bundles it, and does not require it — it only *detects* it.

**The panel must be served over HTTPS.** An SSO token is a password substitute
that travels in a URL, so `ispconfig-sso` refuses to mint or redeem one on a
plaintext request — minting answers *"Single sign-on requires an encrypted
(https) connection to the panel."* and the redemption endpoint refuses without
touching the token. ISPConfig's installer offers TLS by default and the stock
panel is `https://…:8080`, so this is normally already true. If something in
front of the panel terminates TLS, declare it on the ISPConfig server:
`reverse_proxy_panel_allowed` in the `[permissions]` section of
`/usr/local/ispconfig/security/security_settings.ini`, set to anything other
than the shipped `none`. No request header (`X-Forwarded-Proto` and friends) is
ever trusted for this.

Then, in ISPConfig: **System > Remote Users > (your API user) > Functions**,
tick *Panel single sign-on (one-time login token)* — the checkbox that project
contributes — and save. This grant is **not** in the standard grant list,
because the function does not exist on a stock panel. (The label is display-only
and set by that project; the grant itself is the function name,
`connect_sso_token_create`.)

Understand what that grant is before you tick it: a remote user holding it can
mint a panel login for **every non-administrator account on the panel**,
**resellers included** — a reseller's panel session administers that reseller's
own sub-clients. ISPConfig gives resellers no distinct user type, so only
`typ = 'admin'` is refused. See
[`docs/REMOTE-USER.md`](REMOTE-USER.md) §2a and the security model in the
`ispconfig-sso` project's own README.

Finally, in WHMCS, open **Configuration > System Settings > Servers** once. That
page refresh is what lets the module notice the project on the panel; from the
next page load on, WHMCS renders a **Log in to ISPConfig** action for services on
that panel. Until then the button stays hidden — the module never shows a login
button it cannot honour.

Notes:

- Detection is one `get_function_list` call, which needs the **Server
  functions** grant the module already requires (see
  [`docs/REMOTE-USER.md`](REMOTE-USER.md) §2). Without it the module cannot tell
  the project is there and keeps the button hidden.
- Detection cannot see the TLS gate. `get_function_list` is a core function and
  answers over plain HTTP, so on an HTTP-only panel WHMCS will advertise the
  button and the *click* will fail with the encrypted-connection message. Serve
  the panel over HTTPS (or declare the reverse proxy) before enabling SSO.
- The password-free login is per **service**. There is deliberately no
  "log in as admin" auto-login: `ispconfig-sso` refuses to mint tokens for
  administrator accounts, because such a token would be a full panel takeover.
- WHMCS's server-level **Login to Control Panel** button (Configuration >
  Servers) therefore does not sign you in automatically. It **opens this
  panel's login page** in a new tab — the address built from the server row, or
  the product's *Panel URL Override* where WHMCS passes one — so you sign in as
  yourself. No administrator session is ever minted on your behalf. (Where
  WHMCS presses that button with a service in scope, you get the real one-time
  login for that service's client instead.)
- The token is single-use and expires in under a minute. It is masked in the
  module log, and the redirect URL that carries it is never logged at all.
- A suspended (locked) client cannot use SSO — the panel refuses to mint the
  token, so a suspension cannot be walked around by clicking the login button.
- With several ISPConfig servers, the label is advertised as soon as **any** of
  them reports the project. On a server that does not have it, clicking the
  button returns an error naming `ispconfig-sso` instead of a blank failure.

## 7. Uninstalling

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
