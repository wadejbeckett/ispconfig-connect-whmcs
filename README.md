# ISPConfig Connect for WHMCS

A free, open-source **WHMCS provisioning module for [ISPConfig](https://www.ispconfig.org/) 3.x** —
bringing the same automated provisioning, suspension, and billing integration that WHMCS ships for
cPanel and Plesk to ISPConfig hosting servers.

> **Status:** Phase 1 complete (full provisioning lifecycle), verified against a live ISPConfig
> 3.3.1p1 server. Phase 2 (usage metrics, account sync, single sign-on) in progress. Licensed under
> **GPLv3**.

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

## What it does (Phase 1)

One WHMCS service maps to one ISPConfig client (mirroring how the cPanel module maps one service to
one account), so terminating a service only ever removes that service's own ISPConfig client — never
a customer's other accounts.

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

## Security posture

This module holds panel credentials and runs on a production billing system, so it is built to a
higher bar than the abandoned modules it replaces:

- Authenticates as a **least-privilege remote user** with a documented, minimal grant list — never the
  panel admin account. See [`docs/REMOTE-USER.md`](docs/REMOTE-USER.md).
- **TLS verification on by default**, with an explicit opt-out and custom-CA support for self-signed
  panels.
- **No credential is ever rendered** into the client area or admin HTML.
- Every API call is logged through WHMCS's module log with password and session values scrubbed.
- Session handling respects ISPConfig's IP-bound sessions and login-failure lockout, and never retries
  into a lockout.

## Requirements

- WHMCS 8.13 or 9.x (PHP 8.2 / 8.3)
- ISPConfig **3.2 or newer** (CI-tested against 3.3.1p1), with the remote API enabled

## Installation

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full walk-through, and
[`docs/REMOTE-USER.md`](docs/REMOTE-USER.md) for creating the least-privilege ISPConfig remote user.

## Development

The shipped module is dependency-free plain PHP. The repository additionally carries a PHPUnit test
suite (200+ tests) that runs against a behavioral double of the ISPConfig remote API modeled directly
on the ISPConfig source, plus PHPStan static analysis. See [`docs/IMPLEMENTATION-NOTES.md`](docs/IMPLEMENTATION-NOTES.md).

## License

GPLv3 — see [`LICENSE`](LICENSE). Any fork or derivative stays open.

## Acknowledgements

Built on, and in support of, the [ISPConfig](https://www.ispconfig.org/) project by Till Brehm and
the ISPConfig community.
