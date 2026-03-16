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
        'title'         => 'Hazard(s) Identification',
        'other_hazards' => 'None known.',
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
        // Smart-logic fragments (appended when matching H-codes are present)
        'inhalation_fatal'     => 'IMMEDIATELY remove person to fresh air. If not breathing, give artificial respiration. Call a poison center or physician immediately.',
        'inhalation_toxic'     => 'Remove to fresh air immediately. If breathing is difficult, give oxygen. Seek immediate medical attention.',
        'skin_corrosive'       => 'Immediately flush skin with copious amounts of water for at least 20 minutes. Remove all contaminated clothing and shoes. Seek immediate medical attention. Wash contaminated clothing before reuse.',
        'skin_sensitizer'      => 'Remove contaminated clothing. Wash skin thoroughly with soap and water. If allergic reaction develops, seek medical attention. Future exposure should be avoided.',
        'eyes_corrosive'       => 'Immediately flush eyes with copious amounts of water for at least 30 minutes, lifting upper and lower lids. Seek immediate medical attention. Do not allow victim to rub eyes.',
        'eyes_serious_damage'  => 'Immediately flush eyes with copious amounts of water for at least 20 minutes, lifting upper and lower lids. Seek immediate medical attention.',
        'ingestion_aspiration' => 'Do NOT induce vomiting — aspiration hazard. If vomiting occurs naturally, keep head below hips to prevent aspiration. Seek immediate medical attention.',
        'ingestion_toxic'      => 'Rinse mouth with water. Do not induce vomiting unless directed by medical personnel. Call a poison center or physician immediately.',
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
        'respiratory'     => 'Use NIOSH-approved respirator if exposure limits are exceeded or if irritation is experienced.',
        'hand_protection' => 'Chemical-resistant gloves (nitrile or neoprene recommended).',
        'eye_protection'  => 'Safety glasses with side shields. Use chemical splash goggles if splash hazard exists.',
        'skin_protection' => 'Wear protective clothing to prevent skin contact.',
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
        'chronic_effects' => 'Prolonged or repeated exposure may cause skin drying or cracking.',
        'carcinogenicity' => 'No components are listed as carcinogens by IARC, NTP, or OSHA.',
    ],
    'section12' => [
        'title'            => 'Ecological Information',
        'ecotoxicity'      => 'No data available on the mixture. Avoid release to the environment.',
        'persistence'      => 'No data available.',
        'bioaccumulation'  => 'No data available.',
        'note'             => 'This section is not required by OSHA HazCom but is included per GHS guidelines.',
        // Smart-logic fragments
        'ecotoxicity_acute'        => 'Toxic to aquatic life based on hazard classification.',
        'ecotoxicity_chronic'      => 'Toxic to aquatic life with long lasting effects based on hazard classification.',
        'ecotoxicity_acute_chronic' => 'Toxic to aquatic life with long lasting effects based on hazard classification.',
        'environmental_warning'    => 'Avoid release to the environment. Prevent entry into waterways, sewers, and soil.',
    ],
    'section13' => [
        'title'   => 'Disposal Considerations',
        'methods' => 'Dispose of in accordance with all applicable federal, state, and local regulations. Do not dump into sewers, drains, or waterways.',
        'note'    => 'This section is not required by OSHA HazCom but is included per GHS guidelines.',
        // Smart-logic fragments
        'methods_ignitable'   => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may be classified as ignitable hazardous waste (EPA D001) due to its flash point. Do not dump into sewers, drains, or waterways.',
        'methods_corrosive'   => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may be classified as corrosive hazardous waste (EPA D002). Do not dump into sewers, drains, or waterways.',
        'methods_toxic'       => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may contain toxic components subject to hazardous waste regulations. Do not dump into sewers, drains, or waterways.',
        'methods_reactive'    => 'Dispose of in accordance with all applicable federal, state, and local regulations. This product may be classified as reactive hazardous waste (EPA D003). Do not dump into sewers, drains, or waterways.',
        'methods_aquatic'     => 'Dispose of in accordance with all applicable federal, state, and local regulations. Do not allow product to reach waterways — toxic to aquatic life. Do not dump into sewers, drains, or waterways.',
    ],
    'section14' => [
        'title' => 'Transport Information',
        'note'  => 'This section is not required by OSHA HazCom but is included per GHS guidelines. Verify classification with carrier before shipment.',
    ],
    'section15' => [
        'title'       => 'Regulatory Information',
        'osha_status' => 'This product is classified as hazardous under OSHA HazCom 2012 (29 CFR 1910.1200).',
        'tsca_status' => 'All components are listed on or exempt from the TSCA inventory.',
        'note'        => 'This section is not required by OSHA HazCom but is included per GHS guidelines.',
    ],
    'section16' => [
        'title'         => 'Other Information',
        'disclaimer'    => 'The information provided in this Safety Data Sheet is correct to the best of our knowledge at the date of publication. It is intended as a guide for safe handling, use, processing, storage, transportation, disposal, and release. It should not be considered a warranty or quality specification. The information relates only to the specific material designated and may not be valid when used in combination with other materials or in any process.',
        'abbreviations' => 'CAS = Chemical Abstracts Service; GHS = Globally Harmonized System; OSHA = Occupational Safety and Health Administration; PEL = Permissible Exposure Limit; TLV = Threshold Limit Value; REL = Recommended Exposure Limit; IDLH = Immediately Dangerous to Life and Health; VOC = Volatile Organic Compound; SARA = Superfund Amendments and Reauthorization Act; TSCA = Toxic Substances Control Act.',
    ],

    // Document-level strings (header, footer, section banner)
    'document' => [
        'title'           => 'SAFETY DATA SHEET',
        'section_prefix'  => 'SECTION',
        'page'            => 'Page',
        'page_of'         => 'of',
        'revision_prefix' => 'Rev.',
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

        // Section 2
        'pictograms'            => 'Pictograms',
        'ghs_classification'    => 'GHS Classification',
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
        'hazardous_only_note'   => 'Only hazardous ingredients are listed. Non-hazardous components are omitted.',
        'no_hazardous_note'     => 'No hazardous ingredients above disclosure thresholds.',
        'mixture'               => 'Mixture',

        // Section 4 (First-Aid)
        'inhalation'            => 'Inhalation',
        'skin_contact'          => 'Skin Contact',
        'eye_contact'           => 'Eye Contact',
        'ingestion'             => 'Ingestion',
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
        'hap_title'             => 'Clean Air Act Section 112(b) — Hazardous Air Pollutants (HAPs)',
        'hap_triggering'        => 'Triggering HAP Chemical',
        'hap_wt_pct'            => 'Wt% in Formula',
        'hap_total'             => 'Total HAP Content',
        'hap_none'              => 'This product does not contain any EPA HAPs listed under Clean Air Act Section 112(b).',
        'prop65_title'          => 'California Proposition 65',
        'prop65_none'           => 'This product is not known to contain any chemicals listed under California Proposition 65.',
        'snur_title'            => 'EPA Significant New Use Rules (SNUR)',
        'state_regulations'     => 'State Regulations',

        // Section 16 (Other Information)
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
