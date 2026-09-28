# ISPConfig Connect for WHMCS

An open-source (GPLv3) **WHMCS provisioning module for [ISPConfig](https://www.ispconfig.org/) 3.x**
— bringing the same automated provisioning, suspension, and billing integration that WHMCS ships for
cPanel and Plesk to ISPConfig hosting servers.

> **Status:** the full provisioning lifecycle, account sync, single sign-on and the servers-page
> health block were built **and proven on a live ISPConfig 3.3.1p1 + WHMCS 9.0.6 install** — as
> the `build/phase-0-1` line (head `1ed05b3`). This branch adds a round of review fixes that change
> several of those paths (suspension, Change Package, Terminate, the link buttons, single sign-on
> gating, the hook installer and the servers-page script). It passes its offline test suite and
> static analysis, but **has not yet been run live**; a live re-verification is pending
> ([`docs/LIVE-TEST-PLAN.md`](docs/LIVE-TEST-PLAN.md)). Usage import and usage-billing metrics are
> built and their definitions are confirmed registered in WHMCS, but their **values have not yet
> been checked against a real service**, so usage-based billing is not ready to sell. Reseller
> products, dedicated IPs and client-area management screens are not started. Licensed under
> **GPLv3**.
>
> [`docs/ROADMAP.md`](docs/ROADMAP.md) is the full current-state account: what is proven and how it
> was verified, what is built but unproven, what is not built, and what happens next.

---

## A note on ISPConfig's own billing module

**This module is not a competitor to, or a replacement for, ISPConfig's first-party billing.** We are
wholehearted supporters of the ISPConfig project, and if you are choosing a billing system today we
encourage you to look first at ISPConfig's own tools:

- **[ISPConfig 3 Billing Module](https://www.ispconfig.org/add-ons/billing-module/)** — the official
  provider billing software, integrated directly into the ISPConfig control panel.
- **[ISPConfig Migration Toolkit](https://www.ispconfig.org/add-ons/ispconfig-migration-tool/)** —
  migrate existing accounts from Plesk (10–12.5, Onyx), cPanel (beta), Confixx, and older ISPConfig
  versions into ISPConfig 3. If your goal is to *leave* cPanel or Plesk behind, this is the tool that
  moves your accounts.

Buying these directly funds the ISPConfig project. Please do.

**So who is this module for?** Established hosting companies that **already run WHMCS** — often with
years of products, automations, domain registrars, support desks, and staff workflows built around it
— and want to **add ISPConfig to their fleet of server options** alongside their existing cPanel,
Plesk, or DirectAdmin servers. For them, "just switch billing systems" is not realistic, but "spin up
an ISPConfig box and sell it through the WHMCS we already operate" absolutely is. That gap — WHMCS
shops that want ISPConfig as a first-class server option — is the only thing this module exists to
fill. It is the missing commercial pillar of using ISPConfig as a cPanel/Plesk alternative, for people
whose billing layer happens to be WHMCS.

If you are starting fresh, or you are willing to move your billing into ISPConfig, the first-party
billing module above is very likely the better home for you.

---

## The two pieces, in thirty seconds

| Piece | Installed on | Required? |
|---|---|---|
| **This module** — a WHMCS provisioning module that creates, suspends, bills and terminates hosting accounts by talking to ISPConfig over its remote JSON API | your **WHMCS** server, in `modules/servers/ispconfigconnect/` | **Yes.** It is the product |
| **[`ispconfig-sso`](https://github.com/wadejbeckett/ispconfig-sso)** — a small standalone panel add-on (same author, GPLv3, **not shipped here**) that adds one remote function minting a one-time panel-login token, plus the page that redeems it | your **ISPConfig** server | **No.** It exists only because ISPConfig core has no login-token API. Install it if you want a password-free *Log in to ISPConfig* button for customers; skip it and everything else works unchanged |

Those are the only two moving parts — there is no WHMCS addon module, no admin dashboard, and
nothing of ours running as a daemon on either machine. (Within WHMCS this module also copies one
hook file into `includes/hooks/`, for the servers-page health block; that file is part of this
module, not a third component. See [Installation](#installation).)

Setting up the optional half is documented once, in that project's own README:
**[`ispconfig-sso` → Install and remove](https://github.com/wadejbeckett/ispconfig-sso#3-install-and-remove)**.
The WHMCS side needs no configuration for it — this module detects the add-on per server and shows
the button only where it is present.

## What it does

One WHMCS service maps to one ISPConfig client (mirroring how the cPanel module maps one service to
one account), so terminating a service only ever removes that service's own ISPConfig client — never
a customer's other accounts. The module enforces the mapping: linking, Terminate, Change Password,
Change Package and whole-client Suspend refuse a client that another live service on the same panel
is linked to ([`docs/INSTALL.md`](docs/INSTALL.md) §6).

### Provisioning lifecycle

Every action below was run end to end against a live ISPConfig 3.3.1p1 panel from a live
WHMCS 9.0.6 install, on the earlier `build/phase-0-1` build. The review fixes on this branch changed
several of these actions directly — Suspend/Unsuspend, Change Password, Change Package, Terminate,
Test Connection — and the API transport under all of them; none of that has been re-run live yet.

| WHMCS action | ISPConfig result |
|---|---|
| Create | Creates the ISPConfig client, then (per product config) website, FTP user, mail domain, DNS zone, and database — all-or-nothing, with automatic rollback on partial failure |
| Suspend / Unsuspend | Locks / unlocks the client, or (configurable) sets just this service's website inactive / active. Website-only mode needs the service's stored website id and refuses without one — it never locks the client instead |
| Change Password | Rotates the panel **and** the provisioned FTP and database passwords together |
| Change Package | Re-maps the client's ISPConfig template atomically, in one update. Refused while the client is locked by a suspension (unsuspend first); a suspended service is suspended again straight afterwards |
| Terminate | Deletes only this service's ISPConfig client; refuses a stale link or a client another service shares |
| Test Connection / Verify Permissions | Diagnoses connectivity and names any missing API grant |

Provisioning is driven by ISPConfig **client templates** — operators manage plans in ISPConfig exactly
as Plesk operators manage service plans — selected per WHMCS product from a live dropdown.

### The rest of the WHMCS surface

| WHMCS feature | What this module does | Needs | Live status |
|---|---|---|---|
| **Usage import** (nightly cron) | Writes disk and bandwidth usage plus their panel limits onto every service on the server, in megabytes | — | Built; **not yet observed under WHMCS's own cron**, and the values are not yet checked against a real service |
| **Usage-billing metrics** | Publishes nine metrics — disk, bandwidth, mail and database storage, and counts of websites, mailboxes, databases, FTP users and DNS zones | — | Definitions **confirmed live** (all nine render on the product's Module Settings tab with working *Configure Pricing* links); **values and the resulting invoices are unverified** |
| **Server Sync** ("Sync Accounts") | Lists every client on the panel with its primary domain, template and lock state, so WHMCS can reconcile them against its own services. A client that owns no website is listed under a reserved `.invalid` placeholder, so it is reported as an unknown account rather than matched to the wrong service | — | **Proven live** (analyse step). The step that imports or terminates services has deliberately not been run on production |
| **Servers page: Services count** | The number of ISPConfig clients on the panel — one per WHMCS service — which WHMCS renders itself as *Services: N* | — | **Proven live** |
| **Servers page: panel health** | Panel version, pending job-queue depth, ISPConfig client count, node count and probe errors, rendered under the server's name; failures show the reason instead of breaking the page | One copy step: WHMCS only loads hooks from `includes/hooks/` ([INSTALL §2](docs/INSTALL.md)) | **Proven live** |
| **Single sign-on** | A password-free *Log in to ISPConfig* button for customers of **Active** services, using a single-use, short-lived token | The separate **`ispconfig-sso`** project installed on the panel (optional; not shipped here) | **Proven live** with that project installed on the panel; not yet walked through by a real customer |
| **Admin service tab** | Stored ISPConfig ids plus live job-queue depth, current disk and bandwidth usage, and SSO availability | — | **Proven live** |

The *Live status* column describes the earlier `build/phase-0-1` build (see the status note at the
top); this branch's changes to these surfaces await the live re-verification.

Everything above degrades rather than breaks: a panel that is down costs a placeholder value, never
an admin page, a failed cron run, or a lost provisioning result.

Two figures on that page originate with **WHMCS, not the module**, and are easy to misread.
*Accounts* is printed from a cached column that no module can write — for an ISPConfig server it
would read `0` forever, so the hook replaces it on our own rows with the client count just probed
from the panel, wherever it can identify the row by the server's own link. *WHMCS Usage Stats* —
the `1/200` — is WHMCS counting the services it has assigned to the server against that server's
*Maximum No. of Accounts* field, and reports nothing about the panel at all.
[`docs/INSTALL.md`](docs/INSTALL.md) §7.4 breaks down every number on the page.

### Why single sign-on needs something on the panel

ISPConfig core has no one-time-login API — `client_login_get()` only validates a password, and the
remote API's `login()` mints an API session rather than a panel session. Every abandoned
WHMCS/ISPConfig module solved this the only way core allowed: by storing the customer's panel password
and replaying it. **This module never stores or replays a password.** That is the whole reason the
optional [`ispconfig-sso`](https://github.com/wadejbeckett/ispconfig-sso) half exists, and the
reason it is deliberately not a WHMCS component: any billing system can drive the same function.

Single sign-on is therefore **optional and self-configuring**. This module probes each server once
per servers-page render (`get_function_list`) and offers the button only where the add-on answers;
on every other panel customers simply get the panel URL and their username, and nothing is broken
by its absence. Setup lives in that project's README — see
[`docs/INSTALL.md` §7.5](docs/INSTALL.md) for the one WHMCS-side detail and the link.

## Security posture

This module holds panel credentials and runs on a production billing system, so it is built to a
higher bar than the abandoned modules it replaces:

- Authenticates as a **least-privilege remote user** with a documented, minimal grant list — never the
  panel admin account. See [`docs/REMOTE-USER.md`](docs/REMOTE-USER.md).
- **TLS verification on by default**, with an explicit opt-out and custom-CA support for self-signed
  panels.
- **No credential is ever rendered** into the client area or admin HTML, and no panel password is
  ever stored or replayed — single sign-on uses single-use tokens instead.
- Every API call is logged through WHMCS's module log with password, session and SSO-token values
  scrubbed; the SSO redirect URL, which carries a token, is never logged at all.
- Session handling respects ISPConfig's IP-bound sessions and login-failure lockout, and never retries
  into a lockout.
- The server's Hostname is accepted only as a bare host name or IP address, because it is built
  straight into the URL that carries the remote-API password.

## Requirements

- WHMCS 8.13 or 9.x. Verified in use on **9.0.6**; 8.13 is expected to work but has not been
  tested (see [`docs/ROADMAP.md`](docs/ROADMAP.md))
- PHP ≥ 8.2 (8.2 and 8.3 verified) with **ext-curl** and **ext-ctype**. **ext-intl** is recommended:
  without it an internationalised server hostname must be entered in punycode (`xn--`) form, and
  internationalised customer domains are not converted. ext-mbstring is optional
- ISPConfig **3.2 or newer**, with the remote API enabled. All live verification to date has been
  against **3.3.1p1**; 3.2 support rests on API signatures that have been stable for years, not on
  testing.

## Installation

Copy `modules/servers/ispconfigconnect/` into your WHMCS `modules/servers/` directory. The panel
health block on the servers page needs one extra step, because WHMCS loads hook files only from
`includes/hooks/`:

```sh
modules/servers/ispconfigconnect/hooks/install-hook.sh /path/to/whmcs
```

Run it as root or as the owner of `includes/hooks`, from any directory; as root it makes the change
as that directory's owner. Confirm with `--status`, which should say *installed and up to date*.
`--uninstall` removes the file again. When upgrading, replace the whole module directory and re-run
the installer — [`docs/INSTALL.md`](docs/INSTALL.md) §2, *Upgrading from an earlier build*, lists
the behaviour changes to check.

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full walk-through and
[`docs/REMOTE-USER.md`](docs/REMOTE-USER.md) for creating the least-privilege ISPConfig remote user.
For the optional single sign-on button, the setup path is
[`ispconfig-sso` → *Install and remove*](https://github.com/wadejbeckett/ispconfig-sso#3-install-and-remove),
run on the ISPConfig server. [`docs/INSTALL.md`](docs/INSTALL.md) §7.5 carries the one WHMCS-side
detail (which grant checkbox to tick) and the troubleshooting for it.

## Development

The shipped module is dependency-free plain PHP. The repository additionally carries a PHPUnit test
suite (1033 tests) that runs against a behavioral double of the ISPConfig remote API modeled directly
on the ISPConfig source, plus PHPStan level 6 static analysis. (The `ispconfig-sso` project carries
its own separate harness in its own repository.) The suite and the static analysis are currently run
by hand — there is no CI yet. See [`docs/IMPLEMENTATION-NOTES.md`](docs/IMPLEMENTATION-NOTES.md) — its §5
records the WHMCS-side contracts, including the ones that are not documented anywhere and had to be
established on a live install, and its §6 is the settled, evidence-backed account of what the WHMCS
servers page does and does not let a third-party module do. For project status and what is planned
next, see [`docs/ROADMAP.md`](docs/ROADMAP.md).

## License

GPLv3 — see [`LICENSE`](LICENSE). Any fork or derivative stays open.

## Acknowledgements

Built on, and in support of, the [ISPConfig](https://www.ispconfig.org/) project by Till Brehm and
the ISPConfig community.

---

Part of the **ISPConfig Toolkit**, a family of independent ISPConfig tools by the same author:
[ispconfig-sso](https://github.com/wadejbeckett/ispconfig-sso) ·
[ISPConfig Theme Customizer](https://github.com/wadejbeckett/ispconfig-theme-customizer).
