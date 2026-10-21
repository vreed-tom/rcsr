<?php
// Vorlage: als config.php kopieren und ausfüllen. config.php nie ins Git-Repository einchecken.
//
// Passwort-Hashes erzeugen (auf einem Rechner mit PHP):
//   php -r 'echo password_hash("NeuesPasswort", PASSWORD_DEFAULT), PHP_EOL;'
// oder über die Datei werkzeuge/passwort-hash.php (nach Gebrauch wieder löschen).

return [
    // Je Rolle ein Passwort-Hash. Leerer Wert = Zugang gesperrt.
    'passwoerter' => [
        'mitglied'  => '',
        'redaktion' => '',
        'vorstand'  => '',
    ],

    // Mistral-API. Ohne Schlüssel laufen Nachschlagewerk und Suche, der Chatbot ist dann aus.
    'mistral_api_key'  => '',
    'mistral_base_url' => 'https://api.eu.mistral.ai', // EU-Endpunkt (10 % Aufschlag); global: https://api.mistral.ai
    'mistral_model'    => 'mistral-small-latest',     // Verfügbarkeit am EU-Endpunkt nach Kontoanlage prüfen

    // Ordner mit den Markdown-Dateien der Wissensbasis.
    'wissensbasis_dir' => __DIR__ . '/data/wissensbasis',

    // Schutz vor versehentlich hohen Kosten (zusätzlich zum Ausgabenlimit im Mistral-Konto).
    'max_fragen_pro_sitzung' => 60,
];
