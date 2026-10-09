<?php
/**
 * DB-free checks for TSCAInventoryImporter — the importer shared by
 * scripts/import-tsca-inventory.php and the /tsca upload card:
 *
 *   parse():
 *     - EPA-style file: BOM + preamble lines before the header, zero-padded
 *       CAS stripped, ACTIVITY / FLAG mapped, INACTIVE → 0, duplicates
 *       counted (first wins), accession numbers / blanks skipped, blank
 *       lines ignored, Windows-1252 name converted to UTF-8.
 *     - Odd headers ("CAS No", "Chemical Name"), no activity / flag column →
 *       warnings, every row ACTIVE, flags null.
 *     - No CAS header → headerError + headerFound; name column missing →
 *       headerError; CAS rows absent → ok=false.
 *   plan():
 *     - insert / update / unchanged / manual-skip classification, prune
 *       estimate (EPA rows not in the file with a different stamp; same
 *       stamp and manual rows never pruned), in-use CAS affected with and
 *       without prune, stored spelling returned for the bumper.
 *   normaliseVersion(): default from CSV basename, 100-char cap.
 *
 * Same Reflection bootstrap as TSCAServiceTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TSCAInventoryImporterTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, ['paths' => []]);

$failures = 0;
$checks   = 0;

function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) {
        echo '       actual: ' . var_export($actual, true) . "\n";
    }
}

use SDS\Services\TSCAInventoryImporter;

$tmpDir = $basePath . '/storage/temp/tsca-importer-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0755, true);
$fixtures = [];
$fixture = static function (string $name, string $content) use ($tmpDir, &$fixtures): string {
    $p = $tmpDir . '/' . $name;
    file_put_contents($p, $content);
    $fixtures[] = $p;
    return $p;
};

$importer = new TSCAInventoryImporter(null);

try {
    // -----------------------------------------------------------------
    echo "a. parse — EPA-style file\n";
    $epa = $fixture('TSCAINV_022025.csv',
        "\xEF\xBB\xBF" . "EPA TSCA Inventory,non-confidential,\n"
        . "Generated 2025-02-01,,\n"
        . "\n"
        . "ID,CASRN,CA INDEX NAME,UVCB,FLAG,ACTIVITY,DEF\n"
        . "1,0000050-00-0,Formaldehyde,,S,ACTIVE,\n"
        . "2,108-88-3,Toluene,,,ACTIVE,\n"
        . "3,64-17-5,Ethanol,,XU,INACTIVE,\n"
        . "4,108-88-3,Toluene (dupe),,,ACTIVE,\n"
        . "5,ACC123456,Confidential substance,,,ACTIVE,\n"
        . "6,,Blank CAS,,,ACTIVE,\n"
        . "\n"
        . "7,7732-18-5,Water \xE9t\xE9,,P,,\n"          // Windows-1252 é
        . "8,1-11-1,Low,,,active,\n"
    );
    $p = $importer->parse($epa);
    check($p['ok'] === true && $p['headerError'] === null, 'ok, no header error', $p['headerError']);
    check($p['columns']['cas'] === 'CASRN' && $p['columns']['name'] === 'CA INDEX NAME'
        && $p['columns']['activity'] === 'ACTIVITY' && $p['columns']['flag'] === 'FLAG' && $p['columns']['uvcb'] === 'UVCB',
        'columns detected past BOM + preamble', $p['columns']);
    check($p['headerFound'][0] === 'ID', 'BOM stripped from first header cell', $p['headerFound'][0] ?? null);
    check($p['rows'] === 8, '8 data rows (blank lines ignored)', $p['rows']);
    check($p['uniqueCas'] === 5 && count($p['parsed']) === 5, '5 unique CAS', $p['uniqueCas']);
    check($p['skippedNoCas'] === 2, 'accession number + blank skipped', $p['skippedNoCas']);
    check($p['dupes'] === 1, 'duplicate counted', $p['dupes']);
    check(isset($p['parsed']['50-00-0']) && !isset($p['parsed']['0000050-00-0']), 'zero padding stripped', array_keys($p['parsed']));
    check($p['parsed']['50-00-0'] === ['name' => 'Formaldehyde', 'active' => 1, 'flags' => 'S'], 'name / active / flags mapped', $p['parsed']['50-00-0']);
    check($p['parsed']['108-88-3']['name'] === 'Toluene', 'first duplicate wins', $p['parsed']['108-88-3']);
    check($p['parsed']['108-88-3']['flags'] === null, 'blank flag → null');
    check($p['parsed']['64-17-5']['active'] === 0 && $p['parsed']['64-17-5']['flags'] === 'XU', 'INACTIVE → 0, flag kept', $p['parsed']['64-17-5']);
    check($p['parsed']['7732-18-5']['name'] === 'Water été' && $p['parsed']['7732-18-5']['active'] === 1, 'Windows-1252 converted, blank activity → ACTIVE', $p['parsed']['7732-18-5']);
    check($p['parsed']['1-11-1']['active'] === 1, 'lowercase active → 1');
    check($p['warnings'] === [], 'no warnings when activity + flag present', $p['warnings']);

    // -----------------------------------------------------------------
    echo "b. parse — odd headers, no activity / flag\n";
    $odd = $fixture('odd.csv', "CAS No,Chemical Name\n\"108-88-3\",\"Toluene, technical\"\n50-00-0,Formaldehyde\n");
    $p = $importer->parse($odd);
    check($p['ok'] === true && $p['columns']['cas'] === 'CAS No' && $p['columns']['name'] === 'Chemical Name', 'CAS No / Chemical Name matched', $p['columns']);
    check($p['columns']['activity'] === null && $p['columns']['flag'] === null, 'activity / flag absent');
    check(count($p['warnings']) === 2 && str_contains($p['warnings'][0], 'ACTIVITY') && str_contains($p['warnings'][1], 'FLAG'), 'two warnings', $p['warnings']);
    check($p['parsed']['108-88-3'] === ['name' => 'Toluene, technical', 'active' => 1, 'flags' => null], 'all ACTIVE, flags null, quoted comma kept', $p['parsed']['108-88-3']);
    check($p['uniqueCas'] === 2 && $p['rows'] === 2, '2 rows / 2 CAS');

    // -----------------------------------------------------------------
    echo "b2. parse — real EPA 2025 download header (ChemName, casregno, UID, EXP, DEF)\n";
    $epa2025 = $fixture('TSCAINV_real_2025.csv',
        "ID,CASRN,casregno,UID,EXP,ChemName,DEF,UVCB,FLAG,ACTIVITY\n"
        . "1,108-88-3,108883,,,\"Benzene, methyl-\",,N,,ACTIVE\n"
        . "2,50-00-0,50000,,,Formaldehyde,,N,S,INACTIVE\n"
    );
    $p = $importer->parse($epa2025);
    check($p['ok'] === true && $p['headerError'] === null, 'real EPA header accepted', $p['headerError']);
    check($p['columns']['cas'] === 'CASRN' && $p['columns']['name'] === 'ChemName'
        && $p['columns']['activity'] === 'ACTIVITY' && $p['columns']['flag'] === 'FLAG' && $p['columns']['uvcb'] === 'UVCB',
        'CASRN (not casregno) + ChemName + ACTIVITY/FLAG/UVCB detected', $p['columns']);
    check($p['parsed']['108-88-3'] === ['name' => 'Benzene, methyl-', 'active' => 1, 'flags' => null], 'row mapped from the real layout', $p['parsed']['108-88-3'] ?? null);
    check($p['parsed']['50-00-0']['active'] === 0 && $p['parsed']['50-00-0']['flags'] === 'S', 'INACTIVE + flag from the real layout', $p['parsed']['50-00-0'] ?? null);

    // -----------------------------------------------------------------
    echo "c. parse — header problems\n";
    $noCas = $fixture('nocas.csv', "ID,Substance,Status\n1,Toluene,ACTIVE\n");
    $p = $importer->parse($noCas);
    check($p['ok'] === false && is_string($p['headerError']) && str_contains($p['headerError'], 'Could not find header row'), 'no CAS column → header error', $p['headerError']);
    check($p['headerFound'] === ['ID', 'Substance', 'Status'], 'first row reported as headerFound', $p['headerFound']);
    check($p['parsed'] === [] && $p['rows'] === 0, 'nothing parsed');

    $noName = $fixture('noname.csv', "CASRN,ACTIVITY\n108-88-3,ACTIVE\n");
    $p = $importer->parse($noName);
    check($p['ok'] === false && $p['headerError'] === 'Header missing required column: name', 'name column missing → header error', $p['headerError']);
    check($p['headerFound'] === ['CASRN', 'ACTIVITY'], 'headers reported for regex tuning', $p['headerFound']);

    $empty = $fixture('empty.csv', "CASRN,CA INDEX NAME\nACC1,Confidential\n,Blank\n");
    $p = $importer->parse($empty);
    check($p['ok'] === false && $p['headerError'] === null && $p['skippedNoCas'] === 2, 'header ok but no valid CAS → ok=false', [$p['ok'], $p['headerError'], $p['skippedNoCas']]);
    check(in_array('No valid CAS rows parsed — nothing to do.', $p['warnings'], true), 'nothing-to-do warning', $p['warnings']);

    $p = $importer->parse($tmpDir . '/missing.csv');
    check($p['ok'] === false && str_starts_with((string) $p['headerError'], 'Cannot open CSV'), 'missing file → Cannot open', $p['headerError']);

    // -----------------------------------------------------------------
    echo "d. plan\n";
    $parsed = [
        '50-00-0'   => ['name' => 'Formaldehyde', 'active' => 1, 'flags' => 'S'],     // new
        '108-88-3'  => ['name' => 'Toluene',      'active' => 1, 'flags' => null],    // unchanged EPA
        '64-17-5'   => ['name' => 'Ethanol',      'active' => 0, 'flags' => 'XU'],    // changed (activity)
        '67-56-1'   => ['name' => 'Methanol',     'active' => 1, 'flags' => null],    // manual → skipped
        '7732-18-5' => ['name' => 'Water',        'active' => 1, 'flags' => 'P'],     // changed (flags '' → 'P')
    ];
    $existing = [
        '108-88-3'  => ['cas_number' => '108-88-3',  'chemical_name' => 'Toluene',  'is_active_inventory' => 1, 'flags' => null, 'source_ref' => 'EPA',    'source_version' => 'OLD'],
        '64-17-5'   => ['cas_number' => '64-17-5',   'chemical_name' => 'Ethanol',  'is_active_inventory' => 1, 'flags' => 'XU', 'source_ref' => 'EPA',    'source_version' => 'OLD'],
        '67-56-1'   => ['cas_number' => '67-56-1',   'chemical_name' => 'MeOH',     'is_active_inventory' => 1, 'flags' => null, 'source_ref' => 'manual', 'source_version' => null],
        '7732-18-5' => ['cas_number' => '7732-18-5', 'chemical_name' => 'Water',    'is_active_inventory' => 1, 'flags' => '',   'source_ref' => 'EPA',    'source_version' => 'OLD'],
        '71-43-2'   => ['cas_number' => '71-43-2',   'chemical_name' => 'Benzene',  'is_active_inventory' => 1, 'flags' => null, 'source_ref' => 'EPA',    'source_version' => 'OLD'],  // not in file → prune
        '75-07-0'   => ['cas_number' => '75-07-0',   'chemical_name' => 'Acetaldehyde', 'is_active_inventory' => 1, 'flags' => null, 'source_ref' => 'EPA', 'source_version' => null], // null stamp → prune
        '100-41-4'  => ['cas_number' => '100-41-4',  'chemical_name' => 'Ethylbenzene', 'is_active_inventory' => 1, 'flags' => null, 'source_ref' => 'EPA', 'source_version' => 'NEW'], // same stamp → kept
        '1330-20-7' => ['cas_number' => '1330-20-7', 'chemical_name' => 'Xylenes',  'is_active_inventory' => 1, 'flags' => null, 'source_ref' => 'manual', 'source_version' => null], // manual → never pruned
    ];
    $inUse = [
        '50-00-0' => '0000050-00-0',   // stored with EPA padding in raw_material_constituents
        '71-43-2' => '71-43-2',
        '64-17-5' => '64-17-5',
    ];

    $r = $importer->plan($parsed, $existing, $inUse, 'NEW', false);
    check($r['inserted'] === 1 && $r['insertedCas'] === ['50-00-0'], 'one insert', [$r['inserted'], $r['insertedCas']]);
    check($r['updated'] === 2, 'two updates (activity, flags)', $r['updated']);
    check($r['unchanged'] === 1 && $r['toRestamp'] === ['108-88-3'], 'one unchanged → restamp', $r['toRestamp']);
    check($r['skippedManual'] === 1, 'manual row skipped', $r['skippedManual']);
    check(count($r['toUpsert']) === 3 && $r['toUpsert'][0] === ['50-00-0', 'Formaldehyde', 1, 'S'], 'toUpsert = insert + updates', $r['toUpsert']);
    check($r['pruned'] === 0 && $r['pruneEstimate'] === 2, 'prune off: pruned 0, estimate 2', [$r['pruned'], $r['pruneEstimate']]);
    check($r['prunedCas'] === ['71-43-2', '75-07-0'], 'prune estimate = EPA rows not in file with other / null stamp', $r['prunedCas']);
    check($r['changedCas'] === ['50-00-0'], 'prune off: changed = inserted only', $r['changedCas']);
    check($r['affected'] === ['0000050-00-0'] && $r['affectedCount'] === 1, 'affected uses the stored spelling', $r['affected']);
    check($r['existingCount'] === 8 && $r['version'] === 'NEW' && $r['prune'] === false, 'metadata carried');

    $r = $importer->plan($parsed, $existing, $inUse, 'NEW', true);
    check($r['pruned'] === 2, 'prune on: pruned 2', $r['pruned']);
    check($r['changedCas'] === ['50-00-0', '71-43-2', '75-07-0'], 'prune on: changed = inserted ∪ pruned', $r['changedCas']);
    check($r['affected'] === ['0000050-00-0', '71-43-2'] && $r['affectedCount'] === 2, 'pruned in-use CAS counted (64-17-5 update is not)', $r['affected']);

    $r = $importer->plan($parsed, [], [], 'NEW', true);
    check($r['inserted'] === 5 && $r['updated'] === 0 && $r['pruned'] === 0 && $r['affected'] === [], 'empty table: everything inserts, nothing affected', [$r['inserted'], $r['pruned']]);

    $r = $importer->plan($parsed, $existing, $inUse, 'OLD', true);
    check($r['prunedCas'] === ['75-07-0', '100-41-4'], 're-run with label OLD keeps OLD-stamped rows, prunes null / other stamps not in file', $r['prunedCas']);

    // is_stale (from fetchExisting($version), SQL collation) wins over the byte-exact PHP comparison.
    $flagged = $existing;
    $flagged['71-43-2']['source_version'] = 'new';   // case-variant of the label: DB says equal
    $flagged['71-43-2']['is_stale']       = 0;
    $flagged['75-07-0']['is_stale']       = 1;
    $flagged['100-41-4']['is_stale']      = 0;
    $r = $importer->plan($parsed, $flagged, $inUse, 'NEW', true);
    check($r['prunedCas'] === ['75-07-0'], 'is_stale=0 keeps a case-variant stamp the DB treats as equal', $r['prunedCas']);
    check($r['affected'] === ['0000050-00-0'], 'kept row is not counted as affected', $r['affected']);

    // -----------------------------------------------------------------
    echo "e. normaliseVersion / summary\n";
    check(TSCAInventoryImporter::normaliseVersion('', '/x/TSCAINV_022025.csv') === 'TSCAINV_022025', 'blank → CSV basename');
    check(TSCAInventoryImporter::normaliseVersion('  v2 ', '/x/a.csv') === 'v2', 'trimmed label wins');
    check(TSCAInventoryImporter::normaliseVersion('', null) === '', 'blank with no fallback stays blank');
    check(mb_strlen(TSCAInventoryImporter::normaliseVersion(str_repeat('x', 150))) === 100, 'capped at 100');
    check(TSCAInventoryImporter::defaultVersion('C:\\dl\\TSCAINV_022025.zip') !== '' , 'defaultVersion non-empty');
    $s = TSCAInventoryImporter::summary(['version' => 'NEW', 'inserted' => 1, 'updated' => 2, 'unchanged' => 3, 'skippedManual' => 4, 'pruned' => 5, 'affectedCount' => 6, 'rmsBumped' => 7, 'sdsQueued' => 8]);
    check($s === ['version' => 'NEW', 'inserted' => 1, 'updated' => 2, 'unchanged' => 3, 'skipped_manual' => 4, 'pruned' => 5, 'cas_in_use_changed' => 6, 'rms_bumped' => 7, 'sds_queued' => 8], 'summary keys', $s);

    // -----------------------------------------------------------------
    echo "f. apply refuses bad input before touching the DB\n";
    try {
        $importer->apply([], ['version' => 'X']);
        check(false, 'empty parsed throws');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), 'nothing to do'), 'empty parsed throws', $e->getMessage());
    }
    try {
        $importer->apply($parsed, ['version' => '  ']);
        check(false, 'blank version throws');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), 'version label'), 'blank version throws', $e->getMessage());
    }
} finally {
    foreach ($fixtures as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
