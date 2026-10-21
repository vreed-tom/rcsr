<?php
// Aufruf auf der Kommandozeile: php werkzeuge/passwort-hash.php "NeuesPasswort"
if (PHP_SAPI !== 'cli') { exit; }
echo password_hash($argv[1] ?? '', PASSWORD_DEFAULT), PHP_EOL;
