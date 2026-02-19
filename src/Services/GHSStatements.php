<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * GHSStatements — Standard H-statement and P-statement text lookup.
 *
 * Provides the official GHS Rev.9 text for hazard (H) and precautionary (P)
 * statement codes. Used to fill in missing text when federal data sources
 * only provide codes without descriptions.
 */
class GHSStatements
{
    /**
     * GHS Hazard Statement texts (H-codes).
     * Source: UN GHS Rev.9, OSHA 29 CFR 1910.1200 Appendix C
     */
    private const H_STATEMENTS = [
        // Physical hazards (H200 series)
        'H200' => 'Unstable explosive',
        'H201' => 'Explosive; mass explosion hazard',
        'H202' => 'Explosive; severe projection hazard',
        'H203' => 'Explosive; fire, blast or projection hazard',
        'H204' => 'Fire or projection hazard',
        'H205' => 'May mass explode in fire',
        'H220' => 'Extremely flammable gas',
        'H221' => 'Flammable gas',
        'H222' => 'Extremely flammable aerosol',
        'H223' => 'Flammable aerosol',
        'H224' => 'Extremely flammable liquid and vapour',
        'H225' => 'Highly flammable liquid and vapour',
        'H226' => 'Flammable liquid and vapour',
        'H227' => 'Combustible liquid',
        'H228' => 'Flammable solid',
        'H229' => 'Pressurized container: may burst if heated',
        'H230' => 'May react explosively even in the absence of air',
        'H231' => 'May react explosively even in the absence of air at elevated pressure and/or temperature',
        'H240' => 'Heating may cause an explosion',
        'H241' => 'Heating may cause a fire or explosion',
        'H242' => 'Heating may cause a fire',
        'H250' => 'Catches fire spontaneously if exposed to air',
        'H251' => 'Self-heating; may catch fire',
        'H252' => 'Self-heating in large quantities; may catch fire',
        'H260' => 'In contact with water releases flammable gases which may ignite spontaneously',
        'H261' => 'In contact with water releases flammable gas',
        'H270' => 'May cause or intensify fire; oxidizer',
        'H271' => 'May cause fire or explosion; strong oxidizer',
        'H272' => 'May intensify fire; oxidizer',
        'H280' => 'Contains gas under pressure; may explode if heated',
        'H281' => 'Contains refrigerated gas; may cause cryogenic burns or injury',
        'H290' => 'May be corrosive to metals',

        // Health hazards (H300 series)
        'H300' => 'Fatal if swallowed',
        'H301' => 'Toxic if swallowed',
        'H302' => 'Harmful if swallowed',
        'H303' => 'May be harmful if swallowed',
        'H304' => 'May be fatal if swallowed and enters airways',
        'H305' => 'May be harmful if swallowed and enters airways',
        'H310' => 'Fatal in contact with skin',
        'H311' => 'Toxic in contact with skin',
        'H312' => 'Harmful in contact with skin',
        'H313' => 'May be harmful in contact with skin',
        'H314' => 'Causes severe skin burns and eye damage',
        'H315' => 'Causes skin irritation',
        'H316' => 'Causes mild skin irritation',
        'H317' => 'May cause an allergic skin reaction',
        'H318' => 'Causes serious eye damage',
        'H319' => 'Causes serious eye irritation',
        'H320' => 'Causes eye irritation',
        'H330' => 'Fatal if inhaled',
        'H331' => 'Toxic if inhaled',
        'H332' => 'Harmful if inhaled',
        'H333' => 'May be harmful if inhaled',
        'H334' => 'May cause allergy or asthma symptoms or breathing difficulties if inhaled',
        'H335' => 'May cause respiratory irritation',
        'H336' => 'May cause drowsiness or dizziness',
        'H340' => 'May cause genetic defects',
        'H341' => 'Suspected of causing genetic defects',
        'H350' => 'May cause cancer',
        'H351' => 'Suspected of causing cancer',
        'H360' => 'May damage fertility or the unborn child',
        'H361' => 'Suspected of damaging fertility or the unborn child',
        'H362' => 'May cause harm to breast-fed children',
        'H370' => 'Causes damage to organs',
        'H371' => 'May cause damage to organs',
        'H372' => 'Causes damage to organs through prolonged or repeated exposure',
        'H373' => 'May cause damage to organs through prolonged or repeated exposure',

        // Combined H-statements
        'H300+H310'      => 'Fatal if swallowed or in contact with skin',
        'H300+H330'      => 'Fatal if swallowed or if inhaled',
        'H310+H330'      => 'Fatal in contact with skin or if inhaled',
        'H300+H310+H330' => 'Fatal if swallowed, in contact with skin or if inhaled',
        'H301+H311'      => 'Toxic if swallowed or in contact with skin',
        'H301+H331'      => 'Toxic if swallowed or if inhaled',
        'H311+H331'      => 'Toxic in contact with skin or if inhaled',
        'H301+H311+H331' => 'Toxic if swallowed, in contact with skin or if inhaled',
        'H302+H312'      => 'Harmful if swallowed or in contact with skin',
        'H302+H332'      => 'Harmful if swallowed or if inhaled',
        'H312+H332'      => 'Harmful in contact with skin or if inhaled',
        'H302+H312+H332' => 'Harmful if swallowed, in contact with skin or if inhaled',

        // Environmental hazards (H400 series)
        'H400' => 'Very toxic to aquatic life',
        'H401' => 'Toxic to aquatic life',
        'H402' => 'Harmful to aquatic life',
        'H410' => 'Very toxic to aquatic life with long lasting effects',
        'H411' => 'Toxic to aquatic life with long lasting effects',
        'H412' => 'Harmful to aquatic life with long lasting effects',
        'H413' => 'May cause long lasting harmful effects to aquatic life',
        'H420' => 'Harms public health and the environment by destroying ozone in the upper atmosphere',
    ];

    /**
     * GHS Precautionary Statement texts (P-codes).
     */
    private const P_STATEMENTS = [
        // General (P100 series)
        'P101' => 'If medical advice is needed, have product container or label at hand',
        'P102' => 'Keep out of reach of children',
        'P103' => 'Read label before use',

        // Prevention (P200 series)
        'P201' => 'Obtain special instructions before use',
        'P202' => 'Do not handle until all safety precautions have been read and understood',
        'P210' => 'Keep away from heat, hot surfaces, sparks, open flames and other ignition sources. No smoking',
        'P211' => 'Do not spray on an open flame or other ignition source',
        'P220' => 'Keep away from clothing and other combustible materials',
        'P221' => 'Take any precaution to avoid mixing with combustibles',
        'P222' => 'Do not allow contact with air',
        'P223' => 'Do not allow contact with water',
        'P230' => 'Keep wetted with...',
        'P231' => 'Handle and store contents under inert gas',
        'P232' => 'Protect from moisture',
        'P233' => 'Keep container tightly closed',
        'P234' => 'Keep only in original container',
        'P235' => 'Keep cool',
        'P240' => 'Ground and bond container and receiving equipment',
        'P241' => 'Use explosion-proof electrical/ventilating/lighting equipment',
        'P242' => 'Use non-sparking tools',
        'P243' => 'Take action to prevent static discharges',
        'P244' => 'Keep valves and fittings free from oil and grease',
        'P250' => 'Do not subject to grinding/shock/friction',
        'P251' => 'Do not pierce or burn, even after use',
        'P260' => 'Do not breathe dust/fume/gas/mist/vapours/spray',
        'P261' => 'Avoid breathing dust/fume/gas/mist/vapours/spray',
        'P262' => 'Do not get in eyes, on skin, or on clothing',
        'P263' => 'Avoid contact during pregnancy and while nursing',
        'P264' => 'Wash hands thoroughly after handling',
        'P270' => 'Do not eat, drink or smoke when using this product',
        'P271' => 'Use only outdoors or in a well-ventilated area',
        'P272' => 'Contaminated work clothing should not be allowed out of the workplace',
        'P273' => 'Avoid release to the environment',
        'P280' => 'Wear protective gloves/protective clothing/eye protection/face protection',
        'P281' => 'Use personal protective equipment as required',
        'P282' => 'Wear cold insulating gloves and either face shield or eye protection',
        'P283' => 'Wear fire resistant or flame retardant clothing',
        'P284' => 'Wear respiratory protection',
        'P285' => 'In case of inadequate ventilation wear respiratory protection',

        // Response (P300 series)
        'P301' => 'IF SWALLOWED:',
        'P302' => 'IF ON SKIN:',
        'P303' => 'IF ON SKIN (or hair):',
        'P304' => 'IF INHALED:',
        'P305' => 'IF IN EYES:',
        'P306' => 'IF ON CLOTHING:',
        'P308' => 'IF exposed or concerned:',
        'P310' => 'Immediately call a POISON CENTER/doctor',
        'P311' => 'Call a POISON CENTER/doctor',
        'P312' => 'Call a POISON CENTER/doctor if you feel unwell',
        'P313' => 'Get medical advice/attention',
        'P314' => 'Get medical advice/attention if you feel unwell',
        'P315' => 'Get immediate medical advice/attention',
        'P320' => 'Specific treatment is urgent (see supplemental first aid instructions on this label)',
        'P321' => 'Specific treatment (see supplemental first aid instructions on this label)',
        'P330' => 'Rinse mouth',
        'P331' => 'Do NOT induce vomiting',
        'P332' => 'If skin irritation occurs:',
        'P333' => 'If skin irritation or rash occurs:',
        'P334' => 'Immerse in cool water or wrap in wet bandages',
        'P335' => 'Brush off loose particles from skin',
        'P336' => 'Thaw frosted parts with lukewarm water. Do not rub affected area',
        'P337' => 'If eye irritation persists:',
        'P338' => 'Remove contact lenses, if present and easy to do. Continue rinsing',
        'P340' => 'Remove person to fresh air and keep comfortable for breathing',
        'P341' => 'If breathing is difficult, remove person to fresh air and keep comfortable for breathing',
        'P342' => 'If experiencing respiratory symptoms:',
        'P350' => 'Gently wash with plenty of soap and water',
        'P351' => 'Rinse cautiously with water for several minutes',
        'P352' => 'Wash with plenty of water',
        'P353' => 'Rinse skin with water or shower',
        'P360' => 'Rinse immediately contaminated clothing and skin with plenty of water before removing clothing',
        'P361' => 'Take off immediately all contaminated clothing',
        'P362' => 'Take off contaminated clothing',
        'P363' => 'Wash contaminated clothing before reuse',
        'P364' => 'And wash it before reuse',
        'P370' => 'In case of fire:',
        'P371' => 'In case of major fire and large quantities:',
        'P372' => 'Explosion risk',
        'P373' => 'DO NOT fight fire when fire reaches explosives',
        'P375' => 'Fight fire remotely due to the risk of explosion',
        'P376' => 'Stop leak if safe to do so',
        'P377' => 'Leaking gas fire: Do not extinguish, unless leak can be stopped safely',
        'P378' => 'Use dry sand, dry chemical or alcohol-resistant foam to extinguish',
        'P380' => 'Evacuate area',
        'P381' => 'In case of leakage, eliminate all ignition sources',
        'P390' => 'Absorb spillage to prevent material damage',
        'P391' => 'Collect spillage',

        // Combined response statements
        'P301+P310' => 'IF SWALLOWED: Immediately call a POISON CENTER/doctor',
        'P301+P312' => 'IF SWALLOWED: Call a POISON CENTER/doctor if you feel unwell',
        'P301+P330+P331' => 'IF SWALLOWED: Rinse mouth. Do NOT induce vomiting',
        'P302+P334' => 'IF ON SKIN: Immerse in cool water or wrap in wet bandages',
        'P302+P350' => 'IF ON SKIN: Gently wash with plenty of soap and water',
        'P302+P352' => 'IF ON SKIN: Wash with plenty of water',
        'P303+P361+P353' => 'IF ON SKIN (or hair): Take off immediately all contaminated clothing. Rinse skin with water/shower',
        'P304+P312' => 'IF INHALED: Call a POISON CENTER/doctor if you feel unwell',
        'P304+P340' => 'IF INHALED: Remove person to fresh air and keep comfortable for breathing',
        'P304+P341' => 'IF INHALED: If breathing is difficult, remove person to fresh air and keep comfortable for breathing',
        'P305+P351+P338' => 'IF IN EYES: Rinse cautiously with water for several minutes. Remove contact lenses, if present and easy to do. Continue rinsing',
        'P306+P360' => 'IF ON CLOTHING: Rinse immediately contaminated clothing and skin with plenty of water before removing clothing',
        'P308+P311' => 'IF exposed or concerned: Call a POISON CENTER/doctor',
        'P308+P313' => 'IF exposed or concerned: Get medical advice/attention',
        'P332+P313' => 'If skin irritation occurs: Get medical advice/attention',
        'P333+P313' => 'If skin irritation or rash occurs: Get medical advice/attention',
        'P335+P334' => 'Brush off loose particles from skin. Immerse in cool water or wrap in wet bandages',
        'P337+P313' => 'If eye irritation persists: Get medical advice/attention',
        'P342+P311' => 'If experiencing respiratory symptoms: Call a POISON CENTER/doctor',
        'P370+P376' => 'In case of fire: Stop leak if safe to do so',
        'P370+P378' => 'In case of fire: Use dry sand, dry chemical or alcohol-resistant foam to extinguish',
        'P370+P380' => 'In case of fire: Evacuate area',
        'P370+P380+P375' => 'In case of fire: Evacuate area. Fight fire remotely due to the risk of explosion',
        'P371+P380+P375' => 'In case of major fire and large quantities: Evacuate area. Fight fire remotely due to the risk of explosion',

        // Storage (P400 series)
        'P401' => 'Store in accordance with local/regional/national/international regulations',
        'P402' => 'Store in a dry place',
        'P403' => 'Store in a well-ventilated place',
        'P404' => 'Store in a closed container',
        'P405' => 'Store locked up',
        'P406' => 'Store in corrosive resistant container with a resistant inner liner',
        'P407' => 'Maintain air gap between stacks or pallets',
        'P410' => 'Protect from sunlight',
        'P411' => 'Store at temperatures not exceeding the specified value',
        'P412' => 'Do not expose to temperatures exceeding 50°C/122°F',
        'P413' => 'Store bulk masses greater than the specified value at temperatures not exceeding the specified value',
        'P420' => 'Store separately',
        'P422' => 'Store contents under inert gas',
        'P402+P404' => 'Store in a dry place. Store in a closed container',
        'P403+P233' => 'Store in a well-ventilated place. Keep container tightly closed',
        'P403+P235' => 'Store in a well-ventilated place. Keep cool',
        'P410+P403' => 'Protect from sunlight. Store in a well-ventilated place',
        'P410+P412' => 'Protect from sunlight. Do not expose to temperatures exceeding 50°C/122°F',

        // Disposal (P500 series)
        'P501' => 'Dispose of contents/container in accordance with local/regional/national/international regulations',
        'P502' => 'Refer to manufacturer or supplier for information on recovery or recycling',
    ];

    /**
     * Pictogram code to description mapping.
     */
    private const PICTOGRAM_NAMES = [
        'GHS01' => 'Exploding Bomb',
        'GHS02' => 'Flame',
        'GHS03' => 'Flame Over Circle',
        'GHS04' => 'Gas Cylinder',
        'GHS05' => 'Corrosion',
        'GHS06' => 'Skull and Crossbones',
        'GHS07' => 'Exclamation Mark',
        'GHS08' => 'Health Hazard',
        'GHS09' => 'Environment',
    ];

    /**
     * Look up the text for an H-statement code.
     */
    public static function hText(string $code): string
    {
        $code = strtoupper(trim($code));
        return self::H_STATEMENTS[$code] ?? '';
    }

    /**
     * Look up the text for a P-statement code.
     */
    public static function pText(string $code): string
    {
        $code = strtoupper(trim($code));
        return self::P_STATEMENTS[$code] ?? '';
    }

    /**
     * Get the human-readable name for a pictogram code.
     */
    public static function pictogramName(string $code): string
    {
        $code = strtoupper(trim($code));
        return self::PICTOGRAM_NAMES[$code] ?? $code;
    }

    /**
     * Resolve an array of H-statements, filling in missing text from the standard.
     *
     * @param  array $statements  [['code' => 'H225', 'text' => ''], ...]
     * @return array              With text filled in from standard where empty
     */
    public static function resolveHStatements(array $statements): array
    {
        foreach ($statements as &$stmt) {
            $code = $stmt['code'] ?? '';
            if ($code !== '' && (($stmt['text'] ?? '') === '')) {
                $stmt['text'] = self::hText($code);
            }
        }
        return $statements;
    }

    /**
     * Resolve an array of P-statements, filling in missing text from the standard.
     *
     * @param  array $statements  [['code' => 'P210', 'text' => ''], ...]
     * @return array              With text filled in from standard where empty
     */
    public static function resolvePStatements(array $statements): array
    {
        foreach ($statements as &$stmt) {
            $code = $stmt['code'] ?? '';
            if ($code !== '' && (($stmt['text'] ?? '') === '')) {
                $stmt['text'] = self::pText($code);
            }
        }
        return $statements;
    }

    /**
     * Get all H-statement entries.
     */
    public static function allHStatements(): array
    {
        return self::H_STATEMENTS;
    }

    /**
     * Get all P-statement entries.
     */
    public static function allPStatements(): array
    {
        return self::P_STATEMENTS;
    }
}
