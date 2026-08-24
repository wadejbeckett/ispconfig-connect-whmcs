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

The directory name must remain exactly `ispconfigconnect`. Nothing else is
required — WHMCS autoloads the module's `lib/` classes automatically.

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
13. **Suspend Also Disables Website** — when WHMCS suspends the service, the
    module always locks the ISPConfig client; with this option (default on)
    it also sets the website inactive so the site stops serving.
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

### Provisioning is eventually consistent

The ISPConfig API is asynchronous by design: an accepted API call writes the
change to the panel's datalog, and each server's cron applies it — typically
within a minute. "Success" in WHMCS therefore means *accepted by the panel*,
not *already live on the web server*. The admin service tab shows the panel's
current **job queue** depth; a non-zero value means changes are still being
rolled out.

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
debug logging in WHMCS to see it). Passwords and session ids are masked via
WHMCS's replaceVars mechanism before storage.

## 6. Uninstalling

Remove the product/server assignments in WHMCS, then delete
`modules/servers/ispconfigconnect/`. The module stores its state as WHMCS
service properties (`ispconfig_client_id`, `ispconfig_domain_id`, …); it
creates no extra database tables.
