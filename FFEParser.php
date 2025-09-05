<?php

namespace FFE\Extension;

/**
 * Parser XML FFE vers format Equipe
 * Gère la structure complète du XML FFE avec officiels par épreuve
 */
class FFEXmlParser
{
    private $debugMode = false;

    public function __construct($debugMode = false)
    {
        $this->debugMode = $debugMode;
    }
    /**
     * Détermine les custom fields d'un engagement selon les critères FFE
     */
    private function determineStartCustomFields($engagement, $engagementAttrs)
    {
        $customFields = [
            'engagement_terrain' => false,
            'invitation_organisateur' => false
        ];

        // Logique pour déterminer engagement_terrain
        // Basé sur des critères comme :
        // - Présence d'attribut terrain="true"
        // - Montant d'engagement spécial
        // - Dossard dans une plage spécifique (ex: > 500)

        if (isset($engagementAttrs['terrain']) && (string)$engagementAttrs['terrain'] === 'true') {
            $customFields['engagement_terrain'] = true;
        }

        // Vérifier le dossard - généralement > 500 pour terrain
        $dossard = (int)$engagementAttrs['dossard'];
        if ($dossard > 500) {
            $customFields['engagement_terrain'] = true;
        }

        // Logique pour déterminer invitation_organisateur
        // Peut être basé sur :
        // - Attribut spécifique
        // - Code engageur particulier
        // - Montant d'engagement = 0

        if (isset($engagementAttrs['invitation_organisateur']) && (string)$engagementAttrs['invitation_organisateur'] === 'O') {
            $customFields['invitation_organisateur'] = true;
        }

        // Si montant d'engagement = 0, probablement invitation organisateur
        $montantEng = (float)($engagementAttrs['montant_eng'] ?? 0);
        if ($montantEng == 0) {
            $customFields['invitation_organisateur'] = true;
        }

        return $customFields;
    }
    /**
     * Point d'entrée principal du parsing
     */
    public function parseForEquipe($xmlContent)
    {
        $xml = simplexml_load_string($xmlContent);
        if (!$xml) {
            throw new \Exception("Impossible de parser le XML");
        }

        // Structure de retour
        $data = [
            'concours' => [],
            'competitions' => [],
            'people' => [],      // Cavaliers uniquement
            'officials' => [],   // Officiels uniquement
            'horses' => [],
            'clubs' => [],
            'starts' => []
        ];

        // Déterminer la structure du XML
        // Le XML peut avoir la structure <concours><epreuve> ou directement des <epreuve> à la racine
        $epreuves = [];
        $concoursElement = null;

        // Cas 1: Structure avec <concours> comme racine
        if ($xml->getName() == 'concours') {
            $concoursElement = $xml;
            $epreuves = $xml->epreuve;
            if ($this->debugMode) {
                $this->logDebug("Structure XML: <concours> à la racine");
                $this->logDebug("Nombre d'épreuves trouvées: " . count($epreuves));
            }
        }
        // Cas 2: Structure avec <concours> comme enfant
        elseif (isset($xml->concours)) {
            $concoursElement = $xml->concours;
            $epreuves = $xml->concours->epreuve;
            if ($this->debugMode) {
                $this->logDebug("Structure XML: <concours> comme enfant");
                $this->logDebug("Nombre d'épreuves trouvées: " . count($epreuves));
            }
        }
        // Cas 3: Chercher les épreuves directement
        else {
            // Peut-être que les épreuves sont directement sous la racine
            $epreuves = $xml->xpath('//epreuve');
            if ($this->debugMode) {
                $this->logDebug("Structure XML: recherche xpath des épreuves");
                $this->logDebug("Nombre d'épreuves trouvées: " . count($epreuves));
            }
        }

        // Parser les informations du concours
        if ($concoursElement) {
            $data['concours'] = $this->parseConcoursInfo($concoursElement);
        } else {
            // Essayer de trouver les infos du concours autrement
            $data['concours'] = $this->parseConcoursInfo($xml);
        }

        // Si toujours pas d'épreuves, essayer une autre approche
        if (count($epreuves) == 0) {
            // Peut-être que c'est un seul élément epreuve
            if ($xml->getName() == 'epreuve') {
                $epreuves = [$xml];
                if ($this->debugMode) {
                    $this->logDebug("Structure XML: élément unique <epreuve>");
                }
            }
        }

        if ($this->debugMode) {
            $this->logDebug("Total épreuves à parser: " . count($epreuves));
        }

        // Collections pour dédupliquer
        $peopleMap = [];
        $officialsMap = [];
        $horsesMap = [];
        $clubsMap = [];

        // Parser chaque épreuve
        foreach ($epreuves as $epreuve) {
            if ($this->debugMode) {
                $attrs = $epreuve->attributes();
                $this->logDebug("Parsing épreuve: " . (string)$attrs['num'] . " - " . (string)$attrs['nom_categorie']);
            }

            // Parser la compétition
            $competition = $this->parseCompetition($epreuve, $data['concours']);

            // Parser et collecter les officiels de cette épreuve
            $epreuveOfficials = $this->parseEpreuveOfficials($epreuve);

            // Construire la chaîne domarec_kb pour cette compétition
            if (!empty($epreuveOfficials)) {
                $domarecEntries = [];
                foreach ($epreuveOfficials as $official) {
                    // Format: "Prénom NOM (FRA)"
                    $name = ucfirst(strtolower($official['prenom'])) . ' ' . strtoupper($official['nom']);
                    $domarecEntries[] = $name . ' (FRA)';

                    // Ajouter l'officiel à la collection globale
                    $officialKey = $official['licence'];
                    if (!isset($officialsMap[$officialKey])) {
                        $officialsMap[$officialKey] = $official;
                    }
                }
                // Joindre avec des virgules pour créer la chaîne domarec_kb
                $competition['domarec_kb'] = implode(', ', $domarecEntries);
            }

            $data['competitions'][] = $competition;

            // Parser les engagements de cette épreuve
            $competitionForeignId = $competition['foreign_id'];
            $data['starts'][$competitionForeignId] = [];

            $engagementCount = 0;
            foreach ($epreuve->engagement as $engagement) {
                $engagementCount++;

                // Parser cavalier, cheval, club et engagement
                $engagementData = $this->parseEngagement($engagement);

                // Collecter le cavalier
                if ($engagementData['person']) {
                    $personKey = $engagementData['person']['lic'];
                    if (!isset($peopleMap[$personKey])) {
                        $peopleMap[$personKey] = $engagementData['person'];
                    }
                }

                // Collecter le cheval
                if ($engagementData['horse']) {
                    $horseKey = $engagementData['horse']['sire'];
                    if (!isset($horsesMap[$horseKey])) {
                        $horsesMap[$horseKey] = $engagementData['horse'];
                    }
                }

                // Collecter le club
                if ($engagementData['club']) {
                    $clubKey = $engagementData['club']['num'];
                    if (!isset($clubsMap[$clubKey])) {
                        $clubsMap[$clubKey] = $engagementData['club'];
                    }
                }

                // Ajouter l'engagement à la compétition
                $data['starts'][$competitionForeignId][] = $engagementData['start'];
            }

            if ($this->debugMode) {
                $this->logDebug("  - Engagements parsés: " . $engagementCount);
            }
        }

        // Convertir les maps en arrays
        $data['people'] = array_values($peopleMap);
        $data['officials'] = array_values($officialsMap);
        $data['horses'] = array_values($horsesMap);
        $data['clubs'] = array_values($clubsMap);
        if ($this->debugMode) {
            // Debug des custom fields
            $terrainCount = 0;
            $invitationCount = 0;
            $engageurCount = 0;

            foreach ($data['starts'] as $starts) {
                foreach ($starts as $start) {
                    if (!empty($start['start_custom_fields']['engagement_terrain'])) {
                        $terrainCount++;
                    }
                    if (!empty($start['start_custom_fields']['invitation_organisateur'])) {
                        $invitationCount++;
                    }
                    if (!empty($start['rider_custom_fields']['compte_engageur'])) {
                        $engageurCount++;
                    }
                }
            }

            $this->logDebug("- Engagements terrain: " . $terrainCount);
            $this->logDebug("- Invitations organisateur: " . $invitationCount);
            $this->logDebug("- Avec compte engageur: " . $engageurCount);
        }
        if ($this->debugMode) {
            $this->logDebug("=== Parsing terminé ===");
            $this->logDebug("- Compétitions: " . count($data['competitions']));
            $this->logDebug("- Cavaliers: " . count($data['people']));
            $this->logDebug("- Officiels: " . count($data['officials']));
            $this->logDebug("- Chevaux: " . count($data['horses']));
            $this->logDebug("- Clubs: " . count($data['clubs']));
            $this->logDebug("- Total engagements: " . array_sum(array_map('count', $data['starts'])));

            // NOUVEAU : Debug des custom fields
            $terrainCount = 0;
            $invitationCount = 0;
            foreach ($data['starts'] as $starts) {
                foreach ($starts as $start) {
                    if (isset($start['start_custom_fields']['engagement_terrain']) && $start['start_custom_fields']['engagement_terrain']) {
                        $terrainCount++;
                    }
                    if (isset($start['start_custom_fields']['invitation_organisateur']) && $start['start_custom_fields']['invitation_organisateur']) {
                        $invitationCount++;
                    }
                }
            }
            $this->logDebug("- Engagements terrain: " . $terrainCount);
            $this->logDebug("- Invitations organisateur: " . $invitationCount);
        }

        return $data;
    }

    /**
     * Parse les informations générales du concours
     */
    private function parseConcoursInfo($element)
    {
        // Récupérer les attributs du concours
        $attrs = $element->attributes();

        $concoursInfo = [
            'num_ffe' => (string)$attrs['num'],
            'nom' => $this->cleanString((string)$attrs['nom']),
            'departement' => (string)$attrs['departement'],
            'date_debut' => (string)$attrs['date_debut'],
            'date_fin' => (string)$attrs['date_fin'],
            'organisateur' => []
        ];

        // Parser l'organisateur s'il existe
        if (isset($element->organisateur)) {
            $orgAttrs = $element->organisateur->attributes();
            $concoursInfo['organisateur'] = [
                'num' => (string)$orgAttrs['num'],
                'nom' => $this->cleanString((string)$orgAttrs['nom'])
            ];
        }

        if ($this->debugMode) {
            $this->logDebug("Concours info: " . $concoursInfo['nom'] . " (" . $concoursInfo['num_ffe'] . ")");
        }

        return $concoursInfo;
    }
    private function mapProtocoleToJudgementId($idProtocole)
    {
        $mapping = [
            '1218' => 11227,
            '1217' => 10029,
            '1400' => 11245,
            '1213' => 10028,
            '1212' => 11246,
            '1287' => 10027,
            '1220' => 10011,
            '1401' => 10018,
            '1216' => 10013,
            '1215' => 10014,
            '1415' => 10010,
            '1416' => 10009,
            '1418' => 10031,
            '1417' => 10017,
            '1425' => 12996,
            '1422' => 10038,
            '1424' => 10397,
            '1430' => 10035,
            '1432' => 10036,
            '1226' => 10005,
            '1228' => 13129,
            '1421' => 10045,
            '1420' => 10044
        ];

        $idStr = (string)$idProtocole;

        if (isset($mapping[$idStr])) {
            if ($this->debugMode) {
                $this->logDebug("  - Protocole FFE $idStr mappé vers judgement_id: " . $mapping[$idStr]);
            }
            return $mapping[$idStr];
        }

        if ($this->debugMode) {
            $this->logDebug("  - Protocole FFE $idStr non trouvé dans le mapping");
        }

        return null; // Retourne null si pas de mapping trouvé
    }
    /**
     * Parse une compétition (épreuve)
     */
    private function parseCompetition($epreuve, $concoursInfo)
    {
        $attrs = $epreuve->attributes();

        $concoursNum = $concoursInfo['num_ffe'] ?? '';
        $epreuveNum = (string)$attrs['num'];
        $foreignId = $concoursNum . '_' . $epreuveNum;

        // Récupérer l'ID protocole et mapper vers judgement_id
        $idProtocole = (string)$attrs['id_protocole_version'];
        $judgementId = $this->mapProtocoleToJudgementId($idProtocole);

        $competition = [
            'foreign_id' => $foreignId,
            'clabb' => (string)$attrs['num'],
            'klass' => $this->cleanString((string)$attrs['nom_categorie']),
            'nom_categorie' => $this->cleanString((string)$attrs['nom_categorie']),
            'datum' => (string)$attrs['date'],
            'heure_debut' => (string)$attrs['heure_debut'],
            'x' => 'I', // Niveau par défaut
            'z' => $this->mapDisciplineCode((string)$attrs['discipline']),

            // Montants
            'anm' => (float)$attrs['montant_eng'],
            'prsum1' => (float)$attrs['dotation_epreuve'],

            'discipline' => (string)$attrs['discipline'],
            'discipline_ffe' => (string)$attrs['discipline'],
            'discipline_libelle' => $this->cleanString((string)$attrs['discipline_libelle']),
            'categorie' => (string)$attrs['categorie'],
            'code_bareme' => (string)$attrs['code_bareme'],
            'nom_bareme' => $this->cleanString((string)$attrs['nom_bareme']),
            'montant_eng' => (float)$attrs['montant_eng'],
            'dotation_epreuve' => (float)$attrs['dotation_epreuve'],
            'nbr_engages' => (int)$attrs['nbr_engages'],
            'team_class' => ((string)$attrs['epreuve_equipe'] === 'O'),
            'id_protocole_version' => $idProtocole,

            // Infos du concours pour référence
            'concours_num_ffe' => $concoursNum,
            'epreuve_num' => $epreuveNum
        ];

        // Ajouter judgement_id seulement si on a trouvé un mapping
        if ($judgementId !== null) {
            $competition['judgement_id'] = $judgementId;
        }

        if ($this->debugMode) {
            $this->logDebug("Épreuve configurée avec:");
            $this->logDebug("  - ID protocole FFE: " . $idProtocole);
            if ($judgementId !== null) {
                $this->logDebug("  - Judgement ID Equipe: " . $judgementId);
            } else {
                $this->logDebug("  - Pas de judgement_id (protocole non mappé)");
            }
        }

        return $competition;
    }
    /**
     * Parse les officiels d'une épreuve
     */
    private function parseEpreuveOfficials($epreuve)
    {
        $officials = [];

        if (isset($epreuve->profil->officiels)) {
            foreach ($epreuve->profil->officiels->officiel as $officiel) {
                $attrs = $officiel->attributes();

                // Ignorer les officiels sans licence
                $licence = (string)$attrs['licence'];
                if (empty($licence)) {
                    continue;
                }

                $officials[] = [
                    'nom' => $this->cleanString((string)$attrs['nom']),
                    'prenom' => $this->cleanString((string)$attrs['prenom']),
                    'licence' => $licence,
                    'nom_fonction' => $this->cleanString((string)$attrs['nom_fonction']),
                    'code_fonction' => (string)$attrs['code_fonction'],
                    'niv_min' => (string)$attrs['niv_min'],
                    'nb_min' => (int)$attrs['nb_min'],
                    'nb_max' => (int)$attrs['nb_max'],
                    'obl_resus' => (int)$attrs['obl_resus']
                ];
            }
        }

        return $officials;
    }

    /**
     * Parse un engagement complet
     */
    private function parseEngagement($engagement)
    {
        $result = [
            'person' => null,
            'horse' => null,
            'club' => null,
            'start' => null
        ];

        // Parser le cavalier
        if (isset($engagement->cavalier)) {
            $cavalier = $engagement->cavalier->attributes();

            $result['person'] = [
                'lic' => (string)$cavalier['lic'],
                'nom' => $this->cleanString((string)$cavalier['nom']),
                'prenom' => $this->cleanString((string)$cavalier['prenom']),
                'dnaiss' => (string)$cavalier['dnaiss'],
                'titre_cavalier' => (string)$cavalier['titre_cavalier'],
                'numero_fei' => (string)$cavalier['numero_fei'],
                'categorie' => (string)$cavalier['categorie'],
                'code_age' => (string)$cavalier['code_age'],
                'libelle_age' => $this->cleanString((string)$cavalier['libelle_age']),
                'club' => (string)$cavalier['club'],
                'nom_club' => $this->cleanString((string)$cavalier['nom_club']),
                'cre' => (string)$cavalier['cre'],
                'region' => (string)$cavalier['region'],
                'nom_region' => $this->cleanString((string)$cavalier['nom_region']),
                'departement_cavalier' => (string)$cavalier['departement_cavalier'],
                'nom_departement_cavalier' => $this->cleanString((string)$cavalier['nom_departement_cavalier'])
            ];

            // NOUVEAU : Ajouter les custom fields pour le cavalier
            $riderCustomFields = [];

            // Parser l'engageur si présent
            if (isset($engagement->engageur)) {
                $engageur = $engagement->engageur->attributes();
                $engageurNum = (string)$engageur['num'];
                $engageurType = (string)$engageur['type'];

                // Ajouter le compte engageur
                $riderCustomFields['compte_engageur'] = $engageurNum;

                // Si type_engageur = "licence", ajouter aussi licence_engageur
                if ($engageurType === 'licence') {
                    $riderCustomFields['licence_engageur'] = $engageurNum;
                }
            }

            // Ajouter les custom fields au cavalier si il y en a
            if (!empty($riderCustomFields)) {
                $result['person']['rider_custom_fields'] = $riderCustomFields;
            }

            // Parser le club si présent
            if (!empty($cavalier['club'])) {
                $result['club'] = [
                    'num' => (string)$cavalier['club'],
                    'nom' => $this->cleanString((string)$cavalier['nom_club']),
                    'cre' => (string)$cavalier['cre'],
                    'region' => (string)$cavalier['region'],
                    'nom_region' => $this->cleanString((string)$cavalier['nom_region']),
                    'departement' => (string)$cavalier['departement_cavalier']
                ];
            }
        }

        // Parser le cheval
        if (isset($engagement->equide)) {
            $equide = $engagement->equide->attributes();

            $result['horse'] = [
                'sire' => (string)$equide['sire'],
                'nom' => $this->cleanString((string)$equide['nom']),
                'dnaiss' => (string)$equide['dnaiss'],
                'equide_age' => (int)$equide['equide_age'],
                'taille' => (string)$equide['taille'],
                'race' => $this->cleanString((string)$equide['race']),
                'code_race' => (string)$equide['code_race'],
                'robe' => $this->cleanString((string)$equide['robe']),
                'code_robe' => (string)$equide['code_robe'],
                'sexe' => $this->cleanString((string)$equide['sexe']),
                'transpondeur' => (string)$equide['transpondeur'],
                'equide_fei' => (string)$equide['equide_fei'],
                'passeport_fei' => (string)$equide['passeport_fei'],
                'enregistrement_fei' => (string)$equide['enregistrement_fei'],
                'equide_gain' => (float)$equide['equide_gain'],
                'equide_code_pays' => (string)$equide['equide_code_pays'],
                'equide_libelle_pays' => $this->cleanString((string)$equide['equide_libelle_pays']),
                'eleveur' => $this->cleanString((string)$equide['eleveur']),
                'proprietaire' => $this->cleanString((string)$equide['proprietaire'])
            ];

            // Ajouter la généalogie
            if (isset($engagement->equide->pere)) {
                $pere = $engagement->equide->pere->attributes();
                $result['horse']['pere'] = [
                    'nom' => $this->cleanString((string)$pere['nom']),
                    'race_code' => (string)$pere['race_code'],
                    'race' => $this->cleanString((string)$pere['race'])
                ];
            }

            if (isset($engagement->equide->mere)) {
                $mere = $engagement->equide->mere->attributes();
                $result['horse']['mere'] = [
                    'nom' => $this->cleanString((string)$mere['nom']),
                    'race_code' => (string)$mere['race_code'],
                    'race' => $this->cleanString((string)$mere['race'])
                ];

                if (isset($engagement->equide->mere->pere)) {
                    $merePere = $engagement->equide->mere->pere->attributes();
                    $result['horse']['mere']['pere'] = [
                        'nom' => $this->cleanString((string)$merePere['nom']),
                        'race_code' => (string)$merePere['race_code'],
                        'race' => $this->cleanString((string)$merePere['race'])
                    ];
                }
            }
        }

        // Parser l'engagement lui-même
        $engagementAttrs = $engagement->attributes();
        $result['start'] = [
            'cavalier_lic' => (string)$engagement->cavalier['lic'],
            'equide_sire' => (string)$engagement->equide['sire'],
            'dossard' => (int)$engagementAttrs['dossard'],
            'hors_classement' => ((string)$engagementAttrs['hors_classement'] === '1'),
            'role' => (string)$engagementAttrs['role'],
            'iperf_couple' => (string)$engagementAttrs['iperf_couple']
        ];

        // NOUVEAU : Ajouter les start custom fields
        $customFields = [
            // Start custom fields
            'start_custom_fields' => [
                'engagement_terrain' => false,  // Par défaut false
                'invitation_organisateur' => false  // Par défaut false
            ],

            // Rider custom fields
            'rider_custom_fields' => [],

            // Horse custom fields  
            'horse_custom_fields' => []
        ];

        // Déterminer engagement_terrain selon critères
        if (isset($engagementAttrs['terrain']) && (string)$engagementAttrs['terrain'] === 'true') {
            $customFields['start_custom_fields']['engagement_terrain'] = true;
        }

        // Vérifier le dossard - généralement > 500 pour terrain
        $dossard = (int)$engagementAttrs['dossard'];
        if ($dossard > 500) {
            $customFields['start_custom_fields']['engagement_terrain'] = true;
        }

        // Parser l'engageur pour rider_custom_fields
        if (isset($engagement->engageur)) {
            $engageur = $engagement->engageur->attributes();
            $engageurNum = (string)$engageur['num'];
            $engageurType = (string)$engageur['type'];

            // Ajouter le compte engageur
            $customFields['rider_custom_fields']['compte_engageur'] = $engageurNum;

            // Si type_engageur = "licence", ajouter aussi licence_engageur
            if ($engageurType === 'licence') {
                $customFields['rider_custom_fields']['licence_engageur'] = $engageurNum;
            }
        }

        // Fusionner tous les custom fields dans le start
        $result['start'] = array_merge($result['start'], $customFields);

        // Ajouter coach si présent
        if (isset($engagement->coach) && !empty((string)$engagement->coach['lic'])) {
            $result['start']['coach_lic'] = (string)$engagement->coach['lic'];
            $result['start']['coach_nom'] = $this->cleanString((string)$engagement->coach['nom']);
            $result['start']['coach_prenom'] = $this->cleanString((string)$engagement->coach['prenom']);
        }

        // Ajouter engageur si présent
        if (isset($engagement->engageur)) {
            $engageur = $engagement->engageur->attributes();
            $result['start']['engageur_type'] = (string)$engageur['type_engageur'];
            $result['start']['engageur_nom'] = $this->cleanString((string)$engageur['nom']);
            $result['start']['engageur_prenom'] = $this->cleanString((string)$engageur['prenom']);
            $result['start']['engageur_num'] = (string)$engageur['num'];
        }

        return $result;
    }

    /**
     * Mappe le code discipline FFE vers le code Equipe
     */
    private function mapDisciplineCode($disciplineFFE)
    {
        $mapping = [
            '01' => 'H', // Saut d'obstacles
            '02' => 'H', // Hunter
            '03' => 'D', // Dressage
            '04' => 'F', // CCE
            '05' => 'E', // Endurance
            '06' => 'A', // Attelage
            '07' => 'V', // Voltige
            '08' => 'R', // Reining
            '09' => 'W', // Western
            '10' => 'P', // Pony Games
            '11' => 'T', // TREC
        ];

        return isset($mapping[$disciplineFFE]) ? $mapping[$disciplineFFE] : 'H';
    }

    /**
     * Nettoie une chaîne de caractères
     */
    private function cleanString($text)
    {
        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // Supprimer les espaces en trop
        $text = trim($text);
        return $text;
    }

    /**
     * Log pour debug
     */
    private function logDebug($message)
    {
        if ($this->debugMode) {
            error_log("[FFEParser] " . $message);
        }
    }
}
class FFEResultFormatter
{
    private $debugMode = false;

    public function __construct($debugMode = false)
    {
        $this->debugMode = $debugMode;
    }

    /**
     * Formate les résultats depuis Equipe vers le format TXT FFE
     * @param array $competition Données de la compétition
     * @param array $results Résultats depuis Equipe
     * @param string $discipline Code discipline FFE (01, 03, etc.)
     * @return string Contenu du fichier résultat formaté
     */
    public function formatResults($competition, $results, $discipline)
    {
        $output = [];

        // En-tête du fichier
        $output[] = $this->generateHeader($competition);

        // Formater selon la discipline
        switch ($discipline) {
            case '01': // Saut d'obstacles
                $output[] = $this->formatObstacleResults($results);
                break;
            case '02': // Hunter
                $output[] = $this->formatObstacleResults($results);
                break;
            case '03': // Dressage
                $output[] = $this->formatDressageResults($results);
                break;
            case '04': // CCE
                $output[] = $this->formatCCEResults($results);
                break;
            case '05': // Endurance
                $output[] = $this->formatEnduranceResults($results);
                break;
            default:
                $output[] = $this->formatGenericResults($results);
        }

        // Pied de page avec statistiques
        $output[] = $this->generateFooter($results);

        return implode("\r\n", $output);
    }

    /**
     * Génère l'en-tête du fichier résultat
     */
    private function generateHeader($competition)
    {
        $header = [];
        $header[] = "** RESULTATS FFE **";
        $header[] = str_repeat('=', 80);
        $header[] = "Epreuve: " . $competition['klass'];
        $header[] = "N° Epreuve: " . $competition['clabb'];
        $header[] = "Date: " . $this->formatDate($competition['datum']);
        $header[] = "Catégorie: " . ($competition['categorie'] ?? '');
        $header[] = str_repeat('-', 80);

        return implode("\r\n", $header);
    }

    /**
     * Formate les résultats pour le saut d'obstacles
     */
    private function formatObstacleResults($results)
    {
        $output = [];

        // En-tête du tableau
        $output[] = sprintf(
            "%-4s %-4s %-30s %-25s %-8s %-8s %-10s",
            "CLA",
            "DOS",
            "CAVALIER",
            "CHEVAL",
            "PTS",
            "TEMPS",
            "GAINS"
        );
        $output[] = str_repeat('-', 100);

        // Trier les résultats par classement
        usort($results, function ($a, $b) {
            $aRank = $this->getEffectiveRank($a);
            $bRank = $this->getEffectiveRank($b);

            if ($aRank == $bRank) {
                return ((int)$a['st'] ?? 0) - ((int)$b['st'] ?? 0);
            }

            // Les non-classés à la fin
            if ($aRank == 999) return 1;
            if ($bRank == 999) return -1;

            return $aRank - $bRank;
        });

        foreach ($results as $result) {
            $classement = $this->formatRank($result);
            $points = $this->formatFaults($result);
            $temps = $this->formatTime($result['grundt'] ?? null);
            $gains = $this->formatPrizeMoney($result['premie'] ?? 0);

            $line = sprintf(
                "%-4s %-4s %-30s %-25s %-8s %-8s %-10s",
                $classement,
                $result['st'] ?? '',
                $this->formatRiderName($result),
                $this->formatHorseName($result),
                $points,
                $temps,
                $gains
            );

            $output[] = $line;
        }

        return implode("\r\n", $output);
    }

    /**
     * Formate les résultats pour le dressage
     */
    private function formatDressageResults($results)
    {
        $output = [];

        // En-tête du tableau
        $output[] = sprintf(
            "%-4s %-4s %-30s %-25s %-10s %-8s",
            "CLA",
            "DOS",
            "CAVALIER",
            "CHEVAL",
            "TOTAL",
            "%"
        );
        $output[] = str_repeat('-', 100);

        // Trier par pourcentage décroissant
        usort($results, function ($a, $b) {
            $aPercent = (float)($a['procent'] ?? $a['pourcentage'] ?? 0);
            $bPercent = (float)($b['procent'] ?? $b['pourcentage'] ?? 0);
            return $bPercent <=> $aPercent;
        });

        $rank = 1;
        foreach ($results as $result) {
            // Calculer le classement basé sur le pourcentage
            if (isset($result['procent']) || isset($result['pourcentage'])) {
                $classement = (string)$rank++;
            } else {
                $classement = $this->formatRank($result);
            }

            $total = number_format($result['poang'] ?? $result['note_totale'] ?? 0, 2, '.', '');
            $percent = number_format($result['procent'] ?? $result['pourcentage'] ?? 0, 2, '.', '') . '%';

            $line = sprintf(
                "%-4s %-4s %-30s %-25s %-10s %-8s",
                $classement,
                $result['st'] ?? '',
                $this->formatRiderName($result),
                $this->formatHorseName($result),
                $total,
                $percent
            );

            $output[] = $line;
        }

        return implode("\r\n", $output);
    }

    /**
     * Formate les résultats pour le CCE
     */
    private function formatCCEResults($results)
    {
        $output = [];

        $output[] = sprintf(
            "%-4s %-4s %-30s %-25s %-10s %-10s %-10s",
            "CLA",
            "DOS",
            "CAVALIER",
            "CHEVAL",
            "DRESSAGE",
            "CSO",
            "CROSS"
        );
        $output[] = str_repeat('-', 110);

        foreach ($results as $result) {
            $line = sprintf(
                "%-4s %-4s %-30s %-25s %-10s %-10s %-10s",
                $this->formatRank($result),
                $result['st'] ?? '',
                $this->formatRiderName($result),
                $this->formatHorseName($result),
                number_format($result['dressage_points'] ?? 0, 2, '.', ''),
                number_format($result['jumping_faults'] ?? 0, 2, '.', ''),
                number_format($result['cross_faults'] ?? 0, 2, '.', '')
            );

            $output[] = $line;
        }

        return implode("\r\n", $output);
    }

    /**
     * Formate les résultats pour l'endurance
     */
    private function formatEnduranceResults($results)
    {
        $output = [];

        $output[] = sprintf(
            "%-4s %-4s %-30s %-25s %-10s %-10s",
            "CLA",
            "DOS",
            "CAVALIER",
            "CHEVAL",
            "TEMPS",
            "VITESSE"
        );
        $output[] = str_repeat('-', 100);

        foreach ($results as $result) {
            $temps = $this->formatEnduranceTime($result['total_time'] ?? null);
            $vitesse = number_format($result['speed'] ?? 0, 2, '.', '') . ' km/h';

            $line = sprintf(
                "%-4s %-4s %-30s %-25s %-10s %-10s",
                $this->formatRank($result),
                $result['st'] ?? '',
                $this->formatRiderName($result),
                $this->formatHorseName($result),
                $temps,
                $vitesse
            );

            $output[] = $line;
        }

        return implode("\r\n", $output);
    }

    /**
     * Formate les résultats génériques
     */
    private function formatGenericResults($results)
    {
        $output = [];

        $output[] = sprintf(
            "%-4s %-4s %-30s %-25s %-15s",
            "CLA",
            "DOS",
            "CAVALIER",
            "CHEVAL",
            "STATUT"
        );
        $output[] = str_repeat('-', 90);

        foreach ($results as $result) {
            $statut = $this->getStatus($result);

            $line = sprintf(
                "%-4s %-4s %-30s %-25s %-15s",
                $this->formatRank($result),
                $result['st'] ?? '',
                $this->formatRiderName($result),
                $this->formatHorseName($result),
                $statut
            );

            $output[] = $line;
        }

        return implode("\r\n", $output);
    }

    /**
     * Génère le pied de page avec statistiques
     */
    private function generateFooter($results)
    {
        $stats = $this->calculateStatistics($results);

        $footer = [];
        $footer[] = str_repeat('-', 80);
        $footer[] = "STATISTIQUES:";
        $footer[] = "Partants: " . $stats['partants'];
        $footer[] = "Classés: " . $stats['classes'];
        $footer[] = "Non-partants: " . $stats['non_partants'];
        $footer[] = "Éliminés: " . $stats['elimines'];
        $footer[] = "Abandons: " . $stats['abandons'];
        $footer[] = str_repeat('=', 80);
        $footer[] = "** FIN DES RESULTATS **";
        $footer[] = "Généré le " . date('d/m/Y à H:i:s');

        return implode("\r\n", $footer);
    }

    /**
     * Calcule les statistiques des résultats
     */
    private function calculateStatistics($results)
    {
        $stats = [
            'partants' => 0,
            'classes' => 0,
            'non_partants' => 0,
            'elimines' => 0,
            'abandons' => 0
        ];

        foreach ($results as $result) {
            // Compter les partants
            if (!isset($result['a']) || $result['a'] !== 'U') {
                $stats['partants']++;
            } else {
                $stats['non_partants']++;
            }

            // Compter les classés
            if (isset($result['re']) && $result['re'] > 0 && $result['re'] < 999) {
                $stats['classes']++;
            }

            // Compter les éliminés
            if (isset($result['or']) && $result['or'] === 'D') {
                $stats['elimines']++;
            }

            // Compter les abandons
            if (isset($result['or']) && $result['or'] === 'U') {
                $stats['abandons']++;
            }
        }

        $stats['total'] = count($results);

        return $stats;
    }

    /**
     * Formate le classement
     */
    private function formatRank($result)
    {
        // Non-partant
        if (isset($result['a']) && $result['a'] === 'U') {
            return 'NP';
        }

        // Éliminé
        if (isset($result['or']) && $result['or'] === 'D') {
            return 'EL';
        }

        // Abandon
        if (isset($result['or']) && $result['or'] === 'U') {
            return 'AB';
        }

        // Disqualifié
        if (isset($result['d']) && $result['d'] === 'U') {
            return 'DQ';
        }

        // Classement normal
        if (isset($result['re']) && $result['re'] > 0 && $result['re'] < 999) {
            return (string)$result['re'];
        }

        // Non classé
        return 'NC';
    }

    /**
     * Obtient le rang effectif pour le tri
     */
    private function getEffectiveRank($result)
    {
        if (isset($result['a']) && $result['a'] === 'U') return 998;
        if (isset($result['or']) && ($result['or'] === 'D' || $result['or'] === 'U')) return 999;
        if (isset($result['d']) && $result['d'] === 'U') return 997;

        return isset($result['re']) ? (int)$result['re'] : 999;
    }

    /**
     * Formate le nom du cavalier
     */
    private function formatRiderName($result)
    {
        $firstName = ucfirst(strtolower($result['rider_first_name'] ?? $result['first_name'] ?? ''));
        $lastName = strtoupper($result['rider_last_name'] ?? $result['last_name'] ?? '');

        $name = trim($firstName . ' ' . $lastName);

        // Limiter à 30 caractères
        if (strlen($name) > 30) {
            $name = substr($name, 0, 27) . '...';
        }

        return $name;
    }

    /**
     * Formate le nom du cheval
     */
    private function formatHorseName($result)
    {
        $name = $result['horse_name'] ?? $result['horse'] ?? '';

        // Limiter à 25 caractères
        if (strlen($name) > 25) {
            $name = substr($name, 0, 22) . '...';
        }

        return strtoupper($name);
    }

    /**
     * Formate les points/fautes
     */
    private function formatFaults($result)
    {
        $faults = $result['grundf'] ?? $result['faults'] ?? 0;

        if ($faults >= 999) {
            return 'ELIM';
        }

        return number_format($faults, 2, '.', '');
    }

    /**
     * Formate le temps
     */
    private function formatTime($time)
    {
        if (!$time || $time >= 999) {
            return '';
        }

        return number_format($time, 2, '.', '') . 's';
    }

    /**
     * Formate le temps d'endurance (HH:MM:SS)
     */
    private function formatEnduranceTime($seconds)
    {
        if (!$seconds) {
            return '';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Formate les gains
     */
    private function formatPrizeMoney($amount)
    {
        if ($amount <= 0) {
            return '';
        }

        return number_format($amount, 2, ',', ' ') . ' €';
    }

    /**
     * Formate une date
     */
    private function formatDate($date)
    {
        if (!$date) {
            return '';
        }

        $timestamp = strtotime($date);
        return date('d/m/Y', $timestamp);
    }

    /**
     * Obtient le statut textuel
     */
    private function getStatus($result)
    {
        if (isset($result['a']) && $result['a'] === 'U') {
            return 'Non-partant';
        }

        if (isset($result['or'])) {
            switch ($result['or']) {
                case 'D':
                    return 'Éliminé';
                case 'U':
                    return 'Abandon';
            }
        }

        if (isset($result['d']) && $result['d'] === 'U') {
            return 'Disqualifié';
        }

        if (isset($result['re']) && $result['re'] > 0 && $result['re'] < 999) {
            return 'Classé';
        }

        return 'Terminé';
    }
}
