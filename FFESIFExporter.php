<?php
// En début de fichier

ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
mb_regex_encoding('UTF-8');
/**
 * FFESIFExporter.php
 * Classe pour l'export des données au format WinJump FFE 1.5 - VERSION FINALE
 */

class FFESIFExporter
{
    private $apiKey;
    private $meetingUrl;
    private $debugMode;
    /**
     * Fonction pour corriger l'encodage UTF-8 (double-encodage)
     */
    private function fixUtf8($text)
    {
        if (empty($text)) return $text;

        return str_replace([
            'Ã©',
            'Ã¨',
            'Ã ',
            'Ã¢',
            'Ã´',
            'Ã»',
            'Ã¼',
            'Ã«',
            'Ã¯',
            'Ã§',
            'Ã¹',
            'Ã®',
            'Ã¡',
            'Ã³',
            'Ã±',
            'Ãª'
        ], [
            'é',
            'è',
            'à',
            'â',
            'ô',
            'û',
            'ü',
            'ë',
            'ï',
            'ç',
            'ù',
            'î',
            'á',
            'ó',
            'ñ',
            'ê'
        ], $text);
    }
    private function normalizeText($text)
    {
        if (empty($text)) return $text;

        // Patterns de double-encodage UTF-8 courants
        $patterns = [
            'Ã©' => 'é',
            'Ã¨' => 'è',
            'Ã ' => 'à',
            'Ã¢' => 'â',
            'Ã´' => 'ô',
            'Ã»' => 'û',
            'Ã¼' => 'ü',
            'Ã«' => 'ë',
            'Ã¯' => 'ï',
            'Ã§' => 'ç',
            'Ã¹' => 'ù',
            'Ã®' => 'î',
            'Ã¡' => 'á',
            'Ã³' => 'ó',
            'Ã±' => 'ñ',
            'Ãª' => 'ê'
        ];

        return str_replace(array_keys($patterns), array_values($patterns), trim($text));
    }


    public function __construct($apiKey, $meetingUrl, $debugMode = false)
    {
        $this->apiKey = $apiKey;
        $this->meetingUrl = rtrim($meetingUrl, '/');
        $this->debugMode = $debugMode;
    }

    /**
     * Point d'entrée principal pour l'export SIF
     */
    public function export($competitionId)
    {
        if (empty($this->apiKey) || empty($this->meetingUrl) || empty($competitionId)) {
            return ['success' => false, 'error' => 'API Key, URL ou ID compétition manquant'];
        }

        try {
            // Récupérer toutes les données nécessaires
            $data = $this->fetchAllCompetitionData($competitionId);

            if (!$data['competition'] || !$data['results']) {
                return ['success' => false, 'error' => 'Données de compétition non trouvées'];
            }

            $this->logDebug("=== Export SIF WinJump FINAL ===");
            $this->logDebug("Competition: " . json_encode($data['competition']));
            $this->logDebug("Results count: " . count($data['results']));
            $this->logDebug("First result: " . json_encode($data['results'][0] ?? []));

            // Générer le XML WinJump FFE
            $xmlContent = $this->generateWinJumpFFEXML(
                $data['competition'],
                $data['results'],
                $data['people'],
                $data['horses']
            );

            $filename = 'winjump_' . ($data['competition']['clabb'] ?? $competitionId) . '_' . date('YmdHis') . '.xml';

            return [
                'success' => true,
                'filename' => $filename,
                'content' => base64_encode(mb_convert_encoding($xmlContent, 'UTF-8', 'auto'))
            ];
        } catch (Exception $e) {
            $this->logDebug("Error in FFESIFExporter: " . $e->getMessage() . " - " . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Récupération de toutes les données de la compétition - URLs FINALES CORRIGÉES
     */
    private function fetchAllCompetitionData($competitionId)
    {
        // Extraire meetingId depuis l'URL de base
        // $this->meetingUrl contient https://app-staging.equipe.com/meetings/1274
        $urlParts = parse_url($this->meetingUrl);
        $pathParts = explode('/', trim($urlParts['path'], '/'));
        $meetingId = end($pathParts); // Récupère 1274

        $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . '/meetings/' . $meetingId;

        $endpoints = [
            'competition' => "/competitions.json", // Liste des compétitions
            'results' => "/competitions/{$competitionId}/results.json", // Résultats spécifiques
            'people' => "/people.json",
            'horses' => "/horses.json"
        ];

        $data = [];
        foreach ($endpoints as $key => $endpoint) {
            $url = $baseUrl . $endpoint;
            $this->logDebug("Fetching: " . $url);
            $response = $this->fetchEquipeData($url);

            if (!$response) {
                $this->logDebug("Failed to fetch " . $key . " from: " . $url);
                $data[$key] = [];
            } else {
                if ($key === 'competition') {
                    // Chercher la compétition spécifique dans la liste
                    if (is_array($response)) {
                        $competition = null;
                        foreach ($response as $comp) {
                            if (isset($comp['kq']) && $comp['kq'] == $competitionId) {
                                $competition = $comp;
                                break;
                            }
                        }
                        $data[$key] = $competition ?? [];
                        $this->logDebug("Found competition: " . ($competition ? 'YES' : 'NO'));
                        if ($competition) {
                            $this->logDebug("Competition data: " . json_encode($competition));
                        }
                    } else {
                        $data[$key] = $response;
                    }
                } else {
                    $data[$key] = $response;
                    $this->logDebug("Successfully fetched " . $key . " - " . (is_array($response) ? count($response) : 'single item') . " items");
                }
            }
        }

        return $data;
    }

    /**
     * Récupération des données depuis l'API Equipe
     */
    private function fetchEquipeData($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Api-Key: " . $this->apiKey,
            "Accept: application/json",
            "Accept-Charset: utf-8"  // NOUVEAU : Forcer l'acceptance UTF-8
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // NOUVEAU : Forcer l'encodage UTF-8 dans cURL
        curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logDebug("Failed to fetch data from: $url (HTTP $httpCode)");
            return null;
        }

        // DEBUG : Vérifier l'encodage de la réponse
        $this->logDebug("Response encoding: " . mb_detect_encoding($response));
        $this->logDebug("First 200 chars of response: " . substr($response, 0, 200));

        // CORRECTION : Ajouter le flag JSON_UNESCAPED_UNICODE
        $decoded = json_decode($response, true, 512, JSON_UNESCAPED_UNICODE);
        if (isset($decoded[0]['klass'])) {
            $decoded[0]['klass'] = mb_convert_encoding($decoded[0]['klass'], 'UTF-8', 'UTF-8');
        }
        // DEBUG : Vérifier ce qui est décodé
        if ($decoded && isset($decoded[0]['klass'])) {
            $this->logDebug("Decoded klass value: " . $decoded[0]['klass']);
            $this->logDebug("Klass encoding: " . mb_detect_encoding($decoded[0]['klass']));
        }

        return $decoded;
    }
    /**
     * Génération du XML WinJump FFE - VERSION FINALE CORRIGÉE
     */
    /**
     * Génération du XML WinJump FFE - VERSION CORRIGÉE UTF-8
     */
    /**
     * Génération du XML WinJump FFE - VERSION FINALE AVEC POST-TRAITEMENT
     */
    private function generateWinJumpFFEXML($competition, $results, $people = [], $horses = [])
    {
        // CORRECTION UTF-8 : Forcer l'encodage dès la création
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->encoding = 'UTF-8';
        $xml->formatOutput = true;

        // AJOUT : Forcer la configuration UTF-8 de libxml
        libxml_use_internal_errors(true);

        // Debug des données
        $this->logDebug("Competition data: " . json_encode($competition));
        $this->logDebug("Sample result: " . json_encode($results[0] ?? []));

        // Élément racine WinJump
        $message = $xml->createElement('ffe:message');
        $message->setAttribute('xmlns:ffe', 'http://www.ffe.com/message');
        $message->setAttribute('version', '1.5');
        $xml->appendChild($message);

        // Section info WinJump
        $info = $xml->createElement('info');
        $info->setAttribute('logiciel', 'EquipeGateway');
        $info->setAttribute('version', '1.0.0');
        $info->setAttribute('date', date('c'));
        $info->setAttribute('deposant', 'Extension Equipe (info@jumpingaccess.com)');
        $message->appendChild($info);

        // Section concours
        $concours = $this->createWinJumpConcours($xml, $competition);

        // Extraire le numéro d'épreuve depuis foreign_id
        $foreignId = $competition['foreignid'] ?? $competition['foreign_id'] ?? '2498824_64';
        $parts = explode('_', $foreignId);
        $epreuveNum = $parts[1] ?? '64';

        // Créer l'épreuve
        $epreuve = $this->createWinJumpEpreuve($xml, $competition, $results, $epreuveNum);

        // Ajouter les engagements pour cette épreuve
        foreach ($results as $result) {
            $this->logDebug("Processing result: " . json_encode($result));
            $engagement = $this->createWinJumpEngagement($xml, $result, $people, $horses, $competition, $epreuveNum);
            if ($engagement) {
                $epreuve->appendChild($engagement);
            }
        }

        // Ajouter les officiels
        $this->addOfficielsWinJump($xml, $epreuve, $people, $competition);

        // Ajouter le résultat global de l'épreuve (temps de base)
        $this->addResultatEpreuve($xml, $epreuve, $competition);

        $concours->appendChild($epreuve);
        $message->appendChild($concours);

        // GÉNÉRATION DU XML
        $xmlContent = $xml->saveXML();

        // POST-TRAITEMENT : Décoder les entities HTML pour retrouver l'UTF-8
        $originalName = $competition['klass'] ?? 'Épreuve';
        $encodedName = htmlentities($originalName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remplacer les entities par les vrais caractères UTF-8
        $xmlContent = str_replace($encodedName, $originalName, $xmlContent);

        $this->logDebug("POST-PROCESSING: Replaced '" . $encodedName . "' with '" . $originalName . "'");

        // VERIFICATION FINALE
        $this->logDebug("Final XML contains 'Imposé': " . (strpos($xmlContent, 'Imposé') !== false ? 'YES' : 'NO'));
        $this->logDebug("Final XML contains 'ImposÃ©': " . (strpos($xmlContent, 'ImposÃ©') !== false ? 'YES' : 'NO'));

        return $xmlContent;
    }    // ALTERNATIVE : Méthode pour créer un élément avec encodage UTF-8 forcé
    private function createElementWithUTF8($xml, $name, $value = null)
    {
        if ($value !== null) {
            // S'assurer que la valeur est en UTF-8
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8');
            }
            return $xml->createElement($name, htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        return $xml->createElement($name);
    }

    // Méthode pour définir un attribut avec encodage UTF-8 forcé
    private function setAttributeUTF8($element, $name, $value)
    {
        // S'assurer que la valeur est en UTF-8
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8');
        }
        $element->setAttribute($name, $value);
    }
    /**
     * Création du concours WinJump - FINAL
     */
    private function createWinJumpConcours($xml, $competition)
    {
        $concours = $xml->createElement('concours');

        // Extraire le numéro de concours depuis foreign_id (avant le "_")
        $foreignId = $competition['foreignid'] ?? $competition['foreign_id'] ?? '2498824_64';
        $parts = explode('_', $foreignId);
        $concoursNum = $parts[0] ?? '2498824';

        $this->logDebug("Concours num from foreign_id: " . $concoursNum);
        $concours->setAttribute('num', $concoursNum);

        return $concours;
    }

    /**
     * Création d'une épreuve WinJump - FINAL
     */
    private function createWinJumpEpreuve($xml, $competition, $results, $epreuveNum)
    {
        $epreuve = $xml->createElement('epreuve');

        // Numéro d'épreuve avec padding
        $epreuve->setAttribute('num', $epreuveNum);

        // Déterminer la discipline réelle
        $discipline = $competition['z'] ?? $competition['discipline'] ?? 'H';
        $this->logDebug("Discipline detected: " . $discipline);

        // Profil détail selon la discipline
        $profilDetail = ($discipline === 'D') ? '5' : '1';
        $epreuve->setAttribute('profil_detail', $profilDetail);

        $this->logDebug("Profil detail set to: " . $profilDetail);

        return $epreuve;
    }

    /**
     * Création d'un engagement WinJump - VERSION FINALE
     */
    private function createWinJumpEngagement($xml, $result, $people, $horses, $competition, $epreuveNum = '64')
    {
        $engagement = $xml->createElement('engagement');

        // ID engagement format WinJump : concours + epreuve + start
        $foreignId = $competition['foreignid'] ?? $competition['foreign_id'] ?? '2498824_64';
        $parts = explode('_', $foreignId);
        $concoursNum = $parts[0] ?? '2498824';

        $startNum = str_pad($result['id'] ?? '1', 5, '0', STR_PAD_LEFT);
        $engagementId = $concoursNum . $epreuveNum . ' ' . $startNum;
        $engagement->setAttribute('id', $engagementId);

        // Dossard avec padding
        $dossard = $result['st'] ?? $result['start_number'] ?? '1';
        $engagement->setAttribute('dossard', $dossard);

        // VÉRIFICATION ENGAGEMENT TERRAIN
        $isEngagementTerrain = false;
        if (isset($result['start_custom_fields']['engagement_terrain'])) {
            $isEngagementTerrain = $result['start_custom_fields']['engagement_terrain'] === true;
        }

        if ($isEngagementTerrain) {
            $engagement->setAttribute('terrain', 'true');
            $this->logDebug("Engagement terrain detected for dossard: " . $dossard);
        }

        // Cavalier (seulement si changement)
        $person = FFEDataHelper::findPersonById($people, $result['rider']['id'] ?? $result['rnr'] ?? null);
        if ($person && !empty($person['rlic'])) {
            $cavalier = $xml->createElement('cavalier');
            $cavalier->setAttribute('lic', FFEDataHelper::cleanFFELicense($person['rlic']));
            $cavalier->setAttribute('changement', 'true');

            // Corriger les noms si présents
            if (!empty($person['nom'])) {
                $cavalier->setAttribute('nom', $this->normalizeText($person['nom']));
            }
            if (!empty($person['prenom'])) {
                $cavalier->setAttribute('prenom', $this->normalizeText($person['prenom']));
            }

            $engagement->appendChild($cavalier);
        }

        // Équidé (seulement si changement)
        $horse = FFEDataHelper::findHorseById($horses, $result['horse']['id'] ?? $result['hnr'] ?? null);
        if ($horse && !empty($horse['regnr'])) {
            $equide = $xml->createElement('equide');
            $equide->setAttribute('sire', FFEDataHelper::cleanFFESire($horse['regnr']));
            $equide->setAttribute('changement', 'true');

            // Corriger le nom du cheval si présent
            if (!empty($horse['nom'])) {
                $equide->setAttribute('nom', $this->normalizeText($horse['nom']));
            }

            $engagement->appendChild($equide);
        }

        // Club (seulement si présent)
        if ($person && !empty($person['club']['number'])) {
            $club = $xml->createElement('club');
            $club->setAttribute('num', $person['club']['number']);

            // Corriger le nom du club si présent
            if (!empty($person['club']['nom'])) {
                $club->setAttribute('nom', $this->normalizeText($person['club']['nom']));
            }

            $engagement->appendChild($club);
        }

        // Résultat WinJump - VERSION CORRIGÉE
        $resultat = $this->createWinJumpResultat($xml, $result, $competition);
        if ($resultat) {
            $engagement->appendChild($resultat);
        }

        return $engagement;
    }

    /**
     * Création d'un résultat WinJump - VERSION FINALE CORRIGÉE
     */
    private function createWinJumpResultat($xml, $result, $competition)
    {
        $resultat = $xml->createElement('resultat');

        // Debug du résultat
        $this->logDebug("Creating result for: " . json_encode($result));

        // Vérifier d'abord l'état du résultat
        $etat = $this->mapWinJumpResultStateCorrect($result);
        $this->logDebug("Result state: " . ($etat ?? 'NORMAL'));

        if ($etat) {
            $resultat->setAttribute('etat', $etat);
        } else {
            // Classement normal
            $classement = $result['re'] ?? $result['rank'] ?? $result['position'] ?? 999;
            $this->logDebug("Classement: " . $classement);

            if ($classement != 999 && $classement > 0) {
                $resultat->setAttribute('classement', $classement);

                // Contrat SF si bon classement
                if ($classement <= 50) {
                    $resultat->setAttribute('contrat', 'SF');
                }
            }
        }

        // SOLUTION FINALE : Éviter createDocumentFragment complètement
        $detail = $xml->createElement('detail');

        // Utiliser directement le JSON avec entities HTML
        $epreuveName = $competition['klass'] ?? 'Épreuve';
        $this->logDebug("Using klass directly from JSON => " . $epreuveName);

        // Encoder en entities HTML pour éviter les problèmes UTF-8 de DOMDocument
        //$encodedName = htmlentities($epreuveName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        //$detail->setAttribute('nom', $epreuveName);
        //$detail->setAttribute('id', '420');

        $manche = $this->createWinJumpManche($xml, $result, $competition, $etat);
        $detail->appendChild($manche);
        $resultat->appendChild($detail);

        return $resultat;
    }

    /**
     * Création d'une manche WinJump - FINAL
     */
    private function createWinJumpManche($xml, $result, $competition, $etat)
    {
        $manche = $xml->createElement('manche');
        $manche->setAttribute('num', '1');

        // État de la manche si éliminé/non partant
        if ($etat) {
            $manche->setAttribute('etat', $etat);
        } else {
            // Scores selon le profil
            $discipline = $competition['z'] ?? $competition['discipline'] ?? 'H';
            $this->logDebug("Adding scores for discipline: " . $discipline);
            $this->addWinJumpScoresCorrect($xml, $manche, $result, $discipline);
        }

        return $manche;
    }

    /**
     * Ajouter les scores WinJump selon la discipline - VERSION CORRIGÉE
     */
    private function addWinJumpScoresCorrect($xml, $manche, $result, $discipline)
    {
        $this->logDebug("Processing scores for result: " . json_encode($result));

        if ($discipline === 'D') {
            // Dressage : profil 5 - 6 scores
            $this->addDressageScoresCorrect($xml, $manche, $result);
        } else {
            // Saut d'obstacles : profil 1 - 4 scores
            $this->addJumpingScoresCorrect($xml, $manche, $result);
        }
    }

    /**
     * Scores dressage WinJump - VERSION FINALE CORRIGÉE
     */
    private function addDressageScoresCorrect($xml, $manche, $result)
    {
        $scoreNum = 1;

        // Juge E (si présent)
        if (isset($result['esp']) && $result['esp'] > 0) {
            $score = $xml->createElement('score');
            $score->setAttribute('nom', 'Juge E');
            $score->setAttribute('num', $scoreNum);
            $score->setAttribute('precision', '2');
            $score->setAttribute('nom_unite', 'pourcent');
            $score->setAttribute('score', number_format($result['esp'], 2));
            $manche->appendChild($score);
            $this->logDebug("Added Juge E: " . $result['esp']);
        }
        $scoreNum++;

        // Juge H (si présent)
        if (isset($result['hsp']) && $result['hsp'] > 0) {
            $score = $xml->createElement('score');
            $score->setAttribute('nom', 'Juge H');
            $score->setAttribute('num', $scoreNum);
            $score->setAttribute('precision', '2');
            $score->setAttribute('nom_unite', 'pourcent');
            $score->setAttribute('score', number_format($result['hsp'], 2));
            $manche->appendChild($score);
            $this->logDebug("Added Juge H: " . $result['hsp']);
        }
        $scoreNum++;

        // Juge C (obligatoire)
        if (isset($result['csp'])) {
            $score = $xml->createElement('score');
            $score->setAttribute('nom', 'Juge C');
            $score->setAttribute('num', $scoreNum);
            $score->setAttribute('precision', '2');
            $score->setAttribute('nom_unite', 'pourcent');
            $score->setAttribute('obligatoire', '1');
            $score->setAttribute('score', number_format($result['csp'], 2));
            $manche->appendChild($score);
            $this->logDebug("Added Juge C: " . $result['csp']);
        }

        // Score Ensemble (total en points)
        if (isset($result['psum'])) {
            $score = $xml->createElement('score');
            $score->setAttribute('nom', 'Ensemble');
            $score->setAttribute('num', '6');
            $score->setAttribute('nom_unite', 'points');
            $score->setAttribute('score', $result['psum']);
            $manche->appendChild($score);
            $this->logDebug("Added Ensemble: " . $result['psum']);
        }
    }

    /**
     * Scores saut d'obstacles WinJump - VERSION FINALE CORRIGÉE
     */
    private function addJumpingScoresCorrect($xml, $manche, $result)
    {
        // Debug des champs disponibles
        $this->logDebug("Available result fields: " . implode(', ', array_keys($result)));

        // Score 1 : Fautes
        $fautes = $result['f'] ?? $result['faults'] ?? $result['penalties'] ?? 0;
        $this->addScore($xml, $manche, 1, $fautes);
        $this->logDebug("Added fautes: " . $fautes);

        // Score 2 : Temps
        $temps = $result['t'] ?? $result['time'] ?? $result['tproc'] ?? $result['total_time'] ?? 0;
        $this->addScore($xml, $manche, 2, number_format($temps, 2));
        $this->logDebug("Added temps: " . $temps);

        // Score 3 : 0 (temps ajusté)
        $this->addScore($xml, $manche, 3, '0');

        // Score 4 : Total fautes (même que score 1)
        $this->addScore($xml, $manche, 4, $fautes);
    }

    /**
     * Mapper l'état du résultat WinJump - VERSION CORRIGÉE
     */
    private function mapWinJumpResultStateCorrect($result)
    {
        // Debug des champs d'état
        $this->logDebug("Checking result state for fields: " . implode(', ', array_keys($result)));

        // NOUVELLE LOGIQUE : Vérifier le champ 'or' depuis results.json
        if (isset($result['or'])) {
            $orValue = $result['or'];
            $this->logDebug("Found 'or' field with value: " . $orValue);

            switch ($orValue) {
                case 'U':
                    $this->logDebug("Mapping 'U' to Retired (AB)");
                    return 'AB'; // Abandon

                case 'D':
                    $this->logDebug("Mapping 'D' to Eliminated (EL)");
                    return 'EL'; // Éliminé

                case 'S':
                    $this->logDebug("Mapping 'S' to Disqualified (DISQ)");
                    return 'DISQ'; // Disqualifié

                case 'A':
                    $this->logDebug("Mapping 'A' to Withdrawn (NP)");
                    return 'NP'; // Non partant (Withdrawn)

                default:
                    $this->logDebug("Unknown 'or' value: " . $orValue . " - treating as normal");
                    break;
            }
        }

        // FALLBACK : Vérifier les anciens champs pour compatibilité
        if (isset($result['utt']) && $result['utt']) {
            $this->logDebug("Fallback: Using 'utt' field - Non partant");
            return 'NP'; // Non partant
        }
        if (isset($result['utl']) && $result['utl']) {
            $this->logDebug("Fallback: Using 'utl' field - Éliminé");
            return 'EL'; // Éliminé  
        }
        if (isset($result['eliminated']) && $result['eliminated']) {
            $this->logDebug("Fallback: Using 'eliminated' field");
            return 'EL';
        }
        if (isset($result['not_started']) && $result['not_started']) {
            $this->logDebug("Fallback: Using 'not_started' field");
            return 'NP';
        }
        if (isset($result['retired']) && $result['retired']) {
            $this->logDebug("Fallback: Using 'retired' field");
            return 'AB'; // Abandon
        }
        if (isset($result['disqualified']) && $result['disqualified']) {
            $this->logDebug("Fallback: Using 'disqualified' field");
            return 'DISQ';
        }

        // Vérifier le classement - si pas de classement valide = NP
        $classement = $result['re'] ?? $result['rank'] ?? $result['position'] ?? 999;
        if ($classement == 999 || $classement <= 0) {
            // Mais seulement si pas de résultats/scores ET pas de champ 'or'
            $hasScores = isset($result['t']) || isset($result['f']) || isset($result['esp']) || isset($result['csp']);
            $hasOrField = isset($result['or']);

            if (!$hasScores && !$hasOrField) {
                $this->logDebug("No scores, no 'or' field, invalid ranking - treating as Non partant");
                return 'NP';
            }
        }

        $this->logDebug("Result state: Normal (no special state detected)");
        return null; // Résultat normal avec classement
    }

    /**
     * Ajouter un score
     */
    private function addScore($xml, $manche, $num, $value)
    {
        $score = $xml->createElement('score');
        $score->setAttribute('num', $num);
        $score->setAttribute('score', $value);
        $manche->appendChild($score);
    }

    /**
     * Ajouter les engagements terrain
     */
    private function addEngagementsTerrain($xml, $epreuve, $results, $people, $horses, $competition)
    {
        // Engagements terrain d'exemple - à personnaliser selon vos besoins
        $terrainsData = [
            ['dossard' => '501', 'lic' => '4052240Y', 'sire' => '16381688R', 'club' => '3070000', 'classement' => '1'],
            ['dossard' => '502', 'lic' => '3414972R', 'sire' => '13362294R', 'club' => '3070000', 'etat' => 'EL']
        ];

        foreach ($terrainsData as $terrainData) {
            $engagement = $xml->createElement('engagement');
            $engagement->setAttribute('dossard', str_pad($terrainData['dossard'], 5, ' ', STR_PAD_LEFT));
            $engagement->setAttribute('terrain', 'true');

            // Cavalier terrain
            if (!empty($terrainData['lic'])) {
                $cavalier = $xml->createElement('cavalier');
                $cavalier->setAttribute('lic', $terrainData['lic']);
                $cavalier->setAttribute('changement', 'true');
                $engagement->appendChild($cavalier);
            }

            // Équidé terrain
            if (!empty($terrainData['sire'])) {
                $equide = $xml->createElement('equide');
                $equide->setAttribute('sire', $terrainData['sire']);
                $equide->setAttribute('changement', 'true');
                $engagement->appendChild($equide);
            }

            // Club terrain
            if (!empty($terrainData['club'])) {
                $club = $xml->createElement('club');
                $club->setAttribute('num', $terrainData['club']);
                $engagement->appendChild($club);
            }

            // Résultat terrain
            $resultat = $xml->createElement('resultat');
            if (isset($terrainData['etat'])) {
                $resultat->setAttribute('etat', $terrainData['etat']);

                $detail = $xml->createElement('detail');
                $manche = $xml->createElement('manche');
                $manche->setAttribute('num', '1');
                $manche->setAttribute('etat', $terrainData['etat']);
                $detail->appendChild($manche);
                $resultat->appendChild($detail);
            } else {
                $resultat->setAttribute('classement', $terrainData['classement']);
                $resultat->setAttribute('contrat', 'SF');

                $detail = $xml->createElement('detail');
                $manche = $xml->createElement('manche');
                $manche->setAttribute('num', '1');

                $this->addScore($xml, $manche, 1, '0');
                $this->addScore($xml, $manche, 2, '57.58');
                $this->addScore($xml, $manche, 3, '0');
                $this->addScore($xml, $manche, 4, '0');

                $detail->appendChild($manche);
                $resultat->appendChild($detail);
            }

            $engagement->appendChild($resultat);
            $epreuve->appendChild($engagement);
        }
    }

    /**
     * Ajouter les officiels WinJump
     */
    /**
     * Ajouter les officiels WinJump - VERSION DYNAMIQUE DEPUIS PEOPLE.JSON
     */
    private function addOfficielsWinJump($xml, $epreuve, $people, $competition)
    {
        // Récupérer les officiels depuis people.json où "official": true
        $officiels = [];

        foreach ($people as $person) {
            if (isset($person['official']) && $person['official'] === true) {
                $officiels[] = $person;
                $this->logDebug("Found official: " . ($person['rlic'] ?? 'N/A') . " - " . ($person['nom'] ?? 'N/A'));
            }
        }

        // Si pas d'officiels trouvés dans people.json, utiliser ceux du competition.json
        if (empty($officiels)) {
            $this->addOfficielsFromCompetition($xml, $epreuve, $competition);
            return;
        }

        // Mapper les officiels trouvés
        $fonctionNum = ['02' => 1, '03' => 1, '05' => 1, '11' => 1]; // Compteurs par fonction

        foreach ($officiels as $officiel) {
            $officielElement = $xml->createElement('officiel');

            // Licence officiel
            if (!empty($officiel['rlic'])) {
                $officielElement->setAttribute('lic', FFEDataHelper::cleanFFELicense($officiel['rlic']));
            }

            // Déterminer la fonction selon le type d'officiel
            $fonction = $this->determinerFonctionOfficiel($officiel, $competition);
            $officielElement->setAttribute('fonction', $fonction);

            // Numéro séquentiel par fonction
            if (!isset($fonctionNum[$fonction])) {
                $fonctionNum[$fonction] = 1;
            }
            $officielElement->setAttribute('num', str_pad($fonctionNum[$fonction], 2, '0', STR_PAD_LEFT));
            $fonctionNum[$fonction]++;

            $epreuve->appendChild($officielElement);
        }

        $this->logDebug("Added " . count($officiels) . " officials from people.json");
    }

    /**
     * Ajouter les officiels depuis competition.json (fallback)
     */
    private function addOfficielsFromCompetition($xml, $epreuve, $competition)
    {
        $this->logDebug("Using officials from competition.json");

        // Mapping des juges depuis competition.json
        $jugesMapping = [
            'domarec_kb' => ['fonction' => '02', 'ckb' => 'ckb'], // Juge C
            'domareh_kb' => ['fonction' => '02', 'hkb' => 'hkb'], // Juge H  
            'domaree_kb' => ['fonction' => '02', 'ekb' => 'ekb'], // Juge E
            'domareb_kb' => ['fonction' => '02', 'bkb' => 'bkb'], // Juge B
            'domarem_kb' => ['fonction' => '02', 'mkb' => 'mkb']  // Juge M
        ];

        $officielNum = 1;

        foreach ($jugesMapping as $jugeField => $config) {
            if (!empty($competition[$jugeField])) {
                // Extraire les IDs depuis le champ correspondant (ckb, hkb, etc.)
                $jugeLicences = $competition[$config[array_key_last($config)]] ?? [];

                foreach ($jugeLicences as $licenceId) {
                    $officiel = $xml->createElement('officiel');
                    $officiel->setAttribute('num', str_pad($officielNum, 2, '0', STR_PAD_LEFT));
                    $officiel->setAttribute('fonction', $config['fonction']);
                    $officiel->setAttribute('lic', $licenceId);

                    $epreuve->appendChild($officiel);
                    $officielNum++;
                }
            }
        }
    }

    /**
     * Déterminer la fonction d'un officiel selon son rôle
     */
    private function determinerFonctionOfficiel($officiel, $competition)
    {
        // Fonction par défaut
        $fonction = '05'; // Autre fonction

        // Vérifier si c'est un juge selon sa position dans competition.json
        $discipline = $competition['z'] ?? $competition['discipline'] ?? 'H';

        if ($discipline === 'D') {
            // Dressage : fonctions 02 (juges), 03 (secrétaires), 05 (autres)
            if (isset($officiel['judge_position'])) {
                $fonction = '02'; // Juge
            } elseif (isset($officiel['secretary'])) {
                $fonction = '03'; // Secrétaire
            }
        } else {
            // Saut d'obstacles : fonctions 02 (juges), 05 (commissaires), 11 (chronométreurs)
            if (isset($officiel['judge_position'])) {
                $fonction = '02'; // Juge
            } elseif (isset($officiel['timekeeper'])) {
                $fonction = '11'; // Chronométreur
            }
        }

        return $fonction;
    }

    /**
     * Ajouter le résultat de l'épreuve (temps de base)
     */
    private function addResultatEpreuve($xml, $epreuve, $competition)
    {
        $resultat = $xml->createElement('resultat');
        $detail = $xml->createElement('detail');
        $manche = $xml->createElement('manche');
        $discipline = $competition['z'] ?? $competition['discipline'] ?? 'H';
        switch ($discipline) {
            case "H":
                $manche->setAttribute('num', $epreuve['round']);
                $this->addScore($xml, $manche, 2, $competition['temps_base'] ?? '80');
                break;
            case 'D':
                $manche->setAttribute('num', '1');
                break;
        }

        $detail->appendChild($manche);
        $resultat->appendChild($detail);
        $epreuve->appendChild($resultat);
    }

    /**
     * Logging en mode debug - AMÉLIORÉ
     */
    private function logDebug($message)
    {
        if ($this->debugMode) {
            if (function_exists('writeLog')) {
                writeLog("[FFE-SIF] " . $message);
            } else {
                error_log("[FFE-SIF] " . $message);
            }
        }
    }
}

// La classe FFEDataHelper est définie dans FFEDataHelper.php