# Roadmap and current state — ISPConfig Connect for WHMCS

**As at:** 2026-08-25
**Against:** WHMCS 9.0.6, ISPConfig 3.3.1p1, PHP 8.2 — one live production install
**Code state (checked while writing this page):** 912 automated tests pass, static analysis
(PHPStan level 6) reports no errors.

This is the honest answer to "where does the plan stand?". It is written to be readable without
knowing the code. Three further documents sit behind it: the original scope and design
(`docs/superpowers/specs/2026-08-05-whmcs-ispconfig-connector-design.md`, which defines Phases 0–4
and the licensing and commercial decisions), the technical record
(`docs/IMPLEMENTATION-NOTES.md`, which holds the evidence for every claim of the form "we proved X
on the live system"), and the operator guide (`docs/INSTALL.md`).

### Words used here

| Term | Plain English |
|---|---|
| **Provisioning** | Creating and managing a customer's hosting account automatically when they order or cancel. |
| **The panel** | ISPConfig — the control panel that actually runs the hosting. |
| **The module** | This software: the piece that lets WHMCS drive the panel. |
| **Live / proven live** | Actually exercised on the real production WHMCS and the real panel, with the result observed. Not "the tests pass". |
| **The cron** | WHMCS's scheduled background task, which runs nightly (and twice-daily for usage metrics). It is what triggers billing-related collection. |
| **`ispconfig-sso`** | A small optional add-on installed on the ISPConfig side, and its own separate open-source project — not part of this module, and usable by any billing system. Only needed for single sign-on. |
| **Grant** | A permission tick-box on the panel's API user, controlling which operations the module is allowed to perform. |

---

## 1. Where the plan stands, in one paragraph

The design set out five phases. **Phase 0 (foundations) and Phase 1 (the provisioning lifecycle) are
done and proven on the live system. Phase 2 (the full WHMCS-facing surface) is built and mostly
proven live — the exception is the money-touching part: usage figures and the billing arithmetic on
top of them are built and unit-tested, but their real values have never been checked against a real
service, and the nightly collection has never been watched running under WHMCS's own scheduler.**
Phase 3 (reseller products, dedicated IP addresses, marketplace launch) and Phase 4 (customer-facing
management screens) are not started. In practical terms: the module can already provision, suspend,
change and terminate real hosting accounts, and after the short list in section 6 it is a reasonable
thing to sell fixed-price hosting through — but usage-based billing should stay switched off until
section 3 is closed out, and we are not yet in a state to publish this to the world.

**Added since that assessment: adoption of pre-existing accounts.** The module used to recognise a
service only if it had created it, which made every migrated customer permanently unmanaged. It now
links such services itself — the admin's whole job is to point a service at the ISPConfig server and
give it an ISPConfig product (`docs/INSTALL.md` §5). The wiring and the admin surfaces are built and
tested; the matching itself lives in `lib/Adopt` and is tracked separately in section 3.

---

## 2. Done and proven live

Each row states how it was verified. Section numbers of the form **§1.5** or **§6.2**, here and
throughout this page, refer to `docs/IMPLEMENTATION-NOTES.md`, where the evidence for that finding
is written up in full.

| Capability | What it does | How it was verified |
|---|---|---|
| **Create account** | Creates the ISPConfig client and, per product settings, the website, FTP user, mail domain, DNS zone and database. All-or-nothing: a partial failure rolls the whole thing back rather than leaving a half-built account. | Run end to end against the production panel; the accounts appeared, with the ids stored back onto the WHMCS service. Two rounds of live testing found and fixed real-world gaps the panel only reveals when you ask it (extra mandatory fields on `client_add`; missing-template resilience) — commits `2efb402`, `401abb9`. |
| **Suspend / unsuspend** | Locks or unlocks the customer's panel account and its services (or just the website, if the product is configured that way). | Run live, both directions, with the panel state checked afterwards. The safety rule that makes this non-destructive — never lock an already-locked client, or unlocking later restores nothing — is documented at §1.5 and was designed from the panel's own source. |
| **Change password** | Rotates the panel password and the FTP and database passwords the module itself created, together. | Run live. |
| **Change package** | Moves the customer onto a different ISPConfig plan template in a single atomic update. | Run live. |
| **Terminate** | Deletes only *this* service's ISPConfig client — never a customer's other accounts. | Run live. This is the defect that sank the abandoned predecessor module; the one-service-to-one-client design (§3.3 of the design doc) is what prevents it. |
| **Test Connection / Verify Permissions** | Tells the operator whether WHMCS can reach the panel, and names any missing permission tick-box by name. | Run live, including against a deliberately wrong TLS setup, which produced targeted advice rather than a generic error (commit `ae01f6f`). |
| **Sync Accounts** | Lists every client on the panel so WHMCS can reconcile them against its own records. | Run live: WHMCS listed all five panel accounts and matched one to two existing services. Establishing this cost real effort — WHMCS silently rejects any identifier field except `domain` (§5.2, §6.6), and it validates nothing else, so uniqueness is entirely the module's job. |
| **Single sign-on** | A password-free *Log in to ISPConfig* button, using a single-use, short-lived token. No password is ever stored or replayed. | The separate `ispconfig-sso` project is installed on the production panel and sign-on works. A live security pass recorded 20 of 20 checks passing (run by the session that owns the production install). That project keeps its own harness, in its own repository. |
| **Servers page panel health** | Under each ISPConfig server on WHMCS's Servers page: panel version, pending job count, client count, node count, sign-on availability, and the reason when a probe fails. It also corrects WHMCS's own *Accounts* figure on our rows, which would otherwise read `0` forever (see §5). | Rendered live on the production Servers page. Getting there produced the five settled findings in §6 — including proof that WHMCS will not let a third-party module use its own styled block, and that WHMCS does not load a server module's own hook file at all. |
| **Usage metric definitions** | Nine billable measures — disk, bandwidth, mailbox storage, database storage, and counts of websites, mailboxes, databases, FTP users and DNS zones — offered to WHMCS's usage-billing feature. | Confirmed live: WHMCS's product *Module Settings* tab renders all nine, each with a working *Configure Pricing* link. That proves the definitions reach WHMCS correctly. It does **not** prove the numbers are right — see section 3. |
| **Security posture** | Least-privilege API user rather than the panel admin account; TLS checking on by default; no credential ever rendered into a page; every API call logged with passwords, session ids and sign-on tokens scrubbed. | Enforced by dedicated tests in the suite, and the least-privilege user is what the production install actually authenticates as. |

---

## 3. Built, but not yet proven live

This is the honest gap. All of it is written, unit-tested against a faithful stand-in for the panel's
API, and analysed clean — but none of it has been fully watched doing its job on the real system, and
in a billing module "the tests pass" is not the same claim as "the number is right".

| Item | What is unproven | Why it matters | Risk if we ship as-is |
|---|---|---|---|
| **Usage figures (the values)** | That disk, bandwidth, mailbox and database numbers match what the panel actually reports for a real customer, and that the megabyte conversions and "unlimited" conventions are right in both directions. | These four numbers land in the customer's WHMCS record and are what overage billing charges against. The panel speaks a different unit per field (some bytes, some megabytes, and "no limit" is `-1` in some places and `0` in others), so a conversion mistake is invisible until it appears on an invoice. | Wrong usage shown to customers; wrong overage charges. |
| **The nightly import under the real scheduler** | That WHMCS's own daily cron actually calls the module, that the module log records `updated` / `skipped` sensibly, and that a full sweep does not trip the panel's login lockout. | The import is designed to survive an unreachable panel (one probe, one login for the whole run) precisely because ten failed logins lock our WHMCS server out of the panel for five minutes. That design has never been watched running. | A nightly job that quietly does nothing, or that locks us out of our own panel every night. |
| **Usage-billing arithmetic** | That a metrics collection stores readings against the right service, and that a full billing cycle turns those readings into the invoice we expect. | Definitions are proven registered; readings and pricing are not. This is the only part of the module that can generate a wrong charge. | Incorrect invoices — the most expensive class of bug in a billing system. |
| **Single sign-on as a real customer experiences it** | An actual end customer clicking the button in the client area (as opposed to admin-side testing), and the refusal cases as they appear to that customer. | There is one operator step the design forces on us: after installing `ispconfig-sso` on the panel, the operator must open *Configuration > Servers* once, or the button never appears. That path has not been walked as a customer. | A support ticket on the first day of real use. |
| **The Servers-page hook across an upgrade** | That re-running the install script after a module update replaces the hook file correctly on a live install. | The hook file must be copied into WHMCS's own `includes/hooks/` directory, because WHMCS will not load it from inside the module (§6.1). Upgrades therefore need a repeat step. | The health block silently disappears after an update. |
| **Adoption of pre-existing accounts** | That a real migrated service — WHMCS service pointed at the ISPConfig server, ISPConfig account already there — is linked by the nightly sweep without anyone touching it, and that the module log's `adopted` list names the right account for the right service. The refusals and the manual buttons are unit-tested; the end-to-end path on real data is not. | This is the feature that decides whether migrating a customer is a two-field change or a per-service manual chore, and a wrong link would point suspend, terminate and usage at somebody else's hosting. Every safety rule is written to make a wrong link *impossible rather than unlikely* (link only on an exact single match, never inside a destructive action, never overwrite a stored id, never re-point a stale one), so the live check is about whether it links *at all* on real data as much as whether it links correctly. | Services that stay unmanaged, and the manual workaround comes back. A *wrong* link is the guarded-against case, not the expected one. |
| **What fills WHMCS's own "Accounts" figure** | Whether the second half of Sync Accounts (the step that imports and terminates services) is what populates that count. | Deliberately not tested: that step mutates real billing records, and running it out of curiosity on a production install is not a trade worth making (§6.3). | None. The module no longer depends on the answer — it puts its own freshly probed client count in that cell instead. Worth settling one day on a throwaway WHMCS. |

---

## 4. Not built

### 4.1 Design Phase 3 — "beyond-Plesk polish" (est. 4–6 dev-days, plus 1–3 weeks marketplace moderation)

| Item | Note |
|---|---|
| **Reseller products** | Selling accounts that can themselves create sub-accounts. Needs a "Reseller Template" product setting and a client limit on creation. |
| **Dedicated IP option** | Assigning a website a specific IP address from the panel's pool instead of "any". |
| **Keep DNS zone on terminate** | A product toggle to preserve a customer's DNS records when their service ends. cPanel-parity nicety; the *decision* about our own default matters sooner than the code (section 6). |
| **Contact sync** | Pushing WHMCS contact edits through to the panel automatically. |
| **Marketplace listing and launch** | Free to list, content-only moderation, one to three weeks. Includes coordinating with the ISPConfig project first, which is both courtesy and our best marketing channel. |
| **Docs site** | Currently the documentation is the three files in `docs/`. |

### 4.2 Design Phase 4 — customer-facing management screens (open-ended, demand-driven)

In-WHMCS screens for managing mailboxes, FTP accounts, DNS records and databases. Worth knowing:
**WHMCS's own bundled cPanel and Plesk modules do not ship these either** — that depth is third-party
"Extended" territory. So this is a differentiator, not a parity gap, and it should be driven by
customer demand rather than by the roadmap.

### 4.3 Known deferred items and small debts

| Item | Status | Effort |
|---|---|---|
| **Two unused internal classes** (`lib/Server/MetaDataPanel`, `lib/Server/ServerMetaData`) | They were the original renderer for the Servers-page block. Once WHMCS proved it never loads a module's own hook file, the live block moved to a self-contained file that cannot use them. They are kept as the clean, testable statement of the same contract, and their field list must stay in step with the hook file's — it has already drifted once. Keep-or-delete is a real decision, not an oversight. | 0.5 day either way |
| **`whmcs.json` version and feature list** | Currently reads `0.2.0`. It is the file the marketplace and WHMCS's own module list read, so it needs a real release version, an accurate feature list, and whatever compatibility fields the marketplace wants at submission time. | 0.5 day |
| **No continuous integration** | There is no automated build: the test suite and static analysis are run by hand. The design also called for a job that spins up a throwaway ISPConfig server and runs the full lifecycle against it — that would close most of section 3 permanently. | 1–2 days for the basic gate; 2–3 more for the throwaway-panel job |
| **No WHMCS developer licence** | We have exactly one WHMCS instance: production. That blocks testing against WHMCS 8.13, blocks any safe test of the destructive half of Sync Accounts, and means every live test happens on the real billing system. | 0.5 day plus licence cost |
| **The `rest.php` spike** | ISPConfig exposes a second, undocumented API endpoint alongside the one we use. The design allotted a day to characterise it and adopt it only if it offers something ours does not. Never done — and the endpoint we use is proven in production, so this is curiosity, not need. | 1 day, low priority |
| **`Renew` is intentionally absent** | ISPConfig has no renewal action to perform. Not a gap. | — |
| **The adoption matcher is a separate component** | The entry file consumes matching through a plain-data seam (`ispconfigconnect_adoptionResolver()`), which binds to `Adopt\Resolver::resolveMany()` when that class is present and reports "automatic adoption unavailable" when it is not. That means the wiring, the admin surfaces and every safety rule ship and pass tests independently of the matcher — but until `lib/Adopt` is in the tree, only the *manual* Link/Replace/Unlink buttons actually link anything. | Seam is done; the matcher is its own lane |
| **Two forum threads unread** | Two HowtoForge threads blocked automated reading during the original research; worth a manual skim before public launch in case private forks are circulating. | 30 minutes |
| **The wider family** | `ispconfig-connect-core` (shared code), and the Blesta and FOSSBilling connectors, remain plans in the design document. Both of those platforms already have maintained modules, so those tracks open with an "should we contribute upstream instead?" assessment. | Out of scope for now |

---

## 5. Constraints and decisions already taken

These are settled. They are recorded here so nobody spends a day rediscovering them.

**Commercial and licensing**

- **GPLv3**, for this module and for the separate `ispconfig-sso` project alike. Any fork or derivative must stay open, which
  forecloses the "encrypt it and resell it" scenario a permissive licence would allow.
- **The connector is a paid product with source available**; the primary revenue line is ISPConfig
  support subscriptions. The connector is the wedge; support is the business.
- **Full public endorsement of the upstream ISPConfig project.** We coordinate before launch, and we
  contribute fixes upstream rather than forking hostilely. The README leads with a recommendation to
  buy ISPConfig's own billing module if that suits the reader better.
- Still open from the design: whether a feature-limited free listing sits alongside the paid one
  (resolvable at marketplace submission).

**Technical constraints we cannot change**

- **One WHMCS service = one ISPConfig client.** Deliberate: it is what makes termination safe.
- **Plans live in ISPConfig**, as client templates, chosen per WHMCS product from a live dropdown —
  the same shape Plesk operators already know.
- **No administrator auto-login, by design.** A customer sign-on token is safe; an *admin* token would
  bypass password and IP checks and hand over the whole panel, so `ispconfig-sso` refuses to mint one
  and the module never advertises an admin login button. A button that could only ever fail is worse
  than no button.
- **WHMCS reserves its own Servers-page block for its bundled modules.** Proven by elimination: our
  row rendered nothing even when handed Plesk's exact data vocabulary, while Plesk's row rendered
  normally on the same page (§6.2). We render our own equivalent instead.
- **WHMCS does not load a server module's own hook file** (9.0.6, proven with a marker that never
  fired — §6.1). Hence the one operator copy step, and hence the need to repeat it after upgrades.
- **WHMCS's own "Accounts" count is a stored column no module can write**, and it is reset on every
  refresh, so for an ISPConfig server it would read `0` permanently (§6.3). Rather than leave a
  number on the page that is always wrong, the health block replaces it on our own rows with the
  client count it has just probed from the panel — presentational only, and only on our rows.
- **Sync Accounts must identify accounts by domain.** WHMCS rejects every other field with
  "Unsupported unique identifier field provided by module", and validates nothing beyond it — so a
  panel client with no website can never be matched, and the module supplies a deliberately
  impossible placeholder domain in that case so such an account is reported as unknown rather than
  matched to the wrong customer (§5.2, §6.6).
- **The panel works asynchronously.** An API call that succeeds means *accepted*, not *done* — the
  panel's own background job applies it moments later. The module surfaces the pending-job count so
  an operator can see this rather than be surprised by it.
- **The panel locks out an IP after ten failed logins in five minutes**, and expired sessions count
  toward that. Every retry decision in the module is shaped by this.
- **WHMCS core is encrypted**, so several of its behaviours can only be established by observation.
  §6 of the implementation notes is that record — five findings, each with the method used, so a
  future maintainer re-checks rather than re-derives them.

---

## 6. Suggested next steps

Two lists. The first is what stands between us and taking money from a real customer through this
module. The second is what stands between us and publishing it. Effort figures are working estimates,
not commitments.

### Before we provision real customers

| # | Step | Why now | Est. |
|---|---|---|---|
| 1 | **Check the usage numbers against one real service.** Compare what the module reports for disk, bandwidth, mailbox and database usage against what the panel shows for the same account, including an unlimited case and a limited one. | The highest-value item on the page: it is the shortest path to ruling out a wrong number appearing in front of a customer. | 1 day |
| 2 | **Watch one real nightly run.** Let WHMCS's own scheduler trigger the import; then read the module log for `updated` / `skipped`, and confirm no lockout. | Proves the design that exists specifically to avoid locking ourselves out of our own panel. | 0.5 day |
| 3 | **Rehearse one order end to end** on a dummy client: cart → paid invoice → account provisioned → welcome email → suspend → unsuspend → terminate. | The individual steps are proven; the sequence WHMCS drives them in is not. Also the only way to check the welcome email actually says something useful. | 0.5 day |
| 4 | **Walk single sign-on as a customer**, from the client area, on an account that is not an administrator — including the "open Configuration > Servers once after installing `ispconfig-sso`" step. | The first customer to click that button should not be the test. | 0.5 day |
| 4a | **Migrate one real customer and watch the module link it.** Point one already-migrated service at the ISPConfig server and an ISPConfig product, change nothing else, and check the *Module* tab's **ISPConfig Link** row and the next `UsageUpdate` log entry. | This is the feature the owner is actually blocked on — every Plesk migration is unmanaged until it works. One service proves it before the rest are moved. | 0.5 day |
| 5 | **Decide the terminate policy** — specifically whether a terminated customer's DNS zone is kept. Document the decision even though the toggle itself is Phase 3. | Cheaper to decide before the first real termination than after. | 0.5 day |
| 6 | **Only then**: enable usage-based billing, on one product, and let it run a full billing cycle before selling it. Compare the resulting invoice against the panel by hand. | The arithmetic is the last unproven money path. A cycle takes calendar time, so start it early even if nothing else waits on it. | 1 day + one billing cycle |

Steps 1–5 are roughly **three days of work**, and after them we can sell fixed-price hosting through
this module with a clear conscience. Step 6 gates usage-based billing only.

### Before public release

| # | Step | Why | Est. |
|---|---|---|---|
| 7 | **Continuous integration**: run the test suite and static analysis automatically on every change. | Right now "the tests pass" depends on somebody remembering to run them. A public module needs a gate that does not. | 1–2 days |
| 8 | **A WHMCS developer licence and a second instance.** | Unblocks WHMCS 8.13 compatibility testing, and lets us safely run the destructive half of Sync Accounts to settle the last open question — neither of which we will ever do on production. | 0.5 day + cost |
| 9 | **Release engineering**: real version number in `whmcs.json`, accurate feature list, changelog, tagged build, and a documented upgrade path (including re-copying the hook file). | This is what an installer downloads. It is currently `0.2.0` with no changelog. | 1 day |
| 10 | **Security and silent-failure review pass** before v1.0.0, as the design's review gates require. | This module holds panel credentials and writes to a live billing system. It should get the same bar the rest of the portfolio got — and reviewing is a separate pass from authoring. | 1–2 days |
| 11 | **Resolve the two unused internal classes** — keep them with a test that pins them to the live hook file's field list, or delete them. | Small, but it is the one thing in the tree a reviewer will legitimately question. | 0.5 day |
| 12 | **Coordinate with the ISPConfig project, then list.** Contact upstream, agree naming and framing, submit the marketplace listing, and settle the free-versus-paid listing shape. | Moderation takes one to three weeks, so submit early. The upstream conversation is a decision for the owner, not a task for a build agent. | 1 day + lead time |
| 13 | **Optionally, the throwaway-panel test job**: CI that installs a real ISPConfig and runs the whole lifecycle against it. | This is what would turn section 3 from "unproven" into "proven continuously", and would catch ISPConfig version drift before customers do. | 2–3 days |

Phase 3 features (reseller products, dedicated IP) sit **after** listing unless a customer asks for
them first; Phase 4 screens should wait for demand. Neither is on the critical path to revenue.
