# Entscheidungsvorschläge zu den offenen Fragen der Suite-Spezifikation

> **Vorschläge, keine Entscheidungen.** Dieses Dokument ändert weder [`SPEC-suite-events.md`](SPEC-suite-events.md) (Version 1.0.0-draft.3) noch [`SPEC-open-questions.md`](SPEC-open-questions.md). Es soll der Inhaberin die Abnahme erleichtern: je offener Frage eine Empfehlung, die sie mit „ja“ oder „nein“ beantworten kann, und die Stellen der Spezifikation, die sich bei „ja“ ändern. Verbindlich wird ein Vorschlag erst, wenn er in `SPEC-open-questions.md` als „Entschieden am …“ eingetragen und in die Spezifikation eingearbeitet ist.

Stand: 16.11.2026. Grundlage: die Entscheidungen D1 bis D13, D8b, D11b, Entscheidung 17 und die Antwort zu F-05.

Gelesene Stände (Kürzel wie in Abschnitt 0 der Spezifikation; Fundstellen dieses Dokuments beziehen sich auf diese Commits, nicht auf die Stände der Spezifikation):

| Kürzel | Repository | Commit |
|---|---|---|
| WF | `wp-workflowmaster` | `081049c` |
| AC | `wpaffiliatemaster` | `758ca4e` |
| VC | `wp-couponmaster` | `ea4ebf7` |
| PC | `wp-passmaster` | `697acf1` |
| LIC | `wp-license` | `75c5146` |
| CP | `captain-pro` | `2f23da7` |
| FC | `wp-formmaster` | `6f4785f` |
| RC | `wp-reviewmaster` | `33d1362` (nur Dokumente, kein Code) |
| CC | `wp-coursemaster` | `e8c6e60` (nur Dokumente) |

## Auf einen Blick

| Frage | Empfehlung in einem Satz |
|---|---|
| F-01 | Keines der drei Rechte anlegen: Verzögerungen und die Connector-Schnittstelle gehören in den kostenlosen Kern, `workflow.multisite_network` kommt mit der späteren Multisite-Fassung. |
| F-02 | Bestätigen: Datensatznummer mit `id_kind` = `record`, wo es keine öffentliche Kennung gibt; `key_prefix` nie. |
| F-03 | Muster `<PREFIX>_CONTRACT_VERSION` bestätigen; Abkündigung: zwei MINOR-Versionen **und** sechs Monate. |
| F-05a | Kein Umrechnen, keine Währungsliste: Höchstbetrag gilt in der Basiswährung des Shops; außer bei EUR trägt der Betreiber ihn selbst ein, bis dahin bleiben Ausstellen und Einlösen gesperrt. |
| F-06 | Zwei Erweiterungen im Add-on-Kernel: weitere Kerne in `requires()` und eine nur lesende Lizenzauskunft über andere Add-ons. |
| F-07 | Version 1 nur je Website; netzwerkweite Abläufe in eine spätere MINOR-Version. |
| F-08 | Alle Adapter in Workflow Captain Pro; die Review-Einladung kommt als Workflow mit einem Review-Befehl in der MINOR-Version für `review.*`, nicht als eigener Adapter in Review Captain Pro. |
| F-09 | Eine gültige Lizenz des Produkt-Add-ons genügt; kein neues Recht je Produkt. |
| F-10 | Bestätigen: kostenloser Kern mit Consumer, ohne Adapter. |
| F-11 (Folgefrage) | Keine eigene Provider-Seite mehr: nur dokumentierte Lesefunktionen; für Affiliate genügt die vorhandene Lese-API. |
| F-12 (Form, Review) | Wie D13: Mail-Übergabe und Zuständigkeitsübergabe vor dem ersten Release herausnehmen; Kataloge in einer MINOR-Version. |
| F-13 | Lesefunktion „Kontakt und Einwilligung“ in Pass und Voucher, aber erst mit der MINOR-Version für die Review-Einladung; Version 1.0 braucht sie nicht. |
| F-14 | Verlust hinnehmen und im Handbuch nennen. |
| F-15 | Acht Versuche, Abstände wie 7.4, Tiefe 3 bestätigen. |
| F-16 | Präfix `course.` nur reserviert lassen; ob Course Captain ein Produkt wird, ist eine eigene Geschäftsentscheidung und hält die Abnahme nicht auf. |
| F-18 | Als durch Entscheidung 17 erledigt markieren. |
| F-19 | Keine Empfehlung (Geschäftsentscheidung); die Spezifikation enthält keine Preise, die Abnahme hängt nicht daran. |
| W-1 bis W-13 | Den in Teil 2 von `SPEC-open-questions.md` beschriebenen Umgang bestätigen; W-11 folgt F-12 (siehe Ende dieses Dokuments). |

---

## F-01 · Drei Workflow-Rechte ohne Vormerkung

**Frage.** Werden `workflow.custom_connectors`, `workflow.delays` und `workflow.multisite_network` Rechte von Workflow Captain Pro?

**Optionen.**
- (a) Alle drei als Rechte anlegen, wie `konzept.md:104` und `:642` es vorsehen.
- (b) Nur `custom_connectors` und `multisite_network` vormerken, `delays` streichen (Empfehlung in `SPEC-open-questions.md`).
- (c) Keines der drei anlegen: `delays` und die Connector-Schnittstelle bleiben im kostenlosen Kern; `multisite_network` erst mit der späteren Multisite-Fassung.

**Empfehlung: (c).**
- **Verzögerungen:** `konzept.md:76` führt „Verzögerung und Datum/Zeitplanung“ im kostenlosen Kern. Ein Workflow ohne Wartezeit taugt für die meisten Abläufe nicht. Wer den Kern ohne Wartezeit auf WordPress.org stellt und die Wartezeit hinter ein Recht legt, kommt mit Richtlinie 5 („voll funktionsfähig, keine gesperrten Funktionen“, `AC:docs/FREE-PREMIUM.md:23`) zumindest in Erklärungsnot.
- **Connectoren:** Nach Entscheidung 17 bringen die Kundenconnectoren ihre eigene Lizenz mit (`WF:TODO.md:266`, `AC:docs/CONTRACT.md:3`). Verlangt die Connector-Laufzeit zusätzlich Workflow Pro, zahlt der Kunde doppelt, ohne dass Workflow Pro etwas schützt. Die öffentliche Connector-Schnittstelle (`workflow_captain_connectors_v1`, `konzept.md:145`) gehört deshalb in den kostenlosen Kern, wie die Hooks jedes anderen Kerns. Ausgehende Webhooks bleiben hinter `workflow.webhooks`.
- **Multisite-Netzwerk:** Folgt F-07. Das Recht wird mit der MINOR-Version angelegt, die netzwerkweite Abläufe beschreibt.
- Nebenbei: Im gelesenen Stand des Lizenzservers stehen auch die sechs „vorgemerkten“ Schlüssel nicht im Code. `PRODUCT_KEYS` nennt nur Voucher, Pass und Affiliate (`LIC:src/Catalog/EntitlementCatalog.php:129-133`), die Workflow-Rechte stehen nur als Aufgabe WFC-LIC-02 (`LIC:docs/TODO.md:723`). Nach W-8 gehören sie ohnehin in die Katalogdatei des Add-ons. Für keines der drei Rechte ist also jetzt etwas am Lizenzserver zu tun.

**Änderungen bei Annahme.**
- 10.2: Die drei Zeilen werden zu „kein Recht von Workflow Captain Pro (F-01)“: Verzögerungen und Connector-Schnittstelle gehören zum kostenlosen Kern, `workflow.multisite_network` folgt F-07. Den Satz „Im Lizenzserver vorgemerkt …“ auf den Ort nach W-8 umstellen (Katalogdatei von `workflow-captain-pro`).
- Außerhalb der Spezifikation: `konzept.md:104`, `:105` („neun Rechte“), `:642`, `:712` sowie `WF:TODO.md:23`, WF-CONNECTOR-12 (`:130`), WF-MS-02 (`:235`) und WF-E17-03 (`:266`) anpassen.

## F-02 · Öffentliche Kennungen fehlen in drei Providern

**Frage.** Was steht in `subject.id`, wenn ein Kern keine öffentliche Kennung hat (Gutschein, Provision, Partner, Lizenz)?

**Optionen.** (a) Datensatznummer mit `id_kind` = `record`. (b) Neue öffentliche Kennungen in Voucher, Affiliate und Lizenzserver. (c) `key_prefix` beziehungsweise Code als Ersatz.

**Empfehlung: (a).** Eine Datensatznummer ist kein Geheimnis und lässt sich allein nicht einlösen. Sender und Empfänger laufen im selben WordPress, und die Daten verlassen die Website nicht (R-12.6). (b) wäre in drei Kernen ein Umbau mit Migration, also genau das, was D5 und D9 vermeiden sollen. (c) verstößt gegen D8: `key_prefix` ist ein Teil des Lizenzschlüssels.

**Änderungen bei Annahme.** Keine Regeländerung. Abschnitt 6 (`subject.id_kind`), 4.3, 4.4 und Abschnitt 11 (`subject`) verweisen statt auf die offene Frage auf „entschieden (F-02)“. W-1 ist damit aufgelöst.

## F-03 · Vertragskonstanten und Abkündigungsfrist

**Frage.** Wie heißen die fehlenden Vertragskonstanten, wie heißt die Konstante des Consumers, und wie lange bleibt eine abgekündigte Action?

**Optionen.**
- Name: (a) `<PREFIX>_CONTRACT_VERSION` mit dem Präfix der vorhandenen Versionskonstante. (b) Ein einheitliches neues Präfix für alle, zum Beispiel `CAPTAIN_<PRODUKT>_CONTRACT_VERSION`, mit Umbenennung der vorhandenen Konstanten.
- Frist: (a) nur zwei MINOR-Versionen. (b) zwei MINOR-Versionen **und** mindestens sechs Monate, je nachdem, was später endet.

**Empfehlung: Name (a), Frist (b).** Vier Kerne erfüllen R-3.1 schon (Affiliate inzwischen mit `1.5.0`, `AC:affiliate-captain.php:53`; `FC:form-captain.php:29`). Eine Umbenennung wäre selbst eine unverträgliche Vertragsänderung. Daraus folgen `REVIEW_CAPTAIN_CONTRACT_VERSION`, `COURSE_CAPTAIN_CONTRACT_VERSION`, `WPLC_CONTRACT_VERSION` (aus `WPLC_VERSION`, `LIC:wp-license.php:23`) und für den Consumer `WORKFLOW_CAPTAIN_CONTRACT_VERSION` (R-13.7). Die Monatsfrist schützt Betreiber, die selten aktualisieren: Zwei MINOR-Versionen können in wenigen Wochen erscheinen.

**Änderungen bei Annahme.** R-3.1 nennt die vier Namen. R-3.7: „mindestens zwei MINOR-Versionen und mindestens sechs Monate; maßgeblich ist das spätere Ende“. Tabelle in 3.1 und R-13.7 (Name des Consumers) ergänzen. Damit ist auch W-9 erledigt.

## F-04 · Aufbewahrung im Ereignisspeicher

**Frage.** Wie lange bleiben gespeicherte Ereignisse, und wann werden `actor_user_id` und `data` geleert?

**Optionen.** (a) 30 Tage für erledigte Ereignisse, 90 Tage für `dead_letter` und `paused`, `actor_user_id` und `data` sofort leeren, sobald kein Lauf mehr wartet; der Betreiber darf die Fristen nur senken. (b) Dieselben Zahlen, aber vom Betreiber frei einstellbar, auch nach oben (vergleichbar mit den bis zu 365 Tagen für Laufprotokolle in Pro, `konzept.md:94`). (c) Keine Zahlen bis zur Rechtsprüfung (A6).

**Empfehlung: (a).** Datensparsamkeit ist der Grundsatz aus R-12.3. Die Zahlen sind Obergrenzen; die Rechtsprüfung kann sie nur noch senken und muss die Abnahme deshalb nicht aufhalten. Der Ereignisspeicher dient der Ausführung und Wiederholung (R-12.2), nicht der Auswertung. 30 Tage reichen für jede Wiederholung (der Plan aus 7.4 endet nach rund 47 Stunden) und für die Fehlersuche. Die 365 Tage aus `konzept.md:94` betreffen Laufprotokolle, nicht den Ereignisspeicher, und bleiben davon unberührt.

**Änderungen bei Annahme.** R-12.4: „Vorschlag“ und den Verweis auf F-04 streichen, die Zahlen als Obergrenze festschreiben: „Der Betreiber KANN die Fristen senken, nicht anheben.“ Den Vorbehalt der Rechtsprüfung (Kasten in Abschnitt 12) stehen lassen. C-14 um „eine höhere Frist lässt sich nicht einstellen“ ergänzen.

## F-05a · Höchstbeträge in Shops ohne Euro

**Frage.** Was gilt für die Höchstbeträge von `voucher.issue` und `voucher.redeem` in einem Shop, dessen Währung nicht EUR ist?

**Optionen.**
- (a) Der Kern rechnet mit Wechselkursen um.
- (b) Eine im Kern gepflegte Liste „ähnlich wertvoller“ Währungen (CHF, USD, GBP …) bekommt ebenfalls die Zahl 100 (Empfehlung in `SPEC-open-questions.md`).
- (c) Keine Umrechnung und keine Liste. Der Höchstbetrag gilt in der **Basiswährung des Shops**. Ist sie EUR, gilt 100. Sonst trägt der Betreiber einmal selbst einen Höchstbetrag ein, und bis dahin lehnt der Kern `voucher.issue` und `voucher.redeem` ab. `voucher.void` und `voucher.reactivate` sind nicht betroffen.

**Empfehlung: (c).**
- Voucher Captain führt Guthaben ohnehin in der Basiswährung des Shops (`VC:TODO.md:149`). Der Betrag eines Befehls lässt sich also immer in dieser einen Währung prüfen, auch ohne die offene Mehrwährungsfrage G6.
- (a) braucht eine Kursquelle. Die einzige im Kern hängt an Währungs-Plugins und gab bei fehlendem Kurs den Betrag bisher unverändert zurück (`VC:TODO.md:269`). Für eine Geldgrenze ist das zu unsicher.
- (b) widerspricht der Entscheidung „nur senken“: 100 GBP sind mehr als 100 EUR. Außerdem müsste jemand die Liste pflegen.
- (c) schließt sicher ab und kostet am wenigsten. Alle vier Befehle sind ab Werk ohnehin aus (R-8.3d), der Betreiber muss also sowieso einmal etwas einstellen.
- Ein Befehl, dessen `currency` nicht die Basiswährung ist, wird abgelehnt. Die Einstellungsseite SOLL darauf hinweisen, dass der Betrag den Gegenwert von 100 EUR nicht überschreiten soll. Prüfen kann der Kern das nicht; die Verantwortung liegt beim Betreiber.

**Änderungen bei Annahme.** 8.3.1: Den letzten Absatz („Die Beträge sind in Euro entschieden …“) durch die Regel (c) ersetzen, als R-8.3g. Die Tabelle in 8.3.1 bekommt die Fußnote „EUR = Basiswährung; andere Basiswährung siehe R-8.3g“. 8.3.2 und 8.3.4: `currency` MUSS die Basiswährung des Shops sein. P-8 ergänzen: „lehnt eine fremde Währung und einen fehlenden Höchstbetrag außerhalb von EUR ab“.

## F-06 · Eine Funktion braucht das Recht eines anderen Add-ons

**Frage.** Wie prüft ein Adapter in Workflow Captain Pro die Lizenz eines anderen Add-ons, und wie verlangt er einen zweiten Kern?

**Optionen.**
- (a) Der Adapter hängt sich an `<prefix>_loaded` des anderen Add-ons. Dieses übergibt heute das ganze `Addon`-Objekt (`CP:packages/addon-kernel/src/Addon.php:551`), und der Adapter ruft dort `is_entitled()` auf (`:594`).
- (b) Zwei Erweiterungen im Kernel: `requires()` bekommt einen Eintrag für weitere Kerne mit Versions- und Vertragskonstante, und der Kernel bietet eine nur lesende Auskunft „hat das Add-on X auf dieser Website eine gültige Lizenz“.
- (c) Jeder Adapter baut die Prüfung selbst.

**Empfehlung: (b).** Mit (a) bekäme jedes Add-on das Objekt eines anderen samt `license()` (`Addon.php:637`), also auch Methoden, die den Lizenzstatus ändern. Das ist eine unnötig große Angriffsfläche, und die Kopplung an innere Klassen ließe sich nicht versionieren. (c) wiederholt Lizenzlogik in mehreren Add-ons. Bei (b) liefert die Auskunft nur ja oder nein. Sie verrät keinen Schlüssel und keine Aktivierungsdaten und kann nichts verändern. Heute kennt der Kernel je Add-on genau einen Kern (`CP:packages/addon-kernel/src/CoreRequirement.php:60`). Das betrifft nur `captain-pro` (nicht WordPress.org), muss vor dem ersten Adapter fertig sein und liegt nach D12 hinter dem Verkaufsstart.

**Änderungen bei Annahme.** R-10.3 wird von „Lücke“ zu einer Anforderung an `captain-pro`, als R-10.3a („weitere Kerne in `requires()`“) und R-10.3b („nur lesende Lizenzauskunft über ein anderes Add-on; liefert nur einen Wahrheitswert; DARF NICHT Schlüssel, Aktivierungsdaten oder schreibende Methoden herausgeben“). Neuer Nachweis A-12 in 14.2. W-6 ist damit beantwortet.

## F-07 · Multisite

**Frage.** Gibt es in Version 1 Ereignisse, die von einer Website eines Netzwerks zu einer anderen gehen?

**Optionen.** (a) Version 1 gilt nur je Website, netzwerkweite Abläufe kommen in einer späteren MINOR-Version. (b) Netzwerkweite Abläufe gehören zu Version 1, wie `konzept.md:621` und `:683` sowie WF-MS-01 bis -25 (`WF:TODO.md:232-258`) es planen.

**Empfehlung: (a).** Netzwerkweite Abläufe brauchen eigene Netzwerktabellen, Freigaben je Website, Datenschutz über Website-Grenzen hinweg und eine Zählung der Lizenzplätze je Blog-ID im Lizenzserver (WF-MS-25). Das sind 25 Aufgaben mit Änderungen in `captain-pro` und im Lizenzserver, die nach D12 ohnehin warten müssten. Mit (a) bleibt R-12.6 („ein Ereignis verlässt seine Website nicht“) einfach und prüfbar (C-16), und eine spätere Erweiterung bricht nichts, weil `site.blog_id` schon im Umschlag steht.

**Änderungen bei Annahme.** Abschnitt 1 (Nichtziele) und R-12.6: Den Verweis „(F-07)“ durch „entschieden: Version 1 nur je Website“ ersetzen. Außerhalb: `konzept.md:487`, `:621`, `:683` und die WF-MS-Aufgaben als „nach Version 1, eigene MINOR-Version der Spezifikation“ kennzeichnen.

## F-08 · In welchem Add-on liegt ein Adapter, und wer plant die Review-Einladung

**Frage.** Liegt der Adapter in Workflow Captain Pro oder im Add-on des Produkts? Und wer plant die Review-Einladung nach Einlass oder Einlösung?

**Optionen.**
- Adapter: (a) alle in Workflow Captain Pro, je Produkt eine Funktion, die zusätzlich die Lizenz des Produkt-Add-ons verlangt. (b) Jeder Adapter im Add-on seines Produkts.
- Review-Einladung: (i) In Version 1 plant ein eigener Adapter in Review Captain Pro (Empfehlung in `SPEC-open-questions.md`). (ii) Sie wird ein Workflow: Auslöser `pass.ticket.checked_in` oder `voucher.voucher.redeemed`, Befehl `review.request.schedule` im Review-Kern. Der Befehl kommt mit der MINOR-Version, die `review.*` beschreibt.

**Empfehlung: Adapter (a), Review-Einladung (ii).**
- (a): Es gibt nur eine Stelle, die Umschlag und Positivlisten kennt (R-5.2). Die Produkt-Add-ons bleiben frei von Workflow-Wissen. Das passt zu D6, braucht aber F-06.
- (ii) statt (i): Nach D6 braucht jede Automation über Captain-Produkte Workflow Pro. Ein eigener Adapter in Review Captain Pro wäre eine zweite Automation an Workflow vorbei und eine zweite Stelle mit Positivlisten.
- Review Captain ist noch nicht veröffentlicht (`RC` hat auf `main` keinen Code), Version 1.0 der Spezifikation verliert durch (ii) also nichts.
- Einwilligung, Opt-out, Sperrliste und Frequenzgrenzen setzt der Review-Kern im Befehl durch, nach demselben Muster wie die Gutschein-Befehle im Voucher-Kern (8.3). So verlangt es auch RC-WF-03 (`RC:TODO.md:357`).
- Die „explizite Ownership-Übergabe“ aus RC-WF-04 (`RC:TODO.md:358`) widerspricht D10 und R-9.3 und entfällt.

**Änderungen bei Annahme.** R-2.3: „Ein Adapter liegt in Workflow Captain Pro“ statt „offen (F-08)“. 4.2 und 4.3 (Sätze zur Review-Einladung): „Die Review-Einladung kommt als Workflow mit dem Befehl `review.request.schedule` in der MINOR-Version für `review.*`.“ 4.6: dasselbe beim Review-Abschnitt. 10.1 und R-10.2 bleiben.

## F-09 · Was heißt „Pro-Lizenz des Produkts“

**Frage.** Genügt eine gültige Lizenz des Produkt-Add-ons, oder braucht es ein eigenes Recht wie `pass.suite_integrations`?

**Optionen.** (a) Eine gültige Lizenz genügt. (b) Ein neues Recht `<produkt>.suite_integrations` je Produkt.

**Empfehlung: (a).** Alle Tarife haben denselben Funktionsumfang (`AC:docs/FREE-PREMIUM.md`, §7), ein weiteres Recht würde also nichts unterscheiden. Es kostete aber in jedem Add-on-Katalog einen Eintrag, eine Funktion und einen Test. Die Workflow-Seite prüft ihr eigenes Recht `workflow.suite_integrations`. Auf der Produktseite genügt die Auskunft aus F-06 (b).

**Änderungen bei Annahme.** R-10.1: „Welches Recht … ist offen (F-09)“ wird zu „gültige Lizenz des Produkt-Add-ons, geprüft über die Auskunft aus R-10.3b; kein eigenes Recht“. Spaltenkopf in 10.1 unverändert.

## F-10 · Grenze zwischen kostenlosem Kern und Pro (D3)

**Frage.** Verarbeitet der kostenlose Workflow-Kern Ereignisse der Captain-Produkte?

**Optionen.** (a) Der Kern enthält den Consumer (Speicher, Wiederholung, Umschlag, Anmeldeschnittstelle), aber keinen Adapter; ohne Workflow Pro kommt kein Suite-Ereignis an. (b) Der Kern enthält auch einfache Adapter, zum Beispiel für Benachrichtigungen.

**Empfehlung: (a).** So folgt es schon aus D6 und D7. Wichtig für WordPress.org ist: Der Kern enthält keinen Code, der erst mit einer Lizenz freigeschaltet wird (Richtlinie 5, `AC:docs/FREE-PREMIUM.md:23`). Der Consumer ist eine offene Schnittstelle, die auch Dritte nutzen können, und keine gesperrte Funktion. (b) würde D7 widersprechen. Offen bleibt danach nur, was der Kern für WordPress- und WooCommerce-Ereignisse bietet. Das regelt die Spezifikation nicht (Abschnitt 1).

**Änderungen bei Annahme.** 10.1, Zeile „WordPress oder WooCommerce“: „offen (D3, F-10)“ bleibt für diese Zeile stehen; die Annahme zu Suite-Ereignissen wird „entschieden (F-10)“. W-7 ist damit aufgelöst. Ein Satz in Abschnitt 2 (Rolle Consumer): „Der kostenlose Kern enthält keinen Adapter.“

## F-11 · Folgefrage: In welcher Form kehren die Provider-Seiten zurück

**Frage.** Was bauen Affiliate- und Voucher-Kern nach der Abnahme (AFF-WF-08, `AC:TODO.md:1114`) wieder ein?

**Optionen.** (a) Den alten Stand aus Commit `39aa1d7` mit eigenem Umschlag. (b) Nur dokumentierte, ausschließlich lesende Funktionen, und nur dort, wo die vorhandene API nicht reicht. (c) Nichts.

**Empfehlung: (b).** Nach R-4.5 und R-6.1 baut kein Kern einen Umschlag, (a) scheidet also aus.
- **Affiliate:** Die vorhandene Lese-API genügt (`wpac_get_affiliate()`, `wpac_get_commission()`, `wpac_get_payout()`, `AC:docs/CONTRACT.md:294`, `:315-317`). Zu bauen bleiben nur die „Seit“-Tabelle und der Test (Z-08, Z-09). Der Adapter MUSS dabei filtern: `wpac_get_affiliate()` liefert auch `name` und `email` (`:315`), die R-5.1 verbietet.
- **Voucher:** Er braucht eine Lesefunktion über die `voucher_id` ohne Code. Die heutige Fassade sucht nur über den Code (`VC:API.md:7`).
- **Pass:** Er braucht eine dokumentierte Lesefunktion über die `public_id`.

**Änderungen bei Annahme.** 8.1, Absatz „Lesefunktionen heute“: je Provider die anerkannten Funktionen beziehungsweise die fehlende additive Lesefunktion nennen (erledigt damit WF-SPEC-15 dem Grundsatz nach). R-4.6 verweist darauf. Neuer Nachweis A-3a: „Felder aus Lesefunktionen gehen nur über die Positivliste in `data`“.

## F-12 · Eigene Workflow-Verträge in Form Captain und Review Captain

**Frage.** Was geschieht mit den Workflow-Teilen, die Form Captain gebaut und Review Captain beschrieben hat?

**Optionen.** (a) Wie D13: nicht normativ, vor dem ersten Release herausnehmen, später nach dieser Spezifikation. (b) Behalten und die Spezifikation anpassen.

**Empfehlung: (a).** Vorhanden ist in Form Captain:
- Die Mail-Übergabe über den Filter `form_captain_mail_handoff`: Liefert ein Abnehmer `'handled'`, entfällt die eigene Mail des Kerns (`FC:src/Mail/MailJobHandler.php:8`, `:111-114`). Dazu kommt die Bestätigung der Übergabe (`FC:src/Integrations/CoreActions.php:242`, `:336`). Das widerspricht D10 und R-9.2.
- Eine Rechteabfrage `form_captain_entitlement_granted` im Kern (`FC:src/Integrations/ConnectorRegistry.php:620-622`). Das steht in Spannung zu R-2.4 und R-3.11.

Review Captain plant eine Zuständigkeitsübergabe (`RC:TODO.md:358`), die R-9.3 verbietet. Beide Kerne sind unveröffentlicht (`FC` 1.0.0-dev), das Herausnehmen kostet jetzt also am wenigsten. Die eigenen schreibenden Aktionen von Form (`set_status`, `anonymize_after`, `FC:src/Integrations/CoreActions.php:190`, `:216`) dürfen als Form-interne Connector-Aktionen bleiben. Sie sind aber keine Suite-Befehle, solange keine MINOR-Version sie aufnimmt (8.1, 8.2).

**Änderungen bei Annahme.** 4.6: „Die Workflow-Teile von Form und Review sind nicht normativ und werden vor dem ersten Release entfernt (F-12): in Form die Mail-Übergabe samt Bestätigung und die Rechteabfrage im Kern, in Review die Zuständigkeitsübergabe.“ R-4.4 um `form_captain_mail_handoff` ergänzen. W-11 ist damit vollständig aufgelöst.

## F-13 · Empfänger ohne Benutzerkonto

**Frage.** Wie erreicht eine Nachricht den Gast eines Tickets oder den Empfänger eines Gutscheins ohne WordPress-Konto, wenn das Ereignis keine E-Mail-Adresse tragen darf?

**Optionen.** (a) Je eine kleine dokumentierte Lesefunktion „Kontakt und Einwilligung zu dieser Kennung“ in Pass und Voucher (additiv, MINOR des jeweiligen Vertrags). (b) Der Adapter nutzt interne Klassen (`PC:src/Orders.php:1544` usw.). (c) Das Ereignis trägt die Adresse.

**Empfehlung: (a), aber erst mit der MINOR-Version für die Review-Einladung (F-08).** (c) verbietet D8. (b) verstößt gegen R-2.6 und bricht bei jedem inneren Umbau. Version 1.0 braucht (a) nicht: `notify.user` adressiert nur über die Benutzer-ID (8.1), und die Review-Einladung kommt nach F-08 später. Die Lesefunktion MUSS eine Berechtigung prüfen, die Einwilligung mitliefern und darf nur zur Laufzeit aufgerufen werden (R-5.6). Die Spannung zu D9 löst sich wie bei W-3: „Kerne unverändert“ gilt für den Transport, nicht für additive Lese- und Befehlswege.

**Änderungen bei Annahme.** R-5.6 und der Absatz nach der `actor_user_id`-Tabelle in Abschnitt 6: „Eine Lesefunktion ‚Kontakt und Einwilligung‘ in Pass und Voucher kommt mit der MINOR-Version, die die Review-Einladung beschreibt (F-13); Version 1.0 adressiert nur über die Benutzer-ID.“ W-2 ist damit beantwortet.

## F-14 · Verlorene Ereignisse

**Frage.** Wird hingenommen, dass ein Ereignis verloren geht, wenn der Prozess zwischen dem Commit des Kerns und dem Speichern im Consumer abbricht?

**Optionen.** (a) Hinnehmen und im Handbuch nennen. (b) Eine Ausgangstabelle in jedem Kern.

**Empfehlung: (a).** (b) widerspricht D5 und D9 und hieße Umbauten in drei Kernen. Das Fenster ist klein: ein einziger Schreibvorgang im selben Prozess (R-7.6). R-7.2 verbietet schon, dass eine Pflicht des Betreibers allein an einem Workflow hängt. Später kann ein Adapter über die Lesefunktionen (F-11) nachsehen, ob ihm etwas fehlt.

**Änderungen bei Annahme.** R-7.2: „(F-14)“ durch „entschieden“ ersetzen und den Satz „Das Handbuch von Workflow Captain MUSS diese Grenze nennen“ anfügen. W-13 ist damit aufgelöst.

## F-15 · Zahlen für Wiederholung und Schleifenschutz

**Frage.** Gelten acht Versuche mit den Abständen aus 7.4 und die Tiefe 3 für Ketten?

**Optionen.** (a) Ja. (b) Andere Zahlen, zum Beispiel fünf Versuche oder Tiefe 5.

**Empfehlung: (a).** Acht Versuche nutzen schon Pass Captain und Affiliate Captain (7.4). Die Abstände decken rund 47 Stunden ab, also auch ein Wochenende mit gestörtem Cron. Tiefe 3 reicht für jede Kette in `konzept.md`. Das Schema erlaubt bis 8, eine spätere Anhebung bricht also nichts (R-7.13).

**Änderungen bei Annahme.** 7.4: „Die Abstände sind ein Vorschlag (F-15)“ streichen. R-7.13: „Die Zahl 3 ist ein Vorschlag (F-15)“ streichen.

## F-16 · Course Captain

**Frage.** Ist Course Captain ein Produkt der Suite mit Add-on und eigenem Präfix?

**Optionen.** (a) Jetzt entscheiden und im Lizenzserver vormerken. (b) Den Präfix `course.` nur reserviert lassen; die Produktfrage getrennt entscheiden.

**Empfehlung: (b).** Das Konzept von Course Captain plant `course-captain-pro` und den Präfix `course.` (`CC:konzept.md:8-10`), Code gibt es nicht. Für die Abnahme genügt die Reservierung: Ein Katalog `course.*` kommt ohnehin erst in einer MINOR-Version (4.6). Ein Eintrag im Lizenzserver wäre nach W-8 der falsche Ort und hat nach D12 keine Eile.

**Änderungen bei Annahme.** Keine. 4.6 bleibt; nur der Verweis „(F-16)“ wird „entschieden: nur reserviert“.

## F-18 · Kundenconnectoren im Umfang der ersten Version

**Frage.** Gehören die Connectoren für BoatingSchool und LearnBoating zu Workflow Captain 1.0?

**Optionen.** (a) Durch Entscheidung 17 erledigt: eigene Projektprodukte außerhalb von Workflow Captain. (b) Doch in Workflow Captain Pro.

**Empfehlung: (a), als entschieden eintragen.** Entscheidung 17 sagt das ausdrücklich. Zusammen mit F-01 (c) braucht die Laufzeit der Connectoren auch kein Workflow Pro (WF-E17-03, `WF:TODO.md:266`).

**Änderungen bei Annahme.** Keine an der Spezifikation (Abschnitt 1 nennt Kundenconnectoren schon als Nichtziel). In `SPEC-open-questions.md` den Vermerk „Entschieden am 16.11.2026 (Entscheidung 17)“ setzen.

## F-19 · Preise (außer Form Captain Pro)

**Frage.** Was kosten die übrigen Pro-Add-ons, insbesondere Workflow Captain Pro?

**Empfehlung: keine.** Das ist eine Geschäftsentscheidung. Die Spezifikation nennt bewusst keine Preise (Abschnitt 1, Nichtziele; 10.2). Die Abnahme hängt also nicht daran.

**Änderungen bei Annahme.** Keine.

---

## Widersprüche W-1 bis W-13

Die Widersprüche lösen sich mit den Fragen oben. Vorschlag: den in Teil 2 von `SPEC-open-questions.md` beschriebenen Umgang bestätigen.

| Nr. | erledigt durch | Vorschlag |
|---|---|---|
| W-1 | F-02 | bestätigen |
| W-2 | F-13, F-08 | Lesefunktion mit der MINOR-Version für die Review-Einladung |
| W-3 | – | bestätigen: „Kerne unverändert“ gilt für den Transport; die vier Einstiegspunkte (8.3) sind additiv |
| W-4 | – | bestätigen: Der Lizenzserver bekommt Vertragskonstante, „nach Commit“ und vier Actions, aber keinen Umschlag |
| W-5 | F-08, F-10 | bestätigen: Adapter für `license.*` in Workflow Captain Pro |
| W-6 | F-06 | Kernel-Erweiterung; für Lizenzereignisse genügt Workflow Pro |
| W-7 | F-10 | bestätigen |
| W-8 | F-01 | bestätigen: Rechte von Workflow Pro in der Katalogdatei des Add-ons |
| W-9 | F-03 | bestätigen; `_v1` im Namen bleibt daneben erlaubt |
| W-11 | F-12 | Mail-Übergabe in Form vor dem Release entfernen |
| W-13 | F-14 | bestätigen |

## Anhang: Stände, die sich seit draft.3 geändert haben

Das sind keine offenen Fragen, sondern Berichtigungen, die bei der Abnahme als PATCH mitlaufen können:

- **Affiliate-Vertrag ist 1.5.0** (`AC:affiliate-captain.php:53`, `AC:docs/CONTRACT.md:1`), nicht mehr 1.4.0 (Spezifikation 3.1, 4.4, R-13.6). Vorschlag: Mindestversion in R-13.6 bei 1.4.0 lassen, weil der Katalog nichts aus 1.5.0 braucht. Die Tabelle in 3.1 berichtigen. Die neue Action `wpac_affiliate_program_changed` (`AC:docs/CONTRACT.md:369`) bleibt vorerst außerhalb des Katalogs: Sie steht nicht in der Ereignistabelle mit der Zusage „nach Commit“ (`:210`), sondern feuert laut Text „nach dem Speichern“. Aufnahme als `affiliate.affiliate.program_changed` erst in einer MINOR-Version, wenn das belegt ist.
- **Lizenzserver:** Im gelesenen Stand stehen keine `workflow.*`-Schlüssel im Code (`LIC:src/Catalog/EntitlementCatalog.php:129-133`), nur die Aufgabe WFC-LIC-02 (`LIC:docs/TODO.md:723`). Die Fundstelle `:118-125` in 10.2 bezieht sich auf einen anderen Branch und sollte bei der Abnahme gegengeprüft werden.
