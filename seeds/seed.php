#!/usr/bin/env php
<?php
/**
 * Seed initial data: admin user, sample raw materials, finished goods, SARA 313 subset, exempt VOCs
 * Usage: php seeds/seed.php
 */

$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "ERROR: config/config.php not found.\n");
    exit(1);
}
$config = require $configPath;

$db = $config['db'];
$dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Seeding database...\n";

    // -------------------------------------------------------
    // 1. Admin User (Argon2id)
    // -------------------------------------------------------
    $adminHash = password_hash('SDS-Admin-2024!', PASSWORD_ARGON2ID);
    $editorHash = password_hash('SDS-Editor-2024!', PASSWORD_ARGON2ID);
    $readonlyHash = password_hash('SDS-Viewer-2024!', PASSWORD_ARGON2ID);

    $pdo->exec("DELETE FROM user_group_members WHERE user_id IN (SELECT id FROM users WHERE username IN ('admin','editor','viewer'))");
    $pdo->exec("DELETE FROM users WHERE username IN ('admin','editor','viewer')");

    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, display_name, is_active)
                           VALUES (?, ?, ?, ?, 1)");
    $stmt->execute(['admin', 'admin@accucolorinks.com', $adminHash, 'System Administrator']);
    $adminId = $pdo->lastInsertId();
    $stmt->execute(['editor', 'editor@accucolorinks.com', $editorHash, 'SDS Editor']);
    $editorId = $pdo->lastInsertId();
    $stmt->execute(['viewer', 'viewer@accucolorinks.com', $readonlyHash, 'SDS Viewer']);
    $viewerId = $pdo->lastInsertId();
    echo "  Created users: admin, editor, viewer\n";

    // -------------------------------------------------------
    // 1b. Permission Groups & Membership
    // -------------------------------------------------------

    // Create Administrators group (is_admin = 1 → full access to everything)
    $pdo->exec("INSERT INTO permission_groups (name, description, is_admin)
                VALUES ('Administrators', 'Full access to all areas including user management', 1)
                ON DUPLICATE KEY UPDATE is_admin = 1");
    $adminGroupId = $pdo->query("SELECT id FROM permission_groups WHERE name = 'Administrators'")->fetchColumn();

    // Create Editors group with full access to content pages
    $pdo->exec("INSERT INTO permission_groups (name, description, is_admin)
                VALUES ('Editors', 'Can view and edit all content areas', 0)
                ON DUPLICATE KEY UPDATE id = id");
    $editorGroupId = $pdo->query("SELECT id FROM permission_groups WHERE name = 'Editors'")->fetchColumn();

    // Create Viewers group with read-only access
    $pdo->exec("INSERT INTO permission_groups (name, description, is_admin)
                VALUES ('Viewers', 'Read-only access to content areas', 0)
                ON DUPLICATE KEY UPDATE id = id");
    $viewerGroupId = $pdo->query("SELECT id FROM permission_groups WHERE name = 'Viewers'")->fetchColumn();

    // Set Editor group permissions (full access to content, no admin)
    $editorPages = [
        'dashboard' => 'full', 'raw_materials' => 'full', 'finished_goods' => 'full',
        'fg_sds_lookup' => 'full', 'rm_sds_book' => 'full', 'reports' => 'full',
        'formulas' => 'full', 'sds' => 'full', 'rm_mass_replace' => 'full',
        'cas_determinations' => 'full', 'bulk_publish' => 'full', 'bulk_export' => 'full',
        'exempt_vocs' => 'full',
    ];
    $gpStmt = $pdo->prepare("INSERT INTO group_permissions (group_id, page_key, access_level)
                              VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)");
    foreach ($editorPages as $page => $level) {
        $gpStmt->execute([$editorGroupId, $page, $level]);
    }

    // Set Viewer group permissions (read-only access)
    $viewerPages = [
        'dashboard' => 'read', 'raw_materials' => 'read', 'finished_goods' => 'read',
        'fg_sds_lookup' => 'read', 'rm_sds_book' => 'read', 'reports' => 'read',
        'formulas' => 'read', 'sds' => 'read', 'rm_mass_replace' => 'none',
        'cas_determinations' => 'read', 'bulk_publish' => 'none', 'bulk_export' => 'read',
        'exempt_vocs' => 'read',
    ];
    foreach ($viewerPages as $page => $level) {
        $gpStmt->execute([$viewerGroupId, $page, $level]);
    }

    // Assign users to groups
    $ugStmt = $pdo->prepare("INSERT INTO user_group_members (user_id, group_id) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)");
    $ugStmt->execute([$adminId, $adminGroupId]);
    $ugStmt->execute([$editorId, $editorGroupId]);
    $ugStmt->execute([$viewerId, $viewerGroupId]);
    echo "  Created permission groups and assigned users\n";

    // -------------------------------------------------------
    // 2. Sample Raw Materials
    // -------------------------------------------------------
    // RM-001: DPGDA (Dipropylene Glycol Diacrylate) - UV monomer
    $stmt = $pdo->prepare("INSERT INTO raw_materials
        (internal_code, supplier, supplier_product_name, voc_wt, exempt_voc_wt, water_wt,
         specific_gravity, temp_ref_c, flash_point_c, physical_state, appearance, odor, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute(['RM-001', 'BASF', 'Laromer DPGDA', 0.5, 0.0, 0.0,
                    1.05, 25.0, 100.0, 'Liquid', 'Clear, colorless', 'Mild acrylic', $adminId]);
    $rm1Id = $pdo->lastInsertId();

    // RM-002: Pigment Yellow 13 (in carrier)
    $stmt->execute(['RM-002', 'Sun Chemical', 'Yellow 13 Flush 55%', 0.0, 0.0, 2.0,
                    1.35, 25.0, null, 'Paste', 'Yellow paste', 'Slight', $adminId]);
    $rm2Id = $pdo->lastInsertId();

    // RM-003: TMPTA (Trimethylolpropane Triacrylate)
    $stmt->execute(['RM-003', 'Allnex', 'Ebecryl TMPTA', 0.3, 0.0, 0.0,
                    1.10, 25.0, 112.0, 'Liquid', 'Clear, light yellow', 'Acrylic', $adminId]);
    $rm3Id = $pdo->lastInsertId();

    // RM-004: Photoinitiator TPO
    $stmt->execute(['RM-004', 'IGM Resins', 'Omnirad TPO', 0.0, 0.0, 0.0,
                    1.22, 25.0, null, 'Solid', 'White to yellow powder', 'Odorless', $adminId]);
    $rm4Id = $pdo->lastInsertId();

    // RM-005: Isopropyl Alcohol (solvent - has trade secret example)
    $stmt->execute(['RM-005', 'Dow Chemical', 'IPA 99%', 100.0, 0.0, 0.5,
                    0.786, 25.0, 11.7, 'Liquid', 'Clear, colorless', 'Strong alcohol', $adminId]);
    $rm5Id = $pdo->lastInsertId();

    echo "  Created 5 raw materials\n";

    // -------------------------------------------------------
    // 3. Raw Material Constituents
    // -------------------------------------------------------
    $cStmt = $pdo->prepare("INSERT INTO raw_material_constituents
        (raw_material_id, cas_number, chemical_name, pct_min, pct_max, pct_exact, is_trade_secret, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    // RM-001 DPGDA constituents
    $cStmt->execute([$rm1Id, '57472-68-1', 'Dipropylene Glycol Diacrylate', 95.0, 100.0, null, 0, 1]);
    $cStmt->execute([$rm1Id, '96-33-3', 'Methyl Acrylate', null, null, 0.5, 0, 2]);

    // RM-002 Pigment Yellow 13 flush
    $cStmt->execute([$rm2Id, '5102-83-0', 'Pigment Yellow 13', 50.0, 60.0, null, 0, 1]);
    $cStmt->execute([$rm2Id, '64742-48-9', 'Petroleum Distillates', 20.0, 30.0, null, 1, 2]); // trade secret: carrier
    $cStmt->execute([$rm2Id, '7732-18-5', 'Water', null, null, 2.0, 0, 3]);

    // RM-003 TMPTA
    $cStmt->execute([$rm3Id, '15625-89-5', 'Trimethylolpropane Triacrylate', 95.0, 100.0, null, 0, 1]);

    // RM-004 TPO
    $cStmt->execute([$rm4Id, '75980-60-8', 'Diphenyl(2,4,6-trimethylbenzoyl)phosphine Oxide', 97.0, 100.0, null, 0, 1]);

    // RM-005 IPA
    $cStmt->execute([$rm5Id, '67-63-0', 'Isopropyl Alcohol', 99.0, 100.0, null, 0, 1]);
    $cStmt->execute([$rm5Id, '7732-18-5', 'Water', null, null, 0.5, 0, 2]);

    echo "  Created raw material constituents\n";

    // -------------------------------------------------------
    // 4. CAS Master entries
    // -------------------------------------------------------
    $casStmt = $pdo->prepare("INSERT IGNORE INTO cas_master (cas_number, preferred_name) VALUES (?, ?)");
    $casData = [
        ['57472-68-1', 'Dipropylene Glycol Diacrylate'],
        ['96-33-3', 'Methyl Acrylate'],
        ['5102-83-0', 'Pigment Yellow 13'],
        ['64742-48-9', 'Petroleum Distillates (Hydrotreated Light)'],
        ['7732-18-5', 'Water'],
        ['15625-89-5', 'Trimethylolpropane Triacrylate'],
        ['75980-60-8', 'Diphenyl(2,4,6-trimethylbenzoyl)phosphine Oxide'],
        ['67-63-0', 'Isopropyl Alcohol'],
    ];
    foreach ($casData as $cas) {
        $casStmt->execute($cas);
    }
    echo "  Created CAS master entries\n";

    // -------------------------------------------------------
    // 5. Finished Goods
    // -------------------------------------------------------
    $fgStmt = $pdo->prepare("INSERT INTO finished_goods (product_code, description, family, created_by)
                             VALUES (?, ?, ?, ?)");
    $fgStmt->execute(['UV-YEL-100', 'UV Offset Yellow Process Ink', 'UV Offset', $adminId]);
    $fg1Id = $pdo->lastInsertId();

    $fgStmt->execute(['FX-CLR-200', 'UV Flexo Overprint Varnish', 'UV Flexo', $adminId]);
    $fg2Id = $pdo->lastInsertId();

    echo "  Created 2 finished goods\n";

    // -------------------------------------------------------
    // 6. Formulas
    // -------------------------------------------------------
    $fStmt = $pdo->prepare("INSERT INTO formulas (finished_good_id, version, is_current, created_by, notes)
                            VALUES (?, ?, 1, ?, ?)");

    // Formula for UV-YEL-100
    $fStmt->execute([$fg1Id, 1, $adminId, 'Initial UV yellow formulation']);
    $f1Id = $pdo->lastInsertId();

    // Formula for FX-CLR-200
    $fStmt->execute([$fg2Id, 1, $adminId, 'Standard UV flexo OPV']);
    $f2Id = $pdo->lastInsertId();

    $flStmt = $pdo->prepare("INSERT INTO formula_lines (formula_id, raw_material_id, pct, sort_order)
                             VALUES (?, ?, ?, ?)");

    // UV-YEL-100: DPGDA 40% + PY13 flush 25% + TMPTA 30% + TPO 5%
    $flStmt->execute([$f1Id, $rm1Id, 40.0, 1]);
    $flStmt->execute([$f1Id, $rm2Id, 25.0, 2]);
    $flStmt->execute([$f1Id, $rm3Id, 30.0, 3]);
    $flStmt->execute([$f1Id, $rm4Id, 5.0, 4]);

    // FX-CLR-200: DPGDA 55% + TMPTA 40% + TPO 5%
    $flStmt->execute([$f2Id, $rm1Id, 55.0, 1]);
    $flStmt->execute([$f2Id, $rm3Id, 40.0, 2]);
    $flStmt->execute([$f2Id, $rm4Id, 5.0, 3]);

    echo "  Created formulas with lines\n";

    // -------------------------------------------------------
    // 7. SARA 313 List (subset of commonly encountered chemicals)
    // -------------------------------------------------------
    $saraStmt = $pdo->prepare("INSERT IGNORE INTO sara313_list
        (cas_number, chemical_name, category_code, deminimis_pct, is_pbt, source_ref)
        VALUES (?, ?, ?, ?, ?, ?)");
    $saraData = [
        ['67-63-0', 'Isopropyl Alcohol (manufacturing)', 'N/A', 1.0, 0, 'EPA TRI Chemical List 2024'],
        ['96-33-3', 'Methyl Acrylate', 'N/A', 1.0, 0, 'EPA TRI Chemical List 2024'],
        ['108-88-3', 'Toluene', 'N/A', 1.0, 0, 'EPA TRI Chemical List 2024'],
        ['1330-20-7', 'Xylene (mixed isomers)', 'N/A', 1.0, 0, 'EPA TRI Chemical List 2024'],
        ['71-43-2', 'Benzene', 'N/A', 0.1, 0, 'EPA TRI Chemical List 2024'],
        ['50-00-0', 'Formaldehyde', 'N/A', 0.1, 0, 'EPA TRI Chemical List 2024'],
        ['7440-02-0', 'Nickel', 'N/A', 0.1, 0, 'EPA TRI Chemical List 2024'],
        ['7439-92-1', 'Lead', 'PBT', 0.1, 1, 'EPA TRI Chemical List 2024'],
        ['7439-97-6', 'Mercury', 'PBT', 0.1, 1, 'EPA TRI Chemical List 2024'],
    ];
    foreach ($saraData as $sara) {
        $saraStmt->execute($sara);
    }
    echo "  Seeded SARA 313 list (subset)\n";

    // -------------------------------------------------------
    // 8. Exempt VOC List (EPA exemptions)
    // -------------------------------------------------------
    $evStmt = $pdo->prepare("INSERT IGNORE INTO exempt_voc_list
        (cas_number, chemical_name, regulation_ref) VALUES (?, ?, ?)");
    $exemptVocs = [
        ['67-64-1', 'Acetone', '40 CFR 51.100(s)'],
        ['75-45-6', 'Chlorodifluoromethane (HCFC-22)', '40 CFR 51.100(s)'],
        ['4170-30-3', '2-Butenal (Crotonaldehyde)', '40 CFR 51.100(s)'],
        ['107-31-3', 'Methyl Formate', '40 CFR 51.100(s)'],
        ['75-37-6', '1,1-Difluoroethane (HFC-152a)', '40 CFR 51.100(s)'],
        ['71-55-6', '1,1,1-Trichloroethane (Methyl Chloroform)', '40 CFR 51.100(s)'],
        ['100-41-4', 'Parachlorobenzotrifluoride (PCBTF)', '40 CFR 51.100(s)'],
    ];
    foreach ($exemptVocs as $ev) {
        $evStmt->execute($ev);
    }
    echo "  Seeded exempt VOC list\n";

    // -------------------------------------------------------
    // 9. Default settings
    // -------------------------------------------------------
    $setStmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $settings = [
        ['voc_calc_mode', 'method24_standard'],
        ['source_priority', 'pubchem,niosh,epa,dot'],
        ['company_name', 'AccuColor Inks, Inc.'],
        ['uv_acrylate_rule_pack', 'enabled'],
        ['sara_deminimis_default', '1.0'],
        ['sds_block_publish_missing', '1'],
    ];
    foreach ($settings as $s) {
        $setStmt->execute($s);
    }
    echo "  Seeded settings\n";

    echo "\nSeed complete! Default login: admin / SDS-Admin-2024!\n";

} catch (PDOException $e) {
    fwrite(STDERR, "Database error: " . $e->getMessage() . "\n");
    exit(1);
}
