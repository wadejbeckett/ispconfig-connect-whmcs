# Installing ISPConfig Connect for WHMCS

ISPConfig Connect is a WHMCS provisioning (server) module that manages hosting
accounts on an ISPConfig 3 panel over its remote JSON API. It is plain PHP — no
`composer install` is needed on the WHMCS server. What it needs from PHP:

- **PHP ≥ 8.2** (8.2 and 8.3 are verified), with **ext-curl** and **ext-ctype**
  (both required; ctype is part of every standard PHP build).
- **ext-intl**, recommended. Without it, an internationalised (IDN) server
  hostname must be entered in its punycode `xn--` form (§3), and an
  internationalised customer domain is not converted to punycode, so ISPConfig
  rejects it at Create.
- ext-mbstring is optional: the one place that uses it has a fallback.

Check the PHP that WHMCS really runs — the web server's PHP-FPM and the CLI
that runs WHMCS's cron can differ: `php -m | grep -Ei 'curl|ctype|intl|mbstring'`.

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

Run it as root or as the owner of `includes/hooks`, from any directory —
including a private checkout such as `/root/src/…` that the web user cannot
read. As root, it reads and checks the shipped file itself (the marker, `php -l`,
the comparison with the installed copy) and then makes the change to
`includes/hooks` as that directory's **owner** — through `runuser`, else
`setpriv` with the directory's group, else `su` — handing it the content on
stdin. The installed file therefore belongs to that owner, exactly as if they
had copied it. The file is written to a temp file in the same directory and
renamed into place, and a symlink at the target is refused. The WHMCS path is
resolved physically first, so reaching WHMCS through a symlink in a root-only
directory (for example `/root/whmcs -> /var/www/whmcs`) works too. A refusal or
failure exits with status 1 and leaves the previously installed file as it was.

Confirm the result with `--status`, which should print
`installed and up to date: …`.

The equivalent by hand is a plain copy, made as the owner of `includes/hooks`:

```sh
cp modules/servers/ispconfigconnect/hooks/ispconfigconnect.php  /path/to/whmcs/includes/hooks/
```

Notes:

- **Re-run it after every module upgrade.** The copy in `includes/hooks/` is a
  copy: updating `modules/servers/ispconfigconnect/` does not update it.
  `--status` reports when the installed file differs from the shipped one, and
  warns about a second marked copy under another `.php` name (WHMCS loads that
  too, and whichever copy loads first wins).
- Skipping this step costs only that block. Nothing else in the module depends
  on it.
- Remove it with `--uninstall` when you uninstall the module (§8).

### Upgrading from an earlier build

1. **Replace the whole directory; do not copy over it.** For example
   `rsync -a --delete modules/servers/ispconfigconnect/ /path/to/whmcs/modules/servers/ispconfigconnect/`,
   or delete the old directory first and then `cp -r`. This build adds
   `lib/Provision/ClientLockedException.php` and ships 58 files
   (`find /path/to/whmcs/modules/servers/ispconfigconnect -type f | wc -l`);
   `whmcs.json` still reads `0.2.0`, so its version cannot tell the builds apart.
   Reload PHP-FPM (or reset OPcache) if it does not revalidate files.
2. **Re-run the hook installer** (above); `--status` must say
   `installed and up to date`.
3. **Check each ISPConfig server's Hostname / IP Address** (§3). It must be a
   bare host name or IP address; anything else is now refused before a request
   is sent.
4. **Website-only products** (*Suspend Entire Client* unticked): a service
   without a stored ISPConfig website id — typically one linked by hand — is now
   **refused** by Suspend and Unsuspend, where the earlier build reported success
   and changed nothing. Its *Module* tab shows *ISPConfig Domain ID* `n/a`. With
   the service's Username field naming the account it is already linked to,
   press **Replace ISPConfig Link**: it stores the website id when the service
   domain matches exactly one of that client's websites (§5). A service the
   earlier build "suspended" this way was never taken offline — press Suspend
   again once the id is stored.
5. **Shared ISPConfig clients** — one client linked to several live services on
   the same panel — are now refused by every whole-client operation (§6). Decide
   which service owns each client and unlink the others.
6. **Change Package** is now refused while the ISPConfig client is locked (§6).
7. **After the upgrade, check the module log for `SharedClientGuard` entries.**
   Each one is a shared-client check that could not run; while it cannot,
   Terminate refuses.
8. A bandwidth figure an earlier build carried over from a previous month stays
   in `bwusage` until the next month boundary.

[`docs/LIVE-TEST-PLAN.md`](LIVE-TEST-PLAN.md) §2 has the SQL that finds the
servers and services behind points 3–5 and 8.

## 3. Add the server in WHMCS

**System Settings > Servers > Add New Server**, then:

| Field | Value |
|---|---|
| Module | ISPConfig Connect |
| Hostname | The panel host, e.g. `panel.example.com` (the IP Address field is used when Hostname is empty). A **bare** host name or IP address only — no scheme, port, path or `user@`; the module refuses anything else with a message saying so. An internationalised host name is converted to punycode when ext-intl is loaded; without it, enter the `xn--` form |
| Port | `8080` (the ISPConfig default; the module defaults to 8080 when the field is empty). The port goes here, never in Hostname |
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

> **Use products, not product addons.** For a product addon, WHMCS passes the
> *parent* service's id. When that parent is itself linked to an ISPConfig
> client (or that cannot be checked), every operation on the addon refuses and
> changes nothing, because it could otherwise reach the parent's client. An
> addon on a parent that is not an ISPConfig service runs on its own stored ids
> only, as in earlier builds; provision new ISPConfig hosting as a product.

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
      can still log into the panel. It needs the service's ISPConfig website
      id, which Create Account stores, and which Link / Replace ISPConfig
      Link store when the service domain matches one of the client's websites
      (§5). Without it, Suspend and Unsuspend **refuse** and change nothing —
      website-only mode never falls back to locking the whole client. On a
      product with Create Website off, it therefore works only for services
      linked to a website that already exists.
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

**The whole job is: point the service at the ISPConfig server, give it an
ISPConfig product, and link it to its panel account by name with one button.**

> **In this build, linking is done by hand.** Automatic adoption — the module
> finding the matching panel account by the service's domain on its own — is
> designed and wired in, but it runs only when an adoption resolver
> (`lib/Adopt/Resolver.php`) is installed, and this build ships none. Until one
> is, every unlinked service is linked with **Link to ISPConfig Account**, and
> the module says so wherever it would otherwise have adopted (*Automatic
> adoption*, below).

### The migration sequence

1. **Move the hosting data** onto ISPConfig however you normally would, and
   create (or keep) the customer's ISPConfig client account. The module does
   not move data and does not care how it got there.
2. In WHMCS, open the service and set its **Server** to the ISPConfig server
   and its **Product** to an ISPConfig Connect product.
3. Set the service's **Username** field to the name of the ISPConfig client
   account that owns the service's domain, save, and press **Link to ISPConfig
   Account** on the service's *Module* tab.
4. Check the *Module* tab (*Checking that it worked*, below).

There is deliberately **no step where you type an ISPConfig client id
anywhere**. An internal id is opaque, cannot be checked by eye, and one wrong
digit points the module at a different customer's account — after which a
suspend or a terminate acts on the wrong hosting. You name the account; the
module looks up its id and checks that it exists.

### The link buttons

Three buttons sit on the service's *Module* tab next to *Verify Permissions*.
**None of them changes anything on the panel** — Link and Replace only read
from it — so none of them can damage a customer's hosting.

| Button | What it does |
|---|---|
| **Link to ISPConfig Account** | Links this service to the ISPConfig account named in the service's **Username** field, after checking with the panel that the account exists and is a client account (not a panel administrator login). It also stores the service's **website id** when it can pin one down (below). Refuses if the service already carries a *different* id, or if another live WHMCS service on the same panel is already linked to that client (§6). Naming the account the service is already linked to re-points nothing; it only fills in a missing website id. |
| **Replace ISPConfig Link** | The same checks, but it deliberately overwrites an existing link; the other ids stored for the old account (website, FTP, mail, DNS, database) are cleared with it. Both the old and the new id go to the module log. Pressed on a service that is already linked to the named account, it too only fills in a missing website id — it never overwrites a stored one. On a website-only product, if no website of that client matches the service domain, it says so (and why) instead of reporting success. |
| **Unlink from ISPConfig** | Clears the stored ids so a wrong link is reversible without hand-editing custom fields. The panel account is untouched. |

**How the website id is found.** Website-only suspension acts on the service's
ISPConfig website and refuses without its id (§4, item 13), so the link
resolves it too. The module lists the websites filed under the client's own
user and its own default group only (`client_get_sites_by_user`) — never a
reseller's whole group list, which would include its sub-clients' sites — and
looks for exactly one named like the service domain. It then reads that website
back (`sites_web_domain_get`) and stores its id only if the site is filed under
the client's own group. Anything else — no match, several matches, a refused
lookup — links the client without a website id, and the reason is in the
`website` entry of the link's module-log line.

> **The Username field is the stale one on a migrated service.** This is worth
> knowing before you use *Link to ISPConfig Account*: a service that came from
> another control panel still carries **that panel's** username in WHMCS, and
> ISPConfig has never heard of it, so linking by name will simply not find it.
> The refusal says so and names the service's domain. Correct the Username
> field to the ISPConfig account name that owns that domain and press the
> button again.

### What it will not do

These are safety rules, not limitations to work around:

- **It never links inside a destructive operation.** Terminate, Suspend,
  Unsuspend, Change Password and Change Package all *refuse* an unlinked
  service and tell you how to link it. A wrong match inside a terminate would
  delete a live customer's hosting, and no amount of confidence in a match
  makes that recoverable.
- **It never overwrites a link that already exists** without the explicit
  *Replace ISPConfig Link* button. Pressing either button for the account
  already linked fills in a missing website id and never changes a stored one.
- **It never links a client that another service holds.** Link, Replace and
  automatic adoption refuse an ISPConfig client that another live WHMCS
  service on the same panel is already linked to (§6).
- **A stale link is reported, never repaired.** If the stored client id no
  longer exists on the panel, the *Module* tab says **STALE**, and
  **Terminate** checks for this and refuses. Suspend, Unsuspend, Change
  Password and Change Package do *not* check first: they act on the stored id
  and report whatever the panel answers — so read the *ISPConfig Link* row
  before running them on a service you are unsure of. The module will not go
  looking for a replacement account, because the one it found could belong to
  somebody else.

### Automatic adoption (only with a resolver installed)

This build ships no resolver, so on a normal install the surfaces below
**report** unlinked services instead of linking them:

- the *Module* tab's **ISPConfig Link** row reads *Not linked to an ISPConfig
  account. Automatic adoption is unavailable on this install, so link it with
  the "Link to ISPConfig Account" button…*;
- the nightly `UsageUpdate` module-log entry (§7.1) lists each unlinked service
  under `skipped` as *no ISPConfig client id stored for this service*, and adds
  an `adoption` line: *unavailable: N service(s) on this server carry no
  ISPConfig client id, and this install has no adoption resolver…*;
- every refusal of an unlinked service ends *Automatic adoption is not
  installed on this WHMCS, so linking by name is the only route until it is.*

With a resolver installed, two moments link unlinked services by themselves,
keyed on the service's domain:

| Moment | What it does |
|---|---|
| **The nightly usage sweep** (§7.1) | Resolves every unlinked service on the server in one batch, links the confident matches, and imports their usage in the same run. |
| **Opening the service's *Module* tab** | Resolves that one service on the spot. |

Both are read-only against the panel — nothing is created, changed or deleted
there. Both record what they did in **Utilities > Logs > Module Log** under the
action `Adopt`, together with the evidence that justified the link. Adoption
links only on an exact, unambiguous match: if no panel client owns the
service's domain, or more than one does, or the client is already linked to
another live service, or that last check cannot run, the service is left
unmanaged with the reason recorded. A service that already has a stored id is
never offered to the matcher.

### Checking that it worked

The service's *Module* tab answers in plain English. The **ISPConfig Link** row
names the account the service is linked to, or says why it is not linked, or
that the link is stale; the **ISPConfig Domain ID** row shows the stored website
id, or `n/a` when there is none (website-only suspension then refuses — §4,
item 13). Every Link and Replace writes a `LinkAccount` / `RelinkAccount`
entry to the module log with the ids it stored and, under `website`, why a
website id was or was not stored. With a resolver installed, the `UsageUpdate`
entry after a sweep also carries an `adopted` list (what was linked, and on
what evidence) and an `unlinked` list (what was left alone, and why).

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
If you manage extra objects under the same client by hand, prefer the
website-only mode — it needs the service's website id (§4, item 13).

**Unsuspend reverses what Suspend did, not what the product says now.**
Suspend records the mode it used in the service property
`ispconfig_suspended_as` (`client` or `website`; WHMCS creates the admin-only
field on first use), and Unsuspend reverses that mode and blanks the record.
So ticking or unticking *Suspend Entire Client*, or moving a suspended service
to another product, no longer strands it: a client locked under the old
setting is unlocked, and a website suspended under the old setting is set
active again. A client lock you set **by hand** in ISPConfig on a service the
module suspended website-only is left alone. Suspensions made by an earlier
build carry no record; for those Unsuspend follows the product's current
setting, with two safety nets. When the product locks the whole client but the
client is not locked, the service's website is set active if it is off. When
the product suspends the website only but the client **is** locked, Unsuspend
refuses and says why: that lock came from an earlier whole-client suspension or
was set by hand, and reactivating only the website would leave mail, FTP and
databases off behind a success. Tick *Suspend Entire Client* for that one
Unsuspend (or remove a hand-set lock in ISPConfig) and press it again.

The record is written **before** the panel is touched, so a WHMCS database
error stops Suspend with nothing changed, and a panel failure puts the
previous record back. A record is only trusted while WHMCS has the service
Suspended: one left behind on an Active service is ignored. Turning a
website-only suspension into a whole-client lock (Suspend pressed again after
the product setting changed) sets the website active first so the lock records
it as active, leaves a client that is already locked alone, and sets the
website inactive again if the lock fails.

### One WHMCS service per ISPConfig client

The module maps one WHMCS service to one ISPConfig client, and it now
**enforces** that: do not share one ISPConfig client between several WHMCS
services. An operation that would reach another service's hosting refuses, and
changes nothing, when another **live** service (any status except Terminated,
Cancelled and Fraud) on the same panel is linked to the same client:

| Operation | On a shared client |
|---|---|
| Link / Replace ISPConfig Link | Refused (when the named account is not the one already stored) |
| Automatic adoption (§5) | The service is left unlinked |
| Terminate | Refused — it would delete the other service's hosting |
| Suspend, with *Suspend Entire Client* | Refused |
| Unsuspend, with *Suspend Entire Client* | Allowed — and logged — unless another service on that client is itself Suspended, because the unlock would bring that one back online too |
| Change Password | Refused — the panel login belongs to the whole client |
| Change Package | Refused |
| Website-only Suspend / Unsuspend | Allowed — it touches only this service's own website |

Each refusal names the other services (service id, domain, WHMCS client and
status). Resolve it by giving each service its own ISPConfig client, or press
**Unlink from ISPConfig** on the services that should not point there.

**Only the same panel counts.** ISPConfig numbers clients per panel, so client
#7 on one panel and client #7 on another are different customers. But two
WHMCS server records can also reach one panel under different addresses (an
alias host name, a host name on one and the IP on the other, IPv4 and IPv6), so
addresses alone never decide it. A service on another server record is ignored
only when **both** hold: its record shares no Hostname or IP Address with this
service's, **and** the two services store different ISPConfig client usernames
(the same client on one panel has one username). Anything less certain — no
server set, a deleted server record, a username missing on either side — still
counts, so the check refuses rather than risks deleting another customer's
hosting. The one case it cannot see: the same client renamed in ISPConfig
between two links, on two server records with different addresses. **If you
keep two WHMCS server records for one panel, give both the same Hostname or IP
Address.** Each service is judged by the client id the module would actually
read for it (its current product's custom field first, then its newest row),
so a value an old product left behind does not count. The nightly usage import
reads a service's link by the same rule.

**When the check cannot run** (a WHMCS database error), the module writes a
`SharedClientGuard` entry to the module log. Terminate and automatic adoption
then refuse; the other operations go ahead without the check.

### What a package change does

Change Package sends exactly **one** `client_update`, carrying the new master
template and the addon templates (§4) together. Around it:

- **It is refused while the ISPConfig client is locked**, i.e. while the service
  is suspended with *Suspend Entire Client*. ISPConfig's `client_update` re-runs
  the client lock on every update; on a locked client that would overwrite the
  lock's record of what was already inactive, and a later Unsuspend could then
  restore nothing. Unsuspend first, change the package, then suspend again if
  needed. The refusal changes nothing.
- **A Suspended service is suspended again straight afterwards**, in the *new*
  product's mode, because `client_update` on an unlocked client re-activates the
  client's objects. Website-only: the stored website is set inactive again;
  *Suspend Entire Client*: the client is locked. If that re-suspension fails,
  Change Package does not report success: its message says the website (or the
  client) is **live** and to press Suspend.
- One ISPConfig behaviour remains that the module cannot avoid: any
  `client_update`, this one included, re-enables objects an admin disabled by
  hand on an **active** client.

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

If the service's WHMCS server record has an empty or malformed Hostname / IP
Address, the tab still shows every other row, and its **ISPConfig Panel** row
explains the problem with the server record instead of showing a link.

### Login rate limit and NAT (lockout caveat)

ISPConfig locks out a source IP for 5 minutes after **10 failed logins within
5 minutes** — and expired/invalid session lookups count as failures too. The
module logs in once per operation, retries an expired session exactly once,
and stops calling the panel for the rest of the operation as soon as it sees a
lockout or a rejected login. A transport failure (a timeout, an HTTP error from
a proxy) stops it only after **two in a row**; any answer from the panel,
including a successful re-login between two failures, resets that count, so one transient failure costs one call, not the rest of a nightly
sweep (§7.1). Normal operation therefore stays far from the limit.

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
`lastupdate`, stamped from PHP's clock — the same clock the month reset of
`bwusage` compares it with, so a database server in another time zone cannot
delay that reset. The figures come from the panel's own accounting:

| WHMCS column | ISPConfig source |
|---|---|
| `diskusage` | `quota_get_by_user` — the repquota block count for each of the client's websites |
| `disklimit` | `web_domain.hd_quota` (MB; `-1`/`0` = unlimited, in which case the column is **left unset** so WHMCS keeps the product's own limit) |
| `bwusage` | `trafficquota_get_by_user` — this calendar month's `web_traffic` bytes (reset at the month boundary, never carried forward as a peak; a figure stored on the 1st is not kept as a peak, because the panel may still have been in the previous month) |
| `bwlimit` | `web_domain.traffic_quota` (MB, same unlimited convention as `disklimit`) |

Requirements and behaviour worth knowing:

- The server must be **enabled** in WHMCS (a disabled server is never visited)
  and only services in the **Active** or **Suspended** status are read —
  terminated services have no panel client left to ask about.
- A service is readable once it is **linked** to a panel account (§5). An
  unlinked service is **named** in the log rather than silently ignored: it is
  listed under `skipped` as *no ISPConfig client id stored for this service*,
  and the entry's `adoption` line says that this install has no adoption
  resolver, so such services must be linked with *Link to ISPConfig Account*.
  (With a resolver installed, the sweep first tries to link them in a single
  batch, imports usage for whatever it linked in the same run, and records in
  `adopted` / `unlinked` what it linked and what it left alone, with the
  reason.) A service's link is read from its current product's custom field
  first; a blank row left behind on another product never hides it.
- The whole sweep uses **one login**. It begins with a single reachability
  probe, so an unreachable or mis-credentialed panel costs one failed login
  for the entire run instead of one per service — which matters because ten
  failed logins lock the WHMCS server's IP out of the panel for five minutes.
- One unreadable service never costs the others their import: a panel fault,
  or a single transport failure (a timeout, an HTTP 500 from a proxy), is
  recorded as that service's `skipped` reason and the sweep moves on. Two
  transport failures **in a row** on the same server (with no successful
  re-login between them) are taken to mean the
  panel has gone away: the rest of that server's sweep is not attempted, and
  each remaining service is listed under `skipped` with that same reason. A
  lockout seen mid-sweep aborts the server's run (`error`), and nothing is
  written for it.

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
| **Accounts: N** | This module, via the admin hook (§2) | The **live ISPConfig client count** from the last metadata refresh — the same figure as *Clients* below. Core's own value for this cell is `tblservers_remote.num_accounts`, a column no returned key populates for this module (tested on WHMCS 9.0.6), so core would show **0** forever; with the admin hook installed the block rewrites the cell client-side to the real count when the page renders, on every row it identifies by the server's own link (see below). **Without the hook it reads 0**, and no server sync will change that — do not run *Sync Accounts* to fill it. |
| **Services: N** | This module | The number of **ISPConfig clients** on the panel — the same figure as *Clients* below, because this module provisions exactly one ISPConfig client per WHMCS service. Refreshed every time the page renders. (It used to be a website count; counting vhosts meant a bulk read that dragged every site's TLS private key across the API, so that number was removed rather than approximated.) |
| **ISPConfig Version / Pending Jobs / Clients / ISPConfig Nodes / Last Updated** | This module, via the admin hook (§2) | Panel version; the depth of ISPConfig's datalog job queue (mutations accepted but not yet applied — a number that keeps climbing means the panel's cron is not running); the number of **ISPConfig clients**, which is this module's own always-fresh answer to "how many accounts"; how many servers the panel fronts; and when WHMCS last refreshed the block. |
| **Probe Errors** | This module | Shown instead of a missing value when a probe fails: the page shows *why*, rather than breaking or quietly reading zero. |

Two consequences worth knowing:

- **Clients vs Accounts.** With the admin hook installed, *Accounts* is rewritten
  to the very same live client count as *Clients* on every row the script finds
  through a link carrying the server's id — the row's own edit link
  (`configservers.php?action=manage&id=N`, written relative or absolute; both
  are resolved the way the browser resolves them), or failing that another
  `?id=N` link inside the table. A server-group link never anchors a row. A row
  found only by its hostname text still gets the block, but its *Accounts* cell
  is left as core printed it, because the same hostname can appear on another
  module's row. **So if *Accounts* reads 0 next to a correct *Clients* figure,
  the row was matched by hostname text only.** Without the hook, *Clients* is
  not shown at all and *Accounts* reads 0 — a server sync will not change that.
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
the *Log in to ISPConfig* action — **Active** services only: a Suspended,
Cancelled, Terminated or Fraud service gets no button in the client area, and a
login attempt for it is refused. (The status is read from what WHMCS passes the
module, `$params['status']`; when WHMCS passes none, this check is not applied.)
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

That row describes the **panel**. Two things about the **service** also hide the
client-area button, whatever the row says:

- **The service is not Active.** Only Active services get single sign-on (§7.5).
- **The service's WHMCS server has an empty or malformed Hostname / IP
  Address** (§3). The client-area page still renders, but without the login
  button, and without the *Open Control Panel* button too unless the product
  has a valid *Panel URL Override*. The admin service tab's *ISPConfig Panel*
  row says what is wrong with the server record (§6).

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
| single sign-on is **only available while the service is active**, naming its status | The service is Suspended, Cancelled, Terminated or Fraud in WHMCS | Working as intended; the customer sees the one fixed sentence. Reactivate the service if it should have access. The login button on the **admin's** service page is not gated on the status (a locked client is still refused by ISPConfig), and shows the admin the diagnosis |
| `The client account is locked; single sign-on is refused.` | The ISPConfig client is locked — normally because the service is suspended with *Suspend Entire Client* | Working as intended: a suspension cannot be walked around by clicking the login button |
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
