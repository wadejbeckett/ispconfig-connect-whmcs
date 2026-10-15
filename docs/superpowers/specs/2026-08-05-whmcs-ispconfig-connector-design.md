# ISPConfig Connect for WHMCS — Scope & Design

**Date:** 2026-08-05 (owner decisions applied same day)
**Status:** Approved — license, naming, and commercial model decided by owner; ready for implementation planning
**Name:** ISPConfig Connect for WHMCS (module system name `ispconfigconnect`, repo `ispconfig-connect-whmcs`) — first of the **ISPConfig Connect family**: `ispconfig-connect-whmcs`, `ispconfig-connect-blesta`, `ispconfig-connect-fossbilling`, sharing an extracted `ispconfig-connect-core` library (REST client, session manager, provisioning recipes). Part of the **ISPConfig Toolkit** portfolio (see `ispconfig-toolkit/ROADMAP.md`).
**Goal:** a FOSS WHMCS provisioning ("server") module for ISPConfig 3.x with feature parity to the bundled cPanel and Plesk modules — deployable on our own production WHMCS, listed on the WHMCS Marketplace, and positioned as the missing commercial pillar of the "ISPConfig as a Plesk/cPanel replacement" roadmap (companion to `ispconfig-theme-customizer`).

All version numbers, licenses, prices, and API behaviors below were verified in-session on 2026-08-05 against vendor sources (docs.whmcs.com, developers.whmcs.com, marketplace.whmcs.com, ispconfig.org blog, git.ispconfig.org at tag `3.3.1p1`, GitHub APIs) by a 10-agent research pass with per-claim adversarial re-verification. Nothing is from memory.

---

## 1. Verified landscape (why now, why us)

**The niche is empty and demand is proven.**

- The WHMCS Marketplace search for "ispconfig" returns **zero results**. The one surviving legacy listing (`ispcfg3`, product 2214, free/GPL-3.0) is delisted from search, last updated **2016-08-06**, and declares compatibility only up to **WHMCS 7.5**.
- WHMCS's own feature request "ISPConfig 3 Module" gathered **122 votes** and was **declined** by WHMCS staff (2021: "due to lack of demand") — WHMCS will not build this.
- **No commercial module exists today.** ispconfig.org sells no WHMCS module (its add-ons page never mentions WHMCS); ModulesGarden has no ISPConfig product; all historical commercial modules (veLenoSo 2011, "Germanius" ~2014, AltoMarketing ~2016) are dead.
- WHMCS has never bundled an ISPConfig module — the 9.0 server-module list (cPanel, Plesk, DirectAdmin, InterWorx, SolusVM, Virtualizor, …) excludes it.

**Platform baselines (verified):**

| Platform | Current | Constraint for us |
|---|---|---|
| WHMCS | **9.0.6** (2026-07-08); 9.0 GA 2026-01-20; 8.13 is the other supported line | PHP **8.2/8.3**, Smarty 4 templates (no `{php}`-family tags), ionCube needed for WHMCS core only — **modules are plain PHP** (official sample module is unencoded MIT) |
| ISPConfig | **3.3.1p1** (2026-03-08 tag; blog announcement 2026-03-09) | Remote API signature-stable for 6+ years; 309 public remote functions across 10 domain classes; **3.3.1 (non-p1) has a `client_add` bug** (`usertheme_syntax_incorrect`, #6990) — we always pass `usertheme` explicitly to neutralize it |

**Marketplace mechanics (verified):** listing is free; moderation is content-only (explicitly no code review), ~1–3 weeks; the marketplace does not process sales — commercial listings link to your own checkout; open-source + commercial listing coexistence is demonstrably permitted (ispcfg3 is GPL and listed). There is no certification program to clear or buy. Listing icon must not be primarily someone else's trademark (i.e., don't use the ISPConfig logo verbatim).

---

## 2. Fork vs greenfield — decision: **greenfield, harvest as specification**

The exhaustive survey (GitHub, GitLab, Codeberg, Packagist, marketplace, forums; all 46 forks of the lead candidate diffed via the compare API) found exactly **four** non-empty open-source WHMCS↔ISPConfig modules. Newest functional code commit across all four: **2020-06-11**. Verdict per candidate:

| Candidate | Why it is disqualified |
|---|---|
| **cwispy/ispcfg3** (61★, GPL-3.0, archived, "no longer maintained") | Unauthenticated elFinder connector (open FTP relay/SSRF at a fixed URL); vendored elFinder 2.1.29 with ~11 known CVEs *and* fatal parse errors on PHP 8+ (26k lines of dead code on any current install); **IDOR in every client-area write** (admin-privileged API session + browser-supplied object IDs — open issue #39 since 2019); TLS verification hardcoded off; credentials leaked into module logs (empty `$replaceVars`) and into rendered HTML (`data-password=…`); GPL-3.0 propagates to any derivative. No living fork among all 46. |
| boldbesusiax/whmcs-ispconfig3 (2017) | **Requires patching ISPConfig core** (`server.inc.php`) — violates the no-core-mods stance; no client area; admits its DNS path is broken; panel password in page source. |
| charter-hosting/whmcs-ispconfig3 (2016, "Beta 1") | No LICENSE file (legally unforkable); include-fragment architecture with a latent variable bug that silently disables SSL; same password-in-HTML leak. |
| jphustman/ispcfg (2014) | `mysql_query()` — fatals on PHP ≥7.0; raw SQL concatenation; no MetaData. |

A clean WHMCS 9 module implementing the full parity surface is an estimated 1,500–2,500 lines plus tests — *less than the audit-and-rewrite cost of any fork*, with no CVE inheritance and no GPL obligation.

**What we harvest (legally clean, as behavioral spec, not code):**

- **cwispy's provisioning sequences** — which ISPConfig functions, in what order, with which params (client_add → template attach → dns_templatezone_add → sites_web_domain_add → sites_ftp_user_add → sites_database_add → mail_domain_add), and its suspend/unsuspend semantics. Six years of ISPConfig quirk knowledge; every function it calls still exists in 3.3.1p1 with unchanged signatures (verified against the 3.3.1p1 tarball).
- **blesta/module-ispconfig** (**MIT**, pushed 2026-07-01 — actively maintained) — best-quality reference for correct call sequences; MIT allows verbatim reuse of API-client patterns.
- **FOSSBilling** `Ispconfig3.php` (**Apache-2.0**, 2026-02-11) — clean REST (`json.php`) client shape, typed methods.
- **cwispy's 14 client-area views** — as a *feature backlog* defining market expectations, not as code.

---

## 3. Product decisions

### 3.1 Naming (decided)
- **Family naming scheme:** `ispconfig-connect-<billing-platform>` — `ispconfig-connect-whmcs` now; `ispconfig-connect-blesta` and `ispconfig-connect-fossbilling` reserved for later tracks (those tracks open with an upstream-first assessment, since Blesta ships a maintained MIT ISPConfig module and FOSSBilling an Apache-2.0 one — we contribute upstream where that serves users better, and build `ispconfig-connect-*` where upstream is missing or structurally behind, as with WHMCS). Shared plumbing lives in **`ispconfig-connect-core`** (REST client, SessionManager, Permissions, provisioning recipes), vendored into each connector at build time (WHMCS installs have no Composer).
- **Module system name:** `ispconfigconnect` (WHMCS rule: single word, lowercase, letters/numbers, unique). Deliberately distinct from the dead `ispcfg3` and `ispconfig3` modules so both can coexist during migration.
- **Product name:** "ISPConfig Connect for WHMCS" (owner-approved). Descriptive use of the ISPConfig mark; we do not use their logo as our icon. Coordinate with the ISPConfig project before launch — the owner's stance is **full public endorsement of the upstream project in all things**; a listing in ISPConfig community channels is also the product's best marketing channel.

### 3.2 License — **GPLv3** (owner decision, 2026-08-05)
- Chosen to protect the open-source nature of the work: any fork or derivative must stay open. This forecloses the ionCube-encode-and-resell fork scenario that MIT would permit.
- Applies to the whole Connect family including `ispconfig-connect-core` and the companion SSO module. (`ispconfig-theme-customizer` stays MIT — separate product, already shipped.)
- **Commercial mechanics under GPLv3** (how "sell it and keep it FOSS" works): selling GPL software is fully permitted; what GPLv3 guarantees buyers is the freedom to modify and redistribute. Revenue therefore attaches to things the license does not oblige us to give away: the supported/auto-update build channel (license-key-gated updates via our own WHMCS + Software Licensing — the standard GPL-ecosystem pattern), priority support, and installation services. A paid marketplace listing plus a free GitHub edition is permitted (verified: marketplace imposes no license terms; the Free/Commercial badge is per-listing, so this is either two listings or one commercial listing that links the source — resolve at submission time).
- **Dual licensing** (selling exceptions to GPLv3) stays possible only while we hold all copyright — if we ever take outside contributions, that requires a CLA. Decision deferred until there's a concrete need; default is plain GPLv3, no CLA, maximum community trust.

### 3.3 Account model — **one WHMCS service = one ISPConfig client**
Mirrors cPanel (one service = one panel account). The WHMCS *client* may own many services, each mapping to its own ISPConfig client. This is a hard lesson from the incumbent: an ispcfg3 marketplace review reports terminate wiping **all** of a customer's accounts — a consequence of coupling one ISPConfig client to a WHMCS client. Terminate calls `client_delete_everything` on *that service's* ISPConfig client only. ISPConfig IDs (client_id, primary domain_id, etc.) are stored via the WHMCS Service Properties API (`$params['model']->serviceProperties`), not a custom table, so they're visible/editable in the admin UI.

### 3.4 Product → plan mapping — **copy the Plesk pattern onto ISPConfig client templates**
Verified precedent: Plesk's module does name-keyed plan binding natively (vs cPanel gating option overlays behind a paid addon). Translation:
- Module setting **"Client Template"** (dropdown, populated live via a WHMCS `Loader` function calling `client_templates_get_all`) → ISPConfig **master template** (`template_master`). The template stays the source of truth for limits — operators manage plans in ISPConfig, exactly like Plesk service plans.
- Configurable options named **`ISPConfig <Addon-Template-Name>`** attach same-named **addon templates** (`client_template_additional_add`) — additive plan deltas, Plesk-style.
- Reserved numeric options **`Disk Space`** / **`Bandwidth`** (value|display syntax) feed both provisioning quota overrides and WHMCS overage soft limits.
- Advanced (later): direct `limit_*` overrides for template-less operation.

### 3.5 Transport — **REST (`remote/json.php`) only**
Verified: `POST https://host:8080/remote/json.php?<method>` with a JSON body whose keys match the PHP parameter names (reflection-matched, order-independent); responses `{"code":"ok"|error,"message":…,"response":…}`. Avoids WSDL-less SOAP quirks entirely; matches FOSSBilling and cwispy's provisioning path. `remote/rest.php` also exists in 3.3.1p1 but its contract is undocumented — Phase 0 includes a one-day spike to characterize it; adopt only if it offers something `json.php` lacks.

### 3.6 Security posture (the differentiator against every incumbent)
1. **Least-privilege remote user, documented exactly.** ISPConfig remote users carry a per-function grant list (`remote_functions`) plus an IP allowlist. We ship the exact function list the module needs (including the non-obvious aliased permission names verified from `remoting.inc.php`, e.g. `server_get_all → server_get`, `client_login_get → client_get`) and installation docs that create that user — never the panel admin credentials all four incumbents used.
2. **TLS verification ON by default**, with an explicit per-server opt-out for self-signed panels and support for a custom CA bundle (Access Hash field carries the CA path/PEM).
3. **No credential ever rendered.** No password-in-HTML/JS login forms — the incumbents' defining defect.
4. **`logModuleCall` on every API call with `$replaceVars` scrubbing** of password/session values.
5. **Session manager** that respects ISPConfig's verified auth behavior: 30-minute hard TTL never extended, session bound to login IP, and the 10-failures-per-5-minutes IP lockout *where invalid session IDs also count* — so we login-per-operation-batch, never retry a dead session more than once, and back off on `session_failure_limit`.
6. Any client-area action resolves the ISPConfig client_id **server-side from the WHMCS service** and validates ownership before every write — the authorization model cwispy never had (its issue #39).

### 3.7 SSO strategy
Verified fact: **ISPConfig core has no one-time-login/token API** (grep of the full 3.3.1p1 tree; `client_login_get` only validates credentials, `login(..., true)` mints API sessions, not panel sessions). Options:
- **(a) Companion ISPConfig extension module** (recommended, Phase 2): ISPConfig auto-loads extra remoting classes from `interface/web/<module>/lib/classes/remote.d/` — a documented extension point, zero core modifications (consistent with the customizer's envelope discipline). We ship a small optional ISPConfig-side module: a remoting function that mints a short-TTL one-time token + a consumer endpoint that establishes the panel session and redirects. WHMCS-side `ServiceSingleSignOn`/`AdminSingleSignOn` call it. This is a headline feature no ISPConfig module has ever had — true "Log in to panel" parity with cPanel/Plesk.
- **(b) Without the companion**: no auto-login. We show panel URL + username (never auto-POST credentials — that's the incumbents' leak). SSO buttons appear only when the companion is detected.

### 3.8 Client-area scope — **lean at parity, deep later**
Verified: the bundled cPanel/Plesk modules ship *no* in-WHMCS mail/FTP/DNS management — that depth is third-party "Extended" territory. Parity therefore requires only: usage display, SSO/login button, and the standard overview. cwispy's 14 views become the Phase 4 backlog (each gated on the per-service authorization layer), not MVP scope.

### 3.9 Suspend/terminate semantics
- **Suspend:** `client_update` with `locked` (invokes ISPConfig's `func_client_lock` — verified present) — locks panel access and, per ISPConfig semantics, the client's services; optionally also `sites_web_domain_set_status(inactive)` per site (config toggle "Suspend disables websites").
- **Unsuspend:** inverse.
- **Terminate:** `client_delete_everything` on the service's own ISPConfig client (see §3.3). Config toggle "Keep DNS zone on terminate" (cPanel-parity SHOULD).
- All mutations are **asynchronous** in ISPConfig (datalog → per-server cron; verified). API success = accepted, not provisioned. We surface pending-job state via `monitor_jobqueue_count` on the admin service tab and in TestConnection diagnostics, and document the eventual-consistency window.

---

## 4. Architecture

```
modules/servers/ispconfigconnect/
├── ispconfigconnect.php        # thin WHMCS entry points only — every function delegates to lib/
├── whmcs.json                  # marketplace metadata
├── logo.png
├── hooks.php                   # ClientEdit → contact sync (opt-in); admin widget (later)
├── lib/                        # autoloaded as WHMCS\Module\Server\Ispconfigconnect
│   ├── Api/RestClient.php      # Guzzle 7 → remote/json.php; TLS verify default-on; typed errors
│   ├── Api/SessionManager.php  # login/session cache, 30-min TTL, lockout-aware backoff
│   ├── Api/Permissions.php     # the canonical remote_functions grant list (doc + self-test)
│   ├── Provision/Recipe.php    # create/terminate orchestration incl. rollback on partial failure
│   ├── Provision/Mapper.php    # product config + configurable options → template/limit params
│   ├── Metrics/Provider.php    # MetricProvider (usage billing)
│   ├── Usage/Updater.php       # UsageUpdate (daily disk/bw per server)
│   ├── Sso/CompanionClient.php # detects + calls the optional ISPConfig-side SSO module
│   └── Support/Params.php      # $params normalization; ServiceProperties accessors
├── templates/                  # Smarty-4-clean; Twenty-One (BS4) + Nexus tested
└── tests/                      # PHPUnit; FakeIspconfigServer (JSON transport double)

(the OPTIONAL ISPConfig-side SSO module was planned here as companion/ispconfig-sso/
 — it now lives in its own repository; see the note below)
```

> **Superseded 2026-08-25.** The panel-side SSO module is no longer part of this repository. It ships
> as the standalone **`ispconfig-sso`** project (the decision recorded in §8.3), so that any billing
> system — not only WHMCS — can use it. This module keeps only `lib/Sso/CompanionClient.php`, which
> detects that project at runtime and calls its one remote function when it is present.

**WHMCS server form mapping:** hostname = panel host; port = 8080 default (`MetaData` DefaultSSLPort); username/password = the least-privilege **remote user**; Access Hash = optional CA bundle / companion shared secret; Secure = HTTPS.

**Function surface by phase** (all verified against current developer docs):

| WHMCS function | Phase | ISPConfig calls (verified names) |
|---|---|---|
| MetaData, ConfigOptions (+ Loaders) | 1 | `client_templates_get_all`, `server_get_all` (dropdowns) |
| TestConnection | 1 | `login` + `get_function_list` (verifies grant completeness) + `monitor_jobqueue_count` |
| CreateAccount | 1 | `client_add` (always passing `usertheme`), `client_template_additional_add`, `sites_web_domain_add`, `dns_templatezone_add` → `dns_zone_get_id`, `mail_domain_add`, `sites_ftp_user_add`, `sites_database_user_add` + `sites_database_add` — each stage per-product toggleable; rollback on partial failure |
| Suspend/Unsuspend | 1 | `client_update` (locked) ± `sites_web_domain_set_status` |
| TerminateAccount | 1 | `client_delete_everything` (± keep-DNS toggle) |
| ChangePassword | 1 | `client_change_password` |
| ChangePackage | 1 | `client_update` (template_master), `client_template_additional_add/delete`, limit recompute |
| AdminServicesTabFields | 2 | shows ISPConfig client_id/domain_id, job-queue depth, links |
| UsageUpdate | 2 | `quota_get_by_user`, `trafficquota_get_by_user` → tblhosting disk/bw per service |
| MetricProvider | 2 | + `mailquota_get_by_user`, `databasequota_get_by_user`, entity counts (websites, mailboxes, databases, FTP users, DNS zones) — snapshot metrics; traffic as period metric |
| ListAccounts (Sync tool) | 2 | `client_get` filter mode + `client_get_sites_by_user` |
| ServiceSingleSignOn / AdminSingleSignOn / LoginLink | 2 | companion module (§3.7); buttons hidden without it |
| Reseller products | 3 | `client_add` with `limit_client > 0` (reseller tform) + "Reseller Template" setting |
| Dedicated IP option | 3 | `server_ip_get` pool + `ip_address` on `sites_web_domain_add` instead of `*` |
| CustomActions, client-area management views | 4 | per-feature; each behind the ownership-validation layer |

**Known API pitfalls the code must encode** (all verified from 3.3.1p1 source): JSON method name must be the *first* query key; `client_update` arg order `(session_id, client_id, reseller_id, params)`; partial updates are safe (server merges old record); `dns_templatezone_add` returns boolean → follow with `dns_zone_get_id`; `mail_user_add` requires the mail domain to exist first; `sites_database_add` requires pre-created DB users and per-server-unique names; validation failures arrive as `data_processing_error` with HTML-joined, sometimes-localized messages (never string-match them); `remote_api_allowed=no` yields a *plain-text* (non-JSON) response; empty password params on update are ignored (useful) and `_ispconfig_pw_crypted=1` passes pre-hashed passwords (imports).

---

## 5. Testing & release engineering

- **Unit layer:** PHPUnit against `FakeIspconfigServer` — a local double speaking the verified `json.php` envelope, including its error shapes (`remote_fault`, plain-text disabled-API response, session expiry mid-batch, lockout).
- **Integration layer:** CI job provisioning a throwaway ISPConfig 3.3.1p1 VM/container; a characterization suite runs the full lifecycle (create → suspend → change package → terminate) against the real API and asserts panel-side state (incl. waiting out the datalog cron — the async model is part of the contract under test).
- **WHMCS layer:** manual test matrix on a WHMCS dev-licensed instance (9.0 + 8.13; PHP 8.2 + 8.3; Twenty-One + Nexus themes). WHMCS offers dev licenses — acquiring one is a Phase 0 task; our production WHMCS is the final staging rung, consistent with "verify against real state".
- **Static gates:** php -l matrix, PHPStan, and the repo-hygiene leak scan before any push (no live hostnames/credentials — same discipline as the customizer repos).
- **Review gates:** the established pipeline — `/code-review` per feature branch, silent-failure-hunter + security review before v1.0.0 (this module is a credential-holding, production-billing component; same bar as the customizer got).

## 6. Phases & effort (estimates, not commitments)

| Phase | Deliverable | Est. effort |
|---|---|---|
| **0 — Foundation** | Repo + CI + fake-server harness; RestClient + SessionManager + Permissions; TestConnection; `rest.php` spike; WHMCS dev license; ISPConfig test instance | 3–5 dev-days |
| **1 — Provisioning parity (MVP)** | The six lifecycle functions (Create, Suspend, Unsuspend, Terminate, ChangePassword, ChangePackage; Renew is intentionally unimplemented — ISPConfig needs no renewal action) + ConfigOptions/Loaders/MetaData + welcome-email variables + admin module commands. *Usable on our own servers at the end of this phase.* | 5–8 dev-days |
| **2 — Full bundled-module parity** | UsageUpdate, MetricProvider, ListAccounts/Sync, AdminServicesTab, companion SSO module + SSO functions | 6–9 dev-days |
| **3 — Beyond-Plesk polish** | Reseller products, dedicated IP, importer polish, docs site, marketplace listing + launch (moderation lead time 1–3 weeks — submit early) | 4–6 dev-days |
| **4 — Extended client area** | Selective management views (the cwispy backlog) behind the authorization layer — demand-driven | open-ended |

Sequencing note: our own servers migrate off Plesk provisioning at end of Phase 2 (parity + usage billing); the marketplace listing goes live with Phase 3.

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| ISPConfig behavior drift across 3.2 → 3.3 (signatures verified stable; *behavior* not fully diffed) | Declare **ISPConfig ≥ 3.2 supported, 3.3.1p1 CI-tested**; always pass `usertheme` (neutralizes the 3.3.1 bug); characterization suite pins behavior |
| WHMCS 9.x module-API changes (9.1 targets PHP 8.4) | Plain-PHP module, no encoded blobs; CI matrix extends per WHMCS release |
| Session/lockout edge cases (IP-bound sessions behind NAT/IPv6 privacy; retry-loop self-lockout) | SessionManager owns all retry logic; docs call out the NAT constraint |
| Companion SSO module = second deploy artifact | Strictly optional; module fully functional without it; reuse the customizer's installer/envelope discipline |
| Trademark/naming friction with ISPConfig project | Descriptive naming, neutral icon, and proactive coordination with Till/community (aligned with existing symbiosis stance) |
| Marketplace listing rot perception | Changelog + compatibility flags updated every WHMCS/ISPConfig release (the incumbent died in public by not doing this) |

## 8. Owner decisions (2026-08-05) and remaining open items

**Decided:**
1. **License: GPLv3** across the Connect family (§3.2) — protect the open-source nature of the work.
2. **Naming:** family scheme `ispconfig-connect-<platform>`; human name "ISPConfig Connect for WHMCS"; system name `ispconfigconnect` (§3.1).
3. **Companion SSO module: yes** — promoted to a standalone **ISPConfig Toolkit** project (`ispconfig-sso`) consumed by all connectors; a panel-side **file manager** module joins the toolkit as its own future project (see `ispconfig-toolkit/ROADMAP.md`).
4. **Commercial model:** the connector is **paid** (funds its upkeep: marketplace commercial listing + own checkout, GPLv3 with source available); the **primary revenue line is ISPConfig support subscriptions** sold to other hosting companies at aggressive pricing. The connector is the wedge; support is the business.
5. **Upstream: full endorsement of ISPConfig in all things.** Coordinate with Till/community pre-launch; contribute fixes as merge requests, never a hostile fork.

**Still open:**
6. Two forum threads (HowtoForge 67824 & 92661) were Cloudflare-blocked to automated readers — worth a manual skim for any circulating private forks before Phase 0 ends.
7. Free-tier question within the paid model: whether a feature-limited free listing exists alongside the paid one (helps adoption; resolvable at marketplace-submission time).

## 9. Verified-source appendix (load-bearing citations)

- WHMCS 9.0 system requirements & PHP/ionCube matrix — docs.whmcs.com (9.0 docs); release notes through 9.0.6.
- Provisioning-module function surface, `$params`, ConfigOptions/Loaders, SSO, UsageUpdate, MetricProvider, ListAccounts — developers.whmcs.com/provisioning-modules/* ; sample module github.com/WHMCS/sample-provisioning-module (MIT, plain PHP).
- Marketplace rules (free listing, no code review, no sales rail, 2-week review target) — marketplace.whmcs.com help/FAQ; developers.whmcs.com marketplace page.
- ISPConfig 3.3.1p1 release + API — ispconfig.org blog (3.3.1p1 announcement 2026-03-09); git.ispconfig.org tag `3.3.1p1`: `interface/lib/classes/remote.d/` (10 classes, 309 public functions), `interface/web/remote/{index,json,rest}.php`, `remoting.inc.php` (auth/session/lockout), `remoting_client/examples/rest_example.php`.
- OSS survey — GitHub repos/compare APIs for cwispy/ispcfg3 (+ all 46 forks), boldbesusiax, charter-hosting, jphustman; marketplace listing 2214; WHMCS request "ISPConfig 3 Module" (declined, 122 votes); blesta/module-ispconfig (MIT, 2026-07-01); FOSSBilling extensions-extra (Apache-2.0, 2026-02-11).
- Parity baseline — docs.whmcs.com cPanel/WHM and Plesk module pages (9.0 docs), incl. the Plesk Service-Plan/Panel-Addon mapping pattern, sync cron cadences (UpdateServerUsage daily, TenantUsageMetrics twice daily, OverageBilling monthly), and Sync Accounts behavior.

Full research reports + per-claim verification verdicts: workflow `wf_968f11fc-370` (10 agents, 151 verified claims), archived in the session transcript directory.
