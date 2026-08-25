# ispconfig-sso — panel single sign-on for ISPConfig

An **optional** ISPConfig-side companion module. It adds one remote API function that mints a
short-lived, single-use panel-login token, and one page that redeems it. With it installed, a
billing system can offer a working *Log in to panel* button; without it, nothing breaks — the
billing system simply shows the panel URL and username instead.

GPLv3. No ISPConfig core file is modified, read-only or otherwise.

> ### ⚠ The panel must be served over HTTPS
>
> A token is a **password substitute travelling in a URL**. Over plain HTTP the mint response, the
> redirect that carries the token and the panel session that follows are all readable by anything on
> the path — and single use plus a 45-second lifetime buy nothing against an interceptor who simply
> redeems the token before the customer's browser does.
>
> Both halves therefore **refuse to operate on a non-TLS request**. Minting answers
> `Single sign-on requires an encrypted (https) connection to the panel.`; the redemption endpoint
> refuses without touching the token. ISPConfig's own installer asks *"Do you want a secure (SSL)
> connection to the ISPConfig web interface"* and **defaults to yes** (`install/install.php:608`), so
> on a normal install this is already satisfied.
>
> **Behind a TLS-terminating reverse proxy** PHP sees the proxy's plaintext hop and cannot tell the
> difference, so the gate steps aside when — and only when — the operator has declared that topology
> in ISPConfig's own security settings:
> `reverse_proxy_panel_allowed` in the `[permissions]` section of
> `/usr/local/ispconfig/security/security_settings.ini`, set to anything other than the shipped
> `none`. That is a local file only root can write. No request header is ever trusted for this, and
> declaring it makes TLS termination the proxy's responsibility.

---

## 1. Why this exists

ISPConfig core has **no one-time-login API**. Verified across the 3.3.1p1 tree:

- `client_login_get()` only *validates* a username/password pair.
- `login($user, $pass, true)` mints a **remote API session** (a row in `remote_session`), not a
  panel session — the panel's own login is `$_SESSION['s']['user']`, written by
  `interface/web/login/index.php`.

So every WHMCS/ISPConfig module that ever shipped a "log in to panel" button had to do it the only
way core allowed: store the customer's panel password and replay it into a login form. That is why
those modules leaked passwords into HTML, and it is the specific thing this module exists to make
unnecessary.

## 2. How it attaches without touching core

ISPConfig's remoting handler auto-loads extra remote API classes from modules
(`interface/lib/classes/remoting_handler_base.inc.php:47`):

```php
$this->load_remoting_classes(realpath(__DIR__) . '/remote.d/*.inc.php');                    // core
$this->load_remoting_classes(realpath(__DIR__) . '/../../web/*/lib/classes/remote.d/*.inc.php'); // modules
```

Each match is `include_once`'d, the class `remoting_<filename>` is instantiated, and **every public
method** of it joins the handler's method map. That is the whole integration: dropping
`interface/web/connectsso/` into the panel registers `connect_sso_token_create`, and deleting the
directory unregisters it. Nothing is patched, nothing is hooked, and an ISPConfig upgrade cannot
overwrite a change we did not make.

Two consequences of that mechanism, both deliberate here:

- `remoting_connectsso` extends `remoting`, so it inherits `checkPerm()` and `getSession()` — the
  same permission and session code every built-in remote function uses, rather than a second
  hand-written copy that could drift from it. Inheriting also means `get_class_methods()` sees the
  public methods of `remoting` itself; the handler re-pins `login`, `logout` and
  `get_function_list` to the core instance immediately afterwards (lines 50–52), while
  `server_get`, `server_get_all` and `__construct` end up dispatched to this class. They are
  inherited verbatim, so behaviour is unchanged — and this already happens between the built-in
  classes on a stock install, where `remoting_sites` (loaded last) owns them today. This class
  therefore declares exactly **one** public method of its own, and `tests/run.php` asserts that it
  takes over nothing else.
- Because the function is registered in the method map, `get_function_list` reports it. That is how
  a client detects the companion: one call, and because that call returns the handler's **whole**
  method map rather than the caller's own grants (`remoting.inc.php:685-692` returns `$this->_methods`),
  "not installed" stays distinguishable from "installed but not granted".
  `get_function_list` is **not** an ungated call — it is permission-checked like any other
  (`checkPerm($session_id, 'get_function_list')`, `remoting.inc.php:687`). The grant lives in the
  panel's **Server functions** checkbox (`interface/web/admin/lib/remote.conf.php:3`), which a
  billing system already needs for `server_get`; only `login` and `logout` are ungated. A remote user
  without it gets `permission_denied` from the probe, which a client must read as *SSO unavailable*,
  not as *companion absent*.

## 3. Install

```sh
./install.sh                       # /usr/local/ispconfig
./install.sh /path/to/ispconfig    # elsewhere
./install.sh --symlink             # development: serve straight from the clone
```

It copies `interface/web/connectsso/` into the panel, creates the module's two tables, and adds
`connectsso` to each admin's `sys_user.modules`.

That last step is what makes the permission checkbox appear: System → Remote Users builds its
Functions list by including `<module>/lib/remote.conf.php` for the modules named in the **logged-in
admin's** module column (`interface/web/admin/form/remote_user.tform.php:36-45`). Without it the
checkbox never renders — and saving that form would then *drop* the grant, because a `CHECKBOXARRAY`
field is rebuilt from the checkboxes that were posted (`tform_base.inc.php:828-833`, the `VARCHAR`
case of `_encode()`: an array is `implode()`d, and nothing posted stores `''`).

It does **not** put anything in the panel navigation: `nav.php` renders only modules that ship
`lib/module.conf.php`, and this one deliberately ships none.

**Then grant it**, which the installer will not do for you:
System → Remote Users → your API user → Functions →
tick *ISPConfig Connect: panel single sign-on (SSO token)* → Save.
(If the box is missing, log out and back in — the list is built from the logged-in admin's modules.)

The same user also needs **Server functions**, because that is the checkbox carrying
`get_function_list` — how a client discovers that this module is installed at all.

**And confirm the panel is HTTPS.** Both halves refuse on a plaintext request; see the box at the
top of this file, including what to set when a reverse proxy terminates TLS for you.

Uninstall reverses all three steps:

```sh
./uninstall.sh              # also drops the tables (they hold nothing but live tokens)
./uninstall.sh --keep-data
```

### Known caveat

Saving an admin's CP-user record rewrites `sys_user.modules` from the checkbox list on that form,
which is built only from directories that have `module.conf.php`
(`interface/web/admin/form/users.tform.php:90`) — so it silently drops `connectsso`. If the SSO
checkbox disappears from the Remote Users form, re-run `./install.sh`, or just:

```sh
php bin/connectsso_module.php --assign /usr/local/ispconfig/interface/lib/config.inc.php
```

Token minting and redemption are unaffected by this; only the checkbox is.

## 4. The API

```
connect_sso_token_create(session_id, client_id, bind_ip = '')
```

| Parameter   | Meaning |
|---|---|
| `session_id` | ordinary remote API session from `login()` |
| `client_id`  | `client.client_id` to log in as |
| `bind_ip`    | optional. The address the token may be redeemed **from** — the *end user's browser*, not the API caller's. `''` leaves it unbound. |

Returns:

```json
{
  "token": "<64 lowercase hex chars>",
  "path": "/connectsso/",
  "expires_in": 45,
  "client_id": 12,
  "username": "acmeltd"
}
```

The caller redirects the browser to `<panel base URL>` + `path` + `?token=` + `token`.
`path` is reported rather than assumed so the endpoint can move without a client release.

Faults (all flattened to `remote_fault` by the JSON handler, message preserved). Every one of them
is written to `sys_log` and `auth.log` first, with the real reason; the caller only ever sees the
message in this table:

| Message | Cause |
|---|---|
| `You do not have the permissions to access this function.` | grant not ticked, **or** the session is a client login (`login(u, p, true)`) rather than a remote user's — client sessions can never mint (core wording for both, on purpose) |
| `Single sign-on requires an encrypted (https) connection to the panel.` | the API request did not arrive over TLS and no reverse proxy is declared — see the box at the top |
| `The client id must be an integer greater than zero.` | bad `client_id` |
| `The bind IP address is invalid.` | `bind_ip` is not a literal IP |
| `There is no panel user for this client id.` | no `sys_user` row with that `client_id` |
| `Single sign-on is not available for administrator accounts.` | target is an admin |
| `The panel user for this client is not active.` | `sys_user.active != 1` |
| `The client account is locked; single sign-on is refused.` | `client.locked = 'y'` or `client.canceled = 'y'` |

## 5. Security model

This is an **authentication bypass primitive by design**: a token is a password substitute. The
design assumes it will leak eventually and makes a leaked token worth as little as possible.

**Who may mint**
- Only a **remote user's** API session. Core's `checkPerm()` is *not* sufficient authorisation
  here: it returns true unconditionally for any *client login* session
  (`remoting.inc.php:542-547`, "permissions are checked at an other place"), and any customer
  whose client record has `can_use_api = 'y'` can open one with `login(user, pass, true)`
  (`remoting.inc.php:99-156`). The mint function therefore re-reads the session and refuses
  `client_login` sessions outright — otherwise one API-enabled customer could mint a panel login
  for **any other** non-admin client on the panel. The refusal reuses core's permission wording
  and is written to both audit logs.
- Only over TLS, or on a panel whose operator has declared a reverse proxy in
  `security_settings.ini`. See the box at the top of this file.

**Who may be minted FOR — the exact boundary**

The only account class the module refuses is `sys_user.typ = 'admin'`, checked at mint **and** again
at redemption. Everything else on the panel is mintable, and that is a wider set than "customers":

- **Resellers are mintable.** ISPConfig does not give a reseller its own user type — every client the
  API creates gets `$type = 'user'` (`remoting_lib.inc.php:329`), and `limit_client > 0` merely adds
  the `client` module to that user's module list (`:311-313`). A token for a reseller therefore opens
  a panel session that can create, edit, suspend and delete **that reseller's sub-clients** and change
  their passwords. If you sell reseller plans through your billing system this is the feature working
  as intended; if you did not realise resellers were in scope, this is the sentence to re-read.
- A client whose `sys_user` row is shared with other services, or hand-created in the panel, is
  mintable too — the mint targets `client_id`, and takes the lowest `sys_user.userid` carrying it.
- Admin accounts are refused in both halves. The redemption-side check is not redundant: it means a
  hand-inserted row in `connectsso_token` still cannot become an admin login.

So the blast radius of the grant is **every non-administrator panel account, resellers included** —
not "customers". Size the grant accordingly (see *A leaked API grant is a leaked panel*, below).

**The token itself**
- 32 bytes from `random_bytes()` (256 bits), hex-encoded. Not `uniqid`, not `mt_rand`, not a hash of
  anything predictable.
- Stored **only as SHA-256**. A read of `connectsso_token` — a database dump, a backup, a SQL
  injection elsewhere in the panel — yields no usable login.
- Returned to the API caller once and never logged. The module writes an audit line naming the
  client and the panel user; it never writes the token or its hash.

**Lifetime and use count**
- **45 seconds** (`CONNECTSSO_TOKEN_TTL`, hard limit 60). It covers one HTTP redirect, nothing more.
- **Single use.** The guard is a conditional `UPDATE … WHERE used_at IS NULL` whose affected-row
  count decides the winner — atomic in the database, so two simultaneous redemptions cannot both
  succeed. The `used_at` check before it is a fast path, not the lock. The endpoint also verifies
  the `UPDATE` actually *executed* before trusting the count: ISPConfig's `affectedRows()` reads
  `mysqli_affected_rows()` off the connection, which still reports the previous statement when a
  query is rejected before reaching the server (`db_mysql.inc.php:507-512`), so a failed burn
  refuses rather than proceeds.
- **One live token per client.** Minting deletes any unredeemed predecessor, which caps how many
  redeemable tokens can accumulate and means an abandoned SSO click cannot be redeemed later from a
  browser history entry.
- Redeemed rows are kept for an hour so a replay is *recognisably* a replay and gets audited as one,
  then garbage-collected — on the next mint, and at the start of the next redemption. There is no
  cron job to install.

**Binding**
- Always bound to one `client_id`/`sys_userid`.
- Optionally bound to a source IP. Pass the end user's address as your billing system sees it. The
  comparison is on the binary form (`inet_pton` + `hash_equals`), so equivalent spellings of the
  same address match and nothing else does.
- Binding is checked **before** the token is burned, on purpose: someone who scraped a token from a
  log should not be able to invalidate the real customer's link just by opening it from the wrong
  address.
- Do **not** bind when the panel and the billing system disagree about the customer's address —
  a CDN or reverse proxy in front of either, or an IPv4/IPv6 asymmetry, and every login fails.
  Unbound is the safe default; bound is stronger where the topology allows it.

**Redemption endpoint**
- **TLS first.** A request that did not arrive over TLS is refused before the token is looked at,
  before the rate limiter, before anything — see the box at the top. It costs no rate-limit budget:
  it is a server-configuration refusal, not a guess at somebody's token.
- Rate-limited by source address before the token is looked at: 10 failures per 5 minutes, in the
  module's own `connectsso_attempt` table. That is the budget ISPConfig's **remote API** applies to
  `attempts_login` (`remoting.inc.php:94` at login, `:578` on every session lookup), and this
  endpoint matches it. Note that the panel's own **login form** is stricter and different —
  more than 5 failures within 1 minute (`login/index.php:61-68`) — so "same as ISPConfig" is not one
  number; this endpoint deliberately follows the API's. As in core, a request that arrives already at
  the limit is refused *without* being counted, so hammering does not extend the block.
- **What counts, exactly.** A failure counts when it was reached by **presenting a token value that
  did not check out**: malformed, unknown, expired, replayed, IP-binding mismatch, or a burn that did
  not take. Those are the guessing paths, and they are the ones the budget bounds.

  Nothing else counts, and the two kinds of "nothing else" are worth naming:
  - **Before the token is read at all** — the TLS refusal, and a request that arrives already at the
    limit. Neither says anything about a token.
  - **After the token checked out and was burned** — maintenance mode, a panel user that vanished or
    went inactive, a client suspended in the seconds since minting, a session that could not be
    started or regenerated. Each of these costs a genuine, already-spent token to reach, so they are
    useless for guessing.

  Counting either kind would let one server-configuration mistake, one maintenance window or one
  suspended customer exhaust the shared budget of every visitor behind the same address — which,
  behind a proxy that does not set `REMOTE_ADDR`, is everyone. All of them are still audited; the
  rate limiter is a guessing bound, not the audit trail.
- Rejections are indistinguishable to the visitor: every token-related refusal — malformed, unknown,
  expired, replayed, IP-mismatched, failed burn — produces the identical 403 page. The specific
  reason goes to the audit log only, so the page is never an oracle about someone else's token. The
  two refusals that carry a different status say nothing about a token either: 429 for the rate limit
  and 503 for maintenance mode, both properties of the server rather than of the link.
- **Session fixation:** `session_regenerate_id(true)` before anything is written to the session, and
  a failure to regenerate is fatal. ISPConfig's own login makes this optional — `session_regenerate_id`
  in the `[permissions]` section of `/usr/local/ispconfig/security/security_settings.ini`, a
  server-side file, not a panel form (`login/index.php:96-102`, because of core issue #3827). Here it
  is mandatory, because a token-based login with an attacker-chosen session id is worse than no SSO
  at all.
- **Open redirect:** the destination is a fixed `/index.php`. The request cannot influence where it
  lands. The WHMCS-side client independently refuses any endpoint path from the panel that is not a
  plain local path, so neither half will build an off-site redirect.
- **Two-factor authentication is preserved.** If the target user has `otp_type != 'none'`, the
  session is parked in `$_SESSION['s_pending']` and the visitor is handed to the panel's own
  `/login/otp.php`, exactly as `login/index.php` does. An SSO link replaces the password, not the
  second factor. A forced password change (`force_password_change_days`) is honoured the same way.
- **State is re-checked at redemption**, not just at minting: the panel user must still exist, still
  be active, still not be an admin, and the client must still not be locked. Seconds are enough for
  a suspension to land.
- **Maintenance mode is honoured.** The panel's own login form refuses every non-admin login while
  maintenance mode is on (`login/index.php:79`, honouring `maintenance_mode_exclude_ips`); the
  redemption endpoint applies the same gate, so an SSO link cannot walk a customer past a closed
  panel. The refusal is a 503.
- The rate-limit counter is a single atomic `INSERT … ON DUPLICATE KEY UPDATE` on the `ip_hash`
  primary key, so parallel rejected redemptions cannot race the counter and be under-counted.
- The PHP files under `lib/` sit inside the panel's document root. Both stock vhost templates serve
  `interface/web/`; the Apache one denies only `*.lng`
  (`install/tpl/apache_ispconfig.vhost.master`, `<FilesMatch "\.lng$">`), and the nginx one denies
  `*.lng` and dotfiles (`install/tpl/nginx_ispconfig.vhost.master`, `location ~ /\.`) — neither
  denies a `.php` under `lib/`. So each such file carries a direct-request guard:
  requested over HTTP without the panel bootstrap (`ISPC_ROOT_PATH`), it answers 404 and stops —
  no fatal, no path disclosure. An `.htaccess` would not be reliable here (`AllowOverride None`
  in the mpm_itk vhost; nginx ignores it), which is why the guard is in the files themselves.
- `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`,
  `X-Robots-Tag: noindex` on every response.

**What is deliberately NOT claimed**
- **The token appears in the panel's access log.** A browser redirect is a GET, so the token is in
  the URL. That is inherent to the pattern (cPanel and Plesk one-time-login URLs have the same
  property) and is why the token is single-use, expires in under a minute, and is stored hashed.
  The endpoint also accepts the token by **POST**, so a caller able to render an auto-submitting
  form can keep it out of access logs and browser history entirely.
- **The TLS gate is a check on the connection PHP sees, not a proof of end-to-end encryption.** It
  refuses the case that is unambiguously wrong (a plaintext request to the panel itself). It cannot
  see the hop between a browser and a reverse proxy, or between the proxy and the panel; where the
  operator declared `reverse_proxy_panel_allowed`, both of those are the operator's to get right. It
  also says nothing about the *billing system's* side of the call — that end must verify the panel's
  certificate itself, which is the connector's `Access Hash` / `insecure:` setting.
- **`REMOTE_ADDR` only.** `X-Forwarded-For` and friends are client-supplied and are never consulted.
  Behind a reverse proxy, make the proxy set `REMOTE_ADDR` (`mod_remoteip`, nginx `realip`), or
  leave IP binding off; otherwise the rate limiter buckets every visitor together and binding
  compares against the proxy.
- **Login CSRF.** As with any SSO link, someone who can make a victim's browser open *their* token
  lands that victim in *their* panel account. The session replacement makes this loud rather than
  silent, and IP binding blocks it where topology allows, but it is not fully preventable in a
  redirect-based flow.
- **A leaked API grant is a leaked panel.** A remote user holding `connect_sso_token_create` can
  mint a login for **every non-administrator account on the panel, resellers included** — see
  *Who may be minted FOR* above. Grant it only to the API user your billing system uses, and keep
  that user's Remote Access IP list populated. Note what that list actually is: ISPConfig resolves
  any hostname in it with `dns_get_record()` **on every login**
  (`remoting.inc.php:173-189`), with no caching and no failure handling — a hostname entry makes your
  DNS an authorisation input, and a resolver failure silently drops the entry and locks the caller
  out. Prefer literal addresses.

## 6. What it writes

Two tables, both module-owned, neither referenced by anything in ISPConfig:

| Table | Contents |
|---|---|
| `connectsso_token` | SHA-256 of live/just-used tokens, target user, client, optional bind IP, timestamps |
| `connectsso_attempt` | `md5(REMOTE_ADDR)` → failed-redemption counter for the 5-minute window |

Neither survives uninstall by default, and losing them costs nothing: everything in them expires
within a minute (tokens) or five (rate-limit buckets).

Audit lines go to `sys_log` (Monitor → System Log) for every mint, every refusal in **either** half —
including a call from a remote user that does not hold the grant — and every successful sign-in.
Core will not cover that first case for you: `checkPerm()`'s own `REMOTE-LIB DENY` line is written at
`LOGLEVEL_WARN` (`remoting.inc.php:553`), which the priority rule below discards on a shipped
install, so this module writes its own.

They are written at **priority 2 (`LOGLEVEL_ERROR`) on purpose**: `app.inc.php` `log()` silently
drops anything below `$conf['log_priority']`, and the installer sets that to 2 on every shipped
distribution (`install/tpl/config.inc.php.master:145`, `install/dist/conf/*.conf.php:39`) — an
audit line at debug or warning priority would be recorded nowhere. Token **issuance**, every
**refusal** and every **sign-in** additionally go to the panel's `/var/log/ispconfig/auth.log`,
alongside ISPConfig's own login records, so the trail survives even a panel whose `sys_log` is
pruned. None of these lines contain a token or a token hash.

## 7. Layout

```
interface/web/connectsso/
├── index.php                                  the redemption endpoint
└── lib/
    ├── connectsso_lib.inc.php                 constants + helpers shared by both halves
    ├── remote.conf.php                        makes the grant a checkbox in the panel UI
    └── classes/remote.d/connectsso.inc.php    class remoting_connectsso — the mint function
bin/connectsso_schema.php                      create / drop / check the two tables
bin/connectsso_module.php                      add / remove 'connectsso' in sys_user.modules
sql/install.sql, sql/uninstall.sql             the schema itself
tests/run.php                                  standalone harness (no panel, no database)
install.sh, uninstall.sh
```

The PHP under `interface/` is written in ISPConfig's own PHP-5-compatible style (no namespaces, no
`strict_types`, no typed properties) so it loads on every panel its host supports. The scripts under
`bin/` are standalone CLI tools and only need a PHP with `mysqli`.

## 8. Verifying it

```sh
php tests/run.php
```

A standalone harness — no panel, no database, no test framework. It replays core's remote.d
auto-loader against this module (so a rename or an accidental second public method fails loudly),
and drives the mint function through every refusal path and the happy path against a stand-in for
`$app`, asserting among other things that:

- the clear token appears in **no** stored column and in **no** log line;
- the TLS gate refuses plaintext and steps aside only for a declared reverse proxy;
- **every** refusal writes an audit line to both `sys_log` and `auth.log` and mints nothing — that
  one is table-driven, so a refusal added to the function without being added to the table fails the
  count assertion rather than slipping through unaudited;
- every SQL statement in the module survives core's `securityScan()`.

What it cannot cover is the redemption endpoint, which is a top-level script that redirects and
exits. That half is verified by hand on a real panel; the harness prints the checklist when it
finishes (replay, expiry, IP binding, rate limit, TLS gate, 2FA hand-off, session-id rotation, and
the install/uninstall round trip).

## 9. Compatibility

Developed and verified against **ISPConfig 3.3.1p1**, whose source is cited inline throughout — that
is the only version any claim here has been checked against. The extension point it depends on is
`interface/lib/classes/remoting_handler_base.inc.php`; `install.sh` checks that the file exists and
that it still globs `web/*/lib/classes/remote.d`, and refuses (or warns) where it does not, rather
than installing something that would silently never register. Everything the module reads from core —
`sys_user`, `client.locked` / `client.canceled`, the session keys written by `login/index.php`, the
OTP hand-off — is long-standing, but nothing here is upstream API: **re-verify after a major
ISPConfig upgrade**, and treat this README's line-number citations as version-specific.
