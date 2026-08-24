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
  Hostnames are allowed (resolved at login). Leave empty only if you accept logins from anywhere.
  *Gotcha:* ISPConfig compares the source address it sees — behind NAT or IPv6 privacy addressing,
  use whatever address the panel actually logs.

## 2. Grant exactly these functions

The panel groups grants into checkboxes per area; the underlying grant names the module requires
are (from `Permissions::requiredGrants()`):

```
client_add
client_change_password
client_delete
client_delete_everything
client_get
client_get_by_username
client_get_sites_by_user
client_templates_get_all
client_update
dns_templatezone_add
dns_zone_get_id
get_function_list
mail_domain_add
monitor_jobqueue_count
quota_get_by_user
server_get
sites_database_add
sites_database_user_add
sites_database_user_update
sites_ftp_user_add
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
| `client_get`, `client_get_by_username`, `client_get_sites_by_user` | suspend/unsuspend state guard and the addon-assignment read before every client update (`client_template_additional_get` checks grant `client_get`, verified in `remote.d/client.inc.php`); admin panel info, import/sync (Phase 2). Without `client_get` every suspend, unsuspend and package change fails |
| `client_templates_get_all` | the Client Template dropdown in product config |
| `server_get` | the Web Server dropdown (`server_get_all` checks grant `server_get`, verified) |
| `sites_web_domain_*`, `sites_ftp_user_add`, `sites_database_*` | website / FTP / database provisioning |
| `sites_ftp_user_update`, `sites_database_user_update` | password changes rotate the FTP and database credentials the module provisioned with the service password — without these grants a WHMCS password change updates only the panel login and leaves the old FTP/DB passwords live |
| `mail_domain_add` | mail domain provisioning |
| `dns_templatezone_add`, `dns_zone_get_id` | DNS zone provisioning |
| `monitor_jobqueue_count` | pending-job indicator (ISPConfig applies changes asynchronously) |
| `quota_get_by_user`, `trafficquota_get_by_user` | disk/bandwidth usage sync (Phase 2) |
| `get_function_list` | connection diagnostics |

`login`/`logout` need no grant.

## 3. Enable the remote API itself

**System → Main Config → Security → Remote API allowed**: yes. If it is off, every call answers
with the plain-text `Remote API is disabled in security settings.` — TestConnection reports this
explicitly.

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
