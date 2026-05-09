# Virtualmin Extension for Paymenter

Provisions and manages web hosting accounts on a **Virtualmin** cluster via the [Virtualmin Remote API](https://www.virtualmin.com/docs/development/remote-api/).

Built for [Paymenter](https://paymenter.org/) by emollusion / [sa6bom.se](https://sa6bom.se).

---

## Architecture

```
Paymenter (this extension)
    │
    │  HTTPS + Basic Auth (port 10000)
    ▼
Virtualmin Master Node
    │
    │  Webmin Cluster (internal, managed by Virtualmin)
    ├──▶ Virtualmin Node 2
    └──▶ Virtualmin Node N
```

Paymenter talks exclusively to the master node. Cluster distribution is handled transparently by Virtualmin. This extension has no multi-node awareness by design.

---

## Installation

1. Place this directory at:
   ```
   extension/Servers/Virtualmin/
   ```

2. In the Paymenter admin panel, navigate to **Extensions → Servers** and enable **Virtualmin**.

3. The extension will automatically run its database migration to create the `virtualmin_accounts` table.

4. Configure the extension with your Virtualmin master node credentials (see Configuration below).

---

## Virtualmin Prerequisites

### API User

Create a dedicated Webmin user for API access (do **not** use root):

1. Webmin → Webmin Users → Create a new Webmin user
2. Under **Available Modules**, grant access to: **Virtualmin Virtual Servers**
3. Note the username and password for the extension config

### Account Plans

Create Account Plans in Virtualmin that match the plan names you will enter in Paymenter product configurations:

- Virtualmin → Account Plans → Add a Plan
- Example: `Starter`, `Business`, `Pro`

### Server Templates

Optionally create Server Templates for fine-grained defaults:

- Virtualmin → Server Templates → Add a Template
- Example: `Shared Hosting`, `Reseller`

---

## Extension Configuration

| Field | Description | Default |
|---|---|---|
| Virtualmin Master Host | Hostname or IP of the master node | — |
| Webmin Port | Port Webmin listens on | `10000` |
| Usermin Port | Port Usermin listens on (customer link) | `20000` |
| API Username | Webmin user with Virtualmin module access | — |
| API Password | Password for the API user | — |
| Verify TLS Certificate | Disable only for self-signed certs | `true` |

---

## Product Configuration

Each Paymenter product maps to a Virtualmin Account Plan with optional resource overrides.

| Field | Description |
|---|---|
| Virtualmin Account Plan | Exact name of the plan in Virtualmin |
| Virtualmin Server Template | Exact name of the template (optional) |
| Feature toggles | Web, SSL, DNS, Mail, MySQL, FTP, SSH, Webmin, LogRotate, Spam, Virus |
| Disk Quota (MB) | Override plan quota. Blank = plan default |
| Bandwidth Quota (MB) | Override plan bandwidth. Blank = plan default |
| Max Sub-Domains | Override max sub-domains |
| Max Alias Domains | Override max alias domains |
| Max Mailboxes | Override max mailboxes |
| Max Databases | Override max databases |

### Recommended plan structure

Define tiers in Virtualmin, then create matching Paymenter products:

| Paymenter Product | Virtualmin Plan | Disk | BW | Mailboxes | DBs |
|---|---|---|---|---|---|
| Starter | Shared Hosting | 5120 | 51200 | 5 | 2 |
| Business | Shared Hosting | 20480 | 204800 | 25 | 10 |
| Pro | Shared Hosting | 51200 | unlimited | unlimited | unlimited |

---

## Customer Panel

When a service is active, the customer sees a **Manage Hosting** panel showing:

- Domain name
- Username
- Password (blurred, click to reveal)
- Account status
- **Open Usermin Control Panel** button (links to `https://host:20000/`)

The customer logs into Usermin with their username and password to manage email, files, and settings.

---

## Lifecycle

| Paymenter Event | Virtualmin Action |
|---|---|
| Order paid / service created | `create-domain` |
| Service suspended | `disable-domain` |
| Service unsuspended | `enable-domain` |
| Service terminated | `delete-domain` |
| Plan upgrade/downgrade | `modify-domain --apply-plan` |

---

## Future: Domain Resale

This extension is designed as a self-contained puzzle piece. When domain resale is added to Morén IT, implement it as a separate Paymenter extension (or gateway extension) that hooks into the domain checkout flow. No changes to this extension will be required.

---

## Notes

- Virtualmin derives the Unix username from the domain name (first label, max 8 chars, alphanumeric only). The displayed username is computed client-side; if Virtualmin assigns a different username due to conflicts, update the `virtualmin_accounts` record manually or add a post-creation lookup.
- Passwords are stored encrypted using Laravel's `Crypt` facade (AES-256-CBC with the app key).
- The Virtualmin API occasionally returns `status: success` with error text in `output`. The `VirtualminClient` inspects the output for known error patterns and throws accordingly.
