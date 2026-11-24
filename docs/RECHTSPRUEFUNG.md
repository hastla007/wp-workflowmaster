# Mappe für die Rechtsprüfung – Workflow Captain

Stand: 24.11.2026. Diese Mappe bündelt alle Punkte, die in diesem Repository auf eine rechtliche Prüfung warten (Stichworte „Rechtsprüfung“, „ungeprüft“, „Rechtsgrundlage“ in [`../TODO.md`](../TODO.md), [`OPTIONS.md`](OPTIONS.md), [`SPEC-suite-events.md`](SPEC-suite-events.md), [`LOESCHUNG.md`](LOESCHUNG.md), [`ENTSCHEIDUNGSVORLAGE.md`](ENTSCHEIDUNGSVORLAGE.md), [`SPEC-open-questions.md`](SPEC-open-questions.md), `readme.txt` und dem Code).

**Was diese Mappe ist:** eine Beschreibung des heutigen technischen Stands mit Fragen und Optionen. Sie enthält keine rechtliche Bewertung und keine Empfehlung, welche Lösung rechtlich zulässig ist. Wo das Repository selbst unklar oder widersprüchlich ist, steht das als **offene Frage** dabei.

**Zielregionen:** Deutschland (DE), Österreich (AT), Schweiz (CH), Europäische Union allgemein (EU). Ein fünftes Profil „Andere Länder“ (OTHER) enthält neutrale Werte und ist ausdrücklich „nicht geprüft“; es ist nicht Gegenstand dieser Prüfung.

**So antworten:** je Prüfpunkt die Kennung (z. B. „RP-05“) und die Antwort bzw. die gelieferten Texte. Die Entwicklung trägt sie dann an den jeweils genannten Stellen ein.

---

## 1. Kurzüberblick

### 1.1 Was das Produkt tut

Workflow Captain ist eine Erweiterung (Plugin) für WordPress-Websites. Der Betreiber einer Website richtet damit Abläufe ein („Workflows“): *Wenn* etwas passiert (z. B. eine Bestellung wird bezahlt, ein Gutschein wird eingelöst, ein Ticket wird beim Einlass gescannt), *dann* passiert automatisch etwas anderes (z. B. eine E-Mail an den Kunden, eine Markierung, ein Hinweis an den Betreiber).

Es gibt zwei Teile:

- **Kostenloser Kern** (dieses Repository): Abläufe, E-Mails an Kunden und Betreiber über die normale Mailfunktion von WordPress. Der Kern ruft selbst **keine** externen Dienste auf.
- **Workflow Captain Pro** (kostenpflichtiges Zusatzmodul, anderes Repository): verbindet zusätzlich die anderen Produkte der „Captain“-Familie (Tickets, Gutscheine, Partnerprogramm, Bewertungen, Formulare) und kann Daten an externe Ziele senden (Webhooks, n8n). Das geschieht nur, wenn der Betreiber es einrichtet.

Rollen: Die **Inhaberin** ist die Herstellerin der Software. Der **Betreiber** installiert sie auf seiner Website und ist für die Inhalte seiner Abläufe verantwortlich (Spezifikation R-12.8). **Kunden** sind die Personen, über die Ereignisse entstehen und an die Nachrichten gehen.

### 1.2 Grundsätze, die heute schon gebaut sind

- Gespeicherte Ereignisse enthalten Kennnummern, Status, Beträge und Zeiten – **nie** E-Mail-Adresse, Name, Anschrift, Telefonnummer, Bankdaten, Freitext, IP-Adresse oder geheime Codes (Gutschein-, Ticket-, Lizenzcodes). Ereignisse mit solchen Feldern werden abgewiesen (Spezifikation Abschnitt 5).
- Personenbezogen ist die **Benutzer-ID** (Kundennummer des WordPress-Kontos) derjenigen Person, die ein Ereignis ausgelöst hat (Spezifikation R-12.1).
- Braucht ein Ablauf eine E-Mail-Adresse (für eine Nachricht), wird sie erst beim Versand nachgeschlagen und **nicht gespeichert** (R-5.6).
- Workflow Captain gibt auf öffentlichen Seiten nichts aus – mit **einer** Ausnahme: der Abmeldeseite für Werbenachrichten (Entscheidung S11, siehe RP-05).
- Die Mails der anderen Produkte (z. B. die Gutschein-Mail an die beschenkte Person) bleiben unverändert in deren Verantwortung; ein Ablauf darf sie weder unterdrücken noch ändern, nur **zusätzliche** Nachrichten senden (Spezifikation R-9.1, R-9.2).

### 1.3 Welche Daten wohin fließen

| Datenart | Zweck | Speicherort | Frist heute | Empfänger / Drittland |
|---|---|---|---|---|
| Ereignis (Art, Kennnummer des Gegenstands, Status, Betrag, Zeit) mit Benutzer-ID des Auslösers | Abläufe ausführen, Fehler wiederholen, Abläufe nachvollziehbar machen (R-12.2) | Datenbank der Website (eigene Tabelle) | erledigt: 30 Tage; fehlgeschlagen/pausiert: 90 Tage; Betreiber kann 1–180 Tage einstellen, darüber nur mit Pro. Die Benutzer-ID wird geleert, sobald kein Ablauf mehr darauf wartet | keine; bleibt auf der Website |
| Ablauf („Lauf“) mit Kopie des Ereignisses und Schrittprotokoll | wie oben | Datenbank der Website | 30 Tage nach Ende (1–180 einstellbar); noch offene Abläufe bleiben bis zum Ende | keine |
| E-Mail-Adresse des Kunden | Versand einer Nachricht aus einem Ablauf | **nicht gespeichert**; beim Versand aus dem Benutzerkonto gelesen | – | Mailversand über WordPress bzw. das Mail-Plugin des Betreibers (welcher Mailanbieter, entscheidet der Betreiber) |
| Inhalt der Nachricht an Kunden (Betreff, Text, ggf. Fußzeile, bei Werbung Abmeldezeile) | Transaktions- oder Werbenachricht | nicht gespeichert (im Protokoll nur Ergebnis, z. B. „gesendet“ oder „übersprungen: keine Einwilligung“) | – | Kunde; Mailanbieter des Betreibers |
| Abmeldevermerk (Zeitpunkt der Abmeldung von Werbung) | keine Werbung mehr an diese Person | Benutzerkonto in WordPress (je Website) | unbegrenzt; bleibt auch bei Deinstallation, außer der Betreiber wählt „alle Daten entfernen“ | keine |
| Tageszähler (Kalendertag, Benutzer-ID, Zahl der Nachrichten) | Höchstzahl Nachrichten je Kunde und Tag | Datenbank der Website | 2 Tage; bei Löschanfrage oder gelöschtem Konto sofort | keine |
| Schlagwörter und Notizen an Gegenständen (vom Betreiber im Ablauf festgelegter Text) | Markieren, Merken | Datenbank der Website | **keine Frist** (bis zur Löschung) – siehe RP-09 | keine |
| Empfänger der täglichen Hinweis-Mail (bis zu zehn Adressen, meist Mitarbeitende des Betreibers) | Betriebshinweise an den Betreiber | Einstellungen der Website | bis zur Änderung; in der Sicherung für „Rückgängig“ 30 Tage | Empfänger selbst |
| Änderungsprotokoll (Zeit, Benutzer-ID der ändernden Person, Art der Änderung) | Nachvollziehbarkeit sicherheitsrelevanter Einstellungen | Einstellungen der Website | die letzten 50 Einträge (10–500 einstellbar) | keine |
| Ausgehende Webhooks (nur Pro) | Weitergabe von Ereignissen an Systeme, die der Betreiber angibt | Ausgangswarteschlange in der Datenbank der Website | gesendet: 7 Tage; fehlgeschlagen: 30 Tage ([`LOESCHUNG.md`](LOESCHUNG.md)) | **beliebiges Ziel, das der Betreiber einträgt**; Land hängt vom Ziel ab |
| Verbindung zu n8n (nur Pro) | Weitergabe an das Automatisierungswerkzeug n8n | wie Webhooks | wie Webhooks | n8n Cloud (Anbieter des Dienstes) oder selbst betriebenes n8n; **Standort/Drittland von n8n Cloud ist im Repository nicht beschrieben** (offene Frage, RP-03) |
| Bewertungseinladung (nur Pro mit Review Captain Pro) | Einladung zu einer Bewertung nach Bestellung oder Gutscheineinlösung | Workflow übergibt nur die Kennnummer (Bestellung oder Gutschein); Kontakt, Einwilligung und Versand liegen bei Review Captain | Regeln von Review Captain (anderes Repository) | Kunde; ggf. Bewertungsplattform (Sache von Review Captain) |
| Lizenzereignisse | nur im Shop der Herstellerin selbst | Datenbank des Shops der Herstellerin | wie Ereignisse | Herstellerin ist selbst verantwortlich (R-12.7) |

Nachweise: [`OPTIONS.md`](OPTIONS.md) (Tabellen, Fristen), `readme.txt` Abschnitt „Privacy“, Spezifikation Abschnitte 5, 9 und 12, [`LOESCHUNG.md`](LOESCHUNG.md).

### 1.4 Auskunft und Löschung heute

Workflow Captain meldet sich bei den Datenschutz-Werkzeugen von WordPress an („Persönliche Daten exportieren“ / „Persönliche Daten löschen“):

- **Auskunft** nennt die gespeicherten Ereignisse und Abläufe einer Person sowie ihre Adresse, wenn sie Empfänger der Hinweis-Mail ist.
- **Löschung** anonymisiert die Benutzer-ID in Ereignissen und Abläufen, löscht die Tageszähler und entfernt die Adresse aus den Hinweis-Empfängern und der Sicherung. Geschäftsdaten der anderen Produkte (Bestellungen, Rechnungen, Gutscheine, Provisionen) löscht Workflow Captain nie; sie unterliegen den Regeln des jeweiligen Produkts.
- Beim Löschen eines Benutzerkontos und beim Löschen eines Partners im Partnerprogramm wird ebenfalls anonymisiert.

---

## 2. Prüfpunkte

Übersicht:

| Nr. | Kennung im Repository | Thema |
|---|---|---|
| RP-01 | TODO „Datenschutz-/Rechtsprüfung der E-Mail-, Review- und Webhook-Datenflüsse“, Teil E-Mail | Nachrichten aus Abläufen an Kunden |
| RP-02 | dieselbe TODO, Teil Review; R-4.30a; F-23 | Bewertungseinladungen |
| RP-03 | dieselbe TODO, Teil Webhook; WF-N8N-12 | Webhooks und n8n |
| RP-04 | SET-WC-03; R-9.5 bis R-9.7; E-S2 | Werbung oder Transaktion, Einwilligung |
| RP-05 | SET-WC-04; R-9.8; S11 | Abmeldung von Werbung |
| RP-06 | SET-WC-10 | Fußzeile (Impressum, Datenschutz) |
| RP-07 | SET-WC-17; SET-WC-P3; S8 | Länderprofile DE, AT, CH, EU |
| RP-08 | WF-SPEC-11; F-04; R-12.4 | Aufbewahrungsfristen |
| RP-09 | SET-WC-34 | Schlagwörter, Notizen, Baustein für die Datenschutzerklärung |
| RP-10 | SET-WC-32; R-9.10 | Preisangaben in Nachrichten |
| RP-11 | F-13 | Kontakt und Einwilligung bei Empfängern ohne Benutzerkonto |

### RP-01 · Nachrichten aus Abläufen an Kunden (E-Mail-Datenfluss)

**Frage an die Prüferin:** Welche Anforderungen gelten in DE, AT, CH und der EU für zusätzliche E-Mails, die ein Betreiber mit Workflow Captain automatisch an Kunden sendet, und deckt der heutige Ablauf sie ab?

**Heutiger Stand:**
- Die Aktion „Nachricht an Benutzer“ sendet eine reine Text-Mail an die E-Mail-Adresse des WordPress-Kontos. Die Adresse wird erst beim Versand gelesen und nicht gespeichert.
- Jede Nachricht ist entweder **Transaktion** (Vertragsabwicklung) oder **Werbung**; ohne Angabe gilt sie als Transaktion (RP-04).
- Der Betreiber kann Absendername, Absenderadresse, Antwortadresse und eine Fußzeile festlegen (RP-06).
- Ruhezeit (z. B. 20–8 Uhr), Sendetage und eine Höchstzahl je Kunde und Tag sind einstellbar, ab Werk **aus**. Eine Nachricht außerhalb des Fensters wartet bis zum nächsten erlaubten Zeitpunkt; über der Tagesgrenze wartet sie bis zum nächsten Tag oder entfällt (Wahl des Betreibers).
- Ein Testmodus leitet alle Nachrichten an eine Testadresse um.
- Die Spezifikation hält fest: Eine zusätzliche Nachricht braucht dieselbe rechtliche Grundlage wie jede andere Mail des Betreibers; das regelt die Software nicht (R-9.5). Für den Inhalt seiner Abläufe ist der Betreiber verantwortlich (R-12.8).

**Optionen / Vorschlag aus dem Repository:** keiner über den heutigen Stand hinaus; die Spezifikation verweist ausdrücklich auf diese Prüfung.

**Zu liefern:** Einschätzung, ob die Einteilung Transaktion/Werbung und die eingebauten Sperren genügen oder ob weitere Pflichtangaben je Nachricht nötig sind (z. B. in der Fußzeile, RP-06); ggf. Hinweistext für Betreiber im Handbuch.

**Wird eingetragen in:** Spezifikation Abschnitt 9, Hilfetexte der Einstellungsseite „Nachrichten“, `readme.txt`.

### RP-02 · Bewertungseinladungen

**Frage an die Prüferin:** Ist die vorgesehene Aufteilung der Verantwortung für Einwilligung und Widerruf bei Bewertungseinladungen aus Abläufen – je nach Quelle beim Shop (Bestellung) oder beim Gutscheinprodukt (Gutschein) – aus Sicht von DE, AT, CH und EU tragfähig, und welche Rechtsgrundlagen kommen in Betracht?

**Heutiger Stand:**
- Ein Ablauf darf eine Bewertungseinladung planen oder abbrechen (Entscheidung F-23, 23.11.2026). Den Versand, die Einwilligungsprüfung, Abmeldung, Sperrliste und Häufigkeitsgrenze übernimmt das Bewertungsprodukt (Review Captain Pro), nicht Workflow Captain.
- Seit 24.11.2026 auch für Gutscheine (R-4.30a): Der Ablauf übergibt nur die Gutschein-Kennnummer; eingeladen wird nur, wenn eine Einlösung tatsächlich stattgefunden hat. Eine bloße Gutscheinausstellung oder Bezahlung löst keine Einladung aus (Grundsatz in `konzept.md`).
- Für Einladungen aus Abläufen gilt dieselbe Einwilligung wie für Einladungen, die das Bewertungsprodukt selbst verschickt („erteilt oder bestätigte Rechtsgrundlage“).
- Workflow Captain speichert keine Kontaktdaten und keine Einwilligung.

**Optionen:** Die Spezifikation legt die Aufteilung fest; offen ist nur die rechtliche Einordnung. Was „bestätigte Rechtsgrundlage“ konkret bedeutet, ist im Repository nicht beschrieben (**offene Frage**, betrifft das Bewertungsprodukt).

**Zu liefern:** Einordnung der Rechtsgrundlage(n) für Bewertungseinladungen nach Bestellung und nach Gutscheineinlösung; ggf. Anforderungen an den Einwilligungstext (der Text selbst gehört zu Review Captain bzw. Voucher Captain).

**Wird eingetragen in:** Spezifikation R-4.30a; Anforderungen an die anderen Repositories ([`ANFORDERUNGEN-andere-repos.md`](ANFORDERUNGEN-andere-repos.md)).

### RP-03 · Webhooks und n8n

**Frage an die Prüferin:** Was muss ein Betreiber beachten bzw. was muss die Software bereitstellen, wenn Ereignisse an externe Ziele (eigene Webhook-Adressen, n8n Cloud oder selbst betriebenes n8n) übermittelt werden, insbesondere bei Zielen außerhalb der EU bzw. der Schweiz?

**Heutiger Stand:**
- Nur mit Workflow Captain Pro und nur nach Einrichtung durch den Betreiber. Der kostenlose Kern sendet nichts nach außen (`readme.txt`: „External services: None“).
- Übermittelt wird das Ereignis mit Feldfilter je Verbindung: keine Codes, keine E-Mail-Adresse, kein Name, kein Freitext. Braucht ein n8n-Schritt Kontaktdaten, soll er sie „mit eigener Rechtsgrundlage“ aus dem führenden System holen ([`n8n/README.md`](n8n/README.md)).
- Die Ausgangswarteschlange hält gesendete Ereignisse 7 Tage, fehlgeschlagene 30 Tage.
- Die Sicherheitsprüfungen (Signatur, Wiederholungsschutz) gegen eine echte n8n-Instanz sind **noch nicht** durchgeführt ([`n8n/MATRIX.md`](n8n/MATRIX.md), „ungeprüft“).

**Offene Fragen aus dem Repository:**
- **Benutzer-ID in Webhooks:** [`LOESCHUNG.md`](LOESCHUNG.md) beschreibt die Ausgangswarteschlange als „ohne Personendaten“. Die Spezifikation stuft die Benutzer-ID aber als personenbezogen ein (R-12.1), und Ereignisse können Benutzer-IDs enthalten (z. B. `customer_user_id`). Ob die Benutzer-ID an Webhook-Ziele geht, ist im Repository nicht eindeutig (die Umsetzung liegt im Pro-Repository).
- **Standort von n8n Cloud** und eine etwaige Auftragsverarbeitung sind im Repository nicht beschrieben.

**Zu liefern:** Hinweise, welche Angaben Betreiber vor dem Einrichten einer Verbindung brauchen (z. B. Hinweis im Einrichtungsdialog, Textbaustein für die Datenschutzerklärung des Betreibers, RP-09).

**Wird eingetragen in:** Hilfetexte der Verbindungsseite (Pro), [`n8n/README.md`](n8n/README.md), Baustein für die Datenschutzerklärung.

### RP-04 · Werbung oder Transaktion, Einwilligung (SET-WC-03)

**Frage an die Prüferin:** Sind die Einteilung „Transaktion / Werbung“, die Vorgabe „ohne Angabe = Transaktion“ und die Regel „Werbung nur mit ausdrücklich bestätigter Einwilligung“ so tragfähig, und welcher Hinweistext soll im Editor bei der Wahl „Werbung“ stehen?

**Heutiger Stand:**
- Feld „Art der Nachricht“: Transaktion oder „Werbung (nur mit Einwilligung, mit Abmelde-Link)“.
- Fehlt die Angabe, gilt die Nachricht als Transaktion; das betrifft auch alle schon veröffentlichten Abläufe (Entscheidung S11: bestehende Abläufe gelten als Transaktion).
- Workflow Captain **speichert keine Einwilligung**. Ein anderes Plugin muss sie auf Nachfrage ausdrücklich bestätigen; ohne Antwort gilt „keine Einwilligung“. Ohne ein solches Plugin geht also **keine** Werbung hinaus.
- Ohne Einwilligung oder nach Abmeldung läuft der Schritt durch und sendet nichts; im Protokoll steht „übersprungen: keine Einwilligung“ bzw. „abgemeldet“.
- Die Einteilung trifft der Betreiber selbst je Nachricht; die Software prüft den Inhalt nicht.

**Optionen:** (a) so belassen; (b) Hinweistext im Editor, der die Einteilung erklärt; (c) andere Vorgabe für neue Nachrichten (für bestehende Abläufe nach S11 nicht vorgesehen).

**Zu liefern:** Hinweistext für den Editor zur Wahl „Werbung“ / „Transaktion“ (kurz, für Betreiber); ggf. Einwilligungshinweis für Betreiber (welche Art Einwilligung das bestätigende Plugin abfragen sollte).

**Wird eingetragen in:** Beschriftung und Hilfetext der Aktion „Nachricht an Benutzer“, Spezifikation R-9.6/R-9.7. Die Aufgabe SET-WC-03 bleibt bis dahin offen.

### RP-05 · Abmeldung von Werbung (SET-WC-04)

**Frage an die Prüferin:** Welcher Wortlaut ist für die Abmeldezeile in der Mail und für die beiden Abmeldeseiten angemessen, auf welche Rechtsgrundlage stützt sich das Speichern des Abmeldevermerks, wie lange darf bzw. muss er aufbewahrt werden, und muss er in der Auskunft erscheinen?

**Heutiger Stand:**
- Jede Werbenachricht trägt am Ende eine Abmeldezeile mit persönlichem, signiertem Link und zusätzlich die technischen Kopfzeilen für „Abmelden mit einem Klick“ in Mailprogrammen.
- Der Link führt zu der **einzigen öffentlichen Adresse** des Plugins (Entscheidung S11). Öffnen zeigt nur eine Schaltfläche; erst der Klick meldet ab (damit automatische Link-Prüfer in Mailprogrammen niemanden versehentlich abmelden). Mailprogramme mit Ein-Klick-Abmeldung lösen die Abmeldung direkt aus.
- Die Abmeldung gilt je Website und betrifft **nur Werbung**, keine Transaktionsnachrichten.
- Gespeichert wird der Zeitpunkt der Abmeldung im Benutzerkonto, unbefristet; bei Deinstallation bleibt er erhalten, außer der Betreiber wählt „alle Daten entfernen“.
- Ändern sich die Sicherheitsschlüssel der Website, werden ältere Links ungültig; die Seite meldet dann „Dieser Abmelde-Link ist nicht (mehr) gültig.“ (Folge der Bauweise, `src/Unsubscribe.php`).
- Die **Auskunft** (Abschnitt 1.4) enthält den Abmeldevermerk heute **nicht**; die Löschung entfernt ihn nicht (Stand `src/Privacy/Privacy.php`). [`OPTIONS.md`](OPTIONS.md) vermerkt dazu „Aufbewahrung und Auskunft: Rechtsprüfung“.

**Heutige Platzhaltertexte (deutsche Übersetzung, Du-Form; daneben gibt es eine Sie-Fassung):**
- Abmeldezeile in der Mail: „Wenn du diese Nachrichten nicht mehr erhalten möchtest: [Link]“
- Seite nach Öffnen: „Möchtest du keine Werbenachrichten dieser Website mehr erhalten?“ mit Schaltfläche „Abmelden“
- Seite nach Abmeldung: „Du erhältst keine Werbenachrichten dieser Website mehr. Nachrichten zu deinen Bestellungen und Buchungen sind davon nicht betroffen.“
- Ungültiger Link: „Dieser Abmelde-Link ist nicht (mehr) gültig.“

**Optionen:** Wortlaut bestätigen oder ersetzen; Frist für den Abmeldevermerk festlegen (unbefristet, feste Frist, oder solange das Konto besteht); Abmeldevermerk in Auskunft aufnehmen ja/nein; bei Löschanfrage entfernen oder behalten (z. B. damit die Person nicht wieder Werbung bekommt) – letzteres ist eine Abwägung, die das Repository nicht trifft.

**Zu liefern:** Wortlaut für Abmeldezeile und drei Seitentexte (Du- und Sie-Form, Deutsch; Englisch als Ausgangstext); Rechtsgrundlage und Frist für den Abmeldevermerk; Entscheidung zur Auskunft/Löschung.

**Wird eingetragen in:** `src/Unsubscribe.php`, `src/Engine/Actions/NotifyUser.php`, Übersetzungsdateien unter `languages/`, Spezifikation R-9.8, [`OPTIONS.md`](OPTIONS.md); ggf. Erweiterung von Auskunft und Löschung. Die Aufgabe SET-WC-04 bleibt bis dahin offen.

### RP-06 · Fußzeile der Nachrichten – Impressum und Datenschutz (SET-WC-10)

**Frage an die Prüferin:** Welche Angaben muss bzw. sollte eine Fußzeile in Nachrichten an Kunden enthalten (je DE, AT, CH, EU), und soll die Software dafür einen Mustertext mitliefern?

**Heutiger Stand:**
- Feld „Fußzeile“ (bis 1000 Zeichen), nur für Nachrichten an Kunden, vor der Abmeldezeile. Platzhalter: Name der Website, Adresse der Website, Adresse der Datenschutzerklärungsseite von WordPress.
- Das Feld ist **bewusst ohne Vorgabetext**: leer = keine Fußzeile (Grundsatz „Rechtliche Werte werden nicht erfunden“, [`SETTINGS-ANALYSE.md`](SETTINGS-ANALYSE.md)).
- Ein Platzhalter für die Impressumsseite fehlt; WordPress kennt von Haus aus nur die Datenschutzseite.

**Optionen:** (a) Feld bleibt leer, Handbuch nennt die nötigen Angaben; (b) Mustertext als Vorschlag (nicht automatisch eingetragen); (c) zusätzlicher Platzhalter für eine Impressumsseite.

**Zu liefern:** Liste der Pflicht- bzw. empfohlenen Angaben je Region; ggf. Mustertext mit Platzhaltern.

**Wird eingetragen in:** Hilfetext des Felds „Fußzeile“ (`src/SettingsRegistry.php`), Handbuch, ggf. Einrichtungsassistent (SET-WC-27).

### RP-07 · Länderprofile DE, AT, CH, EU (SET-WC-17, SET-WC-P3)

**Frage an die Prüferin:** Sind die vorgeschlagenen Werte der Länderprofile (Ruhezeit, Sendetage, Höchstzahl je Tag, Aufbewahrungsfristen) für die jeweilige Region vertretbar, oder welche Werte sollen stattdessen vorgeschlagen werden?

**Heutiger Stand:**
- Ein Länderprofil ist **nur ein Vorschlag**: Der Betreiber sieht „aktuell“ und „vorgeschlagen“ und wählt einzeln, was er übernimmt. Nichts wird still geändert (Entscheidungen S8, S9).
- Werte mit rechtlichem Gewicht sind als „ungeprüft – rechtliche Prüfung“ gekennzeichnet und werden nie vorausgewählt.
- Laut Entscheidung S8 werden DE, AT und CH **vor dem Verkaufsstart** rechtlich und steuerlich geprüft. [`OPTIONS.md`](OPTIONS.md): „Die Werte von DE, AT und CH sind Platzhalter.“

**Heutige Platzhalterwerte (`src/CountryProfiles.php`):**

| Wert | DE | AT | CH | EU | Status |
|---|---|---|---|---|---|
| Ruhezeit von | 20 Uhr | 20 Uhr | 20 Uhr | 21 Uhr | ungeprüft |
| Ruhezeit bis | 8 Uhr | 8 Uhr | 8 Uhr | 8 Uhr | ungeprüft |
| Sendetage | Montag–Samstag | Montag–Samstag | Montag–Samstag | alle Tage | ungeprüft |
| Höchstzahl Nachrichten je Kunde und Tag | 2 | 2 | 2 | 3 | ungeprüft |
| Über der Höchstzahl | am nächsten Tag senden | wie DE | wie DE | wie DE | ohne rechtliches Gewicht (geht keine Nachricht verloren) |
| Zeitzone | Europe/Berlin | Europe/Vienna | Europe/Zurich | – | ohne rechtliches Gewicht |
| Frist erledigte Ereignisse | 30 Tage | 30 | 30 | 30 | ungeprüft |
| Frist erledigte Abläufe | 30 Tage | 30 | 30 | 30 | ungeprüft |
| Frist fehlgeschlagene/pausierte Ereignisse | 90 Tage | 90 | 90 | 90 | ungeprüft |

Technische Grenzen, innerhalb derer die Werte liegen müssen: Ruhezeit in vollen Stunden; Höchstzahl 0–20 (0 = aus); Fristen 1–180 Tage.

**Hinweis:** Ruhezeit und Höchstzahl gelten nur für Nachrichten aus Abläufen, nicht für Mails, die andere Produkte selbst versenden (siehe Abschnitt 4, Gutschein-Mail).

**Offene Frage:** Welche steuerlichen Werte gemeint sind, beschreibt das Repository nicht; die Profile enthalten heute keine Steuerwerte. SET-WC-P3 nennt „Länder, Steuer und Recht“ als Prüfbereich.

**Zu liefern:** je Region und Zeile: Wert bestätigen oder Ersatzwert nennen; ob der Wert danach als „geprüft“ gekennzeichnet werden darf.

**Wird eingetragen in:** `src/CountryProfiles.php` (Wert und Kennzeichen geprüft/ungeprüft), [`OPTIONS.md`](OPTIONS.md). Die Aufgaben SET-WC-17 und SET-WC-P3 bleiben bis dahin offen.

### RP-08 · Aufbewahrungsfristen (WF-SPEC-11, F-04, Spezifikation R-12.4)

**Frage an die Prüferin:** Sind die vorgegebenen Fristen angemessen, und gibt es Mindest- oder Höchstfristen, die die Software vorgeben oder dem Betreiber nahelegen sollte?

**Heutiger Stand (Vorgaben, vom Betreiber einstellbar):**

| Daten | Vorgabe | Einstellbar |
|---|---|---|
| erledigte Ereignisse | 30 Tage | 1–180 Tage (darüber nur mit Pro) |
| fehlgeschlagene oder pausierte Ereignisse | 90 Tage | 1–180 Tage |
| erledigte Abläufe mit Schrittprotokoll | 30 Tage nach Ende | 1–180 Tage |
| Benutzer-ID im Ereignis | geleert, sobald kein Ablauf mehr wartet | – |
| Tageszähler | 2 Tage | – |
| Sicherung der Einstellungen (inkl. Hinweis-Empfänger) | 30 Tage | – |
| Änderungsprotokoll | letzte 50 Einträge | 10–500 Einträge |
| Schlagwörter, Notizen | keine Frist | – (RP-09) |
| Abmeldevermerk | keine Frist | – (RP-05) |
| Ausgangswarteschlange Webhooks (Pro) | gesendet 7, fehlgeschlagen 30 Tage | laut [`LOESCHUNG.md`](LOESCHUNG.md) |

Entschieden ist (F-04/E-S7, 23.11.2026): 30 und 90 Tage bleiben Vorgabe, bis 180 Tage frei verlängerbar. „Welche Frist rechtlich nötig ist, bleibt Sache der Rechtsprüfung“ (R-12.4). Kürzere Fristen wirken bei der nächsten täglichen Wartung auch auf ältere Einträge.

**Optionen:** Vorgaben bestätigen; andere Vorgaben; Hinweistext für Betreiber, wann längere Fristen begründet sein können.

**Zu liefern:** Bestätigung oder neue Werte je Zeile; ggf. Hinweistext für den Reiter „Datenschutz & Aufbewahrung“.

**Wird eingetragen in:** `src/SettingsRegistry.php` (Vorgaben), `src/Privacy/Privacy.php`, Länderprofile (RP-07), `readme.txt`, Spezifikation R-12.4. Der Hilfetext sagt heute: „Die Vorgaben sind ein Vorschlag bis zur Rechtsprüfung.“ Die Aufgabe WF-SPEC-11 bleibt bis dahin offen.

### RP-09 · Schlagwörter, Notizen und Baustein für die Datenschutzerklärung (SET-WC-34)

**Frage an die Prüferin:** Brauchen Schlagwörter und Notizen eine Aufbewahrungsfrist, und welcher Text soll als Baustein für die Datenschutzerklärung des Betreibers mitgeliefert werden?

**Heutiger Stand:**
- Abläufe können Gegenständen (z. B. Bestellungen) Schlagwörter und Notizen anhängen. Der Text stammt vom Betreiber; Personenbezug entsteht nur, wenn er ihn hineinschreibt. Es gibt **keine Frist**; die Fristen aus RP-08 erfassen sie nicht (`readme.txt`).
- WordPress bietet Plugins an, einen Textvorschlag für die Datenschutzerklärung der Website bereitzustellen (Seite „Datenschutz“ im Admin). Workflow Captain liefert dort **heute keinen** Text.
- **Offene Frage (Widerspruch im Repository):** [`LOESCHUNG.md`](LOESCHUNG.md) sagt, Notizen mit Benutzerbezug würden mit exportiert und gelöscht. Spezifikation R-12.5 verlangt, dass Auskunft und Löschung auch Markierungen und Notizen erfassen. Der heutige Code von Auskunft und Löschung (`src/Privacy/Privacy.php`) behandelt Ereignisse, Abläufe, Tageszähler und Hinweis-Empfänger, **aber keine Schlagwörter oder Notizen**. Das ist vor oder mit der Rechtsprüfung technisch zu klären.

**Optionen für Schlagwörter/Notizen:** (a) keine Frist, Hinweis an Betreiber; (b) einstellbare Frist; (c) Löschen zusammen mit dem Gegenstand.

**Zu liefern:** Entscheidung zur Frist; Baustein für die Datenschutzerklärung (Deutsch, Englisch als Ausgangstext), der mindestens die Datenflüsse aus Abschnitt 1.3 abdeckt, die der Kern verantwortet; ggf. ein optionaler Zusatzabsatz für Pro (Webhooks, n8n, Bewertungseinladungen).

**Wird eingetragen in:** Baustein über die WordPress-Funktion für Datenschutztexte (neu im Kern), `readme.txt` Abschnitt „Privacy“, [`OPTIONS.md`](OPTIONS.md). Die Aufgabe SET-WC-34 bleibt bis dahin offen.

### RP-10 · Preisangaben in Nachrichten (SET-WC-32, R-9.10)

**Frage an die Prüferin:** Gibt es Anforderungen an die Darstellung von Preisen und Beträgen in Nachrichten (z. B. Angaben zu Steuern, Währungsschreibweise), die die Software unterstützen oder dem Betreiber nahelegen sollte?

**Heutiger Stand:**
- Betreiber können Beträge aus Ereignissen in Nachrichten einsetzen. Mit Formatierung erscheinen sie in der Schreibweise der Sprache des Empfängers mit der richtigen Zahl von Nachkommastellen je Währung (z. B. „12,50 €“); ohne Formatierung erscheint der Rohwert.
- Ob ein Betrag brutto oder netto ist, liefert das Ereignis nicht; den umgebenden Text schreibt der Betreiber.
- Vermerk im Repository: „Wortlaut von Preisangaben in Nachrichten: Rechtsprüfung.“

**Optionen:** (a) keine Vorgabe, Hinweis im Handbuch; (b) Hinweis im Editor beim Einsetzen eines Betrags.

**Zu liefern:** ggf. Hinweistext für Betreiber.

**Wird eingetragen in:** Handbuch, Hilfetext im Editor, Spezifikation R-9.10.

### RP-11 · Kontakt und Einwilligung bei Empfängern ohne Benutzerkonto (F-13)

**Frage an die Prüferin:** Darf ein Ablauf über eine Lesefunktion des Ticket- bzw. Gutscheinprodukts Kontakt und Einwilligung einer Person ohne WordPress-Konto (z. B. Ticketgast, Beschenkte) zur Laufzeit abfragen, um sie anzuschreiben, und unter welchen Bedingungen?

**Heutiger Stand:**
- Entschieden am 23.11.2026: „Ja, **nach Rechtsprüfung**.“ Je eine dokumentierte Lesefunktion im Gutschein- und im Ticketprodukt, **ab Werk aus** (Umsetzung in den anderen Repositories, noch offen).
- Workflow Captain speichert die Kontaktdaten nicht; sie würden nur beim Versand gelesen (R-5.6).
- Bis dahin speichert und nutzt Workflow Captain keine Kontaktdaten solcher Personen ([`LOESCHUNG.md`](LOESCHUNG.md)).

**Zu liefern:** Voraussetzungen, unter denen der Betreiber die Funktion einschalten darf (z. B. welche Einwilligung oder Rechtsgrundlage vorliegen muss); ggf. Hinweistext am Schalter.

**Wird eingetragen in:** Spezifikation (F-13, Abschnitt 5), Anforderungen an Voucher Captain und Pass Captain.

---

## 3. Liste der zu liefernden Texte und Werte

| Nr. | Text / Wert | Sprachen / Formen | Prüfpunkt | Ziel |
|---|---|---|---|---|
| T-1 | Abmeldezeile in Werbenachrichten (mit Platzhalter für den Link) | Englisch (Ausgangstext), Deutsch Du, Deutsch Sie | RP-05 | `src/Engine/Actions/NotifyUser.php`, `languages/` |
| T-2 | Abmeldeseite „Bestätigen“ (Frage + Beschriftung der Schaltfläche) | wie T-1 | RP-05 | `src/Unsubscribe.php`, `languages/` |
| T-3 | Abmeldeseite „Erfolgreich abgemeldet“ | wie T-1 | RP-05 | wie T-2 |
| T-4 | Abmeldeseite „Link ungültig“ | wie T-1 | RP-05 | wie T-2 |
| T-5 | Rechtsgrundlage und Frist des Abmeldevermerks; Aufnahme in Auskunft/Löschung ja/nein | – | RP-05 | [`OPTIONS.md`](OPTIONS.md), `src/Privacy/Privacy.php` |
| T-6 | Inhalt der Fußzeile bzw. Liste der Pflichtangaben; ggf. Impressum-Platzhalter | je Region | RP-06 | Hilfetext Feld „Fußzeile“, Handbuch |
| T-7 | Hinweistext zur Wahl „Werbung“ / „Transaktion“ und zur nötigen Einwilligung | wie T-1 | RP-04 | Aktion „Nachricht an Benutzer“ |
| T-8 | Baustein für die Datenschutzerklärung (Kern; optional Zusatz Pro) | wie T-1 | RP-09, RP-03 | neu im Kern; `readme.txt` |
| T-9 | Länderprofilwerte DE, AT, CH, EU (Tabelle in RP-07) | – | RP-07 | `src/CountryProfiles.php` |
| T-10 | Aufbewahrungsfristen (Tabelle in RP-08), inkl. Schlagwörter/Notizen | – | RP-08, RP-09 | `src/SettingsRegistry.php`, `src/Privacy/Privacy.php` |
| T-11 | Hinweis zu Preisangaben | wie T-1 | RP-10 | Editor, Handbuch |
| T-12 | Voraussetzungen für die Kontaktabfrage ohne Konto | – | RP-11 | Spezifikation, andere Repositories |
| T-13 | Hinweis an Betreiber vor dem Einrichten von Webhooks/n8n | wie T-1 | RP-03 | Pro, [`n8n/README.md`](n8n/README.md) |

---

## 4. Bereits getroffene Entscheidungen der Inhaberin mit rechtlicher Bedeutung

Diese Entscheidungen sind gebaut oder festgelegt. Die Prüferin kann sie bestätigen oder Bedenken anmelden; eine Änderung wäre eine neue Entscheidung der Inhaberin.

| Datum | Kennung | Entscheidung | Fundstelle |
|---|---|---|---|
| 16.11.2026 | D8 | Ereignisse ohne geheime Codes und ohne Kontaktdaten; „Datenschutzbewertung → Rechtsprüfung“ | Spezifikation Abschnitt 5 |
| 16.11.2026 | D10 | Mails der Produkte bleiben unverändert in deren Verantwortung; Abläufe senden nur zusätzliche Nachrichten | Spezifikation Abschnitt 9 |
| 23.11.2026 | E-S2 | Werbenachricht ohne Einwilligung wird **nicht gesendet**; Vorgabe und Texte unter Rechtsprüfung | [`SETTINGS-ANALYSE.md`](SETTINGS-ANALYSE.md) |
| 23.11.2026 | S11 | Jede Nachricht an Kunden ist Werbung oder Transaktion; Werbung nur mit Einwilligung und Abmelde-Link über **eine** öffentliche Adresse; bestehende Abläufe gelten als Transaktion | [`SETTINGS-ANALYSE.md`](SETTINGS-ANALYSE.md), Spezifikation R-9.6 bis R-9.8 |
| 23.11.2026 | E-S7 = F-04 | Aufbewahrung: Vorgaben 30/90 Tage, bis 180 Tage frei, darüber nur Pro | Spezifikation R-12.4 |
| 23.11.2026 | E-S6 / S10 | Bei Deinstallation werden Daten **behalten**; Löschen nur nach ausdrücklicher Wahl mit getipptem Bestätigungswort | [`OPTIONS.md`](OPTIONS.md) |
| 23.11.2026 | S8 / S9 | Länderprofile DE, AT, CH, EU, OTHER nur als Vorschlag; DE, AT, CH vor Verkaufsstart prüfen; bestehende Fristen nie still ändern | [`SETTINGS-ANALYSE.md`](SETTINGS-ANALYSE.md) |
| 23.11.2026 | SET-WC-29 | Auskunft und Löschung erfassen auch Adressen in den Hinweis-Empfängern und in der Sicherung der Einstellungen | `TODO.md` |
| 23.11.2026 | F-13 | Kontakt und Einwilligung je Gutschein bzw. Ticket lesbar – „ja, nach Rechtsprüfung“, ab Werk aus | [`SPEC-open-questions.md`](SPEC-open-questions.md) |
| 23.11.2026 | F-23 | Abläufe dürfen Bewertungseinladungen planen und abbrechen; Einwilligung, Abmeldung, Sperrliste und Häufigkeit prüft das Bewertungsprodukt | [`SPEC-open-questions.md`](SPEC-open-questions.md) |
| 24.11.2026 | – | Die Gutschein-Mail des Gutscheinprodukts an die beschenkte Person bleibt **außerhalb** von Ruhezeit und Tagesgrenze (bezahlte Ware, den Termin wählt der Käufer) | [`OPTIONS.md`](OPTIONS.md), Spezifikation R-9.9 |
| 24.11.2026 | R-4.30a | Bewertungseinladungen aus Abläufen auch für Gutscheine; es gilt dieselbe Einwilligung wie bei Einladungen, die das Bewertungsprodukt selbst verschickt; Zuständigkeit für Einwilligung und Widerruf je Quelle (Bestellung: Shop, Gutschein: Gutscheinprodukt) | Spezifikation R-4.30a |

---

## 5. Offene Unklarheiten im Repository (vor oder mit der Prüfung zu klären)

1. **Benutzer-ID in Webhooks** (RP-03): „ohne Personendaten“ in [`LOESCHUNG.md`](LOESCHUNG.md) gegen R-12.1 (Benutzer-ID ist personenbezogen); Umsetzung liegt im Pro-Repository.
2. **Standort von n8n Cloud** / Auftragsverarbeitung (RP-03): nicht beschrieben.
3. **Schlagwörter und Notizen in Auskunft und Löschung** (RP-09): [`LOESCHUNG.md`](LOESCHUNG.md) und R-12.5 sagen ja, der Code erfasst sie heute nicht.
4. **Abmeldevermerk in Auskunft und Löschung** (RP-05): heute nicht erfasst; Frist offen.
5. **„Bestätigte Rechtsgrundlage“** bei Bewertungseinladungen (RP-02): Bedeutung im Repository nicht beschrieben.
6. **Steuerliche Werte der Länderprofile** (RP-07): welche gemeint sind, ist nicht beschrieben.
