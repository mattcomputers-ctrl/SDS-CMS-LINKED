<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * Admin settings that print on every SDS (audit #58). Published SDSs are frozen
 * PDFs, so changing one of these does not reissue them; AdminController::saveSettings
 * offers "Bump ALL unblocked SDSs" when one changes. DB-free.
 */
final class SheetContentSettings
{
    /** Exact keys, with the value a missing row means. */
    public const KEYS = [
        'sds.show_ghs_section_note' => '1',
        'uv_acrylate_rule_pack'     => 'enabled',
    ];

    /** Prefixes: Section 1 supplier block + logo (company.*), Section 16 disclaimer per language. */
    public const PREFIXES = ['company.', 'sds.legal_disclaimer.'];

    public static function isSheetContent(string $key): bool
    {
        if (array_key_exists($key, self::KEYS)) {
            return true;
        }
        foreach (self::PREFIXES as $p) {
            if (str_starts_with($key, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array<string,string> $before settings key => value before the save
     * @param  array<string,string> $after  settings key => value after the save
     * @return string[] sorted keys that print on the sheet and whose trimmed value changed
     */
    public static function changedKeys(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $key = (string) $key;
            if (!self::isSheetContent($key)) {
                continue;
            }
            $default = self::KEYS[$key] ?? '';
            if (trim((string) ($before[$key] ?? $default)) !== trim((string) ($after[$key] ?? $default))) {
                $changed[] = $key;
            }
        }
        sort($changed);
        return $changed;
    }
}
