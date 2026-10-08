<?php
/**
 * English (EN) translations for SDS sections.
 */
return [
    'section1' => [
        'title'           => 'Identification',
        'recommended_use' => 'Printing ink for commercial and industrial applications.',
        'restrictions'    => 'For professional/industrial use only. Not for household consumer use.',
    ],
    'section2' => [
        'title'          => 'Hazard(s) Identification',
        'other_hazards'  => 'None known.',
        'not_classified' => 'Not a hazardous substance or mixture.',
    ],
    'section3' => [
        'title'            => 'Composition / Information on Ingredients',
        'trade_secret_note' => 'Specific chemical identity and/or exact percentage of composition has been withheld as a trade secret in accordance with 29 CFR 1910.1200(i).',
    ],
    'section4' => [
        'title'      => 'First-Aid Measures',
        'inhalation' => 'Move to fresh air. If breathing difficulty persists, seek medical attention.',
        'skin'       => 'Remove contaminated clothing. Wash skin thoroughly with soap and water. If irritation persists, seek medical attention.',
        'eyes'       => 'Flush eyes with large amounts of water for at least 15 minutes, lifting upper and lower lids. Seek medical attention if irritation persists.',
        'ingestion'  => 'Do not induce vomiting. Rinse mouth with water. Seek medical attention if symptoms develop.',
        'notes'      => 'Treat symptomatically. Show this SDS to medical personnel.',
        // Smart-logic fragments. "Severe" fragments (fatal/toxic, corrosive, serious eye
        // damage, aspiration, skin_toxic) REPLACE the base paragraph; all other fragments
        // are APPENDED to the base paragraph when the matching H-code is present.
        'inhalation_fatal'     => 'IMMEDIATELY remove person to fresh air. If not breathing, give artificial respiration. Call a poison center or physician immediately.',
        'inhalation_toxic'     => 'Remove to fresh air immediately. If breathing is difficult, give oxygen. Seek immediate medical attention.',
        'skin_corrosive'       => 'Immediately flush skin with copious amounts of water for at least 20 minutes. Remove all contaminated clothing and shoes. Seek immediate medical attention. Wash contaminated clothing before reuse.',
        'skin_sensitizer'      => 'May cause an allergic skin reaction. If skin irritation or a rash occurs, get medical advice/attention. Avoid further exposure.',
        'eyes_corrosive'       => 'Immediately flush eyes with copious amounts of water for at least 30 minutes, lifting upper and lower lids. Seek immediate medical attention. Do not allow victim to rub eyes.',
        'eyes_serious_damage'  => 'Immediately flush eyes with copious amounts of water for at least 20 minutes, lifting upper and lower lids. Seek immediate medical attention.',
        'ingestion_aspiration' => 'Do NOT induce vomiting — aspiration hazard. If vomiting occurs naturally, keep head below hips to prevent aspiration. Seek immediate medical attention.',
        'ingestion_toxic'      => 'Rinse mouth with water. Do not induce vomiting unless directed by medical personnel. Call a poison center or physician immediately.',
        'inhalation_harmful'         => 'Harmful if inhaled. Call a poison center or physician if you feel unwell.',
        'inhalation_resp_sensitizer' => 'May cause allergy or asthma symptoms or breathing difficulties if inhaled. If experiencing respiratory symptoms, call a poison center or physician.',
        'inhalation_irritant'        => 'May cause respiratory irritation. Keep the person at rest in a position comfortable for breathing.',
        'inhalation_narcotic'        => 'Vapors may cause drowsiness or dizziness. Keep the person under observation; if symptoms persist, call a poison center or physician.',
        'skin_toxic'                 => 'Take off immediately all contaminated clothing. Gently wash with plenty of soap and water. Get emergency medical help immediately. Wash contaminated clothing before reuse.',
        'skin_harmful'               => 'Harmful in contact with skin. Call a poison center or physician if you feel unwell.',
        'skin_irritant'              => 'Wash contaminated clothing before reuse.',
        'eyes_contact_lenses'        => 'Remove contact lenses, if present and easy to do, and continue rinsing.',
        'ingestion_harmful'          => 'Harmful if swallowed. Call a poison center or physician if you feel unwell.',
        // 4(b) symptoms line (built from the H3xx statements present)
        'symptoms_none'              => 'No significant symptoms or effects are known or expected under normal conditions of use.',
        'symptoms_acute_prefix'      => 'Acute:',
        'symptoms_delayed_prefix'    => 'Delayed:',
        // 4(c) notes-to-physician fragments (appended to section4.notes)
        'notes_aspiration'           => 'Aspiration hazard: if vomiting occurs or gastric lavage is considered, protect the airway against aspiration (risk of chemical pneumonitis).',
        'notes_corrosive'            => 'Corrosive material: do not attempt to neutralize and do not induce vomiting.',
        'notes_inhalation_delayed'   => 'Effects of inhalation exposure may be delayed; keep the person under medical observation for at least 48 hours.',
    ],
    'section5' => [
        'title'            => 'Fire-Fighting Measures',
        'suitable_media'   => 'Water spray, dry chemical, carbon dioxide (CO2), foam.',
        'unsuitable_media' => 'Do not use direct water stream as it may spread fire.',
        'specific_hazards' => 'Combustion may produce carbon monoxide, carbon dioxide, and other toxic fumes.',
        'firefighter_advice' => 'Wear self-contained breathing apparatus (SCBA) and full protective gear. Cool containers exposed to fire with water spray.',
        // Smart-logic fragments
        'unsuitable_water_reactive' => 'Do NOT use water. Product reacts with water. Use dry chemical, dry sand, or carbon dioxide (CO2).',
        'suitable_oxidizer'         => 'Water spray (flood quantities), foam. Do not use dry chemical on large fires involving oxidizers.',
        'specific_hazards_oxidizer' => 'Oxidizer — may intensify fire. May cause or intensify fire; oxidizer. Combustion may produce carbon monoxide, carbon dioxide, and other toxic fumes.',
        'specific_hazards_organic_peroxide' => 'May catch fire or explode upon heating. Combustion may produce carbon monoxide, carbon dioxide, and other toxic fumes.',
        'firefighter_advice_explosive' => 'Evacuate area. Fight fire from a protected location. Wear self-contained breathing apparatus (SCBA) and full protective gear.',
        'flash_point_low_warning'   => 'Highly flammable liquid and vapor. Keep away from heat, sparks, open flames, and hot surfaces.',
    ],
    'section6' => [
        'title'                => 'Accidental Release Measures',
        'personal_precautions' => 'Use appropriate PPE (see Section 8). Avoid contact with skin and eyes. Ensure adequate ventilation.',
        'environmental'        => 'Prevent entry into drains, sewers, and waterways.',
        'containment'          => 'Contain spill with inert absorbent material (sand, vermiculite). Collect in suitable containers for disposal.',
        // Smart-logic fragments
        'precautions_corrosive'    => 'Use appropriate PPE including chemical-resistant suit, gloves, and face shield (see Section 8). Avoid all contact with skin and eyes. Ensure adequate ventilation. Evacuate unprotected personnel.',
        'precautions_acute_toxic'  => 'Use appropriate PPE including self-contained breathing apparatus (SCBA) (see Section 8). Evacuate unprotected personnel from affected area.',
        'environmental_aquatic'    => 'Prevent entry into drains, sewers, and waterways. Toxic to aquatic life. Notify authorities if product enters waterways.',
        'containment_liquid'       => 'Stop leak if safe to do so. Dam or contain spill. Absorb with inert absorbent material (sand, vermiculite, diatomaceous earth). Collect in suitable containers for disposal.',
        'containment_solid'        => 'Avoid generating dust. Sweep or vacuum up material. Collect in suitable containers for disposal.',
    ],
    'section7' => [
        'title'    => 'Handling and Storage',
        'handling' => 'Use in well-ventilated areas. Avoid contact with skin and eyes. Use appropriate PPE. Keep away from heat, sparks, and open flame.',
        'storage'  => 'Store in a cool, dry, well-ventilated area. Keep containers tightly closed when not in use. Store away from incompatible materials.',
        // Smart-logic fragments
        'handling_flammable'       => 'Use in well-ventilated areas. Keep away from heat, sparks, open flames, and hot surfaces. No smoking. Use non-sparking tools. Take precautionary measures against static discharge.',
        'handling_oxidizer'        => 'Keep away from combustible materials, heat, and ignition sources. Do not mix with flammable or combustible materials.',
        'handling_water_reactive'  => 'Keep dry. Do not handle in wet conditions. Use appropriate PPE.',
        'handling_pyrophoric'      => 'Handle under inert gas atmosphere. Protect from moisture and air. Use appropriate PPE.',
        'handling_self_reactive'   => 'Keep away from heat. Handle and open container with care.',
        'storage_flammable'        => 'Store in a cool, dry, well-ventilated area away from heat and ignition sources. Keep containers tightly closed. Ground and bond containers when transferring material.',
        'storage_oxidizer'         => 'Store in a cool, dry, well-ventilated area. Keep separated from combustible materials, reducing agents, and flammable substances.',
        'storage_water_reactive'   => 'Store in a cool, dry area. Protect from moisture and water. Keep containers tightly sealed.',
        'storage_pyrophoric'       => 'Store under inert gas. Protect from air and moisture. Keep containers tightly sealed.',
        'storage_self_heating'     => 'Store in a cool area. Keep away from heat sources. Monitor storage temperature.',
    ],
    'section8' => [
        'title'           => 'Exposure Controls / Personal Protection',
        'engineering'     => 'Use local exhaust ventilation or other engineering controls to maintain airborne concentrations below exposure limits.',
        // PPE sentences, selected per field by HazardEngine::derivePPE tier
        // (section8.ppe.<field>.<tier>). 'general' = classified product, no
        // H-code on this route; 'none' = no H-codes at all.
        'ppe' => [
            'respiratory' => [
                'scba'       => 'Where engineering controls cannot keep airborne concentrations below applicable exposure limits, use a NIOSH-approved supplied-air respirator or self-contained breathing apparatus (SCBA) under a respiratory protection program meeting 29 CFR 1910.134. Air-purifying cartridge respirators are not adequate for this material.',
                'sensitizer' => 'Use a NIOSH-approved air-purifying respirator with organic vapor/P100 combination cartridges under a respiratory protection program meeting 29 CFR 1910.134. Use a supplied-air respirator where concentrations are unknown or high, or where sensitization has occurred.',
                'cartridge'  => 'If exposure limits are exceeded or irritation is experienced, use a NIOSH-approved air-purifying respirator with organic vapor and/or particulate (P100) cartridges, selected and used in accordance with 29 CFR 1910.134.',
                'general'    => 'Not normally required under normal conditions of use with adequate ventilation. If exposure limits are exceeded, use a NIOSH-approved respirator selected in accordance with 29 CFR 1910.134.',
                'none'       => 'No special respiratory protection required under normal conditions of use with adequate ventilation.',
            ],
            'hand_protection' => [
                'impervious' => 'Impervious chemical-resistant gloves (butyl rubber or fluoroelastomer (Viton) recommended) meeting 29 CFR 1910.138. Double gloving is recommended. Verify breakthrough time with the glove manufacturer (ASTM F739) and replace gloves at the first sign of degradation.',
                'sensitizer' => 'Chemical-resistant gloves (nitrile recommended) meeting 29 CFR 1910.138. Change gloves frequently and at any sign of contamination to prevent sensitization. Verify breakthrough time with the glove manufacturer (ASTM F739).',
                'resistant'  => 'Chemical-resistant gloves (nitrile or neoprene recommended) meeting 29 CFR 1910.138. Verify breakthrough time with the glove manufacturer (ASTM F739).',
                'general'    => 'Chemical-resistant gloves (nitrile or neoprene) are recommended as good industrial hygiene practice to minimize skin contact.',
                'none'       => 'No special hand protection required under normal conditions of use. Gloves may be worn as good industrial hygiene practice.',
            ],
            'eye_protection' => [
                'goggles_faceshield' => 'Chemical splash goggles and a face shield meeting ANSI/ISEA Z87.1 (29 CFR 1910.133). Contact lenses should not be worn. An eyewash station must be immediately accessible.',
                'goggles'            => 'Chemical splash goggles or safety glasses with side shields meeting ANSI/ISEA Z87.1 (29 CFR 1910.133). Use a face shield where a splash hazard exists.',
                'glasses'            => 'Safety glasses with side shields meeting ANSI/ISEA Z87.1 (29 CFR 1910.133).',
                'general'            => 'Safety glasses with side shields meeting ANSI/ISEA Z87.1 are recommended as good industrial hygiene practice.',
                'none'               => 'No special eye protection required under normal conditions of use. Safety glasses may be worn as good industrial hygiene practice.',
            ],
            'skin_protection' => [
                'suit'     => 'Chemical-resistant protective suit or coveralls, chemical-resistant apron and impervious boots. An emergency safety shower and eyewash station must be immediately accessible. Remove contaminated clothing immediately and launder before reuse.',
                'clothing' => 'Wear protective clothing (long sleeves and an impervious apron) to prevent skin contact. Remove contaminated clothing and launder before reuse.',
                'general'  => 'Wear suitable work clothing to minimize skin contact. Launder contaminated clothing before reuse.',
                'none'     => 'No special protective clothing required under normal conditions of use.',
            ],
        ],
    ],
    'section9' => [
        'title' => 'Physical and Chemical Properties',
    ],
    'section10' => [
        'title'            => 'Stability and Reactivity',
        'reactivity'       => 'No dangerous reaction known under conditions of normal use.',
        'stability'        => 'Stable under recommended storage conditions.',
        'conditions_avoid' => 'Excessive heat, sparks, open flames, strong oxidizers.',
        'incompatible'     => 'Strong oxidizing agents, strong acids, strong bases.',
        'decomposition'    => 'Carbon monoxide, carbon dioxide, and other toxic gases may be released upon thermal decomposition.',
        // Smart-logic fragments
        'conditions_avoid_water_reactive' => 'Water, moisture, excessive heat, sparks, open flames.',
        'conditions_avoid_pyrophoric'     => 'Air, moisture, excessive heat.',
        'conditions_avoid_self_reactive'  => 'Heat, friction, shock, contamination. Avoid temperatures above recommended storage limits.',
        'incompatible_oxidizer'           => 'Combustible materials, reducing agents, organic materials, metals in powder form.',
        'incompatible_water_reactive'     => 'Water, moisture, strong acids, strong bases.',
        'incompatible_flammable'          => 'Strong oxidizing agents, strong acids, strong bases, halogens.',
        'incompatible_pyrophoric'         => 'Air, moisture, water, oxidizing agents.',
        'decomposition_nitrogen'          => 'Carbon monoxide, carbon dioxide, nitrogen oxides, and other toxic gases may be released upon thermal decomposition.',
        'decomposition_sulfur'            => 'Carbon monoxide, carbon dioxide, sulfur oxides, and other toxic gases may be released upon thermal decomposition.',
        'decomposition_halogen'           => 'Carbon monoxide, carbon dioxide, hydrogen halides, and other toxic gases may be released upon thermal decomposition.',
    ],
    'section11' => [
        'title'           => 'Toxicological Information',
        'acute_toxicity'  => 'Based on available data, the classification criteria are not met.',
        // #20: per-route acute toxicity derived from the engine classification
        // (SDSGenerator::buildAcuteToxicity); the constant above is the fallback.
        'acute_route_oral'       => 'Acute toxicity (oral)',
        'acute_route_dermal'     => 'Acute toxicity (dermal)',
        'acute_route_inhalation' => 'Acute toxicity (inhalation)',
        'acute_route_line'       => ':route: :category — :statement (:code).',
        'acute_ate'              => 'ATEmix = :value :unit.',
        'acute_unit_oral'        => 'mg/kg bw',
        'acute_unit_dermal'      => 'mg/kg bw',
        'acute_unit_inhalation'  => 'mg/L (4 h)',
        'chronic_effects' => 'Prolonged or repeated exposure may cause skin drying or cracking.',
        'carcinogenicity' => 'No components present at or above :threshold% are listed as carcinogens by IARC, NTP, or OSHA.',
        'carcinogenicity_listed_intro' => 'The following component(s), present at or above :threshold%, are listed as carcinogens or potential carcinogens in the IARC Monographs, the NTP Report on Carcinogens, or by OSHA:',
        // :range is the Section 3 prescribed-range band (e.g. "1 - 5%"), never the exact percentage
        'carcinogenicity_listed_line'  => ':name (CAS :cas, :range) — :listings',
        'carcinogenicity_listing'      => ':agency: :classification',
        // Chronic Effects smart-logic fragments (audit #21). Wording tracks GHS Rev. 7 / HazCom 2024 App. C.
        'chronic_resp_sens'     => 'Respiratory sensitizer: may cause allergy or asthma symptoms or breathing difficulties if inhaled (H334). Repeated inhalation exposure may lead to sensitization; sensitized individuals may react to very low concentrations.',
        'chronic_skin_sens'     => 'Skin sensitizer: may cause an allergic skin reaction (H317). Repeated or prolonged skin contact may lead to sensitization; once sensitized, individuals may react to very low concentrations.',
        'chronic_muta_1'        => 'Germ cell mutagenicity: may cause genetic defects (H340).',
        'chronic_muta_2'        => 'Germ cell mutagenicity: suspected of causing genetic defects (H341).',
        'chronic_carc_1'        => 'Carcinogenicity: may cause cancer (H350). See Carcinogenicity below for agency listings.',
        'chronic_carc_2'        => 'Carcinogenicity: suspected of causing cancer (H351). See Carcinogenicity below for agency listings.',
        'chronic_carc_listed'   => 'One or more components are listed as carcinogens by IARC, NTP, or OSHA; see Carcinogenicity below.',
        'chronic_repr_1'        => 'Reproductive toxicity: may damage fertility or the unborn child (H360).',
        'chronic_repr_2'        => 'Reproductive toxicity: suspected of damaging fertility or the unborn child (H361).',
        'chronic_lactation'     => 'May cause harm to breast-fed children (H362).',
        'chronic_stot_re_1'     => 'Specific target organ toxicity, repeated exposure (Category 1): causes damage to organs through prolonged or repeated exposure (H372). Refer to Section 2 for the affected organs and route of exposure where specified.',
        'chronic_stot_re_2'     => 'Specific target organ toxicity, repeated exposure (Category 2): may cause damage to organs through prolonged or repeated exposure (H373). Refer to Section 2 for the affected organs and route of exposure where specified.',
        'chronic_repeated_skin' => 'Repeated or prolonged skin contact may cause irritation, dryness, or dermatitis.',
        'chronic_repeated_eye'  => 'Repeated eye contact may cause irritation.',
        'chronic_none'          => 'None known. Based on available data for the mixture and its components, the classification criteria for chronic health effects (sensitization, germ cell mutagenicity, carcinogenicity, reproductive toxicity, specific target organ toxicity from repeated exposure) are not met.',
    ],
    'section12' => [
        'title'            => 'Ecological Information',
        'ecotoxicity'      => 'No data available on the mixture. Avoid release to the environment.',
        'persistence'      => 'No data available.',
        'bioaccumulation'  => 'No data available.',
        // Smart-logic fragments
        'ecotoxicity_acute'        => 'Toxic to aquatic life based on hazard classification.',
        'ecotoxicity_chronic'      => 'Toxic to aquatic life with long lasting effects based on hazard classification.',
        'ecotoxicity_acute_chronic' => 'Toxic to aquatic life with long lasting effects based on hazard classification.',
        'environmental_warning'    => 'Avoid release to the environment. Prevent entry into waterways, sewers, and soil.',
        // #23: echo the resolved aquatic H-statements instead of paraphrasing
        'ecotoxicity_classified'    => 'Classified as hazardous to the aquatic environment by the GHS summation method applied to the component classifications and M-factors listed below (GHS Rev. 7, Chapter 4.1):',
        // Lead-in when an aquatic H-code is present but no component table follows (e.g. finished-good hazard override)
        'ecotoxicity_classified_no_table' => 'Classified as hazardous to the aquatic environment (GHS Rev. 7, Chapter 4.1):',
        'ecotoxicity_not_classified' => 'Based on the component data below, the mixture does not meet the GHS criteria for classification as hazardous to the aquatic environment (GHS Rev. 7, Chapter 4.1, summation method). Avoid release to the environment.',
    ],
    'section13' => [
        'title'   => 'Disposal Considerations',
        'methods' => 'Dispose of in accordance with all applicable federal, state, and local regulations. Do not dump into sewers, drains, or waterways.',
        // Smart-logic fragments
        'methods_ignitable'   => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may be classified as ignitable hazardous waste (EPA D001) due to its flash point. Do not dump into sewers, drains, or waterways.',
        'methods_corrosive'   => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may be classified as corrosive hazardous waste (EPA D002). Do not dump into sewers, drains, or waterways.',
        'methods_toxic'       => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may contain toxic components subject to hazardous waste regulations. Do not dump into sewers, drains, or waterways.',
        'methods_reactive'    => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may be classified as reactive hazardous waste (EPA D003). Do not dump into sewers, drains, or waterways.',
        'methods_aquatic'     => 'Dispose of in accordance with all applicable federal, state, and local regulations. Do not allow product to reach waterways — toxic to aquatic life. Do not dump into sewers, drains, or waterways.',
    ],
    'section14' => [
        'title' => 'Transport Information',
        'note'  => 'Transport classification should be verified with the carrier and against current 49 CFR (DOT), IATA and IMDG requirements before shipment.',
    ],
    'section15' => [
        'title'       => 'Regulatory Information',
        'osha_status' => 'This product is classified as hazardous under OSHA HazCom 2012 (29 CFR 1910.1200).',
        'tsca_status' => 'All components are listed on or exempt from the TSCA inventory.',
    ],
    'section16' => [
        'title'         => 'Other Information',
        'draft'         => 'Draft (not yet published)',
        'disclaimer'    => 'The information provided in this Safety Data Sheet is correct to the best of our knowledge at the date of publication. It is intended as a guide for safe handling, use, processing, storage, transportation, disposal, and release. It should not be considered a warranty or quality specification. The information relates only to the specific material designated and may not be valid when used in combination with other materials or in any process.',
        // Master abbreviation table (audit #33). Keys = the term exactly as it
        // prints on an EN sheet; AbbreviationService keeps only the matched
        // ones. Keep alphabetical (case-insensitive) — this is the print order.
        'abbreviation_table' => [
            'ACGIH'  => 'American Conference of Governmental Industrial Hygienists',
            'CAS'    => 'Chemical Abstracts Service registry number',
            'CFR'    => 'Code of Federal Regulations (United States)',
            'DOT'    => 'United States Department of Transportation',
            'EPA'    => 'United States Environmental Protection Agency',
            'GHS'    => 'Globally Harmonized System of Classification and Labelling of Chemicals (United Nations)',
            'HAP'    => 'Hazardous Air Pollutant (Clean Air Act Section 112(b))',
            'HazCom' => 'OSHA Hazard Communication Standard, 29 CFR 1910.1200',
            'Hxxx'   => 'GHS hazard statement code',
            'IARC'   => 'International Agency for Research on Cancer',
            'IDLH'   => 'Immediately Dangerous to Life or Health (National Institute for Occupational Safety and Health)',
            'LC50'   => 'Median lethal concentration (inhalation)',
            'LD50'   => 'Median lethal dose (oral or dermal)',
            'LED'    => 'Light-emitting diode (curing lamp)',
            'n.o.s.' => 'Not otherwise specified (proper shipping name)',
            'NIOSH'  => 'National Institute for Occupational Safety and Health',
            'NTP'    => 'National Toxicology Program (United States)',
            'OSHA'   => 'Occupational Safety and Health Administration (United States)',
            'OV'     => 'Organic vapor (respirator cartridge)',
            'P100'   => 'Particulate respirator filter class, oil-proof, 99.97% efficient (National Institute for Occupational Safety and Health)',
            'PEL'    => 'Permissible Exposure Limit (OSHA, 29 CFR 1910.1000)',
            'PPE'    => 'Personal Protective Equipment',
            'Pxxx'   => 'GHS precautionary statement code',
            'REL'    => 'Recommended Exposure Limit (National Institute for Occupational Safety and Health)',
            'SARA'   => 'Superfund Amendments and Reauthorization Act of 1986 (Title III, Section 313)',
            'SCBA'   => 'Self-Contained Breathing Apparatus',
            'SNUR'   => 'Significant New Use Rule (TSCA Section 5)',
            'STEL'   => 'Short-Term Exposure Limit (15-minute)',
            'STOT'   => 'Specific Target Organ Toxicity',
            'TLV'    => 'Threshold Limit Value (American Conference of Governmental Industrial Hygienists)',
            'TRI'    => 'Toxics Release Inventory (Emergency Planning and Community Right-to-Know Act, Section 313)',
            'TSCA'   => 'Toxic Substances Control Act',
            'TWA'    => 'Time-Weighted Average (8-hour)',
            'UN'     => 'United Nations (transport identification number)',
            'UV'     => 'Ultraviolet (energy-curable)',
            'VOC'    => 'Volatile Organic Compound (EPA Method 24)',
            'vol%'   => 'Percent by volume',
            'W&E'    => 'Water and exempt compounds (VOC less water and exempt solvents, EPA Method 24)',
            'wt%'    => 'Percent by weight',
        ],
    ],

    // Document-level strings (header, footer, section banner)
    'document' => [
        'title'           => 'SAFETY DATA SHEET',
        'section_prefix'  => 'SECTION',
        'page'            => 'Page',
        'page_of'         => 'of',
        'revision_prefix' => 'Rev.',
        // Shared Sections 12-15 footnote (audit item #25); printed only when
        // admin setting sds.show_ghs_section_note is on (default on).
        'ghs_section_note' => 'Sections 12–15 are included as required by OSHA HazCom (29 CFR 1910.1200(g)(2)). Their content is regulated by other agencies (e.g., EPA, DOT) and is not enforced by OSHA; it is provided in accordance with the GHS.',
    ],

    // PDF / preview sub-labels used within sections
    'labels' => [
        // Section 1
        'product_identifier'    => 'Product Identifier',
        'product_family'        => 'Product Family',
        'recommended_use'       => 'Recommended Use',
        'restrictions'          => 'Restrictions on Use',
        'manufacturer_info'     => 'Manufacturer / Supplier Information',
        'company'               => 'Company',
        'address'               => 'Address',
        'phone'                 => 'Phone',
        'emergency'             => 'Emergency',
        'email'                 => 'Email',
        'website'               => 'Website',

        // Section 2
        'pictograms'            => 'Pictograms',
        'ghs_classification'    => 'GHS Classification',
        'physical_hazards'      => 'Physical Hazards',
        'health_hazards'        => 'Health Hazards',
        'environmental_hazards' => 'Environmental Hazards',
        'hazard_statements'     => 'Hazard Statements',
        'precautionary_statements' => 'Precautionary Statements',
        'ppe_recommendations'   => 'Recommended Personal Protective Equipment (PPE)',
        'other_hazards'         => 'Other Hazards',
        'ppe_wear_eye'          => 'Wear Eye Protection',
        'ppe_wear_gloves'       => 'Wear Gloves',
        'ppe_wear_respiratory'  => 'Wear Respiratory Protection',
        'ppe_wear_skin'         => 'Wear Protective Clothing',

        // Section 3
        'type'                  => 'Type',
        'cas_number'            => 'CAS Number',
        'chemical_name'         => 'Chemical Name',
        'concentration'         => 'Concentration',
        'hazardous_only_note'   => 'Hazardous ingredients and ingredients with an occupational exposure limit are listed. Ingredients not listed are non-hazardous or are present below disclosure thresholds.',
        'no_hazardous_note'     => 'No hazardous ingredients above disclosure thresholds.',
        'mixture'               => 'Mixture',

        // Section 4 (First-Aid)
        'inhalation'            => 'Inhalation',
        'skin_contact'          => 'Skin Contact',
        'eye_contact'           => 'Eye Contact',
        'ingestion'             => 'Ingestion',
        'symptoms_effects'      => 'Most Important Symptoms/Effects, Acute and Delayed',
        'notes_to_physician'    => 'Notes to Physician',

        // Section 5 (Fire-Fighting)
        'suitable_media'        => 'Suitable Extinguishing Media',
        'unsuitable_media'      => 'Unsuitable Extinguishing Media',
        'specific_hazards'      => 'Specific Hazards',
        'firefighter_advice'    => 'Advice for Firefighters',

        // Section 6 (Accidental Release)
        'personal_precautions'  => 'Personal Precautions',
        'environmental_precautions' => 'Environmental Precautions',
        'containment_cleanup'   => 'Containment and Cleanup',

        // Section 7 (Handling and Storage)
        'handling'              => 'Handling',
        'storage'               => 'Storage',

        // Section 8 (Exposure Controls) — table headers
        'engineering_controls'  => 'Engineering Controls',
        'respiratory_protection' => 'Respiratory Protection',
        'hand_protection'       => 'Hand Protection',
        'eye_protection'        => 'Eye Protection',
        'skin_protection'       => 'Skin Protection',
        'respiratory'           => 'Respiratory',
        'skin_body'             => 'Skin/Body Protection',
        'el_cas'                => 'CAS',
        'el_chemical'           => 'Chemical',
        'el_type'               => 'Type',
        'el_value'              => 'Value',
        'el_units'              => 'Units',
        'el_conc_pct'           => 'Conc%',
        'el_notes'              => 'Notes',

        // Section 9 (Physical/Chemical Properties)
        'physical_state'        => 'Physical State',
        'color'                 => 'Color',
        'appearance'            => 'Appearance',
        'odor'                  => 'Odor',
        'boiling_point'         => 'Boiling Point',
        'flash_point'           => 'Flash Point',
        'solubility'            => 'Solubility',
        'specific_gravity'      => 'Specific Gravity',
        'voc_lb_gal'            => 'VOC (lb/gal) (EPA Method 24)',
        'voc_less_we'           => 'VOC less W&E (lb/gal)',
        'voc_wt_pct'            => 'VOC (wt%)',
        'solids_wt_pct'         => 'Solids (wt%)',
        'solids_vol_pct'        => 'Solids (vol%)',

        // Section 10 (Stability and Reactivity)
        'reactivity'            => 'Reactivity',
        'chemical_stability'    => 'Chemical Stability',
        'conditions_avoid'      => 'Conditions to Avoid',
        'incompatible_materials' => 'Incompatible Materials',
        'decomposition_products' => 'Hazardous Decomposition Products',

        // Section 11 (Toxicological Information)
        'acute_toxicity'        => 'Acute Toxicity',
        'chronic_effects'       => 'Chronic Effects',
        'carcinogenicity'       => 'Carcinogenicity',
        'component_tox_data'    => 'Component Toxicological Data',
        'health_hazard'         => 'Health Hazard',

        // Section 12 (Ecological Information)
        'ecotoxicity'           => 'Ecotoxicity',
        'persistence'           => 'Persistence and Degradability',
        'bioaccumulation'       => 'Bioaccumulative Potential',
        'component_ecotox_data' => 'Component Aquatic Hazard Data',
        'aquatic_acute'         => 'Aquatic Acute',
        'aquatic_chronic'       => 'Aquatic Chronic',
        'm_factor'              => 'M-factor',

        // Section 13 (Disposal)
        'disposal_methods'      => 'Disposal Methods',

        // Section 14 (Transport)
        'un_number'             => 'UN Number',
        'proper_shipping_name'  => 'Proper Shipping Name',
        'transport_hazard_class' => 'Hazard Class',
        'packing_group'         => 'Packing Group',

        // Section 15 (Regulatory)
        'osha_status'           => 'OSHA Status',
        'tsca_status'           => 'TSCA Status',
        'sara_313_title'        => 'SARA 313 / TRI Reporting',
        'sara_313_statement'    => 'This product contains the following toxic chemical(s) subject to the reporting requirements of Section 313 of Title III of the Superfund Amendments and Reauthorization Act of 1986 (SARA) and 40 CFR Part 372 (supplier notification per 40 CFR 372.45):',
        'sara_313_none'         => 'This product does not contain any toxic chemicals subject to the reporting requirements of SARA Title III Section 313 (40 CFR Part 372) at or above the applicable de minimis concentration.',
        'sara_313_threshold'    => 'de minimis threshold',
        'sara_313_pbt'          => 'PBT chemical',
        'hap_title'             => 'EPA HAPs',
        'hap_triggering'        => 'Triggering HAP Chemical',
        'hap_wt_pct'            => 'Wt% in Formula',
        'hap_total'             => 'Total HAP Content',
        'hap_none'              => 'This product does not contain any EPA HAPs listed under Clean Air Act Section 112(b).',
        'prop65_title'          => 'California Proposition 65',
        'prop65_none'           => 'This product is not known to contain any chemicals listed under California Proposition 65.',
        'snur_title'            => 'EPA Significant New Use Rules (SNUR)',
        'state_regulations'     => 'State Regulations',

        // Section 16 (Other Information)
        'version'               => 'Version',
        'effective_date'        => 'Effective Date',
        'revision_date'         => 'Revision Date',
        'abbreviations'         => 'Abbreviations',
        'disclaimer'            => 'DISCLAIMER',

        // Generic / shared
        'not_determined'        => 'Not determined',
        'not_regulated'         => 'Not regulated',
        'not_applicable'        => 'Not applicable',
        'note'                  => 'Note',
        'revision_note'         => 'Revision Note',
        'uv_acrylate_note'      => 'UV Acrylate Information',
    ],
];
