<?php

/**
 * OptimizerService — all the read/write logic for the On Demand Control Center.
 *
 * Reads/writes:
 *   - global settings via SettingsManager (the scattered on-demand knobs)
 *   - per-stream on-demand fields:  streams.probesize_ondemand, streams.llod
 *   - per-stream on-demand flag:    streams_servers.on_demand
 *   - the module's priority list:   ondemand_priority (created in install())
 *
 * VERIFY-POINTS (column/table names confirmed from StreamProcess.php, but
 * worth a sanity check on your build):
 *   - streams.probesize_ondemand, streams.llod
 *   - streams_servers.on_demand
 *   - ProcessManager::startMonitor() / ::isStreamAlive() for keep-warm
 *
 * @package XC_VM_Module_Optimizer
 * @license AGPL-3.0
 */

class OptimizerService {

    /** Cron filename → XC_VM runs `console.php cron:ondemand-warm`. */
    const CRON_FILENAME = 'ondemand-warm';

    /** Auto-tier cron filename → `console.php cron:ondemand-autotier`. */
    const AUTOTIER_FILENAME = 'ondemand-autotier';

    /** Rebalance cron filename → `console.php cron:ondemand-rebalance`. */
    const REBALANCE_FILENAME = 'ondemand-rebalance';

    /** Smart-placement cron filename → `console.php cron:ondemand-placement`. */
    const PLACEMENT_FILENAME = 'ondemand-placement';

    /**
     * The on-demand-relevant GLOBAL settings surfaced on the control page.
     * key => [label, type]
     */
    public static function globalKeys(): array {
        return [
            'on_demand_wait_time'   => ['label' => 'On Demand Wait Time (s)',          'type' => 'number'],
            'client_prebuffer'      => ['label' => 'Client Prebuffer (s)',             'type' => 'number'],
            'restreamer_prebuffer'  => ['label' => 'Restreamer Prebuffer (s)',         'type' => 'number'],
            'segment_wait_time'     => ['label' => 'Segment Wait Time (s)',            'type' => 'number'],
            'read_buffer_size'      => ['label' => 'Read Buffer Size (bytes)',         'type' => 'number'],
            'probesize_ondemand'    => ['label' => 'Default On Demand Probesize (bytes)', 'type' => 'number'],
            'create_expiration'     => ['label' => 'Token Create Expiration (s)',      'type' => 'number'],
            'probe_extra_wait'      => ['label' => 'Probe Extra Wait (s)',             'type' => 'number'],
            'on_demand_instant_off' => ['label' => 'Instant Off when idle',            'type' => 'checkbox'],
            'use_buffer'            => ['label' => 'Use nginx buffering',              'type' => 'checkbox'],
        ];
    }

    /** Current values for the global keys above. */
    public static function getGlobalSettings(): array {
        $all = SettingsManager::getAll();
        $out = [];
        foreach (self::globalKeys() as $key => $meta) {
            $out[$key] = $all[$key] ?? '';
        }
        return $out;
    }

    /** Persist posted global settings (only whitelisted keys). */
    public static function saveGlobalSettings(array $data): void {
        foreach (self::globalKeys() as $key => $meta) {
            if ($meta['type'] === 'checkbox') {
                SettingsManager::update($key, isset($data[$key]) && $data[$key] ? 1 : 0);
            } elseif (array_key_exists($key, $data) && $data[$key] !== '') {
                SettingsManager::update($key, intval($data[$key]));
            }
        }
    }

    /** How many streams are currently flagged on-demand. */
    public static function onDemandStreamCount(): int {
        global $db;
        $db->query("SELECT COUNT(*) AS `c` FROM `streams_servers` WHERE `on_demand` = 1;");
        return $db->num_rows() ? intval($db->get_row()['c']) : 0;
    }

    /**
     * Bulk-apply probesize + LLOD to all currently on-demand streams.
     *
     * @param int      $probesize  bytes (e.g. 1000000)
     * @param int|null $llod       0 = Disabled, 1 = LLOD v2, 2 = LLOD v3 (null = leave as-is)
     * @return int     rows affected
     */
    public static function applyBulk(int $probesize, ?int $llod = null): int {
        global $db;
        if ($probesize <= 0) {
            $probesize = 1000000;
        }

        if ($llod === null) {
            $db->query(
                "UPDATE `streams` t1
                   INNER JOIN `streams_servers` t2 ON t2.`stream_id` = t1.`id` AND t2.`on_demand` = 1
                   SET t1.`probesize_ondemand` = ?;",
                $probesize
            );
        } else {
            $db->query(
                "UPDATE `streams` t1
                   INNER JOIN `streams_servers` t2 ON t2.`stream_id` = t1.`id` AND t2.`on_demand` = 1
                   SET t1.`probesize_ondemand` = ?, t1.`llod` = ?;",
                $probesize, $llod
            );
        }
        return self::onDemandStreamCount();
    }

    /**
     * One-click presets. Sets a few global knobs + bulk-applies to on-demand streams.
     */
    public static function applyPreset(string $name): array {
        if ($name === 'fast') {
            // Favour fast start (accept slightly higher risk of missing audio).
            SettingsManager::update('probesize_ondemand', 1000000);
            SettingsManager::update('client_prebuffer', 1);
            SettingsManager::update('on_demand_wait_time', 15);
            self::applyBulk(1000000, 1); // LLOD v2 → 0.5s analyze
            return ['preset' => 'fast', 'note' => 'Fast-start: probesize 1M, LLOD v2, low prebuffer. Restart streams to apply.'];
        }

        // Default: 'reliable' — favour correct loading.
        SettingsManager::update('probesize_ondemand', 1000000);
        SettingsManager::update('client_prebuffer', 2);
        SettingsManager::update('on_demand_wait_time', 20);
        self::applyBulk(1000000, 0); // LLOD disabled → 10s analyze
        return ['preset' => 'reliable', 'note' => 'Reliable: probesize 1M, LLOD disabled, longer wait. Restart streams to apply.'];
    }

    // ── Keep-warm cron (auto-managed via XC_VM's `crontab` table) ─────────

    public static function isCronEnabled(?string $filename = null): bool {
        global $db;
        $filename = $filename ?: self::CRON_FILENAME;
        $db->query("SELECT `enabled` FROM `crontab` WHERE `filename` = ?;", $filename);
        return $db->num_rows() > 0 && intval($db->get_row()['enabled']) === 1;
    }

    /**
     * Register the keep-warm cron the SAME way XC_VM registers its own crons —
     * a row in the `crontab` table. The root_signals job rebuilds the xc_vm
     * user crontab from that table, so no /etc/cron edits and no root needed.
     */
    public static function enableCron(string $time = '* * * * *'): void {
        self::setCron(self::CRON_FILENAME, $time, true);
    }

    public static function disableCron(): void {
        self::setCron(self::CRON_FILENAME, '* * * * *', false);
    }

    /** Generic: upsert a row in XC_VM's crontab table + flag a rebuild. */
    public static function setCron(string $filename, string $time, bool $enabled): void {
        global $db;
        $db->query("SELECT `id` FROM `crontab` WHERE `filename` = ?;", $filename);
        if ($db->num_rows() > 0) {
            $db->query("UPDATE `crontab` SET `time` = ?, `enabled` = ? WHERE `filename` = ?;", $time, $enabled ? 1 : 0, $filename);
        } elseif ($enabled) {
            $db->query("INSERT INTO `crontab` (`filename`, `time`, `enabled`) VALUES (?, ?, 1);", $filename, $time);
        }
        self::flagCrontabRebuild();
    }

    /** Signal root_signals to regenerate the xc_vm crontab from the table. */
    private static function flagCrontabRebuild(): void {
        if (defined('TMP_PATH')) {
            @touch(TMP_PATH . 'crontab');
        }
    }

    // ── Keep-warm priority list ──────────────────────────────────────────

    /**
     * Create the module's table if missing. Lets the module work when installed
     * by simply copying the folder in (no panel uploader / no zip extension),
     * since install() only runs through the panel's module installer.
     */
    public static function ensureSchema(): void {
        global $db;
        $db->query(
            "CREATE TABLE IF NOT EXISTS `ondemand_priority` (
                `stream_id` INT(11) NOT NULL,
                `added_at`  INT(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`stream_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS `ondemand_config` (
                `k` VARCHAR(64) NOT NULL,
                `v` TEXT,
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
    }

    /** Priority streams joined with their names, for the table. */
    public static function getPriorityList(): array {
        global $db;
        self::ensureSchema();
        $db->query(
            "SELECT p.`stream_id`, s.`stream_display_name` AS `name`
               FROM `ondemand_priority` p
               LEFT JOIN `streams` s ON s.`id` = p.`stream_id`
               ORDER BY p.`added_at` DESC;"
        );
        return $db->get_rows();
    }

    /** @return int[] */
    public static function getPriorityIds(): array {
        global $db;
        self::ensureSchema();
        $db->query("SELECT `stream_id` FROM `ondemand_priority`;");
        $ids = [];
        foreach ($db->get_rows() as $row) {
            $ids[] = intval($row['stream_id']);
        }
        return $ids;
    }

    public static function togglePriority(int $streamID, bool $on): void {
        global $db;
        self::ensureSchema();
        if ($streamID <= 0) {
            return;
        }
        if ($on) {
            $db->query(
                "INSERT IGNORE INTO `ondemand_priority` (`stream_id`, `added_at`) VALUES (?, UNIX_TIMESTAMP());",
                $streamID
            );
        } else {
            $db->query("DELETE FROM `ondemand_priority` WHERE `stream_id` = ?;", $streamID);
        }
    }

    /**
     * Pre-start every priority channel that isn't already running.
     * Uses the SAME start path the live endpoint uses (no engine changes).
     *
     * @return int number of channels (re)started
     */
    public static function warmAll(): int {
        $warmed = 0;
        foreach (self::getPriorityIds() as $streamID) {
            if (self::isStreamRunning($streamID)) {
                continue;
            }
            if (class_exists('ProcessManager') && method_exists('ProcessManager', 'startMonitor')) {
                // Same call live.php uses to bring an on-demand channel up.
                ProcessManager::startMonitor($streamID);
                $warmed++;
            }
        }
        return $warmed;
    }

    private static function isStreamRunning(int $streamID): bool {
        $pidFile = STREAMS_PATH . $streamID . '_.pid';
        if (!file_exists($pidFile)) {
            return false;
        }
        $pid = intval(@file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }
        if (class_exists('ProcessManager') && method_exists('ProcessManager', 'isStreamAlive')) {
            return ProcessManager::isStreamAlive($pid, $streamID);
        }
        return false;
    }

    // ── Auto-tier (logs → always-on / on-demand) ─────────────────────────
    //
    // Popularity source: `streams_stats` (rebuilt hourly by cron:stats from
    // lines_activity) — rank/connections/users per window: today|week|month|all.
    // "Popular" = top-N by rank  OR  >= min connections  OR  on the keep-warm
    // priority list. Popular → on_demand=0 (always-on); the rest → on_demand=1
    // (only if demote is on). Live streams only (streams.type IN (1,3)); VOD
    // is never touched.

    public static function getConfig(string $key, $default = null) {
        global $db;
        self::ensureSchema();
        $db->query("SELECT `v` FROM `ondemand_config` WHERE `k` = ?;", $key);
        return $db->num_rows() > 0 ? $db->get_row()['v'] : $default;
    }

    public static function setConfig(string $key, $value): void {
        global $db;
        self::ensureSchema();
        $db->query("REPLACE INTO `ondemand_config` (`k`, `v`) VALUES (?, ?);", $key, (string) $value);
    }

    public static function getAutoTierConfig(): array {
        return [
            'window'          => self::getConfig('at_window', 'week'),
            'top_n'           => intval(self::getConfig('at_top_n', 50)),
            'min_connections' => intval(self::getConfig('at_min_conns', 20)),
            'demote'          => self::getConfig('at_demote', '0') === '1',
            'auto_daily'      => self::isCronEnabled(self::AUTOTIER_FILENAME),
        ];
    }

    public static function saveAutoTierConfig(array $d): void {
        $window = in_array($d['window'] ?? 'week', ['today', 'week', 'month', 'all'], true) ? $d['window'] : 'week';
        self::setConfig('at_window', $window);
        self::setConfig('at_top_n', max(0, intval($d['top_n'] ?? 50)));
        self::setConfig('at_min_conns', max(0, intval($d['min_connections'] ?? 20)));
        self::setConfig('at_demote', !empty($d['demote']) ? '1' : '0');
        // "Both" apply mode: optional daily auto-tier cron (runs once a day).
        self::setCron(self::AUTOTIER_FILENAME, '17 4 * * *', !empty($d['auto_daily']));
    }

    /** All live streams (id => [name, on_demand]) on this server. */
    public static function getLiveStreams(): array {
        global $db;
        $db->query(
            "SELECT s.`id`, s.`stream_display_name` AS `name`, MAX(ss.`on_demand`) AS `on_demand`
               FROM `streams` s
               INNER JOIN `streams_servers` ss ON ss.`stream_id` = s.`id`
              WHERE s.`type` IN (1, 3)
              GROUP BY s.`id`;"
        );
        $out = [];
        foreach ($db->get_rows() as $r) {
            $out[intval($r['id'])] = ['name' => $r['name'], 'on_demand' => intval($r['on_demand'])];
        }
        return $out;
    }

    /** Stream ids considered popular for a window: top-N rank OR >= min connections. */
    public static function getPopularIds(string $window, int $topN, int $minConns): array {
        global $db;
        $ids = [];
        if ($topN > 0) {
            $db->query(
                "SELECT st.`stream_id` FROM `streams_stats` st
                   INNER JOIN `streams` s ON s.`id` = st.`stream_id`
                  WHERE st.`type` = ? AND s.`type` IN (1,3) AND st.`rank` > 0
                  ORDER BY st.`rank` ASC LIMIT " . intval($topN) . ";",
                $window
            );
            foreach ($db->get_rows() as $r) {
                $ids[intval($r['stream_id'])] = true;
            }
        }
        if ($minConns > 0) {
            $db->query(
                "SELECT st.`stream_id` FROM `streams_stats` st
                   INNER JOIN `streams` s ON s.`id` = st.`stream_id`
                  WHERE st.`type` = ? AND s.`type` IN (1,3) AND st.`connections` >= ?;",
                $window, $minConns
            );
            foreach ($db->get_rows() as $r) {
                $ids[intval($r['stream_id'])] = true;
            }
        }
        return array_keys($ids);
    }

    /** Dry-run: what would be promoted/demoted, without changing anything. */
    public static function computeTierPlan(): array {
        $cfg       = self::getAutoTierConfig();
        $live      = self::getLiveStreams();
        $popular   = array_flip(self::getPopularIds($cfg['window'], $cfg['top_n'], $cfg['min_connections']));

        $promote = [];
        $demote  = [];
        foreach ($live as $id => $info) {
            $isPopular = isset($popular[$id]);
            if ($isPopular && $info['on_demand'] === 1) {
                $promote[] = ['id' => $id, 'name' => $info['name']];
            } elseif (!$isPopular && $info['on_demand'] === 0 && $cfg['demote']) {
                $demote[] = ['id' => $id, 'name' => $info['name']];
            }
        }
        return [
            'config'        => $cfg,
            'promote'       => $promote,
            'demote'        => $demote,
            'live_total'    => count($live),
            'popular_total' => count($popular),
        ];
    }

    /** Apply the plan: flip on_demand flags. Returns counts. */
    public static function applyTier(): array {
        global $db;
        $plan       = self::computeTierPlan();
        $promoteIds = array_map('intval', array_column($plan['promote'], 'id'));
        $demoteIds  = array_map('intval', array_column($plan['demote'], 'id'));

        if ($promoteIds) {
            $db->query("UPDATE `streams_servers` SET `on_demand` = 0 WHERE `stream_id` IN (" . implode(',', $promoteIds) . ");");
        }
        if ($demoteIds) {
            $db->query("UPDATE `streams_servers` SET `on_demand` = 1 WHERE `stream_id` IN (" . implode(',', $demoteIds) . ");");
        }
        return ['promoted' => count($promoteIds), 'demoted' => count($demoteIds)];
    }

    // ── Server Check & Optimize ──────────────────────────────────────────
    //
    // Reads each installed server's reported hardware (servers.server_hardware
    // JSON: cores / total_ram / network_speed / cpu_name) and applies safe,
    // well-understood streaming settings sized to the SMALLEST box so the
    // values are safe cluster-wide.

    /** All installed servers (main + load balancers) with parsed hardware. */
    public static function detectServers(): array {
        global $db;
        $out = [];
        // `cpu_cores` is NOT on `servers` (it's on servers_stats) — read hardware
        // from the server_hardware JSON, falling back to the live watchdog_data.
        $db->query("SELECT `id`, `server_name`, `is_main`, `network_guaranteed_speed`, `server_hardware`, `watchdog_data` FROM `servers` WHERE `enabled` = 1;");
        foreach ($db->get_rows() as $r) {
            $hw = json_decode($r['server_hardware'] ?: '{}', true) ?: [];
            $wd = json_decode($r['watchdog_data'] ?: '{}', true) ?: [];
            $out[] = [
                'id'        => intval($r['id']),
                'name'      => $r['server_name'],
                'is_main'   => intval($r['is_main'] ?? 0),
                'type'      => intval($r['is_main'] ?? 0) ? 'Main (info)' : 'Load Balancer',
                'cores'     => intval($hw['cores'] ?? ($wd['cpu_cores'] ?? 0)),
                // total_ram / total_mem are reported in kB (from /proc/meminfo) → ×1024 for bytes
                'ram_bytes' => intval($hw['total_ram'] ?? ($wd['total_mem'] ?? 0)) * 1024,
                'cpu_name'  => $hw['cpu_name'] ?? ($wd['cpu_name'] ?? ''),
                'net'       => $hw['network_speed'] ?? null,
                'net_mbps'  => intval($r['network_guaranteed_speed'] ?? 1000) ?: 1000,
            ];
        }
        // Fallback: if the table reported no usable hardware, detect the local
        // box directly (this module always runs on the main server).
        $usable = false;
        foreach ($out as $s) {
            if ($s['cores'] > 0) { $usable = true; break; }
        }
        if (empty($out) || !$usable) {
            $out[] = self::detectLocal();
        }
        return $out;
    }

    /** CPU/RAM of the machine this code runs on (shell + /proc). */
    private static function detectLocal(): array {
        $cores = intval(@shell_exec('nproc 2>/dev/null'));
        if ($cores < 1) {
            $cores = intval(@shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null'));
        }
        $ramKb = 0;
        $mem = @file_get_contents('/proc/meminfo');
        if ($mem && preg_match('/MemTotal:\s+(\d+)\s*kB/', $mem, $m)) {
            $ramKb = intval($m[1]);
        }
        $cpuName = '';
        $info = @file_get_contents('/proc/cpuinfo');
        if ($info && preg_match('/model name\s*:\s*(.+)/', $info, $m)) {
            $cpuName = trim($m[1]);
        }
        return [
            'id'        => 0,
            'name'      => 'This server (local detect)',
            'is_main'   => 1,
            'type'      => 'Main (info)',
            'cores'     => $cores,
            'ram_bytes' => $ramKb * 1024,
            'cpu_name'  => $cpuName,
            'net'       => null,
            'net_mbps'  => 1000,
        ];
    }

    /** Best-all-round settings derived from the weakest server's resources. */
    public static function recommendSettings(array $servers): array {
        // Size GLOBAL settings to the weakest STREAMING server (load balancer).
        // The main server is info-only and must not drag the values down.
        $streaming = array_filter($servers, function ($s) { return empty($s['is_main']); });
        if (empty($streaming)) {
            $streaming = $servers; // single-box install: no separate load balancer
        }
        $cores = null;
        $ram   = null;
        foreach ($streaming as $s) {
            if ($s['cores'] > 0)     { $cores = is_null($cores) ? $s['cores'] : min($cores, $s['cores']); }
            if ($s['ram_bytes'] > 0) { $ram   = is_null($ram) ? $s['ram_bytes'] : min($ram, $s['ram_bytes']); }
        }
        $ramGB = $ram ? $ram / 1073741824 : 0;
        $cores = $cores ?: 1;

        // read buffer scales with available RAM
        if ($ramGB >= 16) {
            $readBuf = 262144;   // 256 KB
        } elseif ($ramGB >= 4) {
            $readBuf = 131072;   // 128 KB
        } else {
            $readBuf = 65536;    // 64 KB
        }

        return [
            // buffers / throughput (sized to RAM)
            'read_buffer_size'          => $readBuf,
            'use_buffer'                => 1,        // nginx buffering on
            // live on-demand startup
            'probesize_ondemand'        => 1000000,  // 1 MB — reliable detect, fast
            'on_demand_wait_time'       => 20,       // grace for slow sources
            'client_prebuffer'          => 12,       // seconds (÷ seg_time): ~2 segments — smooth + quick (default 30 ≈ slow start)
            'monitor_connection_status' => 1,        // drop dead clients, free resources
            // stream + VOD probing / analysis
            'probesize'                 => 5000000,  // 5 MB — robust stream/VOD detection
            'stream_max_analyze'        => 5000000,  // 5 s analyze for always-on/VOD
            // VOD delivery headroom (don't underrun)
            'vod_bitrate_plus'          => 60,
            '_detected'                 => ['cores' => $cores, 'ram_gb' => round($ramGB, 1), 'servers' => count($streaming)],
        ];
    }

    /**
     * Recommended per-server client cap from its bandwidth + cores.
     * (Streaming is bandwidth-bound; cores are a sanity ceiling.)
     */
    public static function recommendCapacity(array $s): int {
        $mbps        = $s['net_mbps'] ?: 1000;
        $avgBitrate  = max(1, intval(self::getConfig('avg_bitrate_mbps', 5)));
        $byBandwidth = intval(($mbps * 0.85) / $avgBitrate);
        $byCpu       = ($s['cores'] ?: 1) * 200;
        return max(50, min($byBandwidth, $byCpu));
    }

    /** Detect → recommend → apply. Returns a report for the UI. */
    public static function applyServerOptimization(): array {
        global $db;
        $servers = self::detectServers();
        $rec     = self::recommendSettings($servers);

        // 1. Global streaming settings — one `settings` row, used by ALL servers.
        //    Sized to the weakest box so the value is safe cluster-wide.
        $applied = [];
        foreach ($rec as $k => $v) {
            if ($k[0] === '_') {
                continue;
            }
            SettingsManager::update($k, $v);
            $applied[$k] = $v;
        }

        // 2. Per-server capacity — `total_clients` is the ONLY tuning knob
        //    XC_VM stores per server; the load balancer uses it to send each
        //    server a share matched to its own bandwidth + cores.
        foreach ($servers as &$s) {
            if ($s['id'] <= 0 || !empty($s['is_main'])) {
                continue; // skip the info-only main server + local placeholder
            }
            $cap = self::recommendCapacity($s);
            $db->query("UPDATE `servers` SET `total_clients` = ? WHERE `id` = ?;", $cap, $s['id']);
            $s['total_clients'] = $cap;
        }
        unset($s);

        return ['servers' => $servers, 'detected' => $rec['_detected'], 'applied' => $applied];
    }

    // ── Rebalance streams across load balancers ──────────────────────────
    //
    // Goal: relieve overloaded LBs only. Looks at current client load per LB
    // (lines_live ÷ total_clients) and moves RELAY streams (streams_servers
    // rows that have a parent/origin) off any LB above the threshold onto LBs
    // with spare capacity, keeping the origin (parent_id) intact. The streaming
    // daemon then stops the stream on the old LB and starts it on the new one.

    public static function getRebalanceConfig(): array {
        return [
            'threshold'  => max(50, min(99, intval(self::getConfig('rb_threshold', 85)))),
            'auto_daily' => self::isCronEnabled(self::REBALANCE_FILENAME),
        ];
    }

    public static function saveRebalanceConfig(array $d): void {
        self::setConfig('rb_threshold', max(50, min(99, intval($d['threshold'] ?? 85))));
        self::setCron(self::REBALANCE_FILENAME, '*/30 * * * *', !empty($d['auto_daily']));
    }

    /** Per-LB load: active clients ÷ total_clients capacity. */
    public static function getServerLoads(): array {
        global $db;
        $db->query("SELECT `id`, `server_name`, `is_main`, `total_clients` FROM `servers` WHERE `enabled` = 1;");
        $servers = [];
        foreach ($db->get_rows() as $r) {
            $servers[intval($r['id'])] = [
                'id'      => intval($r['id']),
                'name'    => $r['server_name'],
                'is_main' => intval($r['is_main'] ?? 0),
                'cap'     => intval($r['total_clients']) ?: 1,
                'clients' => 0,
            ];
        }
        $db->query("SELECT `server_id`, COUNT(*) AS `c` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `server_id`;");
        foreach ($db->get_rows() as $r) {
            $sid = intval($r['server_id']);
            if (isset($servers[$sid])) {
                $servers[$sid]['clients'] = intval($r['c']);
            }
        }
        return $servers;
    }

    /** Dry-run: which relay streams to move off overloaded LBs, and where. */
    public static function computeRebalancePlan(): array {
        global $db;
        $threshold = self::getRebalanceConfig()['threshold'] / 100.0;
        $servers   = self::getServerLoads();
        $lbs       = array_filter($servers, function ($s) { return empty($s['is_main']); });

        // per (server, stream) live clients
        $streamClients = [];
        $db->query("SELECT `server_id`, `stream_id`, COUNT(*) AS `c` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `server_id`, `stream_id`;");
        foreach ($db->get_rows() as $r) {
            $streamClients[intval($r['server_id'])][intval($r['stream_id'])] = intval($r['c']);
        }

        // streams_servers rows for the LBs (relay rows = parent_id not null)
        $assign = $onServer = [];
        $lbIds  = array_keys($lbs);
        if ($lbIds) {
            $db->query("SELECT `server_stream_id`, `stream_id`, `server_id`, `parent_id` FROM `streams_servers` WHERE `server_id` IN (" . implode(',', array_map('intval', $lbIds)) . ");");
            foreach ($db->get_rows() as $r) {
                $sid = intval($r['server_id']);
                $stid = intval($r['stream_id']);
                $onServer[$sid][$stid] = true;
                $assign[$sid][$stid] = ['ssid' => intval($r['server_stream_id']), 'parent' => $r['parent_id']];
            }
        }

        $work = [];
        foreach ($servers as $id => $s) {
            $work[$id] = $s['clients'];
        }

        // most-loaded LBs first
        uasort($lbs, function ($a, $b) use ($work) {
            return ($work[$b['id']] / max(1, $b['cap'])) <=> ($work[$a['id']] / max(1, $a['cap']));
        });

        $moves = [];
        foreach ($lbs as $srcId => $src) {
            $guard = 0;
            while (($work[$srcId] / max(1, $src['cap'])) > $threshold && $guard++ < 1000) {
                // candidate relay streams on source, heaviest first
                $cands = [];
                foreach (($assign[$srcId] ?? []) as $stid => $a) {
                    if ($a['parent'] === null) {
                        continue; // never move origin assignments
                    }
                    $cands[$stid] = $streamClients[$srcId][$stid] ?? 0;
                }
                arsort($cands);

                $moved = false;
                foreach ($cands as $stid => $cl) {
                    $bestTarget = null;
                    $bestLoad   = 2.0;
                    foreach ($lbs as $tId => $t) {
                        if ($tId == $srcId || !empty($onServer[$tId][$stid])) {
                            continue;
                        }
                        $projected = ($work[$tId] + $cl) / max(1, $t['cap']);
                        if ($projected <= $threshold && $work[$tId] < $t['cap'] && $projected < $bestLoad) {
                            $bestLoad   = $projected;
                            $bestTarget = $tId;
                        }
                    }
                    if ($bestTarget === null) {
                        continue;
                    }

                    $moves[] = [
                        'stream_id' => $stid,
                        'clients'   => $cl,
                        'from'      => $srcId,
                        'from_name' => $src['name'],
                        'to'        => $bestTarget,
                        'to_name'   => $lbs[$bestTarget]['name'],
                        'ssid'      => $assign[$srcId][$stid]['ssid'],
                    ];
                    $work[$srcId]       -= $cl;
                    $work[$bestTarget]  += $cl;
                    unset($assign[$srcId][$stid]);
                    $onServer[$bestTarget][$stid] = true;
                    $moved = true;
                    break;
                }
                if (!$moved) {
                    break;
                }
            }
        }

        // stream names for the report
        if ($moves) {
            $ids = array_unique(array_map(function ($m) { return $m['stream_id']; }, $moves));
            $db->query("SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (" . implode(',', array_map('intval', $ids)) . ");");
            $names = [];
            foreach ($db->get_rows() as $r) {
                $names[intval($r['id'])] = $r['stream_display_name'];
            }
            foreach ($moves as &$m) {
                $m['name'] = $names[$m['stream_id']] ?? ('#' . $m['stream_id']);
            }
            unset($m);
        }

        $summary = [];
        foreach ($servers as $s) {
            if (!empty($s['is_main'])) {
                continue;
            }
            $summary[] = [
                'name'    => $s['name'],
                'clients' => $s['clients'],
                'cap'     => $s['cap'],
                'load'    => round($s['clients'] / max(1, $s['cap']) * 100, 1),
            ];
        }

        return ['threshold' => intval($threshold * 100), 'servers' => $summary, 'moves' => $moves];
    }

    /** Apply the rebalance: reassign each chosen relay stream to its target LB. */
    public static function applyRebalance(): array {
        global $db;
        $plan = self::computeRebalancePlan();
        $n = 0;
        foreach ($plan['moves'] as $m) {
            // keep parent_id (origin) — only the relay server changes
            $db->query("UPDATE `streams_servers` SET `server_id` = ? WHERE `server_stream_id` = ?;", $m['to'], $m['ssid']);
            $n++;
        }
        return ['moved' => $n];
    }

    // ── Smart Placement (popularity × capability) ────────────────────────
    //
    // Places hot (popular) streams on strong servers and cold ones on weak
    // servers. "Strong" = higher total_clients capacity (derived from each
    // server's bandwidth + cores). Greedy: heaviest streams first onto the
    // server with the most remaining capacity → hot streams spread across the
    // strong boxes, cold streams settle on the weak ones. Relay rows only;
    // origin (parent_id) is kept. Moves per run are capped to limit churn.

    public static function getPlacementConfig(): array {
        return [
            'max_moves' => max(1, intval(self::getConfig('pl_max_moves', 20))),
            'auto'      => self::isCronEnabled(self::PLACEMENT_FILENAME),
        ];
    }

    public static function savePlacementConfig(array $d): void {
        self::setConfig('pl_max_moves', max(1, intval($d['max_moves'] ?? 20)));
        self::setCron(self::PLACEMENT_FILENAME, '0 * * * *', !empty($d['auto'])); // hourly
    }

    /** Dry-run: ideal hot→strong / cold→weak placement, capped to max_moves. */
    public static function computePlacementPlan(): array {
        global $db;
        $maxMoves = self::getPlacementConfig()['max_moves'];
        $window   = self::getConfig('at_window', 'week');

        $loads = self::getServerLoads();
        $lbs   = array_filter($loads, function ($s) { return empty($s['is_main']); });
        if (count($lbs) < 2) {
            return ['servers' => [], 'moves' => [], 'window' => $window, 'note' => 'Need at least 2 load balancers to place across.'];
        }

        // popularity weight (connections) per stream for the window
        $pop = [];
        $db->query("SELECT `stream_id`, `connections` FROM `streams_stats` WHERE `type` = ?;", $window);
        foreach ($db->get_rows() as $r) {
            $pop[intval($r['stream_id'])] = intval($r['connections']);
        }

        // current relay assignment per stream (first relay row only)
        $lbIds = array_keys($lbs);
        $rows  = [];
        $db->query("SELECT `server_stream_id`, `stream_id`, `server_id` FROM `streams_servers` WHERE `server_id` IN (" . implode(',', array_map('intval', $lbIds)) . ") AND `parent_id` IS NOT NULL ORDER BY `server_stream_id` ASC;");
        foreach ($db->get_rows() as $r) {
            $stid = intval($r['stream_id']);
            if (!isset($rows[$stid])) {
                $rows[$stid] = ['ssid' => intval($r['server_stream_id']), 'cur' => intval($r['server_id']), 'weight' => $pop[$stid] ?? 0];
            }
        }
        // heaviest (most popular) first
        uasort($rows, function ($a, $b) { return $b['weight'] <=> $a['weight']; });

        // greedy: each stream → the LB with the most remaining capacity
        $remaining = [];
        foreach ($lbs as $id => $s) {
            $remaining[$id] = $s['cap'];
        }

        $moves = [];
        foreach ($rows as $stid => $st) {
            $target = null;
            $best   = -PHP_INT_MAX;
            foreach ($remaining as $sid => $rem) {
                if ($rem > $best) {
                    $best   = $rem;
                    $target = $sid;
                }
            }
            $remaining[$target] -= max(1, $st['weight']);
            if ($target !== $st['cur']) {
                $moves[] = ['stream_id' => $stid, 'weight' => $st['weight'], 'from' => $st['cur'], 'to' => $target, 'ssid' => $st['ssid']];
            }
        }
        $moves = array_slice($moves, 0, $maxMoves); // most-impactful first, then cap

        // names
        if ($moves) {
            $ids = array_unique(array_map(function ($m) { return $m['stream_id']; }, $moves));
            $db->query("SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (" . implode(',', array_map('intval', $ids)) . ");");
            $names = [];
            foreach ($db->get_rows() as $r) {
                $names[intval($r['id'])] = $r['stream_display_name'];
            }
            foreach ($moves as &$m) {
                $m['name']      = $names[$m['stream_id']] ?? ('#' . $m['stream_id']);
                $m['from_name'] = $lbs[$m['from']]['name'] ?? ('#' . $m['from']);
                $m['to_name']   = $lbs[$m['to']]['name'] ?? ('#' . $m['to']);
            }
            unset($m);
        }

        $servers = [];
        foreach ($lbs as $s) {
            $servers[] = ['name' => $s['name'], 'cap' => $s['cap'], 'clients' => $s['clients']];
        }
        usort($servers, function ($a, $b) { return $b['cap'] <=> $a['cap']; }); // strongest first

        return ['servers' => $servers, 'moves' => $moves, 'window' => $window];
    }

    /** Apply placement: reassign each chosen relay stream to its target LB. */
    public static function applyPlacement(): array {
        global $db;
        $plan = self::computePlacementPlan();
        $n = 0;
        foreach ($plan['moves'] as $m) {
            $db->query("UPDATE `streams_servers` SET `server_id` = ? WHERE `server_stream_id` = ?;", $m['to'], $m['ssid']);
            $n++;
        }
        return ['moved' => $n];
    }
}
