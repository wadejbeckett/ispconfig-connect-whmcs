# Implementation Notes — ISPConfig Connect for WHMCS

Working spec for the build. Companion to `docs/superpowers/specs/2026-08-05-whmcs-ispconfig-connector-design.md`
(the approved scope & design). Everything in §1 below was re-verified on 2026-08-24 directly against the
ISPConfig **3.3.1p1** source tarball (extracted locally; see the session scratchpad `ispconfig-src/` tree).

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
client_template_additional_add($session_id, $client_id, $template_id)
client_template_additional_delete($session_id, $client_id, $assigned_template_id)
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

`client_update` **partial updates are safe**: the server merges the old record first
(`updateQueryPrepare`), and empty PASSWORD-type params are dropped from the merge, so omitting or
sending an empty password never wipes it.

**`get_function_list` returns the full registered method list, not per-user grants** — a granted-vs-not
check can only be made by calling a function and catching `PermissionDeniedException`. TestConnection
therefore login-checks + read-probes only (`server_get_all`, `client_templates_get_all`,
`monitor_jobqueue_count`); the full required grant list ships as documentation.

When exact `$params` field requirements matter, READ THE FORM DEFINITIONS in the extracted source
(`interface/web/<module>/form/*.tform.php`) rather than guessing — the scratchpad path is in your task
brief. Validation failures surface as `data_processing_error` remote faults.

### 1.6 Known pitfalls (design §4, all source-verified)

- `client_add`: **always pass `usertheme` explicitly** (`'default'`) — neutralizes the 3.3.1
  `usertheme_syntax_incorrect` bug (#6990).
- `dns_templatezone_add` returns bool → resolve the id with `dns_zone_get_id(origin)`; try the domain
  with trailing dot (`example.com.`) — check the source for exact matching before implementing.
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
  success or failure (failure: response = exception message). The callback receives the request with
  the password/session values INTACT — scrubbing is the wiring's job (Support\Logging), because only
  the wiring knows WHMCS's replaceVars convention. RestClient must pass the values it knows are
  sensitive (password, session_id, remote_password) to the callback separately:
  signature `callable(string $action, array $request, mixed $response, list<string> $sensitiveValues)`.
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
- Unit tests assert the alias mapping against a fixture extracted from source (grep `checkPerm`
  in the scratchpad tree and hard-code the expected pairs in the test).

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
  `errordocs` => 1. Check `web_vhost_domain.tform.php` for required fields.
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
- `suspend(int $clientId, ?int $domainId, bool $alsoDisableWebsite)` — `client_update(clientId, 0,
  ['locked' => 'y'])`; if requested and domainId known: `sites_web_domain_set_status(domainId,
  'inactive')`.
- `unsuspend(...)` — inverse (`locked` => `'n'`, status `'active'`).
- `terminate(int $clientId): void` — `client_delete_everything`.
- `changePassword(int $clientId, string $newPassword)` — `client_change_password`.
- `changePackage(int $clientId, ProductConfig, list<int> $currentAddonAssignmentIds)` —
  `client_update` with new `template_master`, then addon template reconciliation
  (client_get to discover current assignments — the field holding assigned addon templates; read
  client.tform / client.inc.php `client_template_additional_*` in source for the exact shape).
- `jobqueueCount(?int $serverId): int` — monitor_jobqueue_count.
- `ProvisioningException extends IspconfigException` lives in `Provision\` (carries `stage`,
  `rollbackSucceeded`).

### 2.8 Entry file `ispconfigconnect.php` + wiring

Follow the official sample module layout (MIT): https://github.com/WHMCS/sample-provisioning-module —
fetch and read it before writing. Every function `if (!defined('WHMCS')) die;` guarded, thin, delegates
to lib, catches ALL IspconfigException + Throwable → returns the message string (WHMCS convention:
return `'success'` or error string). Functions (MVP):

- `ispconfigconnect_MetaData()` — DisplayName `ISPConfig Connect`, APIVersion 1.1,
  RequiresServer true, DefaultSSLPort 8080, DefaultNonSSLPort 8080, ServiceSingleSignOnLabel omitted (Phase 2).
- `ispconfigconnect_ConfigOptions()` — §3.1 frozen order, with `Loader` functions for options 1/2
  (`ispconfigconnect_ClientTemplateLoader`, `ispconfigconnect_WebServerLoader` — each builds a
  SessionManager from $params, calls the API, returns `[id => name]`, and MUST logout; on exception
  rethrow — WHMCS displays loader errors inline).
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
contract in executable form — model it on the REAL code (read it in the scratchpad tree), including:

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
| 13 | Suspend Also Disables Website | yesno | on |
| 14 | Panel URL Override | text | — |

### 3.2 Deferred out of MVP (tracked, not stubbed)

Keep-DNS-on-terminate toggle (Phase 3), hooks.php contact sync (Phase 2), SSO (Phase 2, companion),
reseller products / dedicated IP (Phase 3), client-area management views (Phase 4), UsageUpdate /
MetricProvider (Phase 2). Do NOT create placeholder functions or empty files for these.

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
- Security tests are first-class: TLS defaults, credential scrubbing (`ModuleCallLog::entriesLeaking`),
  no-password-in-HTML, lockout circuit, rollback on partial create.
- Style: PSR-12, `declare(strict_types=1)`, final classes by default, readonly VOs, no abbreviations in
  public API names. Comments only for non-obvious constraints (wire facts, ISPConfig quirks) — cite
  the source file when stating a wire fact.
