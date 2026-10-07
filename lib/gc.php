<?php

function gc_tables() {
    return [
        'request_log'    => ['title' => 'Лог запросов',       'pk' => 'id',        'col' => 'ts',        'kind' => 'text'],
        'webhook_log'    => ['title' => 'Лог вебхуков',       'pk' => 'id',        'col' => 'ts',        'kind' => 'text'],
        'forward_log'    => ['title' => 'Лог пересылки',      'pk' => 'id',        'col' => 'ts',        'kind' => 'text'],
        'panel_write_log' => ['title' => 'Журнал записей в панель', 'pk' => 'id',   'col' => 'ts',        'kind' => 'text'],
        'metrics_minute' => ['title' => 'Метрики по минутам', 'pk' => 'minute_ts', 'col' => 'minute_ts', 'kind' => 'epoch'],
        'metrics_peak'   => ['title' => 'Пики нагрузки',      'pk' => 'minute_ts', 'col' => 'minute_ts', 'kind' => 'epoch'],
        'rep_hour'       => ['title' => 'Отчёты: сводка по часам',  'pk' => 'id', 'col' => 'h',         'kind' => 'epoch'],
        'rep_ip_day'     => ['title' => 'Отчёты: сводка по IP',     'pk' => 'id', 'col' => 'd',         'kind' => 'epoch'],
        'rep_state'      => ['title' => 'Отчёты: состояние устройств', 'pk' => 'id', 'col' => 'last_seen', 'kind' => 'epoch'],
        'rep_net_ip'     => ['title' => 'Отчёты: адреса устройств',  'pk' => 'id', 'col' => 'last_h',    'kind' => 'epoch'],
    ];
}

function gc_periods() { return [30, 60, 90]; }

function gc_chunk() { return 2000; }

function gc_cut_value($kind, $epoch) {
    if ($kind === 'epoch') return (int) $epoch;
    return db_driver() === 'mysql' ? date('Y-m-d H:i:s', (int) $epoch) : gmdate('Y-m-d H:i:s', (int) $epoch);
}

function gc_where(array $t, $days, array &$args) {
    $args = [];
    if ((int) $days <= 0) return '1 = 1';
    $args[] = gc_cut_value($t['kind'], time() - (int) $days * 86400);
    return $t['col'] . ' < ?';
}

function gc_ensure() {
    ensure_forward_log();
    ensure_panel_write_log();
    ensure_metrics_tables();
    rep_ensure();
}

function gc_count($table, $days) {
    $all = gc_tables();
    if (!isset($all[$table]) || !($p = db())) return null;
    $args = [];
    $where = gc_where($all[$table], $days, $args);
    try {
        $st = $p->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $st->execute($args);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return null; }
}

function gc_overview_counts($name, array $t, array $periods) {
    $res = ['total' => null, 'old' => array_fill_keys($periods, null)];
    if (!($p = db())) return $res;
    $sel = ['COUNT(*)'];
    $args = [];
    foreach ($periods as $d) {
        $sel[] = 'SUM(CASE WHEN ' . $t['col'] . ' < ? THEN 1 ELSE 0 END)';
        $args[] = gc_cut_value($t['kind'], time() - (int) $d * 86400);
    }
    try {
        $st = $p->prepare('SELECT ' . implode(', ', $sel) . " FROM $name");
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) { return $res; }
    if (!is_array($r)) $r = [];
    $res['total'] = (int) ($r[0] ?? 0);
    foreach (array_values($periods) as $i => $d) $res['old'][$d] = (int) ($r[$i + 1] ?? 0);
    return $res;
}

function gc_overview() {
    gc_ensure();
    $out = [];
    $periods = gc_periods();
    foreach (gc_tables() as $name => $t) {
        $c = gc_overview_counts($name, $t, $periods);
        $out[$name] = ['title' => $t['title'], 'total' => $c['total'], 'old' => $c['old']];
    }
    return $out;
}

function gc_purge($table, $days, $budget = 12.0) {
    $all = gc_tables();
    if (!isset($all[$table]) || !($p = db())) return 0;
    $t  = $all[$table];
    $pk = $t['pk'];
    $n  = gc_chunk();
    $args = [];
    $where = gc_where($t, $days, $args);
    $sql = "DELETE FROM $table WHERE $pk IN (SELECT $pk FROM (SELECT $pk FROM $table WHERE $where ORDER BY $pk LIMIT $n) x)";
    $done = 0;
    $t0 = microtime(true);
    try {
        $st = $p->prepare($sql);
        do {
            $st->execute($args);
            $hit = $st->rowCount();
            $done += $hit;
        } while ($hit >= $n && microtime(true) - $t0 < $budget);
    } catch (Throwable $e) {
        error_log('submw gc_purge ' . $table . ': ' . $e->getMessage());
    }
    return $done;
}

function gc_free_bytes() {
    if (!($p = db())) return null;
    try {
        if (db_driver() === 'mysql') {
            $st = $p->query('SELECT COALESCE(SUM(data_free), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
            return (int) $st->fetchColumn();
        }
        $free = (int) $p->query('PRAGMA freelist_count')->fetchColumn();
        $page = (int) $p->query('PRAGMA page_size')->fetchColumn();
        return $free * $page;
    } catch (Throwable $e) { return null; }
}

function gc_compact(&$err = '') {
    $err = '';
    if (!($p = db())) { $err = 'нет соединения с базой'; return false; }
    gc_ensure();
    try {
        if (db_driver() === 'mysql') {
            foreach (array_keys(gc_tables()) as $name) {
                if (!db_table_exists($p, 'mysql', $name)) continue;
                $st = $p->query('OPTIMIZE TABLE ' . $name);
                if ($st) { $st->fetchAll(); $st->closeCursor(); }
            }
            return true;
        }
        try { $p->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) {}
        $p->exec('VACUUM');
        return true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        return false;
    }
}
