<?php
// Digitales Handbuch RC Stuttgart-Remstal: Nachschlagewerk, Chatbot, Redaktion und Freigabe.

declare(strict_types=1);

require __DIR__ . '/lib.php';

date_default_timezone_set('Europe/Berlin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function csrf_feld(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function csrf_pruefen(): void
{
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sitzung abgelaufen. Bitte Seite neu laden.');
    }
}

function weiter(string $ziel): never
{
    header('Location: ' . $ziel);
    exit;
}

function meldung(string $text, string $art = 'ok'): void
{
    $_SESSION['meldung'] = [$text, $art];
}

$aktion = $_POST['aktion'] ?? null;

// --- Anmeldung -------------------------------------------------------------
if ($aktion === 'anmelden') {
    csrf_pruefen();
    $pw = (string)($_POST['passwort'] ?? '');
    $gefunden = null;
    foreach (cfg('passwoerter', []) as $r => $hash) {
        if (isset(ROLLEN[$r]) && $hash !== '' && password_verify($pw, $hash)) {
            $gefunden = $r;
        }
    }
    if ($gefunden) {
        session_regenerate_id(true);
        $_SESSION['rolle'] = $gefunden;
        $_SESSION['chat'] = [];
        weiter('?');
    }
    sleep(2); // bremst das Durchprobieren von Passwörtern
    meldung('Passwort nicht erkannt.', 'fehler');
    weiter('?');
}
if ($aktion === 'abmelden') {
    csrf_pruefen();
    $_SESSION = [];
    session_destroy();
    weiter('?');
}

if (!rolle()) {
    seite_kopf('Anmeldung');
    echo '<main class="schmal"><h1>Handbuch RC Stuttgart-Remstal</h1>';
    meldung_zeigen();
    echo '<form method="post" class="karte">' . csrf_feld() . '<input type="hidden" name="aktion" value="anmelden">'
        . '<label for="pw">Passwort</label><input id="pw" type="password" name="passwort" autofocus required>'
        . '<button>Anmelden</button></form>'
        . '<p class="klein">Mitglieder, Vorstand und Redaktion haben je ein eigenes Passwort. '
        . 'Fragen an den Chatbot werden zur Beantwortung an Mistral AI (EU) übermittelt und hier nicht gespeichert.</p></main>';
    seite_fuss();
    exit;
}

// --- Aktionen nach der Anmeldung --------------------------------------------
if ($aktion === 'frage') {
    csrf_pruefen();
    $frage = trim((string)($_POST['frage'] ?? ''));
    $_SESSION['chat'] ??= [];
    $_SESSION['anfragen'] = ($_SESSION['anfragen'] ?? 0) + 1;
    if ($frage !== '' && mb_strlen($frage) <= 1000) {
        if (!ki_verfuegbar()) {
            $antwort = 'Der Chatbot ist noch nicht eingerichtet (kein Mistral-Schlüssel in config.php). Bitte die Suche benutzen.';
        } elseif ($_SESSION['anfragen'] > (int)cfg('max_fragen_pro_sitzung', 60)) {
            $antwort = 'Für diese Sitzung sind genug Fragen gestellt. Bitte später erneut anmelden.';
        } else {
            try {
                $antwort = chat_antwort($frage, $_SESSION['chat']);
            } catch (RuntimeException $e) {
                $antwort = 'Fehler: ' . $e->getMessage();
            }
        }
        $_SESSION['chat'][] = ['rolle' => 'user', 'text' => $frage];
        $_SESSION['chat'][] = ['rolle' => 'assistant', 'text' => $antwort];
    }
    weiter('?seite=chat#ende');
}
if ($aktion === 'chat_leeren') {
    csrf_pruefen();
    $_SESSION['chat'] = [];
    weiter('?seite=chat');
}

if ($aktion === 'entwurf_formulieren' && darf_einreichen()) {
    csrf_pruefen();
    $datei = (string)($_POST['datei'] ?? '');
    $roh = trim((string)($_POST['rohtext'] ?? ''));
    if (!in_array($datei, kb_dateien(), true) || $roh === '') {
        meldung('Bitte Baustein wählen und Text eingeben.', 'fehler');
        weiter('?seite=redaktion');
    }
    if (ki_verfuegbar()) {
        try {
            $_SESSION['entwurf'] = entwurf_formulieren($roh, $datei) + ['datei' => $datei];
        } catch (RuntimeException $e) {
            meldung($e->getMessage(), 'fehler');
            weiter('?seite=redaktion');
        }
    } else {
        $_SESSION['entwurf'] = ['titel' => '', 'text' => $roh, 'sicht' => 'öffentlich', 'datei' => $datei,
            'hinweise' => 'Ohne Chatbot nicht umformuliert: Bitte selbst alle Personennamen entfernen.'];
    }
    weiter('?seite=redaktion');
}
if ($aktion === 'entwurf_einreichen' && darf_einreichen()) {
    csrf_pruefen();
    $datei = (string)($_POST['datei'] ?? '');
    $titel = trim((string)($_POST['titel'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    if (!in_array($datei, kb_dateien(), true) || $titel === '' || $text === '') {
        meldung('Titel und Text dürfen nicht leer sein.', 'fehler');
        weiter('?seite=redaktion');
    }
    entwurf_speichern([
        'datei' => $datei,
        'titel' => $titel,
        'text' => $text,
        'sicht' => ($_POST['sicht'] ?? '') === 'intern' ? 'intern' : 'öffentlich',
        'hinweise' => trim((string)($_POST['hinweise'] ?? '')),
        'erstellt' => date('c'),
        'von_rolle' => rolle(),
    ]);
    unset($_SESSION['entwurf']);
    meldung('Danke! Der Entwurf wartet jetzt auf die Freigabe durch den Vorstand.');
    weiter('?seite=redaktion');
}
if ($aktion === 'entwurf_verwerfen_lokal' && darf_einreichen()) {
    csrf_pruefen();
    unset($_SESSION['entwurf']);
    weiter('?seite=redaktion');
}

if ($aktion === 'freigeben' && darf_freigeben()) {
    csrf_pruefen();
    [$datei, $abschnitt] = array_pad(explode('|', (string)($_POST['ziel'] ?? ''), 2), 2, '');
    $stand = in_array($_POST['stand'] ?? '', ['belegt', 'teilweise', 'offen'], true) ? $_POST['stand'] : 'teilweise';
    try {
        $id = eintrag_einfuegen($datei, $abschnitt, [
            'titel' => trim((string)$_POST['titel']),
            'text' => trim((string)$_POST['text']),
            'sicht' => ($_POST['sicht'] ?? '') === 'intern' ? 'intern' : 'öffentlich',
            'stand' => $stand,
            'quellen' => trim((string)($_POST['quellen'] ?? '')) ?: 'Redaktion ' . date('d.m.Y'),
        ]);
        entwurf_loeschen((string)$_POST['key']);
        meldung("Freigegeben und als Eintrag $id gespeichert.");
    } catch (RuntimeException $e) {
        meldung($e->getMessage(), 'fehler');
    }
    weiter('?seite=freigabe');
}
if ($aktion === 'verwerfen' && darf_freigeben()) {
    csrf_pruefen();
    entwurf_loeschen((string)($_POST['key'] ?? ''));
    meldung('Entwurf verworfen.');
    weiter('?seite=freigabe');
}

// --- Seiten -----------------------------------------------------------------
$seite = (string)($_GET['seite'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

seite_kopf('Handbuch');
navigation($seite, $q);
echo '<main>';
meldung_zeigen();

if ($q !== '') {
    seite_suche($q);
} elseif ($seite === 'chat') {
    seite_chat();
} elseif ($seite === 'redaktion' && darf_einreichen()) {
    seite_redaktion();
} elseif ($seite === 'freigabe' && darf_freigeben()) {
    seite_freigabe();
} elseif ($seite !== '' && in_array($seite . '.md', kb_dateien(), true) && datei_sichtbar($seite . '.md')) {
    seite_baustein($seite . '.md');
} else {
    seite_start();
}
echo '</main>';
seite_fuss();

// ---------------------------------------------------------------------------

function seite_kopf(string $titel): void
{
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($titel) . ' · RC Stuttgart-Remstal</title>'
        . '<link rel="stylesheet" href="assets/style.css"></head><body>';
}

function seite_fuss(): void
{
    echo '</body></html>';
}

function meldung_zeigen(): void
{
    if (!empty($_SESSION['meldung'])) {
        [$text, $art] = $_SESSION['meldung'];
        echo '<p class="meldung ' . h($art) . '">' . h($text) . '</p>';
        unset($_SESSION['meldung']);
    }
}

function navigation(string $seite, string $q): void
{
    echo '<header class="kopf"><a class="marke" href="?">Handbuch RC Stuttgart-Remstal</a>'
        . '<form class="suche" method="get"><input type="search" name="q" value="' . h($q) . '" placeholder="Suchen …" aria-label="Suchen"></form>'
        . '<span class="rolle">' . h(ROLLEN[rolle()]) . '</span>'
        . '<form method="post">' . csrf_feld() . '<input type="hidden" name="aktion" value="abmelden"><button class="link">Abmelden</button></form>'
        . '</header><nav class="leiste">';
    $punkte = [];
    foreach (kb_dateien() as $d) {
        if (datei_sichtbar($d)) {
            $punkte[slug_von_datei($d)] = kurzname(parse_datei($d)['titel']);
        }
    }
    $punkte['chat'] = 'Fragen an den Chatbot';
    if (darf_einreichen()) {
        $punkte['redaktion'] = 'Inhalt beitragen';
    }
    if (darf_freigeben()) {
        $n = count(entwuerfe());
        $punkte['freigabe'] = 'Freigabe' . ($n ? " ($n)" : '');
    }
    foreach ($punkte as $slug => $name) {
        echo '<a href="?seite=' . h($slug) . '"' . ($seite === $slug ? ' class="aktiv"' : '') . '>' . h($name) . '</a>';
    }
    echo '</nav>';
}

function kurzname(string $titel): string
{
    return trim(preg_replace('/^Baustein \d+:\s*/u', '', $titel));
}

function seite_start(): void
{
    echo '<h1>Willkommen im Handbuch</h1>'
        . '<p>Hier stehen Aktivitäten, Veranstaltungen und Gepflogenheiten des RC Stuttgart-Remstal. '
        . 'Wählen Sie oben einen Baustein, suchen Sie nach einem Stichwort oder fragen Sie den Chatbot.</p>';
    if (sieht_alles()) {
        $gemischt = [];
        foreach (kb_dateien() as $d) {
            foreach (parse_datei($d)['bloecke'] as $b) {
                if ($b['typ'] === 'eintrag' && str_contains($b['sicht'], 'öffentlich') && str_contains($b['sicht'], 'intern')) {
                    $gemischt[] = $b;
                }
            }
        }
        if ($gemischt) {
            echo '<div class="karte hinweis"><strong>Hinweis für den Vorstand:</strong> Diese Einträge sind teils öffentlich, teils intern markiert '
                . 'und werden Mitgliedern deshalb ganz verborgen. Zum Freischalten bitte in einen öffentlichen und einen internen Eintrag aufteilen:<ul>';
            foreach ($gemischt as $e) {
                echo '<li><a href="' . h(eintrag_link($e)) . '">' . h($e['id'] . ' ' . $e['titel']) . '</a></li>';
            }
            echo '</ul></div>';
        }
    }
}

function eintrag_html(array $e): string
{
    $klasse = ist_oeffentlich($e['sicht']) ? 'oeffentlich' : 'intern';
    return '<article class="eintrag" id="' . h($e['id']) . '"><h3>' . h($e['titel']) . '</h3>'
        . '<p class="kopfzeile"><span class="id">' . h($e['id']) . '</span>'
        . (sieht_alles() ? '<span class="sicht ' . $klasse . '">' . h($e['sicht']) . '</span>' : '')
        . '<span class="stand stand-' . h(preg_replace('/\W/u', '', $e['stand'])) . '">' . h($e['stand']) . '</span>'
        . '<span class="quellen">Quellen: ' . h($e['quellen']) . '</span></p>'
        . md($e['text']) . '</article>';
}

function seite_baustein(string $datei): void
{
    $d = parse_datei($datei);
    echo '<h1>' . h($d['titel']) . '</h1>';
    if (sieht_alles() && $d['einleitung'] !== '') {
        echo '<div class="einleitung">' . md($d['einleitung']) . '</div>';
    }
    $offener_abschnitt = null;
    $inhalt_seit_abschnitt = false;
    foreach ($d['bloecke'] as $b) {
        if (!block_sichtbar($b)) {
            continue;
        }
        if ($b['typ'] === 'abschnitt') {
            $offener_abschnitt = '<h2>' . md_inline($b['titel']) . '</h2>';
            continue;
        }
        if ($offener_abschnitt) {
            echo $offener_abschnitt; // Abschnittsüberschrift nur zeigen, wenn darunter etwas sichtbar ist
            $offener_abschnitt = null;
        }
        echo $b['typ'] === 'eintrag' ? eintrag_html($b) : '<div class="frei">' . md($b['text']) . '</div>';
    }
}

function seite_suche(string $q): void
{
    $treffer = suche($q);
    echo '<h1>Suche: „' . h($q) . '“</h1>';
    if (!$treffer) {
        echo '<p>Keine Einträge gefunden.</p>';
        return;
    }
    echo '<p class="klein">' . count($treffer) . ' Treffer</p><ol class="treffer">';
    foreach ($treffer as $t) {
        $e = $t['eintrag'];
        echo '<li><a href="' . h(eintrag_link($e)) . '">' . h($e['titel']) . '</a> <span class="id">' . h($e['id']) . '</span>'
            . '<p>' . h($t['auszug']) . '</p></li>';
    }
    echo '</ol>';
}

function seite_chat(): void
{
    echo '<h1>Fragen an den Chatbot</h1>'
        . '<p class="klein">Der Chatbot antwortet nur aus dem Handbuch' . (sieht_alles() ? '' : ' (öffentlicher Teil)')
        . ' und nennt die Einträge, auf die er sich stützt. Bitte die Angaben im verlinkten Eintrag prüfen.</p>';
    echo '<div class="chat">';
    foreach ($_SESSION['chat'] ?? [] as $m) {
        if ($m['rolle'] === 'user') {
            echo '<div class="frage">' . h($m['text']) . '</div>';
        } else {
            echo '<div class="antwort">' . verweise_verlinken(md($m['text'])) . '</div>';
        }
    }
    echo '<span id="ende"></span></div>'
        . '<form method="post" class="chatform">' . csrf_feld() . '<input type="hidden" name="aktion" value="frage">'
        . '<textarea name="frage" rows="2" maxlength="1000" required placeholder="z. B. Wann ist die Ämterübergabe?"></textarea>'
        . '<button>Fragen</button></form>';
    if (!empty($_SESSION['chat'])) {
        echo '<form method="post">' . csrf_feld() . '<input type="hidden" name="aktion" value="chat_leeren"><button class="link">Verlauf leeren</button></form>';
    }
}

function datei_auswahl(string $name, string $gewaehlt): string
{
    $html = '<select name="' . h($name) . '" required>';
    foreach (kb_dateien() as $d) {
        if (in_array($d, NUR_VORSTAND, true)) {
            continue;
        }
        $html .= '<option value="' . h($d) . '"' . ($d === $gewaehlt ? ' selected' : '') . '>' . h(kurzname(parse_datei($d)['titel'])) . '</option>';
    }
    return $html . '</select>';
}

function sicht_auswahl(string $gewaehlt): string
{
    $html = '<select name="sicht">';
    foreach (['öffentlich' => 'öffentlich (alle Mitglieder)', 'intern' => 'intern (nur Vorstand)'] as $w => $l) {
        $html .= '<option value="' . h($w) . '"' . ($w === $gewaehlt ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    return $html . '</select>';
}

function seite_redaktion(): void
{
    echo '<h1>Inhalt beitragen</h1>';
    $e = $_SESSION['entwurf'] ?? null;
    if (!$e) {
        echo '<p>Schreiben Sie in eigenen Worten, was ins Handbuch gehört. '
            . (ki_verfuegbar() ? 'Der Chatbot formt daraus einen Eintrag und ersetzt Namen durch Rollen. ' : '')
            . 'Sie können den Entwurf danach noch ändern. Erst nach Freigabe durch den Vorstand erscheint er im Handbuch.</p>'
            . '<form method="post" class="karte">' . csrf_feld() . '<input type="hidden" name="aktion" value="entwurf_formulieren">'
            . '<label>Baustein</label>' . datei_auswahl('datei', '')
            . '<label>Ihr Text</label><textarea name="rohtext" rows="10" required maxlength="8000"></textarea>'
            . '<button>' . (ki_verfuegbar() ? 'Entwurf erstellen lassen' : 'Weiter') . '</button></form>';
        return;
    }
    echo '<p>Bitte prüfen und bei Bedarf ändern. <strong>Achten Sie darauf, dass keine Personennamen enthalten sind.</strong></p>'
        . '<form method="post" class="karte">' . csrf_feld() . '<input type="hidden" name="aktion" value="entwurf_einreichen">'
        . '<label>Baustein</label>' . datei_auswahl('datei', $e['datei'])
        . '<label>Titel</label><input name="titel" value="' . h($e['titel']) . '" required>'
        . '<label>Text</label><textarea name="text" rows="12" required>' . h($e['text']) . '</textarea>'
        . '<label>Vorschlag Sichtbarkeit</label>' . sicht_auswahl($e['sicht'])
        . '<label>Hinweise an den Vorstand</label><textarea name="hinweise" rows="3">' . h($e['hinweise']) . '</textarea>'
        . '<button>Zur Freigabe einreichen</button></form>'
        . '<form method="post">' . csrf_feld() . '<input type="hidden" name="aktion" value="entwurf_verwerfen_lokal"><button class="link">Verwerfen und neu beginnen</button></form>';
}

function seite_freigabe(): void
{
    echo '<h1>Freigabe</h1>';
    $liste = entwuerfe();
    if (!$liste) {
        echo '<p>Keine Entwürfe offen.</p>';
        return;
    }
    foreach ($liste as $e) {
        $ziele = '';
        foreach (kb_dateien() as $d) {
            if (in_array($d, NUR_VORSTAND, true)) {
                continue;
            }
            $pd = parse_datei($d);
            $abschnitte = array_values(array_filter($pd['bloecke'], fn($b) => $b['typ'] === 'abschnitt'));
            $optionen = $abschnitte ? array_map(fn($b) => $b['titel'], $abschnitte) : [''];
            foreach ($optionen as $a) {
                $wert = $d . '|' . $a;
                $sel = ($d === $e['datei'] && $a === end($optionen)) ? ' selected' : '';
                $ziele .= '<option value="' . h($wert) . '"' . $sel . '>' . h(kurzname($pd['titel']) . ($a !== '' ? ' › ' . $a : '')) . '</option>';
            }
        }
        echo '<form method="post" class="karte">' . csrf_feld()
            . '<input type="hidden" name="key" value="' . h($e['key']) . '">'
            . '<p class="klein">Eingereicht am ' . h(date('d.m.Y H:i', strtotime($e['erstellt']))) . ' über den Zugang „' . h(ROLLEN[$e['von_rolle']] ?? '') . '“</p>'
            . ($e['hinweise'] !== '' ? '<p class="hinweis">' . h($e['hinweise']) . '</p>' : '')
            . '<label>Einordnen unter</label><select name="ziel">' . $ziele . '</select>'
            . '<label>Titel</label><input name="titel" value="' . h($e['titel']) . '" required>'
            . '<label>Text</label><textarea name="text" rows="10" required>' . h($e['text']) . '</textarea>'
            . '<div class="reihe"><div><label>Sichtbarkeit</label>' . sicht_auswahl($e['sicht']) . '</div>'
            . '<div><label>Stand</label><select name="stand"><option>belegt</option><option selected>teilweise</option><option>offen</option></select></div>'
            . '<div><label>Quellen</label><input name="quellen" value="' . h('Redaktion ' . date('d.m.Y', strtotime($e['erstellt']))) . '"></div></div>'
            . '<div class="knoepfe"><button name="aktion" value="freigeben">Freigeben</button>'
            . '<button name="aktion" value="verwerfen" class="zweitrangig">Verwerfen</button></div></form>';
    }
}
