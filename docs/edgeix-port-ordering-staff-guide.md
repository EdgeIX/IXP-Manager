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
Types → edit). Every morning at 09:00, if any DC has fewer sellable ports of
a type than its threshold, a digest email goes to the stock alert address —
that's your "go pre-wire more" trigger. Locations that never stock a type
don't alert. The email also nags about unmatched optics.

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

**"A port should be orderable but isn't showing as stock"** — check the
five sellable conditions in order: port active + type Peering? optic
classified (Port Transceivers)? type Active in the catalogue? no service on
the port? panel port marked Prewired? not already reserved by an open order
(Order Queue)?

**"Customer says they can't order"** — in order: do they have an MSA
recorded (or exempt type)? are they a **customer admin** (regular users
can't order)? is maintenance mode on? is there stock of what they want at
that DC?

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
