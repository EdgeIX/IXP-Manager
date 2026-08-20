# EdgeIX Route Server Modernisation Program

Bring every route server up to the pattern already proven on **rs1-adl (both
protocols) and rs2-drw** — modern template, birdwatcher LG backend, new
reconfigure script. Runs AFTER the v7.3.0 prod deploy
(see `edgeix-upstream-merges.md`).

## Target state (per RS box)

| Component | From | To |
|---|---|---|
| BIRD template | `bird2-addpath-rfc9234` (old syntax) | `bird2-addpath-rfc9234-bfd-2026` |
| LG API daemon | birdseye (`api_type=1`) | birdwatcher (`api_type=2`) |
| Reconfigure script | old URLs, no EOF check | `/admin/api/v4/...` + EOF-marker-aware |
| BIRD version | varies (old boxes) | new enough for `-> bool` fn syntax (2.13+) |
| BFD | none | passive (peers may opt in) |

Note rs2-drw-ipv6 is on `-2025` (no BFD) while its IPv4 twin is on `-bfd-2026`
— align it to `-bfd-2026` as part of this program. rs1-adl pair likewise
(currently `-2025`).

## Current fleet state (2026-08-19 routers table)

- **Done (pattern boxes):** rs1-adl-ipv4/6 (`-2025`, birdwatcher), rs2-drw-ipv4
  (`-bfd-2026`, birdwatcher), rs2-drw-ipv6 (`-2025`, birdwatcher)
- **To migrate (27 handles):** rs1/rs2 at akl, bne, mel, per, syd, adl(rs2),
  drw(rs1), hba(rs1) — all on old template + birdseye
- **Special case:** rs1-syd-ipv6 on ancient `bird2/standard-addpath` — gets
  fixed by this migration; if its window is far out, move it to
  `bird2-addpath-rfc9234` sooner as an interim step
- **as112 ×4 (bne/per):** on birdseye too — decide whether they get
  birdwatcher or stay (LG value is low for as112); they do NOT need the RS
  template work

## Per-box migration runbook

Pre-checks (capture baseline):
1. `birdc show protocols` — session count + states; `birdc show route count`
   per table; save output
2. Note current BIRD version (`bird --version`) — if too old for the 2026
   template syntax, OS/BIRD upgrade is step 0 (this is what makes a box a
   maintenance-window job rather than a config flip)

Migration:
3. Install/upgrade BIRD if needed
4. Install + configure **birdwatcher** daemon (copy config from rs1-adl);
   verify it serves `/protocols/bgp` locally
5. Update reconfigure script: `/admin/api/v4/...` URLs (ties into the API
   securing sweep — same touch) and deploy the EOF-marker-aware script version
   (pairs with the 2026 template's `END_OF_CONFIG_MARKER`)
6. In IXP-Manager routers table (via admin UI): set template to
   `api/v4/router/server/bird2-addpath-rfc9234-bfd-2026/standard`, set
   `api_type` to Birdwatcher, update the `api` URL to the birdwatcher endpoint
7. Trigger reconfigure; watch it pull, EOF-check, `bird -p` validate, apply

Post-checks:
8. Sessions re-established; prefix counts match baseline (±normal churn)
9. LG pages work for this handle: bgp summary, routes, **and the EdgeIX custom
   views** (filtered / not-exported / community) — these exercise our
   `BirdWatcher.php` backend paths
10. `router:check-stale` quiet; last-updated advancing
11. Nagios green **after** monitoring update (below)
12. Decommission birdseye on the box (stop daemon, remove vhost) once stable

## Fleet-level tasks (not per-box)

- [ ] **Nagios monitoring migration** — easy to miss: the generated checks use
  the `birdseye-daemons` / `birdseye-bgp-sessions` API templates. Birdwatcher
  boxes need equivalent daemon + session checks (new/updated nagios templates
  or direct birdwatcher health checks). Update the nagios generation per
  migrated box or monitoring silently goes stale.
- [ ] Optional per-box RPKI RTR version pinning (`ixp.rpki.rtrN.min/max_version`
  env) now available with the 2026 template — decide policy once, apply fleet-wide
- [ ] Announce passive BFD availability to members as sites migrate (it only
  activates if the peer enables it)
- [ ] After full fleet: retire the `bird2-addpath-rfc9234` and
  `bird2-addpath-rfc9234-2025` skin template dirs (and `bird2/standard-addpath`
  usage), leaving `-bfd-2026` as the single RS template
- [ ] Update `edgeix-upstream-merges.md` router matrix as boxes move

## Sequencing

1. Pilot: one small site first — **rs1-hba** or **rs1-drw** (drw already has
   its rs2 twin proven on the target stack)
2. Per site: migrate one of the rs pair, soak ≥1 week, then the twin —
   route server redundancy means members never lose both
3. Suggested order after pilot: drw(rs1) → hba → akl → adl(rs2) → per → bne →
   mel → syd (biggest last, includes the rs1-syd-ipv6 template oddity)
4. Coordinate with the **API securing sweep**: the script URL change (its step
   2) can roll fleet-wide quickly and independently — don't hold API securing
   hostage to the slower box-by-box modernisation. Where a box's migration
   window comes first, do both in that one visit.
