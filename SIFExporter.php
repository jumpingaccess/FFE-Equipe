<?php

namespace FFE\Extension\Export;

/**
 * Export au format SIF (Système d'Information Fédéral)
 * Basé sur https://www.telemat.org/FFE/sif/
 */
class SIFExporter
{
    /**
     * Génère l'export SIF pour un concours
     */
    public function export($concours, $competitions, $people, $horses, $starts)
    {
        $output = [];

        // En-tête SIF
        $output[] = $this->generateHeader($concours);

        // Section concours
        $output[] = $this->generateConcoursSection($concours);

        // Section épreuves
        foreach ($competitions as $comp) {
            $output[] = $this->generateEpreuveSection($comp, $starts[$comp['foreign_id']] ?? []);
        }

        // Section cavaliers
        $output[] = $this->generateCavaliersSection($people);

        // Section chevaux
        $output[] = $this->generateChevauxSection($horses);

        // Pied de page
        $output[] = $this->generateFooter();

        return implode("\r\n", $output);
    }

    private function generateHeader($concours)
    {
        $header = [];
        $header[] = "## FICHIER SIF FFE ##";
        $header[] = "## VERSION: 2.0 ##";
        $header[] = "## DATE: " . date('d/m/Y H:i:s') . " ##";
        $header[] = "## CONCOURS: " . $concours['num_ffe'] . " ##";
        $header[] = "";

        return implode("\r\n", $header);
    }

    private function generateConcoursSection($concours)
    {
        $section = [];
        $section[] = "[CONCOURS]";
        $section[] = "NUM=" . $concours['num_ffe'];
        $section[] = "NOM=" . $this->cleanText($concours['nom']);
        $section[] = "DATE_DEBUT=" . $this->formatDate($concours['date_debut']);
        $section[] = "DATE_FIN=" . $this->formatDate($concours['date_fin']);
        $section[] = "DEPT=" . $concours['departement'];
        $section[] = "ORG_NUM=" . $concours['organisateur']['num'];
        $section[] = "ORG_NOM=" . $this->cleanText($concours['organisateur']['nom']);
        $section[] = "";

        return implode("\r\n", $section);
    }

    private function generateEpreuveSection($competition, $starts)
    {
        $section = [];
        $section[] = "[EPREUVE]";
        $section[] = "NUM=" . str_replace('EP', '', $competition['clabb']);
        $section[] = "LIBELLE=" . $this->cleanText($competition['klass']);
        $section[] = "DATE=" . $this->formatDate($competition['datum']);
        $section[] = "HEURE=" . $competition['klock'];
        $section[] = "DISCIPLINE=" . $this->mapDisciplineToSIF($competition['z']);
        $section[] = "NIVEAU=" . $this->mapNiveauToSIF($competition['x']);
        $section[] = "DOTATION=" . number_format($competition['prsum1'] ?? 0, 2, '.', '');
        $section[] = "ENGAGES=" . count($starts);

        // Ajouter les engagements
        foreach ($starts as $start) {
            $section[] = $this->generateEngagementLine($start);
        }

        $section[] = "";

        return implode("\r\n", $section);
    }

    private function generateEngagementLine($start)
    {
        $line = "ENG=";
        $line .= ($start['st'] ?? '0') . ";";  // Dossard
        $line .= ($start['rider']['rlic'] ?? '') . ";";  // Licence cavalier
        $line .= ($start['horse']['regnr'] ?? '') . ";";  // SIRE cheval
        $line .= ($start['re'] ?? '') . ";";  // Classement
        $line .= ($start['grundf'] ?? '') . ";";  // Points/Fautes
        $line .= ($start['grundt'] ?? '') . ";";  // Temps
        $line .= ($start['premie'] ?? '0');  // Gains

        return $line;
    }

    private function generateCavaliersSection($people)
    {
        $section = [];
        $section[] = "[CAVALIERS]";

        foreach ($people as $person) {
            if (isset($person['rlic']) && !empty($person['rlic'])) {
                $line = "CAV=";
                $line .= $person['rlic'] . ";";
                $line .= $this->cleanText($person['last_name'] ?? '') . ";";
                $line .= $this->cleanText($person['first_name'] ?? '') . ";";
                $line .= ($person['fei_id'] ?? '') . ";";
                $line .= ($person['club']['name'] ?? '');

                $section[] = $line;
            }
        }

        $section[] = "";
        return implode("\r\n", $section);
    }

    private function generateChevauxSection($horses)
    {
        $section = [];
        $section[] = "[CHEVAUX]";

        foreach ($horses as $horse) {
            if (isset($horse['regnr']) && !empty($horse['regnr'])) {
                $line = "CHV=";
                $line .= $horse['regnr'] . ";";
                $line .= $this->cleanText($horse['hast'] ?? '') . ";";
                $line .= ($horse['feipass'] ?? '') . ";";
                $line .= ($horse['fo'] ?? '') . ";";
                $line .= $this->mapSexeToSIF($horse['kon'] ?? '') . ";";
                $line .= $this->cleanText($horse['breed'] ?? '') . ";";
                $line .= $this->cleanText($horse['far'] ?? '') . ";";
                $line .= $this->cleanText($horse['mor'] ?? '') . ";";
                $line .= $this->cleanText($horse['agare'] ?? '');

                $section[] = $line;
            }
        }

        $section[] = "";
        return implode("\r\n", $section);
    }

    private function generateFooter()
    {
        $footer = [];
        $footer[] = "[FIN]";
        $footer[] = "## FIN FICHIER SIF ##";

        return implode("\r\n", $footer);
    }

    private function cleanText($text)
    {
        // Nettoyer le texte pour le format SIF
        $text = str_replace([';', "\r", "\n", "\t"], ' ', $text);
        return trim($text);
    }

    private function formatDate($date)
    {
        // Convertir YYYY-MM-DD vers DD/MM/YYYY
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $date, $matches)) {
            return $matches[3] . '/' . $matches[2] . '/' . $matches[1];
        }
        return $date;
    }

    private function mapDisciplineToSIF($z)
    {
        $mapping = [
            'H' => '01',  // Saut d'obstacles
            'D' => '03',  // Dressage
            'F' => '04',  // CCE
            'A' => '08',  // Elevage
            'K' => '05',  // Attelage
            'E' => '06',  // Endurance
            'R' => '07',  // Western/Reining
        ];
        return $mapping[$z] ?? '01';
    }

    private function mapNiveauToSIF($x)
    {
        $mapping = [
            'K' => 'CLUB',
            'L' => 'LOCAL',
            'R' => 'REGIONAL',
            'N' => 'NATIONAL',
            'E' => 'ELITE',
            'I' => 'INTERNATIONAL'
        ];
        return $mapping[$x] ?? 'CLUB';
    }

    private function mapSexeToSIF($kon)
    {
        $mapping = [
            'hin' => 'E',  // Etalon
            'val' => 'H',  // Hongre
            'sto' => 'J'   // Jument
        ];
        return $mapping[$kon] ?? 'H';
    }
}

/**
 * Export au format FFECompet
 * Format spécifique pour l'import dans FFECompet
 */
class FFECompetExporter
{
    /**
     * Génère l'export FFECompet pour un concours
     */
    public function export($concours, $competitions, $people, $horses, $starts, $results = null)
    {
        $output = [];

        // En-tête FFECompet
        $output[] = $this->generateHeader();

        // Informations du concours
        $output[] = $this->generateConcoursInfo($concours);

        // Export des épreuves avec résultats
        foreach ($competitions as $comp) {
            $compStarts = $starts[$comp['foreign_id']] ?? [];
            $compResults = $results[$comp['foreign_id']] ?? [];
            $output[] = $this->generateEpreuveData($comp, $compStarts, $compResults);
        }

        return implode("\r\n", $output);
    }

    private function generateHeader()
    {
        $header = [];
        $header[] = "* EXPORT FFECOMPET V3.0";
        $header[] = "* DATE: " . date('d/m/Y');
        $header[] = "* HEURE: " . date('H:i:s');
        $header[] = "*";

        return implode("\r\n", $header);
    }

    private function generateConcoursInfo($concours)
    {
        $info = [];
        $info[] = "C;" . $concours['num_ffe'] . ";" .
            $this->cleanText($concours['nom']) . ";" .
            $this->formatDateFFE($concours['date_debut']) . ";" .
            $this->formatDateFFE($concours['date_fin']) . ";" .
            $concours['departement'] . ";" .
            $concours['organisateur']['num'] . ";" .
            $this->cleanText($concours['organisateur']['nom']);
        $info[] = "";

        return implode("\r\n", $info);
    }

    private function generateEpreuveData($competition, $starts, $results)
    {
        $data = [];

        // Ligne d'épreuve
        $epreuveLine = "E;" .
            str_replace('EP', '', $competition['clabb']) . ";" .
            $this->cleanText($competition['klass']) . ";" .
            $this->formatDateFFE($competition['datum']) . ";" .
            $competition['klock'] . ";" .
            $this->mapDisciplineCode($competition['z']) . ";" .
            ($competition['code_bareme'] ?? '') . ";" .
            number_format($competition['prsum1'] ?? 0, 2, '.', '') . ";" .
            count($starts);

        $data[] = $epreuveLine;

        // Lignes de participants/résultats
        foreach ($starts as $start) {
            $resultData = $this->findResultForStart($start, $results);

            $participantLine = "P;" .
                ($start['st'] ?? '0') . ";" .
                ($start['rider']['rlic'] ?? '') . ";" .
                $this->cleanText($start['rider']['last_name'] ?? '') . ";" .
                $this->cleanText($start['rider']['first_name'] ?? '') . ";" .
                ($start['horse']['regnr'] ?? '') . ";" .
                $this->cleanText($start['horse']['hast'] ?? '') . ";" .
                ($resultData['classement'] ?? '') . ";" .
                ($resultData['points'] ?? '') . ";" .
                ($resultData['temps'] ?? '') . ";" .
                ($resultData['gains'] ?? '0') . ";" .
                $this->mapStatut($resultData['statut'] ?? '');

            $data[] = $participantLine;
        }

        $data[] = "";

        return implode("\r\n", $data);
    }

    private function findResultForStart($start, $results)
    {
        // Chercher le résultat correspondant au start
        foreach ($results as $result) {
            if (isset($result['foreign_id']) && $result['foreign_id'] === $start['foreign_id']) {
                return [
                    'classement' => $result['re'] ?? '',
                    'points' => $result['grundf'] ?? '',
                    'temps' => $result['grundt'] ?? '',
                    'gains' => $result['premie'] ?? '0',
                    'statut' => $this->determineStatut($result)
                ];
            }
        }

        return [
            'classement' => '',
            'points' => '',
            'temps' => '',
            'gains' => '0',
            'statut' => ''
        ];
    }

    private function determineStatut($result)
    {
        if (isset($result['or'])) {
            switch ($result['or']) {
                case 'D':
                    return 'ELIMINE';
                case 'U':
                    return 'ABANDON';
                case 'A':
                    return 'ABSENT';
            }
        }

        if (isset($result['rid']) && $result['rid']) {
            return 'TERMINE';
        }

        return 'EN_COURS';
    }

    private function mapStatut($statut)
    {
        $mapping = [
            'TERMINE' => 'T',
            'ELIMINE' => 'E',
            'ABANDON' => 'A',
            'ABSENT' => 'N',
            'EN_COURS' => 'C'
        ];
        return $mapping[$statut] ?? '';
    }

    private function cleanText($text)
    {
        // Nettoyer le texte pour FFECompet
        $text = str_replace([';', "\r", "\n", "\t"], ' ', $text);
        return trim($text);
    }

    private function formatDateFFE($date)
    {
        // Format YYYYMMDD pour FFECompet
        return str_replace('-', '', $date);
    }

    private function mapDisciplineCode($z)
    {
        $mapping = [
            'H' => '01',  // Saut d'obstacles
            'D' => '03',  // Dressage
            'F' => '04',  // CCE
            'A' => '08',  // Elevage
            'K' => '05',  // Attelage
            'E' => '06',  // Endurance
            'R' => '07',  // Western/Reining
        ];
        return $mapping[$z] ?? '01';
    }
}
