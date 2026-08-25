# ISPConfig Connect for WHMCS

A free, open-source **WHMCS provisioning module for [ISPConfig](https://www.ispconfig.org/) 3.x** —
bringing the same automated provisioning, suspension, and billing integration that WHMCS ships for
cPanel and Plesk to ISPConfig hosting servers.

> **Status:** Phase 1 (full provisioning lifecycle) and Phase 2 (usage import, usage-billing metrics,
> account sync, server health, optional single sign-on) complete; Phase 1 verified against a live
> ISPConfig 3.3.1p1 + WHMCS 9.0.6 server. Licensed under **GPLv3**.

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

| WHMCS feature | What this module does | Needs |
|---|---|---|
| **Usage import** (nightly cron) | Writes disk and bandwidth usage plus their panel limits onto every service on the server, in megabytes | — |
| **Usage-billing metrics** | Publishes nine metrics — disk, bandwidth, mail and database storage, and counts of websites, mailboxes, databases, FTP users and DNS zones | — |
| **Server Sync** ("Sync Accounts") | Lists every client on the panel with its primary domain, template and lock state, so WHMCS can reconcile them against its own services. A client that owns no website is listed under a reserved `.invalid` placeholder, so it is reported as an unknown account rather than matched to the wrong service | — |
| **Servers page: Services count** | The number of websites on the panel, which WHMCS renders itself as *Services: N* | — |
| **Servers page: panel health** | Panel version, pending job-queue depth, ISPConfig client count, node count and probe errors, rendered under the server's hostname; failures show the reason instead of breaking the page | One copy step: WHMCS only loads hooks from `includes/hooks/` ([INSTALL §2](docs/INSTALL.md)) |
| **Single sign-on** | A password-free *Log in to ISPConfig* button for customers, using a single-use, short-lived token | Optional [`companion/ispconfig-sso`](companion/ispconfig-sso/README.md) |
| **Admin service tab** | Stored ISPConfig ids plus live job-queue depth, current disk and bandwidth usage, and SSO availability | — |

Everything above degrades rather than breaks: a panel that is down costs a placeholder value, never
an admin page, a failed cron run, or a lost provisioning result.

Two figures on that page are **WHMCS's own, not the module's**, and are easy to misread: *Accounts* is
a cached count that a **Sync Accounts** run fills in (blank until you run one), and *WHMCS Usage
Stats* — the `1/200` — is WHMCS counting the services it has assigned to the server against that
server's *Maximum No. of Accounts* field. Neither reports anything about the panel.
[`docs/INSTALL.md`](docs/INSTALL.md) §6.4 breaks down every number on the page.

### Why single sign-on needs a companion

ISPConfig core has no one-time-login API — `client_login_get()` only validates a password, and the
remote API's `login()` mints an API session rather than a panel session. Every abandoned
WHMCS/ISPConfig module solved this the only way core allowed: by storing the customer's panel password
and replaying it. **This module never stores or replays a password.** Instead, the optional GPLv3
companion adds one remote function that mints a single-use token and one page that redeems it, without
modifying a single ISPConfig core file. Install it and the login button appears; leave it out and the
button is never rendered.

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

- WHMCS 8.13 or 9.x (PHP 8.2 / 8.3)
- ISPConfig **3.2 or newer** (CI-tested against 3.3.1p1), with the remote API enabled

## Installation

Copy `modules/servers/ispconfigconnect/` into your WHMCS `modules/servers/` directory. The panel
health block on the servers page needs one extra step, because WHMCS loads hook files only from
`includes/hooks/`:

```sh
modules/servers/ispconfigconnect/hooks/install-hook.sh /path/to/whmcs
```

Re-run it after a module upgrade; `--uninstall` removes the file again.

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full walk-through,
[`docs/REMOTE-USER.md`](docs/REMOTE-USER.md) for creating the least-privilege ISPConfig remote user,
and [`companion/ispconfig-sso/README.md`](companion/ispconfig-sso/README.md) if you want the single
sign-on button.

## Development

The shipped module is dependency-free plain PHP. The repository additionally carries a PHPUnit test
suite (539 tests) that runs against a behavioral double of the ISPConfig remote API modeled directly
on the ISPConfig source, plus PHPStan level 6 static analysis. See
[`docs/IMPLEMENTATION-NOTES.md`](docs/IMPLEMENTATION-NOTES.md) — its §5 records the WHMCS-side
contracts, including the ones that are not documented anywhere and had to be established on a live
install, and its §6 is the settled, evidence-backed account of what the WHMCS servers page does and
does not let a third-party module do.

## License

GPLv3 — see [`LICENSE`](LICENSE). Any fork or derivative stays open.

## Acknowledgements

Built on, and in support of, the [ISPConfig](https://www.ispconfig.org/) project by Till Brehm and
the ISPConfig community.
