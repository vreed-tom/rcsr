# Handbuch RC Stuttgart-Remstal – Web-Anwendung

Kleine PHP-Anwendung ohne Datenbank und ohne Bauschritt. Sie liest die Markdown-Dateien der Wissensbasis direkt und bietet:

- **Nachschlagewerk** je Baustein mit Volltextsuche
- **Zwei Sichten:** Mitglieder und Redaktion sehen nur Einträge mit `sicht: öffentlich`, der Vorstand sieht alles
- **Chatbot** (Mistral-API), der nur aus den sichtbaren Einträgen antwortet und die Eintrags-id nennt
- **Redaktion:** Text eingeben, der Chatbot formuliert einen Entwurf ohne Personennamen, Einreichen zur Freigabe
- **Freigabe** durch den Vorstand: Entwurf prüfen, einordnen, freigeben oder verwerfen

## Dateien

| Pfad | Inhalt |
|---|---|
| `index.php` | Seiten und Abläufe |
| `lib.php` | Wissensbasis lesen, Markdown anzeigen, Mistral-Aufruf, Entwürfe |
| `assets/style.css` | Gestaltung |
| `config.example.php` | Vorlage für `config.php` (Passwörter, API-Schlüssel) |
| `data/wissensbasis/` | die Markdown-Dateien (hierher hochladen) |
| `data/entwuerfe/` | eingereichte Entwürfe (entsteht automatisch) |
| `data/archiv/` | Sicherung jeder Datei vor einer Freigabe (entsteht automatisch) |
| `werkzeuge/passwort-hash.php` | erzeugt Passwort-Hashes |

`.htaccess`-Dateien sperren `config.php`, `lib.php`, `data/` und `werkzeuge/` gegen Abruf aus dem Netz und erzwingen HTTPS.

## Regeln, die die Anwendung umsetzt

- Ein Eintrag gilt nur dann als öffentlich, wenn sein sicht-Feld „öffentlich“ enthält und „intern“ nicht. Gemischte Angaben wie `öffentlich (Ablauf) / intern (Vorbereitung)` bleiben Mitgliedern verborgen; die Startseite zeigt dem Vorstand diese Einträge, damit sie aufgeteilt werden können.
- Die Einleitungstexte der Bausteine und die Dateien `offene-punkte.md`, `quellen.md`, `frageliste-andere-mitglieder.md` sieht nur der Vorstand. `README.md` wird nicht angezeigt.
- Der Rohtext der Redaktion wird nicht gespeichert, nur der bearbeitete Entwurf.
- Chatverläufe liegen nur in der laufenden Sitzung und verschwinden beim Abmelden.
- Neue Einträge bekommen die nächste freie id nach dem Muster des Abschnitts (z. B. `tra-08`) und als Quelle „Redaktion TT.MM.JJJJ“.

## Einrichtung (all-inkl, Tarif „Privat Plus“)

1. Tarif buchen, Domain oder Subdomain anlegen, Let's-Encrypt-Zertifikat aktivieren.
2. PHP-Version im KAS auf eine aktuelle 8.x stellen.
3. Alle Dateien per FTP hochladen, die Markdown-Dateien nach `data/wissensbasis/`.
4. `config.example.php` als `config.php` kopieren. Drei Passwörter festlegen und mit `php werkzeuge/passwort-hash.php "Passwort"` Hashes erzeugen (auf einem Rechner mit PHP), in `config.php` eintragen.
5. Mistral: Konto mit Zahlungsmittel anlegen, **Training mit eigenen Daten abwählen**, **monatliches Ausgabenlimit setzen** (Admin Panel › Subscriptions › Billing), API-Schlüssel erzeugen und in `config.php` eintragen.
6. Prüfen, ob das Modell am EU-Endpunkt verfügbar ist. Wenn nicht, `mistral_base_url` oder `mistral_model` anpassen.
7. Testen: anmelden mit allen drei Passwörtern, eine Frage stellen, einen Entwurf einreichen und freigeben.

## Pflege

- Passwörter bei der Ämterübergabe (1. Juli) wechseln.
- `data/archiv/` gelegentlich herunterladen und aufräumen.
- Bei einer PHP-Umstellung durch den Hoster kurz alle Seiten testen.

## Noch nicht geprüft

- Lokal getestet mit PHP 8.3 und einem Platzhalter statt der echten Mistral-API. Die echte Anbindung und die Formulierqualität des Chatbots sind erst mit einem Mistral-Konto prüfbar.
- Ob all-inkl ausgehende HTTPS-Aufrufe aus PHP erlaubt, ist erst auf dem gebuchten Paket prüfbar (erster Monat kostenlos).
