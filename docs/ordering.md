# EdgeIX Customer Ordering — Workflow Spec

This document describes the planned customer self-service ordering flows and
the current state of implementation.

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
different data capture, validation, and provisioning steps.

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
- [ ] Low-stock alerting (AGREED 2026-10-02): threshold field + stock-page
      badges exist; the admin *notification* job does not yet.
      Per (DC × port type) threshold
      — notify admins when stock drops *below N*, not only at zero, so
      prewiring happens before orders are blocked. Plus: order placed against
      an out-of-stock DC → immediate admin notification + customer-facing
      lead-time messaging.

**Order workflow:**
- [ ] Orders table + state machine: submitted → approved → provisioned +
      LOA issued → awaiting x-connect → active; cancelled / expired. Orders
      missing a MAC can reach "awaiting x-connect" but are flagged
      incomplete until the MAC is supplied.
- [ ] Port **reservation at submit time** with a hold expiry — two concurrent
      orders must not be able to take the same last prewired port.
- [x] Approval model (DECIDED): full automation end-to-end, zero-touch by
      default — see "Approval model" above. Gates are toggleable policy, not
      the design.
- [ ] Customer comms: order confirmation email, portal order-status page
      (incl. MAC-missing nag), LOA PDF emailed + downloadable from the
      order; admin order queue UI.
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
  avoid parallel wheels, **the detector consumes mauType as a fallback
  source through the same catalogue**: where the ENTITY walk yields nothing
  for a port but the core poller has stored a MAU string (`10GigBaseLR`,
  `40GbasePSM4`, …), that string is matched against the same patterns
  (seeds updated to match MAU spellings). ENTITY wins when both exist;
  MAU-sourced values are flagged "via MAU" in the UI/CLI, and their
  freshness follows the core snmp-poll cadence. Upstream's pages remain
  useful as MAU-level procurement counts.
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
