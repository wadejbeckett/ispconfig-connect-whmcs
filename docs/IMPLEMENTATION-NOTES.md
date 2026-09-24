# Implementation Notes — ISPConfig Connect for WHMCS

Working spec for the build, and the reference for anyone changing the module. A sibling to the scope
& design document in this repository,
[`docs/superpowers/specs/2026-08-05-whmcs-ispconfig-connector-design.md`](superpowers/specs/2026-08-05-whmcs-ispconfig-connector-design.md).

Everything in §1 below was re-verified on 2026-08-24 directly against the ISPConfig **3.3.1p1**
release tarball. To re-verify it — or to answer anything this file does not — download the matching
ISPConfig release from ispconfig.org, extract it, and read the cited files: every citation here is a
path plus a line range inside that tree. **Line numbers are version-specific.** Re-check them against
the version you are actually running before relying on one.

## 1. Verified wire contract (ISPConfig 3.3.1p1 source)

### 1.1 Request

- `POST <scheme>://<host>:<port>/remote/json.php?<method>` — the method name is the **first key of the
  query string** (`json_handler.inc.php`: `$method = reset(array_keys($_GET))`).
- Body: JSON object; keys are the remote function's **PHP parameter names** (reflection-matched,
  order-independent). Missing parameters arrive as `null`.
- `session_id` is an ordinary named parameter (`{"session_id": "..."}`), required by every function
  except `login`.

### 1.2 Response envelope

`{"code": <string>, "message": <string>, "response": <mixed>}` with `Content-Type: application/json`.

- `code == "ok"` → result is in `response`.
- `code == "remote_fault"` → **every** server-side SoapFault is flattened to this one code; only the
  fault *message* survives. The original fault code is never transmitted.
- `code ∈ {invalid_method, invalid_data, invalid_class}` → JSON-handler-level protocol errors.
- API disabled → **plain text, non-JSON** body: `Remote API is disabled in security settings.` (exact,
  from `json.php` `die()`).
- Demo mode → HTML error page (`$app->error(...)`), also non-JSON.

### 1.3 Fault message sentinels (hard-coded English in `remoting.inc.php`, stable, not localized)

| Message (prefix match) | Meaning | Typed exception |
|---|---|---|
| `The login failure limit has been reached.` | 10 failed logins / 5 min / IP | `LockoutException` |
| `The Session failure limit has been reached.` | same counter, tripped via session checks | `LockoutException` |
| `The Session is expired or does not exist.` | dead/unknown session id | `SessionExpiredException` |
| `Session IP mismatch.` | session bound to another source IP | `SessionIpMismatchException` |
| `The login is not allowed from ` | remote user's IP allowlist | `IpNotAllowedException` |
| `The login failed. Username or password wrong.` | bad credentials | `LoginFailedException` |
| `The login failed. User is blocked.` / `...Client may not use api.` | account state | `LoginFailedException` |
| `The login username is empty.` / `The login password is empty.` | empty credential | `LoginFailedException` |
| `You do not have the permissions to access this function.` | missing `remote_functions` grant | `PermissionDeniedException` |
| `This ISPConfig installation is currently under maintenance` | maintenance mode (login-time) | `MaintenanceModeException` |

Anything else stays a plain `RemoteFaultException` — notably `data_processing_error` validation text,
which is localized, HTML-joined (`<br />` separators), and MUST NOT be string-matched. Also seen:
`database_error` (message + SQL), `Duplicate username<br />` from `client_add`.

The full taxonomy is already implemented in `lib/Api/Exception/` — use it, don't extend it without need.

### 1.4 Session & lockout semantics (verified `remoting.inc.php`)

- Session TTL **1800 s, fixed at login, never extended by use** (tstamp written once).
- Sessions are **IP-bound** (`session_ip_mismatch` otherwise).
- The failure counter (per source-IP, 5-minute window, limit 10): failed logins **and** failed session
  lookups (expired/unknown ids) both increment it. A successful login **clears** it.
- Expired remote sessions are garbage-collected at each login.
- `logout(session_id)` exists and returns bool — call it when a batch ends.
- Session id format: 1 lowercase letter + 40 hex chars (must start with a letter).

**Mandated client behavior:** login once per WHMCS operation (entry-point call), reuse the session for
the whole batch, proactively re-login if the session is older than **25 minutes**, retry **once** on
`SessionExpiredException` with a fresh login, **never** call again after `LockoutException` (circuit
stays open for the rest of the request), logout in a `finally`-equivalent path (best-effort, swallow
transport errors on logout only).

### 1.5 Function signatures the module calls (all verified in `remote.d/*.inc.php`)

```
login($username, $password, $client_login = false) -> session_id string
logout($session_id) -> bool
get_function_list($session_id) -> list of ALL registered method names (NOT the user's grants!)
monitor_jobqueue_count($session_id, $server_id = 0) -> int
server_get_all($session_id) -> [{server_id, server_name}, ...]        [grant name: server_get]
client_get($session_id, $client_id) -> record
client_get_by_username($session_id, $username) -> record
client_add($session_id, $reseller_id, $params) -> client_id int
client_update($session_id, $client_id, $reseller_id, $params) -> affected rows   // ARG ORDER!
client_change_password($session_id, $client_id, $new_password) -> bool
client_delete_everything($session_id, $client_id) -> bool
client_templates_get_all($session_id) -> [{template_id, template_name, template_type('m'|'a'), ...limits}]
client_template_additional_get($session_id, $client_id) -> [{assigned_template_id, client_id, client_template_id}]   [grant: client_get]
client_template_additional_add($session_id, $client_id, $template_id)
client_template_additional_delete($session_id, $client_id, $assigned_template_id)
sites_ftp_user_update($session_id, $client_id, $primary_id, $params)        // plain updateQuery - partial update safe
sites_database_user_update($session_id, $client_id, $primary_id, $params)  // plain updateQuery - partial update safe
sites_web_domain_add($session_id, $client_id, $params, $readonly = false) -> domain_id
sites_web_domain_get($session_id, $primary_id) -> record (incl. system_user, system_group, document_root)
sites_web_domain_set_status($session_id, $primary_id, $status)   // 'active' | 'inactive'
sites_ftp_user_add($session_id, $client_id, $params) -> ftp_user_id
sites_database_user_add($session_id, $client_id, $params) -> database_user_id
sites_database_add($session_id, $client_id, $params) -> database_id
mail_domain_add($session_id, $client_id, $params) -> mail_domain_id
dns_templatezone_add($session_id, $client_id, $template_id, $domain, $ip, $ns1, $ns2, $email, $ipv6='') -> bool
dns_zone_get_id($session_id, $origin) -> zone_id      // follow-up because templatezone_add returns bool
quota_get_by_user($session_id, $client_id)            [grant name: sites_web_domain_get]... verify in source
trafficquota_get_by_user($session_id, $client_id, $lastdays = 0)
```

**`client_update` partial updates are NOT safe as-is** (found by adversarial review, verified in
source — `remote.d/client.inc.php`): client_update pre-merges the stored DB record into `$params`
*before* `updateQuery`, which defeats `updateQueryPrepare`'s empty-PASSWORD protection and always
populates `template_additional`. Consequences and mandatory mitigations:

1. **Password re-crypt corruption**: the stored crypt hash lands in `params['password']` and is
   re-crypted by `getSQL` (remoting_lib.inc.php:339) and `ispconfig_sysuser_update` (:367) —
   breaking the panel login on every partial update. Mitigation: **every `client_update` must send
   `'_ispconfig_pw_crypted' => 1`** so the merged hash passes through verbatim.
2. **Addon-template wipe**: merged `template_additional` (usually `''`) triggers
   `update_client_templates()` (client_templates.inc.php), which resyncs `client_template_assigned`
   against the given list — `''` deletes every assignment. Semantics: bare ids (`"5/7"`) =
   count-diff per template id (passing the currently-assigned ids = no-op; passing desired ids =
   atomic delta); `"assignedId:templateId"` pairs = keep/delete by assignment id. Mitigation:
   **every `client_update` must send `template_additional` = '/'-joined bare ids of the
   currently-desired assignments**, read via `client_template_additional_get`.
3. **Lock cascade + tmp_data poisoning**: `locked='y'` runs `func_client_lock`
   (functions.inc.php): disables ALL client objects (web, mail, FTP, DB, cron, shell …),
   remembering previously-inactive ones in `client.tmp_data`; `locked='n'` re-enables everything
   except the remembered set. Locking an already-locked client overwrites `tmp_data` with
   everything-inactive, so unlock then restores nothing. Mitigation: **read `client_get` first and
   skip the lock/unlock when the state is already correct**; "suspend disables website only" is
   impossible via `locked` — a website-only mode must skip `client_update` entirely and use
   `sites_web_domain_set_status`.

Grant aliases (verified via checkPerm() in source, encoded in `Api\Permissions`): `server_get_all`
→ grant `server_get`; `client_template_additional_add`/`_delete` → grant `client_update`;
`client_delete_everything` additionally re-checks grant `client_delete` mid-cascade — both required.
All other called functions check their own name.

**`get_function_list` returns the full registered method list, not per-user grants** — a granted-vs-not
check can only be made by calling a function and catching `PermissionDeniedException`. TestConnection
therefore login-checks + read-probes only (`server_get_all`, `client_templates_get_all`,
`monitor_jobqueue_count`); the full required grant list ships as documentation.

When exact `$params` field requirements matter, read the form definitions in the ISPConfig source
(`interface/web/<module>/form/*.tform.php`) rather than guessing. Validation failures surface as
`data_processing_error` remote faults.

### 1.6 Known pitfalls (design §4, all source-verified)

- `client_add`: **always pass `usertheme` explicitly** (`'default'`) — neutralizes the 3.3.1
  `usertheme_syntax_incorrect` bug (#6990).
- `dns_templatezone_add` returns bool → resolve the id with `dns_zone_get_id(origin)`. **Call it
  WITHOUT a trailing dot**: `dns_zone_get_id` rejects any origin ending in `.` with `Invalid domain
  name.` (regex in `remote.d/dns.inc.php`), while the dotless origin matches zones stored either way.
- `sites_database_add` requires a **pre-created database user id** and per-server-unique names.
- `mail_user_add` requires the mail domain to exist first (Phase 4 concern).
- All mutations are **asynchronous** (datalog → per-server cron). API "ok" = accepted, not provisioned.
- `_ispconfig_pw_crypted=1` passes pre-hashed passwords (import tooling, later).

## 2. Component specs

Namespace root `WHMCS\Module\Server\Ispconfigconnect` (autoloaded by WHMCS from
`modules/servers/ispconfigconnect/lib/`; mirrored in composer autoload for tests).
PHP ≥ 8.2, `declare(strict_types=1)` everywhere, PSR-12, no runtime dependencies outside PHP + ext-curl.
Never `echo`/`print` from lib code. No WHMCS function calls from lib code (only the entry file and
Support\Logging touch WHMCS APIs) — lib must stay extractable into ispconfig-connect-core.

### 2.1 `Api\CurlTransport implements HttpTransportInterface`

- `__construct(ServerConfig $config)`.
- `post(string $method, string $jsonBody): string` via ext-curl: POST, body as-is,
  `Content-Type: application/json`, encode the method name into the query string as the sole key
  (`?` . rawurlencode($method) — no `=`), `CURLOPT_SSL_VERIFYPEER/VERIFYHOST` **on** unless
  `verifyTls=false`; `caBundle`: if the string contains `-----BEGIN` treat as literal PEM (write to a
  temp file, cleanup), else treat as a path (`CURLOPT_CAINFO`). Timeouts from config (connect = min(10,
  timeout)). Fail (`TransportException`) on curl error or HTTP status ≥ 400; include curl error and
  status in the message but NEVER the request body (it contains credentials).
- `protected function buildOptions(...): array` (or equivalent seam) so tests can assert the exact curl
  option set without a network — unit tests must cover: verify-on default, verify-off opt-out, CA path,
  literal PEM, method-in-query-key encoding.

### 2.2 `Api\RestClient`

- `__construct(HttpTransportInterface $transport, ?LoggerCallback $logger = null)` — logger is
  `callable(string $action, array $request, mixed $response): void`; called for **every** API call,
  success or failure (failure: response = exception message). The callback receives the **request**
  with the password/session values INTACT — masking is the wiring's job (Support\Logging), because
  only the wiring knows WHMCS's replaceVars convention. RestClient passes the values it knows are
  sensitive (password, passwort, session_id, remote_password, …) to the callback separately:
  signature `callable(string $action, array $request, mixed $response, list<string> $sensitiveValues)`.
- **Two mechanisms, not one list** (see the class docblock for the full argument). Masking by value is
  right for credentials and key material: long, unique, high-entropy strings, where WHMCS's
  str_replace masking is exact and the visible-but-masked key is itself a useful diagnostic. It is
  wrong for personal and financial columns — a bank code or a country code is too short to be a safe
  needle, and masking still hands the raw value to `logModuleCall`. Those are **removed from the copy
  handed to the logger**, inside RestClient, before it leaves the class. The value returned to the
  caller is never touched.
- **The customer-record reads are projected, not filtered.** `client_get` (which with `client_id = -1`
  returns every client on the panel) and `client_get_by_username` (a bare `SELECT * FROM sys_user`)
  are logged through an ALLOWLIST of the columns the module actually reads, plus the masked ones, with
  a count of what was dropped. A blocklist rots the moment ISPConfig adds a column; an allowlist makes
  an unknown column private by default.
- **Key matching is exact on a normalized name** (lowercased, separators stripped) — never a substring
  test. Substring matching is what produced the original leak: the rule looked for `password` and the
  sys_user column is the German `passwort`; in the other direction it matched `lost_password_function`,
  a 1/0 flag whose value as a masking needle would have blanked every "1" in the entry.
- `call(string $method, array $params): mixed` — json_encode (JSON_UNESCAPED_SLASHES|UNESCAPED_UNICODE,
  throw on failure), transport->post, then decode:
  - body starts with `Remote API is disabled` → `ApiDisabledException`
  - not valid JSON / missing `code` → `MalformedResponseException` (truncate body to 300 chars in msg)
  - `code=ok` → return `response`
  - `code=remote_fault` → `throw RemoteFaultException::fromMessage(message)`
  - other codes → `ApiProtocolException(code, message)`
- `login(string $username, string $password): string` — `call('login', ...)`; reject non-string/empty
  response with `MalformedResponseException`.
- `logout(string $sessionId): bool`.

### 2.3 `Api\SessionManager`

- `__construct(RestClient $client, string $username, #[\SensitiveParameter] string $password, ?\Closure $clock = null)`
  — `$clock` returns int unix time (default `time()`); injectable for TTL tests.
- `call(string $method, array $params): mixed` — ensures a session (login on first use or when older
  than 1500 s), merges `['session_id' => $sid]` **under** the given params (caller never passes
  session_id), one retry on `SessionExpiredException` (fresh login), zero retries on anything else,
  permanent open-circuit after `LockoutException` (subsequent calls rethrow a stored LockoutException
  without any network I/O).
- `close(): void` — best-effort logout, swallow only transport-level errors, clear state. Safe to call
  twice or before any call.
- Never logs, never echoes; holds credentials in private props.

### 2.4 `Api\Permissions`

- `requiredGrants(): list<string>` — canonical `remote_functions` grant list for Phases 1–2. Grants are
  **grant names**, with the verified aliases collapsed: calling `server_get_all` requires grant
  `server_get`; verify every checkPerm() name in the extracted source for each function in §1.5 and
  encode the mapping. Include `get_function_list`, `login`/`logout` need no grant.
- `grantFor(string $function): string` — checkPerm name for a callable function.
- `readProbes(): array<string, array>` — the safe read-only calls TestConnection makes:
  `server_get_all` (no extra params), `client_templates_get_all`, `monitor_jobqueue_count`.
- Unit tests assert the alias mapping against a fixture extracted from source: grep `checkPerm` in
  the ISPConfig tree and hard-code the expected function → grant pairs in the test, so a change in
  either direction fails loudly.

### 2.5 `Support\Params`

Turns the raw WHMCS `$params` array into typed objects. WHMCS provisioning `$params` reference:
https://developers.whmcs.com/provisioning-modules/module-parameters/

- `serverConfig(array $params): ServerConfig` — `serverhostname` (fall back `serverip`), `serverport`
  (default 8080), `serversecure` truthy → secure, `serverusername`, `serverpassword`,
  `serveraccesshash` trim → empty = null; literal `insecure` sentinel is NOT a thing — TLS opt-out is
  the dedicated WHMCS "Disable TLS verification" checkbox we DON'T have, so: Access Hash starting with
  `insecure:` prefix disables verification, anything else is the CA bundle. Document this in INSTALL.
- `productConfig(array $params): ProductConfig` — reads `configoption1`..`configoption14` per the
  frozen option order in §3.1. Yes/no checkboxes arrive as `'on'`/`''`. Numeric template/server ids
  cast int, 0 = unset.
- `service(array $params): ServiceParams` — readonly VO: serviceId (`serviceid`), domain, username,
  password, clientDetails (name/company/email/address1/city/state/postcode/country/phone from
  `clientsdetails`), whmcsClientId (`userid`), serverIp (`serverip`), language (`clientsdetails.language`
  or ''), configurable options array (`configoptions`).
- `ServiceProperties` accessor wrapping `$params['model']->serviceProperties` (WHMCS
  `save()`/`get()` API): typed getters/setters for `ispconfig_client_id`, `ispconfig_client_username`,
  `ispconfig_domain_id`, `ispconfig_ftp_user_id`, `ispconfig_mail_domain_id`, `ispconfig_dns_zone_id`,
  `ispconfig_database_id`, `ispconfig_database_user_id`. Guard: when `model` is absent (unit tests),
  accept an injected plain-object double with the same interface.
- All VOs (`ProductConfig`, `ServiceParams`) are final readonly classes in `Support\`.

### 2.6 `Provision\Mapper`

Pure functions (static or instance, no I/O): WHMCS data → ISPConfig call params.

- `clientAddParams(ProductConfig, ServiceParams): array` — contact/address fields from client details,
  `username`/`password` from service, `language` (2-letter, default `en`), **`usertheme` => `'default'`
  ALWAYS**, `template_master` from ProductConfig, `template_additional` => `''`,
  `default_webserver`/`default_mailserver`/`default_dnsserver`/`default_dbserver` => web server id,
  `notes` => `Managed by WHMCS service #<id>`. Read `client.tform.php` in the extracted source for
  required-field validation before finalizing.
- `webDomainAddParams(ProductConfig, ServiceParams): array` — `server_id`, `ip_address` => `'*'`,
  `domain`, `type` => `'vhost'`, `parent_domain_id` => 0, `subdomain` => `'www'`, `php` from phpMode
  option, `hd_quota` => -1, `traffic_quota` => -1, `allow_override` default, `active` => `'y'`,
  `errordocs` => 1, **plus the four advanced-tab fields whose REGEX validators have no allowempty
  and therefore reject absent values over the remote API** (review-verified): `pm_process_idle_timeout`
  => 10, `pm_max_requests` => 0, `http_port` => 80, `https_port` => 443 (values from the stock
  `remoting_client/examples/sites_web_domain_add.php`).
- `ftpUserAddParams(...)` — needs the fetched web-domain record (uid/gid/dir from `system_user`,
  `system_group`, `document_root`); username = service username (append `_ftp` ONLY on collision
  policy? No — username = service username; ISPConfig prefixes don't apply over the API),
  `quota_size` => -1, `active` => `'y'`.
- `mailDomainAddParams(...)` — `server_id`, `domain`, `active` => `'y'` (+ whatever
  `mail_domain.tform.php` requires).
- `databaseNames(ServiceParams, int $clientId): {user: string, name: string}` — scheme:
  `'c' . clientId . '_' . <alnum(sld) truncated so the whole name ≤ 16 chars>`; both user and db name
  use it. Deterministic, collision-free per client (one DB per service in MVP).
- `dnsTemplateArgs(ProductConfig, ServiceParams): array` — template_id (dnsTemplateId option), domain,
  ip = ServiceParams::serverIp, ns1/ns2/email from options (email fallback: dns admin email option,
  else `admin@<domain>`).
- `changePackage` mapping: new template_master + recompute addon template ids from configurable
  options named `ISPConfig <Addon-Template-Name>` (design §3.4): resolve against
  `client_templates_get_all` (type 'a') by exact name match.

### 2.7 `Provision\Recipe`

`__construct(SessionManager $session)`. Orchestrates multi-call sequences. **All-or-nothing create**:

- `create(ProductConfig, ServiceParams): CreateResult` — sequence: `client_add` (reseller_id 0) →
  addon templates → optional web domain (`sites_web_domain_add` → `sites_web_domain_get` for
  system_user/group/docroot) → optional FTP user → optional mail domain → optional DNS zone
  (`dns_templatezone_add` → `dns_zone_get_id`) → optional DB user + DB. On ANY failure after
  `client_add` succeeded: best-effort `client_delete_everything(client_id)` rollback, then rethrow the
  original exception wrapped with context (`ProvisioningException` w/ stage name + original message +
  rollback outcome). `CreateResult` = readonly VO with all created ids + username.
- `suspend` — entire-client mode: `client_get` first (skip when already locked — tmp_data
  poisoning, §1.6), then protective `client_update` with `locked='y'`, `_ispconfig_pw_crypted=1`,
  and the current bare addon ids in `template_additional`; website-only mode: no `client_update`
  at all, just `sites_web_domain_set_status(domainId, 'inactive')`.
- `unsuspend` — symmetric inverse with the same guards.
- `terminate(int $clientId): void` — `client_delete_everything`.
- `changePassword(int $clientId, string $newPassword, ?int $ftpUserId, ?int $databaseUserId)` —
  `client_change_password` + `sites_ftp_user_update` / `sites_database_user_update` for the
  credentials the module itself provisioned with the service password.
- `changePackage(int $clientId, ProductConfig, list<int> $desiredAddonTemplateIds)` — ONE
  protective `client_update` carrying the new `template_master` AND `template_additional` =
  '/'-joined DESIRED bare addon template ids (+ `_ispconfig_pw_crypted=1`): the server-side
  `update_client_templates` resync applies the delta atomically (§1.5 hazard #2 turned into the
  mechanism). No separate `client_template_additional_add/delete` calls.
- `jobqueueCount(?int $serverId): int` — monitor_jobqueue_count.
- `ProvisioningException extends IspconfigException` lives in `Provision\` (carries `stage`,
  `rollbackSucceeded`).

### 2.8 Entry file `ispconfigconnect.php` + wiring

Follow the official sample module layout (MIT): https://github.com/WHMCS/sample-provisioning-module —
fetch and read it before writing. Every function `if (!defined('WHMCS')) die;` guarded, thin, delegates
to lib, catches ALL IspconfigException + Throwable → returns the message string (WHMCS convention:
return `'success'` or error string). Functions (MVP):

- `ispconfigconnect_MetaData()` — DisplayName `ISPConfig Connect`, APIVersion 1.1,
  RequiresServer true, DefaultSSLPort 8080, DefaultNonSSLPort 8080. Phase 2 added the three
  `ListAccounts*` keys and a **conditional** `ServiceSingleSignOnLabel` (§5.2, §5.5).
- `ispconfigconnect_ConfigOptions()` — §3.1 frozen order, with `Loader` functions for options 1/2
  (`ispconfigconnect_ClientTemplateLoader`, `ispconfigconnect_WebServerLoader` — each builds a
  SessionManager from $params, calls the API, returns `[id => name]`, and MUST logout; on exception
  rethrow — WHMCS displays loader errors inline). Loader-backed fields also need
  `'SimpleMode' => true` or WHMCS never invokes the loader (developers.whmcs.com loader-functions).
- `ispconfigconnect_TestConnection(array $params)` — login + the three read-probes + jobqueue count;
  returns `['success' => bool, 'error' => string]`; error strings must be actionable (which probe
  failed, which grant to add, IP to allowlist on IpNotAllowedException, backoff advice on Lockout).
- `ispconfigconnect_CreateAccount` — Recipe::create; store every id via ServiceProperties; set
  service username if empty. Existing non-empty `ispconfig_client_id` property → refuse with error
  (double-provision guard).
- `ispconfigconnect_SuspendAccount` / `_UnsuspendAccount` / `_TerminateAccount` (clears the stored
  properties afterwards) / `_ChangePassword` / `_ChangePackage`.
- `ispconfigconnect_AdminServicesTabFields(array $params): array` — read-only panel: client id,
  username, domain id, job-queue depth (live call, exception-safe → 'n/a'), panel link.
- `ispconfigconnect_AdminCustomButtonArray()` — `['Verify Permissions' => 'VerifyPermissions']`;
  `ispconfigconnect_VerifyPermissions($params)` runs the read-probes and reports missing grants.
- `ispconfigconnect_ClientArea(array $params)` — template `templates/overview.tpl`: panel URL link +
  ISPConfig username + friendly note; NEVER a password anywhere in HTML (design §3.6.3).
- Every API call logged via `Support\Logging::wire($moduleName)` → returns the RestClient logger
  callback that calls `logModuleCall('ispconfigconnect', $action, $request, $response, '', $sensitiveValues)`.

### 2.9 `tests/Support/FakeIspconfigServer implements HttpTransportInterface`

In-process behavioral double of `remote/json.php` + `remoting.inc.php`. This is the compatibility
contract in executable form — model it on the real ISPConfig code, not on this summary, including:

- envelope shapes (§1.2), fault messages VERBATIM (§1.3), plain-text disabled-API body, invalid_data
  for non-JSON body, invalid_method for unknown method;
- named-param mapping with nulls for missing params;
- session issue/TTL-1800-fixed/IP-binding is out of scope (single client IP), but: expiry via
  settable clock, expired/unknown session → `The Session is expired or does not exist.` AND failure
  counter increment; 10 failures in 5-min window → lockout messages; successful login clears counter;
  logout invalidates;
- per-user `remote_functions` grants (configurable) → permission_denied message for missing grant
  (checkPerm alias names, e.g. grant `server_get` covers `server_get_all`);
- handlers for every §1.5 function: id assignment, state in arrays, required-param validation
  (`data_processing_error` with an HTML-ish localized-looking message — tests must NOT match its text),
  `client_add` duplicate username → `Duplicate username<br />`, `client_delete_everything` cascades,
  `sites_web_domain_get` returns system_user `web<id>`, system_group `client<clientid>`,
  document_root `/var/www/clients/client<cid>/web<id>`, `client_update` merge semantics (empty
  PASSWORD fields ignored), `dns_templatezone_add` → bool + zone record retrievable via
  `dns_zone_get_id` (accept origin with and without trailing dot — mirror source behavior),
  `monitor_jobqueue_count` returns configurable int;
- test hooks: `$fake->clock += n`, `$fake->failNextWith(method, 'fault message')`,
  `$fake->apiEnabled = false`, `$fake->maintenanceMode = true`, `$fake->requestLog` (method + decoded
  params of every call), state inspection (`clients()`, `sites()`, ...).

Location: `tests/Support/FakeIspconfigServer.php` (+ helpers in `tests/Support/`), namespace
`Ispconfigconnect\Tests\Support`. It is a test asset — clarity over cleverness, but its behavioral
fidelity is load-bearing for the whole suite.

## 3. Frozen decisions for this build

### 3.1 ConfigOptions order (do not reorder once shipped)

| # | Name | Type | Default |
|---|---|---|---|
| 1 | Client Template | dropdown (Loader: master templates) | — |
| 2 | Web Server | dropdown (Loader: server_get_all) | — |
| 3 | Create Website | yesno | on |
| 4 | Create FTP User | yesno | on |
| 5 | Create Mail Domain | yesno | off |
| 6 | Create DNS Zone | yesno | off |
| 7 | DNS Template ID | text | 1 |
| 8 | NS1 | text | — |
| 9 | NS2 | text | — |
| 10 | DNS Admin Email | text | — |
| 11 | Create Database | yesno | off |
| 12 | PHP Mode | dropdown: php-fpm,fast-cgi,mod,suphp,cgi,no | php-fpm |
| 13 | Suspend Entire Client (unchecked: website only) | yesno | on |
| 14 | Panel URL Override | text | — |

### 3.2 Deferred out of MVP (tracked, not stubbed)

Keep-DNS-on-terminate toggle (Phase 3), hook-driven contact sync (Phase 3 — in the admin hook file,
which per §6.1 lives in `includes/hooks/`, not in the module), reseller products /
dedicated IP (Phase 3), client-area management views (Phase 4). Do NOT create placeholder functions
or empty files for these.

**Delivered in Phase 2** (see §5): ListAccounts / Server Sync, UsageUpdate, MetricProvider,
GetRemoteMetaData + the servers-page hook, and single sign-on against the optional `ispconfig-sso`
project — a standalone ISPConfig add-on this repository does not ship, detected at runtime.

### 3.3 Open questions to settle against the live server (not in unit tests)

1. Does `client_add` with `template_master` apply the template's limits server-side, or must the
   module copy limit fields from the template record? (Fallback ready: read template via
   `client_templates_get_all` and pass explicit `limit_*`.)
2. `remote/rest.php` characterization (design Phase 0 spike) — deferred until after MVP.
3. Exact required-field sets accepted by 3.2.x panels (trinity) vs 3.3.1p1 for
   `sites_web_domain_add` / `mail_domain_add`.

## 4. Quality bar

- TDD: write the test first, watch it fail, implement, watch it pass; every component's tests run green
  before its task is done. No `test.skip`, no `@todo`, no commented-out assertions.
- `php -l` clean on every file (PHP 8.2 syntax); PHPStan level 6 clean on the paths you touched.
- Security tests are first-class: TLS defaults, credential scrubbing (`ModuleCallLog::entriesLeaking`
  and the stricter `entriesLeakingAfterMasking`, which applies the masking rather than trusting the
  replaceVars list), no-password-in-HTML, lockout circuit, rollback on partial create.
- A leak test is only worth what its fixture emits. `FakeIspconfigServer` seeds the FULL rows the panel
  really returns — `client` with the bank block, tax id and postal record, `sys_user` with `passwort`,
  `otp_data`, `otp_recovery` and `lost_password_*` — because a regression test whose fixture never
  emits the column cannot fail. Before trusting a scrubbing assertion, prove the value was on the wire.
- Style: PSR-12, `declare(strict_types=1)`, final classes by default, readonly VOs, no abbreviations in
  public API names. Comments only for non-obvious constraints (wire facts, ISPConfig quirks) — cite
  the source file when stating a wire fact.

## 5. Phase 2 — the WHMCS-facing surface

This section is the permanent record of the **WHMCS-side contracts**, in the same spirit as §1 for the
ISPConfig wire. Where WHMCS documents a contract, the developers.whmcs.com URL is cited; where it does
not, the behaviour below was established on a live **WHMCS 9.0.6** install against ISPConfig 3.3.1p1
and is marked LIVE-VERIFIED. WHMCS core is ionCube-encoded, so LIVE-VERIFIED facts were obtained by
observing behaviour, never by decompiling.

### 5.1 `GetRemoteMetaData` — LIVE-VERIFIED, undocumented

WHMCS calls `<module>_GetRemoteMetaData(array $params)` while rendering **Configuration > System
Settings > Servers**. Established on the live install:

- `$params` keys: `whmcsVersion`, `server`, `serverid`, `serverip`, `serverhostname`,
  `serverusername`, `serverpassword`, `serveraccesshash`, `serversecure`, `serverhttpprefix`,
  `serverport`, `action`.
- The returned array is stored **verbatim as JSON** in `tblservers_remote` (columns: `server_id`,
  `num_accounts`, `meta_data`, `created_at`, `updated_at`).
- A **FLAT return is required**. A `['success' => true, 'metaData' => [...]]` wrapper is stored as
  those two literal keys — it is not unwrapped.
- The key **`service_count`** is rendered by core as *"Services: N"* in the row's Remote Usage Stats
  cell. That is the only key core renders itself.
- The `num_accounts` column was **not** written by any key tried (`num_accounts`, `numAccounts`,
  `ownedAccounts`, `accounts`) — see §6.3, which also records where that number does come from.
- The styled `<div class="remote-meta-data">` block that bundled modules render under the hostname is
  **not reachable from this function** — §6.2 has the elimination that settled it.
  `Server\MetaDataPanel` plus the admin hook (AdminAreaFooterOutput) build the equivalent block
  themselves.

§6 is the full, dated record of that page; this section stays limited to the function's own contract.

Module-side consequences: `ispconfigconnect_GetRemoteMetaData()` never throws (it runs mid-render),
always returns a flat scalar array, and on total failure still returns `checked_at`,
`ispconfig_status` and `ispconfig_error` so the row shows a reason rather than an empty cell.

It is also where the SSO presence probe result is cached (`sso_available`), because `MetaData()`
receives no server parameters and must not make API calls — see §5.5.

### 5.2 `ListAccounts` / Server Sync

developers.whmcs.com/provisioning-modules/server-sync/ — and the three MetaData keys are what makes
the **Sync Accounts** action appear at all (Configuration > System Settings > Servers):

```
'ListAccountsUniqueIdentifierDisplayName' => 'Domain'
'ListAccountsUniqueIdentifierField'       => 'domain'
'ListAccountsProductField'                => 'configoption1'
```

Return shape: `['success' => true, 'accounts' => [...]]`, or `['success' => false, 'error' => '...']`.
Each account row carries exactly `email, username, domain, uniqueIdentifier, product, primaryip,
created, status`, with `status` from `WHMCS\Service\Status::ACTIVE|SUSPENDED`.

Two mapping decisions worth keeping:

- `ListAccountsProductField` points at **configoption1**, the *Client Template* dropdown. WHMCS
  stores a Loader dropdown's array KEY, so that option holds the ISPConfig **template id** — which is
  what `RemoteAccount::product` emits, making the match id-to-id rather than label-to-label.
- The identifier field **must be `domain`** (LIVE-VERIFIED, WHMCS 9.0.6). Core only accepts a field
  name it can match a service on and rejects everything else with *"Unsupported unique identifier
  field provided by module"* — including `uniqueIdentifier`, which is the documentation's name for the
  account **array key**, not a WHMCS service field. The bundled cPanel and Plesk modules both ship
  `domain`. See §6.6 for what that forces on to every row the module emits.

`WHMCS\Service\Status` is resolved through `defined()`/`constant()` with the module's own copy of the
values as a fallback, so lib code stays free of WHMCS classes (§2 rule) while the entry file still
passes the real constants when WHMCS is in scope.

### 5.3 `UsageUpdate`

developers.whmcs.com/provisioning-modules/usage-update/ — "a daily import of the disk and bandwidth
usage for accounts of a server", triggered by WHMCS's cron, **per server, not per product**, and only
for an active/enabled server. It writes `tblhosting.diskusage / disklimit / bwusage / bwlimit` plus
`lastupdate => Capsule::raw('now()')`.

Units are **megabytes** — not stated on the UsageUpdate page itself; the UpdateClientProduct API
reference documents the same four columns as "in megabytes"
(developers.whmcs.com/api-reference/updateclientproduct/).

Entry-file design:

- The service list is one query on `tblhosting` (server id, status Active/Suspended) plus one query
  for the two `ispconfig_*` service properties by field NAME + service id — the same
  product-independent lookup as the `ServiceProperties` fallback reader, because custom fields are
  scoped to the service's current product (§ live fact from Phase 1).
- **One reachability probe** (`monitor_jobqueue_count`) runs before the sweep. Without it, an
  unreachable or mis-credentialed panel would be retried once per service, and ten failed logins lock
  the WHMCS server's IP out of the panel for five minutes (§1.4) — a nightly cron that locks itself
  out every night would be a self-inflicted outage.
- A per-service failure is data, not an exception: it lands in the module log entry (`action =
  UsageUpdate`) as `skipped[serviceId] => reason`, alongside `updated` and, when the sweep aborted,
  `error`. Nothing in this function throws — an exception would abort WHMCS's cron for every other
  server too.

### 5.4 `MetricProvider`

developers.whmcs.com/provisioning-modules/usage-metrics/. WHMCS wants an object implementing
`\WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface` with `metrics()`, `usage()` and
`tenantUsage($tenant)`, built from `\WHMCS\UsageBilling\Metrics\{Metric, Usage}` and the
`Metrics\Units\*` classes; the documented sample declares all three methods **without parameter or
return types**, and the adapter must match that or it will be incompatible with the real, encoded
interface.

Those classes are ionCube-encoded core, so `Metrics\Provider` deals in plain data
(`MetricDefinition`, `array<systemName, int>`) and the entry file does the final construction. The
adapter is an **anonymous class**: a top-level `implements ProviderInterface` would be resolved when
the module file is included, which fails anywhere WHMCS core is not loaded.

- `metrics()` is static-data only and does **no I/O** — WHMCS invokes it in product contexts where no
  server is in scope.
- `usage()` is keyed by the account username, per the documented sample.
- `tenantUsage($tenant)` accepts a service model, a service id or a username, because WHMCS's own type
  for that argument is not documented and cannot be read from encoded core.
- A value of `0` is reported rather than skipped (the doc sample's `if ($data[$key])` would drop it):
  zero is a real reading, and dropping it leaves the previous collection standing forever.
- Metric **system names are stable** (`diskusage bandwidth mailquota databasequota websites mailboxes
  databases ftpusers dnszones`) — renaming one orphans its stored history.

### 5.5 Single sign-on

developers.whmcs.com/provisioning-modules/single-sign-on/ and .../meta-data-params/. Both functions
return `['success' => bool, 'redirectTo' => string, 'errorMsg' => string]`.

ISPConfig core has **no one-time-login API** (verified across the 3.3.1p1 tree: `client_login_get()`
only validates a credential pair, and `login(..., true)` mints an API session, not a panel session),
so SSO exists only where the optional **`ispconfig-sso`** project is deployed on the panel. That
project is separate from this repository — its own GPLv3 ISPConfig add-on, not WHMCS-specific and not
shipped or required by this module — so its ABSENCE IS THE DEFAULT the module is written around. That
makes the MetaData labels conditional, and creates one genuinely awkward constraint:

> `MetaData()` receives **no `$params`** — no server, no credentials — and is called on many admin
> pages. It therefore cannot probe the panel.

Resolution: `GetRemoteMetaData` caches `sso_available` into `tblservers_remote.meta_data` (one
`get_function_list` call, already granted), and `MetaData()` reads that cache with a single joined
query, memoised per request and defaulting to *not advertised* on any failure. The operator-visible
consequence is documented in INSTALL §7.5: after installing `ispconfig-sso` on the panel, open
Configuration > Servers once.

**`AdminSingleSignOnLabel` is deliberately never advertised.** WHMCS documents that label as
server-level ("Login to Panel as Admin"), and `ispconfig-sso` refuses to mint tokens for administrator
accounts by design — an admin token bypasses password and IP checks, so it would be a full panel
takeover. A server-level button could therefore only ever fail, and a dead button is worse than none.
`ispconfigconnect_AdminSingleSignOn()` still exists and works wherever WHMCS calls it with a service
in scope; called with only a server, it returns that explanation plus the panel URL.

Logging rule: the redirect URL carries the one-time token in its query string, so the SSO entry points
log the **outcome only**, never the URL. (`Api\RestClient` masks the `token` key of the mint
response through WHMCS's replaceVars, so the wire log is safe on its own.)

### 5.6 Entry-file seams and the WHMCS class doubles

The entry file is the only place allowed to touch WHMCS APIs, which makes it the only place that
cannot be unit-tested against a real dependency. Two conventions keep it testable:

- **Optional trailing seam parameters.** `UsageUpdate($params, $rowProvider, $writer)`,
  `MetricProvider($params, $rowProvider)` and `MetaData($ssoProbe)` take extra arguments that WHMCS
  never passes; the defaults do the Capsule work. `MetaData`'s seam is typed `mixed` rather than
  `?callable` on purpose — a typed parameter would turn any future core change that passes an
  argument into a fatal error.
- **`tests/Module/WhmcsClassDoubles.php`** declares the WHMCS core classes the entry file names
  (`WHMCS\Service\Status`, the `UsageBilling` metric types) with the public shapes the documentation
  shows. It is the class-level counterpart of `tests/stubs/whmcs-functions.php`, is required from
  `ModuleTestCase` before the entry file, and is never shipped. Without it the module tests would only
  ever exercise the fallback branches.

`WHMCS\Database\Capsule` is used only behind `class_exists()` guards (which is also what lets PHPStan
resolve it), and every Capsule block degrades to an empty result rather than an exception.

## 6. The WHMCS servers page (verified 2026-08-25, WHMCS 9.0.6)

**Configuration > System Settings > Servers** is the one WHMCS screen where a provisioning module's
behaviour is almost entirely undocumented, and where guessing costs hours. Everything below was
established on a live WHMCS 9.0.6 install running this module against ISPConfig 3.3.1p1, on
2026-08-25. WHMCS core is ionCube-encoded: each finding was obtained by **observing behaviour and by
elimination**, never by decompiling. Each one states how it was proven, so a later reader can
re-check it rather than re-derive it.

**These five findings are settled. Do not re-litigate them; build on them.**

### 6.1 Finding 1 — WHMCS does not load `modules/servers/<module>/hooks.php`

A file-level marker — code at the top of the file, outside any function and before any `add_hook()`
call, so that merely *including* the file would record it — was placed in the deployed
`modules/servers/ispconfigconnect/hooks.php` on a live install with the module active and an
ISPConfig server configured. It **never fired**, across many admin page loads, including the Servers
page itself.

The developer docs describe a module `hooks.php` as auto-discovered ("hook files are detected when
the module is activated"). On 9.0.6, for a **provisioning (servers) module**, that did not happen.

Consequence for this module: a hook file under `modules/servers/ispconfigconnect/` is **dead code**.
The admin-area hook therefore ships as `modules/servers/ispconfigconnect/hooks/ispconfigconnect.php`
and is **copied by the operator into `includes/hooks/`**, which is the documented, always-scanned
location (INSTALL §2 and §7.4).

### 6.2 Finding 2 — the styled `remote-meta-data` block is core-rendered and gated to bundled modules

The block WHMCS draws under a server's hostname — `<div class="remote-meta-data">`, where the bundled
Plesk module shows *Plesk Version / Load Averages / License Max # of Accounts* — **cannot be reached
by returning different keys from `GetRemoteMetaData`.**

Proven by elimination: `tblservers_remote.meta_data` for our server was hand-populated with Plesk's
**exact** key vocabulary —

```json
{"version": "...", "load": {"one": ..., "five": ..., "fifteen": ...}, "max_accounts": ...,
 "max_domains": ..., "service_count": ..., "ownedAccounts": ..., "sitejet_packages": ...,
 "sitejet_available": ...}
```

— together with a fresh `updated_at`. Our row still rendered **no block**, while the Plesk server's
row rendered its block normally **on the same page**. The block's label strings appear in no plain
file of the install, so the markup comes from encoded core, and the only variable left that
distinguishes the two rows is which module the server runs.

**Do not chase this further with more key guesses.** The module renders its own equivalent block
through the documented `AdminAreaFooterOutput` hook (`Server\MetaDataPanel`).

### 6.3 Finding 3 — "Accounts: N" is `tblservers_remote.num_accounts`, which no metadata key sets

The *Accounts* figure in the row's Remote Usage Stats cell is printed from the
`tblservers_remote.num_accounts` **column**, not from the `meta_data` JSON.

Proven from both directions:

- returning `num_accounts`, `numAccounts`, `ownedAccounts` and `accounts` from `GetRemoteMetaData`
  stored each of them verbatim **inside `meta_data`** and left the column untouched — the page showed
  no account count;
- writing the column directly made the page show the number immediately.

So the column is populated somewhere else. `GetRemoteMetaData` is not that place, and the module has
no other write path to it. **The remaining candidate is the Server Sync (`ListAccounts`) run** — that
is the only other module entry point core invokes with the whole account list in hand.

Status (updated 2026-08-25, live): the sync's **analyse** step does **not** write it. A full *Sync
Accounts* analyse ran against the live install — core called `ListAccounts`, rendered all five
accounts, matched one to two existing services — and `num_accounts` was still `0` afterwards, with
`updated_at` freshly bumped by the same page load. Core's own
`Admin\Utilities\Tools\ServerSync\Controller` exposes exactly two entry points, `analyse()` and
`process()`, so the remaining candidate is `process()` — the step that actually imports/syncs/
terminates services in WHMCS.

That has deliberately **not** been tested: `process()` mutates real billing records, and running it
to satisfy curiosity on a production install is not a trade worth making. If a future maintainer
wants the answer, do it on a throwaway WHMCS.

Practical consequence: **do not rely on core's *Accounts* figure for this module.** It stays `0`
until (perhaps) someone completes a sync. The module's own block reports `Clients: N` from a live
probe on every metadata refresh, which is the same question answered accurately and always fresh —
that is what an ISPConfig operator should read.

The module's side of that flow is in place either way: `ListAccounts` returns `success` with one row
per client, each row carrying an identifier core accepts (§6.6), and `tests/Module/ListAccountsTest`
covers the return shape. Until the column is confirmed, the module's own always-fresh answer to the
same question is the **Clients** line in its own block (`client_count`), which never depends on a
sync having been run.

### 6.4 Finding 4 — the flat return IS stored verbatim, and `service_count` IS rendered

`GetRemoteMetaData`'s return is stored **verbatim as JSON** in `tblservers_remote.meta_data`; a
`['success' => true, 'metaData' => [...]]` wrapper is stored as those two literal keys rather than
being unwrapped, so the return **must be flat**. Of everything in it, core renders exactly one key
itself: **`service_count`** becomes *"Services: N"* in the Remote Usage Stats cell. That works today
and is covered by `tests/Unit/Server/RemoteMetaDataTest`.

The full key list and its consumer for each key is in the `Server\RemoteMetaData` class docblock;
`RemoteMetaDataTest` locks both the key set and the round trip through `ServerMetaData` and
`MetaDataPanel`, so a rename cannot quietly drop a line from the page.

### 6.5 Finding 5 — "WHMCS Usage Stats" (e.g. `1/200`) is core's own arithmetic

The *WHMCS Usage Stats* figure is core counting the **WHMCS services assigned to that server** over
the server's own **Maximum No. of Accounts** field (Servers > Edit). It has **nothing to do with the
module**, reports nothing about the panel, and no module return value changes it. A module that
provisions perfectly still shows `0/200` until WHMCS services point at the server.

### 6.6 What this forces on `ListAccounts`

Finding 3 makes Server Sync the only lever the module has on the account count, and the sync's
matching rules are strict:

- `ListAccountsUniqueIdentifierField` **must be `domain`** — core rejects any other value with
  *"Unsupported unique identifier field provided by module"* (§5.2).
- Core matches that value against `tblhosting.domain` and **validates nothing else**. Rows pushed
  through core's own `SyncItem` on the live install were accepted as-is: `client-42.no-domain.invalid`
  and `client-43.no-domain.invalid` were both `ACCEPTED` with `matches=0`, coexisting without
  colliding — and so was an **empty** domain. Non-emptiness and uniqueness are therefore **entirely
  the module's job**; nothing downstream will catch a bad identifier.

Hence `Sync\AccountLister::identifier()`: every row carries a non-empty, batch-unique value in
**both** the `domain` and `uniqueIdentifier` keys (encoded core makes it unobservable which one it
reads, so they answer identically), and a client whose primary domain is missing or already claimed
gets a synthetic `client-<ispconfig client id>.no-domain.invalid`.

Why a reserved TLD rather than the panel username, which the module used before:

- an **empty** identifier is the dangerous shape, not a harmless one — every WHMCS service whose
  `tblhosting.domain` is blank would be a candidate match for it, and several blank rows are
  ambiguous against each other;
- `.invalid` is reserved by RFC 2606 §2 and can never be a real service domain, so a synthetic row
  can only ever be reported as *an account WHMCS does not know about* — never mispaired with an
  unrelated service. An unmatched row is an operator's decision; a wrong match is an operator's
  incident.

Known limitation, deliberately not worked around: an ISPConfig client with **no website** can never
match a WHMCS service, because core matches on domain only. If a future build wants those rows to
reconcile, the material exists — the module stores `ispconfig_client_id` as a service property, so the
**entry file** (the only layer allowed to touch Capsule) could resolve each panel client id to its
WHMCS service domain and pass that in as the identifier. `AccountLister` is deliberately free of
WHMCS calls, so that resolution belongs in `ispconfigconnect_ListAccounts()`, not here.
