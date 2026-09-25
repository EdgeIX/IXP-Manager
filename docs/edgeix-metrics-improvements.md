# EdgeIX metrics pipeline — required changes and improvements

Working list for the metrics pipeline: `ixpmanager_exporter`, the `edgeix-metrics`
compose stack (gnmic → vmagent → VictoriaMetrics → vmalert/Grafana), and the
VictoriaMetrics grapher backend in IXP-Manager. Items are ordered by priority.
Work them top to bottom; tick the status column as each lands.

Reviewed 2026-09-23 against: exporter v1.1.0, IXP-Manager post-v7.3.0 (prod
2026-09-22), edgeix-metrics working tree (pulled from the prod box).

## Status board

| # | Item | Priority | Repo | Status |
|---|------|----------|------|--------|
| 1 | Rotate every credential committed to `tardoe/edgeix-prod` | P0 | edgeix-metrics + devices | in progress — IXP-Manager API key rotated 2026-09-23; gNMI, SNMP, InfluxDB pending (gNMI waits for the edgeix-metrics cut-over) |
| 2 | Re-home edgeix-metrics in a clean private repo, secrets via `.env` | P0 | edgeix-metrics | in progress — local restructure done 2026-09-23, awaiting prod verification + push to `EdgeIX/edgeix-metrics` |
| 3 | Exporter: `/admin` API prefix, fail-fast on 4xx, golden test | P1 | ixpmanager_exporter | **DEPLOYED to prod 2026-09-23** (v1.2.0 built on the metrics host, swapped into the existing edgeix-prod compose; pre-flight diff vs v1.1 showed only added lines). Re-point old-vs-new checks at `count(ixpmanager_up==1)` in VM |
| 4 | Exporter: rotate to `ixpm_` API key with IP allow-list, yearly reminder | P1 | IXP-Manager admin | done 2026-09-23 (new key live on prod; set a 2027-09 rotation reminder) |
| 5 | Grapher: `avg_over_time` / `max_over_time` per step (peaks are currently lost) | P1 | IXP-Manager, ixpm-pseudowire | todo |
| 6 | Pipeline-health alerts (gnmic down, stale counters, exporter cache) | P2 | edgeix-metrics | todo |
| 7 | Exporter self-monitoring metrics | P2 | ixpmanager_exporter | done in v1.2.0 (`ixpmanager_up`, `_last_status_code`, `_cache_age_seconds`, `_fetch_duration_seconds`) |
| 8 | Site-aware aggregates replace hand-written city rules | P2 | edgeix-metrics | todo |
| 9 | Gap-tolerant traffic-drop alerts | P2 | edgeix-metrics | todo |
| 10 | Exporter HTTP service discovery for switches | P3 | ixpmanager_exporter + edgeix-metrics | todo |
| 11 | Grafana dashboards as code | P3 | edgeix-metrics | todo |
| 12 | 5m / 1h rollup recording rules, 95th percentile | P3 | edgeix-metrics + IXP-Manager | todo |
| 13 | Pseudowire enrichment (`type="pseudowire"`) | P3 | ixpm-pseudowire + exporter | todo |
| 14 | gnmic: on-change for status paths, pin image versions | P3 | edgeix-metrics | todo |
| 15 | Exporter hygiene (escaping, atomic cache, base image, healthz) | P4 | ixpmanager_exporter | done in v1.2.0 (python:3.12-slim + gunicorn, non-root, healthcheck, target validation) |
| 16 | Grapher: shared query helper, null instead of 0 for missing TX | P4 | IXP-Manager, ixpm-pseudowire | todo |
| 17 | Repo tidy: dead Prometheus config, `.bak`, logs, unused Dockerfile | P4 | edgeix-metrics | partly done with item 2 (rule_files, .bak, prometheus-empty.yml, Dockerfile removed; influx service + `prometheus:9090` self-scrape job still to decide) |
| 18 | Core-link enrichment from IXP-Manager (`ixpmanager_corelink`) + weathermap | P3 | ixpmanager_exporter + edgeix-metrics + IXP-Manager | todo |

## Contract that must not change

Grafana dashboards and vmalert rules are built on the exporter's output. Any
change to the items below breaks graphs or silently stops recording rules.

- Metric names: `ixpmanager_port`, `ixpmanager_svc`, `ixpmanager_serving_cached`.
- Label names and values on those metrics: `device`, `interface_name`, `customer`,
  `asn`, `member`, `bundle`, `bundle_parent`, `parent_interface`, `peering_vlan`,
  `type`, `instance`.
- `device_interface` is NOT emitted by the exporter. vmagent builds it from
  `device` + `interface_name` in `metric_relabel_configs`
  (`prometheus/prometheus.yml`, ixpmanager job). Every recording rule
  and port alert joins on it with `* on (device_interface) group_left(...)`.
- vmagent overwrites `instance` with the switch name; the exporter's own value
  ends up in `exported_instance`. Rules don't use it, dashboards might. Keep it.
- `Port-channel` → `Port-Channel` casing is fixed by relabel. Don't fix it in the
  exporter.
- Never emit two series with the same `device` + `interface_name` on one metric.
  That makes the join many-to-many and vmalert stops producing the whole rule group.
- New data goes on NEW metric names. Don't add labels to the existing two metrics.

Cut-over guard for any exporter release: run old and new images side by side,
loop the target list from `prometheus.yml`, diff sorted `/metrics?target=X` per
switch. Only added lines are acceptable.

---

## P0 — security

### 1. Rotate every credential committed to `tardoe/edgeix-prod`

**Finding.** The repo `github.com/tardoe/edgeix-prod` tracks
`env.ixpmanager_exporter` (IXP-Manager API key, present since the initial commit)
and `gnmic/gnmic.yaml` (gNMI username/password for every PE, `insecure: true`).
Both are pushed to origin. Untracked on disk but on the prod box:
`env.influxdb` (InfluxDB admin password), `gnmic/gnmic_debug.yaml` (same gNMI
password), `snmp-exporter/snmp.yml` is tracked and carries an SNMP community.
The repo is **private** (confirmed 2026-09-23) but sits under a personal GitHub
account rather than the EdgeIX org, so access is tied to one person's account
and its collaborators. Exposure is limited, but the credentials are still in git
history and will travel with any clone; rotate them as part of the move rather
than carrying them into the org repo.

**Do.**
1. IXP-Manager: create a new `ixpm_`-format API key for the exporter user with
   `allowed_ips` set to the metrics box; revoke the old key. (Same action as item 4.)
2. Aristas: change the `gnmi` user password fleet-wide (31 PEs + 3 CDN routers in
   `gnmic.yaml`). Consider a TLS gNMI listener so `insecure: true` can go.
3. SNMP: change the community on the device(s) polled by snmp_exporter.
4. InfluxDB admin password (if InfluxDB is still needed at all — it is only used
   by the retired migration scripts).

**Verify.** gnmic reconnects to all targets, exporter `/metrics` returns 200 with
the new key, snmp_exporter scrapes succeed, old key returns 401.

### 2. Re-home edgeix-metrics in a clean private repo, secrets via `.env`

**Finding.** History is 3 commits; the working tree on prod has drifted far from
git (`prometheus/rules/`, `gnmic/processors/`, migration scripts and
`prometheus-empty.yml` are untracked; `prometheus/rules.yml` is deleted but still
tracked). "Pull on prod to update" doesn't work today. The `Dockerfile` (telegraf)
is not referenced by any `build:` stanza.

**Target layout.**
```
edgeix-metrics/
  docker-compose.yaml        # no secrets; ${VAR} interpolation from .env
  .env.example               # every variable, placeholder values, committed
  .env                       # real values, gitignored, lives only on prod
  .gitignore                 # .env, *.log, *.bak, influxdb/
  gnmic/gnmic.yaml           # no password; GNMIC_PASSWORD env in compose
  gnmic/processors/
  prometheus/prometheus.yml
  prometheus/rules/*.yml
  snmp-exporter/snmp.yml     # generated modules only
  snmp-exporter/auth.yml     # gitignored; second --config.file
  grafana/grafana.ini        # no secrets (admin pw via GF_SECURITY_ADMIN_PASSWORD)
  grafana/provisioning/      # item 11
  README.md                  # deploy runbook
```

**How each secret moves.**
- Exporter: `env_file: .env` or `environment: IXP_MANAGER_API_KEY=${IXP_MANAGER_API_KEY}`.
  Drop `env.ixpmanager_exporter`.
- gnmic: remove `password:` from the YAML; set `GNMIC_PASSWORD` (and
  `GNMIC_USERNAME`) in the gnmic service `environment:` from `.env`. gnmic reads
  any flag from a `GNMIC_`-prefixed env var; the global value applies to targets
  without their own.
- snmp_exporter: keep the `auths:` block in a gitignored `auth.yml` and pass
  `--config.file` twice (supported since v0.24; files are merged). Pin the image
  version when doing this.
- Grafana: nothing secret in `grafana.ini` today. Admin password goes in `.env` as
  `GF_SECURITY_ADMIN_PASSWORD` if/when it's set.
- InfluxDB: `.env` or delete the service (see item 17).

**Repo move.**
- Create a fresh private repo `EdgeIX/edgeix-metrics` (decided 2026-09-23)
  from the cleaned tree; do not carry the old history. Archive or delete `tardoe/edgeix-prod` after item 1 is complete. A
  fresh repo is simpler and safer than `git filter-repo` on 3 commits.
- Add `gitleaks` as a pre-commit hook and as a GitHub Action so a secret can't
  land again.
- Commit everything prod actually runs: `rules/`, `processors/`,
  `prometheus-empty.yml` (or delete it — see item 17).

**Deploy flow on prod.**
```
cd /opt/edgeix-metrics && git pull --ff-only
docker compose config >/dev/null      # fails fast if a ${VAR} is unset
docker compose up -d
docker compose exec vmalert wget -qO- localhost:8880/-/reload   # or restart vmalert
```
Pin image tags (`victoriametrics/*`, `gnmic`, `grafana`) so a pull on prod
doesn't also upgrade software unexpectedly (item 14).

**Verify.** `git status` clean on prod after deploy; `grep -r` for the old
secrets returns nothing; gitleaks passes on the new repo.

**Progress 2026-09-23 (local working tree only, nothing committed).**
- `docker-compose.yaml` rewritten: all secrets via `${VAR:?...}` from `.env`;
  image tags via `${*_VERSION:-...}`; dead prometheus/gnmic_debug/alertmanager
  blocks removed; obsolete `version:` key dropped.
- `.env` (mode 600, gitignored) holds the CURRENT credentials so the stack can be
  verified unchanged before rotation. `.env.example` mirrors every key.
- gnmic: `username:`/`password:` removed from `gnmic.yaml` and `gnmic_debug.yaml`;
  supplied via `GNMIC_USERNAME`/`GNMIC_PASSWORD` (gnmic docs: env overrides file).
- Removed: `env.ixpmanager_exporter`, `env.influxdb`, `Dockerfile` (unused
  telegraf), `prometheus/rules.yml.bak`, `prometheus/prometheus-empty.yml`;
  `rule_files:` and the duplicate `pe1drw1` target dropped from `prometheus.yml`.
- `README.md` added (layout, secrets policy, deploy + verification runbook).
- `grafana.ini` local diff vs git is prod drift (local tree was pulled from prod) — no action.
- Found: `rules/core-links.yaml` is NOT loaded by vmalert (`*.yml` glob). Left
  as-is; renaming activates never-evaluated alerts.
- `snmp` service IS scraped (job `snmp_apcpdu`, APC PDUs, module `apcups`) and
  working — an earlier note saying otherwise was wrong. `snmp.yml` is generated,
  pre-v0.23 format, community baked in → gitignored (still tracked → needs
  `git rm --cached`). Pin `SNMP_EXPORTER_VERSION` to the running image before
  any re-pull; v0.23+ rejects the old format.
- Prometheus→VM migration scripts and logs removed by Joe 2026-09-23 (migration
  complete). InfluxDB service is now only a leftover — remove with its volume
  and the `INFLUXDB_*` vars when ready.
- 2026-09-23 later: `edgeix-prod/` contents moved to the repo root (local dir is
  now `/Users/joe/git/edgeix/edgeix-metrics`). Exporter service switched to a
  BuildKit git-context build: `build.context: git@github.com:EdgeIX/ixpmanager_exporter.git#${IXPMANAGER_EXPORTER_VERSION}`,
  `ssh: default=${EXPORTER_DEPLOY_KEY}`, `pull_policy: build` — `docker compose up -d`
  builds from the tag, no registry, no second checkout. Needs a read-only deploy
  key on the exporter repo (GitHub: one deploy key per repo, so separate from the
  metrics-repo key).
- Exporter v1.2.0 was rolled into the EXISTING `/opt/edgeix-prod` stack on
  2026-09-23 (image built on host from the v1.2.0 tag with deploy key
  `/root/.ssh/edgeix_exporter_deploy`; only the image line in the old compose
  changed). Compose project name confirmed `edgeix-prod` → matches the `name:`
  pin in the new compose. Old `prom01` container is an orphan — `--remove-orphans`.
- Post-deploy checks 2026-09-23: all 29 targets healthy except `pe1syd3`
  (404 — decommissioned; removed from prod and from prometheus.yml + gnmic
  configs in the working tree 2026-09-23). vmagent had been running a stale target list —
  `pe1mel4`/`pe1drw2` were in the file but never loaded until a SIGHUP; README
  deploy flow must include `docker compose kill -s HUP vmagent` after any
  prometheus.yml change. `pe2per2`, `pe1cbr1` are active in IXP-Manager but in
  neither scrape list (item 10 territory).
- Remaining before push: run `docker compose config` + `up -d` on prod and the
  README verification block; fresh `git init` + `git add -A` (drops the tracked
  `snmp.yml`/old env file automatically); create `EdgeIX/edgeix-metrics` and push.

---

## P1 — must do before the API securing sweep flips

### 3. Exporter: `/admin` prefix, fail-fast on 4xx, golden test

**Finding.** `app/main.py` `_fetch_from_api` calls
`/api/v4/provisioner/layer2interfaces/switch-name/{device}.json`. v7.2.0 moved
secured APIs under `/admin/api/v4/...`; the old path only exists while prod has
`UNSECURED_API_ACCESS=true`, which the securing plan
(`docs/edgeix-upstream-merges.md`, "API securing plan") will remove. Payload
shape is unchanged — every field the exporter reads is still produced by
`app/Tasks/Yaml/SwitchConfigurationGenerator.php`.

**Do.**
1. Change the URL prefix to `/admin/api/v4/provisioner/...`. Output is
   byte-identical.
2. In `_get_ixp_manager_interfaces`, only fall back to cache on 5xx / timeout /
   connection error. Return 503 immediately on 401/403/404 with the status in the
   body, so an expired key or a removed route is visible at once instead of hiding
   behind the 2-hour cache.
3. Add `tests/`: one real captured payload as a fixture, assert exact rendered
   output (golden file), and assert `device`+`interface_name` uniqueness per metric.
4. README: document the prefix and key expiry. Bump `pyproject.toml` to 1.2.0.
5. Build, push `ghcr.io/tardoe/ixpmanager_exporter:v1.2`, run the cut-over diff
   above, bump the tag in compose.
6. Note the metrics box IP in the securing-sweep log inventory (plan step 5).

**Progress 2026-09-23 — v1.2.0 written and tested locally, not yet pushed.**
- `IXP_MANAGER_API_PREFIX` env (default `/admin/api/v4`; `/api/v4` for rollback).
- 4xx → 503 immediately, cache untouched; 5xx/timeouts → cache fallback as before.
- Golden test: fixture payload rendered by the v1.1.0 code from git, new code must
  match sample-for-sample. Plus uniqueness, escaping, core-entry, HTTP-path tests.
- Found + fixed: `type: core` payload entries have no `asnum`/`customVlanTag` →
  v1.1.0 KeyErrors on any switch with a core bundle in IXP-Manager. Now skipped.
- Image: `python:3.12-slim` + gunicorn, non-root, `/healthz` healthcheck. uwsgi,
  uv.lock, Dockerfile.dev removed. Built on the prod host from the private repo
  (`EdgeIX/ixpmanager_exporter`) by compose itself from the git tag in `.env`.
- Staff runbook (build/update/verify/rollback/key rotation) in the exporter README.
- Docker build NOT tested locally (no daemon on the laptop) — first build happens
  on the host; the README's old-vs-new diff loop is the gate before cut-over.

Sibling consumer: `edgeix-configgen` README still documents
`https://ixp.edgeix.net.au/api/v4/provisioner` as `BASE_URL` — same change
(plan step 3).

### 4. Exporter API key rotation

Legacy keys were back-filled with an expiry 12 months from the v7.3.0 migration
run (prod: ~2027-09-22) and would then return 401 (`ApiAuthenticate.php`). New
`ixpm_` keys have a max lifetime of one year (`IXP_FE_API_KEYS_MAX_EXPIRES_DURATION`).
Rotate now (covers item 1), set `allowed_ips`, add a yearly calendar reminder,
and once item 7 lands alert on `ixpmanager_last_status_code == 401`.

### 5. Grapher: real avg/max per step

**Finding.** `app/Services/Grapher/Backend/VictoriaMetrics.php::data()` runs a
plain `query_range` on the `:10s` gauges with step 60s / 300s / 1800s / 86400s and
stores the sampled value as both avg and max. A range query returns one sample
per step, so the week view sees 1 in 30 samples, month 1 in 180, year 1 in 8640.
Peaks are lost, "max" in the stats panel is the max of sparse samples, and
`Statistics.php` totals are built on the same points. `PwTrafficService` and
`VliTrafficService` share the period map (`config/pseudowire.php` → `metrics.periods`)
and the same defect.

**Do.**
- Issue two queries per direction: `avg_over_time(<expr>[<step>])` and
  `max_over_time(<expr>[<step>])`, and populate the `[ts, avg_in, avg_out,
  max_in, max_out]` tuple properly. For the raw-counter fallback wrap the `rate()`
  expression the same way.
- Apply the same in `PwTrafficService::buildQuery`/`buildRawQuery` and
  `VliTrafficService`, or (item 16) via one shared helper.
- Any Grafana panel that queries `:10s` rules at week+ ranges needs the same
  wrapping, or should move to the rollups in item 12.

**Verify.** Month view max for a busy port matches `max_over_time(port_bitrate_tx:10s[30d])`
queried directly in VM. Customer-visible change: graphs get taller at long ranges.

---

## P2 — operational visibility

### 6. Pipeline-health alerts (new rule file `rules/pipeline-health.yml`)

- `up{job="gnmic"} == 0`, `up{job="ixpmanager"} == 0`.
- `absent_over_time(openconfig_interfaces_in_octets{device="X"}[5m])` per device
  (use `count by (device)` of a recent-sample check rather than one rule per switch).
- `ixpmanager_serving_cached == 1` for > 15m; `ixpmanager_cache_age_seconds > 3600`
  (after item 7).
- `count by (device) (ixpmanager_port) == 0` — a switch dropped out of enrichment.
- Rule-group evaluation errors: vmalert exposes `vmalert_alerting_rules_error` /
  `vmalert_recording_rules_error`; alert on `> 0` — this is how a many-to-many
  join gets noticed.

### 7. Exporter self-monitoring (new metric names only)

`ixpmanager_up`, `ixpmanager_last_status_code`, `ixpmanager_cache_age_seconds`,
`ixpmanager_fetch_duration_seconds`, plus `# HELP`/`# TYPE` on the existing
metrics. All additive.

### 8. Site-aware aggregates

**Finding.** `rules/recording-rules.yml` has eight hand-written
`aggregate_<city>:10s` rules. Hobart (`pe1hba1`) is scraped but has no rule;
Darwin has one but is absent from the national sum used by
`traffic-critical.yml` and `traffic-milestones.yml`.

**Do.** In the gnmic job's `metric_relabel_configs` derive `site` from `device`
(`pe\d+([a-z]{3})\d+` → `$1`). Then one rule
`sum by (site) (port_bitrate_tx:10s{interface_name=~"Ethernet.*"})` replaces the
eight, and `sum(...)` replaces the hand-added national expression. Keep the old
`aggregate_*` names for one release (alias rules) until dashboards are migrated
(item 11), then drop them.

### 9. Gap-tolerant traffic-drop alerts

`sum()` over per-port gauges dips whenever gnmic reconnects to a switch or a
sample is late, so `NationalTrafficDrop` etc. fire on pipeline noise. Sum an
`avg_over_time(...[1m])` (or use `aggregate_*` derived from the 5m rollup in
item 12) and lengthen `for:`. Milestone alerts should use `max_over_time` over a
day so they fire once, not on every crossing.

---

## P3 — make the pipeline dynamic

### 10. Exporter HTTP service discovery

The 31-entry static target list in `prometheus.yml` (with `pe1drw1` twice) is
the last hand-maintained switch list. Add `/sd` to the exporter backed by
`/admin/api/v4/provisioner/switch/list.json`, filtered to `active` switches,
returning `[{"targets": ["pe1syd1"], "labels": {}}]`. Switch the vmagent job to
`http_sd_configs` with a refresh interval of 5m. Existing relabelling
(`__address__` → `__param_target` → `instance`, `__address__` → exporter) is
unchanged. gnmic targets remain static (needs device IPs) — a follow-up could
generate `gnmic.yaml` targets from the same endpoint.

### 11. Grafana dashboards as code

Dashboards exist only in the `grafana-volume`. Export each as JSON into
`grafana/provisioning/dashboards/`, add a provisioning `dashboards.yaml` and a
datasource file, and mount them. Then audit the JSON once for every exporter
label and rule name (`ixpmanager_`, `exported_instance`, `peering_vlan`,
`type=`, `aggregate_`) so the contract above is verified rather than assumed.
Also upgrade Grafana from 10.2.1.

### 12. Rollups and 95th percentile

- Recording rules `port_bitrate_{rx,tx}:5m` (`avg_over_time` and a `_max`
  variant) and `:1h` from the `:10s` series; same for `svc_*`. VM OSS has no
  downsampling, retention is 10y at 10s, and year queries currently walk millions
  of points per series.
- Grapher picks the rollup per period: day → `:10s`, week/month → `:5m`,
  year → `:1h`. Fixes item 5's cost at long ranges.
- `quantile_over_time(0.95, port_bitrate_tx:5m[30d])` for the billing-standard
  95th percentile; expose in `Statistics` and the customer stats panel; feeds the
  PW capacity tracking.

### 13. Pseudowire enrichment

Prerequisite: the per-switch PW API (`ixpm-pseudowire`
`PwCircuitController::resolveEndpoint`) returns the full customer name with
hyphens, whereas `ixpmanager_svc` uses `abbreviatedName`. Add
`customer_abbreviated_name` to that API first, otherwise a customer gets two
`customer` label values and every `label_values(customer)` variable shows both.
Then the exporter emits `ixpmanager_svc{type="pseudowire"}` rows from
`/api/v4/pseudowire/switch-name/{name}/circuits.json` (`interface` + `subif_vlan`),
deduplicated against peering rows on `device`+`interface_name`. Panels without a
`type` filter will start showing PW rows — check item 11's audit first. Once
present, `PwTrafficService` stops falling back to raw counters.

Side note: that PW API group is registered at `api/v4/pseudowire`, not under
`/admin/`. Authenticated, but inconsistent with the securing-sweep convention.

### 14. gnmic and image pinning

- `oper-status` / `admin-status` are subscribed in `sample` mode at 10s, so
  flaps shorter than 10s are invisible to `changes()`. Put the state paths in
  their own subscription with `stream-mode: on-change`.
- `gnmic`, `victoria-metrics`, `vmagent`, `vmalert` all run `:latest`. Pin them.

---

### 18. Core-link enrichment from IXP-Manager and weathermap

**Today.** `rules/core-links.yaml` (9 rules, currently NOT loaded — `.yaml` vs the
`*.yml` glob) finds core links by regex on port descriptions:
`openconfig_interfaces_description{description=~".*CORE:.*"}`. That depends on
humans keeping descriptions consistent, gives no remote end, and can't drive a
topology view. The original intent was to generate these from IXP-Manager and
build the weathermap from the same data.

**Design (additive — no existing metric or label changes).**
- Exporter emits a new info metric per core interface on the scraped switch,
  sourced from `/admin/api/v4/provisioner/layer3interfaces/switch-name/{name}.json`
  (fork already returns `remote_switch`, `remote_port`, `cost`, `preference`,
  `speed`, `mtu`, `description`, `shutdown`):
  ```
  ixpmanager_corelink{device="pe1syd1",interface_name="Ethernet49/1",
    remote_device="pe1syd2",remote_interface="Ethernet49/1",
    bundle="Port-Channel1",type="l3-lag",cost="10",preference="100",
    description="CORE: pe1syd1 <> pe1syd2"} 1
  ```
  `device` + `interface_name` unique per switch, same as the other two metrics,
  so the existing `device_interface` relabel applies unchanged. Also emit the
  bundle (Port-Channel) row for L3-LAG bundles so `openconfig_interfaces_lag_speed`
  joins.
- Generic static rules replace the description regex, one file, never
  regenerated. Rename `core-links.yaml` → `core-links.yml` in the same change so
  it starts being evaluated deliberately:
  ```
  core_link_bitrate_tx:10s =
    rate(openconfig_interfaces_out_octets[30s]) * 8
    * on (device_interface) group_left(remote_device, remote_interface, bundle, cost, description)
    ixpmanager_corelink
  ```
  Keep the existing `core_link_*` names so nothing downstream moves.
- New PEs/links appear as soon as IXP-Manager has the core bundle — no config
  edits (pairs with item 10 for switch discovery).
- Weathermap: `core_link_bitrate_{rx,tx}:10s` and `core_link_speed` now carry
  `device`/`remote_device` — that is an edge list with utilisation. Feed a
  Grafana node-graph/weathermap panel from it, or render it in IXP-Manager's
  skin from the same VM queries (the grapher backend already talks to VM).
  Sites for node placement can come from the `switch` provisioner endpoint
  (`location`, `location_shortname`, `cabinet` — also fork additions).

**Order.** After item 3 (exporter on `/admin` + golden test) and item 6 (rule
error alerting, so a bad join is noticed). Nothing in it touches the frozen
contract.

**Verify.** `count(ixpmanager_corelink)` equals the number of core interfaces in
IXP-Manager; `core_link_bitrate_tx:10s` series count matches; alerts in
`core-links.yml` evaluate without error in vmalert UI.

## P4 — hygiene

### 15. Exporter hygiene (no output change)

Escape `\`, `"`, newline in label values; write cache via temp file +
`os.replace`; validate env at startup; `/healthz`; `requests.Session` with a
configurable timeout; replace the unmaintained `tiangolo/uwsgi-nginx-flask` base
with `python:3.12-slim` + gunicorn.

### 16. Grapher code

- One shared VM query helper used by the core backend, `PwTrafficService` and
  `VliTrafficService` (three copies of the same PromQL/range logic today).
- In `VictoriaMetrics::data()` a TX timestamp missing from the RX set becomes
  `0.0`, drawing a dip that isn't there. Emit `null` and let uPlot gap it.

### 17. edgeix-metrics tidy

- `rule_files: rules.yml` in `prometheus.yml` is dead (Prometheus is commented
  out; vmalert loads `rules/*.yml`). Remove it and `rules.yml.bak`.
- Migration scripts/logs: removed 2026-09-23. InfluxDB service + `influxdb-volume`
  + `INFLUXDB_*` env: remove (migration complete).
- Delete the unused telegraf `Dockerfile` or move it to a `telegraf/` dir with a
  `build:` stanza if it's still wanted.
- Note `-promscrape.config.strictParse=false` on vmagent hides config typos;
  turn it back on once the config is clean.
