<?php

/**
 * FFEDataHelper.php
 * Classe utilitaire pour le mapping et nettoyage des données FFE
 */

class FFEDataHelper
{
    /**
     * Recherche d'une personne par ID
     */
    public static function findPersonById($people, $id)
    {
        if (!$id || !is_array($people)) return null;

        foreach ($people as $person) {
            if (isset($person['id']) && $person['id'] == $id) {
                return $person;
            }
            if (isset($person['rnr']) && $person['rnr'] == $id) {
                return $person;
            }
        }
        return null;
    }

    /**
     * Recherche d'un cheval par ID
     */
    public static function findHorseById($horses, $id)
    {
        if (!$id || !is_array($horses)) return null;

        foreach ($horses as $horse) {
            if (isset($horse['id']) && $horse['id'] == $id) {
                return $horse;
            }
            if (isset($horse['hnr']) && $horse['hnr'] == $id) {
                return $horse;
            }
        }
        return null;
    }

    /**
     * Nettoyage du texte pour XML
     */
    public static function cleanXMLText($text)
    {
        if (empty($text)) return '';

        $text = trim($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $text;
    }

    /**
     * Formatage de la licence FFE
     */
    public static function cleanFFELicense($license)
    {
        if (empty($license)) return '0000000A';

        // Format FFE : 7 chiffres + 1 lettre
        $license = preg_replace('/[^0-9A-Z]/i', '', strtoupper($license));

        if (strlen($license) < 8) {
            $license = str_pad($license, 8, '0', STR_PAD_LEFT);
            if (!preg_match('/[A-Z]$/', $license)) {
                $license .= 'A';
            }
        }

        return $license;
    }

    /**
     * Formatage du SIRE FFE
     */
    public static function cleanFFESire($sire)
    {
        if (empty($sire)) return '50000000A';

        // Format FFE SIRE : 8 chiffres + 1 lettre
        $sire = preg_replace('/[^0-9A-Z]/i', '', strtoupper($sire));

        if (strlen($sire) < 9) {
            $sire = str_pad($sire, 8, '0', STR_PAD_LEFT);
            if (!preg_match('/[A-Z]$/', $sire)) {
                $sire .= 'A';
            }
        }

        return $sire;
    }

    /**
     * Formatage des dates XML
     */
    public static function formatXMLDate($date)
    {
        if (empty($date)) return date('Y-m-d');

        // Convertir au format YYYY-MM-DD
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $date)) {
            return $date;
        }

        try {
            return date('Y-m-d', strtotime($date));
        } catch (Exception $e) {
            return date('Y-m-d');
        }
    }

    /**
     * Mapping des disciplines vers codes FFE (avec détection améliorée)
     */
    public static function mapDisciplineToFFECode($discipline)
    {
        $mapping = [
            'H' => '01', // Saut d'obstacles  
            'S' => '01', // Saut d'obstacles
            'D' => '03', // Dressage
            'F' => '04', // Concours Complet (CCE)
            'C' => '04', // Concours Complet
            'K' => '05', // Attelage
            'E' => '06', // Endurance
            'A' => '05', // Attelage
            'W' => '07', // Western
            'V' => '08'  // Voltige
        ];

        return $mapping[$discipline] ?? '01';
    }

    /**
     * Détection intelligente de la discipline depuis le nom de l'épreuve
     */
    public static function detectDisciplineFromName($competitionName)
    {
        $name = strtoupper($competitionName);

        // Dressage
        if (
            strpos($name, 'IMPOSE') !== false ||
            strpos($name, 'IMPOSÉ') !== false ||
            strpos($name, 'LIBRE') !== false ||
            strpos($name, 'DRESSAGE') !== false ||
            strpos($name, 'REPRISE') !== false
        ) {
            return 'D';
        }

        // Saut d'obstacles
        if (
            strpos($name, 'SAUT') !== false ||
            strpos($name, 'OBSTACLE') !== false ||
            strpos($name, 'CSO') !== false ||
            strpos($name, 'VITESSE') !== false ||
            strpos($name, 'PUISSANCE') !== false
        ) {
            return 'H';
        }

        // Concours Complet
        if (
            strpos($name, 'CCE') !== false ||
            strpos($name, 'COMPLET') !== false ||
            strpos($name, 'CROSS') !== false
        ) {
            return 'C';
        }

        // Hunter
        if (strpos($name, 'HUNTER') !== false) {
            return 'H';
        }

        // Par défaut, considérer comme saut d'obstacles
        return 'H';
    }

    /**
     * Mapping des disciplines vers noms
     */
    public static function mapDisciplineToName($discipline)
    {
        $mapping = [
            'H' => 'Saut d\'obstacles',
            'S' => 'Saut d\'obstacles',
            'D' => 'Dressage',
            'F' => 'Concours Complet',
            'C' => 'Concours Complet',
            'K' => 'Attelage',
            'E' => 'Endurance',
            'A' => 'Attelage',
            'W' => 'Western',
            'V' => 'Voltige'
        ];

        return $mapping[$discipline] ?? 'Saut d\'obstacles';
    }

    /**
     * Mapping des catégories vers codes FFE
     */
    public static function mapCategoryCode($category)
    {
        $mapping = [
            'K' => 'CLUB1',
            'L' => 'DEP1',
            'R' => 'REG1',
            'N' => 'NAT1',
            'E' => 'ELI1',
            'I' => 'INT1'
        ];

        return $mapping[$category] ?? 'CLUB1';
    }

    /**
     * Mapping des catégories vers noms
     */
    public static function mapCategoryName($category)
    {
        $mapping = [
            'K' => 'Club 1',
            'L' => 'Départemental 1',
            'R' => 'Régional 1',
            'N' => 'National 1',
            'E' => 'Elite 1',
            'I' => 'International 1'
        ];

        return $mapping[$category] ?? 'Club 1';
    }

    /**
     * Noms des départements français
     */
    public static function getDepartmentName($code)
    {
        $departments = [
            '01' => 'Ain',
            '02' => 'Aisne',
            '03' => 'Allier',
            '04' => 'Alpes-de-Haute-Provence',
            '05' => 'Hautes-Alpes',
            '06' => 'Alpes-Maritimes',
            '07' => 'Ardèche',
            '08' => 'Ardennes',
            '09' => 'Ariège',
            '10' => 'Aube',
            '11' => 'Aude',
            '12' => 'Aveyron',
            '13' => 'Bouches-du-Rhône',
            '14' => 'Calvados',
            '15' => 'Cantal',
            '16' => 'Charente',
            '17' => 'Charente-Maritime',
            '18' => 'Cher',
            '19' => 'Corrèze',
            '21' => 'Côte-d\'Or',
            '22' => 'Côtes-d\'Armor',
            '23' => 'Creuse',
            '24' => 'Dordogne',
            '25' => 'Doubs',
            '26' => 'Drôme',
            '27' => 'Eure',
            '28' => 'Eure-et-Loir',
            '29' => 'Finistère',
            '30' => 'Gard',
            '31' => 'Haute-Garonne',
            '32' => 'Gers',
            '33' => 'Gironde',
            '34' => 'Hérault',
            '35' => 'Ille-et-Vilaine',
            '36' => 'Indre',
            '37' => 'Indre-et-Loire',
            '38' => 'Isère',
            '39' => 'Jura',
            '40' => 'Landes',
            '41' => 'Loir-et-Cher',
            '42' => 'Loire',
            '43' => 'Haute-Loire',
            '44' => 'Loire-Atlantique',
            '45' => 'Loiret',
            '46' => 'Lot',
            '47' => 'Lot-et-Garonne',
            '48' => 'Lozère',
            '49' => 'Maine-et-Loire',
            '50' => 'Manche',
            '51' => 'Marne',
            '52' => 'Haute-Marne',
            '53' => 'Mayenne',
            '54' => 'Meurthe-et-Moselle',
            '55' => 'Meuse',
            '56' => 'Morbihan',
            '57' => 'Moselle',
            '58' => 'Nièvre',
            '59' => 'Nord',
            '60' => 'Oise',
            '61' => 'Orne',
            '62' => 'Pas-de-Calais',
            '63' => 'Puy-de-Dôme',
            '64' => 'Pyrénées-Atlantiques',
            '65' => 'Hautes-Pyrénées',
            '66' => 'Pyrénées-Orientales',
            '67' => 'Bas-Rhin',
            '68' => 'Haut-Rhin',
            '69' => 'Rhône',
            '70' => 'Haute-Saône',
            '71' => 'Saône-et-Loire',
            '72' => 'Sarthe',
            '73' => 'Savoie',
            '74' => 'Haute-Savoie',
            '75' => 'Paris',
            '76' => 'Seine-Maritime',
            '77' => 'Seine-et-Marne',
            '78' => 'Yvelines',
            '79' => 'Deux-Sèvres',
            '80' => 'Somme',
            '81' => 'Tarn',
            '82' => 'Tarn-et-Garonne',
            '83' => 'Var',
            '84' => 'Vaucluse',
            '85' => 'Vendée',
            '86' => 'Vienne',
            '87' => 'Haute-Vienne',
            '88' => 'Vosges',
            '89' => 'Yonne',
            '90' => 'Territoire de Belfort',
            '91' => 'Essonne',
            '92' => 'Hauts-de-Seine',
            '93' => 'Seine-Saint-Denis',
            '94' => 'Val-de-Marne',
            '95' => 'Val-d\'Oise'
        ];

        return $departments[$code] ?? 'Département';
    }

    /**
     * Détermination du titre (M./Mme)
     */
    public static function determineTitre($person)
    {
        // Par le genre si disponible
        if (isset($person['gender'])) {
            return $person['gender'] === 'F' ? 'Mme' : 'M.';
        }

        // Par le prénom
        if (isset($person['first_name'])) {
            $prenom = strtolower($person['first_name']);
            $prenomsFeminins = [
                'marie',
                'anne',
                'sophie',
                'claire',
                'julie',
                'sarah',
                'emma',
                'lea',
                'camille',
                'manon',
                'laura',
                'chloe',
                'charlotte',
                'pauline',
                'marine',
                'louise',
                'alice'
            ];

            foreach ($prenomsFeminins as $fem) {
                if (strpos($prenom, $fem) !== false) {
                    return 'Mme';
                }
            }
        }

        return 'M.'; // Par défaut
    }

    /**
     * Détermination du code d'âge FFE
     */
    public static function determineAgeCode($birthDate)
    {
        if (empty($birthDate)) return 'S';

        $age = self::calculateAge($birthDate);

        if ($age >= 50) return 'MJ'; // Majors
        if ($age >= 21) return 'S';  // Seniors  
        if ($age >= 18) return 'YR'; // Young Riders
        if ($age >= 16) return 'J';  // Juniors
        if ($age >= 14) return 'C';  // Cadets

        return 'P'; // Poneys
    }

    /**
     * Label de la catégorie d'âge
     */
    public static function determineAgeLabel($birthDate)
    {
        $code = self::determineAgeCode($birthDate);

        $labels = [
            'MJ' => 'Majors',
            'S' => 'Seniors',
            'YR' => 'Young Riders',
            'J' => 'Juniors',
            'C' => 'Cadets',
            'P' => 'Poneys'
        ];

        return $labels[$code] ?? 'Seniors';
    }

    /**
     * Calcul de l'âge
     */
    public static function calculateAge($birthDate)
    {
        if (empty($birthDate)) return 25;

        try {
            $birth = new DateTime($birthDate);
            $today = new DateTime();
            return $birth->diff($today)->y;
        } catch (Exception $e) {
            return 25;
        }
    }

    /**
     * Mapping du sexe des chevaux
     */
    public static function mapSexe($sex)
    {
        $mapping = [
            'hin' => 'Etalon',
            'val' => 'Hongre',
            'sto' => 'Jument',
            'M' => 'Etalon',
            'H' => 'Hongre',
            'F' => 'Jument'
        ];

        return $mapping[$sex] ?? 'Hongre';
    }

    /**
     * Mapping des races de chevaux
     */
    public static function mapBreedCode($breed)
    {
        if (empty($breed)) return 'SF';

        $breed = strtoupper($breed);

        if (strpos($breed, 'SELLE FRANCAIS') !== false) return 'SF';
        if (strpos($breed, 'ANGLO') !== false) return 'AES';
        if (strpos($breed, 'KWPN') !== false) return 'KWPN';
        if (strpos($breed, 'OLDENBURG') !== false) return 'OLD';
        if (strpos($breed, 'WESTF') !== false) return 'WESTF';
        if (strpos($breed, 'TRAKEH') !== false) return 'TRAK';
        if (strpos($breed, 'HANNOV') !== false) return 'HANN';
        if (strpos($breed, 'HOLSTEIN') !== false) return 'HOLST';
        if (strpos($breed, 'BWP') !== false) return 'BWP';
        if (strpos($breed, 'ZANGERSHEIDE') !== false) return 'ZANG';

        return 'SF'; // Par défaut
    }

    /**
     * Mapping des couleurs de robe
     */
    public static function mapColorCode($color)
    {
        if (empty($color)) return 'BAI';

        $color = strtoupper($color);

        if (strpos($color, 'BAI') !== false) return 'BAI';
        if (strpos($color, 'ALEZAN') !== false) return 'ALEZAN';
        if (strpos($color, 'GRIS') !== false) return 'GRIS';
        if (strpos($color, 'NOIR') !== false) return 'NOIR';
        if (strpos($color, 'ISABELLE') !== false) return 'ISABELLA';
        if (strpos($color, 'PIE') !== false) return 'PIE';
        if (strpos($color, 'ROUAN') !== false) return 'ROUAN';
        if (strpos($color, 'PALOMINO') !== false) return 'PALOMINO';

        return 'BAI'; // Par défaut
    }

    /**
     * Mapping de l'état du résultat
     */
    public static function mapResultState($result)
    {
        // Codes d'abandon/élimination
        if (isset($result['or']) && !empty($result['or'])) {
            switch ($result['or']) {
                case 'D':
                case 'E':
                    return 'EL'; // Éliminé
                case 'A':
                    return 'AB'; // Abandon  
                case 'U':
                    return 'NP'; // Non partant
                case 'S':
                    return 'DISQ'; // Disqualifié
            }
        }

        // Vérifier aussi le champ 'a'
        if (isset($result['a']) && !empty($result['a'])) {
            switch ($result['a']) {
                case 'Ö':
                    return 'NP'; // Non partant
            }
        }

        return 'FI'; // Fini normalement
    }
}
