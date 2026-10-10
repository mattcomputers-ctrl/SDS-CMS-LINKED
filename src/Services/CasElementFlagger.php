<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * CasElementFlagger — audit #19. Infers the cas_master element flags
 * (has_nitrogen / has_sulfur / has_halogen) that drive the Section 10
 * "Hazardous decomposition products" sentence.
 *
 * Precedence: a parseable Hill molecular formula is authoritative; only
 * when there is none do the conservative chemical-name keywords apply.
 * Pure functions — used by scripts/seed-cas-element-flags.php, by the
 * cas_master auto-learn step in RawMaterial::saveConstituents(), and by
 * tests/Services/CasElementFlaggerTest.php.
 */
final class CasElementFlagger
{
    public const FLAGS = ['has_nitrogen', 'has_sulfur', 'has_halogen'];

    /** Every element symbol (so "Unspecified", "Mixture", "On" etc. are rejected, not parsed). */
    private const ELEMENT_SYMBOLS = 'H He Li Be B C N O F Ne Na Mg Al Si P S Cl Ar K Ca Sc Ti V Cr Mn Fe Co Ni Cu Zn Ga Ge As Se Br Kr Rb Sr Y Zr Nb Mo Tc Ru Rh Pd Ag Cd In Sn Sb Te I Xe Cs Ba La Ce Pr Nd Pm Sm Eu Gd Tb Dy Ho Er Tm Yb Lu Hf Ta W Re Os Ir Pt Au Hg Tl Pb Bi Po At Rn Fr Ra Ac Th Pa U Np Pu Am Cm Bk Cf Es Fm Md No Lr Rf Db Sg Bh Hs Mt Ds Rg Cn Nh Fl Mc Lv Ts Og';

    private const FORMULA_ELEMENTS = [
        'has_nitrogen' => ['N'],
        'has_sulfur'   => ['S'],
        'has_halogen'  => ['F', 'Cl', 'Br', 'I'],
    ];

    /**
     * Conservative lower-case substrings. Each one implies the element in
     * essentially every chemical name it occurs in; ambiguous stems (e.g.
     * "cyan" the colour, "pigment yellow") are deliberately absent.
     */
    private const NAME_KEYWORDS = [
        'has_nitrogen' => [
            'amine', 'amino', 'amide', 'imide', 'imine', 'nitro', 'nitrile', 'nitrate', 'nitrite',
            'cyanide', 'cyanate', 'cyano', 'isocyan', 'urea', 'urethane', 'carbamate', 'azo', 'azole',
            'azine', 'pyridine', 'pyrrol', 'piperidine', 'piperazine', 'morpholine', 'imidazol',
            'indole', 'quinoline', 'aniline', 'ammonium', 'ammonia', 'hydrazine', 'hydrazide',
            'lactam', 'nylon', 'polyamide', 'melamine', 'benzotriazole', 'phthalocyanine',
            'carbazole', 'quinacridone', 'dioxazine', 'diketopyrrolo', 'isoindolin', 'benzimidazolone',
            'ethanolamine', 'toluidine', 'xylidine', 'naphthylamine', 'benzidine', 'guanidine', 'oxime',
        ],
        'has_sulfur' => [
            'sulf', 'sulph', 'thio', 'thia', 'mercapt', 'sulfonate', 'thiuram', 'lithopone',
        ],
        'has_halogen' => [
            'fluor', 'chlor', 'brom', 'iod', 'halogen', 'perfluor', 'ptfe', 'tetrafluoroethylene',
        ],
    ];

    /** Substrings removed from a name before keyword matching (known false positives). */
    private const NAME_EXCLUSIONS = [
        'fluoren', 'fluoranth', 'fluoresc',   // fluorene, fluoranthene, fluorescein — no F
        'chlorophyll', 'bromelain',           // no Cl / no Br
        'laminat',                            // never matches "amine" anyway; kept for clarity
    ];

    /**
     * @return array{has_nitrogen:bool,has_sulfur:bool,has_halogen:bool}|null
     *         null when the formula is empty or not a parseable Hill formula.
     */
    public static function fromFormula(?string $formula): ?array
    {
        $formula = trim((string) $formula);
        if ($formula === '') {
            return null;
        }
        // "(C2H4O)n" / "(C3H6O)x" — drop the repeat-unit suffix, then every
        // non-letter a Hill formula may carry.
        $core = preg_replace('/\)\s*[nx]\b/', ')', $formula);
        $core = preg_replace('/[^A-Za-z]/', '', (string) $core);
        if ($core === '' || !preg_match('/^(?:[A-Z][a-z]?)+$/', $core)) {
            return null;
        }
        preg_match_all('/[A-Z][a-z]?/', $core, $m);
        $known = array_flip(explode(' ', self::ELEMENT_SYMBOLS));
        foreach ($m[0] as $sym) {
            if (!isset($known[$sym])) {
                return null;
            }
        }
        $set = array_flip($m[0]);
        $out = [];
        foreach (self::FORMULA_ELEMENTS as $flag => $symbols) {
            $out[$flag] = false;
            foreach ($symbols as $sym) {
                if (isset($set[$sym])) {
                    $out[$flag] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * @param  string[] $names  preferred name, synonyms, constituent / list names
     * @return array{has_nitrogen:bool,has_sulfur:bool,has_halogen:bool}
     */
    public static function fromNames(array $names): array
    {
        $matched = self::matchedKeywords($names);
        $out = [];
        foreach (self::FLAGS as $flag) {
            $out[$flag] = !empty($matched[$flag]);
        }
        return $out;
    }

    /**
     * Which keywords fired, per flag — for the seed script's dry-run report.
     *
     * @param  string[] $names
     * @return array<string, string[]>  flag => distinct keywords matched
     */
    public static function matchedKeywords(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $n = mb_strtolower(trim((string) $name));
            if ($n === '') {
                continue;
            }
            $n = str_replace(self::NAME_EXCLUSIONS, '', $n);
            foreach (self::NAME_KEYWORDS as $flag => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($n, $kw)) {
                        $out[$flag][$kw] = true;
                    }
                }
            }
        }
        foreach ($out as $flag => $kws) {
            $out[$flag] = array_keys($kws);
        }
        return $out;
    }

    /**
     * Formula wins when parseable; names otherwise.
     *
     * @param  string[] $names
     * @return array{has_nitrogen:bool,has_sulfur:bool,has_halogen:bool}
     */
    public static function infer(?string $formula, array $names): array
    {
        return self::fromFormula($formula) ?? self::fromNames($names);
    }

    /* ------------------------------------------------------------------
     *  Audit #12 — RCRA toxicity-characteristic metals (40 CFR 261.24)
     *  stored in cas_master.tc_metals; RCRAService gives a compound the
     *  D004–D011 rows of the elements it contains.
     * ----------------------------------------------------------------*/

    /** The eight TC metals, in storage / print order. */
    public const TC_METALS = ['As', 'Ba', 'Cd', 'Cr', 'Pb', 'Hg', 'Se', 'Ag'];

    /**
     * Conservative name patterns per TC metal, matched (PCRE) on the
     * lower-cased name after TC_METAL_NAME_EXCLUSIONS are removed. Colour
     * Index names cover pigments whose names do not mention the metal;
     * (?![\d:]) stops "pigment white 1" matching "pigment white 18".
     */
    private const TC_METAL_NAME_PATTERNS = [
        'As' => ['/arsen/'],
        'Ba' => ['/barium/', '/\bbaryt/', '/\bbarite\b/', '/blanc fixe/', '/lithopone/',
                 '/pigment red 48:1(?![\d:])/', '/pigment red 49:1(?![\d:])/', '/pigment red 53:1(?![\d:])/',
                 '/pigment white 21(?![\d:])/', '/pigment white 22(?![\d:])/'],
        'Cd' => ['/cadmium/', '/pigment yellow 35(?![\d:])/', '/pigment yellow 37(?![\d:])/',
                 '/pigment orange 20(?![\d:])/', '/pigment red 108(?![\d:])/'],
        'Cr' => ['/chromium/', '/chromate/', '/\bchromic\b/', '/\bchromous\b/', '/\bchrome\b/', '/molybdate (?:orange|red)/',
                 '/pigment yellow 34(?![\d:])/', '/pigment red 104(?![\d:])/', '/pigment green 17(?![\d:])/',
                 '/pigment green 18(?![\d:])/', '/pigment brown 24(?![\d:])/', '/pigment blue 36(?![\d:])/'],
        'Pb' => ['/\blead\b/', '/plumb/', '/litharge/', '/chrome (?:yellow|orange)/', '/molybdate (?:orange|red)/',
                 '/pigment yellow 34(?![\d:])/', '/pigment red 104(?![\d:])/', '/pigment red 105(?![\d:])/',
                 '/pigment white 1(?![\d:])/'],
        'Hg' => ['/mercur/', '/cinnabar/', '/calomel/'],
        'Se' => ['/selen/', '/pigment orange 20(?![\d:])/', '/pigment red 108(?![\d:])/'],
        'Ag' => ['/silver/', '/argent/'],
    ];

    /** Removed before TC-metal matching (known false positives). */
    private const TC_METAL_NAME_EXCLUSIONS = ['/lead[- ]?free/', '/plumbago/'];

    /**
     * Element symbols of a parseable Hill formula (same parse rules as
     * fromFormula()), or null when the formula is empty / not parseable.
     *
     * @return array<string, true>|null
     */
    private static function formulaSymbolSet(?string $formula): ?array
    {
        $formula = trim((string) $formula);
        if ($formula === '') {
            return null;
        }
        $core = preg_replace('/\)\s*[nx]\b/', ')', $formula);
        $core = preg_replace('/[^A-Za-z]/', '', (string) $core);
        if ($core === '' || !preg_match('/^(?:[A-Z][a-z]?)+$/', $core)) {
            return null;
        }
        preg_match_all('/[A-Z][a-z]?/', $core, $m);
        $known = array_flip(explode(' ', self::ELEMENT_SYMBOLS));
        foreach ($m[0] as $sym) {
            if (!isset($known[$sym])) {
                return null;
            }
        }
        return array_fill_keys($m[0], true);
    }

    /**
     * Normalise a stored / typed list ("pb, CR;hg") to TC_METALS order;
     * unknown tokens are dropped.
     *
     * @return string[]
     */
    public static function parseTcMetals(?string $csv): array
    {
        $set = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $csv)) ?: [] as $tok) {
            if ($tok !== '') {
                $set[ucfirst(strtolower($tok))] = true;
            }
        }
        return array_values(array_filter(self::TC_METALS, static fn (string $s): bool => isset($set[$s])));
    }

    /** @return string[]|null  TC metals in a parseable formula (TC_METALS order); null = not parseable */
    public static function tcMetalsFromFormula(?string $formula): ?array
    {
        $set = self::formulaSymbolSet($formula);
        if ($set === null) {
            return null;
        }
        return array_values(array_filter(self::TC_METALS, static fn (string $s): bool => isset($set[$s])));
    }

    /**
     * @param  string[] $names
     * @return array<string, string[]>  symbol => patterns that fired (TC_METALS order)
     */
    public static function matchedTcMetalKeywords(array $names): array
    {
        $hit = [];
        foreach ($names as $name) {
            $n = mb_strtolower(trim((string) $name));
            if ($n === '') {
                continue;
            }
            $n = (string) preg_replace(self::TC_METAL_NAME_EXCLUSIONS, '', $n);
            foreach (self::TC_METAL_NAME_PATTERNS as $sym => $patterns) {
                foreach ($patterns as $re) {
                    if (preg_match($re, $n) === 1) {
                        $hit[$sym][trim($re, '/')] = true;
                    }
                }
            }
        }
        $out = [];
        foreach (self::TC_METALS as $sym) {
            if (isset($hit[$sym])) {
                $out[$sym] = array_keys($hit[$sym]);
            }
        }
        return $out;
    }

    /** @param string[] $names  @return string[] */
    public static function tcMetalsFromNames(array $names): array
    {
        return array_keys(self::matchedTcMetalKeywords($names));
    }

    /** Formula wins when parseable; names otherwise. @param string[] $names @return string[] */
    public static function inferTcMetals(?string $formula, array $names): array
    {
        return self::tcMetalsFromFormula($formula) ?? self::tcMetalsFromNames($names);
    }
}
