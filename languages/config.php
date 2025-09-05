<?php
// languages/config.php

namespace FFE\Extension\Languages;

// languages/config.php simplifié
class LanguageConfig
{

    const SUPPORTED_LANGUAGES = [
        'fr' => 'Français',
        'en' => 'English'
    ];

    const DEFAULT_LANGUAGE = 'fr';

    public static function detectLanguage()
    {
        // Vérifier la session en priorité
        if (
            isset($_SESSION['ffe_language']) &&
            array_key_exists($_SESSION['ffe_language'], self::SUPPORTED_LANGUAGES)
        ) {
            return $_SESSION['ffe_language'];
        }

        // Détecter depuis le navigateur
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
            if ($browserLang === 'en') {
                $_SESSION['ffe_language'] = 'en';
                return 'en';
            }
        }

        // Défaut : français
        $_SESSION['ffe_language'] = self::DEFAULT_LANGUAGE;
        return self::DEFAULT_LANGUAGE;
    }

    public static function loadTranslations($lang)
    {
        $file = __DIR__ . '/' . $lang . '.php';

        if (file_exists($file)) {
            return include $file;
        }

        return include __DIR__ . '/fr.php';
    }
}
