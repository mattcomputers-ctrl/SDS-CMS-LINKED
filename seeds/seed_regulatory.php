<?php
/**
 * Seed regulatory data: California Prop 65 chemicals and IARC/NTP/OSHA carcinogen listings.
 *
 * Run: php seeds/seed_regulatory.php
 *
 * This seeds a representative subset of chemicals commonly found in
 * inks, coatings, and chemical manufacturing environments.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = new SDS\Core\App();
$db  = SDS\Core\Database::getInstance();

echo "Seeding regulatory data...\n";

// ── California Proposition 65 Chemicals ────────────────────────────

$prop65 = [
    // Common industrial chemicals found in inks/coatings
    ['50-00-0',    'Formaldehyde',                       'cancer',                                   null, null, '1988-01-01'],
    ['71-43-2',    'Benzene',                            'cancer,developmental,male reproductive',    null, null, '1987-02-27'],
    ['100-41-4',   'Ethylbenzene',                       'cancer',                                   null, null, '2004-06-11'],
    ['1330-20-7',  'Xylene (mixed isomers)',             'developmental',                            null, null, '1989-10-01'],
    ['108-88-3',   'Toluene',                            'developmental',                            null, null, '1991-01-01'],
    ['67-56-1',    'Methanol',                           'developmental',                            null, null, '2012-04-06'],
    ['110-54-3',   'n-Hexane',                           'male reproductive',                        null, null, '1999-12-01'],
    ['7440-43-9',  'Cadmium and cadmium compounds',      'cancer',                                   null, null, '1987-10-01'],
    ['7439-92-1',  'Lead and lead compounds',            'cancer,developmental,female reproductive,male reproductive', null, null, '1987-02-27'],
    ['18540-29-9', 'Chromium (hexavalent compounds)',    'cancer',                                   null, null, '1987-02-27'],
    ['7440-02-0',  'Nickel and nickel compounds',        'cancer',                                   null, null, '1987-10-01'],
    ['13463-67-7', 'Titanium dioxide (airborne, unbound particles)', 'cancer',                      null, null, '2011-09-02'],
    ['1333-86-4',  'Carbon black (airborne, unbound)',   'cancer',                                   null, null, '2003-02-21'],
    ['91-20-3',    'Naphthalene',                        'cancer',                                   null, null, '2004-04-19'],
    ['98-82-8',    'Cumene (Isopropylbenzene)',          'cancer',                                   null, null, '2015-11-13'],
    ['75-56-9',    'Propylene oxide',                    'cancer',                                   null, null, '1988-04-01'],
    ['106-99-0',   '1,3-Butadiene',                     'cancer,developmental,female reproductive,male reproductive', null, null, '1987-02-27'],
    ['107-13-1',   'Acrylonitrile',                     'cancer',                                   null, null, '1987-01-01'],
    ['75-21-8',    'Ethylene oxide',                     'cancer,developmental,male reproductive',    null, null, '1987-02-27'],
    ['1634-04-4',  'Methyl tert-butyl ether (MTBE)',    'cancer',                                   null, null, '1999-10-15'],
    ['79-06-1',    'Acrylamide',                         'cancer,developmental,male reproductive',    null, null, '1990-01-01'],
    ['96-09-3',    'Styrene oxide',                      'cancer',                                   null, null, '1990-02-01'],
    ['100-42-5',   'Styrene',                            'cancer',                                   null, null, '2016-04-22'],
    ['117-81-7',   'Di(2-ethylhexyl)phthalate (DEHP)',  'cancer,developmental,male reproductive',    null, null, '1988-01-01'],
    ['75-09-2',    'Methylene chloride (Dichloromethane)', 'cancer',                                 null, null, '1988-04-01'],
    ['111-76-2',   '2-Butoxyethanol',                   'developmental',                            null, null, '1993-01-01'],
    ['110-80-5',   '2-Ethoxyethanol',                   'developmental,male reproductive',           null, null, '1993-01-01'],
    ['109-86-4',   '2-Methoxyethanol',                  'developmental,male reproductive',           null, null, '1993-01-01'],
    ['123-91-1',   '1,4-Dioxane',                       'cancer',                                   null, null, '1988-01-01'],
    ['127-18-4',   'Tetrachloroethylene (Perchloroethylene)', 'cancer',                             null, null, '1988-04-01'],
];

$insertedP65 = 0;
foreach ($prop65 as $row) {
    $existing = $db->fetch("SELECT id FROM prop65_list WHERE cas_number = ?", [$row[0]]);
    if ($existing) continue;

    $db->insert('prop65_list', [
        'cas_number'        => $row[0],
        'chemical_name'     => $row[1],
        'toxicity_type'     => $row[2],
        'listing_mechanism' => 'State qualified experts',
        'nsrl_ug'           => $row[3],
        'madl_ug'           => $row[4],
        'date_listed'       => $row[5],
        'source_ref'        => 'OEHHA Proposition 65 List',
    ]);
    $insertedP65++;
}
echo "  Seeded {$insertedP65} Prop 65 chemicals\n";

// ── IARC / NTP / OSHA Carcinogen Listings ──────────────────────────

$carcinogens = [
    // IARC listings
    ['50-00-0',    'Formaldehyde',                'IARC', 'Group 1',  'Carcinogenic to humans. Causes nasopharyngeal cancer and leukemia.'],
    ['71-43-2',    'Benzene',                     'IARC', 'Group 1',  'Carcinogenic to humans. Causes acute myeloid leukemia.'],
    ['18540-29-9', 'Chromium (VI) compounds',     'IARC', 'Group 1',  'Carcinogenic to humans. Causes lung cancer.'],
    ['7440-02-0',  'Nickel compounds',            'IARC', 'Group 1',  'Carcinogenic to humans. Causes lung and nasal sinus cancer.'],
    ['75-21-8',    'Ethylene oxide',              'IARC', 'Group 1',  'Carcinogenic to humans. Sufficient evidence for lymphatic and hematopoietic cancers.'],
    ['1333-86-4',  'Carbon black',                'IARC', 'Group 2B', 'Possibly carcinogenic to humans. Limited evidence from epidemiological studies.'],
    ['13463-67-7', 'Titanium dioxide',            'IARC', 'Group 2B', 'Possibly carcinogenic to humans. Evidence of carcinogenicity in animal studies by inhalation.'],
    ['100-41-4',   'Ethylbenzene',                'IARC', 'Group 2B', 'Possibly carcinogenic to humans.'],
    ['91-20-3',    'Naphthalene',                 'IARC', 'Group 2B', 'Possibly carcinogenic to humans. Evidence of tumors in animals.'],
    ['100-42-5',   'Styrene',                     'IARC', 'Group 2A', 'Probably carcinogenic to humans.'],
    ['75-56-9',    'Propylene oxide',             'IARC', 'Group 2B', 'Possibly carcinogenic to humans.'],
    ['107-13-1',   'Acrylonitrile',               'IARC', 'Group 2B', 'Possibly carcinogenic to humans.'],
    ['79-06-1',    'Acrylamide',                  'IARC', 'Group 2A', 'Probably carcinogenic to humans.'],
    ['75-09-2',    'Methylene chloride',          'IARC', 'Group 2A', 'Probably carcinogenic to humans.'],
    ['123-91-1',   '1,4-Dioxane',                 'IARC', 'Group 2B', 'Possibly carcinogenic to humans.'],
    ['127-18-4',   'Tetrachloroethylene',         'IARC', 'Group 2A', 'Probably carcinogenic to humans.'],
    ['106-99-0',   '1,3-Butadiene',               'IARC', 'Group 1',  'Carcinogenic to humans. Causes hematolymphatic cancers.'],

    // NTP listings
    ['50-00-0',    'Formaldehyde',                'NTP',  'Known',    'Known to be a human carcinogen (14th RoC).'],
    ['71-43-2',    'Benzene',                     'NTP',  'Known',    'Known to be a human carcinogen (14th RoC).'],
    ['18540-29-9', 'Chromium (VI) compounds',     'NTP',  'Known',    'Known to be human carcinogens (14th RoC).'],
    ['7440-02-0',  'Nickel compounds',            'NTP',  'Known',    'Certain nickel compounds are known to be human carcinogens (14th RoC).'],
    ['75-21-8',    'Ethylene oxide',              'NTP',  'Known',    'Known to be a human carcinogen (14th RoC).'],
    ['106-99-0',   '1,3-Butadiene',               'NTP',  'Known',    'Known to be a human carcinogen (14th RoC).'],
    ['1333-86-4',  'Carbon black',                'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['100-41-4',   'Ethylbenzene',                'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['91-20-3',    'Naphthalene',                 'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['100-42-5',   'Styrene',                     'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['75-56-9',    'Propylene oxide',             'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['107-13-1',   'Acrylonitrile',               'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['79-06-1',    'Acrylamide',                  'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['75-09-2',    'Methylene chloride',          'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['123-91-1',   '1,4-Dioxane',                 'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['127-18-4',   'Tetrachloroethylene',         'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (14th RoC).'],
    ['13463-67-7', 'Titanium dioxide',            'NTP',  'RAHC',     'Reasonably anticipated to be a human carcinogen (15th RoC, 2021).'],

    // OSHA regulated carcinogens (29 CFR 1910.1003-1016)
    ['71-43-2',    'Benzene',                     'OSHA', 'Listed',   'Regulated carcinogen per 29 CFR 1910.1028.'],
    ['75-21-8',    'Ethylene oxide',              'OSHA', 'Listed',   'Regulated carcinogen per 29 CFR 1910.1047.'],
    ['50-00-0',    'Formaldehyde',                'OSHA', 'Listed',   'Regulated carcinogen per 29 CFR 1910.1048.'],
    ['107-13-1',   'Acrylonitrile',               'OSHA', 'Listed',   'Regulated carcinogen per 29 CFR 1910.1045.'],
    ['106-99-0',   '1,3-Butadiene',               'OSHA', 'Listed',   'Regulated carcinogen per 29 CFR 1910.1051.'],
    ['75-09-2',    'Methylene chloride',          'OSHA', 'Listed',   'Regulated carcinogen per 29 CFR 1910.1052.'],
];

$insertedCarc = 0;
foreach ($carcinogens as $row) {
    $existing = $db->fetch(
        "SELECT id FROM carcinogen_list WHERE cas_number = ? AND agency = ?",
        [$row[0], $row[2]]
    );
    if ($existing) continue;

    $db->insert('carcinogen_list', [
        'cas_number'     => $row[0],
        'chemical_name'  => $row[1],
        'agency'         => $row[2],
        'classification' => $row[3],
        'description'    => $row[4],
        'source_ref'     => $row[2] === 'IARC' ? 'IARC Monographs' : ($row[2] === 'NTP' ? '14th/15th Report on Carcinogens' : '29 CFR 1910'),
    ]);
    $insertedCarc++;
}
echo "  Seeded {$insertedCarc} carcinogen listings (IARC/NTP/OSHA)\n";

echo "\nRegulatory seed complete!\n";
