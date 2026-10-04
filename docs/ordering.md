# EdgeIX Customer Ordering — Workflow Spec

This document describes the planned customer self-service ordering flows and
the current state of implementation. It is the ENGINEERING spec — for the
operational how-to (ops/admin staff), see
`docs/edgeix-port-ordering-staff-guide.md`.

## Current state (Phase 2 MSA gate — 2026-10-01)

- **"Order Port" button** in the customer admin nav — orange CTA on the right
  side, next to My Account.
- Clicking it goes to `/order` → a "Coming Soon" page describing the three
  planned flows and a `sales@edgeix.net` fallback for now.
- **The MSA gate is enforced**: the `/order` route group is wrapped in the
  `msa` middleware (`EnsureMsaSigned`). See below.

Only `custadmin` users see the button — regular `custuser` accounts don't have
authority to order.

## MSA gate (Phase 2 — built 2026-10-01)

**Design: ONE gate, at order time, uniform for everyone.** New signups log in
and use the portal freely (dashboards, users, contacts); the MSA check fires
when they first try to order. Existing customers with no MSA logged in the
system hit the same gate at their next order — the design is self-retroactive,
no backfill of the existing book is needed.

### End-to-end journey

```
/signup (or admin-created)        existing customer
        │                                │
        ▼                                ▼
   log in, use portal freely (dashboards, users, contacts, graphs)
        │
        ▼
   click "Order Port"  ──────  msa middleware checks cust.msa_status
        │                                │
        │ signed                         │ not signed
        ▼                                ▼
     /order                            /msa
  (order flows)              ┌───────────┴───────────┐
                             │ standard              │ custom
                             ▼                       ▼
                   acceptance flow            "agreement being
                   (interim: contact           finalised — contact
                   sales; later:               sales"
                   SignNow e-sign)
                             │
                             ▼
                   admin records / webhook stamps MSA → ordering enabled
```

### Day-to-day workflows

**New signup wants to order (standard MSA).** They hit Order Port, land on
`/msa`, and are told to email `sales@edgeix.net`. Sales sends the standard MSA
for signature (manual for now). Once executed, a superuser opens the customer
overview → edit dropdown → **Record MSA...**, sets status *Signed*, date, and
uploads the PDF (or notes where it's held). The customer's next visit to Order
Port goes straight through. When SignNow lands, this whole loop becomes
self-service and the admin step disappears.

**Existing customer with a historic MSA hits the gate.** Same screen, same
fix: find the executed agreement, Record MSA with the original execution date,
upload the scan if one exists — otherwise record signatory + where the paper
copy lives in notes. This can also be done proactively for known accounts
before they ever hit the gate; nothing requires waiting for the customer to be
blocked first.

**Customer negotiates custom terms.** As soon as negotiation starts, Record
MSA with type *Custom* and status *Unsigned* — from then on the customer sees
"agreement being finalised" at the gate instead of being told to sign the
standard MSA. When the custom agreement is executed, come back and set status
*Signed* (+ date, PDF/notes). Custom customers are permanently exempt from
standard-terms version re-consent.

**Revoking ordering.** Set status back to *Unsigned* on the Record MSA screen.
The signed date is cleared; type, notes and any uploaded document are kept.

**What the customer sees at each state** (on `/msa`):

| `msa_status` | `msa_type` | Customer sees |
|---|---|---|
| signed | any | Green "MSA in place" panel, executed date, PDF download (if on file), Continue to ordering |
| unsigned/pending/declined | custom | Blue "agreement being finalised" — contact sales |
| unsigned/pending/declined | standard | Amber "MSA required" — contact sales (later: embedded e-sign) |

### Customer side

- `EnsureMsaSigned` middleware (registered as `msa`) wraps the `/order` route
  group only. Superusers bypass. No executed MSA on record
  (`cust.msa_status != 'signed'`) → redirect to `/msa`.
- `/msa` (`EdgeIX\MsaController`, view `resources/skins/edgeix/msa/index.foil.php`)
  branches on customer state:
  - **Signed** (standard or custom) → status panel: executed date, signatory
    (for e-signed MSAs), download link when the PDF is in the docstore, and a
    "Continue to ordering" button.
  - **Custom type, not signed** → "agreement being finalised — contact sales".
    Customers negotiating bespoke terms are never offered the standard flow.
  - **Standard, not signed** → acceptance path. Interim: instructions +
    contact `sales@edgeix.net`. This panel becomes the embedded e-signature
    flow (SignNow) once the provider account exists.

### Admin side — Record MSA

`/admin/customer/msa/{cust}` (`EdgeIX\MsaAdminController`, superuser-only,
linked from the customer overview edit dropdown as "Record MSA..."). This is
the tool for the existing book: enable and/or upload MSAs executed manually to
date.

- Sets `msa_status` (signed/unsigned), `msa_type` (standard/custom),
  `msa_signed_at`, `msa_notes`.
- Optional PDF upload → stored in the customer's docstore under an
  **Agreements** directory with `min_privs = custadmin`, so the customer's own
  admins can download their executed MSA from `/msa`.
- A signed record requires evidence: an uploaded PDF, one already on file, or
  notes recording the signatory and where the executed copy is held (historic
  paper MSAs may not be scannable).
- `msa_signed_by_user_id` / `msa_signature_provider_id` are reserved for the
  e-sign flow — manual records keep the signatory in notes.

### E-signature integration (planned — blocked on provider account)

When the e-sign provider (SignNow or similar) is wired in, the standard
unsigned panel on `/msa` becomes an embedded signing flow:

1. Create a document from the pre-uploaded MSA template with prefilled
   fields (company name, rep, ASN, date); embed the signing URL.
2. On the provider's completion webhook, **the API pulls down the executed
   PDF and stores it locally** in the customer's docstore under
   *Agreements* (min_privs custadmin) — the exact same storage path the
   admin Record MSA screen uses. The provider is a signing ceremony, NOT
   the system of record: we never depend on the provider's portal to
   retrieve an executed agreement later.
3. Stamp the same columns as a manual record — `msa_status=signed`,
   `msa_signed_at`, `msa_document_id` — plus the two reserved for e-sign:
   `msa_signed_by_user_id` (the portal user who signed) and
   `msa_signature_provider_id` (the provider's document reference, for
   audit cross-checks).

**Invariant: both paths (staff upload / e-sign) converge on the identical
end state** — local executed PDF + stamped cust columns — so the order
gate, the `/msa` status page and the audit trail never care which route
an agreement took.

### Exempt customer types (2026-10-03)

**Internal** and **pro-bono** customers have no commercial agreement to
execute, so the gate waves them through: `Customer::msaRequired()` returns
false for the types in `config('ordering.msa_exempt_cust_types')` (defaults:
`TYPE_INTERNAL`, `TYPE_PROBONO`). Exempt customers visiting `/msa` see a
"no agreement required" panel; the customer-overview dropdown shows an
*exempt* badge instead of *unsigned*; the admin Record MSA screen notes the
exemption (recording an agreement for the file is still possible — it just
isn't what enables ordering).

### Custom MSAs

Some customers negotiate custom terms (`cust.msa_type = 'custom'`). These are
executed externally and admin-recorded only. Custom MSAs are exempt from
standard-terms version re-consent (their terms don't track
`SIGNUP_TERMS_VERSION`). While in negotiation (custom + unsigned), the `/msa`
page shows "agreement being finalised" instead of the acceptance flow.

### Schema

`cust` columns (migrations `2026_07_01`, `2026_07_02`, `2026_10_01`):
`msa_status` ('unsigned'|'pending'|'signed'|'declined', default 'unsigned'),
`msa_type` ('standard'|'custom', default 'standard'), `msa_signed_at`,
`msa_signed_by_user_id`, `msa_document_id` (docstore file),
`msa_signature_provider_id`, `msa_notes`, `terms_version_accepted`.
Model constants + helpers on `Customer`: `MSA_STATUS_*`, `MSA_TYPE_*`,
`msaSigned()`, `msaDocument()`, `msaSignedBy()`.

## Planned order sub-flows

The `/order` landing page will branch into three distinct sub-flows. Each has
different data capture, validation, and provisioning steps — but all three
run on the SAME orders chassis (`port_order.kind` = `new_port` | `add_lag` |
`upgrade`, with `target_virtual_interface_id` pointing at the existing
service for the latter two): one table, one state machine, one reservation
engine, one LOA path. What differs per kind (2026-10-03):

| | new_port (built) | add_lag | upgrade |
|---|---|---|---|
| Port picker | diversity-preferred across switches | **constrained to the LAG's own switch** | new type, prefer same switch |
| Provisioning | full recipe (VI+PI+VLI+IPs) | add PI(s) to the existing VI — no new IPs/VLI | new PI + IP swing + cutover window + old port decommission |
| Service impact | none | none when already LACP (conversion case shrinks as single ports ship as single-member LAGs) | peering interrupted during the swing |

### 1. New Port

Customer wants a new **peering** port at an existing or new EdgeIX location.
(Scope decision 2026-10-02: peering ports only for now — we are not selling
access-only ports yet.)

**Wizard flow (refined 2026-10-02):**

1. **Which IX** (metro/infrastructure: Sydney / Melbourne / Brisbane / Perth /
   Adelaide / Hobart / Darwin).
2. **Which DC** within that IX (only DCs with sellable stock shown, or with a
   lead-time note when prewired stock is exhausted).
3. **Port type** — from the admin-defined port-type catalogue (10G LR, 40G LR,
   100G LR4, 100G LR, 400G LR4; 25G later), filtered to types with stock at
   the chosen DC.
4. **Quantity** — minimum 1 port; selecting 2+ makes it an LACP LAG, all
   members on the same switch/location. (Delivery note: consider provisioning
   single ports as single-member LAGs so add-to-LAG later needs no
   maintenance window.)
5. **Tagged yes/no** — if tagged, **VLAN ID** (untagged provisions with
   vlan tag 0, no 802.1q framing).
6. **MAC address(es)** — up to 2 per MSA Schedule A; optional at order time,
   but the form must make clear **provisioning will not complete until the
   MAC is provided** (portal nags on the order-status page until it is).
7. **Delivery contact** + optional PO number + preferred go-live.

**Not asked — provisioned by default for everyone** (decision 2026-10-02):
IPv4 + IPv6 are both allocated whether the customer intends to use them or
not; route-server client config is always generated; IRRDB filtering always
applied. Opt-outs are an admin action after the fact, not an order-form
choice. See the provisioning recipe below for the exact defaults.

**Dynamic constraints (Phase 3):**
- The form must show only available PoPs and speeds at the chosen metro.
- Free/prewired port availability influences lead time — surface this to the
  customer.
- New locations (no existing customer footprint there) may require additional
  provisioning info like cross-connect vendor and cage details.

### 2. Add to LAG

Customer wants to add capacity to an existing service by bundling another port.

**Captured:**
- Which existing port they're adding to.
- Desired speed of the new member (must match existing LAG member speed).

**Two branches:**
- **Target port is already a LAG** → just add another member port. No service
  interruption.
- **Target port is NOT a LAG** → conversion required. Existing port becomes
  the first member of a new LAG. This can involve a brief maintenance window
  and needs to be captured explicitly so admins/customers agree on cutover
  timing.

The form should detect and display which case applies before submission.

### 3. Upgrade Port

Customer wants to swap an existing port for a higher speed at the same DC.

Different workflow from "new port" because the existing IP addresses (IPv4 +
IPv6) need to swing across to the new port. This means:

- Coordinated cutover window.
- Explicit acknowledgement that peering will be interrupted during the swing.
- Old port decommissioned after the new one is live.

**Captured:**
- Existing port to upgrade.
- Target speed.
- Preferred cutover window.

## Path to auto-LOA fulfilment — prerequisites (2026-10-02)

Goal: a placed (and approved) port order automatically assigns a pre-patched
port, **provisions it fully in IXP-Manager** (ready-to-go: VI/PI/VLI, IPs,
hostnames — everything except the config-agent push, which stays manual and
comes much later), and generates + emails the LOA, with no manual admin
assembly. The LOA generator itself already exists (see the cross-connect LOA
feature); what's missing is everything that feeds it.

### Approval model (DECIDED 2026-10-02)

**Build for full automation end-to-end; gates are optional toggles, off by
design.** Order submitted → reserve → provision in IXP-M → LOA generated +
emailed, as one immediate automated shot with **no human intervention**.

- **Auto-LOA without admin intervention is the key requirement.** The DC
  x-connect process is the long pole and starts only once the customer has
  the LOA in hand — so the LOA must never wait on a human. Config push to
  the switch can happen at any time afterwards (exactly as done manually
  today); the whole point is removing the human back-and-forth in
  IXP-Manager port provisioning, which is pure time waste.
- A policy flag (`auto-approve: all | existing | none`) exists in the design
  so approval gates can be toggled in later for specific risk situations as
  we progress — but `all` is the default and the build target. Everything
  the automation does is reversible IXP-Manager database state; the
  network-affecting step (config push) remains manual and much later.
- Every order still lands in the admin order queue with a notification —
  billing is manual, so admins need awareness of every order, but awareness
  is not approval.
- Deliberately NOT "auto-LOA now, approve provisioning later": one pipeline,
  no half-done states. If a gate is ever toggled on, it sits in front of the
  whole shot, and whatever gates the LOA is the only gate.

### Provisioning recipe (automation spec — captured 2026-10-02)

What the automation must replicate — today's manual flow is: know which
ports are free/prewired and their speed/technology (fiddly: remembering the
patch panel state), then Peers page → provision new port, fill in:

| Field | Value |
|---|---|
| VLAN | the IX's peering VLAN |
| VLAN tag | customer's VLAN ID; **0 if untagged** |
| 802.1q framing | ticked if tagged |
| Switch / switch port | the auto-picked prewired port |
| Status | per order state |
| Speed / Duplex | port type speed / **always full** |
| IPv4 enabled / IPv6 enabled | **both always** |
| Route Server Client | **ticked (default for all)** |
| Apply IRRDB filtering | **ticked** |
| IRRDB allow more specific | **ticked** |
| IPv4 + IPv6 address | next available in the VLAN's allocation list |
| IPv4 + IPv6 hostname | `as<peerasn>.<ixlocation>.edgeix.net.au` |

RS participation / v4 / v6 can be opted out by an admin later, but default
on for everyone.

Prerequisite work list, in no particular order:

**Stock / detection:**
- [ ] Switch ports patched + marked pre-wired consistently
      (`PatchPanelPort::STATE_PREWIRED`). The Port Stock page's hygiene list
      ("free typed ports NOT marked prewired") shows exactly what needs
      fixing — work the list down to zero.
- [x] Automatic port-type detection (BUILT 2026-10-03, **pending fleet
      validation**): `artisan switch:detect-transceivers` — ENTITY-MIB walk
      via OSS_SNMP's bundled Entity MIB, maps optics to switch ports by
      interface name (own fields, then containment parents), fans parent-cage
      optics out to breakout legs, matches against the catalogue, stamps
      `switchport.detected_xcvr` / `port_type_id`. Validate per platform
      with `--nosave --debug` before cronning it.
- [x] Detection in the switch UI (BUILT 2026-10-03): the per-switch dropdown
      (skinned `switches/list-row-menu.foil.php`) gains **"Detect
      Transceivers"** under SNMP Actions — a live ENTITY-MIB walk preview
      (detected optics, catalogue matches, will-update diff vs stored
      values, unmapped optics, collapsible raw entity rows = the CLI's
      `--debug`) with a "Save to database" button sharing the command's
      persist path — and **"Port Transceivers"** under Database Actions:
      stored detection per port with per-port **admin override editing**.
      Workflow for a new switch: add switch → add ports via the SNMP
      "View / Edit Ports" action (ports must exist in the DB first) →
      "Detect Transceivers" from the same dropdown; cron keeps it current
      thereafter.
- [x] Admin port-type catalogue CRUD (BUILT 2026-10-03): `/admin/port-type/list`
      — name, speed, priority-ordered regex match patterns, active flag,
      low-stock threshold. Seeded with 10G LR, 40G LR4, 100G LR4, 100G LR/FR,
      400G LR4, 10G PSM4-breakout (+ inactive 25G rows ready to enable).
      Per-port admin override column (`port_type_override_id`) wins over
      detection for lying optics.
- [x] Stock visibility (BUILT 2026-10-03): `/admin/port-stock` — sellable
      counts per (location × type) with low-stock badges, sellable port
      list, and the two hygiene lists (not-prewired, unmatched optics).
- [x] "Sellable" semantics: sellable = active peering switch port +
      effective port type + no physical interface + panel port PREWIRED.
      Reserved/internal panel states are excluded naturally.
- [x] Low-stock alerting (BUILT 2026-10-03): `port-stock:check-levels` —
      daily digest email (mirrors `router:check-stale`) to
      `PORT_STOCK_ALERT_EMAIL`, scheduled 09:00 from Kernel when the env
      var is set. Alerts per (location × type) where the type has a
      threshold AND the location actually deploys that type (any port of
      that type on site, in service or not — sites that never stock a type
      stay silent); also nags unmatched optics so unclassified SKUs can't
      hide from stock. Detection itself is scheduled daily 05:20
      (`switch:detect-transceivers`). Stock/sellable definitions live in
      `PortStockService`, shared with the admin page and (later) the order
      form's availability logic. Per (DC × port type) threshold
      — notify admins when stock drops *below N*, not only at zero, so
      prewiring happens before orders are blocked. Plus: order placed against
      an out-of-stock DC → immediate admin notification + customer-facing
      lead-time messaging.

### Orders data model (designed/built 2026-10-03)

Tables (`port_order` — "order" is an SQL reserved word — and
`port_order_port`):

- `port_order`: cust_id, user_id (who placed), `state`, location_id,
  port_type_id, quantity (2+ = LACP LAG, same switch), tagged + vlan_tag,
  macs (JSON, ≤2 per Schedule A, nullable = "provide later"),
  delivery_contact, po_number, preferred_golive, reserved_until (hold
  expiry while awaiting approval), per-transition timestamps
  (approved_at/by, provisioned_at, loa_issued_at, activated_at,
  cancelled_at + reason), virtual_interface_id (set by provisioning).
- `port_order_port`: one row per reserved switch port (+ its patch panel
  port at reservation time). The reservation IS these rows: the stock
  query excludes switch ports held by any open order.

**States:** `submitted → approved → provisioned → awaiting_xconnect →
active`, terminal `cancelled` / `expired`. Open (= holds its reservation):
submitted/approved/provisioned/awaiting_xconnect. `reserved_until` only
applies in `submitted` (an approval gate, if policy enables one, must not
hold stock forever); `port-order:expire-holds` (hourly) expires overdue
submitted orders and frees the ports.

**Reservation:** `PortOrderService::place()` — single DB transaction,
`lockForUpdate` over sellable candidates (active peering port, effective
type = requested, no physical interface, panel PREWIRED, same switch for
LAG quantity, not held by an open order) so two concurrent orders can
never take the same last port. No stock → exception surfaced to the
caller (order form shows lead-time path instead).

**Switch diversity (2026-10-03):** the picker prefers switches where the
customer has NO existing or reserved ports — so "two single-port services
at one DC for router redundancy" (two separate quantity-1 orders, each
its own LAG/VI/IPs — the correct shape for redundancy) automatically
lands on two different switches when stock allows, no customer input
needed. Falls back to a shared switch when that's all that's left.

**Approval policy** (`ORDER_AUTO_APPROVE` = `all` | `existing` | `none`,
default `all` per the zero-touch decision): `all` → auto-approved at
placement; `existing` → auto only when the customer already has services;
`none` → every order waits for an admin. Every placement emails
`ORDER_NOTIFY_EMAIL` regardless — awareness ≠ approval, and it's the
manual-billing trigger.

**Order workflow:**
- [x] Orders table + state machine (BUILT 2026-10-03): `port_order` +
      `port_order_port`, `PortOrder` model with state constants,
      `PortOrderService` (place / approve / cancel / expireHolds). See
      "Orders data model" above. MAC-missing is a derived flag
      (`macMissing()`), nag-not-block as decided.
- [x] Port **reservation at submit time** (BUILT 2026-10-03): placement
      locks sellable candidates FOR UPDATE in one transaction, LAG
      quantities constrained to a single switch; stock queries exclude
      ports held by open orders; `port-order:expire-holds` (hourly)
      releases overdue unapproved holds (`ORDER_HOLD_DAYS`, default 14).
- [x] Approval model (DECIDED): full automation end-to-end, zero-touch by
      default — see "Approval model" above. Gates are toggleable policy, not
      the design.
- [x] New Port wizard (BUILT 2026-10-03): `/order` is now the real form —
      stock-driven selects (only locations/types with sellable stock;
      quantity capped at the largest same-switch pool), tagged/VLAN, MACs
      optional with the provisioning warning, delivery contact/PO/go-live.
      Custadmin+ only. Out-of-stock placement → lead-time message, no
      order created. Customer order-status page (`/order/view/{id}`) with
      MAC-missing nag and "what happens next". Reserved port identities
      are NOT shown to the customer pre-LOA.
- [x] Admin order queue (BUILT 2026-10-03): `/admin/port-order/list` —
      state filters, Approve (for policy-gated orders), Cancel with
      reason (releases ports), order detail incl. reserved ports + panel
      links + timeline. Linked from Port Stock.
- [ ] Customer comms: order confirmation email to the customer, LOA PDF
      emailed + downloadable from the order (admin notify email exists).
- [x] Billing hook (DECIDED 2026-10-02): **stays manual for now** — lots of
      trial periods make automated billing premature. Admin order
      notifications are the billing trigger. Xero hook-in is a later,
      separate piece.

**Fulfilment (per approved order):**
- [ ] Auto-pick the demarc: free prewired PPP at the chosen DC matching the
      port type → assign to customer, state → awaiting x-connect.
- [ ] Auto-provision in IXP-M per the recipe above: VI (+LAG framing per
      quantity), PI(s), VLI with the default flags, next-available IPv4 +
      IPv6, hostnames `as<peerasn>.<ixlocation>.edgeix.net.au`.
- [x] X-connect ownership (DECIDED 2026-10-02): **the customer always orders
      the x-connect from the DC** — that's exactly why we generate them an
      LOA. No per-DC variation to model.
- [ ] Eligibility guards at order time (AGREED): custadmin only (done), MSA
      gate (done), customer status not suspended; resold customers excluded
      from self-serve initially.

## OPEN DESIGN ISSUE — port speed visibility for availability (2026-10-01)

**The problem.** Phase 3's dynamic order form must answer: *"how many free,
pre-patched ports at speed X exist at PoP Y?"* We pre-patch patch panel ports
to switch ports carrying optics of a specific speed, and IXP-Manager tracks
the panels — but nothing in the data model records the speed of a pre-patched
port:

- `patchpanelport` has state (incl. `PREWIRED`), ownership, colo refs — **no
  speed column at all**, and nothing speed-related shows in the patch panel UI.
- `switchport.ifHighSpeed` is SNMP-polled and only truthful once the link is
  up and negotiated. A pre-patched port sitting notconnect reports the
  configured/default lane speed or 0 — exactly the ports we need visibility of.
- `switchport.mauType` / `mauJacktype` come from the MAU MIB, which many
  optics and platforms don't populate. Not scalable as the source of truth.

**Candidate solutions:**

**A. Declared service speed on the switch port** (admin-set column, e.g.
`switchport.designated_speed`, set when the optic goes in / the port is
pre-provisioned). The speed a port is sold at is a *deployment decision*, not
a derived fact — a 10G optic in a 25G-capable cage is 10G stock. Availability
is then a deterministic query: prewired PPP → switch port where
`designated_speed = X`, type peering, active, no `physicalinterface` row.
Surfaced on the patch panel port list/detail views so pre-patch stock is
visible to humans too. Cost: one more field to maintain, with drift risk.

**B. Declared speed on the patch panel port.** Same field, panel side.
Rejected (leaning): speed is a property of the switch port + optic, not the
panel. It would have to move on every re-patch, and duplex/slave ports
complicate it. The PPP already derives customer/service facts from its switch
port — speed should follow the same direction.

**C. Derive from transceiver inventory telemetry.** Read the optic's PMD type
from EEPROM (eAPI `show inventory` / OpenConfig `platform/components`
transceiver `ethernet-pmd`, e.g. `ETH_100GBASE_LR4`) — works regardless of
link state and doesn't depend on the MAU MIB. We already scrape per-port
transceiver data into VictoriaMetrics for DOM (`dom_rx_power:interface`), so
the collection path exists. Caveats: third-party-coded optics occasionally
misreport; dual-rate optics (10/25G) and breakout ports are ambiguous; it
reports what the optic *can* do, not what we *sell* the port as.

**Leaning: A as source of truth, C as the auditor.** Admin declares the speed
(A); a periodic job compares declared speed against detected optic PMD (C)
and flags mismatches on the patch panel view / a report, rather than trusting
telemetry blindly. The MAU fields stay as-is (upstream, informational only).

**Refinement (2026-10-02): detection must be automatic.** Requirement from
review: port type (10G LR, 40G LR, 100G LR4, 100G LR, 400G LR4, later 25G…)
should be *automatically determined*, with an admin-managed catalogue of
sellable port types layered on top. Direction:

- **Relationship to upstream's Optic Inventory (checked 2026-10-03):**
  IXP-Manager already ships "Optic Inventory" / "Unused Optics" / "Optic
  List" pages — but they are built entirely on `switchport.mauType`
  (`GROUP BY mauType`, gated on `switch.mauSupported`), i.e. the MAU MIB we
  ruled out as the source of truth: no entries for 400G at all (verified
  against OSS_SNMP's MAU type table), `(empty)` for unregistered optics,
  switches without mauSupported invisible, and no mapping to sellable
  types, breakout legs, overrides or stock. NOT a duplication — but to
  avoid parallel wheels, **the detector consumes mauType as a second
  source through the same catalogue**. Lesson from the first real-switch
  run (pe1per1): third-party optics report their vendor PART NUMBER in
  `entPhysicalModelName` (e.g. `Q.1340G.10`), while the media type EOS
  prints as "Media type" (`40GBASE-PLR4`) surfaces via Arista's private
  MAU OIDs, which the core poller already stores as `40GbasePLR4`.
  Resolution order per port: ENTITY strings → catalogue; no match → stored
  `mauType` → same catalogue; the ENTITY part number is kept as the
  displayed/stored optic string. Sources are flagged in UI/CLI
  (`entity` / `mau` / `entity+mau`); MAU freshness follows the core
  snmp-poll cadence. Known hard cases — single-lambda types MAU lacks
  (100GBASE-DR) and optics EOS itself can't name (`UnknownOptical400G`):
  classify via a vendor-P/N pattern in the catalogue or the per-port
  override. Upstream's pages remain useful as MAU-level procurement counts.
- **Hard constraint (2026-10-03): detection is SNMP-only.** IXP-Manager has
  read-only SNMP access to the switches and deliberately holds NO switch
  API credentials (eAPI/gNMI) — that access boundary is the point of the
  separate config agent. An eAPI-based media-type source was prototyped and
  reverted for this reason. If SNMP + catalogue patterns + overrides ever
  prove insufficient, the escalation path is a read-only endpoint on the
  config agent, never direct switch API access from IXP-Manager.
- **Detection: SNMP ENTITY-MIB**, not the MAU MIB. Arista populates
  `entPhysicalModelName`/`entPhysicalDescr` for every inserted transceiver
  (e.g. `QSFP-100G-LR4`) regardless of link state, and it rides the same SNMP
  credentials/poller stack IXP-Manager already uses — a new walk, not a new
  transport. The MAU MIB stays ruled out: many optics don't populate it and
  the IANA MAU registry has no codes for many modern types (400G-LR4 etc.).
  Store the result in a new `switchport.detected_xcvr` (model string) +
  mapped port-type id. gnmic/OpenConfig (`platform/components` →
  `ethernet-pmd`) is the auditor/fallback — we already run gNMI collection
  for the VictoriaMetrics graphs.
- **Admin port-type catalogue** (new admin CRUD): the sellable products —
  name ("100GBASE-LR4"), speed, matching transceiver model patterns, active
  flag. Detection maps `detected_xcvr` → catalogue entry; unmatched optics
  surface on a report instead of silently becoming stock. Adding 25G later =
  add a catalogue row, no code.
  - **`active` means sellable, not classifiable** (2026-10-03): detection
    matches inactive catalogue rows too — classification and sellability
    are different things. 100G CWDM4 lives on core links (same-rack): its
    row is inactive, so those optics classify cleanly for inventory but
    never count as customer stock (core switch ports are also excluded by
    `type = PEERING` anyway).
  - **Optic self-description can lie** (2026-10-03): e.g. our third-party
    10km 100G LR SKU (`Q.13S1HG`) is EEPROM-coded as `100GBASE-DR` for
    switch compatibility. Irrelevant to the pipeline: the catalogue maps
    per-SKU part-number patterns to what WE sell the port as — the optic's
    claim only needs to be consistent, not true.
- **Manual override per switchport** stays (the original Option A field) for
  lying third-party optics and odd cases — override wins over detection.
- **PSM / breakouts**: the optic model identifies a PSM4, but the sellable
  unit comes from the breakout config — the 4×10G legs already appear as
  separate switchports once polled (`Ethernet49/1…/4`), so each leg is its
  own 10G stock item; detection tags legs with the parent optic. Same model
  will hold for 4×25G.

**Also needs deciding alongside:**
- Is `PatchPanelPort::STATE_PREWIRED` actually used consistently today for
  pre-patched stock? The availability query depends on it (or on a defined
  equivalent, e.g. AVAILABLE + switch port assigned).
- Breakout ports (e.g. 4×100G out of 400G): one switchport row per breakout
  leg, each with its own declared speed, or parent-level stock?
- Where "sellable" is marked: not every free port with an optic is for sale
  (reserved stock, internal use). `designated_speed IS NOT NULL` + prewired
  state may be sufficient as the sellable signal, or an explicit flag.

**Status: OPEN — no code written.** Decision needed before the Phase 3
availability service (`PortAvailabilityService` or similar) is designed.

## Configuration (env vars)

All read via config files — run `php artisan config:clear` after changing.

| Env var | Config | Default | Meaning |
|---|---|---|---|
| `ORDER_MAINTENANCE` | `ordering.maintenance` | `false` | `true` disables customer order placement — wizard shows a maintenance notice and the store endpoint rejects server-side. Customers can still view existing orders; admin queue unaffected. For slow prod rollout / operational pauses. |
| `ORDER_MAINTENANCE_MESSAGE` | `ordering.maintenance_message` | email-sales text | Customer-facing text on the maintenance notice. |
| `ORDER_AUTO_APPROVE` | `ordering.auto_approve` | `all` | `all` = zero-touch; `existing` = auto only for customers with services; `none` = every order waits for admin Approve. Rollout plan: prod starts `none` → `existing` → `all` as confidence grows; test box runs `all`. |
| `ORDER_HOLD_DAYS` | `ordering.hold_days` | `14` | Days an unapproved (submitted) order holds its port reservation before `port-order:expire-holds` releases it. |
| `ORDER_NOTIFY_EMAIL` | `ordering.notify_email` | unset | Every placed order emails this address (awareness + manual-billing trigger). Unset = no emails. |
| `PORT_STOCK_ALERT_EMAIL` | `porttype.low_stock_alert_email` | unset | Recipient for the daily low-stock digest. Unset = job not scheduled. |

**Served-via sites (2026-10-04):** so campus customers can FIND us, a
passive/campus site can be marked *served via* a demarc site
(`location.served_via_locationid`, managed at Port Stock → Served-via
Sites). The order form then lists it ("Equinix SY4 — delivered at Equinix
SY1/SY2") drawing on the demarc's stock, with an inline explainer that
the LOA will be for the demarc and the customer orders the x-connect from
their site. On placement the order stores both: `customer_locationid`
(what they picked) and `locationid` (resolved demarc — used for
reservation/provisioning/LOA). Admin queue badges "customer at SY4".
Chained aliases are rejected; aliases hide automatically when the demarc
has no stock, and excluded sites can't be aliases.

**Demarc attribution & long-lined sites (2026-10-04):** a sellable port's
location is its **patch panel's site** (where the customer x-connects and
what the LOA names), not the switch's — identical for co-located pairs,
and truthful for long-lined ones (panel at a remote passive site, switch
elsewhere; badged *long-lined* on Port Stock). The reservation query
matches on the panel side too. Equinix campus needs nothing special: the
demarc panels are at SY1/SY2, so campus sites never show stock of their
own — customers get an SY1 LOA and arrange the campus x-connect, as
today. Manual-only sites (e.g. Vocus DC PER01) are excluded from
self-serve entirely via `ORDER_EXCLUDED_LOCATIONS` (comma-separated
location IDs; config `ordering.excluded_locations`): no sellable stock,
no order form, no low-stock alerts, no prewire hygiene — and `place()`
refuses the location server-side regardless of UI.

**Thresholds & the offering map (2026-10-04):** the low-stock threshold is
one number per port type (Port Types → edit), evaluated **per DC** — each
DC below it gets its own digest line and matrix badge. By default a type
alerts at every DC that *deploys* it (any detected port). For types only
offered at certain sites (400G), each type has an **"Offered at"**
location checklist (`port_type_location`): no sites ticked = offered
wherever detected (default); sites ticked = authoritative — sellable
stock, the order form and alerting apply ONLY at ticked sites, stray
optics elsewhere can't become stock or alert noise, and offered sites
alert **even with zero ports of the type** (capable-but-empty). The stock
matrix's *low* badges use the exact same shortfall logic as the digest.

## Catalogue seeds: adding shipped types to an existing install

The canonical port-type seed rows live in
`database/seeders/PortTypeSeeder.php` (the create migration calls it too).
It is **idempotent by name and non-destructive**: it inserts types that
don't exist yet and never modifies existing rows, so admin edits (patterns
added via the UI, thresholds, active flags) are always preserved. When a
release ships new catalogue types:

```
php artisan db:seed --class=PortTypeSeeder
php artisan switch:detect-transceivers   # re-map with the new rows
```

Do NOT use `migrate:rollback` to refresh the catalogue — once orders and
per-port overrides exist, a re-seed assigns fresh ids and silently remaps
`port_order.port_type_id` / `switchport.port_type_override_id`.

## Testing the pipeline (pre-wizard)

Until the order wizard lands, `PortOrderService` has no UI caller — test the
core from tinker on the test box (after migrating):

```php
$svc  = app(\IXP\Services\EdgeIX\PortOrderService::class);
$cust = \IXP\Models\Customer::find( <test cust id> );
$type = \IXP\Models\PortType::where( 'name', '10GBASE-LR' )->first();

$order = $svc->place( $cust, null, [
    'locationid' => <location id>, 'port_type_id' => $type->id,
    'quantity' => 1, 'tagged' => false,
] );
$order->state;                      // approved (if auto_approve=all) or submitted
$order->portOrderPorts->first()->switchPort->ifName;
```

Verify against the checklist: `/admin/port-stock` shows the reserved port
gone from sellable; a second `place()` picks a different switch (diversity)
or different port; an oversized quantity throws
`InsufficientPortStockException`; `ORDER_NOTIFY_EMAIL` receives the
placement email; with `ORDER_AUTO_APPROVE=none`, the order stays
`submitted` and `php artisan port-order:expire-holds` expires it once
`reserved_until` passes (set it into the past manually to test); cancel via
`$svc->cancel( $order, 'testing' )` returns the port to sellable.

## Cancellations

Not in the self-service UI. Customers email `sales@edgeix.net` with a
cancellation request; billing terms are governed by their signed MSA (Schedule
A / General Terms).

May be added to the portal in a later phase once the ordering flows are
production-proven.

## Implementation status

| Phase | Scope | Status |
|---|---|---|
| Nav button + placeholder page | This doc | **Done** (2026-07-04) |
| Phase 2 — MSA gate wraps `/order` routes | `EnsureMsaSigned` middleware | **Done** (2026-10-01) |
| Phase 2 — `/msa` customer status page | Signed / custom-pending / unsigned branches | **Done** (2026-10-01) — interim panel; SignNow embed pending provider account |
| Phase 2 — Admin Record MSA screen | Enable/upload manually-executed MSAs | **Done** (2026-10-01) |
| Phase 2 — SignNow e-sign + webhook | Embedded signing, signed PDF → docstore | Not started (blocked on SignNow account) |
| Phase 2 — Terms-version re-consent hook | Standard MSAs only; custom exempt | Not started |
| Phase 3 — New Port flow | Full form, PoP/DC/speed picker, dynamic availability | Not started |
| Phase 3 — Add to LAG flow | Port selector, LAG conversion detection | Not started |
| Phase 3 — Upgrade Port flow | Existing port picker, IP swing acknowledgement | Not started |
| Phase 3 — Admin approval / provisioning integration | Behind the scenes, uses existing port wizard | Not started |

## Related files

- `app/Http/Controllers/EdgeIX/OrderController.php` — placeholder controller.
- `app/Http/Controllers/EdgeIX/MsaController.php` — customer `/msa` page.
- `app/Http/Controllers/EdgeIX/MsaAdminController.php` — admin Record MSA.
- `app/Http/Middleware/EnsureMsaSigned.php` — the order-time gate (`msa`).
- `resources/skins/edgeix/order/index.foil.php` — "Coming Soon" landing view.
- `resources/skins/edgeix/msa/index.foil.php` — customer MSA status page.
- `resources/skins/edgeix/msa/admin.foil.php` — admin Record MSA form.
- `resources/skins/edgeix/layouts/menus/custadmin.foil.php` — nav button.
- `routes/web-auth.php` — `order` (gated) + `msa` route groups.
- `routes/web-auth-superuser.php` — `admin/customer/msa/{cust}`,
  `admin/port-type/*`, `admin/port-stock` routes.
- `app/Models/PortType.php` — sellable port-type catalogue model.
- `app/Services/EdgeIX/TransceiverDetector.php` — ENTITY-MIB walk + mapping.
- `app/Console/Commands/EdgeIX/DetectTransceivers.php` —
  `switch:detect-transceivers {switch?} {--nosave} {--debug}`.
- `app/Http/Controllers/EdgeIX/PortTypeController.php` — catalogue CRUD.
- `app/Http/Controllers/EdgeIX/PortStockController.php` — stock view.
- `app/Services/EdgeIX/PortStockService.php` — shared stock/sellable logic.
- `app/Models/PortOrder.php`, `app/Models/PortOrderPort.php` — orders +
  reservations (migration `2026_10_03_000001_create_port_orders.php`).
- `app/Services/EdgeIX/PortOrderService.php` — place/approve/cancel/expire;
  locked reservation; approval policy; admin notify email.
- `app/Console/Commands/EdgeIX/ExpirePortOrderHolds.php` — hourly hold expiry.
- `app/Console/Commands/EdgeIX/ExplainPortStock.php` —
  `port-stock:explain {switch} {--only-failing} {--all}`: per-port
  first-failing sellable condition (the stock-discrepancy debugger; covers
  ports the stock page's base query filters out, e.g. non-Peering use;
  Port-Channel/Management hidden unless --all).
- `app/Console/Commands/EdgeIX/PruneStaleSwitchPorts.php` —
  `switch:prune-stale-ports {switch?} {--delete}`: bulk-deletes DB port
  rows the switch no longer has (stale by lastSnmpPoll, or duplicate
  ifName) when unattached (no service/panel/order); attached-but-stale is
  reported, never deleted. Dry run by default — upstream has no bulk tool
  (the poller only warns).
- `app/Http/Controllers/EdgeIX/OrderController.php` — New Port wizard +
  customer order status (replaces the placeholder).
- `app/Http/Controllers/EdgeIX/PortOrderAdminController.php` — admin queue.
- `resources/skins/edgeix/order/{index,view}.foil.php` — wizard + status.
- `resources/skins/edgeix/portorder/{index,view}.foil.php` — admin queue.
- `config/ordering.php` — `ORDER_AUTO_APPROVE`, `ORDER_HOLD_DAYS`,
  `ORDER_NOTIFY_EMAIL`.
- `app/Console/Commands/EdgeIX/CheckPortStockLevels.php` —
  `port-stock:check-levels` low-stock digest (env `PORT_STOCK_ALERT_EMAIL`).
- `config/porttype.php` — stock settings (deliberately SNMP-only, no
  switch API config).
- `app/Http/Controllers/EdgeIX/SwitchXcvrController.php` — live detect
  preview/apply + stored per-port view + override editing.
- `resources/skins/edgeix/porttype/{index,edit,stock}.foil.php` — admin views.
- `resources/skins/edgeix/xcvr/{detect,index}.foil.php` — switch UI pages.
- `resources/skins/edgeix/switches/list-row-menu.foil.php` — per-switch
  dropdown (skin override of upstream file; re-check on merges).
- Migration `2026_10_02_000001_create_port_types_and_xcvr_detection.php` —
  `port_type` table (+seed) and `switchport` detection columns.

## Related documentation

- `docs/signup-terms-management.md` — Privacy Policy consent + version stamping
  applied at signup. Phase 2's MSA re-consent will hook into the same version
  tracking.
