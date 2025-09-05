<?php
// index.php - Point d'entrée principal de l'extension FFE

// Configuration de session pour fonctionner dans un iframe
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', 'true');
    ini_set('session.cookie_httponly', 'true');
    session_start();
}


require_once 'languages/config.php';

use FFE\Extension\Languages\LanguageConfig;

// Détecter et charger la langue
$currentLanguage = LanguageConfig::detectLanguage();
$translations = LanguageConfig::loadTranslations($currentLanguage);

// Fonction helper pour les traductions
function t($key, $params = [])
{
    global $translations;

    $text = $translations[$key] ?? $key;

    // Remplacer les paramètres si nécessaire
    foreach ($params as $param => $value) {
        $text = str_replace('{' . $param . '}', $value, $text);
    }

    return $text;
}
// Charger les variables d'environnement depuis .env.php AVANT toute autre chose
if (file_exists(__DIR__ . '/.env.php')) {
    $env = include __DIR__ . '/.env.php';
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Configuration debug - Utilisation de constantes pour éviter les warnings
define('DEBUG_MODE', isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == '1');
define('LOG_FILE', __DIR__ . '/logs/ffe_extension_' . date('Y-m-d') . '.log');

// Créer le dossier logs s'il n'existe pas
if (DEBUG_MODE && !is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Fonction de logging
function writeLog($message)
{
    if (DEBUG_MODE) {
        $timestamp = date('[Y-m-d H:i:s]');
        file_put_contents(LOG_FILE, $timestamp . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// Chargement des dépendances
require_once 'vendor/autoload.php';
require_once 'FFEParser.php';
require_once 'FFEExportFormats.php';
// Ajout en haut du fichier index.php, après les requires existants
require_once 'FFESIFExporter.php';
require_once 'FFEDataHelper.php';
// Inclure EquipeApiSender - important de le faire après la définition de DEBUG_MODE
if (!class_exists('FFE\Extension\EquipeApiSender')) {
    require_once 'EquipeApiSender.php';
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FFE\Extension\FFEXmlParser;
use FFE\Extension\FFEResultFormatter;
use FFE\Extension\EquipeApiSender;
use FFE\Extension\Export\SIFExporter;
use FFE\Extension\Export\FFECompetExporter;
// ==========================================
// FONCTIONS UTILITAIRES POUR EXPORT
// ==========================================
function extractFFEEventNumber($foreignid)
{
    // Extraire le numéro depuis FFE_EPR_2 -> 2
    if (preg_match('/FFE_EPR?_(\d+)/', $foreignid, $matches)) {
        return $matches[1];
    }
    // Si pas de match, retourner 1 par défaut
    return '1';
}
function generateSIFXML($competition, $results)
{
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;

    // Racine selon spec SIF
    $root = $xml->createElement('sif');
    $root->setAttribute('version', '1.0');
    $xml->appendChild($root);

    // En-tête concours
    $concours = $xml->createElement('concours');
    $concours->setAttribute('id', $competition['foreignid'] ?? 'FFE_' . $competition['kq']);
    $concours->setAttribute('nom', htmlspecialchars($competition['klass'] ?? 'Concours'));
    $concours->setAttribute('date', $competition['datum'] ?? date('Y-m-d'));
    $root->appendChild($concours);

    // Épreuve
    $epreuve = $xml->createElement('epreuve');
    $epreuve->setAttribute('numero', $competition['clabb'] ?? '1');
    $epreuve->setAttribute('discipline', mapDisciplineToSIF($competition['z'] ?? 'H'));
    $epreuve->setAttribute('niveau', mapLevelToSIF($competition['x'] ?? 'I'));
    $concours->appendChild($epreuve);

    // Participants et résultats
    foreach ($results as $result) {
        if (isset($result['re']) && $result['re'] != 999) {
            $participant = $xml->createElement('participant');
            $participant->setAttribute('dossard', $result['st'] ?? '0');
            $participant->setAttribute('cavalier', htmlspecialchars($result['rider_name'] ?? ''));
            $participant->setAttribute('cheval', htmlspecialchars($result['horse_name'] ?? ''));
            $participant->setAttribute('club', htmlspecialchars($result['club_name'] ?? ''));
            $participant->setAttribute('classement', $result['re']);

            // Points de dressage si applicable
            if (($competition['z'] ?? 'H') === 'D') {
                if (isset($result['ct'])) $participant->setAttribute('juge_c', $result['ct']);
                if (isset($result['ht'])) $participant->setAttribute('juge_h', $result['ht']);
                if (isset($result['et'])) $participant->setAttribute('juge_e', $result['et']);
                if (isset($result['mt'])) $participant->setAttribute('juge_m', $result['mt']);
                if (isset($result['bt'])) $participant->setAttribute('juge_b', $result['bt']);
                if (isset($result['kt'])) $participant->setAttribute('juge_k', $result['kt']);
                if (isset($result['ft'])) $participant->setAttribute('juge_f', $result['ft']);
                if (isset($result['gproc'])) $participant->setAttribute('pourcentage', $result['gproc']);
            }

            $epreuve->appendChild($participant);
        }
    }

    return $xml->saveXML();
}

function generateFFECompetTXT($competition, $results, $isGlobalExport = false)
{
    $lines = [];
    $lineCount = 0;

    // Ligne 00 - Début de fichier
    $line00 = '00';
    $line00 .= date('d/m/Y H:i:s');
    $line00 .= 'V024FFECompet Export Equipe';
    $line00 = str_pad($line00, 121, ' ');
    $lines[] = $line00;
    $lineCount++;

    // Extraire le numéro du concours depuis le foreign_id
    $foreignId = $competition['foreignid'] ?? $competition['foreign_id'] ?? '';
    $parts = explode('_', $foreignId);
    $concoursNum = $parts[0];
    $epreuveNum = $parts[1] ?? ($competition['clabb'] ?? '1');

    // Ligne 01 - Concours
    $line01 = '01';
    $line01 .= str_pad($concoursNum, 9, '0', STR_PAD_LEFT); // Numéro de concours sur 9 caractères
    $line01 .= mapDisciplineToFFECompetCode($competition['z'] ?? 'SO'); // Code discipline (2 caractères)

    // Pour un export individuel (via bouton), toujours 1 épreuve
    $nombreEpreuves = 1;

    $line01 .= str_pad($nombreEpreuves, 2, '0', STR_PAD_LEFT); // Toujours '01' pour un export individuel
    $lines[] = $line01;
    $lineCount++;

    // Ligne 02 - Déclaration de l'épreuve
    $line02 = '02';
    $line02 .= str_pad($epreuveNum, 3, '0', STR_PAD_LEFT); // Numéro de séquence de l'épreuve
    $line02 .= str_pad(count($results), 3, '0', STR_PAD_LEFT); // Nombre de résultats
    $lines[] = $line02;
    $lineCount++;
    $customLines = generateCustomFieldLines($results, $epreuveNum);
    foreach ($customLines as $customLine) {
        $lines[] = $customLine;
        $lineCount++;
    }
    // Trier les résultats par classement
    usort($results, function ($a, $b) {
        return ($a['re'] ?? 999) - ($b['re'] ?? 999);
    });

    $discipline = $competition['z'] ?? 'H';

    foreach ($results as $result) {
        if (isset($result['re']) && $result['re'] != 999) {

            if ($discipline === 'D') { // Dressage - Code 06
                $line06 = '06';

                // Position 3-5: Numéro de départ
                $line06 .= str_pad($result['st'] ?? '0', 3, '0', STR_PAD_LEFT);

                // Position 6-13: Numéro SIRE du cheval
                $line06 .= str_pad($result['horse_sire'] ?? '50053829', 8);

                // Position 14-20: Numéro de licence (7 caractères)
                $line06 .= str_pad($result['rider_license'] ?? '0132611', 7);

                // Position 21-22: État du résultat
                $line06 .= determineResultStatus($result);
                $line06 .= ' ';
                // Points des juges (chaque juge sur 6 caractères)
                $pointsJugeC = $result['ct'] ?? 0;  // 142
                $pointsJugeH = $result['ht'] ?? 0;  // 114
                $pointsJugeM = $result['mt'] ?? 0;  // 0
                $pointsJugeB = $result['bt'] ?? 0;  // 0
                $pointsJugeE = $result['et'] ?? 0;  // 120.5

                // Position 23-28: Points Juge C
                $line06 .= str_pad(number_format($pointsJugeC, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 29-34: Points Juge H
                $line06 .= str_pad(number_format($pointsJugeH, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 35-40: Points Juge M
                $line06 .= str_pad(number_format($pointsJugeM, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 41-46: Points Juge B
                $line06 .= str_pad(number_format($pointsJugeB, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 47-52: Points Juge E
                $line06 .= str_pad(number_format($pointsJugeE, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 53-59: Moyenne en % (7 caractères)
                $line06 .= str_pad(number_format($result['gproc'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 60-66: Total (7 caractères)
                $line06 .= str_pad(number_format($result['psum'] ?? 0, 2, ',', ''), 7, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 67-72: Note Artistique = SOMME des points des juges
                $noteArtistique = $pointsJugeC + $pointsJugeH + $pointsJugeM + $pointsJugeB + $pointsJugeE;
                $line06 .= str_pad(number_format($noteArtistique, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 73-75: Place (3 caractères)
                $line06 .= str_pad($result['re'] ?? '', 3, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 76-83: Gains (8 caractères) - attention aux positions !
                $line06 .= str_pad(number_format($result['premie'] ?? 0, 2, ',', ''), 8, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Positions 84-93: Primes et Surprimes (10 caractères d'espaces)
                $line06 .= str_repeat(' ', 10);
                $line06 .= ' ';
                // Position 94-103: Pourcentage Juge C (10 caractères)
                $line06 .= str_pad(number_format($result['csp'] ?? 0, 3, ',', ''), 10, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 104-113: Pourcentage Juge H (10 caractères)  
                $line06 .= str_pad(number_format($result['hsp'] ?? 0, 3, ',', ''), 10, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 114-123: Pourcentage Juge M (10 caractères)
                $line06 .= str_pad(number_format($result['msp'] ?? 0, 3, ',', ''), 10, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 124-133: Pourcentage Juge B (10 caractères)
                $line06 .= str_pad(number_format($result['bsp'] ?? 0, 3, ',', ''), 10, ' ', STR_PAD_LEFT);
                $line06 .= ' ';
                // Position 134-143: Pourcentage Juge E (10 caractères)
                $line06 .= str_pad(number_format($result['esp'] ?? 0, 3, ',', ''), 10, ' ', STR_PAD_LEFT);

                $lines[] = $line06;
                $lineCount++;
            } elseif ($discipline === 'H' || $discipline === 'S') { // Saut d'obstacles - Code 05
                $line05 = '05';

                // Position 3-5: Numéro de départ (3 caractères)
                $line05 .= str_pad($result['st'] ?? '0', 3, '0', STR_PAD_LEFT);

                // Position 6-13: Numéro SIRE du cheval (8 caractères)
                $line05 .= str_pad($result['horse_sire'] ?? '50053829', 8);

                // Position 14-20: Numéro de licence (7 caractères)
                $line05 .= str_pad($result['rider_license'] ?? '9999999', 7);

                // Position 21-22: État du résultat (2 caractères)
                $line05 .= determineResultStatus($result);

                // Position 23-29: Points / Points Manche 1 (7 caractères, Flottant(2))
                $line05 .= str_pad(number_format($result['p'] ?? 0, 2, ',', ''), 7, ' ', STR_PAD_LEFT);

                // Position 30-36: Points barrage / Points Manche 2 (7 caractères, Flottant(2))
                $line05 .= str_pad(number_format($result['p2'] ?? 0, 2, ',', ''), 7, ' ', STR_PAD_LEFT);

                // Position 37-43: Temps / Temps Manche 1 (7 caractères, Flottant(3))
                $line05 .= str_pad(number_format($result['t'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);

                // Position 44-50: Temps barrage / Temps Manche 2 (7 caractères, Flottant(3))
                $line05 .= str_pad(number_format($result['t2'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);

                // Position 51-52: Indice (2 caractères, optionnel)
                $line05 .= str_pad($result['indice'] ?? '', 2, ' ');

                // Position 53-58: Note de présentation (6 caractères, Flottant(3), optionnel)
                $line05 .= str_pad(number_format($result['presentation'] ?? 0, 3, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 59-61: Place (3 caractères, Entier)
                $line05 .= str_pad($result['re'], 3, ' ', STR_PAD_LEFT);

                // Position 62-69: Gains (8 caractères, Flottant(2))
                $line05 .= str_pad(number_format($result['premie'] ?? 0, 2, ',', ''), 8, ' ', STR_PAD_LEFT);

                // Positions 70-81: Primes et Surprimes (optionnels)
                $line05 .= str_repeat(' ', 12);

                $lines[] = $line05;
                $lineCount++;
            } elseif ($discipline === 'C') { // Concours Complet - Code 07
                $line07 = '07';

                // Position 3-5: Numéro de départ (3 caractères)
                $line07 .= str_pad($result['st'] ?? '0', 3, '0', STR_PAD_LEFT);

                // Position 6-13: Numéro SIRE du cheval (8 caractères)
                $line07 .= str_pad($result['horse_sire'] ?? '50053829', 8);

                // Position 14-20: Numéro de licence (7 caractères)
                $line07 .= str_pad($result['rider_license'] ?? '9999999', 7);

                // Position 21-22: État du résultat (2 caractères)
                $line07 .= determineResultStatus($result);

                // Position 23-28: Points Dressage (6 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['dr_points'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 29-34: Points Fond Obstacles (6 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['xc_obs_points'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 35-40: Points Fond Temps (6 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['xc_time_points'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 41-46: Points SO Obstacles (6 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['sj_obs_points'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 47-52: Points SO Temps (6 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['sj_time_points'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 53-58: Total (6 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['total_points'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 59-60: Indice (2 caractères, optionnel)
                $line07 .= str_pad($result['indice'] ?? '', 2, ' ');

                // Position 61-62: Chutes (2 caractères, optionnel)
                $line07 .= str_pad($result['falls'] ?? '', 2, ' ');

                // Position 63-65: Place (3 caractères, Entier)
                $line07 .= str_pad($result['re'], 3, ' ', STR_PAD_LEFT);

                // Position 66-73: Gains (8 caractères, Flottant(2))
                $line07 .= str_pad(number_format($result['premie'] ?? 0, 2, ',', ''), 8, ' ', STR_PAD_LEFT);

                $lines[] = $line07;
                $lineCount++;
            } elseif ($discipline === 'A') { // Attelage - Code 08
                $line08 = '08';

                // Position 3-5: Numéro de départ (3 caractères)
                $line08 .= str_pad($result['st'] ?? '0', 3, '0', STR_PAD_LEFT);

                // Position 6-13: Numéro SIRE du cheval (8 caractères)
                $line08 .= str_pad($result['horse_sire'] ?? '50053829', 8);

                // Position 14-20: Numéro de licence (7 caractères)
                $line08 .= str_pad($result['rider_license'] ?? '9999999', 7);

                // Position 21-22: État du résultat (2 caractères)
                $line08 .= determineResultStatus($result);

                // Position 23-28: Points Pénalités Dressage (6 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['dress_pen'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 29: Indice Dressage (1 caractère)
                $line08 .= substr($result['dress_indice'] ?? ' ', 0, 1);

                // Position 30-35: Points Pénalités Marathon (6 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['marathon_pen'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 36-41: Points Pénalités de temps Marathon (6 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['marathon_time_pen'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 42: Indice Marathon (1 caractère)
                $line08 .= substr($result['marathon_indice'] ?? ' ', 0, 1);

                // Position 43-48: Points Pénalités Maniabilité (6 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['maniab_pen'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 49-54: Points Pénalités Temps Maniabilité (6 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['maniab_time_pen'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 55: Indice Maniabilité (1 caractère)
                $line08 .= substr($result['maniab_indice'] ?? ' ', 0, 1);

                // Position 56-61: Total (6 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['total'] ?? 0, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

                // Position 62-63: Indice (2 caractères, optionnel)
                $line08 .= str_pad($result['indice'] ?? '', 2, ' ');

                // Position 64-66: Place (3 caractères, Entier)
                $line08 .= str_pad($result['re'], 3, ' ', STR_PAD_LEFT);

                // Position 67-74: Gains (8 caractères, Flottant(2))
                $line08 .= str_pad(number_format($result['premie'] ?? 0, 2, ',', ''), 8, ' ', STR_PAD_LEFT);

                $lines[] = $line08;
                $lineCount++;
            }
        }
    }

    // Ligne 20 - Déclaration Jury (optionnel mais recommandé)
    if (isset($competition['domarec_kb']) && !empty($competition['domarec_kb'])) {
        // Parser la chaîne domarec_kb pour extraire les juges
        // Format: "Marie paule Leonard (FRA), Jean Bretenoux (FRA)"
        $judges = explode(',', $competition['domarec_kb']);

        foreach ($judges as $index => $judge) {
            // Extraire le nom complet (sans le code pays)
            $judgeName = trim(str_replace('(FRA)', '', $judge));

            // Séparer prénom et nom
            $nameParts = explode(' ', $judgeName);
            $firstName = '';
            $lastName = '';

            if (count($nameParts) >= 2) {
                // Le dernier mot est le nom de famille
                $lastName = strtoupper(array_pop($nameParts));
                // Le reste est le prénom
                $firstName = implode(' ', $nameParts);
            }

            // Chercher la licence du juge dans les données people ou officials
            $judgeLicense = '0000000'; // Valeur par défaut

            // Option 1: Si on a les données officials en session
            if (isset($_SESSION['ffe_parsed_data']['officials'])) {
                foreach ($_SESSION['ffe_parsed_data']['officials'] as $official) {
                    if (
                        stripos($official['nom'], $lastName) !== false &&
                        stripos($official['prenom'], $firstName) !== false
                    ) {
                        $judgeLicense = $official['licence'];
                        break;
                    }
                }
            }

            // Option 2: Chercher dans people si pas trouvé dans officials
            if ($judgeLicense === '0000000' && isset($people)) {
                foreach ($people as $person) {
                    // Comparer avec les noms dans people
                    if ((isset($person['last_name']) && stripos($person['last_name'], $lastName) !== false) ||
                        (isset($person['first_name']) && stripos($person['first_name'], $firstName) !== false)
                    ) {
                        if (isset($person['rlic'])) {
                            $judgeLicense = str_pad($person['rlic'], 7, '0', STR_PAD_LEFT);
                            break;
                        }
                    }
                }
            }

            // Option 3: Récupérer depuis les champs spécifiques de la compétition
            // Les juges peuvent être dans ckb, hkb, mkb, ekb, bkb
            if ($judgeLicense === '0000000') {
                // Déterminer quel juge selon la position
                $judgePositions = ['C', 'H', 'M', 'E', 'B'];
                $judgePosition = $judgePositions[min($index, 4)] ?? 'C';

                // Chercher dans les tableaux de juges (ckb, hkb, etc.)
                $judgeFieldMap = [
                    'C' => 'ckb',
                    'H' => 'hkb',
                    'M' => 'mkb',
                    'E' => 'ekb',
                    'B' => 'bkb'
                ];

                $judgeField = $judgeFieldMap[$judgePosition] ?? 'ckb';

                // Si on a un ID de juge dans le tableau
                if (isset($competition[$judgeField]) && !empty($competition[$judgeField])) {
                    $judgeId = $competition[$judgeField][0] ?? null;
                    if ($judgeId && isset($peopleIndex[$judgeId])) {
                        $judgeLicense = $peopleIndex[$judgeId]['rlic'] ?? '0000000';
                    }
                }
            }

            // Construire la ligne 20
            $line20 = '20';

            // Type de juge (8 caractères)
            // PDTJ pour le président (premier), ASSJ pour les assesseurs
            $judgeType = ($index === 0) ? 'PDTJ' : 'ASSJ';
            $line20 .= str_pad($judgeType, 8);

            // Numéro de licence (7 caractères, sans la lettre de contrôle)
            // Supprimer la lettre finale si présente
            $licenseNumber = preg_replace('/[A-Z]$/i', '', $judgeLicense);
            $line20 .= str_pad($licenseNumber, 7, '0', STR_PAD_LEFT);

            // Numéro d'épreuve (3 caractères)
            // Utiliser le numéro d'épreuve réel, pas "00"
            $line20 .= str_pad($epreuveNum, 3, ' ', STR_PAD_LEFT);

            $lines[] = $line20;
            $lineCount++;

            if (DEBUG_MODE) {
                writeLog("Line 20 - Judge: " . $judgeName . " / License: " . $licenseNumber . " / Epreuve: " . $epreuveNum);
            }
        }
    }


    // Ligne 99 - Fin de fichier
    $line99 = '99';
    $line99 .= str_pad($lineCount + 1, 5, '0', STR_PAD_LEFT);
    $lines[] = $line99;

    return implode("\r\n", $lines);
}
// Version alternative plus complète qui utilise les données de juges stockées

function generateJuryDeclarationLines($competition, $epreuveNum, $peopleData = [])
{
    $lines = [];

    // Mapping des positions de juges
    $judgePositions = [
        'C' => ['field' => 'domarec_kb', 'ids' => 'ckb'],
        'H' => ['field' => 'domareh_kb', 'ids' => 'hkb'],
        'M' => ['field' => 'domarem_kb', 'ids' => 'mkb'],
        'E' => ['field' => 'domaree_kb', 'ids' => 'ekb'],
        'B' => ['field' => 'domareb_kb', 'ids' => 'bkb'],
        'K' => ['field' => 'domarek_kb', 'ids' => 'kkb'],
        'F' => ['field' => 'domaref_kb', 'ids' => 'fkb']
    ];

    $judgeCount = 0;

    foreach ($judgePositions as $position => $config) {
        $judgeName = $competition[$config['field']] ?? '';
        $judgeIds = $competition[$config['ids']] ?? [];

        if (!empty($judgeName)) {
            // Nettoyer le nom (enlever le code pays)
            $judgeName = trim(str_replace(['(FRA)', '(fra)'], '', $judgeName));

            // Trouver la licence
            $judgeLicense = '0000000';

            // Si on a un ID de juge
            if (!empty($judgeIds) && isset($judgeIds[0])) {
                $judgeId = $judgeIds[0];

                // Chercher dans les données people
                foreach ($peopleData as $person) {
                    if (isset($person['id']) && $person['id'] == $judgeId) {
                        $judgeLicense = $person['rlic'] ?? '0000000';
                        break;
                    }
                }
            }

            // Si toujours pas trouvé, chercher par nom
            if ($judgeLicense === '0000000') {
                $nameParts = explode(' ', $judgeName);
                if (count($nameParts) >= 2) {
                    $lastName = strtoupper(end($nameParts));

                    foreach ($peopleData as $person) {
                        if (
                            isset($person['last_name']) &&
                            strtoupper($person['last_name']) === $lastName
                        ) {
                            $judgeLicense = $person['rlic'] ?? '0000000';
                            break;
                        }
                    }
                }
            }

            // Créer la ligne 20
            $line20 = '20';

            // Type de juge - Position C est présidente, autres sont assesseurs
            $judgeType = ($position === 'C' && $judgeCount === 0) ? 'PDTJ' : 'ASSJ';
            $line20 .= str_pad($judgeType, 8);

            // Numéro de licence (7 caractères numériques)
            $licenseNumber = preg_replace('/\D/', '', $judgeLicense);
            $line20 .= str_pad(substr($licenseNumber, 0, 7), 7, '0', STR_PAD_LEFT);

            // Numéro d'épreuve
            $line20 .= str_pad($epreuveNum, 3, ' ', STR_PAD_LEFT);

            $lines[] = $line20;
            $judgeCount++;
        }
    }

    return $lines;
}
function mapDisciplineToFFECompetCode($code)
{
    $mapping = [
        'H' => 'HU', // Hunter/Saut d'obstacles
        'D' => 'DR', // Dressage  
        'F' => 'CC', // Concours complet
        'K' => 'AT', // Attelage
        'E' => 'EN'  // Endurance
    ];
    return $mapping[$code] ?? 'HU';
}
function formatJudgePoints($points)
{
    return str_pad(number_format($points, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
}

function determineResultStatus($result)
{
    // Vérifier le champ 'or' depuis results.json (PRIORITÉ)
    if (isset($result['or']) && !empty($result['or'])) {
        switch ($result['or']) {
            case 'U':
                return 'AB';   // Retired → Abandon
            case 'D':
                return 'EL';   // Eliminated → Éliminé
            case 'S':
                return 'DI'; // Disqualified → Disqualifié  
            case 'A':
                return 'NP';   // Withdrawn → Non partant
        }
    }

    // FALLBACK : anciens champs pour compatibilité
    if (isset($result['a']) && !empty($result['a'])) {
        switch ($result['a']) {
            case 'Ö':
                return 'NP'; // Non partant
        }
    }

    return 'FI'; // Fini normalement
}

function mapDisciplineToFFECompet($code)
{
    $mapping = [
        'H' => 'SO', // Saut d'obstacles
        'D' => 'DR', // Dressage
        'F' => 'CC', // Concours complet
        'K' => 'AT', // Attelage
        'E' => 'EN'  // Endurance
    ];
    return $mapping[$code] ?? 'SO';
}
function formatJumpingResults($results)
{
    $output = [];
    $output[] = "CLASSEMENT SAUT D'OBSTACLES";
    $output[] = str_repeat("-", 80);
    $output[] = sprintf(
        "%-3s %-25s %-25s %-8s %-12s",
        "Clt",
        "Cavalier",
        "Cheval",
        "Points",
        "Temps"
    );
    $output[] = str_repeat("-", 80);

    foreach ($results as $result) {
        $output[] = sprintf(
            "%-3s %-25s %-25s %-8s %-12s",
            $result['re'] ?? 'N/A',
            substr($result['rider_name'] ?? 'N/A', 0, 25),
            substr($result['horse_name'] ?? 'N/A', 0, 25),
            $result['p'] ?? '0',
            number_format($result['t'] ?? 0, 2) . 's'
        );
    }

    return $output;
}

function mapDisciplineToSIF($code)
{
    $mapping = [
        'H' => 'CSO',
        'D' => 'DRESSAGE',
        'F' => 'CCE',
        'K' => 'ATTELAGE',
        'E' => 'ENDURANCE'
    ];
    return $mapping[$code] ?? 'CSO';
}

function mapLevelToSIF($code)
{
    $mapping = [
        'K' => 'CLUB',
        'L' => 'DEPARTEMENTAL',
        'R' => 'REGIONAL',
        'N' => 'NATIONAL',
        'E' => 'ELITE',
        'I' => 'INTERNATIONAL'
    ];
    return $mapping[$code] ?? 'CLUB';
}

function getDisciplineName($code)
{
    $mapping = [
        'H' => 'Saut d\'obstacles',
        'D' => 'Dressage',
        'F' => 'Concours complet',
        'K' => 'Attelage',
        'E' => 'Endurance'
    ];
    return $mapping[$code] ?? 'Saut d\'obstacles';
}

// Headers CORS
header("Access-Control-Allow-Origin: https://app.equipe.com");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization");

// Gérer les requêtes OPTIONS pour CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Fonction utilitaire pour envoyer une réponse JSON
function sendJsonResponse($data)
{
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode($data);
    exit;
}

// Décoder la requête JWT ou POST
$decoded = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Requête POST depuis Equipe - lire le body
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput);

    if (DEBUG_MODE && $decoded) {
        writeLog("POST decoded from Equipe:");
        writeLog("API Key: " . (isset($decoded->api_key) ? substr($decoded->api_key, 0, 10) . '...' : 'MISSING'));
        writeLog("Meeting URL: " . (isset($decoded->payload->meeting_url) ? $decoded->payload->meeting_url : 'MISSING'));
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    // Requête GET - décoder le JWT
    $key = $_ENV['EQUIPE_SECRET'] ?? '';
    $jwt = $_GET['token'] ?? '';

    if (!empty($jwt)) {
        JWT::$leeway = 60;
        try {
            $decoded = JWT::decode($jwt, new Key($key, 'HS256'));

            if (DEBUG_MODE) {
                writeLog("JWT decoded successfully:");
                writeLog("API Key: " . (isset($decoded->api_key) ? substr($decoded->api_key, 0, 10) . '...' : 'MISSING'));
                writeLog("Meeting URL: " . (isset($decoded->payload->meeting_url) ? $decoded->payload->meeting_url : 'MISSING'));
            }
        } catch (Exception $e) {
            writeLog("JWT decode error: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => "Erreur token: " . $e->getMessage()]);
        }
    }
}

// ==========================================
// TRAITER LES REQUÊTES AJAX
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'];
        $response = ['success' => false, 'error' => 'Action non reconnue'];

        switch ($action) {
            case 'test_connection':
                $response = handleTestConnection();
                break;

            case 'check_imported':
                $response = handleCheckImported();
                break;

            case 'parse_ffe_xml':
                $response = handleParseXML();
                break;

            case 'import_to_equipe':
                $response = handleImportToEquipe();
                break;

            case 'export_sif':
                $response = handleExportSIF();
                break;

            case 'export_ffecompet':
                $response = handleExportFFECompet();
                break;

            case 'update_competition_levels':
                $response = handleUpdateCompetitionLevels();
                break;

            case 'export_results':
                $response = handleExportResults();
                break;
            case 'export_ffecompet_global':
                $response = handleExportFFECompetGlobal();
                break;
            // AJOUTER CE CASE ICI
            case 'change_language':
                $lang = $_POST['lang'] ?? 'fr';
                $supportedLangs = ['fr', 'en'];

                if (in_array($lang, $supportedLangs)) {
                    $_SESSION['ffe_language'] = $lang;
                    $response = ['success' => true, 'language' => $lang];

                    if (DEBUG_MODE) {
                        writeLog("Language changed to: " . $lang);
                    }
                } else {
                    $response = ['success' => false, 'error' => 'Unsupported language'];
                }
                break;
        }

        sendJsonResponse($response);
    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => DEBUG_MODE ? $e->getTraceAsString() : null
        ]);
    }
}

// ==========================================
// FONCTIONS DE TRAITEMENT DES ACTIONS
// ==========================================
function handleExportFFECompetGlobal()
{
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';

    if (empty($apiKey) || empty($meetingUrl)) {
        return ['success' => false, 'error' => 'API Key ou URL manquant'];
    }

    try {
        // Récupérer toutes les compétitions FFE
        $competitionsUrl = rtrim($meetingUrl, '/') . '/competitions.json';

        $ch = curl_init($competitionsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $competitionsResponse = curl_exec($ch);
        curl_close($ch);

        $competitions = json_decode($competitionsResponse, true);

        // Filtrer les compétitions FFE
        $ffeCompetitions = [];
        if (is_array($competitions)) {
            foreach ($competitions as $comp) {
                if (
                    isset($comp['foreignid']) &&
                    (strpos($comp['foreignid'], 'FFE_') === 0 ||
                        preg_match('/^\d{9}_\d+$/', $comp['foreignid']))
                ) {
                    $ffeCompetitions[] = $comp;
                }
            }
        }

        if (empty($ffeCompetitions)) {
            return ['success' => false, 'error' => 'Aucune compétition FFE trouvée'];
        }

        // PREMIÈRE PASSE : Vérifier quelles compétitions ont des résultats
        $competitionsWithResults = [];

        foreach ($ffeCompetitions as $comp) {
            $compId = $comp['kq'];
            $resultsUrl = rtrim($meetingUrl, '/') . '/competitions/' . $compId . '/results.json';

            $ch = curl_init($resultsUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $apiKey]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $resultsResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $resultsResponse) {
                $results = json_decode($resultsResponse, true);

                // Vérifier qu'il y a au moins un résultat valide
                $hasValidResults = false;
                if (is_array($results)) {
                    foreach ($results as $result) {
                        if (isset($result['re']) && $result['re'] != 999) {
                            $hasValidResults = true;
                            break;
                        }
                    }
                }

                if ($hasValidResults) {
                    $comp['results'] = $results; // Stocker les résultats pour éviter de refaire la requête
                    $competitionsWithResults[] = $comp;
                }
            }
        }

        if (empty($competitionsWithResults)) {
            return ['success' => false, 'error' => 'Aucune compétition avec des résultats trouvée'];
        }

        // Grouper par discipline et numéro de concours
        $groupedByDiscipline = [];
        foreach ($competitionsWithResults as $comp) {
            $discipline = mapDisciplineToFFECompetCode($comp['z'] ?? 'SO');
            $foreignId = $comp['foreignid'] ?? '';
            $parts = explode('_', $foreignId);
            $concoursNum = $parts[0];

            if (!isset($groupedByDiscipline[$discipline])) {
                $groupedByDiscipline[$discipline] = [];
            }
            if (!isset($groupedByDiscipline[$discipline][$concoursNum])) {
                $groupedByDiscipline[$discipline][$concoursNum] = [];
            }
            $groupedByDiscipline[$discipline][$concoursNum][] = $comp;
        }

        // Récupérer les données people une seule fois
        $peopleUrl = rtrim($meetingUrl, '/') . '/people.json';
        $ch = curl_init($peopleUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $peopleResponse = curl_exec($ch);
        curl_close($ch);

        $people = json_decode($peopleResponse, true);
        $peopleIndex = [];
        if (is_array($people)) {
            foreach ($people as $person) {
                if (isset($person['rnr'])) {
                    $peopleIndex[$person['rnr']] = $person;
                }
            }
        }

        // Créer un fichier pour chaque discipline/concours
        $allFiles = [];

        foreach ($groupedByDiscipline as $discipline => $concoursList) {
            foreach ($concoursList as $concoursNum => $competitions) {
                $lines = [];
                $lineCount = 0;

                // Ligne 00 - Début de fichier
                $line00 = '00';
                $line00 .= date('d/m/Y H:i:s');
                $line00 .= 'V024FFECompet Export Global';
                $line00 = str_pad($line00, 121, ' ');
                $lines[] = $line00;
                $lineCount++;

                // Ligne 01 - Concours
                $line01 = '01';
                $line01 .= str_pad($concoursNum, 9, '0', STR_PAD_LEFT);
                $line01 .= $discipline;
                // IMPORTANT : Nombre d'épreuves AVEC résultats
                $line01 .= str_pad(count($competitions), 2, '0', STR_PAD_LEFT);
                $lines[] = $line01;
                $lineCount++;

                // Pour chaque épreuve
                foreach ($competitions as $competition) {
                    $epreuveNum = explode('_', $competition['foreignid'])[1] ?? '1';
                    $results = $competition['results']; // Utiliser les résultats déjà récupérés

                    // Enrichir les résultats
                    $validResults = [];
                    foreach ($results as $result) {
                        if (isset($result['re']) && $result['re'] != 999) {
                            $riderId = $result['rnr'] ?? null;
                            $riderLicense = '9999999';

                            if ($riderId && isset($peopleIndex[$riderId])) {
                                $rider = $peopleIndex[$riderId];
                                $riderLicense = $rider['rlic'] ?? '9999999';
                            }

                            $result['rider_license'] = $riderLicense;
                            $result['horse_sire'] = $result['horse_sire'] ?? '50053829';
                            $validResults[] = $result;
                        }
                    }

                    // Ligne 02 - Épreuve
                    $line02 = '02';
                    $line02 .= str_pad($epreuveNum, 3, '0', STR_PAD_LEFT);
                    $line02 .= str_pad(count($validResults), 3, '0', STR_PAD_LEFT);
                    $lines[] = $line02;
                    $lineCount++;

                    // Lignes de résultats
                    foreach ($validResults as $result) {
                        if ($competition['z'] === 'D') {
                            $lineResult = generateDressageResultLine($result);
                        } else {
                            $lineResult = generateJumpingResultLine($result);
                        }
                        $lines[] = $lineResult;
                        $lineCount++;
                    }
                }

                // Ligne 99 - Fin de fichier
                $line99 = '99';
                $line99 .= str_pad($lineCount, 5, '0', STR_PAD_LEFT);
                $lines[] = $line99;

                $content = implode("\r\n", $lines);
                $filename = 'ffecompet_' . $concoursNum . '_' . $discipline . '_' . date('YmdHis') . '.txt';

                $allFiles[] = [
                    'filename' => $filename,
                    'content' => base64_encode($content)
                ];
            }
        }

        // Si un seul fichier, le retourner directement
        if (count($allFiles) === 1) {
            return [
                'success' => true,
                'filename' => $allFiles[0]['filename'],
                'content' => $allFiles[0]['content']
            ];
        }

        // Si plusieurs fichiers, retourner le premier (ou implémenter un ZIP)
        return [
            'success' => true,
            'filename' => $allFiles[0]['filename'],
            'content' => $allFiles[0]['content'],
            'message' => count($allFiles) . ' fichiers générés'
        ];
    } catch (Exception $e) {
        if (DEBUG_MODE) {
            writeLog("Error in handleExportFFECompetGlobal: " . $e->getMessage());
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
function handleTestConnection()
{
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';

    // Log détaillé pour debug
    writeLog("=== Test Connection Debug ===");
    writeLog("Raw API Key: " . ($apiKey ? substr($apiKey, 0, 10) . '...' : 'EMPTY'));
    writeLog("Raw Meeting URL: " . $meetingUrl);
    writeLog("POST data: " . print_r($_POST, true));

    if (empty($apiKey) || empty($meetingUrl)) {
        writeLog("Missing API Key or URL");
        return ['success' => false, 'error' => 'API Key ou URL manquante'];
    }

    // Nettoyer l'URL
    $meetingUrl = rtrim(trim($meetingUrl), '/');
    $url = $meetingUrl . '/competitions.json';

    writeLog("Final URL for test: " . $url);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Api-Key: " . $apiKey,
        "Accept: application/json",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Ajouter plus de détails de debug
    if (DEBUG_MODE) {
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if (DEBUG_MODE && isset($verbose)) {
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        writeLog("CURL verbose output: " . $verboseLog);
    }

    curl_close($ch);

    writeLog("HTTP Code: " . $httpCode);
    writeLog("Response (first 200 chars): " . substr($response, 0, 200));

    if ($error) {
        return ['success' => false, 'error' => 'Erreur CURL: ' . $error];
    }

    if ($httpCode === 200 || $httpCode === 204 || $httpCode === 404) {
        return ['success' => true, 'message' => 'Connexion réussie', 'http_code' => $httpCode];
    }

    if ($httpCode === 401 || $httpCode === 403) {
        return ['success' => false, 'error' => 'Erreur d\'authentification (HTTP ' . $httpCode . ')'];
    }

    return ['success' => false, 'error' => 'Erreur HTTP: ' . $httpCode];
}

function handleCheckImported()
{
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';

    if (empty($apiKey) || empty($meetingUrl)) {
        return ['success' => false, 'error' => 'API Key ou URL manquante'];
    }

    try {
        // Nettoyer l'URL
        $meetingUrl = rtrim(trim($meetingUrl), '/');
        $url = $meetingUrl . '/competitions.json';

        if (DEBUG_MODE) {
            writeLog("=== handleCheckImported ===");
            writeLog("Fetching competitions from: " . $url);
        }

        // Faire la requête pour récupérer les compétitions
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Api-Key: " . $apiKey,
            "Accept: application/json",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (DEBUG_MODE) {
            writeLog("HTTP Code: " . $httpCode);
            writeLog("Response length: " . strlen($response));
            if ($response) {
                writeLog("Response preview: " . substr($response, 0, 500));
            }
        }

        if ($error) {
            return [
                'success' => false,
                'error' => 'Erreur CURL: ' . $error,
                'has_imports' => false,
                'competitions' => []
            ];
        }

        if ($httpCode === 404) {
            // Pas de compétitions
            return [
                'success' => true,
                'has_imports' => false,
                'competitions' => []
            ];
        }

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $httpCode,
                'has_imports' => false,
                'competitions' => []
            ];
        }

        // Parser la réponse JSON
        $competitions = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (DEBUG_MODE) {
                writeLog("JSON decode error: " . json_last_error_msg());
            }
            return [
                'success' => false,
                'error' => 'Invalid JSON response',
                'has_imports' => false,
                'competitions' => []
            ];
        }

        // S'assurer que c'est un tableau
        if (!is_array($competitions)) {
            $competitions = [];
        }

        // Filtrer uniquement les compétitions FFE
        $ffeCompetitions = array_filter($competitions, function ($comp) {
            if (!isset($comp['foreignid'])) return false;

            // Accepter les formats "202501009_2" ou "FFE_XXX" (ancien format)
            return (strpos($comp['foreignid'], 'FFE_') === 0) ||
                (preg_match('/^\d{9}_\d+$/', $comp['foreignid']));
        });

        // Réindexer le tableau
        $ffeCompetitions = array_values($ffeCompetitions);

        if (DEBUG_MODE) {
            writeLog("Total competitions: " . count($competitions));
            writeLog("FFE competitions: " . count($ffeCompetitions));
            if (count($ffeCompetitions) > 0) {
                writeLog("First FFE competition: " . json_encode($ffeCompetitions[0]));
            }
        }

        // Ajouter une propriété has_results pour chaque compétition
        // Dans handleCheckImported(), remplacer la section de vérification des résultats par :
        foreach ($ffeCompetitions as &$comp) {
            $comp['has_results'] = false;

            // Vérifier s'il y a des engagements


            // URL pour récupérer les résultats
            $resultsUrl = $meetingUrl . '/competitions/' . $comp['kq'] . '/results.json';

            if (DEBUG_MODE) {
                writeLog("Checking results for competition " . $comp['kq'] . " at: " . $resultsUrl);
            }

            $ch = curl_init($resultsUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $resultsResponse = curl_exec($ch);
            $resultsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resultsHttpCode === 200 && $resultsResponse) {
                $results = json_decode($resultsResponse, true);

                if (is_array($results)) {
                    // Vérifier qu'au moins un résultat a un classement différent de 999
                    foreach ($results as $result) {
                        if (isset($result['re']) && $result['re'] != 999) {
                            $comp['has_results'] = true;

                            if (DEBUG_MODE) {
                                writeLog("Found valid result for competition " . $comp['kq'] . " - classement: " . $result['re']);
                            }
                            break; // Un seul résultat valide suffit
                        }
                    }

                    if (DEBUG_MODE && !$comp['has_results']) {
                        writeLog("No valid results found for competition " . $comp['kq'] . " - all results have re=999");
                    }
                }
            } else {
                if (DEBUG_MODE) {
                    writeLog("Failed to get results for competition " . $comp['kq'] . " - HTTP: " . $resultsHttpCode);
                }
            }
        }

        return [
            'success' => true,
            'has_imports' => count($ffeCompetitions) > 0,
            'competitions' => $ffeCompetitions
        ];
    } catch (Exception $e) {
        if (DEBUG_MODE) {
            writeLog("Error in handleCheckImported: " . $e->getMessage());
            writeLog("Stack trace: " . $e->getTraceAsString());
        }

        return [
            'success' => false,
            'error' => $e->getMessage(),
            'has_imports' => false,
            'competitions' => []
        ];
    }
}

function handleParseXML()
{
    // Vérifier qu'un fichier a été uploadé
    if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Aucun fichier XML valide'];
    }

    try {
        $xmlContent = file_get_contents($_FILES['xml_file']['tmp_name']);

        // Parser le XML
        $parser = new FFEXmlParser();
        $parsedData = $parser->parseForEquipe($xmlContent);

        // Stocker en session pour l'import
        $_SESSION['ffe_parsed_data'] = $parsedData;
        $_SESSION['ffe_concours_info'] = $parsedData['concours'];

        if (DEBUG_MODE) {
            writeLog("FFE data parsed and stored in session");
            writeLog("Competitions found: " . count($parsedData['competitions']));
            writeLog("Officials found: " . count($parsedData['officials'] ?? []));
        }

        // Retourner un résumé pour l'affichage
        return [
            'success' => true,
            'session_id' => session_id(),
            'concours' => $parsedData['concours'],
            'stats' => [
                'competitions' => count($parsedData['competitions']),
                'people' => count($parsedData['people']),
                'officials' => count($parsedData['officials'] ?? []),  // Ajouter le compte des officiels
                'horses' => count($parsedData['horses']),
                'clubs' => count($parsedData['clubs']),
                'total_starts' => array_sum(array_map('count', $parsedData['starts']))
            ],
            'competitions' => $parsedData['competitions'],
            'officials' => $parsedData['officials'] ?? []  // Inclure les officiels dans la réponse
        ];
    } catch (Exception $e) {
        if (DEBUG_MODE) {
            writeLog("Error parsing FFE XML: " . $e->getMessage());
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
function handleImportToEquipe()
{
    if (!isset($_SESSION['ffe_parsed_data'])) {
        return ['success' => false, 'error' => 'Aucune donnée à importer. Veuillez d\'abord analyser un fichier XML.'];
    }
    // Marquer l'import comme en cours
    $_SESSION['import_in_progress'] = true;
    $importId = uniqid('import_', true);
    if (DEBUG_MODE) {
        writeLog("=== IMPORT START $importId ===");
        writeLog("POST data: " . json_encode($_POST));
        writeLog("Session ID: " . session_id());
    }
    $parsedData = $_SESSION['ffe_parsed_data'];
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';
    $selectedCompetitions = json_decode($_POST['selected_competitions'] ?? '[]', true);

    // IMPORTANT: Récupérer le niveau sélectionné
    $selectedLevel = $_POST['competition_level'] ?? 'I';

    if (empty($apiKey) || empty($meetingUrl)) {
        return ['success' => false, 'error' => 'API Key ou URL manquante'];
    }

    // Appliquer le niveau sélectionné à toutes les compétitions
    foreach ($parsedData['competitions'] as &$competition) {
        // Appliquer le niveau uniquement aux compétitions sélectionnées
        if (in_array($competition['foreign_id'], $selectedCompetitions)) {
            $competition['x'] = $selectedLevel;

            if (DEBUG_MODE) {
                writeLog("Setting competition " . $competition['foreign_id'] . " level to: " . $selectedLevel);
            }
        }
    }

    // Mettre à jour la session avec les niveaux modifiés
    $_SESSION['ffe_parsed_data'] = $parsedData;
    if (!isset($_SESSION['ffe_parsed_data'])) {
        $_SESSION['import_in_progress'] = false;
        return ['success' => false, 'error' => 'Aucune donnée à importer'];
    }
    try {

        $sender = new EquipeApiSender($apiKey, $meetingUrl, DEBUG_MODE);
        $result = $sender->sendDataInOrder($parsedData, $selectedCompetitions);


        if ($result['success']) {
            $message = 'Import terminé avec succès';
            if (isset($result['results']['custom_fields'])) {
                $message .= ' (custom fields configurés)';
            }

            return [
                'success' => true,
                'message' => $message,
                'results' => $result['results']
            ];
        } else {
            return [
                'success' => false,
                'error' => $result['error'],
                'results' => $result['results']
            ];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        // Toujours libérer le verrou
        $_SESSION['import_in_progress'] = false;
    }
}

function handleExportSIF()
{
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';
    $competitionId = $_POST['competition_id'] ?? null;

    try {
        $exporter = new FFESIFExporter($apiKey, $meetingUrl, DEBUG_MODE);
        return $exporter->export($competitionId);
    } catch (Exception $e) {
        if (DEBUG_MODE) {
            writeLog("Error in handleExportSIF: " . $e->getMessage());
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
function handleExportFFECompet()
{
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';
    $competitionId = $_POST['competition_id'] ?? null;

    if (empty($apiKey) || empty($meetingUrl) || empty($competitionId)) {
        return ['success' => false, 'error' => 'API Key, URL ou ID compétition manquant'];
    }

    try {
        // URLs des endpoints
        $competitionsUrl = rtrim($meetingUrl, '/') . '/competitions.json';
        $resultsUrl = rtrim($meetingUrl, '/') . '/competitions/' . $competitionId . '/results.json';
        $peopleUrl = rtrim($meetingUrl, '/') . '/people.json';

        // Récupérer liste des compétitions
        $ch = curl_init($competitionsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $competitionsResponse = curl_exec($ch);
        curl_close($ch);

        // Récupérer résultats
        $ch = curl_init($resultsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $resultsResponse = curl_exec($ch);
        curl_close($ch);

        // Récupérer les données des personnes (cavaliers ET juges)
        $ch = curl_init($peopleUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: " . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $peopleResponse = curl_exec($ch);
        curl_close($ch);

        $competitions = json_decode($competitionsResponse, true);
        $results = json_decode($resultsResponse, true);
        $people = json_decode($peopleResponse, true);

        // Trouver la compétition spécifique
        $competition = null;
        if (is_array($competitions)) {
            foreach ($competitions as $comp) {
                if (isset($comp['kq']) && $comp['kq'] == $competitionId) {
                    $competition = $comp;
                    break;
                }
            }
        }

        // Créer un index des personnes par ID
        $peopleIndex = [];
        if (is_array($people)) {
            foreach ($people as $person) {
                // Pour les officiels et cavaliers, utiliser 'rnr'
                if (isset($person['rnr'])) {
                    $peopleIndex[$person['rnr']] = $person;
                }
                // Aussi indexer par 'id' si différent de 'rnr'
                if (isset($person['id']) && $person['id'] != $person['rnr']) {
                    $peopleIndex[$person['id']] = $person;
                }
            }
        }

        if (!$competition || !$results) {
            return ['success' => false, 'error' => 'Compétition non trouvée ou pas de résultats'];
        }

        // Enrichir les résultats avec les données des cavaliers
        $enrichedResults = [];
        foreach ($results as $result) {
            if (isset($result['re']) && $result['re'] != 999) {
                // Récupérer la licence du cavalier
                $riderId = $result['rnr'] ?? null;
                $riderLicense = '9999999';

                if ($riderId && isset($peopleIndex[$riderId])) {
                    $rider = $peopleIndex[$riderId];
                    $riderLicense = $rider['rlic'] ?? '9999999';
                }

                $result['rider_license'] = $riderLicense;

                // Récupérer aussi le SIRE du cheval si disponible
                $horseId = $result['hnr'] ?? null;
                if ($horseId) {
                    // On pourrait chercher dans horses.json si disponible
                    $result['horse_sire'] = $result['horse_sire'] ?? '50053829';
                }

                $enrichedResults[] = $result;
            }
        }

        if (empty($enrichedResults)) {
            return ['success' => false, 'error' => 'Aucun résultat classé trouvé'];
        }

        // Enrichir la compétition avec les données des juges
        $judgeData = [];

        // Mapping des positions de juges avec leurs IDs
        $judgePositions = [
            'C' => ['field' => 'domarec_kb', 'ids' => 'ckb'],
            'H' => ['field' => 'domareh_kb', 'ids' => 'hkb'],
            'M' => ['field' => 'domarem_kb', 'ids' => 'mkb'],
            'E' => ['field' => 'domaree_kb', 'ids' => 'ekb'],
            'B' => ['field' => 'domareb_kb', 'ids' => 'bkb']
        ];

        foreach ($judgePositions as $position => $config) {
            $judgeName = $competition[$config['field']] ?? '';
            $judgeIds = $competition[$config['ids']] ?? [];

            if (!empty($judgeName) && !empty($judgeIds)) {
                $judgeId = $judgeIds[0] ?? null;

                if (!empty($judgeIds) && isset($judgeIds[0])) {
                    $judgeId = $judgeIds[0];

                    // L'ID correspond au champ 'rnr' pour les juges
                    if (isset($peopleIndex[$judgeId])) {
                        $judge = $peopleIndex[$judgeId];
                        $judgeLicense = $judge['rlic'] ?? '';

                        // Nettoyer: "0506118T" → "0506118"
                        $judgeLicense = preg_replace('/[A-Z]$/i', '', $judgeLicense);
                    }
                }
            }
        }
        // Dans handleExportFFECompet, après avoir récupéré les people
        // Il faut s'assurer que les juges sont bien dans les données people

        // DEBUG - Vérifier si les juges sont dans people
        if (DEBUG_MODE) {
            writeLog("=== JURY DATA DEBUG ===");
            writeLog("Competition " . $competitionId . " judges:");

            // Pour la position C
            if (isset($competition['ckb']) && !empty($competition['ckb'])) {
                $judgeId = $competition['ckb'][0];
                writeLog("Judge C ID: " . $judgeId);

                if (isset($peopleIndex[$judgeId])) {
                    writeLog("Judge C found: " . json_encode($peopleIndex[$judgeId]));
                } else {
                    // Le juge n'est pas dans peopleIndex, cherchons dans people array
                    foreach ($people as $person) {
                        if ($person['id'] == $judgeId) {
                            writeLog("Judge C found in people array: " . json_encode($person));
                            break;
                        }
                    }
                }
            }
        }
        // Ajouter les données de juges à la compétition
        $competition['judge_data'] = $judgeData;

        // Générer le fichier FFECompet avec toutes les données
        $content = generateFFECompetTXTWithJudges($competition, $enrichedResults, $peopleIndex);

        $filename = 'ffecompet_' . ($competition['clabb'] ?? $competitionId) . '_' . date('YmdHis') . '.txt';

        return [
            'success' => true,
            'filename' => $filename,
            'content' => base64_encode($content)
        ];
    } catch (Exception $e) {
        if (DEBUG_MODE) {
            writeLog("Error in handleExportFFECompet: " . $e->getMessage());
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Générer les lignes 21 et 22 pour engagement terrain et invitation organisateur
 */
function generateCustomFieldLines($results, $epreuveNum)
{
    $lines = [];
    if (DEBUG_MODE) {
        writeLog("=== CUSTOM FIELDS DEBUG ===");
        foreach ($results as $result) {
            if (isset($result['start_custom_fields'])) {
                $fields = $result['start_custom_fields'];
                writeLog("Dossard " . ($result['st'] ?? 'N/A') . ":");
                writeLog("  engagement_terrain: " . (isset($fields['engagement_terrain']) ? ($fields['engagement_terrain'] ? 'true' : 'false') : 'not set'));
                writeLog("  invitation_organisateur: " . (isset($fields['invitation_organisateur']) ? ($fields['invitation_organisateur'] ? 'true' : 'false') : 'not set'));
            }
        }
    }
    foreach ($results as $result) {
        // Vérifier start_custom_fields
        if (!isset($result['start_custom_fields'])) {
            continue;
        }

        $customFields = $result['start_custom_fields'];
        $dossard = $result['st'] ?? '1';

        // Ligne 21 - Engagement Terrain
        if (isset($customFields['engagement_terrain']) && $customFields['engagement_terrain'] === true) {
            $line21 = generateEngagementTerrainLine($result, $epreuveNum);
            if ($line21) {
                $lines[] = $line21;
            }
        }

        // Ligne 22 - Invitation Organisateur  
        if (isset($customFields['invitation_organisateur']) && $customFields['invitation_organisateur'] === true) {
            $line22 = generateInvitationOrganisateurLine($result, $epreuveNum);
            if ($line22) {
                $lines[] = $line22;
            }
        }
    }

    return $lines;
}

/**
 * Générer ligne 21 - Engagement Terrain
 */
function generateEngagementTerrainLine($result, $epreuveNum)
{
    $line21 = '21';                                                    // Code (2 chars)
    $line21 .= str_pad($epreuveNum, 3, ' ', STR_PAD_LEFT);           // Numéro épreuve (3 chars)
    $line21 .= str_pad($result['st'] ?? '1', 3, '0', STR_PAD_LEFT);   // Dossard (3 chars)

    // Numéro du compte engageur (7 chars) - à récupérer depuis les données
    $compteEngageur = $result['compte_engageur'] ?? '2547867';
    $line21 .= str_pad($compteEngageur, 7);

    // Numéro de licence du cavalier sans lettre clé (7 chars)
    $licenceCavalier = $result['rider_license'] ?? '1214584';
    $licenceCavalier = preg_replace('/[A-Z]$/i', '', $licenceCavalier); // Supprimer lettre finale
    $line21 .= str_pad($licenceCavalier, 7);

    // Numéro SIRE du cheval sans lettre clé (8 chars)
    $sireCHeval = $result['horse_sire'] ?? '25478674';
    $sireCHeval = preg_replace('/[A-Z]$/i', '', $sireCHeval);
    $line21 .= str_pad($sireCHeval, 8);

    // Numéro de licence du compte engageur sans lettre clé (7 chars)
    $licenceEngageur = $result['licence_engageur'] ?? '1345616';
    $licenceEngageur = preg_replace('/[A-Z]$/i', '', $licenceEngageur);
    $line21 .= str_pad($licenceEngageur, 7);

    // Numéro de club du compte engageur (7 chars)
    $clubEngageur = $result['club_engageur'] ?? '1234845';
    $line21 .= str_pad($clubEngageur, 7);

    return $line21;
}

/**
 * Générer ligne 22 - Invitation Organisateur
 */
function generateInvitationOrganisateurLine($result, $epreuveNum)
{
    $line22 = '22';                                                    // Code (2 chars)
    $line22 .= str_pad($epreuveNum, 3, ' ', STR_PAD_LEFT);           // Numéro épreuve (3 chars)
    $line22 .= str_pad($result['st'] ?? '1', 3, '0', STR_PAD_LEFT);   // Dossard (3 chars)

    // Même structure que ligne 21 mais code différent
    $compteEngageur = $result['compte_engageur'] ?? '2547867';
    $line22 .= str_pad($compteEngageur, 7);

    $licenceCavalier = $result['rider_license'] ?? '1214584';
    $licenceCavalier = preg_replace('/[A-Z]$/i', '', $licenceCavalier);
    $line22 .= str_pad($licenceCavalier, 7);

    $sireCHeval = $result['horse_sire'] ?? '25478674';
    $sireCHeval = preg_replace('/[A-Z]$/i', '', $sireCHeval);
    $line22 .= str_pad($sireCHeval, 8);

    $licenceEngageur = $result['licence_engageur'] ?? '1345616';
    $licenceEngageur = preg_replace('/[A-Z]$/i', '', $licenceEngageur);
    $line22 .= str_pad($licenceEngageur, 7);

    $clubEngageur = $result['club_engageur'] ?? '1234845';
    $line22 .= str_pad($clubEngageur, 7);

    return $line22;
}

function generateFFECompetTXTWithJudges($competition, $results, $peopleIndex = [])
{
    $lines = [];
    $lineCount = 0;

    // Ligne 00 - Début de fichier
    $line00 = '00';
    $line00 .= date('d/m/Y H:i:s');
    $line00 .= 'V024FFECompet Export Equipe';
    $line00 = str_pad($line00, 121, ' ');
    $lines[] = $line00;
    $lineCount++;

    // Extraire le numéro du concours
    $foreignId = $competition['foreignid'] ?? $competition['foreign_id'] ?? '';
    $parts = explode('_', $foreignId);
    $concoursNum = $parts[0];
    $epreuveNum = $parts[1] ?? ($competition['clabb'] ?? '1');

    // Ligne 01 - Concours
    $line01 = '01';
    $line01 .= str_pad($concoursNum, 9, '0', STR_PAD_LEFT);
    $line01 .= mapDisciplineToFFECompetCode($competition['z'] ?? 'SO');

    // Pour un export individuel, toujours 1 épreuve
    $nombreEpreuves = 1;

    $line01 .= str_pad($nombreEpreuves, 2, '0', STR_PAD_LEFT);
    $lines[] = $line01;
    $lineCount++;



    // Ligne 02 - Déclaration de l'épreuve
    $line02 = '02';
    $line02 .= str_pad($epreuveNum, 3, ' ', STR_PAD_LEFT);  // 3 caractères, espaces à droite
    $line02 .= str_pad(count($results), 3, ' ', STR_PAD_LEFT);  // 3 caractères, espaces à droite
    $lines[] = $line02;
    $lineCount++;
    // Après la boucle des résultats, avant les lignes 20 (jury)
    // Générer les lignes 21 et 22 pour les custom fields
    $customLines = generateCustomFieldLines($results, $epreuveNum);
    foreach ($customLines as $customLine) {
        $lines[] = $customLine;
        $lineCount++;
    }
    // Ligne 20 - Déclaration Jury
    // Gérer les juges avec leurs licences
    $judgePositions = [
        'C' => ['field' => 'domarec_kb', 'ids' => 'ckb'],
        'H' => ['field' => 'domareh_kb', 'ids' => 'hkb'],
        'M' => ['field' => 'domarem_kb', 'ids' => 'mkb'],
        'E' => ['field' => 'domaree_kb', 'ids' => 'ekb'],
        'B' => ['field' => 'domareb_kb', 'ids' => 'bkb']
    ];

    $judgeCount = 0;
    foreach ($judgePositions as $position => $config) {
        $judgeName = $competition[$config['field']] ?? '';
        $judgeIds = $competition[$config['ids']] ?? [];

        if (!empty($judgeName)) {
            $line20 = '20';

            // Type de juge
            $judgeType = ($position === 'C' && $judgeCount === 0) ? 'PDTJ' : 'ASSJ';
            $line20 .= str_pad($judgeType, 8);

            // Trouver la licence du juge
            $judgeLicense = '';
            if (!empty($judgeIds) && isset($judgeIds[0])) {
                $judgeId = $judgeIds[0];
                if (isset($peopleIndex[$judgeId])) {
                    $judgeLicense = $peopleIndex[$judgeId]['rlic'] ?? '';
                }
            }

            // Si pas de licence trouvée, utiliser une valeur par défaut
            if (empty($judgeLicense)) {
                $judgeLicense = '0000000';
            }

            // Nettoyer la licence (garder seulement les chiffres, max 7)
            $judgeLicense = preg_replace('/\D/', '', $judgeLicense);
            $judgeLicense = str_pad(substr($judgeLicense, 0, 7), 7, '0', STR_PAD_LEFT);

            $line20 .= $judgeLicense;

            // Numéro d'épreuve (3 caractères, aligné à droite)
            $line20 .= str_pad($epreuveNum, 3, ' ', STR_PAD_LEFT);

            $lines[] = $line20;
            $lineCount++;
            $judgeCount++;

            if (DEBUG_MODE) {
                writeLog("Judge $position: $judgeName - License: $judgeLicense");
            }
        }
    }
    // Trier les résultats
    usort($results, function ($a, $b) {
        return ($a['re'] ?? 999) - ($b['re'] ?? 999);
    });

    $discipline = $competition['z'] ?? 'H';

    // Générer les lignes de résultats (05, 06, 07, 08 selon discipline)
    foreach ($results as $result) {
        if (isset($result['re']) && $result['re'] != 999) {
            if ($discipline === 'D') {
                // Ligne 06 pour Dressage
                $line06 = generateDressageResultLine($result);
                $lines[] = $line06;
                $lineCount++;
            } else {
                // Ligne 05 pour Saut d'obstacles
                $line05 = generateJumpingResultLine($result);
                $lines[] = $line05;
                $lineCount++;
            }
        }
    }



    // Ligne 99 - Fin de fichier
    $line99 = '99';
    $line99 .= str_pad($lineCount, 5, '0', STR_PAD_LEFT);
    $lines[] = $line99;

    return implode("\r\n", $lines);
}

function generateDressageResultLine($result)
{
    $line06 = '06';

    // Positions selon le format FFE V24
    $line06 .= str_pad($result['st'] ?? '0', 3, '0', STR_PAD_LEFT);
    $line06 .= str_pad($result['horse_sire'] ?? '50053829', 8);
    $line06 .= str_pad($result['rider_license'] ?? '9999999', 7);
    $etat = determineResultStatus($result);
    $line06 .= $etat;
    if ($etat !== 'FI') {
        // Pas de scores pour les états spéciaux
        return $line06;
    }
    // Points des juges
    $pointsJugeC = $result['ct'] ?? 0;
    $pointsJugeH = $result['ht'] ?? 0;
    $pointsJugeM = $result['mt'] ?? 0;
    $pointsJugeB = $result['bt'] ?? 0;
    $pointsJugeE = $result['et'] ?? 0;

    $line06 .= str_pad(number_format($pointsJugeC, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
    $line06 .= str_pad(number_format($pointsJugeH, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
    $line06 .= str_pad(number_format($pointsJugeM, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
    $line06 .= str_pad(number_format($pointsJugeB, 2, ',', ''), 6, ' ', STR_PAD_LEFT);
    $line06 .= str_pad(number_format($pointsJugeE, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

    // Moyenne et total
    $line06 .= str_pad(number_format($result['gproc'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    $line06 .= str_pad(number_format($result['psum'] ?? 0, 2, ',', ''), 7, ' ', STR_PAD_LEFT);

    // Note Artistique = somme des points des juges
    $noteArtistique = $pointsJugeC + $pointsJugeH + $pointsJugeM + $pointsJugeB + $pointsJugeE;
    $line06 .= str_pad(number_format($noteArtistique, 2, ',', ''), 6, ' ', STR_PAD_LEFT);

    // Place et gains
    $line06 .= str_pad($result['re'], 3, ' ', STR_PAD_LEFT);
    $line06 .= str_pad(number_format($result['premie'] ?? 0, 2, ',', ''), 8, ' ', STR_PAD_LEFT);

    // Espaces pour les champs optionnels
    $line06 .= str_repeat(' ', 13);

    // Pourcentages des juges

    $line06 .= str_pad(number_format($result['csp'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    if ($result['hsp']) {
        $line06 .= str_pad(number_format($result['hsp'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    }
    if ($result['msp']) {
        $line06 .= str_pad(number_format($result['msp'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    }
    if ($result['bsp']) {
        $line06 .= str_pad(number_format($result['bsp'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    }
    if ($result['esp']) {
        $line06 .= str_pad(number_format($result['esp'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    }


    return $line06;
}
function generateJumpingResultLine($result)
{
    $line05 = '05';

    $line05 .= str_pad($result['st'] ?? '0', 3, '0', STR_PAD_LEFT);
    $line05 .= str_pad($result['horse_sire'] ?? '50053829', 8);
    $line05 .= str_pad($result['rider_license'] ?? '9999999', 7);
    $etat = determineResultStatus($result);
    $line05 .= $etat;

    // Si état spécial, ligne simplifiée
    if ($etat !== 'FI') {
        return $line05;
    }
    // Points et temps
    $line05 .= str_pad(number_format($result['p'] ?? 0, 2, ',', ''), 7, ' ', STR_PAD_LEFT);
    $line05 .= str_pad(number_format($result['p2'] ?? 0, 2, ',', ''), 7, ' ', STR_PAD_LEFT);
    $line05 .= str_pad(number_format($result['t'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);
    $line05 .= str_pad(number_format($result['t2'] ?? 0, 3, ',', ''), 7, ' ', STR_PAD_LEFT);

    // Indice et présentation
    $line05 .= str_pad($result['indice'] ?? '', 2, ' ');
    $line05 .= str_pad(number_format($result['presentation'] ?? 0, 3, ',', ''), 6, ' ', STR_PAD_LEFT);

    // Place et gains
    $line05 .= str_pad($result['re'], 3, ' ', STR_PAD_LEFT);
    $line05 .= str_pad(number_format($result['premie'] ?? 0, 2, ',', ''), 8, ' ', STR_PAD_LEFT);

    // Espaces pour primes
    $line05 .= str_repeat(' ', 12);

    return $line05;
}
function handleUpdateCompetitionLevels()
{
    $competitions = json_decode($_POST['competitions'] ?? '[]', true);
    $level = $_POST['level'] ?? 'I';

    if (empty($competitions)) {
        return ['success' => false, 'error' => 'Aucune compétition à mettre à jour'];
    }

    // Mettre à jour les niveaux dans la session si nécessaire
    if (isset($_SESSION['ffe_parsed_data'])) {
        foreach ($_SESSION['ffe_parsed_data']['competitions'] as &$comp) {
            if (in_array($comp['foreign_id'], $competitions)) {
                $comp['x'] = $level;
            }
        }
    }

    return ['success' => true, 'message' => 'Niveaux mis à jour'];
}

function handleExportResults()
{
    $competitionId = $_POST['competition_id'] ?? '';
    $competitionName = $_POST['competition_name'] ?? '';
    $apiKey = $_POST['api_key'] ?? '';
    $meetingUrl = $_POST['meeting_url'] ?? '';
    $discipline = $_POST['discipline'] ?? '01';

    if (empty($competitionId) || empty($apiKey) || empty($meetingUrl)) {
        return ['success' => false, 'error' => 'Paramètres manquants'];
    }

    try {
        $sender = new EquipeApiSender($apiKey, $meetingUrl, DEBUG_MODE);

        // Récupérer les résultats depuis Equipe
        $results = $sender->getCompetitionResults($competitionId);

        if (empty($results)) {
            return ['success' => false, 'error' => 'Aucun résultat trouvé pour cette compétition'];
        }

        // Formatter les résultats
        $formatter = new FFEResultFormatter(DEBUG_MODE);
        $competition = [
            'klass' => $competitionName,
            'clabb' => $_POST['competition_clabb'] ?? '',
            'datum' => $_POST['competition_date'] ?? date('Y-m-d'),
            'categorie' => $_POST['competition_category'] ?? ''
        ];

        $formattedResults = $formatter->formatResults($competition, $results, $discipline);

        // Créer le fichier
        $filename = 'RES_FFE_' . preg_replace('/[^A-Za-z0-9]/', '_', $competitionName) . '_' . date('YmdHis') . '.txt';

        return [
            'success' => true,
            'filename' => $filename,
            'content' => base64_encode($formattedResults),
            'preview' => substr($formattedResults, 0, 500)
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ==========================================
// AFFICHAGE DE L'INTERFACE
// ==========================================

if ($decoded && isset($decoded->payload->target)) {
    // Si l'appel demande une réponse API JSON
    if ($decoded->payload->target == "api") {
        sendJsonResponse([
            'success' => true,
            'api_key' => $decoded->api_key ?? '',
            'meeting_url' => $decoded->payload->meeting_url ?? ''
        ]);
    }

    // Si l'appel demande l'interface modal ou browser
    if ($decoded->payload->target == "modal" || $decoded->payload->target == "browser") {

        // Récupérer l'API key et l'URL
        $apiKey = $decoded->api_key ?? $decoded->payload->api_key ?? '';
        $meetingUrl = $decoded->payload->meeting_url ?? $decoded->meeting_url ?? '';

        $hasApiKey = !empty($apiKey);
        $hasMeetingUrl = !empty($meetingUrl);

        if (DEBUG_MODE) {
            writeLog("=== FFE Extension Interface Loading ===");
            writeLog("API Key found: " . ($hasApiKey ? 'YES - ' . substr($apiKey, 0, 10) . '...' : 'NO'));
            writeLog("Meeting URL found: " . ($hasMeetingUrl ? 'YES - ' . $meetingUrl : 'NO'));
            writeLog("=====================================");
        }
?>
        <!DOCTYPE html>
        <html lang="<?php echo $currentLanguage; ?>">

        <head>
            <meta charset="UTF-8">
            <title><?php echo t('title'); ?></title>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($decoded->payload->style_url ?? ''); ?>">
            <link rel="stylesheet" href="css/custom.css?version=<?php echo time(); ?>">
            <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
            <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.3.2/css/flag-icons.min.css" />
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
        </head>

        <body class="extension">
            <div class="ffe-container">
                <div style="display: flex; align-items: center; justify-content: center; padding: 10px 0; border-bottom: 2px solid #e0e0e0; margin-bottom: 20px;">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 15px;">
                        <img src="ffe-logo.jpg" height="60" alt="FFE">
                        <i class="fa-solid fa-arrow-left fa-xl"></i> <i class="fa-solid fa-arrow-right fa-xl" style="color: #0f2f66;"></i>
                        <img src="equipe.jpg" height="60" alt="Equipe">
                    </h3>
                </div>
                <!-- Sélecteur de langue avec drapeaux -->
                <div class="language-flags">
                    <button class="flag-btn <?php echo $currentLanguage === 'fr' ? 'active' : ''; ?>"
                        data-lang="fr"
                        title="Français">
                        <span class="fi fi-fr"></span>
                    </button>
                    <button class="flag-btn <?php echo $currentLanguage === 'en' ? 'active' : ''; ?>"
                        data-lang="en"
                        title="English">
                        <span class="fi fi-gb"></span>
                    </button>
                </div>
            </div>
            <div id="alertMessage" class="alert" style="display: none;"></div>

            <!-- Écran de chargement initial -->
            <div id="loadingStep" class="step-section active">
                <div class="text-center">
                    <i class="fa fa-spinner fa-spin fa-3x"></i>
                    <p><?php echo t('loading'); ?></p>
                </div>
            </div>

            <!-- Étape principale -->
            <div id="mainStep" class="step-section" style="display: none;">
                <!-- Barre de navigation pour basculer entre les vues -->
                <div class="btn-group mb-3" role="group">
                    <button type="button" class="btn btn-outline-primary" id="viewImportsBtn">
                        <i class="fa fa-list"></i> <?php echo t('nav_view_imports'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="viewUploadBtn">
                        <i class="fa fa-upload"></i> <?php echo t('nav_new_import'); ?>
                    </button>
                </div>

                <!-- Si pas d'imports -->
                <div id="noImportsSection" style="display: none;">
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> <?php echo t('no_imports'); ?>
                    </div>
                    <h4><?php echo t('import_file'); ?></h4>
                    <form id="uploadForm" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="xmlFile"><?php echo t('select_file'); ?></label>
                            <input type="file" id="xmlFile" name="xml_file" accept=".xml" class="form-control" required>
                            <small class="form-text text-muted"><?php echo t('select_file_help'); ?></small>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-upload"></i> <?php echo t('analyze_file'); ?>
                        </button>
                    </form>
                </div>

                <!-- Si imports existants -->
                <div id="hasImportsSection" style="display: none;">
                    <h4><?php echo t('imported_competitions'); ?></h4>
                    <div id="importedCompetitionsList"></div>

                    <div class="action-buttons" style="margin-top: 20px;">
                        <!--<button type="button" class="btn btn-success" id="exportSifBtn">
                                <i class="fa fa-download"></i> Exporter tout en SIF
                            </button>-->
                        <button type="button" class="btn btn-info" id="exportFFECompetBtn">
                            <i class="fa fa-download"></i> <?php echo t('btn_export_all'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary" id="refreshImportsBtn">
                            <i class="fa fa-sync"></i> <?php echo t('btn_refresh'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Étape de sélection des épreuves -->
            <div id="selectionStep" class="step-section">
                <h4><?php echo t('competition_data'); ?></h4>
                <div id="concoursInfo"></div>

                <div class="stats-grid" id="statsGrid"></div>

                <h5><?php echo t('select_competitions'); ?></h5>
                <div class="competition-list" id="competitionList"></div>

                <div class="form-group" style="margin-top: 15px;">
                    <label><strong><?php echo t('competition_level'); ?></strong></label>
                    <div class="btn-group btn-group-toggle" data-toggle="buttons" id="competitionLevelRadio">
                        <label class="btn btn-outline-primary">
                            <input type="radio" name="competition_level" value="K"> <?php echo t('level_club'); ?>
                        </label>
                        <label class="btn btn-outline-primary">
                            <input type="radio" name="competition_level" value="L"> <?php echo t('level_local'); ?>
                        </label>
                        <label class="btn btn-outline-primary">
                            <input type="radio" name="competition_level" value="R"> <?php echo t('level_regional'); ?>
                        </label>
                        <label class="btn btn-outline-primary">
                            <input type="radio" name="competition_level" value="N"> <?php echo t('level_national'); ?>
                        </label>
                        <label class="btn btn-outline-primary">
                            <input type="radio" name="competition_level" value="E"> <?php echo t('level_elite'); ?>
                        </label>
                        <label class="btn btn-outline-primary active">
                            <input type="radio" name="competition_level" value="I" checked> <?php echo t('level_international'); ?>
                        </label>
                    </div>
                    <small class="form-text text-muted"><?php echo t('level_help'); ?></small>
                </div>

                <div class="action-buttons" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" id="selectAllBtn">
                        <i class="fa fa-check-square"></i> <?php echo t('btn_select_all'); ?>
                    </button>
                    <button type="button" class="btn btn-secondary" id="deselectAllBtn">
                        <i class="fa fa-square"></i> <?php echo t('btn_deselect_all'); ?>
                    </button>
                    <button type="button" class="btn btn-primary" id="importBtn">
                        <i class="fa fa-cloud-upload"></i> <?php echo t('btn_import'); ?>
                    </button>
                    <button type="button" class="btn btn-secondary" id="backBtn">
                        <i class="fa fa-arrow-left"></i> <?php echo t('btn_back'); ?>
                    </button>
                </div>
            </div>

            <!-- Étape d'import en cours -->
            <div id="importStep" class="step-section">
                <h4><?php echo t('import_in_progress'); ?></h4>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div id="importStatus"></div>
            </div>

            <!-- Étape d'export des résultats -->
            <div id="exportStep" class="step-section">
                <h4><?php echo t('export_title'); ?></h4>
                <div id="exportCompetitionList"></div>
                <button type="button" class="btn btn-primary" id="newImportBtn">
                    <i class="fa fa-plus"></i> <?php echo t('nav_new_import'); ?>
                </button>
            </div>
            </div>

            <script>
                const translations = <?php echo json_encode($translations); ?>;

                function t(key, params = {}) {
                    let text = translations[key] || key;

                    // Remplacer les paramètres
                    for (let param in params) {
                        text = text.replace(new RegExp('{' + param + '}', 'g'), params[param]);
                    }

                    return text;
                }
                const apiKey = '<?php echo addslashes($apiKey); ?>';
                const meetingUrl = '<?php echo addslashes($meetingUrl); ?>';
                const debugMode = <?php echo DEBUG_MODE ? 'true' : 'false'; ?>;

                console.log('=== FFE Extension JavaScript Init ===');
                console.log('Debug Mode:', debugMode);
                console.log('API Key:', apiKey ? 'Present (' + apiKey.substring(0, 10) + '...)' : 'MISSING');
                console.log('Meeting URL:', meetingUrl || 'MISSING');
                console.log('=====================================');

                let parsedData = null;
                let selectedCompetitions = [];

                // Fonction utilitaire pour télécharger un fichier
                function downloadFile(filename, base64Content) {
                    const blob = new Blob([atob(base64Content)], {
                        type: 'text/plain'
                    });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                }

                // Fonction pour afficher les alertes
                function showAlert(type, message) {
                    const alertDiv = $('#alertMessage');
                    alertDiv.removeClass('alert-success alert-danger alert-warning alert-info');
                    alertDiv.addClass('alert alert-' + type);
                    alertDiv.html(message);
                    alertDiv.show();

                    setTimeout(function() {
                        alertDiv.fadeOut();
                    }, 5000);
                }

                // Fonction pour formater les dates
                function formatDate(dateStr) {
                    const parts = dateStr.split('-');
                    return parts[2] + '/' + parts[1] + '/' + parts[0];
                }

                // Test de connexion - définie globalement
                function testConnection(callback) {
                    console.log('Testing API connection...');

                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'test_connection',
                            api_key: apiKey,
                            meeting_url: meetingUrl
                        },
                        dataType: 'json',
                        success: function(response) {
                            console.log('Connection test response:', response);

                            if (response.success) {
                                console.log('Connection successful!');
                                callback(true);
                            } else {
                                console.error('Connection failed:', response.error);
                                showAlert('danger', t('error_connection', {
                                    error: response.error
                                }));
                                //showAlert('danger', 'Erreur de connexion: ' + response.error);
                                callback(false);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Connection test AJAX error:', error);
                            showAlert('danger', t('error_connection', {
                                error: error
                            }));
                            //showAlert('danger', 'Erreur de connexion: ' + error);
                            callback(false);
                        }
                    });
                }

                // Vérifier les imports existants - définie globalement

                function checkExistingImports() {
                    if (debugMode) {
                        console.log('=== Checking existing imports ===');
                        console.log('API Key:', apiKey ? 'Present' : 'Missing');
                        console.log('Meeting URL:', meetingUrl);
                    }

                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'check_imported',
                            api_key: apiKey,
                            meeting_url: meetingUrl
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (debugMode) {
                                console.log('Check imports response:', response);
                            }

                            $('#loadingStep').hide();
                            $('#mainStep').show();

                            // Vérifier si on a une erreur
                            if (response.success === false) {
                                console.error('Error checking imports:', response.error);
                                $('#hasImportsSection').hide();
                                $('#noImportsSection').show();

                                if (response.error && response.error !== 'HTTP Error: 404') {
                                    showAlert('warning', t('error_connection', {
                                        error: response.error
                                    }));
                                    //showAlert('warning', 'Erreur: ' + response.error);
                                }
                                return;
                            }

                            // Récupérer les compétitions
                            const competitions = response.competitions || [];

                            if (debugMode) {
                                console.log('Competitions found:', competitions.length);
                                if (competitions.length > 0) {
                                    console.log('First competition:', competitions[0]);
                                    console.log('Competition keys:', Object.keys(competitions[0]));
                                }
                            }

                            // Vérifier si on a des imports FFE
                            if (response.has_imports && competitions.length > 0) {
                                displayExistingImports(competitions);
                                $('#hasImportsSection').show();
                                $('#noImportsSection').hide();
                            } else {
                                $('#hasImportsSection').hide();
                                $('#noImportsSection').show();

                                if (debugMode) {
                                    console.log('No FFE imports found');
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error);

                            $('#loadingStep').hide();
                            $('#mainStep').show();
                            $('#noImportsSection').show();
                            $('#hasImportsSection').hide();

                            // Ne pas afficher d'erreur pour 404 (pas de compétitions)
                            if (xhr.status !== 0 && xhr.status !== 404) {
                                showAlert('warning', t('error_server'));
                                //showAlert('warning', 'Erreur de connexion au serveur');
                            }
                        }
                    });
                }

                // Afficher les imports existants
                function displayExistingImports(competitions) {
                    if (debugMode) {
                        console.log('Displaying', competitions.length, 'competitions');
                    }

                    if (!competitions || competitions.length === 0) {
                        $('#importedCompetitionsList').html(
                            '<div class="alert alert-warning">' +
                            '<i class="fa fa-exclamation-triangle"></i> ' +
                            t('error_comp_not_found') +
                            '</div>'
                        );
                        $('#exportSifBtn').prop('disabled', true);
                        $('#exportFFECompetBtn').prop('disabled', true);
                        return;
                    }

                    // Activer les boutons d'export
                    $('#exportSifBtn').prop('disabled', false);
                    $('#exportFFECompetBtn').prop('disabled', false);

                    let html = '<div class="mb-2">';
                    html += '<span class="badge badge-primary">' + competitions.length + ' ' + t('imported_comp_multi') + '</span>';
                    html += '</div>';

                    html += '<table class="table table-striped">';
                    html += '<thead><tr>';
                    html += '<th>' + t('col_event_num') + '</th>';
                    html += '<th>' + t('col_name') + ' </th>';
                    html += '<th>' + t('col_date') + '/th>';
                    html += '<th>' + t('col_ffe_num') + '</th>'; // Changé de "ID FFE" à "N° Concours FFE"
                    html += '<th>' + t('col_results') + '</th>';
                    html += '<th>' + t('col_exports') + '</th>';
                    html += '</tr></thead><tbody>';

                    competitions.forEach(function(comp) {
                        const hasResults = comp.has_results || false;

                        // Extraire le numéro du concours FFE depuis le foreign_id
                        // Format attendu: "202501009_2" ou juste "202501009"
                        let concoursFFE = '';
                        let numEpreuve = comp.clabb || comp.kq || '-';

                        if (comp.foreignid) {
                            const parts = comp.foreignid.split('_');
                            concoursFFE = parts[0] || comp.foreignid;

                            // Si on a un numéro d'épreuve dans le foreign_id, l'utiliser
                            if (parts[1]) {
                                numEpreuve = parts[1];
                            }
                        }

                        html += '<tr>';
                        html += '<td>' + numEpreuve + '</td>';
                        html += '<td><strong>' + (comp.klass || 'Sans nom') + '</strong></td>';
                        html += '<td>' + (comp.datum || '-') + '</td>';
                        html += '<td>';
                        if (concoursFFE) {
                            html += '<span class="badge badge-info" title="' + t('badge_title') + '">';
                            html += concoursFFE;
                            html += '</span>';
                        } else {
                            html += '<small class="text-muted">-</small>';
                        }
                        html += '</td>';

                        if (hasResults) {
                            html += '<td><span class="badge badge-success"><i class="fa fa-check"></i> ' + t('status_yes') + '</span></td>';
                            html += '<td>';
                            html += '<div class="btn-group btn-group-sm">';

                            // Bouton export SIF
                            /*html += '<button style="width:75;" class="btn btn-primary export-sif-btn" ';
                            html += 'title="Exporter au format SIF" ';
                            html += 'data-comp-id="' + comp.kq + '" ';
                            html += 'data-comp-name="' + encodeURIComponent(comp.klass || '') + '" ';
                            html += 'data-concours-ffe="' + concoursFFE + '">';
                            html += 'SIF';
                            html += '</button>';
                            */
                            // Bouton export FFECompet
                            html += '<button  style="width:75;" class="btn btn-info export-ffecompet-btn" ';
                            html += 'title="' + t('tooltip_export_ffe') + '" ';
                            html += 'data-comp-id="' + comp.kq + '" ';
                            html += 'data-comp-name="' + encodeURIComponent(comp.klass || '') + '" ';
                            html += 'data-concours-ffe="' + concoursFFE + '">';
                            html += '<i class="fa-solid fa-download me-2"></i>FFE';
                            html += '</button>';

                            html += '</div>';
                            html += '</td>';
                        } else {
                            html += '<td><span class="badge badge-warning"><i class="fa fa-clock"></i>' + t('badge_wait') + '</span></td>';
                            html += '<td>';
                            html += '<span class="text-muted" style="font-size: 0.85em;">';
                            html += '<i class="fa fa-info-circle"></i> ' + t('status_in_progress');
                            html += '</span>';
                            html += '</td>';
                        }

                        html += '</tr>';
                    });

                    html += '</tbody></table>';

                    // Ajouter une légende sous le tableau
                    html += '<div class="mt-2 text-muted" style="font-size: 0.9em;">';
                    html += '<i class="fa fa-info-circle"></i> ';
                    html += t('format_info');
                    html += '</div>';

                    $('#importedCompetitionsList').html(html);

                    // Attacher les événements pour les boutons SIF
                    $('.export-sif-btn').off('click').on('click', function() {
                        const compId = $(this).data('comp-id');
                        const compName = decodeURIComponent($(this).data('comp-name'));
                        const concoursFFE = $(this).data('concours-ffe');

                        if (debugMode) {
                            console.log('Export SIF for:', compName, '(ID:', compId, ', Concours FFE:', concoursFFE, ')');
                        }

                        const button = $(this);
                        const originalText = button.html();
                        button.prop('disabled', true);
                        button.html('<i class="fa fa-spinner fa-spin"></i>');

                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'export_sif',
                                competition_id: compId,
                                api_key: apiKey,
                                meeting_url: meetingUrl
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    downloadFile(response.filename, response.content);
                                    showAlert('success', 'Export SIF réussi: ' + response.filename);
                                } else {
                                    showAlert('danger', 'Erreur SIF: ' + response.error);
                                }
                            },
                            error: function(xhr, status, error) {
                                showAlert('danger', 'Erreur lors de l\'export SIF: ' + error);
                            },
                            complete: function() {
                                button.prop('disabled', false);
                                button.html(originalText);
                            }
                        });
                    });

                    // Attacher les événements pour les boutons FFECompet
                    $('.export-ffecompet-btn').off('click').on('click', function() {
                        const compId = $(this).data('comp-id');
                        const compName = decodeURIComponent($(this).data('comp-name'));
                        const concoursFFE = $(this).data('concours-ffe');

                        if (debugMode) {
                            console.log('Export FFECompet for:', compName, '(ID:', compId, ', Concours FFE:', concoursFFE, ')');
                        }

                        const button = $(this);
                        const originalText = button.html();
                        button.prop('disabled', true);
                        button.html('<i class="fa fa-spinner fa-spin"></i>');

                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'export_ffecompet',
                                competition_id: compId,
                                api_key: apiKey,
                                meeting_url: meetingUrl
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    downloadFile(response.filename, response.content);
                                    showAlert('success', t('success_export'), response.filename);
                                } else {
                                    showAlert('danger', t('error_ffecompet', {
                                        error: response.error
                                    }));
                                    //showAlert('danger', 'Erreur FFECompet: ' + response.error);
                                    if (debugMode && response.trace) {
                                        console.error('Stack trace:', response.trace);
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                //showAlert('danger', 'Erreur lors de l\'export FFECompet: ' + error);
                                showAlert('danger', t('error_connection', {
                                    error: error
                                }));
                                console.error('Export error details:', xhr.responseText);
                            },
                            complete: function() {
                                button.prop('disabled', false);
                                button.html(originalText);
                            }
                        });
                    });
                } // Fonction pour exporter les résultats d'une compétition
                function exportCompetitionResults(competitionId, competitionName, clabb, date, foreignId) {
                    //showAlert('info', 'Export des résultats en cours...');
                    showAlert('info', t('export_in_progress'));
                    // Extraire la discipline du foreign_id si possible (FFE_EPR_XX)
                    let discipline = '01'; // Par défaut saut d'obstacles
                    if (foreignId && foreignId.includes('_')) {
                        // Essayer de récupérer la discipline depuis les données parsées si disponibles
                        if (parsedData && parsedData.competitions) {
                            const comp = parsedData.competitions.find(c => c.foreign_id === foreignId);
                            if (comp && comp.discipline_ffe) {
                                discipline = comp.discipline_ffe;
                            }
                        }
                    }

                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'export_results',
                            competition_id: competitionId,
                            competition_name: competitionName,
                            competition_clabb: clabb,
                            competition_date: date,
                            discipline: discipline,
                            api_key: apiKey,
                            meeting_url: meetingUrl
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                downloadFile(response.filename, response.content);
                                showAlert('success', t('export_completed') + response.filename);
                            } else {
                                showAlert('danger', 'Erreur: ' + response.error);
                            }
                        },
                        error: function(xhr, status, error) {
                            showAlert('danger', t('error_in_export', {
                                error: error
                            }));
                            //showAlert('danger', 'Erreur lors de l\'export: ' + error);
                        }
                    });
                }

                // Afficher les données parsées
                function displayParsedData(data) {
                    // Info concours
                    let concoursHtml = '<div class="alert alert-info">';
                    concoursHtml += '<strong>' + data.concours.nom + '</strong><br>';
                    concoursHtml += t('col_ffe_num') + ': ' + data.concours.num_ffe + '<br>';
                    concoursHtml += t('from') + ' ' + formatDate(data.concours.date_debut) + ' ' + t('to') + ' ' + formatDate(data.concours.date_fin) + '<br>';
                    concoursHtml += t("organizer") + ': ' + data.concours.organisateur.nom;
                    concoursHtml += '</div>';
                    $('#concoursInfo').html(concoursHtml);

                    // Statistiques (inclure les officiels)
                    let statsHtml = '';
                    statsHtml += '<div class="stat-card"><div class="number">' + data.stats.competitions + '</div><div class="label">' + t('stats_competitions') + '</div></div>';
                    statsHtml += '<div class="stat-card"><div class="number">' + data.stats.people + '</div><div class="label">' + t('stats_riders') + '</div></div>';

                    // Ajouter une carte pour les officiels si présents
                    if (data.stats.officials && data.stats.officials > 0) {
                        statsHtml += '<div class="stat-card"><div class="number">' + data.stats.officials + '</div><div class="label">' + t('stats_officials') + '</div></div>';
                    }

                    statsHtml += '<div class="stat-card"><div class="number">' + data.stats.horses + '</div><div class="label">' + t('stats_horses') + '</div></div>';
                    statsHtml += '<div class="stat-card"><div class="number">' + data.stats.clubs + '</div><div class="label">' + t('stats_clubs') + '</div></div>';
                    statsHtml += '<div class="stat-card"><div class="number">' + data.stats.total_starts + '</div><div class="label">' + t('stats_entries') + '</div></div>';
                    $('#statsGrid').html(statsHtml);

                    // Liste des compétitions
                    let compHtml = '';
                    data.competitions.forEach(function(comp) {
                        compHtml += '<div class="competition-item">';
                        compHtml += '<input type="checkbox" class="comp-checkbox" value="' + comp.foreign_id + '" checked>';
                        compHtml += '<strong>' + comp.clabb + '</strong> - ' + comp.klass;
                        compHtml += ' <small>(' + comp.datum + ')</small>';
                        if (comp.team_class) {
                            compHtml += ' <span class="badge badge-info">Équipe</span>';
                        }
                        compHtml += '</div>';
                    });
                    $('#competitionList').html(compHtml);

                    // Afficher les officiels si présents (optionnel - pour debug)
                    if (data.officials && data.officials.length > 0 && debugMode) {
                        console.log('Officiels trouvés:');
                        data.officials.forEach(function(official) {
                            console.log('- ' + official.prenom + ' ' + official.nom + ' (' + official.nom_fonction + ')');
                        });
                    }
                }

                // Mettre à jour la barre de progression
                function updateProgress(percent, message) {
                    $('#progressFill').css('width', percent + '%');
                    $('#importStatus').html('<p>' + message + '</p>');
                }

                // Démarrer l'import
                function startImport() {
                    updateProgress(0, t('import_preparing'));

                    // Récupérer le niveau sélectionné
                    const selectedLevel = $('input[name="competition_level"]:checked').val() || 'I';

                    if (debugMode) {
                        console.log('Selected level to send:', selectedLevel);
                        console.log('Selected competitions:', selectedCompetitions);
                    }

                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'import_to_equipe',
                            api_key: apiKey,
                            meeting_url: meetingUrl,
                            selected_competitions: JSON.stringify(selectedCompetitions),
                            competition_level: selectedLevel
                        },
                        dataType: 'json',
                        xhrFields: {
                            withCredentials: true
                        },
                        success: function(response) {
                            if (response.success) {
                                updateProgress(100, t('import_success'));
                                setTimeout(function() {
                                    $('#importStep').hide();
                                    $('#exportStep').show();
                                    displayExportOptions();
                                }, 2000);
                            } else {
                                updateProgress(0, 'Erreur: ' + response.error);
                                showAlert('danger', t('error_in_import', {
                                    error: response.error
                                }));
                                console.error('Import error:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            updateProgress(0, t('error_connection_no_param'));
                            showAlert('danger', t('error_connection', {
                                error: error
                            }));
                            console.error('Import connection error:', xhr.responseText);
                        }
                    });
                }

                // Afficher les options d'export
                function displayExportOptions() {
                    let html = '<div class="alert alert-success">' + t('success_import_completed') + '</div>';
                    html += '<div class="action-buttons mt-3">';
                    html += '<button type="button" class="btn btn-primary" id="checkResultsBtn">';
                    html += '<i class="fa fa-sync"></i> ' + t('import_check');
                    html += '</button> ';
                    html += '<button type="button" class="btn btn-secondary" id="newImportFromExportBtn">';
                    html += '<i class="fa fa-plus"></i> ' + t('nav_new_import');
                    html += '</button>';
                    html += '</div>';

                    $('#exportCompetitionList').html(html);

                    // Bouton pour vérifier les imports
                    $('#checkResultsBtn').on('click', function() {
                        $('#exportStep').hide();
                        $('#loadingStep').show();
                        checkExistingImports();
                    });

                    // Bouton pour nouvel import
                    $('#newImportFromExportBtn').on('click', function() {
                        location.reload();
                    });
                }

                $(document).ready(function() {
                    // Configuration globale pour toutes les requêtes AJAX
                    $.ajaxSetup({
                        xhrFields: {
                            withCredentials: true
                        }
                    });

                    // Vérifier les imports existants au chargement
                    if (apiKey && meetingUrl) {
                        testConnection(function(success) {
                            if (success) {
                                checkExistingImports();
                            } else {
                                $('#loadingStep').hide();
                                $('#mainStep').show();
                                $('#noImportsSection').show();
                            }
                        });
                    } else {
                        console.error('Configuration manquante');
                        $('#loadingStep').hide();
                        $('#mainStep').show();
                        $('#noImportsSection').show();
                        showAlert('danger', t('error_missing_api'));
                    }

                    // Upload et parse du fichier XML
                    $('#uploadForm').on('submit', function(e) {
                        e.preventDefault();

                        const formData = new FormData(this);
                        formData.append('action', 'parse_ffe_xml');

                        showAlert('info', t('analyze_in_progress'));

                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            xhrFields: {
                                withCredentials: true
                            },
                            success: function(response) {
                                if (response.success) {
                                    parsedData = response;
                                    displayParsedData(response);
                                    $('#mainStep').hide();
                                    $('#selectionStep').show();
                                    showAlert('success', t('success_file_analyzed'));
                                } else {
                                    showAlert('danger', t('error', {
                                        error: response.error
                                    }));

                                }
                            },
                            error: function(xhr, status, error) {
                                showAlert('danger', t('error_in_analyze', {
                                    error: error
                                }));
                                console.error('Upload error:', xhr.responseText);
                            }
                        });
                    });

                    // Sélectionner/Désélectionner tout
                    $('#selectAllBtn').on('click', function() {
                        $('.comp-checkbox').prop('checked', true);
                    });

                    $('#deselectAllBtn').on('click', function() {
                        $('.comp-checkbox').prop('checked', false);
                    });

                    // Bouton retour
                    $('#backBtn').on('click', function() {
                        $('#selectionStep').hide();
                        $('#mainStep').show();
                    });

                    // Import vers Equipe
                    $('#importBtn').on('click', function() {
                        const button = $(this);

                        // Empêcher les doubles clics
                        if (button.prop('disabled')) {
                            return false;
                        }

                        button.prop('disabled', true);

                        selectedCompetitions = [];
                        $('.comp-checkbox:checked').each(function() {
                            selectedCompetitions.push($(this).val());
                        });

                        if (selectedCompetitions.length === 0) {
                            showAlert('warning', t('error_no_selection'));
                            button.prop('disabled', false); // Réactiver le bouton
                            return;
                        }

                        $('#selectionStep').hide();
                        $('#importStep').show();

                        // Démarrer l'import
                        startImport();
                    })

                    // Export SIF
                    $('#exportSifBtn').on('click', function() {
                        const button = $(this);
                        button.prop('disabled', true);
                        button.html('<i class="fa fa-spinner fa-spin"></i> ' + t('export_running'));

                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'export_sif',
                                api_key: apiKey,
                                meeting_url: meetingUrl
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    downloadFile(response.filename, response.content);
                                    showAlert('success', 'Export SIF global réussi');
                                } else {
                                    showAlert('danger', 'Erreur: ' + response.error);
                                }
                            },
                            error: function(xhr, status, error) {
                                showAlert('danger', 'Erreur lors de l\'export: ' + error);
                            },
                            complete: function() {
                                button.prop('disabled', false);
                                button.html('<i class="fa fa-download"></i> Exporter tout en SIF');
                            }
                        });
                    });

                    // Export FFECompet
                    $('#exportFFECompetBtn').on('click', function() {
                        const button = $(this);
                        button.prop('disabled', true);
                        button.html('<i class="fa fa-spinner fa-spin"></i>' + t('export_running'));

                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'export_ffecompet_global', // ← Changé ici
                                api_key: apiKey,
                                meeting_url: meetingUrl
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    downloadFile(response.filename, response.content);
                                    showAlert('success', t('success_global_export'));
                                } else {
                                    showAlert('danger', t('error', {
                                        error: response.error
                                    }));
                                }
                            },
                            error: function(xhr, status, error) {
                                showAlert('danger', t('error_in_export', {
                                    error: error
                                }));
                            },
                            complete: function() {
                                button.prop('disabled', false);
                                button.html('<i class="fa fa-download"></i>' + t('tooltip_global_export_ffe'));
                            }
                        });
                    });

                    // Bascule entre les vues
                    $('#viewImportsBtn').on('click', function() {
                        $('#noImportsSection').hide();
                        $('#hasImportsSection').show();
                        $(this).addClass('active');
                        $('#viewUploadBtn').removeClass('active');

                        // Rafraîchir la liste des imports
                        checkExistingImports();
                    });

                    $('#viewUploadBtn').on('click', function() {
                        $('#hasImportsSection').hide();
                        $('#noImportsSection').show();
                        $(this).addClass('active');
                        $('#viewImportsBtn').removeClass('active');
                    });

                    // Bouton rafraîchir
                    $('#refreshImportsBtn').on('click', function() {
                        const button = $(this);
                        button.html('<i class="fa fa-spinner fa-spin"></i> ' + t('refresh_in_progress'));
                        button.prop('disabled', true);

                        setTimeout(function() {
                            checkExistingImports();
                            button.html('<i class="fa fa-sync"></i> ' + t('btn_refresh'));
                            button.prop('disabled', false);
                        }, 500);
                    });

                    // Export des résultats
                    $(document).on('click', '.export-btn', function() {
                        const compId = $(this).data('comp-id');
                        const compName = $(this).data('comp-name');
                        const discipline = $(this).data('discipline');

                        showAlert('info', t('export_running'));

                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'export_results',
                                competition_id: compId,
                                competition_name: compName,
                                discipline: discipline,
                                api_key: apiKey,
                                meeting_url: meetingUrl
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    downloadFile(response.filename, response.content);
                                    showAlert('success', t('success_completed') + response.filename);
                                } else {
                                    showAlert('danger', t('error', {
                                        error: response.error
                                    }));
                                }
                            },
                            error: function(xhr, status, error) {
                                showAlert('danger', t('error_in_export', {
                                    error: error
                                }));
                            }
                        });
                    });

                    // Nouvel import
                    $('#newImportBtn').on('click', function() {
                        location.reload();
                    });

                    if (debugMode) {
                        console.log('FFE Extension loaded in debug mode');
                    }
                });
                $('.flag-btn').on('click', function() {
                    const $btn = $(this);
                    const newLang = $btn.data('lang');
                    const currentLang = '<?php echo $currentLanguage; ?>';

                    // Si c'est déjà la langue active, ne rien faire
                    if (newLang === currentLang) {
                        return;
                    }

                    // Désactiver temporairement tous les boutons
                    $('.flag-btn').prop('disabled', true);

                    // Animation visuelle
                    $btn.addClass('switching');

                    console.log('Changing language to:', newLang);

                    // Sauvegarder en session via AJAX
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'change_language',
                            lang: newLang
                        },
                        dataType: 'json',
                        success: function(response) {
                            console.log('Language change response:', response);

                            if (response.success) {
                                // Recharger la page avec la nouvelle langue
                                window.location.reload();
                            } else {
                                // Afficher l'erreur
                                console.error('Language change failed:', response.error);
                                showAlert('danger', response.error || 'Error changing language');

                                // Réactiver les boutons
                                $('.flag-btn').prop('disabled', false);
                                $btn.removeClass('switching');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error:', status, error);
                            console.error('Response:', xhr.responseText);

                            // Réactiver les boutons
                            $('.flag-btn').prop('disabled', false);
                            $btn.removeClass('switching');

                            showAlert('danger', 'Error changing language: ' + error);
                        }
                    });
                });
            </script>
        </body>

        </html>
<?php

    }
}
?>