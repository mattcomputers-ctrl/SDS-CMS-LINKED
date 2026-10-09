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
}
