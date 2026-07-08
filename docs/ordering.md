# EdgeIX Customer Ordering — Workflow Spec

> **Note (2026-07-08):** Ordering work is on hold pending the upstream
> IXP-Manager v7.2.0 merge. The "Order Port" CTA + placeholder page ship as-is.
> Phase 2 (MSA gate) and Phase 3 (actual order flows) resume after the merge
> lands.

This document describes the planned customer self-service ordering flows and
the current state of implementation.

## Current state (Phase 2 placeholder — 2026-07-04)

- **"Order Port" button** in the customer admin nav — orange CTA on the right
  side, next to My Account.
- Clicking it goes to `/order` → a "Coming Soon" page describing the three
  planned flows and a `sales@edgeix.net.au` fallback for now.
- No MSA gate is enforced yet. Phase 2 will wrap the order routes in an
  `EnsureMsaSigned` middleware that redirects to the MSA sign flow if the
  customer hasn't signed.

Only `custadmin` users see the button — regular `custuser` accounts don't have
authority to order.

## Planned order sub-flows

The `/order` landing page will branch into three distinct sub-flows. Each has
different data capture, validation, and provisioning steps.

### 1. New Port

Customer wants a new peering port at an existing or new EdgeIX location.

**Captured:**
- Metro (Sydney / Melbourne / Brisbane / Perth / Adelaide / Hobart / Darwin).
- DC / PoP within that metro.
- Port speed (10GbE / 100GbE / 400GbE — availability varies per metro).
- AS-SET (IPv4 + IPv6 if different).
- IRRDB source.
- Optional VLAN ID.
- MAC address(es) — up to 2 per MSA schedule A.
- Delivery contact.

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

## Cancellations

Not in the self-service UI. Customers email `sales@edgeix.net.au` with a
cancellation request; billing terms are governed by their signed MSA (Schedule
A / General Terms).

May be added to the portal in a later phase once the ordering flows are
production-proven.

## Implementation status

| Phase | Scope | Status |
|---|---|---|
| Nav button + placeholder page | This doc | **Done** (2026-07-04) |
| Phase 2 — MSA gate wraps `/order` routes | `EnsureMsaSigned` middleware | Not started |
| Phase 3 — New Port flow | Full form, PoP/DC/speed picker, dynamic availability | Not started |
| Phase 3 — Add to LAG flow | Port selector, LAG conversion detection | Not started |
| Phase 3 — Upgrade Port flow | Existing port picker, IP swing acknowledgement | Not started |
| Phase 3 — Admin approval / provisioning integration | Behind the scenes, uses existing port wizard | Not started |

## Related files

- `app/Http/Controllers/EdgeIX/OrderController.php` — placeholder controller.
- `resources/skins/edgeix/order/index.foil.php` — "Coming Soon" landing view.
- `resources/skins/edgeix/layouts/menus/custadmin.foil.php` — nav button.
- `routes/web-auth.php` — route group (`prefix: order`, `namespace: EdgeIX`).

## Related documentation

- `docs/signup-terms-management.md` — Privacy Policy consent + version stamping
  applied at signup. Phase 2's MSA re-consent will hook into the same version
  tracking.
