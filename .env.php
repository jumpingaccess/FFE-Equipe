<?php
// .env.php - Configuration pour l'extension FFE
// Copier ce fichier vers .env.php et remplir avec vos vraies clés

return [
    // Clé secrète JWT fournie par Equipe pour décoder les tokens
    // Cette clé est utilisée pour valider les tokens JWT envoyés par Equipe
    'EQUIPE_SECRET' => 'your_Equipe_Secret_key_from extension_management',
    
    // Mode DEBUG - Active les logs détaillés (1 = On, 0 = Off)
    'DEBUG' => 1,
    
    // Version de l'extension
    'VERSION' => '1.0.0',
    
    // Configuration FFE (optionnel - pour futures intégrations)
    'FFE_API_URL' => 'https://www.ffe.com/api/',
    'FFE_API_KEY' => '',
    
    // Paramètres par défaut
    'DEFAULT_COUNTRY' => 'FRA',
    'DEFAULT_CURRENCY' => 'EUR',
    
    // Timezone pour l'application
    'TIMEZONE' => 'Europe/Paris',
    
    // Chemin pour les fichiers temporaires
    'TEMP_PATH' => sys_get_temp_dir(),
    
    // Limite de taille pour l'upload XML (en MB)
    'MAX_UPLOAD_SIZE' => 50,
    
    // Options d'export
    'EXPORT_ENCODING' => 'UTF-8',
    'EXPORT_LINE_ENDING' => "\r\n", // Windows format pour compatibilité FFE
    
    // Custom fields mapping (pour future utilisation)
    'CUSTOM_FIELDS' => [
        'engageur' => 'custom_engageur',
        'coach' => 'custom_coach',
        'categorie_age' => 'custom_categorie_age'
    ]
];

?>
    