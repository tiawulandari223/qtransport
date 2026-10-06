<?php

/**
 * Append-only audit trail. Rows are never updated or deleted by the application.
 * $before/$after are arrays; stored as JSON for the record.
 */
function audit_log(string $entity, string $entityId, string $action, ?array $before, ?array $after): void {
    $u = auth_user();
    q('INSERT INTO audit_log(user_id,user_name,entity,entity_id,action,before_json,after_json) VALUES(?,?,?,?,?,?,?)',
        [$u['id'] ?? null, $u['name'] ?? 'system', $entity, $entityId, $action,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE)]);
}

/** Diff two flat arrays into only the changed fields (for readable audit rows). */
function changed_fields(?array $before, ?array $after, array $ignore = []): array {
    $before ??= [];
    $after ??= [];
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    $out = [];
    foreach ($keys as $k) {
        if (in_array($k, $ignore, true)) continue;
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if ((string)$b !== (string)$a) $out[$k] = ['before' => $b, 'after' => $a];
    }
    return $out;
}
