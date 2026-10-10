#!/usr/bin/env php
<?php
/**
 * TemperatureParser — Section 9 Flash Point / Initial Boiling Point edits
 * (findings #8 and #44(2)): degree sign optional, unit required, explicit
 * °C wins, ">" / "<" flags, and the save-time plan filter.
 *
 * Run:  php tests/Services/TemperatureParserTest.php
 * Exit: 0 = all cases passed, 1 = one or more failed
 */

declare(strict_types=1);

error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\TemperatureParser as TP;

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

$near = static fn (?array $p, float $c): bool => $p !== null && abs($p['c'] - $c) < 0.01;

echo "1. Valid temperatures\n";
$valid = [
    ['24 °C', 24.0, false, false],
    ['24°C', 24.0, false, false],
    ['24 C', 24.0, false, false],
    ['24c', 24.0, false, false],
    ['75 F', 23.89, false, false],
    ['75F', 23.89, false, false],
    ['75 °F (24 °C)', 24.0, false, false],
    ['24 °C (75 °F)', 24.0, false, false],
    ['> 93 °C', 93.0, true, false],
    ['> 200°F (93°C)', 93.0, true, false],
    ['200 deg F', 93.33, false, false],
    ['23,5 °C', 23.5, false, false],
    ['-4 °C', -4.0, false, false],
    ["\u{2212}4 °C", -4.0, false, false],
    ['< 23 °C', 23.0, false, true],
    ['≥ 60 °C', 60.0, true, false],
    ['61 Celsius', 61.0, false, false],
    ['150 Fahrenheit', 65.56, false, false],
    ['ASTM D93: 24 C', 24.0, false, false],
];
foreach ($valid as [$text, $c, $gt, $lt]) {
    $p = TP::parse($text);
    check($near($p, $c) && $p['gt'] === $gt && $p['lt'] === $lt, sprintf('"%s" → %s °C%s%s', $text, $c, $gt ? ' gt' : '', $lt ? ' lt' : ''), $p);
}

echo "2. Not a temperature → null\n";
foreach (['', '   ', 'Not determined', 'None — water based', '75', '24 cP', 'ASTM D93', 'Tag closed cup 24', null] as $text) {
    $p = TP::parse($text);
    check($p === null, sprintf('%s → null', var_export($text, true)), $p);
}

echo "3. thresholdValue\n";
$lt = TP::thresholdValue(TP::parse('< 23 °C'));
check($lt > 22.9 && $lt < 23.0, '"< 23 °C" classifies just below 23', $lt);
check(TP::thresholdValue(TP::parse('> 93 °C')) === 93.0, '"> 93 °C" classifies at 93', TP::thresholdValue(TP::parse('> 93 °C')));

echo "4. filterPlan\n";
$plan = [
    'upsert' => [
        ['section' => 9, 'key' => 'flash_point', 'text' => 'None'],
        ['section' => 9, 'key' => 'boiling_point', 'text' => '30 °C'],
        ['section' => 9, 'key' => 'odor', 'text' => 'Mild'],
        ['section' => 5, 'key' => 'specific_hazards', 'text' => 'No number here'],
    ],
    'delete' => [['section' => 9, 'key' => 'solubility']],
    'counts' => ['stored' => 4, 'unchanged' => 0, 'removed' => 1, 'auto' => 0, 'ignored' => 0],
];
$res = TP::filterPlan($plan);
check($res['rejected'] === [['section' => 9, 'key' => 'flash_point', 'text' => 'None']], 'flash point "None" rejected', $res['rejected']);
check(array_column($res['plan']['upsert'], 'key') === ['boiling_point', 'odor', 'specific_hazards'], 'other upserts kept in order', array_column($res['plan']['upsert'], 'key'));
check($res['plan']['delete'] === $plan['delete'], 'delete untouched', $res['plan']['delete']);
check($res['plan']['counts']['stored'] === 3 && $res['plan']['counts']['rejected'] === 1 && $res['plan']['counts']['removed'] === 1, 'counts stored 3, rejected 1', $res['plan']['counts']);
$empty = TP::filterPlan(['upsert' => [], 'delete' => [], 'counts' => ['stored' => 0, 'unchanged' => 0, 'removed' => 0, 'auto' => 0, 'ignored' => 0]]);
check($empty['rejected'] === [] && $empty['plan']['counts']['rejected'] === 0, 'empty plan: nothing rejected', $empty);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
