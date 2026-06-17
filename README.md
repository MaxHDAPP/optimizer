# XC_VM Server Optimizer

A one-stop **streaming optimization** module for [XC_VM](https://github.com/Vateron-Media/XC_VM). It adds a single **Server Optimizer** page to the admin panel that:

- **Tunes** streaming settings to your hardware,
- **Auto-tiers** streams between *always-on* and *on-demand* based on real usage,
- **Rebalances** streams off overloaded load balancers, and
- **Smart-places** popular streams on your strong servers and idle ones on your weak servers.

---

## ⚠️ BETA — use with caution

> This module is **beta software**. Several of its features **move live streams between servers**, which causes those streams' viewers to **reconnect (brief buffering)**. It changes panel settings and stream-to-server assignments in your database.
>
> **Before you rely on it:**
> - **Always use the _Preview_ buttons first** — they are read-only and show exactly what *would* change.
> - **Test on a low-traffic window** before enabling any automation.
> - **Do not enable multiple auto-movers at once** (see [Safety notes](#safety-notes)).
> - **Back up your database** before first use.
> - Test on a **non-production / spare** setup if you can.
>
> No warranty. You are responsible for what it does to your servers. Read [Safety notes](#safety-notes) fully.

---

## What it does

The page has four sections:

### 1. Server Check & Optimize
Reads each installed server's hardware (cores / RAM from the watchdog, bandwidth from each server's **"Network Speed"** field) and:
- Applies safe, well-understood **global streaming settings** sized to your weakest **load balancer** (the info-only main server is ignored): `read_buffer_size` (scaled to RAM), `use_buffer`, `probesize_ondemand`, `on_demand_wait_time`, `client_prebuffer`, `monitor_connection_status`, `probesize`, `stream_max_analyze`, `vod_bitrate_plus`.
- Sets each load balancer's **`total_clients`** (max users) from its own bandwidth ÷ your average stream bitrate, capped by cores — so the panel's load balancer fills each server to a capacity matched to its hardware.

> **Note:** XC_VM's streaming tune settings are **global** (one settings row shared by all servers). They cannot be made per-server without core changes. Per-server tuning is therefore done through **capacity (`total_clients`) + placement**, not per-server buffer values.

### 2. Auto-Tier from Usage Logs
Reads XC_VM's own popularity stats (`streams_stats`, rebuilt hourly by `cron:stats`) and flips the `on_demand` flag:
- **Popular** streams (top-N by rank **or** ≥ a connection threshold, for a chosen window: today/week/month/all) → **always-on** (`on_demand = 0`).
- The rest → **on-demand** (`on_demand = 1`) when "Demote" is enabled.
- Live streams only — **VOD is never touched.**
- **Preview**, **Apply now**, or **run daily** automatically.

### 3. Rebalance Load Balancers
Relieves **overloaded** load balancers. Computes each LB's load (`active clients ÷ total_clients`) and moves relay streams off any LB above your threshold onto LBs with spare capacity — keeping each stream's origin intact.
- **Preview**, **Rebalance now**, or **run every 30 min**.

### 4. Smart Placement
Proactively places **popular streams on strong servers** (spread across them) and **rarely-used streams on weak servers**, sized by each server's capacity. Moves are **capped per run** to limit churn, so it converges over a few runs.
- **Preview**, **Place now**, or **run hourly**.

All four use a safe move model: only **relay** assignments are moved (`streams_servers.server_id`), the **origin (`parent_id`) is preserved**, and the streaming daemon then stops/starts streams on their new servers.

---

## Requirements

- XC_VM **2.0+** (developed against 2.2.x).
- A working `cron:stats` (XC_VM core, on by default) — Auto-Tier and Smart Placement weight by `streams_stats`, which builds from real traffic. On a fresh install, choose a longer window (month/all) until usage history accumulates.
- Each **load balancer's "Network Speed"** field set to its real provisioned Mbps (Servers → edit) — used to size capacity / max-users. RAM and cores are auto-detected.

---

## Installation (manual — required)

> **The panel's "Upload & Install" (zip) does NOT work for this on a standard XC_VM box.** XC_VM ships its **own bundled PHP** that is compiled **without the `zip` extension**, so the panel's module uploader fails with *"ZipArchive extension is not available."* Install by **copying the folder** instead — no zip, no extension needed.

1. Copy the `optimizer/` folder into your XC_VM modules directory:

   ```bash
   # from your machine
   scp -r optimizer root@YOUR_SERVER_IP:/home/xc_vm/modules/
   ```

   …or upload it over SFTP into `/home/xc_vm/modules/optimizer/`.

2. Fix ownership on the server:

   ```bash
   chown -R --reference=/home/xc_vm/modules /home/xc_vm/modules/optimizer
   ```

3. (Optional) lint:

   ```bash
   for f in /home/xc_vm/modules/optimizer/*.php; do php -l "$f"; done
   ```

That's it. The module auto-discovers via `module.json`, adds a top-level **Server Optimizer** menu entry, and creates its own tables on first use. Hard-refresh the panel.

> **Folder name matters:** the directory **must** be `optimizer` (lowercase) — XC_VM resolves the class name from the folder, so `optimizer` → `OptimizerModule`.

---

## Automation / scheduling

The auto toggles (daily Auto-Tier, 30-min Rebalance, hourly Smart Placement) register themselves in XC_VM's own **`crontab` database table** — the same mechanism the built-in crons use. No `/etc/cron` editing and no root needed; XC_VM's `root_signals` job rebuilds the `xc_vm` user crontab from that table. Toggling an auto option off removes its row.

---

## Recommended workflow

1. Set each load balancer's **Network Speed** in the panel.
2. **Server Check** → review the table, then **Check & Apply**.
3. **Auto-Tier** → **Preview**, confirm the promote/demote lists, then Apply (and enable daily if happy).
4. **Smart Placement** → **Preview**, confirm the moves, then **Place now** (enable hourly if happy).
5. Keep **Rebalance** as a manual "relieve overload now" button.

---

## Safety notes

- **Preview first, always.** Every mover has a read-only Preview.
- **Moving live streams reconnects their viewers.** Do first applies at low traffic.
- **Do not enable _Smart Placement (auto)_ and _Rebalance (auto)_ at the same time** — both move streams and will fight each other. Smart Placement largely supersedes Rebalance; pick one to automate and keep the other manual.
- **Relay topology assumption:** movers only touch relay assignments and keep the origin. This is correct for the normal setup (LBs relay from a fixed origin). If your LBs relay from **each other** in a chain, review moves manually before applying.
- **VOD is never moved or re-tiered** — live streams only.
- **Power metric** for placement/capacity is bandwidth + cores. If you are **transcode-heavy**, weight it toward CPU/GPU (open an issue).

---

## Uninstall

Delete the folder and drop the module's tables:

```bash
rm -rf /home/xc_vm/modules/optimizer
# tables use the legacy ondemand_ prefix (see note at the end). Use your XC_VM DB name.
mysql xc_vm -e "DROP TABLE IF EXISTS ondemand_config; DROP TABLE IF EXISTS ondemand_priority;"
```

…and turn off any auto crons it registered:

```sql
DELETE FROM crontab WHERE filename LIKE 'ondemand-%';
```

(Updates to XC_VM do **not** remove this module — the updater merges files and never deletes custom folders — but your settings/placement changes persist in the DB.)

---

## How it integrates (technical)

| File | Role |
|------|------|
| `module.json` | manifest (auto-discovery, navbar/settings flags) |
| `OptimizerModule.php` | `ModuleInterface` — routes, crons, navbar, install/uninstall |
| `OptimizerController.php` | page + JSON API actions |
| `OptimizerService.php` | server detection, settings, auto-tier, rebalance, placement |
| `AutoTierCronJob.php` | `cron:ondemand-autotier` (daily) |
| `RebalanceCronJob.php` | `cron:ondemand-rebalance` (30-min) |
| `PlacementCronJob.php` | `cron:ondemand-placement` (hourly) |
| `views/index.php` | the Server Optimizer page |

It uses only core XC_VM facilities (`SettingsManager`, `NavbarRegistry`, the `Router`, the `crontab` table, `streams_stats`, `streams_servers`, `servers`, `lines_live`) — no core files are modified, so it survives panel updates.

> Internal identifiers (routes, API actions, cron names, tables) use the legacy `ondemand_` / `ondemand-` prefix from the module's original name; this is intentional and harmless.

---

## License

This module targets XC_VM, which is licensed **AGPL-3.0**. Provided **as-is, no warranty**, as a community/beta tool. Use at your own risk.
