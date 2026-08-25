# ISPConfig Connect for WHMCS

An open-source (GPLv3) **WHMCS provisioning module for [ISPConfig](https://www.ispconfig.org/) 3.x**
— bringing the same automated provisioning, suspension, and billing integration that WHMCS ships for
cPanel and Plesk to ISPConfig hosting servers.

> **Status:** the full provisioning lifecycle, account sync, single sign-on and the servers-page
> health block are built **and proven on a live ISPConfig 3.3.1p1 + WHMCS 9.0.6 install**. Usage
> import and usage-billing metrics are built and their definitions are confirmed registered in
> WHMCS, but their **values have not yet been checked against a real service**, so usage-based
> billing is not ready to sell. Reseller products, dedicated IPs and client-area management screens
> are not started. Licensed under **GPLv3**.
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

## What it does

One WHMCS service maps to one ISPConfig client (mirroring how the cPanel module maps one service to
one account), so terminating a service only ever removes that service's own ISPConfig client — never
a customer's other accounts.

### Provisioning lifecycle

Every action below has been run end to end against a live ISPConfig 3.3.1p1 panel from a live
WHMCS 9.0.6 install.

| WHMCS action | ISPConfig result |
|---|---|
| Create | Creates the ISPConfig client, then (per product config) website, FTP user, mail domain, DNS zone, and database — all-or-nothing, with automatic rollback on partial failure |
| Suspend / Unsuspend | Locks / unlocks the client (or just the website, configurable) |
| Change Password | Rotates the panel **and** the provisioned FTP and database passwords together |
| Change Package | Re-maps the client's ISPConfig template atomically |
| Terminate | Deletes only this service's ISPConfig client |
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
| **Servers page: panel health** | Panel version, pending job-queue depth, ISPConfig client count, node count and probe errors, rendered under the server's hostname; failures show the reason instead of breaking the page | One copy step: WHMCS only loads hooks from `includes/hooks/` ([INSTALL §2](docs/INSTALL.md)) | **Proven live** |
| **Single sign-on** | A password-free *Log in to ISPConfig* button for customers, using a single-use, short-lived token | The separate **`ispconfig-sso`** project installed on the panel (optional; not shipped here) | **Proven live** with that project installed on the panel; not yet walked through by a real customer |
| **Admin service tab** | Stored ISPConfig ids plus live job-queue depth, current disk and bandwidth usage, and SSO availability | — | **Proven live** |

Everything above degrades rather than breaks: a panel that is down costs a placeholder value, never
an admin page, a failed cron run, or a lost provisioning result.

Two figures on that page originate with **WHMCS, not the module**, and are easy to misread.
*Accounts* is printed from a cached column that no module can write — for an ISPConfig server it
would read `0` forever, so the hook replaces it on our own rows with the client count just probed
from the panel. *WHMCS Usage Stats* — the `1/200` — is WHMCS counting the services it has assigned
to the server against that server's *Maximum No. of Accounts* field, and reports nothing about the
panel at all. [`docs/INSTALL.md`](docs/INSTALL.md) §6.4 breaks down every number on the page.

### Why single sign-on needs something on the panel

ISPConfig core has no one-time-login API — `client_login_get()` only validates a password, and the
remote API's `login()` mints an API session rather than a panel session. Every abandoned
WHMCS/ISPConfig module solved this the only way core allowed: by storing the customer's panel password
and replaying it. **This module never stores or replays a password.**

The password-free button instead needs one small piece of code on the ISPConfig side, and that piece
is **its own project — `ispconfig-sso` (same author, GPLv3), which this repository does not ship.**
It is a standalone ISPConfig add-on, deliberately not tied to WHMCS: it
adds one remote function that mints a single-use, short-lived token and one page that redeems it,
modifying no ISPConfig core file, and *any* billing system — WHMCS, Blesta, FOSSBilling, something
in-house — can drive it.

Single sign-on here is therefore **optional and off by default**. This module detects the project at
runtime (one `get_function_list` probe) and shows the button only on panels that have it; on every
other panel customers simply get the panel URL and their username, and nothing is broken by its
absence.

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

## Requirements

- WHMCS 8.13 or 9.x (PHP 8.2 / 8.3). Verified in use on **9.0.6**; 8.13 is expected to work but has
  not been tested (see [`docs/ROADMAP.md`](docs/ROADMAP.md))
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

Re-run it after a module upgrade; `--uninstall` removes the file again.

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full walk-through and
[`docs/REMOTE-USER.md`](docs/REMOTE-USER.md) for creating the least-privilege ISPConfig remote user.
If you want the single sign-on button, install the separate `ispconfig-sso` project on the ISPConfig
server as well and follow its own README — [`docs/INSTALL.md`](docs/INSTALL.md) §6.5 covers the WHMCS
side of that.

## Development

The shipped module is dependency-free plain PHP. The repository additionally carries a PHPUnit test
suite (755 tests) that runs against a behavioral double of the ISPConfig remote API modeled directly
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
