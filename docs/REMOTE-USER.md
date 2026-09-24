# Creating the least-privilege ISPConfig remote user

ISPConfig Connect never uses the panel admin account. It authenticates as a **remote user** — an
API-only identity carrying exactly the function grants the module needs and (ideally) an IP
allowlist restricted to your WHMCS server. This page is the source-verified setup guide; the
authoritative machine-readable list lives in `lib/Api/Permissions.php`.

## 1. Create the remote user

In the ISPConfig panel: **System → Remote Users → Add new user**.

- **Username / Password**: pick a dedicated identity, e.g. `whmcs-connect`. Use a long random
  password; it goes into the WHMCS server's *Password* field.
- **Remote Access**: enabled.
- **Remote Access IPs**: enter your WHMCS server's public IP address(es), comma-separated.
  Leave empty only if you accept logins from anywhere.
  *Gotcha:* ISPConfig compares the source address it sees — behind NAT or IPv6 privacy addressing,
  use whatever address the panel actually logs.

  **Prefer literal addresses over hostnames.** ISPConfig accepts hostnames here, but it resolves
  every one of them with `dns_get_record($name, DNS_A + DNS_AAAA)` **on every single login**
  (`remoting.inc.php:173-189`, verified 3.3.1p1) — there is no cache and no error handling. Two
  consequences follow:
  - **Your DNS becomes an authorisation input.** Whoever can influence what that name resolves to on
    the panel host — a poisoned resolver, an edited `/etc/hosts`, a hijacked zone — can add
    themselves to the allowlist.
  - **A resolver failure locks you out.** When the lookup returns nothing, the entry is silently
    dropped from the allowlist rather than erroring, so every call fails with
    *"The login is not allowed from &lt;ip&gt;"* until DNS recovers.

  A hostname is the right choice only when the WHMCS server's address genuinely changes and you
  control the zone. Otherwise pin the address.

## 2. Tick these permission checkboxes

The panel's remote-user form grants functions in **groups** — one checkbox stores a fixed list of
function names (defined in each module's `lib/remote.conf.php`, verified 3.3.1p1; the same groups
exist on 3.2.x). The module needs these nine:

| Checkbox | Why the module needs it |
|---|---|
| **Server functions** | `server_get` (Web Server dropdown, and the `server_get_app_version` panel-version probe — it checks `server_get`, verified), `client_templates_get_all` (Client Template dropdown), `get_function_list` (diagnostics, and the probe that detects the optional panel-side SSO project — §2a). *Caveat: this group also carries config-write functions (`system_config_set`, `server_ip_*`, …) — checkbox granularity forces the over-grant; the IP allowlist and TLS bound the exposure.* |
| **Client functions** | client create/update/lock/password/terminate (incl. both `client_delete_everything` and `client_delete`, which termination re-checks mid-cascade), plus `client_get_all` for the servers-page client counter |
| **Quota functions** | the whole group — `quota_get_by_user`, `trafficquota_get_by_user`, `mailquota_get_by_user`, `databasequota_get_by_user` — for disk/bandwidth usage import and usage-billing metrics |
| **Sites Domain functions** | website create/read/status (suspend) |
| **Sites FTP-User functions** | FTP user create + password rotation, and `sites_ftp_user_get` for the FTP-user metric |
| **Sites database functions** | database + database-user create, DB password rotation |
| **Mail domain functions** | mail domain provisioning |
| **DNS zone functions** | `dns_templatezone_add` + `dns_zone_get_id`, and `dns_zone_get` for the DNS-zone metric |
| **Monitor functions** | `monitor_jobqueue_count` (pending-job indicator) |

The precise function names the module actually calls (the minimal set, for auditing or for a
hand-edited `remote_functions` value) are (from `Permissions::requiredGrants()`):

```
client_add
client_change_password
client_delete
client_delete_everything
client_get
client_get_all
client_get_by_username
client_get_sites_by_user
client_templates_get_all
client_update
databasequota_get_by_user
dns_templatezone_add
dns_zone_get
dns_zone_get_id
get_function_list
mail_domain_add
mailquota_get_by_user
monitor_jobqueue_count
quota_get_by_user
server_get
sites_database_add
sites_database_user_add
sites_database_user_update
sites_ftp_user_add
sites_ftp_user_get
sites_ftp_user_update
sites_web_domain_add
sites_web_domain_get
sites_web_domain_set_status
trafficquota_get_by_user
```

Why each (grouped):

| Grants | Used for |
|---|---|
| `client_add`, `client_update`, `client_change_password` | create / suspend (locked flag) / package & password changes. `client_update` also covers addon-template assignment — ISPConfig checks `client_update` for `client_template_additional_add/delete` (verified in 3.3.1p1 `remote.d/client.inc.php`) |
| `client_delete_everything` **and** `client_delete` | termination. `client_delete_everything` re-checks `client_delete` mid-cascade (verified), so both are required — missing `client_delete` fails termination half-way |
| `client_get`, `client_get_by_username`, `client_get_sites_by_user` | suspend/unsuspend state guard and the addon-assignment read before every client update (`client_template_additional_get` checks grant `client_get`, verified in `remote.d/client.inc.php`); admin panel info. Also **Sync Accounts** (WHMCS's ListAccounts): one `client_get` with `client_id = -1` reads the whole client table, then `client_get_by_username` + `client_get_sites_by_user` resolve each client's primary website. Without `client_get` every suspend, unsuspend and package change fails; without the other two, Sync Accounts lists accounts with no domain |
| `client_templates_get_all` | the Client Template dropdown in product config |
| `server_get` | the Web Server dropdown (`server_get_all` checks grant `server_get`, verified) |
| `sites_web_domain_*`, `sites_ftp_user_add`, `sites_database_*` | website / FTP / database provisioning |
| `sites_ftp_user_update`, `sites_database_user_update` | password changes rotate the FTP and database credentials the module provisioned with the service password — without these grants a WHMCS password change updates only the panel login and leaves the old FTP/DB passwords live |
| `mail_domain_add` | mail domain provisioning |
| `dns_templatezone_add`, `dns_zone_get_id` | DNS zone provisioning |
| `monitor_jobqueue_count` | pending-job indicator (ISPConfig applies changes asynchronously) |
| `server_get`, `monitor_jobqueue_count`, `client_get_all` | the **Configuration → Servers** stats block: panel version (`server_get_app_version`, grant `server_get`), pending datalog jobs, client count and ISPConfig node count. All four probes return ids, counts or a version string — the module deliberately does **not** count websites or mail domains there, because ISPConfig's only bulk read is `SELECT *` and `web_domain.ssl_key` / `mail_domain.dkim_private` would land in WHMCS's module log. Any of these grants missing just drops its own line from the block |
| `quota_get_by_user`, `trafficquota_get_by_user` | the nightly disk/bandwidth import into `tblhosting` (WHMCS's UsageUpdate cron), which also drives disk and bandwidth overage billing. When a service's quota answers come back empty, the sweep also spends one `client_get` (already granted above) to distinguish "client owns nothing" from "client was deleted in the panel" — a deleted client answers every quota call with zero rows rather than an error, and importing that as zero usage forever would be silent wrong billing; such services are skipped with a reason in the module log instead |
| `mailquota_get_by_user`, `databasequota_get_by_user` | usage-billing metrics: mailbox and database storage, and the mailbox/database counts. Same **Quota functions** checkbox as the two above, so they cost no extra grant group |
| `sites_ftp_user_get`, `dns_zone_get` | usage-billing metrics: the FTP-user and DNS-zone counts, read as `sys_groupid` filters. Both are already inside the **Sites FTP-User functions** and **DNS zone functions** checkboxes the module needs for provisioning |
| `get_function_list` | connection diagnostics, and the probe that detects the optional panel-side SSO project (§2a) |

`login` and `logout` are the only ungated remote functions. Everything else — `get_function_list`
included — goes through `checkPerm()` and needs its grant
(`remoting.inc.php:685-692`, verified 3.3.1p1).

### 2a. Optional — the single sign-on grant

One further grant exists, and it is **not part of the list above** because it does not exist on a
stock ISPConfig. It is registered only where the panel has the separate
[**`ispconfig-sso`**](https://github.com/wadejbeckett/ispconfig-sso) project installed — a standalone
GPLv3 ISPConfig add-on by the same author, which this module does not ship and does not require (any
billing system can use it). That project is what gives the client area and the admin service tab a
working *Log in to ISPConfig* button. **Skip this whole section unless you have installed it on the
panel** — a stock panel needs no grant here, and the module will simply keep the button hidden.

| Function | Why |
|---|---|
| `connect_sso_token_create` | mints a 45-second, single-use panel-login token bound to one client, so the module never has to store or replay a customer's panel password |

The project ships its own `lib/remote.conf.php`, so the grant appears as a checkbox of its own on the
Remote Users form. [`INSTALL.md` §7.5](INSTALL.md) names that checkbox and where to tick it; the
project's own README is the setup path and the source for everything panel-side (installation, the
HTTPS requirement, and what to do when the checkbox is missing from the form):
[**`ispconfig-sso` → *3. Install and remove***](https://github.com/wadejbeckett/ispconfig-sso#3-install-and-remove).

On this side, the grant is the list from `Permissions::optionalGrants()`;
`Permissions::requiredGrants()` deliberately excludes it, so a missing SSO grant is never treated
as a broken connection by Test Connection or Verify Permissions.

Notes:

- **Detection is separate from the grant.** Without it, the module still *detects* the project and
  reports an actionable error naming the grant when SSO is attempted. Detection is one
  `get_function_list` call, which returns the panel's whole registered method list rather than the
  caller's own grants — so it distinguishes "the panel does not have the SSO project" from
  "installed but not granted". It is **not** an ungated call: it needs the **Server functions**
  grant the module already requires (see the note under the grant list above). Without *that*,
  detection fails closed and the module treats SSO as unavailable.
- **Know the blast radius before you tick this box.** A remote user holding this grant can mint a
  panel login for **every non-administrator account on the panel** — and that includes
  **resellers**, whose panel session administers their own sub-clients. ISPConfig gives a reseller
  no distinct user type: every client the API creates gets `sys_user.typ = 'user'`
  (`remoting_lib.inc.php:329`), and `limit_client > 0` only adds the `client` module
  (`:311-313`). Only `typ = 'admin'` is refused. Grant it solely to the API user WHMCS uses, and
  keep that user's Remote Access IP list populated. The `ispconfig-sso` project's own README carries
  the full security model.

## 3. Enable the remote API itself

`remote_api_allowed=yes` in the `[permissions]` section of
`/usr/local/ispconfig/security/security_settings.ini` on the ISPConfig **server**. This is a
server-side file that only root can edit — **there is no panel form for it** (verified: the value is
read by `interface/web/remote/json.php:14` via `getconf::get_security_config('permissions')`, and
its only definition is `install/tpl/security_settings.ini.master:15`).

Stock installs ship it enabled. When it is off, every call answers with the plain-text
`Remote API is disabled in security settings.` — TestConnection reports this explicitly.

## 4. Verify from WHMCS

After entering the server details in WHMCS (**Configuration → System Settings → Servers**), use
**Test Connection**, and after assigning a product, the **Verify Permissions** button on any
service using the module. Both run safe read-only probes and name any missing grant precisely.
Write grants (create/delete) cannot be probed without creating data — they are exercised the first
time a test order is provisioned.

## 5. Operational caveats

- **Lockout**: 10 failed attempts from one IP within 5 minutes locks that IP out — and *expired
  session ids count as failures*. The module logs in once per operation and backs off on lockout;
  don't hammer Test Connection while locked out.
- **Sessions are IP-bound** with a fixed 30-minute TTL. If your WHMCS host's outbound IP rotates
  (NAT pools), pin it — rotating addresses break in-flight batches.
- **TLS**: verification is ON by default. For a self-signed panel certificate, either install a
  proper certificate (recommended — the panel serves 8080), put the CA bundle path or PEM in the
  server's *Access Hash* field, or prefix the Access Hash with `insecure:` to disable verification
  (last resort; documented in INSTALL.md).
