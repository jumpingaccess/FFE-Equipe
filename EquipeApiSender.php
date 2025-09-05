<?php

namespace FFE\Extension;

/**
 * Classe pour gérer la communication avec l'API Equipe via l'endpoint batch
 */
class EquipeApiSender
{
    private $apiKey;
    private $meetingUrl;
    private $debugMode;
    private $logFile;

    /**
     * Constructeur
     */
    public function __construct($apiKey, $meetingUrl, $debugMode = null)
    {
        $this->apiKey = $apiKey;
        $this->meetingUrl = rtrim(trim($meetingUrl), '/');

        if ($debugMode === null) {
            $this->debugMode = isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == '1';
        } else {
            $this->debugMode = $debugMode;
        }

        $logDir = __DIR__ . '/logs';
        if ($this->debugMode && !is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/ffe_extension_' . date('Y-m-d') . '.log';

        if ($this->debugMode) {
            $this->writeLog("EquipeApiSender initialized - Debug mode: ON");
            $this->writeLog("Meeting URL: " . $this->meetingUrl);
            $this->writeLog("API Key: " . substr($this->apiKey, 0, 10) . '...');
        }
    }

    /**
     * Écrire dans le fichier de log
     */
    private function writeLog($message)
    {
        if ($this->debugMode) {
            $timestamp = date('[Y-m-d H:i:s]');
            file_put_contents($this->logFile, $timestamp . ' [EquipeApiSender] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Génère un UUID v4
     */
    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
    private function ucFirstOnly($str)
    {
        $str = preg_replace('/\s+/u', ' ', trim((string) $str));
        if ($str === '') return $str;

        $lower = mb_strtolower($str, 'UTF-8');
        $first = mb_substr($lower, 0, 1, 'UTF-8');
        $rest  = mb_substr($lower, 1, null, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8') . $rest; // ex: "jean-luc" → "Jean-luc"
    }
    /**
     * Envoie les données dans l'ordre correct vers Equipe via l'API Batch
     */
    /**
     * Vérifie que les custom fields ont été correctement configurés
     */
    public function verifyCustomFields()
    {
        $settingsUrl = rtrim($this->meetingUrl, '/') . '/settings.json';

        $ch = curl_init($settingsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $settings = json_decode($response, true);

            if (isset($settings['custom_field_names']['start']['engagement_terrain'])) {
                if ($this->debugMode) {
                    $this->writeLog("Custom fields verification: SUCCESS");
                }
                return true;
            }
        }

        if ($this->debugMode) {
            $this->writeLog("Custom fields verification: FAILED");
        }
        return false;
    }
    /*
    public function sendDataInOrder($parsedData, $selectedCompetitions = [])
    {
        $results = [
            'clubs' => [],
            'people' => [],
            'horses' => [],
            'competitions' => [],
            'starts' => []
        ];
        $success = true;
        $error = null;

        try {
            // ÉTAPE 0: Configurer les custom fields avec PATCH (séparé du batch)
            if ($this->debugMode) {
                $this->writeLog("=== STEP 0: Configuring Custom Fields (PATCH) ===");
            }

            $customFieldResult = $this->configureCustomFields();
            if (!$customFieldResult['success']) {
                if ($this->debugMode) {
                    $this->writeLog("Custom fields configuration failed: " . $customFieldResult['error']);
                }
                // Continuer quand même l'import
            } else {
                $results['custom_fields'] = $customFieldResult;
                if ($this->debugMode) {
                    $this->writeLog("Custom fields configured successfully");
                }
            }

            // ÉTAPE 1-5: Import en BATCH (comme avant)
            if ($this->debugMode) {
                $this->writeLog("=== Starting BATCH Import ===");
            }

            // Filtrer les données selon les compétitions sélectionnées
            //$filteredData = $this->filterDataByCompetitions($parsedData, $selectedCompetitions);

            // ÉTAPE 1: Envoyer les clubs
            if ($this->debugMode) {
                $this->writeLog("=== STEP 1: Sending Clubs (BATCH) ===");
            }
            // Préparer les données au format batch
            $batchData = [];

            // 1. Clubs
            if (!empty($parsedData['clubs'])) {
                $clubRecords = [];
                foreach ($parsedData['clubs'] as $club) {
                    $clubRecords[] = [
                        'foreign_id' => 'FFE_CLUB_' . $club['num'],
                        'name' => $club['nom'],
                        'region' => $club['region'] ?? '',
                        'department' => $club['departement'] ?? ''
                    ];
                }
                $batchData['clubs'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $clubRecords
                ];
            }

            // 2. People (cavaliers + officiels)
            $peopleRecords = [];

            // Ajouter les cavaliers
            foreach ($parsedData['people'] as $person) {
                $peopleRecords[] = [
                    'foreign_id' => 'FFE_' . $person['lic'],
                    'first_name' => $this->ucFirstOnly($person['prenom']),
                    'last_name'  => $this->ucFirstOnly($person['nom']),
                    'country' => 'FRA',
                    'licence' => $person['lic'],
                    'birthdate' => $person['dnaiss'] ?? null,
                    'club' => !empty($person['club']) ? ['foreign_id' => 'FFE_CLUB_' . $person['club']] : null,
                    'official' => false
                ];
            }

            // Ajouter les officiels
            foreach ($parsedData['officials'] ?? [] as $official) {
                $peopleRecords[] = [
                    'foreign_id' => 'FFE_' . $official['licence'],
                    'first_name' => $this->ucFirstOnly($official['prenom']),
                    'last_name' => $this->ucFirstOnly($official['nom']),
                    'country' => 'FRA',
                    'licence' => $official['licence'],
                    'official' => true
                ];
            }

            if (!empty($peopleRecords)) {
                $batchData['people'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $peopleRecords
                ];
            }

            // 3. Horses
            if (!empty($parsedData['horses'])) {
                $horseRecords = [];
                $horseNum = 1;
                foreach ($parsedData['horses'] as $horse) {
                    $horseRecords[] = [
                        'foreign_id' => 'FFE_' . $horse['sire'],
                        'num' => (string)$horseNum++,
                        'name' => $this->ucFirstOnly($horse['nom']),
                        'breed' => $horse['race'] ?? '',
                        'sex' => $this->mapHorseGender($horse['sexe'] ?? ''),
                        'born_year' => substr($horse['dnaiss'] ?? '', 0, 4),
                        'sire' => $horse['pere']['nom'] ?? null,
                        'dam_sire' => $horse['mere']['pere']['nom'] ?? null,
                        'owner' => $horse['proprietaire'] ?? null,
                        'category' => 'H',
                        'fei_id' => $horse['equide_fei'] ?? null,
                        'transponder' => $horse['transpondeur'] ?? null
                    ];
                }
                $batchData['horses'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $horseRecords
                ];
            }

            // 4. Competitions
            $classcounter = 0;
            if (!empty($competitionsToSend)) {
                $competitionRecords = [];
                foreach ($competitionsToSend as $comp) {
                    $competitionRecord = [
                        'foreign_id' => $comp['foreign_id'],
                        'ord' => $classcounter++,
                        'clabb' => $comp['clabb'],
                        'name' => $comp['klass'],
                        'starts_on' => $comp['datum'],
                        'start_time' => $comp['heure_debut'] ?? '',
                        'x' => $comp['x'] ?? 'I',
                        'z' => $comp['z'] ?? 'H',
                        'entry_fee' => $comp['montant_eng'] ?? 0,
                        'prize_money' => $comp['dotation_epreuve'] ?? 0
                    ];

                    $competitionRecords[] = $competitionRecord;
                }

                $batchData['competitions'] = [
                    'unique_by' => 'foreign_id',
                    'skip_user_changed' => true,
                    'records' => $competitionRecords
                ];
            }

            // 5. Starts (engagements) - pour chaque compétition
            foreach ($selectedCompetitions as $compForeignId) {
                if (isset($parsedData['starts'][$compForeignId])) {
                    $startRecords = [];
                    foreach ($parsedData['starts'][$compForeignId] as $start) {
                        $startRecords[] = [
                            'foreign_id' => 'START_' . $start['cavalier_lic'] . '_' . $start['equide_sire'] . '_' . $compForeignId,
                            'st' => (string)$start['dossard'],
                            'ord' => $start['dossard'],
                            'rider' => ['foreign_id' => 'FFE_' . $start['cavalier_lic']],
                            'horse' => ['foreign_id' => 'FFE_' . $start['equide_sire']],
                            // AJOUTER LES CUSTOM FIELDS
                            'start_custom_fields' => $start['start_custom_fields'] ?? [],
                            'rider_custom_fields' => $start['rider_custom_fields'] ?? [],
                            'horse_custom_fields' => $start['horse_custom_fields'] ?? []
                        ];
                    }

                    if (!empty($startRecords)) {
                        $batchData['starts'] = [
                            'unique_by' => 'foreign_id',
                            'where' => [
                                'competition' => ['foreign_id' => $compForeignId]
                            ],
                            'replace' => true,
                            'records' => $startRecords
                        ];

                        // Envoyer ce batch pour cette compétition
                        $result = $this->sendBatch($batchData);
                        $results[] = $result;

                        // Retirer les starts pour le prochain batch
                        unset($batchData['starts']);
                    }
                }
            }

            // Si pas de starts mais d'autres données, envoyer quand même
            if (empty($results) && !empty($batchData)) {
                $result = $this->sendBatch($batchData);
                $results[] = $result;
            }
        } catch (\Exception $e) {
            $success = false;
            $error = $e->getMessage();
            $this->writeLog("Error during import: " . $error);
        }

        return [
            'success' => $success,
            'error' => $error,
            'results' => $results
        ];
    }
    */
    public function sendDataInOrder($parsedData, $selectedCompetitions = [])
    {
        $results = [
            'clubs' => [],
            'people' => [],
            'horses' => [],
            'competitions' => [],
            'starts' => []
        ];
        $success = true;
        $error = null;

        try {
            // ÉTAPE 0: Configurer les custom fields avec PATCH
            if ($this->debugMode) {
                $this->writeLog("=== STEP 0: Configuring Custom Fields (PATCH) ===");
            }
            /*
            $customFieldResult = $this->configureCustomFields();
            if (!$customFieldResult['success']) {
                if ($this->debugMode) {
                    $this->writeLog("Custom fields configuration failed: " . $customFieldResult['error']);
                }
            } else {
                $results['custom_fields'] = $customFieldResult;
                if ($this->debugMode) {
                    $this->writeLog("Custom fields configured successfully");
                }
            }

            // Attendre que la config soit propagée
            if ($customFieldResult['success']) {
                if ($this->debugMode) {
                    $this->writeLog("Waiting for custom fields propagation...");
                }
                sleep(2);
            }
*/
            // ÉTAPE 1: Préparer TOUTES les données dans UN SEUL BATCH
            if ($this->debugMode) {
                $this->writeLog("=== Preparing SINGLE BATCH with all data ===");
            }

            $batchData = [];

            // 1. Clubs
            if (!empty($parsedData['clubs'])) {
                $clubRecords = [];
                foreach ($parsedData['clubs'] as $club) {
                    $clubRecords[] = [
                        'foreign_id' => 'FFE_CLUB_' . $club['num'],
                        'name' => $club['nom'],
                        'region' => $club['region'] ?? '',
                        'department' => $club['departement'] ?? ''
                    ];
                }
                $batchData['clubs'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $clubRecords
                ];
            }

            // 2. People (cavaliers + officiels)
            $peopleRecords = [];

            foreach ($parsedData['people'] as $person) {
                $peopleRecords[] = [
                    'foreign_id' => 'FFE_' . $person['lic'],
                    'first_name' => $this->ucFirstOnly($person['prenom']),
                    'last_name'  => $this->ucFirstOnly($person['nom']),
                    'country' => 'FRA',
                    'licence' => $person['lic'],
                    'birthdate' => $person['dnaiss'] ?? null,
                    'club' => !empty($person['club']) ? ['foreign_id' => 'FFE_CLUB_' . $person['club']] : null,
                    'official' => false
                ];
            }

            foreach ($parsedData['officials'] ?? [] as $official) {
                $peopleRecords[] = [
                    'foreign_id' => 'FFE_' . $official['licence'],
                    'first_name' => $this->ucFirstOnly($official['prenom']),
                    'last_name' => $this->ucFirstOnly($official['nom']),
                    'country' => 'FRA',
                    'licence' => $official['licence'],
                    'official' => true
                ];
            }

            if (!empty($peopleRecords)) {
                $batchData['people'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $peopleRecords
                ];
            }

            // 3. Horses
            if (!empty($parsedData['horses'])) {
                $horseRecords = [];
                $horseNum = 1;
                foreach ($parsedData['horses'] as $horse) {
                    $horseRecords[] = [
                        'foreign_id' => 'FFE_' . $horse['sire'],
                        'num' => (string)$horseNum++,
                        'name' => $this->ucFirstOnly($horse['nom']),
                        'breed' => $horse['race'] ?? '',
                        'sex' => $this->mapHorseGender($horse['sexe'] ?? ''),
                        'born_year' => substr($horse['dnaiss'] ?? '', 0, 4),
                        'sire' => $horse['pere']['nom'] ?? null,
                        'dam_sire' => $horse['mere']['pere']['nom'] ?? null,
                        'owner' => $horse['proprietaire'] ?? null,
                        'category' => 'H',
                        'fei_id' => $horse['equide_fei'] ?? null,
                        'transponder' => $horse['transpondeur'] ?? null
                    ];
                }
                $batchData['horses'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $horseRecords
                ];
            }

            // 4. Competitions - FILTRER par les sélectionnées
            $competitionRecords = [];
            $classcounter = 0;

            foreach ($parsedData['competitions'] as $comp) {
                if (in_array($comp['foreign_id'], $selectedCompetitions)) {
                    $competitionRecords[] = [
                        'foreign_id' => $comp['foreign_id'],
                        'ord' => $comp['clabb'],
                        'clabb' => $comp['clabb'],
                        'name' => $comp['klass'],
                        'starts_on' => $comp['datum'],
                        'start_time' => $comp['heure_debut'] ?? '',
                        'x' => $comp['x'] ?? 'I',
                        'z' => $comp['z'] ?? 'H',
                        'alias' => true,
                        'anm' => $comp['montant_eng'] ?? 0,
                        'prsum1' => $comp['dotation_epreuve'] ?? 0,
                        'premietxt1' => $comp['dotation_epreuve'] ?? 0,
                        'judgement_id' => $comp['judgement_id'] ?? ''

                    ];
                }
            }

            if (!empty($competitionRecords)) {
                $batchData['competitions'] = [
                    'unique_by' => 'foreign_id',
                    'skip_user_changed' => true,
                    'records' => $competitionRecords
                ];
            }

            // 5. Starts - TOUS les starts des compétitions sélectionnées
            $allStartRecords = [];

            foreach ($selectedCompetitions as $compForeignId) {
                if (isset($parsedData['starts'][$compForeignId])) {
                    foreach ($parsedData['starts'][$compForeignId] as $start) {
                        $startRecord = [
                            'foreign_id' => 'START_' . $start['cavalier_lic'] . '_' . $start['equide_sire'] . '_' . $compForeignId,
                            'st' => (string)$start['dossard'],
                            'ord' => $start['dossard'],
                            'rider' => ['foreign_id' => 'FFE_' . $start['cavalier_lic']],
                            'horse' => ['foreign_id' => 'FFE_' . $start['equide_sire']],
                            'competition' => ['foreign_id' => $compForeignId],

                            // CUSTOM FIELDS
                            'start_custom_fields' => $start['start_custom_fields'] ?? [
                                'engagement_terrain' => false,
                                'invitation_organisateur' => false
                            ],
                            'replace' => true,
                            'rider_custom_fields' => $start['rider_custom_fields'] ?? [],
                            'horse_custom_fields' => $start['horse_custom_fields'] ?? []
                        ];

                        $allStartRecords[] = $startRecord;
                    }
                }
            }

            if (!empty($allStartRecords)) {
                $batchData['starts'] = [
                    'unique_by' => 'foreign_id',
                    'records' => $allStartRecords
                ];
            }

            // ENVOI DU BATCH UNIQUE avec tout
            if (!empty($batchData)) {
                if ($this->debugMode) {
                    $this->writeLog("Sending SINGLE batch with:");
                    foreach ($batchData as $key => $value) {
                        $this->writeLog("  - " . $key . ": " . count($value['records'] ?? []) . " records");
                    }
                }

                $result = $this->sendBatch($batchData);
                $results = $result;
            }
        } catch (\Exception $e) {
            $success = false;
            $error = $e->getMessage();
            $this->writeLog("Error during import: " . $error);
        }

        return [
            'success' => $success,
            'error' => $error,
            'results' => $results
        ];
    }
    /**
     * Configure les custom field names dans les settings du meeting
     */
    public function configureCustomFields()
    {
        $settingsUrl = rtrim($this->meetingUrl, '/') . '/settings.json';

        $customFieldConfig = [
            "custom_field_names" => [
                "start" => [
                    "engagement_terrain" => [
                        "name" => "Engagement Terrain",
                        "type" => "bool",
                        "align" => "center",
                        "publish" => false
                    ],
                    "invitation_organisateur" => [
                        "name" => "Invitation Organisateur",
                        "type" => "bool",
                        "align" => "center",
                        "publish" => false
                    ]
                ],
                "person" => [
                    "compte_engageur" => [
                        "name" => "Compte Engageur",
                        "type" => "string",
                        "align" => "center",
                        "publish" => false
                    ],
                    "licence_engageur" => [
                        "name" => "Licence Engageur",
                        "type" => "string",
                        "align" => "center",
                        "publish" => false
                    ]
                ]
            ]
        ];

        if ($this->debugMode) {
            $this->writeLog("=== Configuring Custom Fields ===");
            $this->writeLog("URL: " . $settingsUrl);
            $this->writeLog("Config: " . json_encode($customFieldConfig, JSON_PRETTY_PRINT));
        }

        $ch = curl_init($settingsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($customFieldConfig));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            if ($this->debugMode) {
                $this->writeLog("CURL Error: " . $error);
            }
            return [
                'success' => false,
                'error' => 'Erreur CURL lors de la configuration des custom fields: ' . $error
            ];
        }

        if ($httpCode !== 200) {
            if ($this->debugMode) {
                $this->writeLog("HTTP Error " . $httpCode . ": " . $response);
            }
            return [
                'success' => false,
                'error' => 'Erreur HTTP ' . $httpCode . ' lors de la configuration des custom fields'
            ];
        }

        if ($this->debugMode) {
            $this->writeLog("Custom fields configured successfully");
            $this->writeLog("Response: " . substr($response, 0, 200));
        }

        return [
            'success' => true,
            'message' => 'Custom fields configurés avec succès'
        ];
    }
    /**
     * Envoie un batch à l'API Equipe
     */
    private function sendBatch($batchData)
    {
        $transactionUuid = $this->generateUuid();
        $url = $this->meetingUrl . '/batch';

        if ($this->debugMode) {
            $this->writeLog("=== SENDING BATCH ===");
            $this->writeLog("URL: " . $url);
            $this->writeLog("Transaction UUID: " . $transactionUuid);
            $this->writeLog("Batch data structure:");
            foreach ($batchData as $key => $value) {
                $this->writeLog("  - " . $key . ": " . count($value['records'] ?? []) . " records");
            }
            $this->writeLog("Full batch data: " . json_encode($batchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($batchData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Api-Key: " . $this->apiKey,
            "X-Transaction-Uuid: " . $transactionUuid,
            "Accept: application/json",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->debugMode) {
            $this->writeLog("HTTP Response Code: " . $httpCode);
            $this->writeLog("Response: " . substr($response, 0, 1000));
        }

        if ($error) {
            throw new \Exception("Erreur CURL: " . $error);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'transaction_uuid' => $transactionUuid,
                'http_code' => $httpCode,
                'response' => json_decode($response, true)
            ];
        } else {
            $errorResponse = json_decode($response, true);
            throw new \Exception("Erreur API (HTTP $httpCode): " . ($errorResponse['message'] ?? $response));
        }
    }

    /**
     * Map le genre du cheval vers le format Equipe
     */
    private function mapHorseGender($sexe)
    {
        $sexe = strtolower($sexe);
        if (strpos($sexe, 'jument') !== false) {
            return 'sto'; // Mare
        } elseif (strpos($sexe, 'hongre') !== false) {
            return 'val'; // Gelding
        } elseif (strpos($sexe, 'etalon') !== false || strpos($sexe, 'entier') !== false) {
            return 'hin'; // Stallion
        }
        return 'val'; // Par défaut
    }

    /**
     * Récupère les compétitions avec leurs résultats
     */
    public function getCompetitionsWithResults()
    {
        try {
            $url = $this->meetingUrl . '/competitions.json';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $this->apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $competitions = json_decode($response, true);

                // Filtrer les compétitions FFE et vérifier les résultats
                $ffeCompetitions = [];
                foreach ($competitions as $comp) {
                    if (isset($comp['foreign_id']) && strpos($comp['foreign_id'], 'FFE_') === 0) {
                        // Vérifier s'il y a des résultats pour cette compétition
                        $comp['has_results'] = $this->checkCompetitionHasResults($comp);
                        $comp['classid'] = $comp['id'] ?? $comp['classid'] ?? null;
                        $comp['klass'] = $comp['name'] ?? $comp['klass'] ?? 'Épreuve';
                        $comp['clabb'] = $comp['clabb'] ?? '';
                        $comp['datum'] = $comp['starts_on'] ?? $comp['datum'] ?? '';
                        $ffeCompetitions[] = $comp;
                    }
                }

                if ($this->debugMode) {
                    $this->writeLog("Found " . count($ffeCompetitions) . " FFE competitions");
                }

                return $ffeCompetitions;
            }

            return [];
        } catch (\Exception $e) {
            $this->writeLog("Error getting competitions: " . $e->getMessage());
            return [];
        }
    }
    /**
     * Vérifie si une compétition a des résultats
     */
    private function checkCompetitionHasResults($competition)
    {
        try {
            // Utiliser l'ID de la compétition pour vérifier les résultats
            $compId = $competition['id'] ?? $competition['classid'] ?? null;
            if (!$compId) {
                return false;
            }

            $url = $this->meetingUrl . '/competitions/' . $compId . '/results.json';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $this->apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $results = json_decode($response, true);
                // Vérifier s'il y a au moins un résultat valide
                if (is_array($results) && count($results) > 0) {
                    foreach ($results as $result) {
                        // Un résultat avec re (rank) < 999 est considéré comme valide
                        if (isset($result['re']) && $result['re'] > 0 && $result['re'] < 999) {
                            return true;
                        }
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * Alias pour getCompetitionsWithResults
     */
    public function getImportedFFECompetitions()
    {
        return $this->getCompetitionsWithResults();
    }

    /**
     * Récupère toutes les données pour l'export
     */
    public function getAllDataForExport()
    {
        $data = [
            'people' => [],
            'horses' => [],
            'starts' => [],
            'clubs' => []
        ];

        try {
            // Récupérer les personnes
            $url = $this->meetingUrl . '/people.json';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $this->apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $data['people'] = json_decode($response, true);
            }
            curl_close($ch);

            // Récupérer les chevaux
            $url = $this->meetingUrl . '/horses.json';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $this->apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $data['horses'] = json_decode($response, true);
            }
            curl_close($ch);

            // Récupérer les clubs
            $url = $this->meetingUrl . '/clubs.json';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $this->apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $data['clubs'] = json_decode($response, true);
            }
            curl_close($ch);

            // Pour les starts, il faut les récupérer par compétition
            $competitions = $this->getCompetitionsWithResults();
            foreach ($competitions as $comp) {
                if (isset($comp['id'])) {
                    $url = $this->meetingUrl . '/competitions/' . $comp['id'] . '/starts.json';
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "X-Api-Key: " . $this->apiKey,
                        "Accept: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    $response = curl_exec($ch);
                    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                        $starts = json_decode($response, true);
                        $data['starts'][$comp['foreign_id']] = $starts;
                    }
                    curl_close($ch);
                }
            }
        } catch (\Exception $e) {
            $this->writeLog("Error getting export data: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * Récupère les résultats d'une compétition
     */
    public function getCompetitionResults($competitionId)
    {
        try {
            $url = $this->meetingUrl . '/competitions/' . $competitionId . '/results.json';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: " . $this->apiKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return json_decode($response, true);
            }

            return [];
        } catch (\Exception $e) {
            $this->writeLog("Error getting competition results: " . $e->getMessage());
            return [];
        }
    }
}
