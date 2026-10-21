<?php
// Hilfsfunktionen: Konfiguration, Wissensbasis lesen, Markdown anzeigen, Mistral-API, Entwürfe.

declare(strict_types=1);

function cfg(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('config.php fehlt. Bitte config.example.php kopieren und ausfüllen (siehe LIESMICH.md).');
        }
        $config = require $file;
    }
    return $config[$key] ?? $default;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Rollen

const ROLLEN = [
    'mitglied'  => 'Mitglieder',
    'redaktion' => 'Redaktion',
    'vorstand'  => 'Vorstand',
];

function rolle(): ?string
{
    return $_SESSION['rolle'] ?? null;
}

// Vorstand sieht alles, Mitglieder und Redaktion sehen nur "öffentlich".
function sieht_alles(): bool
{
    return rolle() === 'vorstand';
}

function darf_einreichen(): bool
{
    return in_array(rolle(), ['redaktion', 'vorstand'], true);
}

function darf_freigeben(): bool
{
    return rolle() === 'vorstand';
}

// ---------------------------------------------------------------------------
// Wissensbasis

// Dateien, die nur der Vorstand sieht, und Dateien, die gar nicht angezeigt werden.
const NUR_VORSTAND = ['offene-punkte.md', 'quellen.md', 'frageliste-andere-mitglieder.md'];
const AUSGEBLENDET = ['README.md'];

function kb_dir(): string
{
    return rtrim((string)cfg('wissensbasis_dir', __DIR__ . '/data/wissensbasis'), '/');
}

function kb_dateien(): array
{
    $files = glob(kb_dir() . '/*.md') ?: [];
    $out = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (in_array($name, AUSGEBLENDET, true)) {
            continue;
        }
        $out[] = $name;
    }
    sort($out, SORT_NATURAL);
    return $out;
}

function slug_von_datei(string $datei): string
{
    return preg_replace('/\.md$/', '', $datei);
}

// Ein Eintrag ist öffentlich, wenn sein sicht-Feld "öffentlich" enthält und "intern" nicht.
// Gemischte Angaben wie "öffentlich (Ablauf) / intern (Vorbereitung)" gelten damit als intern.
function ist_oeffentlich(string $sicht): bool
{
    $s = mb_strtolower($sicht);
    return str_contains($s, 'öffentlich') && !str_contains($s, 'intern');
}

function parse_kopfzeile(string $zeile): ?array
{
    if (!preg_match('/^`(id:.*)`\s*$/u', trim($zeile), $m)) {
        return null;
    }
    $felder = [];
    foreach (preg_split('/\s+·\s+/u', $m[1]) as $teil) {
        if (preg_match('/^(\w+):\s*(.*)$/u', $teil, $mm)) {
            $felder[$mm[1]] = trim($mm[2]);
        }
    }
    return isset($felder['id']) ? $felder : null;
}

/**
 * Zerlegt eine Datei in Titel, Einleitung und eine Folge von Blöcken.
 * Block: ['typ' => 'abschnitt', 'titel' => ...] oder
 *        ['typ' => 'eintrag', 'id', 'titel', 'sicht', 'stand', 'quellen', 'text', 'abschnitt']
 *        ['typ' => 'frei', 'text'] (Text ohne Kopfzeile, nur Vorstand)
 */
function parse_datei(string $datei): array
{
    $pfad = kb_dir() . '/' . $datei;
    $zeilen = preg_split('/\r?\n/', (string)@file_get_contents($pfad));
    $titel = slug_von_datei($datei);
    $einleitung = [];
    $bloecke = [];
    $aktuell = null;
    $abschnitt = '';

    $abschliessen = function () use (&$aktuell, &$bloecke) {
        if ($aktuell !== null) {
            $aktuell['text'] = trim(implode("\n", $aktuell['text']));
            $bloecke[] = $aktuell;
            $aktuell = null;
        }
    };

    $n = count($zeilen);
    for ($i = 0; $i < $n; $i++) {
        $z = $zeilen[$i];
        if (preg_match('/^# (.+)$/u', $z, $m) && $titel === slug_von_datei($datei) && !$bloecke && $aktuell === null) {
            $titel = trim($m[1]);
            continue;
        }
        if (preg_match('/^## (.+)$/u', $z, $m)) {
            $abschliessen();
            $abschnitt = trim($m[1]);
            $bloecke[] = ['typ' => 'abschnitt', 'titel' => $abschnitt];
            continue;
        }
        if (preg_match('/^### (.+)$/u', $z, $m)) {
            $abschliessen();
            $kopf = ($i + 1 < $n) ? parse_kopfzeile($zeilen[$i + 1]) : null;
            if ($kopf) {
                $aktuell = [
                    'typ' => 'eintrag',
                    'id' => $kopf['id'],
                    'titel' => trim($m[1]),
                    'sicht' => $kopf['sicht'] ?? '',
                    'stand' => $kopf['stand'] ?? '',
                    'quellen' => $kopf['quellen'] ?? '',
                    'abschnitt' => $abschnitt,
                    'datei' => $datei,
                    'text' => [],
                ];
                $i++;
            } else {
                $aktuell = ['typ' => 'frei', 'text' => [$z]];
            }
            continue;
        }
        // Kopfzeile direkt unter einer ##-Überschrift: Eintrag trägt den Abschnittstitel
        $kopf = parse_kopfzeile($z);
        if ($kopf && ($aktuell === null || ($aktuell['typ'] === 'frei' && trim(implode('', $aktuell['text'])) === ''))) {
            $aktuell = [
                'typ' => 'eintrag',
                'id' => $kopf['id'],
                'titel' => $abschnitt !== '' ? $abschnitt : $kopf['id'],
                'sicht' => $kopf['sicht'] ?? '',
                'stand' => $kopf['stand'] ?? '',
                'quellen' => $kopf['quellen'] ?? '',
                'abschnitt' => $abschnitt,
                'datei' => $datei,
                'text' => [],
            ];
            continue;
        }
        if ($aktuell === null) {
            if (!$bloecke) {
                $einleitung[] = $z;
                continue;
            }
            $aktuell = ['typ' => 'frei', 'text' => []];
        }
        $aktuell['text'][] = $z;
    }
    $abschliessen();

    return ['datei' => $datei, 'titel' => $titel, 'einleitung' => trim(implode("\n", $einleitung)), 'bloecke' => $bloecke];
}

function datei_sichtbar(string $datei): bool
{
    return sieht_alles() || !in_array($datei, NUR_VORSTAND, true);
}

function block_sichtbar(array $b): bool
{
    if (sieht_alles()) {
        return true;
    }
    if ($b['typ'] === 'eintrag') {
        return ist_oeffentlich($b['sicht']);
    }
    return $b['typ'] === 'abschnitt';
}

/** Alle für die aktuelle Rolle sichtbaren Einträge, über alle Dateien. */
function sichtbare_eintraege(): array
{
    $alle = [];
    foreach (kb_dateien() as $datei) {
        if (!datei_sichtbar($datei)) {
            continue;
        }
        foreach (parse_datei($datei)['bloecke'] as $b) {
            if ($b['typ'] === 'eintrag' && block_sichtbar($b)) {
                $alle[] = $b;
            }
        }
    }
    return $alle;
}

function eintrag_link(array $e): string
{
    return '?seite=' . rawurlencode(slug_von_datei($e['datei'])) . '#' . rawurlencode($e['id']);
}

function suche(string $q): array
{
    $begriffe = array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))), fn($w) => mb_strlen($w) >= 2);
    if (!$begriffe) {
        return [];
    }
    $treffer = [];
    foreach (sichtbare_eintraege() as $e) {
        $hay = mb_strtolower($e['titel'] . "\n" . $e['text']);
        $punkte = 0;
        foreach ($begriffe as $w) {
            $c = mb_substr_count($hay, $w);
            if ($c === 0) {
                continue 2;
            }
            $punkte += $c + (str_contains(mb_strtolower($e['titel']), $w) ? 5 : 0);
        }
        $treffer[] = ['eintrag' => $e, 'punkte' => $punkte, 'auszug' => auszug($e['text'], reset($begriffe))];
    }
    usort($treffer, fn($a, $b) => $b['punkte'] <=> $a['punkte']);
    return $treffer;
}

function auszug(string $text, string $wort): string
{
    $plain = preg_replace('/[*`#>|]/u', '', $text);
    $pos = mb_stripos($plain, $wort);
    $start = max(0, ($pos === false ? 0 : $pos) - 80);
    $s = mb_substr($plain, $start, 220);
    return ($start > 0 ? '… ' : '') . trim(preg_replace('/\s+/u', ' ', $s)) . ' …';
}

// ---------------------------------------------------------------------------
// Markdown (bewusst kleine Teilmenge: Überschriften, Absätze, Listen, Aufgaben, Tabellen, Zitate)

function md_inline(string $s): string
{
    $s = h($s);
    $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<em>$1</em>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (preg_match('/^([\w.-]+)\.md(#.*)?$/u', $url, $mm)) {
            $url = '?seite=' . rawurlencode($mm[1]) . ($mm[2] ?? '');
        } elseif (!preg_match('#^(https?://|mailto:|\?|\#)#i', $url)) {
            return $m[1];
        }
        $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . h($url) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s);
    return $s;
}

function md(string $text): string
{
    $zeilen = preg_split('/\r?\n/', $text);
    $out = '';
    $absatz = [];
    $listen = []; // Stapel aus ['ul'|'ol', einrückung]
    $flush_absatz = function () use (&$absatz, &$out) {
        if ($absatz) {
            $out .= '<p>' . md_inline(implode(' ', $absatz)) . "</p>\n";
            $absatz = [];
        }
    };
    $schliesse_listen = function (int $bis = -1) use (&$listen, &$out) {
        while ($listen && end($listen)[1] > $bis) {
            $out .= '</li></' . array_pop($listen)[0] . ">\n";
        }
    };

    $n = count($zeilen);
    for ($i = 0; $i < $n; $i++) {
        $z = rtrim($zeilen[$i]);
        if (trim($z) === '') {
            $flush_absatz();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/u', $z, $m)) {
            $flush_absatz();
            $schliesse_listen();
            $lvl = min(6, strlen($m[1]) + 1);
            $out .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        if (preg_match('/^\s*(---|\*\*\*)\s*$/', $z)) {
            $flush_absatz();
            $schliesse_listen();
            $out .= "<hr>\n";
            continue;
        }
        if (str_starts_with(ltrim($z), '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{2,}/', $zeilen[$i + 1])) {
            $flush_absatz();
            $schliesse_listen();
            $zellen = fn($l) => array_map('trim', explode('|', trim(trim($l), '|')));
            $out .= "<div class=\"tabelle\"><table><thead><tr>";
            foreach ($zellen($z) as $c) {
                $out .= '<th>' . md_inline($c) . '</th>';
            }
            $out .= "</tr></thead><tbody>\n";
            $i += 2;
            while ($i < $n && str_starts_with(ltrim($zeilen[$i]), '|')) {
                $out .= '<tr>';
                foreach ($zellen($zeilen[$i]) as $c) {
                    $out .= '<td>' . md_inline($c) . '</td>';
                }
                $out .= "</tr>\n";
                $i++;
            }
            $i--;
            $out .= "</tbody></table></div>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/u', $z, $m)) {
            $flush_absatz();
            $schliesse_listen();
            $out .= '<blockquote>' . md_inline($m[1]) . "</blockquote>\n";
            continue;
        }
        if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/u', $z, $m)) {
            $flush_absatz();
            $einr = strlen(str_replace("\t", '    ', $m[1]));
            $typ = ctype_digit($m[2][0]) ? 'ol' : 'ul';
            $inhalt = $m[3];
            if (preg_match('/^\[( |x|X)\]\s+(.*)$/u', $inhalt, $mm)) {
                $inhalt = '<input type="checkbox" disabled' . (trim($mm[1]) !== '' ? ' checked' : '') . '> ' . md_inline($mm[2]);
            } else {
                $inhalt = md_inline($inhalt);
            }
            if (!$listen || $einr > end($listen)[1]) {
                $out .= "<$typ>\n<li>";
                $listen[] = [$typ, $einr];
            } else {
                $schliesse_listen($einr);
                if (!$listen) {
                    $out .= "<$typ>\n<li>";
                    $listen[] = [$typ, $einr];
                } else {
                    $out .= "</li>\n<li>";
                }
            }
            $out .= $inhalt;
            continue;
        }
        if ($listen && preg_match('/^\s{2,}\S/', $z)) {
            $out .= ' ' . md_inline(trim($z));
            continue;
        }
        $schliesse_listen();
        $absatz[] = trim($z);
    }
    $flush_absatz();
    $schliesse_listen();
    return $out;
}

// ---------------------------------------------------------------------------
// Mistral-API

function ki_verfuegbar(): bool
{
    return (string)cfg('mistral_api_key', '') !== '';
}

/** Ruft die Chat-Completions-Schnittstelle auf. Gibt den Antworttext zurück oder wirft eine Exception. */
function mistral(array $messages, bool $json = false, int $max_tokens = 900): string
{
    $body = [
        'model' => cfg('mistral_model', 'mistral-small-latest'),
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => $max_tokens,
    ];
    if ($json) {
        $body['response_format'] = ['type' => 'json_object'];
    }
    $ch = curl_init(rtrim((string)cfg('mistral_base_url', 'https://api.eu.mistral.ai'), '/') . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . cfg('mistral_api_key'),
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $antwort = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);
    if ($antwort === false) {
        throw new RuntimeException('Verbindung zu Mistral fehlgeschlagen: ' . $fehler);
    }
    if ($status === 429) {
        throw new RuntimeException('Mistral meldet: Limit erreicht (zu viele Anfragen oder Monatsbudget aufgebraucht).');
    }
    if ($status >= 400) {
        throw new RuntimeException('Mistral meldet Fehler ' . $status . '.');
    }
    $daten = json_decode((string)$antwort, true);
    $text = $daten['choices'][0]['message']['content'] ?? null;
    if (!is_string($text)) {
        throw new RuntimeException('Unerwartete Antwort von Mistral.');
    }
    return $text;
}

function kontext_text(array $eintraege): string
{
    $teile = [];
    foreach ($eintraege as $e) {
        $teile[] = "[{$e['id']}] {$e['titel']}\n(Stand: {$e['stand']}; Quellen: {$e['quellen']})\n{$e['text']}";
    }
    return implode("\n\n---\n\n", $teile);
}

function chat_antwort(string $frage, array $verlauf): string
{
    $system = "Du bist das digitale Handbuch des Rotary Clubs Stuttgart-Remstal.\n"
        . "Regeln:\n"
        . "1. Antworte ausschließlich auf Grundlage der Einträge unten. Nutze kein Allgemeinwissen.\n"
        . "2. Nenne nach jeder Aussage in eckigen Klammern die id des Eintrags, aus dem sie stammt, z. B. [kal-02].\n"
        . "3. Steht die Antwort nicht in den Einträgen, sage: \"Dazu steht nichts im Handbuch.\" und rate nicht.\n"
        . "4. Ist ein Eintrag als 'teilweise' oder 'offen' markiert, weise darauf hin, dass er unvollständig ist.\n"
        . "5. Nenne keine Personennamen, nur Rollen. Ausnahme: Namen, die wörtlich in den Einträgen stehen.\n"
        . "6. Antworte auf Deutsch, knapp und sachlich.\n\n"
        . "EINTRÄGE:\n\n" . kontext_text(sichtbare_eintraege());
    $messages = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($verlauf, -6) as $m) {
        $messages[] = ['role' => $m['rolle'], 'content' => $m['text']];
    }
    $messages[] = ['role' => 'user', 'content' => $frage];
    return mistral($messages);
}

/** Verlinkt [id]-Verweise in einer Chat-Antwort auf die passenden Einträge. */
function verweise_verlinken(string $html): string
{
    $index = [];
    foreach (sichtbare_eintraege() as $e) {
        $index[$e['id']] = $e;
    }
    return preg_replace_callback('/\[([a-z]+-\d+[a-z]?)\]/u', function ($m) use ($index) {
        if (!isset($index[$m[1]])) {
            return $m[0];
        }
        return '<a class="verweis" href="' . h(eintrag_link($index[$m[1]])) . '" title="' . h($index[$m[1]]['titel']) . '">' . h($m[1]) . '</a>';
    }, $html);
}

function entwurf_formulieren(string $rohtext, string $datei): array
{
    $system = "Du hilfst, Einträge für das Handbuch des Rotary Clubs Stuttgart-Remstal zu schreiben.\n"
        . "Forme die Eingabe in einen sachlichen Handbucheintrag um.\n"
        . "Regeln:\n"
        . "- Entferne ALLE Personennamen und ersetze sie durch Rollen (z. B. 'der Präsident', 'der Clubmeister', 'ein Mitglied').\n"
        . "- Keine Kontaktdaten (Telefon, E-Mail, Adressen von Privatpersonen).\n"
        . "- Erfinde nichts hinzu. Was unklar ist, nenne als offene Frage.\n"
        . "- Beträge nur als Beispiel kennzeichnen, sie ändern sich jährlich.\n"
        . "- Schreibe in Markdown (Absätze, Aufzählungen mit '- '), ohne Überschrift.\n"
        . "Antworte NUR mit JSON: {\"titel\": \"...\", \"text\": \"...\", \"sicht\": \"öffentlich\" oder \"intern\", \"hinweise\": \"was der Vorstand prüfen sollte\"}.\n"
        . "sicht: 'intern' für Finanzen, Vorstandsabläufe und Personalangelegenheiten, sonst 'öffentlich'.\n"
        . "Der Eintrag ist für den Baustein: " . parse_datei($datei)['titel'];
    $roh = mistral([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $rohtext],
    ], true, 1200);
    $d = json_decode($roh, true);
    if (!is_array($d) || !isset($d['titel'], $d['text'])) {
        throw new RuntimeException('Der Entwurf konnte nicht gelesen werden. Bitte erneut versuchen.');
    }
    return [
        'titel' => trim((string)$d['titel']),
        'text' => trim((string)$d['text']),
        'sicht' => ($d['sicht'] ?? '') === 'intern' ? 'intern' : 'öffentlich',
        'hinweise' => trim((string)($d['hinweise'] ?? '')),
    ];
}

// ---------------------------------------------------------------------------
// Entwürfe und Freigabe

function entwurf_dir(): string
{
    $d = __DIR__ . '/data/entwuerfe';
    if (!is_dir($d)) {
        mkdir($d, 0770, true);
    }
    return $d;
}

function entwuerfe(): array
{
    $liste = [];
    foreach (glob(entwurf_dir() . '/*.json') ?: [] as $f) {
        $e = json_decode((string)file_get_contents($f), true);
        if (is_array($e)) {
            $e['key'] = basename($f, '.json');
            $liste[] = $e;
        }
    }
    usort($liste, fn($a, $b) => strcmp($a['erstellt'], $b['erstellt']));
    return $liste;
}

function entwurf_speichern(array $e): void
{
    $key = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    file_put_contents(entwurf_dir() . "/$key.json", json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function entwurf_loeschen(string $key): void
{
    if (preg_match('/^[\w-]+$/', $key)) {
        @unlink(entwurf_dir() . "/$key.json");
    }
}

/** Nächste freie id im gewählten Abschnitt, nach dem Muster der vorhandenen ids (z. B. kal-19). */
function naechste_id(array $datei, string $abschnitt): string
{
    $praefix = null;
    $max = [];
    foreach ($datei['bloecke'] as $b) {
        if ($b['typ'] !== 'eintrag' || !preg_match('/^([a-z]+)-(\d+)/', $b['id'], $m)) {
            continue;
        }
        $max[$m[1]] = max($max[$m[1]] ?? 0, (int)$m[2]);
        if ($b['abschnitt'] === $abschnitt || $praefix === null) {
            $praefix = ($b['abschnitt'] === $abschnitt) ? $m[1] : ($praefix ?? $m[1]);
        }
    }
    $praefix ??= substr(preg_replace('/^\d+-/', '', slug_von_datei($datei['datei'])), 0, 3);
    return sprintf('%s-%02d', $praefix, ($max[$praefix] ?? 0) + 1);
}

/** Fügt einen freigegebenen Eintrag am Ende des gewählten Abschnitts ein; legt vorher eine Sicherung an. */
function eintrag_einfuegen(string $dateiname, string $abschnitt, array $e): string
{
    if (!in_array($dateiname, kb_dateien(), true)) {
        throw new RuntimeException('Unbekannte Datei.');
    }
    $pfad = kb_dir() . '/' . $dateiname;
    $datei = parse_datei($dateiname);
    $id = naechste_id($datei, $abschnitt);

    $archiv = __DIR__ . '/data/archiv';
    if (!is_dir($archiv)) {
        mkdir($archiv, 0770, true);
    }
    copy($pfad, $archiv . '/' . slug_von_datei($dateiname) . '.' . date('Ymd-His') . '.md');

    $block = "### " . str_replace("\n", ' ', $e['titel']) . "\n"
        . "`id: $id · sicht: {$e['sicht']} · stand: {$e['stand']} · quellen: " . str_replace(['`', "\n"], '', $e['quellen']) . "`\n\n"
        . trim($e['text']) . "\n";

    $zeilen = preg_split('/\r?\n/', (string)file_get_contents($pfad));
    $ziel = count($zeilen);
    $im_abschnitt = ($abschnitt === '');
    foreach ($zeilen as $i => $z) {
        if (preg_match('/^## (.+)$/u', $z, $m)) {
            if ($im_abschnitt) {
                $ziel = $i;
                break;
            }
            $im_abschnitt = (trim($m[1]) === $abschnitt);
        }
    }
    // Leerzeilen vor dem Einfügepunkt zusammenfassen
    $vorher = array_slice($zeilen, 0, $ziel);
    while ($vorher && trim(end($vorher)) === '') {
        array_pop($vorher);
    }
    $nachher = array_slice($zeilen, $ziel);
    $neu = implode("\n", $vorher) . "\n\n" . $block . ($nachher ? "\n" . implode("\n", $nachher) : '');
    file_put_contents($pfad, rtrim($neu) . "\n", LOCK_EX);
    return $id;
}
