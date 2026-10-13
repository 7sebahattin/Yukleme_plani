<?php
declare(strict_types=1);

// Expand each role before merging: one role cannot change another role's policy.
function beyan_expand_role_permissions(array $permissions): array {
    if (!in_array('beyan.permissions.detailed', $permissions, true)) {
        if (in_array('beyan.read', $permissions, true)) {
            foreach (array_keys(beyan_permission_fields()) as $section) $permissions[] = "beyan.$section.read";
        }
        if (in_array('beyan.write', $permissions, true)) {
            foreach (['create','edit','status','match','notify'] as $action) $permissions[] = "beyan.$action";
            foreach (array_keys(beyan_permission_fields()) as $section) $permissions[] = "beyan.$section.write";
        }
    }
    return array_values(array_unique($permissions));
}

function beyan_permission_fields(): array {
    return [
        'contact' => ['company_address','contact_person','buyer_name'],
        'analysis' => ['sample_taken_at','analysis_result_at','analysis_note','sample_gross_kg','sample_net_kg','sample_crate_type','sample_crate_count'],
        'source' => ['raw_text','unmatched_text'],
        'hks' => ['hks_firma_id','sender_company_name','hks_urun_id','hks_urun_ad','hks_ulke_id','hks_ulke_ad'],
    ];
}

function beyan_redact_fields(array $row): array {
    foreach (beyan_permission_fields() as $section => $fields) {
        if (!can_beyan($section . '.read')) foreach ($fields as $field) $row[$field] = null;
    }
    return $row;
}

// Hidden/disabled browser fields are not a security boundary.
function beyan_protect_input(array $input, array $existing = []): array {
    foreach (beyan_permission_fields() as $section => $fields) {
        if (!can_beyan($section . '.write')) foreach ($fields as $field) $input[$field] = $existing[$field] ?? '';
    }
    if (!can_beyan('status')) $input['status'] = $existing['status'] ?? 'beyan_acildi';
    return $input;
}
