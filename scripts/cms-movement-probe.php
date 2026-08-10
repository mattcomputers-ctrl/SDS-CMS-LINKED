#!/usr/bin/env php
<?php
/**
 * cms-movement-probe.php — discover how CMS records raw material
 * consumption (production usage) so the Ross report can total pounds
 * produced. Prints distinct movement contexts with row counts and
 * quantity signs, plus a few sample rows for the busiest contexts.
 *
 * Usage: php scripts/cms-movement-probe.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Services\CMSDatabase;

new App();
if (!CMSDatabase::isConfigured()) {
    fwrite(STDERR, "CMS database is not configured.\n");
    exit(1);
}
$cms = CMSDatabase::getInstance();

echo "Distinct InvMovementDtl contexts (last 12 months):\n";
$rows = $cms->fetchAll(
    "SELECT im.Context, COUNT(*) AS cnt,
            SUM(CASE WHEN imd.Qty > 0 THEN 1 ELSE 0 END) AS pos_rows,
            SUM(CASE WHEN imd.Qty < 0 THEN 1 ELSE 0 END) AS neg_rows
     FROM CMS.dbo.InvMovementDtl imd
     JOIN CMS.dbo.InvMovement im ON im.InvMovement = imd.InvMovement
     JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = im.ChangeSet
     GROUP BY im.Context
     ORDER BY cnt DESC"
);
foreach ($rows as $r) {
    echo "  {$r['Context']}: {$r['cnt']} rows ({$r['pos_rows']} pos / {$r['neg_rows']} neg)\n";
}

echo "\nInvMovement columns with a date-ish type:\n";
$cols = $cms->fetchAll(
    "SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
     FROM CMS.INFORMATION_SCHEMA.COLUMNS c
     WHERE c.TABLE_NAME IN ('InvMovement', 'InvMovementDtl', 'ChangeSet')
       AND c.DATA_TYPE IN ('datetime', 'date', 'smalldatetime')
     ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
);
foreach ($cols as $c) {
    echo "  {$c['TABLE_NAME']}.{$c['COLUMN_NAME']} ({$c['DATA_TYPE']})\n";
}

echo "\nDone. Paste this output to wire the Ross report to production consumption.\n";
