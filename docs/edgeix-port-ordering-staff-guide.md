# Port Ordering & Stock — Staff Guide

This guide is for EdgeIX staff operating the port ordering system in
IXP-Manager day-to-day. For the technical/architecture spec, see
`docs/ordering.md`.

## The big picture

Customers order peering ports through the portal and — when fully enabled —
everything up to the switch config happens automatically:

```
customer signs up → accepts/has MSA → places order on /order
      → a pre-wired port is reserved automatically
      → (approval, if gating is on)
      → service provisioned in IXP-Manager        ← being built
      → LOA generated and emailed to the customer ← being built
      → customer orders their cross-connect from the DC
      → port goes live
```

Your job in this system: keep the **stock** honest (optics classified, panels
marked pre-wired), handle the **order queue**, and record **MSAs**. The
system emails you; you rarely need to go looking.

## Where everything lives

Left sidebar → **EDGEIX → Port Stock** (sub-menu appears when you're in the
section):

| Page | What it's for |
|---|---|
| **Port Stock** (`/admin/port-stock`) | Sellable ports per DC × type, low-stock flags, and the two "fix me" lists |
| **Order Queue** (`/admin/port-order/list`) | Every customer order: view, approve, cancel |
| **Port Types** (`/admin/port-type/list`) | The catalogue of port types and the patterns that classify optics |

Per-switch (Switches → list → dropdown on a switch row):

| Action | What it's for |
|---|---|
| SNMP Actions → **Detect Transceivers** | Live scan of what optics are in the switch right now, with Save |
| Database Actions → **Port Transceivers** | What's stored per port + set a per-port type override |

Per-customer (customer overview → edit dropdown): **Record MSA...**

## Optic detection — how ports become stock

Every night at 05:20 the system scans every switch (read-only SNMP) and
works out what optic is in every port — part number and sellable type
(10G LR, 100G LR4, breakout legs, etc.). You never type optic data per port.

A port counts as **sellable stock** when ALL of these are true:

1. The switch port is **active** and type **Peering** (not Core/Management).
2. Detection has classified its optic to an **active** catalogue type.
3. It has **no customer service** on it.
4. Its patch panel port is marked **Prewired**.
5. No open order has already reserved it.

**That last mile is yours**: when you physically pre-patch a port, set its
patch panel port state to **Prewired** in IXP-Manager. The Port Stock page's
hygiene list *"free typed ports NOT marked prewired"* shows exactly which
ports are invisible to ordering because of panel state — work it to zero.

### After physically installing optics / pre-patching

Either wait for the overnight scan, or make it instant: switch dropdown →
**Detect Transceivers** → review → **Save to database**. Then check Port
Stock shows the new capacity.

### A new optic SKU shows up as "no catalogue match"

This happens when we buy from a new supplier. One-time, 30-second fix:

1. It appears in the unmatched list (Detect page, Port Stock hygiene panel,
   and the daily stock email).
2. Note the detected string, e.g. `Q.13S1HG.10`.
3. **Port Types → edit the type it really is** (ask engineering if unsure
   what the SKU is) → add the part number as a new line in *Match patterns*.
   A plain part-number substring works — no regex needed.
4. Re-run detection (button or wait for 05:20). Every port fleet-wide with
   that SKU classifies, forever.

Rule of thumb for the **Active** tick on a port type: Active = we sell it
(counts as stock, appears on the order form). Optic types we only use
internally (CWDM4 core links, DACs, DWDM) stay **inactive** — they still
classify for inventory but can never be ordered.

### An optic is classified wrongly

Rare (some third-party optics lie about what they are). Switch dropdown →
**Port Transceivers** → set the **override** dropdown on that port. The
override wins over detection permanently until cleared.

## Low-stock alerting

Set a **low-stock threshold** on each port type we actively sell (Port
Types → edit). The threshold is one number per type but it is checked
**per DC**: every morning at 09:00, each DC that has fewer sellable ports
of a type than the threshold gets its own line in the digest email (e.g.
"NEXTDC P1 — 10G — sellable: 1, threshold 2"), and the Port Stock matrix
shows a red *low* badge on that exact DC cell. Locations that never stock
a type don't alert. The email also nags about unmatched optics.

**Two kinds of remote site — pick the model by who owns the tail:**

- **EdgeIX-owned fibre to the site (Vocus DC PER01 → our dark fibre →
  NEXTDC P2):** modelled with ONE panel — the real one at PER01
  (`VDC-PER01-Rack70-R21`) — whose ports are linked to the pe1per2
  switch ports using the **"Remote switch?"** checkbox on the panel
  port's edit form: tick it and the Switch dropdown lists every site's
  switches (shown as `switch — site`); pick the remote switch, then the
  port, and save as normal. Ports already linked cross-site open with
  the box ticked and a *remote site* badge — safe to edit and re-save
  like any other port. From there the site behaves like any other:
  PER01 shows its own stock (*long-lined* badge), customers order
  PER01 directly, LOAs are generated from the PER01 panel port as normal
  and correctly say Vocus PER01, and the low-stock alert = dark-fibre
  pairs running out.

  Leave the box **unticked** for normal same-site patching — it exists
  only for panels long-lined over EdgeIX-owned fibre.

- **Campus / served-via (the CUSTOMER buys the tail — e.g. Equinix
  SY3/SY4/SY5 → "Equinix SY1/SY2"):** mark the site served-via its demarc
  at **Port Stock → Served-via Sites**. The order form lists "Equinix SY4
  — delivered at Equinix SY1/SY2", the LOA is for the demarc site, the
  customer orders the campus cross-connect, and the queue shows a
  *customer at SY4* badge. Served-via sites never low-stock alert.

(A fully manual site can still be excluded from online ordering entirely
— ask engineering — but nothing currently is.)

**Types only offered at some sites (e.g. 400G):** on the type's edit page,
tick the sites under **Offered at**. Nothing ticked = offered wherever the
optic is detected (fine for common types). Ticked = only those sites count
for stock, ordering and alerts — and a ticked site alerts even when it has
ZERO ports of the type, so "P1 is supposed to have 400G and has none"
can't go unnoticed.

**Pre-wire before it alerts at zero** — ordering is self-service, so stock
gets consumed without anyone phoning first.

## MSAs — who can order at all

Customers must have an executed MSA on record before the portal lets them
order. The gate is automatic; your part is recording agreements that were
signed outside the portal:

- Customer overview → edit dropdown → **Record MSA...** (an *unsigned*
  badge shows if there's nothing on record).
- Status **Signed** + date + either the executed **PDF** (preferred — it's
  then visible to the customer's own admins) or **notes** saying who signed
  and where the paper copy lives.
- **Custom/negotiated MSA in progress**: set type **Custom** + status
  **Unsigned** — the customer sees "agreement being finalised, contact
  sales" instead of being asked to sign the standard terms. When executed,
  come back and set Signed.
- **Internal and pro-bono customers are exempt** — no MSA needed, the gate
  waves them through automatically (badge shows *exempt*).

E-signature (customer self-serve signing) is coming; recording stays the
tool for paper and custom agreements.

## Backorders — orders we accepted without stock

We never refuse an order: when a customer orders something with no
pre-provisioned capacity, the order is accepted as a **backorder** (red
badge in the queue, "ACTION NEEDED" in the notification email). Your job:

1. Arrange the capacity — prewire, run structured cabling, or install a
   switch, as appropriate.
2. Make sure the new ports show as **sellable** on Port Stock (detected,
   typed Peering, panel linked + Prewired).
3. Open the order → **Reserve ports & approve**. Done — it continues like
   any other order. (If it complains there's still no stock, run the
   explain command from Troubleshooting.)

Backorders never expire — they wait until you fulfil or cancel them.

## The order queue

Every order lands in **Order Queue** and emails the order notify address —
**billing is manual**, so that email is also your billing trigger.

- **Approve** — only needed when approval gating is on (see dials below);
  otherwise orders auto-approve at placement. Unapproved orders hold their
  reserved port for 14 days, then auto-expire and release it.
- **Cancel** (with a reason) — releases the reserved port(s) back to stock.
  Use for customer-requested cancellations or junk orders.
- **"MAC needed" badge** — the customer ordered without a MAC address. The
  order can progress, but the port can't go live until they provide one;
  the customer sees the same nag on their order page.
- Customers are never shown which physical port was reserved — that's
  internal until the LOA goes out.

## Control dials (engineering sets these; know they exist)

| Control | Effect |
|---|---|
| **Maintenance mode** | Customer ordering off fleet-wide (wizard shows a notice, placement blocked). Existing orders still viewable; queue unaffected. |
| **Auto-approve policy** | `all` = zero-touch; `existing` = new customers' first order waits for your Approve; `none` = every order waits. |

## Troubleshooting

**"A port should be orderable but isn't showing as stock" / "the stock
count looks too low"** — ask engineering to run, per switch:

```
php artisan port-stock:explain <switch>            # every port, one verdict each
php artisan port-stock:explain <switch> --only-failing
```

It prints each port's FIRST failing condition in plain words ("panel state
Available (needs Prewired)", "no patch panel port linked", "port use is
unset (needs Peering)", "type not offered at this site", …) — fix what it
names and the port appears in stock on the next page load. Common causes:
the panel port isn't linked in IXP-Manager at all, the panel state is
something other than *Prewired*, or the switch port's use isn't set to
*Peering*.

**"Customer says they can't order"** — in order: do they have an MSA
recorded (or exempt type)? are they a **customer admin** (regular users
can't order)? is maintenance mode on? is there stock of what they want at
that DC?

**"IXP-Manager lists ports the switch doesn't have (stale/duplicate
rows)"** — engineering runs `php artisan switch:prune-stale-ports
<switch>` (dry run — shows what it would remove) then adds `--delete`.
It only ever removes rows with no service, no panel link and no order
attached; anything attached is reported for a human decision.

**"Detection shows something weird"** — screenshot the Detect Transceivers
page (expand the raw rows) and send it to engineering; don't Save if it
looks wrong.

## New switch checklist

1. Add the switch (SNMP) and add its ports via the SNMP "View / Edit Ports"
   action, as usual.
2. Set port types correctly — customer-facing ports **Peering**, uplinks/
   inter-switch **Core** (Core ports can never become sellable stock).
3. Switch dropdown → **Detect Transceivers** → Save.
4. Link patch panel ports and mark the pre-patched ones **Prewired**.
5. Confirm the new capacity on **Port Stock**.
