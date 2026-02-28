<?php

require_once 'medisch.civix.php';
use CRM_Intake_ExtensionUtil as E;

/**
 * Hook Pre: De "Tolk" tussen CiviCRM Profiles en de database.
 * * WAAROM DEZE FUNCTIE: 
 * Wanneer een gebruiker een profiel invult, stuurt CiviCRM data met technische namen (bijv. 'medisch_issues_1832').
 * Deze functie vertaalt die technische namen naar leesbare API-namen (bijv. 'MEDISCH.medisch_issues')
 * en zorgt dat complexe velden (zoals checkboxes) in het juiste database-formaat (SOH-separators) terechtkomen.
 */

function medisch_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    // Configuratie: Welke profiel-ID's moeten deze logica triggeren?
    $profilecontmedisch = [148];
    $extdebug           = 0; // Log-niveau: 3 is standaard, 7+ is voor diepe debugging.

    // Filter: Alleen uitvoeren als het om het juiste profiel gaat en bij opslaan (create/edit).
    if (!in_array($groupID, $profilecontmedisch) || ($op != 'create' && $op != 'edit')) {
        return;
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH PRE - START VOOR CONTACT: " . $entityID,  "[OP: $op]");
    wachthond($extdebug, 3, "########################################################################");

    /**
     * MAPPING TABEL
     * Sleutel = De naam van het veld in het HTML formulier (CiviCRM Custom Field ID).
     * Waarde  = De alias/API-naam zoals we die in de rest van de extensie gebruiken.
     * LET OP: Als een Custom Field ID verandert in CiviCRM, hoef je alleen hier het getal aan te passen.
     */
    $mapping = [
        'medisch_issues_1832'           => 'MEDISCH.medisch_issues',
        'medisch_toelichting_1833'      => 'MEDISCH.medisch_toelichting',
        'medisch_medicatie_1834'        => 'MEDISCH.medisch_medicatie',
        'medisch_behandeling_1835'      => 'MEDISCH.medisch_behandeling',
        'medisch_toestemming_1836'      => 'MEDISCH.medisch_toestemming',
        'medisch_behandelaar_naam_1837' => 'MEDISCH.medisch_behandelaar_naam',
        'medisch_behandelaar_tel_1838'  => 'MEDISCH.medisch_behandelaar_tel',
        'medisch_verzekering_naam_1839' => 'MEDISCH.medisch_verzekering_naam',
        'medisch_verzekering_nr_1840'   => 'MEDISCH.medisch_verzekering_nr',
        'dieet_shortlist_1841'          => 'MEDISCH.dieet_shortlist',
        'dieet_toelichting_1842'        => 'MEDISCH.dieet_toelichting',
        'zwemdiploma_1843'              => 'MEDISCH.zwemdiploma',
        'medisch_modified_1844'         => 'MEDISCH.medisch_modified',
    ];

    $params_values = [];
    $displayname   = $params['display_name'] ?? "Onbekend";

    // Loop door alle bekende velden om te kijken of ze in de verzonden data ($params) zitten.
    foreach ($mapping as $db_col => $field_key) {
        if (isset($params[$db_col])) {
            $val = $params[$db_col];
            
            /**
             * TYPE CONVERSIE:
             * CiviCRM checkboxes/multi-selects komen binnen als PHP Arrays.
             * De database (en API4) verwachten voor deze velden een "SOH" string: ^waarde1^waarde2^
             */
            if (is_array($val)) {
                // Converteer array naar ^waarde1^waarde2^ formaat
                $params_values[$field_key] = CRM_Core_DAO::VALUE_SEPARATOR . implode(CRM_Core_DAO::VALUE_SEPARATOR, $val) . CRM_Core_DAO::VALUE_SEPARATOR;
                wachthond($extdebug, 5, "Veld $field_key omgezet van array naar SOH-string", $params_values[$field_key]);
            } 
            else {
                // Voor tekstvelden en radiobuttons nemen we de waarde direct over.
                $params_values[$field_key] = $val;
            }
        }
    }

    /**
     * DATA INTEGRITEIT CHECK:
     * We mogen NOOIT een PHP-array doorsturen naar de API4 'update' actie voor custom fields,
     * anders crasht de SQL query met een "Invalid Type" error.
     */
    foreach ($params_values as $field_key => $value) {
        if (is_array($value)) {
            wachthond(1, 3, "CRITICAL: Veld $field_key is nog steeds een ARRAY voor $displayname. Opschonen naar leeg.", "FOUT");
            $params_values[$field_key] = ""; 
        }
    }

    wachthond($extdebug, 3, "Gereedstaande waarden voor verwerking", $params_values);

    /**
     * DATABASE SYNC (Optioneel):
     * Als de operatie 'direct' is, forceren we een update via de API.
     * In de meeste gevallen (create/edit) doet CiviCRM dit zelf met de gewijzigde $params.
     */
    if ($op == 'direct' && !empty($entityID)) {
        wachthond($extdebug, 3, "Directe database update gestart voor ID: " . $entityID);
        
        $params_update_medisch = [
            'checkPermissions' => FALSE,
            'where'            => [['id', '=', (int)$entityID]],
            'values'           => $params_values,
        ];
        
        try {
            $result_update_medisch = civicrm_api4('Contact', 'update', $params_update_medisch);
            wachthond($extdebug, 4, "Resultaat directe update", $result_update_medisch);
        } catch (\Exception $e) {
            wachthond(1, 3, "API4 UPDATE FAILED: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * TERUGKOPPELING AAN CIVICRM:
     * We voegen onze vertaalde 'MEDISCH.xxx' velden toe aan de globale $params array.
     * Hierdoor snappen andere hooks en de database-engine welke data opgeslagen moet worden.
     */
    $params = array_merge($params, $params_values);
    
    wachthond($extdebug, 3, "### MEDISCH PRE - EINDE VOOR $displayname (ID: $entityID)");
    wachthond($extdebug, 3, "########################################################################");
}

/**
 * Centrale verwerkingsfunctie (De Motor).
 * * WAAROM: Deze functie bevat alle 'business logic'. Hij combineert:
 * 1. Bestaande data uit de database (indien aanwezig)
 * 2. Nieuwe data uit het formulier of de core-module
 * 3. Slimme regels (trefwoorden zoeken, vlaggetjes zetten)
 * En slaat het resultaat gestandaardiseerd op.
 */
function medisch_civicrm_configure($entityID = NULL, array $params = [], $op = 'direct', array $mapping = []): array {

    // --- STAP 0.1: RECURSIE STOP ---
    // We gebruiken 'static' om de status te onthouden zolang het script draait.
    // Hernoemd naar $processing_medisch voor duidelijkheid.
    static $processing_medisch = [];
    
    if (!empty($entityID)) {
        // Als we dit ID al aan het verwerken zijn, stoppen we direct om een loop te voorkomen.
        if (isset($processing_medisch[$entityID])) return $params;
        
        // Markeer dit ID als 'in behandeling'
        $processing_medisch[$entityID] = true;
    }

    $extdebug    = 0; 
    $displayname = "Onbekend";

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 1.0. START CONFIGURATIE (ID: $entityID)",             "[START]");
    wachthond($extdebug, 3, "########################################################################");

    if (!empty($entityID)) {
        
        // --- OPTIMALISATIE: HET 'GEHEUGEN' (STATIC CACHE) ---
        static $contact_cache = [];
        $result_contact_get = null;

        // STAP 1: EERST IN HET GEHEUGEN KIJKEN
        if (isset($contact_cache[$entityID])) {
            $result_contact_get = $contact_cache[$entityID];
            wachthond($extdebug, 3, "MEDISCH: Contact data uit CACHE geladen (Database overgeslagen)");
            
        } else {
            
            // NEE. We kennen dit ID nog niet. We moeten naar de database.
            
            // 1. Bepaal welke velden we minimaal nodig hebben.
            $select_fields = ['display_name'];

            // 2. [FIX DATAVERLIES] Als we in 'direct' modus zitten (aanroep vanuit core.php),
            // MOETEN we de bestaande waarden ophalen. Anders overschrijft de motor ze met 'leeg'.
            if ($op == 'direct') {
                $select_fields = array_merge($select_fields, [
                    // Medische velden
                    'MEDISCH.medisch_issues',
                    'MEDISCH.medisch_toelichting',
                    'MEDISCH.medisch_medicatie',
                    'MEDISCH.medisch_luchtwegklachten',
                    'MEDISCH.medisch_longlist',
                    'MEDISCH.medisch_notities',
                    'MEDISCH.medisch_doublecheck',
                    'MEDISCH.medisch_modified', // Belangrijk voor timestamps
                    
                    // Dieet velden (CRUCIAAL: Deze ontbraken eerst!)
                    'MEDISCH.dieet_issues',
                    'MEDISCH.dieet_shortlist',
                    'MEDISCH.dieet_longlist',
                    'MEDISCH.dieet_toelichting',
                    'MEDISCH.dieet_doublecheck'
                ]);
            }

            // 3. De daadwerkelijke query naar CiviCRM API4
            try {
                $result_contact_get = civicrm_api4('Contact', 'get', [
                    'checkPermissions' => FALSE,
                    'select'           => $select_fields,
                    'where'            => [['id', '=', $entityID]],
                    'limit'            => 1,
                ])->first();
            } catch (\Exception $e) {
                wachthond(1, 3, "MEDISCH FETCH ERROR: " . $e->getMessage());
            }

            // 4. OPSLAAN IN HET GEHEUGEN
            if ($result_contact_get) {
                $contact_cache[$entityID] = $result_contact_get;
            }
        }
        
        // --- VERWERKING ---
        if ($result_contact_get) {
            $displayname = $result_contact_get['display_name'] ?? "Onbekend";
            
            // [FIX DATAVERLIES] Slimme samenvoeging
            if ($op == 'direct') {
                // FILTER: Haal lege waarden uit de input ($params).
                // Zo voorkom je dat een lege parameter uit core.php een gevuld veld uit de DB wist.
                $input_params = array_filter($params, function($v) { 
                    return $v !== '' && $v !== null; 
                });
                
                // Voeg DB-resultaten samen met de gefilterde input.
                // Input wint alleen als het niet leeg is.
                $params = array_merge($result_contact_get, $input_params);
                
                wachthond($extdebug, 5, "MERGE COMPLETE: Input gecombineerd met Database voor $displayname");
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 1.1. INITIALISATIE LIJSTEN",                       "[CURRENT]");
    wachthond($extdebug, 3, "########################################################################");

    // --- STAP 1.3: INITIALISATIE LIJSTEN (GEPANTSERD) ---
    $init_keys = [
        'medisch_longlist'         => 'MEDISCH.medisch_longlist',
        'medisch_luchtwegklachten' => 'MEDISCH.medisch_luchtwegklachten',
        'dieet_shortlist'          => 'MEDISCH.dieet_shortlist',
        'dieet_longlist'           => 'MEDISCH.dieet_longlist',
    ];

    $lists = [];
    foreach ($init_keys as $key => $api_name) {
        $raw_input    = $params[$api_name] ?? '';
        $clean_string = format_civicrm_smart($raw_input, $api_name);
        // \x01 is het SOH scheidingsteken
        $as_array     = explode(CRM_Core_DAO::VALUE_SEPARATOR, trim($clean_string, CRM_Core_DAO::VALUE_SEPARATOR));
        
        $lists[$key] = (array) array_values(array_filter($as_array, function($v) {
            return !empty($v) && is_string($v) && trim($v) !== '' && strtolower($v) !== 'array';
        }));
    }

    // [FIX VARIABELE TOEWIJZING]
    // We gebruiken de waarde uit $params (Input). Als die leeg is, vallen we terug op de DB ($result_contact_get).
    // Dit is de tweede verdedigingslinie tegen dataverlies.
    
    // Medisch
    $val_medisch_toelichting = !empty($params['MEDISCH.medisch_toelichting']) ? $params['MEDISCH.medisch_toelichting'] : ($result_contact_get['MEDISCH.medisch_toelichting'] ?? '');
    $val_medisch_medicatie   = !empty($params['MEDISCH.medisch_medicatie'])   ? $params['MEDISCH.medisch_medicatie']   : ($result_contact_get['MEDISCH.medisch_medicatie'] ?? '');
    
    // Dieet
    $val_dieet_toelichting   = !empty($params['MEDISCH.dieet_toelichting'])   ? $params['MEDISCH.dieet_toelichting']   : ($result_contact_get['MEDISCH.dieet_toelichting'] ?? '');

    // Haystacks genereren voor trefwoord-analyse
    $haystack_medisch = $val_medisch_toelichting . ' ' . $val_medisch_medicatie . ' ' . $val_dieet_toelichting;
    $haystack_dieet   = $val_dieet_toelichting;

    wachthond($extdebug, 3, "HAYSTACKS GEGENEREERD", ["medisch_len" => strlen($haystack_medisch), "dieet_len" => strlen($haystack_dieet)]);

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 2.0. OPSCHONEN NEGATIEVE TERMEN",                 "[CLEANUP]");
    wachthond($extdebug, 3, "########################################################################");

    // Verwijder termen die effectief 'geen info' betekenen.
    $negatieve_termen = ['nee', 'geen', 'n.v.t.', 'nvt', 'niet van toepassing', '-', 'x', 'leeg'];
    $fields_to_clean  = [
        'MEDISCH.medisch_toelichting' => &$val_medisch_toelichting, 
        'MEDISCH.medisch_medicatie'   => &$val_medisch_medicatie,
        'MEDISCH.dieet_toelichting'   => &$val_dieet_toelichting
    ];

    foreach ($fields_to_clean as $f_name => &$val) {
        if (in_array(trim(strtolower((string)$val)), $negatieve_termen)) {
            wachthond($extdebug, 3, "OPSCHONEN: Negatieve term gevonden in $f_name");
            $val                 = '';
            $params[$f_name] = ''; 
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 3.0. BEHANDEL ALGEMENE MEDISCHE CHECKS",          "[MEDISCH]");
    wachthond($extdebug, 3, "########################################################################");

    $medische_checks = [
        'epipen'       => [' epipen', ' epi pen', 'epi-pen', 'EpiPen'], 
        'anafylaxie'   => ['anafylaxie', 'anafylactische'],
        'hartklachten' => ['hartklachten', 'hartritme', 'hartaanval'], 
        'mobiliteit'   => ['mobiliteit', ' krukken', 'rolstoel', 'rollator'],
        'epilepsie'    => ['epilepsie', 'insult', 'toeval', 'absences', 'depakine', 'tegretol', 'valproaat'],
        'diabetes'     => ['diabetes', 'suikerziekte', 'insuline', 'glucose', 'spuiten', 'pompje']
    ];

    foreach ($medische_checks as $value => $needles) {
        if (str_contains_any_reporting($haystack_medisch, $needles, false)) {
            if (!in_array($value, $lists['medisch_longlist'])) {
                wachthond($extdebug, 3, "MEDISCHE MATCH: $value");
                $lists['medisch_longlist'][] = $value;
            }
        } else {
            // Automatisch uitvinken als de tekst niet meer matcht
            if (($fK = array_search($value, $lists['medisch_longlist'])) !== false) {
                wachthond($extdebug, 3, "MEDISCHE VERWIJDERD: $value");
                unset($lists['medisch_longlist'][$fK]);
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 4.0. BEHANDEL LUCHTWEG CHECKS",                   "[LUCHTWEG]");
    wachthond($extdebug, 3, "########################################################################");

    $luchtweg_checks = [
        'astma'       => ['asmatisch', 'asthma', 'astma', 'pufje', 'puffer', 'ventolin', 'salbutamol'],
        'hooikoorts'  => ['hooikoorts', 'pollen', 'aerius', 'cetirizine'], 
        'verkoudheid' => ['verkouden', 'loopneus'],
        'overig'      => ['hoestbui', 'hyperventilatie']
    ];

    foreach ($luchtweg_checks as $value => $needles) {
        if (str_contains_any_reporting($haystack_medisch, $needles, false)) {
            if (!in_array($value, $lists['medisch_luchtwegklachten'])) {
                wachthond($extdebug, 3, "LUCHTWEG MATCH: $value");
                $lists['medisch_luchtwegklachten'][] = $value;
            }
        } else {
            if (($fK = array_search($value, $lists['medisch_luchtwegklachten'])) !== false) {
                wachthond($extdebug, 3, "LUCHTWEG VERWIJDERD: $value");
                unset($lists['medisch_luchtwegklachten'][$fK]);
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 5.0. BEHANDEL DIEET CHECKS",                      "[DIEET]");
    wachthond($extdebug, 3, "########################################################################");

    // Haal dynamisch de toegestane opties voor de shortlist op
    $valid_shortlist_options = get_valid_options('dieet_shortlist', 148);
    wachthond($extdebug, 3, "DYNAMISCHE SHORTLIST OPTIES", $valid_shortlist_options);

    // 'Overig' wordt elke ronde opnieuw berekend
    if (($fK = array_search('overig', $lists['dieet_shortlist'])) !== false) unset($lists['dieet_shortlist'][$fK]);
    if (($fK = array_search('overig', $lists['dieet_longlist']))  !== false) unset($lists['dieet_longlist'][$fK]);

    $dieet_checks = [
        'vega'    => ['vegetarisch', 'veganistisch', ' vega'], 
        'lactose' => ['lactose', 'koemelk'], 
        'gluten'  => ['gluten', 'coeliakie'], 
        'noten'   => ['pinda', 'notenallergie'],
        'varken'  => ['varken', 'halal'],
        'schaal'  => ['schaal', 'garnalen']
    ];

    $any_dieet_match = false;

    foreach ($dieet_checks as $value => $needles) {
        $found_in_text = str_contains_any_reporting($val_dieet_toelichting, $needles, false);
        $is_in_shortlist = in_array($value, $lists['dieet_shortlist']);
        
        // LOGICA: Als het in de tekst staat OF handmatig is aangevinkt -> Longlist gevuld
        if ($found_in_text || $is_in_shortlist) {
            $any_dieet_match = true;
            
            if (!in_array($value, $lists['dieet_longlist'])) {
                $lists['dieet_longlist'][] = $value;
                wachthond($extdebug, 3, "DIEET LONGLIST MATCH: $value (Bron: " . ($found_in_text ? 'Tekst' : 'Shortlist') . ")");
            }

            // Auto-vink shortlist als tekstmatch wordt gevonden
            if ($found_in_text && in_array($value, $valid_shortlist_options) && !$is_in_shortlist) {
                $lists['dieet_shortlist'][] = $value;
                wachthond($extdebug, 3, "DIEET SHORTLIST AUTO-VINK: $value");
            }
        } else {
            // Alleen verwijderen als het in GEEN van beide bronnen voorkomt
            if (($fK = array_search($value, $lists['dieet_longlist'])) !== false) {
                unset($lists['dieet_longlist'][$fK]);
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 5.5. OVERIG & STATUS BEPALING",                     "[STATUS]");
    wachthond($extdebug, 3, "########################################################################");

    $has_dieet_text = !empty(trim((string)$val_dieet_toelichting));
    $any_shortlist_match = !empty($lists['dieet_shortlist']); // Variabele init
    $unlisted_items = array_diff($lists['dieet_longlist'], $lists['dieet_shortlist']);

    // --- SECTIE A: TOEVOEGEN (HET VANGNET) ---
    if ((!$any_shortlist_match && $has_dieet_text) || !empty($unlisted_items)) {
        if (!in_array('overig', $lists['dieet_shortlist'])) {
            $lists['dieet_shortlist'][] = 'overig';
        }
        if (empty($lists['dieet_longlist'])) {
            $lists['dieet_longlist'][] = 'overig';
        }
    }

    // --- SECTIE B: VERWIJDEREN (DE SCHOONMAAK) ---
    if (count($lists['dieet_shortlist']) > 1) {
        if (($fK = array_search('overig', $lists['dieet_shortlist'])) !== false) {
            unset($lists['dieet_shortlist'][$fK]);
        }
    }
    if (count($lists['dieet_longlist']) > 1) {
        if (($fK = array_search('overig', $lists['dieet_longlist'])) !== false) {
            unset($lists['dieet_longlist'][$fK]);
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 6.0. BEPAAL DOUBLE & EXTRA CHECKS",                 "[CHECK]");
    wachthond($extdebug, 3, "########################################################################");

    // --- STAP 6.0: STATUS BEPALING ---
    $kritiek_med = ['epipen', 'anafylaxie', 'diabetes', 'hartklachten', 'epilepsie'];
    $med_check_status = "0";

    // 1. Check op Kritieke zaken (Status 2)
    $intersect = array_intersect($lists['medisch_longlist'], $kritiek_med);
    if (!empty($intersect)) {
        $med_check_status = "2"; 
        wachthond($extdebug, 3, "STATUS MEDISCH: Status 2 (Kritiek) wegens: " . implode(', ', $intersect));
    } 
    // 2. Check op Algemene zaken (Status 1)
    elseif (!empty($lists['medisch_longlist']) || !empty(trim((string)$val_medisch_toelichting)) || !empty(trim((string)$val_medisch_medicatie))) {
        $med_check_status = "1";
        $reden = !empty($lists['medisch_longlist']) ? "longlist items" : "tekst-inhoud/medicatie";
        wachthond($extdebug, 3, "STATUS MEDISCH: Status 1 (Check nodig) wegens: " . $reden);
    } else {
        wachthond($extdebug, 5, "STATUS MEDISCH: Status 0 (Geen bijzonderheden).");
    }

    // 3. Dieet Status
    $has_noten = in_array('noten', $lists['dieet_longlist']);
    $dieet_check_status = (!empty($lists['dieet_longlist']) || !empty(trim((string)$val_dieet_toelichting)) || $has_noten) ? "1" : "0";
    
    if ($dieet_check_status == "1") {
        $reden_dieet = $has_noten ? "noten-allergie" : "dieet-informatie aanwezig";
        wachthond($extdebug, 3, "STATUS DIEET: Status 1 wegens: " . $reden_dieet);
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### MEDISCH 7.0. OPSCHONEN & FORMATTEREN",                       "[SMART]");
    wachthond($extdebug, 3, "########################################################################");

    wachthond($extdebug, 3, "START SANITIZING WERK-LIJSTEN...");

    foreach ($lists as $key => &$list) {
        $filtered = array_filter((array)$list, function($v) {
            $val = trim((string)$v);
            return !empty($val) && $val !== '' && strtolower($val) !== 'array';
        });
        $list = array_values(array_unique($filtered));
        wachthond($extdebug, 5, "CLEANUP [$key]: Bevat nu " . count($list) . " items.");
    }    

    $has_med_issues = (!empty($lists['medisch_longlist']) || !empty(trim((string)$val_medisch_toelichting)) || !empty(trim((string)$val_medisch_medicatie))) ? "1" : "0";
    $has_dieet_issues = (!empty($lists['dieet_longlist']) || !empty(trim((string)$val_dieet_toelichting))) ? "1" : "0";

    // WITTE LIJST: Forceer ELK type naar String
    $params_values = [
        'MEDISCH.medisch_issues'           => (string)$has_med_issues,
        'MEDISCH.medisch_toelichting'      => (string)$val_medisch_toelichting,
        'MEDISCH.medisch_medicatie'        => (string)$val_medisch_medicatie,
        'MEDISCH.medisch_luchtwegklachten' => (string)format_civicrm_smart($lists['medisch_luchtwegklachten'], 'MEDISCH.medisch_luchtwegklachten'),
        'MEDISCH.medisch_longlist'         => (string)format_civicrm_smart($lists['medisch_longlist'],         'MEDISCH.medisch_longlist'),
        'MEDISCH.medisch_doublecheck'      => (string)$med_check_status,
        'MEDISCH.dieet_issues'             => (string)$has_dieet_issues,
        'MEDISCH.dieet_shortlist'          => (string)format_civicrm_smart($lists['dieet_shortlist'],          'MEDISCH.dieet_shortlist'),
        'MEDISCH.dieet_longlist'           => (string)format_civicrm_smart($lists['dieet_longlist'],           'MEDISCH.dieet_longlist'),
        'MEDISCH.dieet_toelichting'        => (string)$val_dieet_toelichting,
        'MEDISCH.dieet_doublecheck'        => (string)$dieet_check_status,
        'MEDISCH.medisch_modified'         => date("Y-m-d H:i:s")
    ];

    // TYPE-CHECK: De laatste barrière
    foreach ($params_values as $field_key => $field_val) {
        if (is_array($field_val)) {
            wachthond(3, 3, "CRITICAL ALERT: Veld $field_key was een ARRAY bij $displayname. Geforceerd naar lege string.");
            $params_values[$field_key] = ""; 
        }
    }

    wachthond($extdebug, 3, 'params_values', $params_values);

    wachthond($extdebug, 3, "########################################################################");    
    wachthond($extdebug, 3, "MEDISCH 8.0. FINALE PARAMS VOOR DB/HOOK",                     $displayname);
    wachthond($extdebug, 3, "########################################################################");

    if ($op == 'direct' && !empty($entityID)) {
        $params_update_medisch = [
            'checkPermissions' => FALSE,
            'where'            => [['id', '=', (int)$entityID]],
            'values'           => $params_values,
        ];
        
        wachthond($extdebug, 7, 'params_update_medisch', $params_update_medisch);
        $result_update_medisch = civicrm_api4('Contact', 'update', $params_update_medisch);
        wachthond($extdebug, 9, 'result_update_medisch', $result_update_medisch);
    }

    // Pas nu we klaar zijn met de database, voegen we de resultaten samen voor de Hook-return
    $params = array_merge($params, $params_values);
    drupal_timestamp_sweep($params);

    wachthond($extdebug, 4, 'final_params_medisch', $params);

    // Recursie vlag vrijgeven (Aangepast naar $processing_medisch)
    if (!empty($entityID)) {
        unset($processing_medisch[$entityID]);
    }
    return $params;
}