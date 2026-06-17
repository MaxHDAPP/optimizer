<div class="wrapper">
    <div class="container-fluid">

        <div class="page-title-box">
            <h4 class="page-title">Server Optimizer</h4>
            <p class="text-muted">One stop: tune servers, auto-tier on-demand, and rebalance load balancers.</p>
        </div>

        <div id="od-status"></div>

        <!-- ── Server Check & Optimize ─────────────────────────────── -->
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-2">Server Check &amp; Optimize</h4>
                <p class="text-muted mb-3">
                    Sizes the global streaming settings to your weakest <strong>load balancer</strong> (the info-only main server is ignored), and sets each load balancer's <strong>max users</strong> from its bandwidth ÷ your average stream bitrate.
                </p>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label for="sc_bitrate">Average stream bitrate (Mbps)</label>
                            <input type="number" class="form-control" id="sc_bitrate" value="<?= intval($rAvgBitrate) ?>">
                            <small class="text-muted">HD ≈ 4–6, 4K ≈ 15–25 — drives the max-users math.</small>
                        </div>
                    </div>
                </div>

                <table class="table table-sm table-centered mb-3">
                    <thead>
                        <tr><th>Server</th><th>Type</th><th class="text-center">Cores</th><th class="text-center">RAM</th><th>CPU</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rServersDetected)): ?>
                            <tr><td colspan="5" class="text-muted">No servers detected.</td></tr>
                        <?php else: foreach ($rServersDetected as $rSrv): ?>
                            <tr>
                                <td><?= htmlspecialchars($rSrv['name'] ?? ('#' . $rSrv['id'])) ?></td>
                                <td><?= htmlspecialchars($rSrv['type'] ?? '—') ?></td>
                                <td class="text-center"><?= $rSrv['cores'] ?: '<span class="text-muted">?</span>' ?></td>
                                <td class="text-center"><?= $rSrv['ram_bytes'] ? round($rSrv['ram_bytes'] / 1073741824, 1) . ' GB' : '<span class="text-muted">?</span>' ?></td>
                                <td class="text-truncate" style="max-width:320px;"><?= htmlspecialchars($rSrv['cpu_name'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <button class="btn btn-primary" onclick="odServerCheck()"><i class="fas fa-magic"></i> Check &amp; Apply Best Settings</button>
                <small class="text-muted d-block mt-2">
                    RAM/cores auto-detect. <strong>Bandwidth comes from each server's "Network Speed" field</strong> (Servers → edit) — set it to each load balancer's real Mbps for accurate max-users. A "?" = that server hasn't reported hardware yet.
                </small>
                <div id="sc-report" class="mt-3"></div>
            </div>
        </div>

        <!-- ── Auto-Tier (logs-driven) ─────────────────────────────── -->
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-2">Auto-Tier from Usage Logs</h4>
                <p class="text-muted mb-3">
                    Promotes the most-watched live streams to <strong>always-on</strong> and demotes rarely/never-used ones to <strong>on-demand</strong>, using XC_VM's own popularity stats (<code>streams_stats</code>). VOD is never touched.
                </p>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group mb-3">
                            <label for="at_window">Usage window</label>
                            <select class="form-control" id="at_window">
                                <?php foreach (['today' => 'Last 24 hours', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'all' => 'All-time'] as $rK => $rV): ?>
                                    <option value="<?= $rK ?>" <?= ($rAutoTier['window'] === $rK) ? 'selected' : '' ?>><?= $rV ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-3">
                            <label for="at_top_n">Keep top N always-on</label>
                            <input type="number" class="form-control" id="at_top_n" value="<?= intval($rAutoTier['top_n']) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-3">
                            <label for="at_min_conns">…or min connections</label>
                            <input type="number" class="form-control" id="at_min_conns" value="<?= intval($rAutoTier['min_connections']) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group mb-3">
                            <label class="d-block">Options</label>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="at_demote" <?= !empty($rAutoTier['demote']) ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="at_demote">Demote idle → on-demand</label>
                            </div>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="at_auto_daily" <?= !empty($rAutoTier['auto_daily']) ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="at_auto_daily">Run automatically every day</label>
                            </div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="odAutotierSave()"><i class="fas fa-save"></i> Save</button>
                <button class="btn btn-secondary" onclick="odAutotierPreview()"><i class="fas fa-eye"></i> Preview changes</button>
                <button class="btn btn-success" onclick="odAutotierApply()"><i class="fas fa-check"></i> Apply now</button>
                <div id="at-preview" class="mt-3"></div>
            </div>
        </div>

        <!-- ── Rebalance (overloaded LBs) ───────────────────────────── -->
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-2">Rebalance Load Balancers</h4>
                <p class="text-muted mb-3">
                    Moves relay streams off any load balancer over the load threshold onto ones with spare capacity (keeps each stream's origin). <strong>Live streams reconnect when moved</strong> — preview first.
                </p>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label for="rb_threshold">Overloaded at (% of max users)</label>
                            <input type="number" class="form-control" id="rb_threshold" value="<?= intval($rRebalance['threshold']) ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label class="d-block">Options</label>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="rb_auto" <?= !empty($rRebalance['auto_daily']) ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="rb_auto">Auto-rebalance every 30 min</label>
                            </div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="odRbSave()"><i class="fas fa-save"></i> Save</button>
                <button class="btn btn-secondary" onclick="odRbPreview()"><i class="fas fa-eye"></i> Preview moves</button>
                <button class="btn btn-warning" onclick="odRbApply()"><i class="fas fa-exchange-alt"></i> Rebalance now</button>
                <div id="rb-preview" class="mt-3"></div>
            </div>
        </div>

        <!-- ── Smart Placement (popularity × capability) ─────────────── -->
        <div class="card">
            <div class="card-body">
                <h4 class="header-title mb-2">Smart Placement</h4>
                <p class="text-muted mb-3">
                    Places the most-watched streams on your strongest servers (spread across them) and the rarely-used ones on the weakest — sized by each server's capacity. <strong>Live streams reconnect when moved</strong> — preview first.
                </p>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label for="pl_max_moves">Max moves per run</label>
                            <input type="number" class="form-control" id="pl_max_moves" value="<?= intval($rPlacement['max_moves']) ?>">
                            <small class="text-muted">Limits churn — it converges over a few runs.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label class="d-block">Options</label>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="pl_auto" <?= !empty($rPlacement['auto']) ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="pl_auto">Auto-place every hour</label>
                            </div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="odPlSave()"><i class="fas fa-save"></i> Save</button>
                <button class="btn btn-secondary" onclick="odPlPreview()"><i class="fas fa-eye"></i> Preview placement</button>
                <button class="btn btn-warning" onclick="odPlApply()"><i class="fas fa-project-diagram"></i> Place now</button>
                <div id="pl-preview" class="mt-3"></div>
            </div>
        </div>

    </div>
</div>

<script>
    function odStatus(msg, ok) {
        var c = ok === false ? 'alert-danger' : 'alert-success';
        document.getElementById('od-status').innerHTML = '<div class="alert ' + c + '">' + msg + '</div>';
        window.scrollTo(0, 0);
    }

    function odPost(action, body) {
        var params = new URLSearchParams(body || {});
        params.append('action', action);
        return fetch('api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (r) { return r.json(); });
    }

    // ── Server check ──────────────────────────────────────────────
    function odServerCheck() {
        if (!confirm('Detect servers and apply best settings to your load balancers now?')) { return; }
        odPost('ondemand_servercheck', { avg_bitrate: document.getElementById('sc_bitrate').value }).then(function (r) {
            odStatus(r.note || 'Applied.', r.result);
            var rows = '';
            for (var k in (r.applied || {})) {
                rows += '<tr><td>' + k + '</td><td class="text-right"><strong>' + r.applied[k] + '</strong></td></tr>';
            }
            var srvRows = '';
            (r.servers || []).forEach(function (s) {
                if (s.total_clients) {
                    srvRows += '<tr><td>' + (s.name || ('#' + s.id)) + '</td><td>' + (s.type || '') + '</td><td class="text-right"><strong>' + s.total_clients + '</strong></td></tr>';
                }
            });
            var srvTable = srvRows
                ? '<div class="mt-3"><strong>Per load-balancer max users (applied as total_clients):</strong>' +
                  '<table class="table table-sm mt-1 mb-0"><thead><tr><th>Server</th><th>Type</th><th class="text-right">Max users</th></tr></thead><tbody>' + srvRows + '</tbody></table></div>'
                : '';
            var det = r.detected || {};
            document.getElementById('sc-report').innerHTML =
                '<div class="alert alert-secondary">' +
                '<div><strong>Global settings</strong> sized to weakest box: ' + (det.cores || '?') + ' cores · ' + (det.ram_gb || '?') + ' GB RAM</div>' +
                '<table class="table table-sm mt-2 mb-0"><thead><tr><th>Setting</th><th class="text-right">Applied</th></tr></thead><tbody>' + rows + '</tbody></table>' +
                srvTable +
                '</div>';
        });
    }

    // ── Auto-tier ─────────────────────────────────────────────────
    function odAutotierData() {
        return {
            window: document.getElementById('at_window').value,
            top_n: document.getElementById('at_top_n').value,
            min_connections: document.getElementById('at_min_conns').value,
            demote: document.getElementById('at_demote').checked ? 1 : 0,
            auto_daily: document.getElementById('at_auto_daily').checked ? 1 : 0
        };
    }
    function odAutotierSave() {
        odPost('ondemand_autotier_save', odAutotierData()).then(function (r) { odStatus(r.note || 'Saved.', r.result); });
    }
    function odAutotierPreview() {
        odPost('ondemand_autotier_save', odAutotierData())
            .then(function () { return odPost('ondemand_autotier_preview', {}); })
            .then(function (r) { renderAtPreview(r); });
    }
    function odAutotierApply() {
        if (!confirm('Apply tiering now? This changes on_demand flags on matching live streams.')) { return; }
        odPost('ondemand_autotier_save', odAutotierData())
            .then(function () { return odPost('ondemand_autotier_apply', {}); })
            .then(function (r) { odStatus(r.note, r.result); });
    }
    function renderAtPreview(r) {
        function list(items, label, cls) {
            if (!items || !items.length) { return '<div class="text-muted">Nothing ' + label + '.</div>'; }
            var names = items.slice(0, 50).map(function (x) { return x.name || ('#' + x.id); }).join(', ');
            var more = items.length > 50 ? ' …(+' + (items.length - 50) + ' more)' : '';
            return '<div class="' + cls + '"><strong>' + items.length + ' ' + label + ':</strong> ' + names + more + '</div>';
        }
        document.getElementById('at-preview').innerHTML =
            '<div class="alert alert-secondary">' +
            '<div>Live channels (eligible): ' + r.live_total + ' · popular set: ' + r.popular_total + '</div>' +
            list(r.promote, 'to promote → always-on', 'text-success mt-2') +
            list(r.demote, 'to demote → on-demand', 'text-warning mt-2') +
            '</div>';
    }

    // ── Rebalance ─────────────────────────────────────────────────
    function odRbData() {
        return {
            threshold: document.getElementById('rb_threshold').value,
            auto_daily: document.getElementById('rb_auto').checked ? 1 : 0
        };
    }
    function odRbSave() {
        odPost('ondemand_rebalance_save', odRbData()).then(function (r) { odStatus(r.note || 'Saved.', r.result); });
    }
    function odRbPreview() {
        odPost('ondemand_rebalance_save', odRbData())
            .then(function () { return odPost('ondemand_rebalance_preview', {}); })
            .then(function (r) { renderRb(r); });
    }
    function odRbApply() {
        if (!confirm('Move streams now to relieve overloaded load balancers? Live streams will reconnect.')) { return; }
        odPost('ondemand_rebalance_save', odRbData())
            .then(function () { return odPost('ondemand_rebalance_apply', {}); })
            .then(function (r) { odStatus(r.note, r.result); });
    }
    function renderRb(r) {
        var srv = (r.servers || []).map(function (s) {
            var cls = s.load > r.threshold ? 'text-danger' : 'text-muted';
            return '<tr><td>' + s.name + '</td><td class="text-right">' + s.clients + ' / ' + s.cap + '</td><td class="text-right ' + cls + '">' + s.load + '%</td></tr>';
        }).join('');
        var mv = (r.moves || []).map(function (m) {
            return '<tr><td>' + m.name + '</td><td class="text-right">' + m.clients + '</td><td>' + m.from_name + ' → <strong>' + m.to_name + '</strong></td></tr>';
        }).join('');
        document.getElementById('rb-preview').innerHTML =
            '<div class="alert alert-secondary">' +
            '<div><strong>Load balancers</strong> (threshold ' + r.threshold + '%):</div>' +
            '<table class="table table-sm mt-1 mb-2"><thead><tr><th>Server</th><th class="text-right">Users/cap</th><th class="text-right">Load</th></tr></thead><tbody>' + srv + '</tbody></table>' +
            (mv ? ('<div><strong>' + r.moves.length + ' move(s) recommended:</strong></div>' +
                   '<table class="table table-sm mt-1 mb-0"><thead><tr><th>Stream</th><th class="text-right">Users</th><th>Move</th></tr></thead><tbody>' + mv + '</tbody></table>')
                : '<div class="text-success">No moves needed — no load balancer is over the threshold.</div>') +
            '</div>';
    }

    // ── Smart Placement ───────────────────────────────────────────
    function odPlData() {
        return {
            max_moves: document.getElementById('pl_max_moves').value,
            auto: document.getElementById('pl_auto').checked ? 1 : 0
        };
    }
    function odPlSave() {
        odPost('ondemand_placement_save', odPlData()).then(function (r) { odStatus(r.note || 'Saved.', r.result); });
    }
    function odPlPreview() {
        odPost('ondemand_placement_save', odPlData())
            .then(function () { return odPost('ondemand_placement_preview', {}); })
            .then(function (r) { renderPl(r); });
    }
    function odPlApply() {
        if (!confirm('Move streams now to optimal servers? Live streams will reconnect.')) { return; }
        odPost('ondemand_placement_save', odPlData())
            .then(function () { return odPost('ondemand_placement_apply', {}); })
            .then(function (r) { odStatus(r.note, r.result); });
    }
    function renderPl(r) {
        if (r.note && (!r.servers || !r.servers.length)) {
            document.getElementById('pl-preview').innerHTML = '<div class="alert alert-warning">' + r.note + '</div>';
            return;
        }
        var srv = (r.servers || []).map(function (s, i) {
            var tag = i === 0 ? ' <span class="badge badge-success">strongest</span>' : '';
            return '<tr><td>' + s.name + tag + '</td><td class="text-right">' + s.cap + '</td></tr>';
        }).join('');
        var mv = (r.moves || []).map(function (m) {
            return '<tr><td>' + m.name + '</td><td class="text-right">' + m.weight + '</td><td>' + m.from_name + ' → <strong>' + m.to_name + '</strong></td></tr>';
        }).join('');
        document.getElementById('pl-preview').innerHTML =
            '<div class="alert alert-secondary">' +
            '<div><strong>Servers by capacity</strong> (popularity window: ' + (r.window || '') + '):</div>' +
            '<table class="table table-sm mt-1 mb-2"><thead><tr><th>Server</th><th class="text-right">Capacity</th></tr></thead><tbody>' + srv + '</tbody></table>' +
            (mv ? ('<div><strong>' + r.moves.length + ' move(s)</strong> (weight = connections in window):</div>' +
                   '<table class="table table-sm mt-1 mb-0"><thead><tr><th>Stream</th><th class="text-right">Weight</th><th>Move</th></tr></thead><tbody>' + mv + '</tbody></table>')
                : '<div class="text-success">Everything is already optimally placed.</div>') +
            '</div>';
    }
</script>
