# EdgeIX Upstream Merge Log

Tracks merges from `inex/IXP-Manager` (remote `upstream`) into the EdgeIX fork,
what was resolved, and the current deploy state. Newest first.

Process reference: create `merge/vX.Y.Z` off `release-v7`, `git merge vX.Y.Z`
(the tag, not the branch), resolve, test on dev, `git merge --no-ff` back to
`release-v7`, tag `edgeix-vX.Y.Z-merge-YYYY-MM-DD`, deploy.

---

## Current state (2026-08-19)

| What | Where |
|---|---|
| `release-v7` (mainline) | v7.2.0 + v7.3.0 merged — commit `7c522d8ae`, tag `edgeix-v7.3.0-merge-2026-08-19`, pushed |
| Dev VM | Running the merged code, all migrations applied, bgpq4 enabled |
| **Prod** | **NOT yet deployed** — still pre-v7.2.0. Deploy runbook below |
| v7.4.0 + v7.3.1 | Released upstream, **not merged yet** — planned as a fresh cycle after prod is on v7.3.0 |

### Pre-prod test status (dev)

- [x] Core smoke tests (login, admin, customer views, wizard)
- [x] v7.2.0 features: bgpq4, bgp.tools ASN DB (121k rows), API 404-for-unauth, admin lockout
- [x] v7.3.0 features: App Passwords tables, API key expiry backfill (+12mo, all legacy keys)
- [x] Multi-p2p graphs end-to-end (see Akvorado notes below)
- [x] Signup end-to-end (welcome email, set-password link, recovery chain, admin list visibility)
- [x] bgpq4 vs bgpq3 A/B — identical prefix/ASN output
- [ ] Router config generation — one per template flavour (matrix below), validate with `bird -p -c`
- [ ] API-key consumers: config agent, mac-sync webhook, Nagios scripts,
      `get-switch-config-RESOLD.py`, IX-F/member-export, any script using `?apikey=` URL
      params (v7.3.0 rewrote the API auth middleware)
- [ ] `php artisan schedule:list` sanity (new v7.3.0 jobs: API key + app password expiry reminders)

### Router template matrix (prod, 2026-08-19)

| Template | Routers | Reconfigure (api_type) |
|---|---|---|
| `bird2-addpath-rfc9234` | 27 route servers | 1 (old script) |
| `bird2-addpath-rfc9234-2025` | rs1-adl-ipv4/6, rs2-drw-ipv6 | 2 (new script, EOF-marker) |
| `bird2-addpath-rfc9234-bfd-2026` | rs2-drw-ipv4 (passive-BFD pilot) | 2 |
| `bird2/standard-addpath` | rs1-syd-ipv6 — **stale outlier**, migrate to rfc9234 post-deploy | 1 |
| `as112/bird2/install-routes` | 4× as112 | 1 |

Variant lineage: `rfc9234` (old BIRD syntax) → `-2025` (modern syntax, RFC 8326
graceful shutdown, explicit `authentication md5`, RTR version pinning, EOF marker)
→ `-bfd-2026` (adds passive BFD). Dev has no bfd-2026 router — flip one
temporarily to test that template's config gen.

### Prod deploy runbook (v7.3.0)

1. Announce/freeze; full DB backup + separate `mysqldump ixpmanager api_keys`
2. **Add `UNSECURED_API_ACCESS=true` to prod `.env`.** Verified 2026-08-19: all
   route-server reconfigure scripts (`api-reconfigure-birdv2.sh` on each rs box)
   call the un-prefixed `/api/v4/router/...` endpoints, and prod `.env` doesn't
   pin this flag — its default flips true→false in v7.2.0, which would break
   sync on all 31 route servers at deploy. This env var is TRANSITIONAL:
   follow-up is to migrate the scripts to `/admin/api/v4/...` URLs, then remove
   the var to get the v7.2.0 hardening.
3. `git pull` on `release-v7`
3. `composer update bluntelk/ixpmanager-xero edgeix/ixpm-pseudowire edgeix/ixpm-mac-sync --with-all-dependencies`
   then `composer install --no-dev --optimize-autoloader` (lock drifts on custom packages)
4. `php artisan migrate --force` — 4 migrations: `remove_user_privs`, `create_asn_table`,
   `create_app_passwords_table` ×2, `set_api_keys_expires_not_nullable`
   (renames api_keys columns to snake_case, backfills expiry to +12 months)
5. `php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear`
6. Apache security headers: `a2enmod headers` + X-Frame-Options vhost directive
   (see upstream `tools/installers/ubuntu-lts-2404-ixp-manager-v7.sh`), reload
7. Restart Apache (OPcache)
8. `php artisan utils:asn-update` (one-off bgp.tools ASN populate; scheduler maintains it)
9. Smoke test: login, admin + customer overviews, port wizard, config gen,
   signup page, p2p-totals graph, Xero tab; verify every external API consumer
10. Unfreeze

Decisions during window: enable bgpq4 on prod (`IXP_IRRDB_UTILITY=bgpq4`,
needs `apt install bgpq4`) — stable on dev since 2026-07. If a p2p graph is
blank, check configured MACs against Akvorado's observed SrcMACs before
suspecting code (dev had a stale Adelaide MAC).

---

## 2026-08-19 — v7.2.0 + v7.3.0 (merge commit `7c522d8ae`)

Branch `merge/v7.3.0` (started as `merge/v7.2.0`, v7.3.0 merged on top with
zero conflicts). 222 commits over `release-v7`.

**v7.2.0 conflicts resolved (4):**
- `routes/web.php` — kept our `statistics/top-n` + upstream `p2p-totals`/`p2p-per-vlan`
- `config/ixp_api.php` — took upstream `bgp.tools` whois-prefix default (prod `.env` overrides)
- `Console/Commands/Irrdb/UpdateAsnDb.php` — adopted new `(IrrQuerier, Customer, ?array $protocols)`
  signature, kept the IPv4-only `[ 4 ]` filter (TODO: verify still needed post-bgp.tools)
- `Providers/LookingGlassServiceProvider.php` — merged upstream route names with our extra
  LG routes (filtered / not-exported / community)

**EdgeIX adaptations:**
- Signup: dropped `$user->privs` write (v7.2.0 removed the column; privs live on
  `customer_to_users` pivot only)
- `composer.lock` drift: custom packages must be re-added via targeted
  `composer update` after upstream lock merges
- Akvorado backend: full **MultiP2p** support — parallel HTTP (`Http::pool()` in
  `AkvoradoService::queryTimeSeriesParallel()`), month/year served from
  `p2p_daily_stats` table, retry-on-5xx, VLAN-filtered IN queries
- Fixed upstream bug: `MultiP2p::identifier()` missing the VLAN filter → per-VLAN
  cards shared one cache key (commit `a5b452ed2` — **worth upstreaming**)
- Skin: p2p-totals + p2p-per-vlan moved to `boxUplot()` (Akvorado has no server-side
  PNG), per-VLAN drill-down button, uplot empty-state height, P2P Traffic Matrix
  menu links (gated sflow OR akvorado), deleted stale `p2p-table.foil.php` override
- Signup thanks page: "didn't get the email" → forgot-password flow (is the resend
  mechanism — same PasswordBroker token)

**Post-merge follow-ups (open):**
- Legacy API keys: 7 keys backfilled to expire 2027-07-08 — rotate to token-hash
  scheme before then (each has an external consumer: Nagios, PeeringDB, DNS, IXPDB…)
- Skin drift: 97 files behind upstream, top-10 port list in the audit
  (memory: `skin-drift-audit-2026-07-08`) — no security impact
- rs1-syd-ipv6 stale template migration; wider passive-BFD rollout
- **Migrate reconfigure scripts to `/admin/api/v4/...` URLs** on all rs boxes
  (`api-reconfigure-birdv2.sh`, one-line URL change), then remove the
  transitional `UNSECURED_API_ACCESS=true` from prod `.env`
- Verify the IPv4-only `[ 4 ]` filter in `UpdateAsnDb` is still doing anything
- Upstream the MultiP2p identifier fix

## Planned — v7.4.0 (+ v7.3.1)

219 commits, 314 files. Mostly a new system-validation framework (admin
diagnostics for security headers, version, Nagios/grapher config), QA/test
coverage, framework updates. **Not a security release** — no urgency over
getting v7.3.0 to prod first.

Merge watch-items:
- Only `config/ixp_api.php` + `config/ixp_fe_settings.php` overlap our changes
- **Upstream removed the RsPrefix model + `rs_prefixes` table**
  (`2026_08_12_113213_remove_rs_prefixes_table`) — we have skin files
  (`search/rsprefixes.foil.php`, `rs-prefixes/*`) and custadmin menu links to
  `rs-prefixes/list`; check what replaced the feature and clean skin/menus
- New `task_last_run` table

## 2026-03-16 — v7.1.0 (memory: `v710-merge-status`)

118 commits, 6 conflicts. Created the `bird2-addpath-rfc9234-2025` template
variant from upstream `bird2-2025`. Details in the memory file.
