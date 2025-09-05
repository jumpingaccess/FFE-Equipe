<?php
// languages/fr.php

return [
    // Interface principale
    'title' => 'Extension FFE pour Equipe',
    'loading' => 'Vérification des imports existants...',

    // Navigation
    'nav_view_imports' => 'Voir les imports',
    'nav_new_import' => 'Nouvel import',

    // Messages
    'no_imports' => 'Aucune épreuve FFE n\'a encore été importée dans ce meeting.',
    'import_file' => 'Importer un fichier XML FFE',
    'select_file' => 'Fichier XML FFE:',
    'select_file_help' => 'Sélectionnez le fichier XML exporté depuis la FFE',
    'analyze_file' => 'Analyser le fichier',
    'analyze_in_progress' => 'Analyse du fichier en cours...',

    // Tableau des compétitions
    'imported_competitions' => 'Épreuves FFE importées',
    'imported_comp_multi' => "épreuve(s) FFE importée(s)",
    'col_event_num' => 'N° Épreuve',
    'col_name' => 'Nom',
    'col_date' => 'Date',
    'col_ffe_num' => 'N° FFE',
    'col_results' => 'Résultats',
    'col_exports' => 'Exports',
    'badge_title' => 'Numéro de concours FFE',
    'badge_wait' => 'En attente',

    // Statuts
    'status_yes' => 'OUI',
    'status_waiting' => 'En attente',
    'status_in_progress' => 'En attente de résultats',

    // Boutons
    'btn_export_all' => 'Exporter tout en FFECompet',
    'btn_refresh' => 'Rafraîchir',
    'refresh_in_progress' => 'Rafraichissement...',
    'btn_select_all' => 'Tout sélectionner',
    'btn_deselect_all' => 'Tout désélectionner',
    'btn_import' => 'Importer vers Equipe',
    'btn_back' => 'Retour',

    // Étape de sélection
    'competition_data' => 'Données du concours',
    'select_competitions' => 'Sélectionner les épreuves à importer:',
    'competition_level' => 'Niveau des épreuves:',
    'level_help' => 'Ce niveau sera appliqué à toutes les épreuves sélectionnées',

    // Niveaux
    'level_club' => 'Club',
    'level_local' => 'Local',
    'level_regional' => 'Régional',
    'level_national' => 'National',
    'level_elite' => 'Elite',
    'level_international' => 'International',

    // Statistiques
    'stats_competitions' => 'Épreuves',
    'stats_riders' => 'Cavaliers',
    'stats_officials' => 'Officiels',
    'stats_horses' => 'Chevaux',
    'stats_clubs' => 'Clubs',
    'stats_entries' => 'Engagements',
    'from' => 'Du',
    'to' => 'au',
    'organizer' => "Organisateur",
    // Import en cours
    'import_in_progress' => 'Import en cours...',
    'import_preparing' => 'Préparation de l\'import...',
    'import_success' => 'Import terminé avec succès!',
    'import_check' => 'Vérifier les imports',

    // Messages d'erreur
    'error_connection' => 'Erreur de connexion: {error}',
    'error_connection_no_param' => 'Erreur de connexion',
    'error_no_file' => 'Aucun fichier sélectionné',
    'error_invalid_xml' => 'Fichier XML invalide',
    'error_no_selection' => 'Veuillez sélectionner au moins une épreuve',
    'warning_error' => 'Erreur {error}',
    "error_server" => "Erreur de connexion au serveur",
    'error_comp_not_found' => "Aucune épreuve FFE trouvée.",
    'error_ffecompet' => "Erreur dans l'export FFECompet {error}",
    'error_in_export' => 'Erreur lors de l\'export: {error}',
    'error_in_import' => 'Erreur lors de l\'import: {error}',
    'error_missing_api' => 'Configuration manquante . API Key ou URL non fournie par Equipe . ',
    'error_in_analyze' => ' Erreur lors de l\'analyse: {error}',
    'error' => 'Erreur {error}',

    // Messages de succès
    'success_file_analyzed' => 'Fichier analysé avec succès',
    'success_import_complete' => 'Import terminé! Les données ont été envoyées à Equipe.',
    'success_export' => 'Export réussi',
    'success_global_export' => 'Export FFECompet global réussi',


    // Tooltips
    'tooltip_export_sif' => 'Exporter au format SIF',
    'tooltip_export_ffe' => 'Exporter au format FFECompet TXT',
    'tooltip_global_export_ffe' => 'Exporter au tout format FFECompet',

    // Export
    'export_title' => 'Export des résultats',
    'export_in_progress' => 'Export des résultats en cours ...',
    'export_completed' => 'Export des résultats réussi',
    'export_running' => 'Export en cours...',

    // Footer
    'format_info' => 'Format texte basé sur FFECompet V24',
    'generated_on' => 'Généré le'
];
