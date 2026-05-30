<?php

function get_blocked_remarks() {
    $raw = setting('blocked_remarks', '');
    $arr = json_decode($raw, true);
    if (!is_array($arr) || !$arr) {
        $arr = ['🚫 Устройство заблокировано', 'Обратитесь в поддержку', '@your_support'];
    }
    return array_values(array_filter(array_map('strval', $arr), fn($s) => trim($s) !== ''));
}

function find_override($match_type, $match_value) {
    if ($match_value === '' || !($p = db())) return null;
    try {
        $stmt = $p->prepare('SELECT * FROM overrides WHERE match_type = ? AND match_value = ? LIMIT 1');
        $stmt->execute([$match_type, $match_value]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('submw find_override: ' . $e->getMessage());
        return null;
    }
}

function find_override_in($match_type, array $candidates) {
    $candidates = array_values(array_unique(array_filter($candidates, fn($s) => $s !== '')));
    if (!$candidates || !($p = db())) return null;
    try {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $p->prepare("SELECT * FROM overrides WHERE match_type = ? AND match_value IN ($in) LIMIT 1");
        $stmt->execute(array_merge([$match_type], $candidates));
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('submw find_override_in: ' . $e->getMessage());
        return null;
    }
}

function upsert_override($match_type, $match_value, $reason, $source, $username = null, $note = null) {
    if (!($p = db())) return false;
    if (db_driver() === 'mysql') {
        $stmt = $p->prepare(
            'INSERT INTO overrides (match_type, match_value, reason, source, username, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source),
                username = VALUES(username), note = VALUES(note), updated_at = CURRENT_TIMESTAMP'
        );
    } else {
        $stmt = $p->prepare(
            'INSERT INTO overrides (match_type, match_value, reason, source, username, note)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(match_type, match_value) DO UPDATE SET reason = excluded.reason, source = excluded.source,
                username = excluded.username, note = excluded.note, updated_at = CURRENT_TIMESTAMP'
        );
    }
    return $stmt->execute([$match_type, $match_value, $reason, $source, $username, $note]);
}

function delete_override($match_type, $match_value, $only_source = null) {
    if (!($p = db())) return false;
    if ($only_source !== null) {
        $stmt = $p->prepare('DELETE FROM overrides WHERE match_type = ? AND match_value = ? AND source = ?');
        return $stmt->execute([$match_type, $match_value, $only_source]);
    }
    $stmt = $p->prepare('DELETE FROM overrides WHERE match_type = ? AND match_value = ?');
    return $stmt->execute([$match_type, $match_value]);
}
