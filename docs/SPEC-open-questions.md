# Offene Fragen zur Suite-Spezifikation

Stand: 16.11.2026 (Abend), zu [`SPEC-suite-events.md`](SPEC-suite-events.md) Version 1.0.0-draft.3. Die Folgeentscheidungen D8b, D11b und D13 und die Preisbestätigung für Form Captain Pro sind eingearbeitet; erledigte Fragen und Widersprüche bleiben stehen und tragen den Vermerk „Entschieden am 16.11.2026“.

**Noch offen:** F-01 bis F-04, F-05a, F-06 bis F-10, F-12 (Teil Form und Review), F-13 bis F-16, F-18, F-19 (ohne Form) und W-1 bis W-9, W-11 (Teil Form), W-13.

Hier steht alles, was die Entscheidungen des Inhabers vom 16.11.2026 nicht festlegen. Jede Frage nennt, was die Spezifikation bis zur Antwort annimmt, und eine Empfehlung. Teil 2 nennt Widersprüche zwischen den Entscheidungen selbst.

Die Kürzel der Repositories und die gelesenen Stände stehen in Abschnitt 0 der Spezifikation.

## Teil 1: Fragen

### F-01 · Drei Workflow-Rechte ohne Vormerkung

- **Frage:** Sollen `workflow.custom_connectors`, `workflow.delays` und `workflow.multisite_network` Rechte von Workflow Captain Pro werden?
- **Stand:** `konzept.md` nennt neun Rechte als bestätigt. Der Lizenzserver merkt sechs vor (`LIC:src/Catalog/EntitlementCatalog.php:118-125`). `konzept.md` gibt Verzögerungen außerdem dem kostenlosen Kern und führt sie zugleich als Pro-Recht.
- **Annahme der Spezifikation:** Die drei sind „nicht reserviert, Entscheidung offen“ (Abschnitt 10.2).
- **Empfehlung:** `workflow.custom_connectors` und `workflow.multisite_network` als geplant vormerken, sobald ihre Funktionen in den Umfang der ersten Version kommen. `workflow.delays` streichen und Verzögerungen im kostenlosen Kern lassen; ein Workflow ohne Wartezeit ist für die meisten Abläufe unbrauchbar. Die Antwort hängt an D3 (F-10).

### F-02 · Öffentliche Kennungen fehlen in drei Providern

- **Frage:** Was ist die „öffentliche Kennung“ (D8) eines Gutscheins, einer Provision, eines Partners und einer Lizenz?
- **Stand:** Pass Captain hat `public_id` für Bestellung und Ticket. Voucher Captain kennt nur Datensatznummer, Code und Token (`VC:src/Voucher.php:19-48`). Affiliate Captain nutzt Datensatznummern. Der Lizenzserver hat `license_id` und `key_prefix`; `key_prefix` ist ein Teil des Schlüssels.
- **Annahme der Spezifikation:** `subject.id_kind` unterscheidet `public` und `record`. Wo es keine öffentliche Kennung gibt, steht die Datensatznummer (Abschnitt 6).
- **Empfehlung:** So bestätigen. Eine Datensatznummer ist kein Geheimnis, mit ihr allein lässt sich nichts einlösen, und alle Beteiligten laufen im selben WordPress. Neue öffentliche Kennungen in drei Kernen wären Umbauten, die D5 und D9 gerade vermeiden sollen. `key_prefix` nie verwenden.

### F-03 · Vertragskonstanten der übrigen Plugins und Abkündigungsfrist

- **Frage:** Nach welchem festen Muster heißen die Vertragskonstanten von Review Captain, Course Captain, dem Lizenzserver und Workflow Captain, und wie lange bleibt eine abgekündigte Action?
- **Stand:** Vorhanden sind `VCAP_CONTRACT_VERSION`, `WPCAP_CONTRACT_VERSION`, `WPAC_CONTRACT_VERSION` und `FORM_CAPTAIN_CONTRACT_VERSION` (Abschnitt 3.1). Die Präfixe folgen keinem gemeinsamen Muster.
- **Annahme der Spezifikation:** `<PREFIX>_CONTRACT_VERSION`, wobei `<PREFIX>` der Präfix der vorhandenen Versionskonstante ist.
- **Empfehlung:** Dieses Muster bestätigen, also `REVIEW_CAPTAIN_CONTRACT_VERSION`, `WPLC_CONTRACT_VERSION`, `COURSE_CAPTAIN_CONTRACT_VERSION` und `WORKFLOW_CAPTAIN_CONTRACT_VERSION`. Vorhandene Konstanten nicht umbenennen. Abkündigungsfrist: zwei MINOR-Versionen und mindestens sechs Monate.

### F-04 · Aufbewahrung im Ereignisspeicher

- **Frage:** Wie lange bleiben gespeicherte Ereignisse, und wann werden Benutzer-ID und Nutzdaten geleert?
- **Annahme der Spezifikation:** erledigte Ereignisse 30 Tage, `dead_letter` und `paused` 90 Tage; Benutzer-ID und Nutzdaten werden geleert, sobald kein Lauf mehr darauf wartet (R-12.4).
- **Empfehlung:** Diese Zahlen als Voreinstellung, vom Betreiber nur nach unten änderbar. Die Frist gehört in die Rechtsprüfung (A6).

### F-05 · Zahlen der Grenzen für die vier Gutschein-Befehle — **Entschieden am 16.11.2026**

- **Frage war:** Wie hoch sind die Grenzen für `voucher.issue`, `voucher.void`, `voucher.reactivate` und `voucher.redeem`?
- **Entscheidung des Inhabers:** Voreinstellungen im Voucher-Kern, der Betreiber kann sie nur senken, alle vier Befehle ab Werk ausgeschaltet.
  - `voucher.issue`: höchstens 100 EUR je Gutschein; 50 je Tag und Website; 1 je Tag und Empfänger.
  - `voucher.void` und `voucher.reactivate`: je 50 je Tag und Website.
  - `voucher.redeem`: **jeder** Gutschein, nicht nur ein selbst ausgestellter; höchstens 100 EUR je Einlösung; 50 je Tag und Website.
- **In der Spezifikation:** Tabelle in Abschnitt 8.3.1. Die frühere Empfehlung dieses Dokuments (50, 20, nur selbst ausgestellte Gutscheine, ein Wechsel je Gutschein und Tag) ist damit überholt.

### F-05a · Höchstbeträge in Shops ohne Euro

- **Frage:** Die Höchstbeträge sind in Euro entschieden. Was gilt in einem Shop mit anderer Währung?
- **Annahme der Spezifikation:** Ohne eigenen Eintrag des Betreibers lehnt der Einstiegspunkt Beträge in einer anderen Währung ab (Abschnitt 8.3.1).
- **Empfehlung:** Der Kern rechnet nicht um und kennt keine Wechselkurse. Er führt je Währung einen festen Höchstbetrag: für EUR 100; für andere Währungen dieselbe Zahl 100 in der Shopwährung nur für eine kurze, im Kern gepflegte Liste von Währungen mit ähnlichem Wert (zum Beispiel CHF, USD, GBP), sonst muss der Betreiber den Betrag einmal selbst eintragen, und bis dahin bleiben `voucher.issue` und `voucher.redeem` aus. Hängt an der offenen Entscheidung zu mehreren Währungen (G6).

### F-06 · Eine Funktion braucht das Recht eines anderen Add-ons

- **Frage:** Wie prüft eine Funktion in einem Add-on die Lizenz eines anderen Add-ons, und wie arbeitet ein Add-on mit mehreren Kernen?
- **Stand:** Der Kernel kennt je Add-on einen Kern und einen Präfix (`CP:packages/addon-kernel/src/CoreRequirement.php:60-64`); `Feature::requires()` beschreibt nur Anforderungen an den eigenen Kern (`CP:packages/addon-kernel/src/Feature.php:41-52`). D6 vermerkt die Lücke selbst.
- **Empfehlung:** Zwei Erweiterungen in `captain-pro`: (1) `requires()` bekommt einen Eintrag für weitere Kerne mit Versions- und Vertragskonstante; (2) eine gemeinsame, nur lesende Auskunft des Kernels, ob ein anderes Add-on auf dieser Website eine gültige Lizenz hat, ohne dass ein Add-on den Lizenzstatus eines anderen verändern oder dessen Schlüssel lesen kann. Beides vor dem ersten Adapter bauen.

### F-07 · Multisite

- **Frage:** Wie verhalten sich Ereignisse in einem WordPress-Netzwerk?
- **Stand:** Entschieden ist nur, dass jede Unterseite einen Platz der Lizenz belegt (B2). `konzept.md` plant netzwerkweite Abläufe von Website A nach Website B.
- **Annahme der Spezifikation:** Alles gilt je Website. Ereignisse verlassen ihre Website nicht; netzwerkweite Abläufe sind nicht Teil dieser Fassung (Abschnitt 1, R-12.6).
- **Empfehlung:** So für Version 1 bestätigen und die netzwerkweiten Abläufe auf eine spätere MINOR-Version dieser Spezifikation verschieben. Sie brauchen eigene Regeln für Freigabe, Datenschutz und Lizenzplätze.

### F-08 · In welchem Add-on liegt ein Adapter

- **Frage:** Liegt der Adapter für ein Produkt in Workflow Captain Pro oder im Add-on des Produkts? Und wer plant eine Review-Einladung: ein Workflow oder Review Captain Pro?
- **Stand:** D7 sagt „nur in Add-ons“. `konzept.md` legt alle Suite-Provider in Workflow Captain Pro. Review Captain plant eigene Adapter in Review Captain Pro (`RC:docs/contracts/workflow-provider-v1.md`).
- **Empfehlung:** Adapter für Workflow liegen in Workflow Captain Pro, je Produkt eine Funktion, die zusätzlich die Lizenz des Produkt-Add-ons verlangt (F-06). So gibt es eine Stelle, die den Umschlag kennt. Die Review-Einladung aus Einlass und Einlösung plant in der ersten Version der Adapter in Review Captain Pro nach denselben Regeln (Katalog, verbotene Werte); ein Workflow bekommt die Review-Ereignisse später als Auslöser.

### F-09 · Was heißt „Pro-Lizenz des Produkts“

- **Frage:** Genügt irgendeine gültige Lizenz des Produkt-Add-ons, oder braucht es ein eigenes Recht wie `pass.suite_integrations`?
- **Stand:** D6 sagt „Pro-Lizenz des Produkt-Add-ons“. Ein Recht `<produkt>.suite_integrations` gibt es nur als Vorschlag bei Review Captain.
- **Empfehlung:** Eine gültige Lizenz des Produkt-Add-ons genügt; keine neuen Rechte je Produkt. Alle Tarife haben denselben Funktionsumfang, ein zusätzliches Recht würde nichts unterscheiden.

### F-10 · Grenze zwischen kostenlosem Kern und Pro (D3)

- **Frage:** Verarbeitet der kostenlose Workflow-Kern Ereignisse der Captain-Produkte, und wer verarbeitet Lizenzereignisse im Shop?
- **Stand:** D3 ist nicht beantwortet. D6 und D7 legen aber fest, dass jede Automation mit einem Captain-Produkt Workflow Pro braucht und Adapter nur in Add-ons liegen.
- **Annahme der Spezifikation:** Der kostenlose Kern enthält den Consumer (Speicher, Wiederholung, Umschlag), aber keinen Adapter. Ohne Workflow Pro kommt kein Suite-Ereignis an.
- **Empfehlung:** Genau so bestätigen. Offen bleibt dann nur, was der kostenlose Kern für WordPress und WooCommerce kann.

### F-11 · Der vorläufige Workflow-Provider im Affiliate-Kern — **Entschieden am 16.11.2026 (D13)**

- **Frage war:** Was geschieht mit `wpac_workflow_event` und `wpac_workflow_read()`, die der Affiliate-Kern bereits enthält? Zur Wahl standen: nicht normativ behalten (a), als Quelle der Suite übernehmen (b), vor dem Release abschalten (c).
- **Umgesetzt im Affiliate-Kern** (Commit `a928a14`, Stand `23f06be`, Wächtertest, Wiedereinführung als AFF-WF-08; 0.15.0 erscheint ohne Provider).
- **Entscheidung:** Die vorläufigen Workflow-Provider werden vor dem Release aus den kostenlosen Kernen genommen und auf einem geparkten Branch aufbewahrt: im Affiliate-Kern `wpac_workflow_event`, `wpac_workflow_read()` und `docs/WORKFLOW-PROVIDER.md`; im Voucher-Kern der Provider aus Pull Request 16. Sie kommen zurück, wenn diese Spezifikation abgenommen ist.
- **In der Spezifikation:** Abschnitt 4.4.1, R-4.4 bis R-4.6. `wpac_workflow_read()` ist keine anerkannte Lesefunktion mehr.
- **Folgefrage, offen:** In welcher Form die Provider-Seiten zurückkehren. Empfehlung: nur als Lesefunktionen (für Gegenlesen und Empfängerauflösung, F-13), nicht als eigener Umschlag; dafür eine MINOR-Version dieser Spezifikation.

### F-12 · Eigene Workflow-Verträge in Form Captain und Review Captain — **für Voucher entschieden am 16.11.2026 (D13); für Form und Review offen**

- **Frage:** Was geschieht mit den Verträgen, die Form Captain und Review Captain auf ihren Branches schon gebaut oder beschrieben haben?
- **Stand:** Form Captain hat eine Übergabe von Mails an einen Workflow mit Rückfall abgehakt (`FC:TODO.md:235`), was D10 widerspricht. Review Captain beschreibt einen eigenen Descriptor, eigene Nutzdaten und eine Rechteprüfung je Auslöser (`RC:docs/contracts/workflow-provider-v1.md`).
- **Entschieden (D13) für den Voucher-Kern:** Der Provider aus Pull Request 16 mit Lizenzwissen und Mail-Übergabe wird vor dem Release entfernt.
- **Empfehlung für Form und Review:** Genauso verfahren wie D13 für Affiliate und Voucher: nicht normativ, vor dem ersten Release herausnehmen. In Form Captain die Übergabe der Mails entfernen oder abschalten. Die Kataloge für `form.*` und `review.*` kommen in einer MINOR-Version dieser Spezifikation, bevor einer der beiden Kerne veröffentlicht wird.

### F-13 · Empfänger ohne Benutzerkonto

- **Frage:** Wie erreicht eine Review-Einladung den Gast eines Tickets oder den Empfänger eines Gutscheins, wenn es kein WordPress-Konto gibt und das Ereignis keine E-Mail-Adresse tragen darf?
- **Stand:** Beim Einlass ist die Benutzer-ID die des Personals (`PC:src/Checkin.php:153`). Einen Abruf „Kontakt zu diesem Ticket“ für andere Plugins beschreibt Pass Captain nicht als Vertrag.
- **Annahme der Spezifikation:** Der Adapter liest den Empfänger zur Laufzeit über die Lesefunktionen des Providers und speichert ihn nicht (R-5.6).
- **Empfehlung:** In Pass Captain und Voucher Captain je eine kleine, dokumentierte Lesefunktion „Kontakt und Einwilligung zu dieser Kennung“ als additive Vertragsänderung vorsehen. Das ist eine Änderung an den Kernen und steht damit in Spannung zu D9 (W-2); ohne sie müsste der Adapter interne Klassen nutzen.

### F-14 · Verlorene Ereignisse

- **Frage:** Wird hingenommen, dass ein Ereignis verloren geht, wenn der Prozess zwischen dem Commit des Kerns und dem Speichern im Consumer abbricht?
- **Annahme der Spezifikation:** ja (R-7.2).
- **Empfehlung:** Hinnehmen für Version 1 und im Handbuch sagen. Später kann ein Adapter über die Lesefunktionen nachsehen, ob ihm etwas fehlt; dafür braucht kein Kern eine Ausgangstabelle.

### F-15 · Zahlen für Wiederholung und Schleifenschutz

- **Frage:** Gelten acht Versuche mit den Abständen aus Abschnitt 7.4 und die Tiefe 3 für Ketten?
- **Empfehlung:** Ja. Acht Versuche nutzen Pass Captain und Affiliate Captain schon. Tiefe 3 reicht für jede Kette, die `konzept.md` beschreibt.

### F-16 · Course Captain

- **Frage:** Ist Course Captain ein Produkt der Suite mit Add-on und eigenem Präfix?
- **Stand:** Der Auftrag für diese Spezifikation nennt `course-captain-pro`. Der Lizenzserver kennt den Schlüssel nicht (`LIC:src/Catalog/EntitlementCatalog.php:179-183`), und die stehende Regel der Produktschlüssel nennt nur Form, Review und Workflow als geplant.
- **Annahme der Spezifikation:** Der Präfix `course.` ist reserviert, mehr nicht (Abschnitt 4.6).
- **Empfehlung:** Erst entscheiden, dann im Lizenzserver vormerken.

### F-17 · Zählt ein öffentlicher Partnercode als „Code“ — **Entschieden am 16.11.2026 (D8b)**

- **Frage war:** D8 sagt „keine Codes“. Gilt das auch für den öffentlichen Partnercode von Affiliate Captain?
- **Entscheidung:** „Keine Codes“ meint nur geheime Codes (Gutscheincode, Ticketcode, Lizenzschlüssel, Token). Der öffentliche Partnercode und Geldbeträge mit Währung sind in den Nutzdaten erlaubt.
- **In der Spezifikation:** R-5.1a, Katalog 4.4 (`affiliate.code_request.*` mit `partner_code`; Beträge als `amount_minor` mit `currency`), Schema und zwei Beispiele.

### F-18 · Kundenconnectoren im Umfang der ersten Version

- **Frage:** Bleiben die Connectoren für BoatingSchool und LearnBoating Aufgaben von Workflow Captain 1.0?
- **Stand:** `TODO.md` führt 19 Aufgaben dafür. Nach E6 ist der Learnboating-Connector ein eigenes Kundenprojekt und kein Teil von Voucher Pro.
- **Empfehlung:** Beide als Kundenprojekte außerhalb der ersten Version führen; die Spezifikation regelt sie nicht.

### F-19 · Preise — **für Form Captain Pro entschieden am 16.11.2026; sonst offen**

- **Entschieden:** Form Captain Pro kostet 59, 129 und 249 Euro pro Jahr brutto für 1, 5 und 25 Websites (`single`, `business`, `agency`).
- **Offen:** die Preise aller anderen Produkte. `konzept.md` und `TODO.md` dieses Repositorys nennen für Workflow Captain Pro 129, 229 und 399 Euro als festgelegt; bestätigt ist das nicht, auch nicht netto oder brutto.
- **Empfehlung:** Keine; Geschäftsentscheidung. Bis dahin gelten die Beträge für Workflow als Vorschlag.

## Teil 2: Widersprüche zwischen den Entscheidungen vom 16.11.2026

| Nr. | Entscheidungen | Widerspruch | Wie die Spezifikation damit umgeht |
|---|---|---|---|
| W-1 | D8 gegen D5 und D9 | D8 verlangt öffentliche Kennungen. Voucher Captain, Affiliate Captain und der Lizenzserver haben keine. D5 und D9 lassen die Kerne unverändert | Datensatznummer mit `id_kind` = `record` (F-02) |
| W-2 | D7 und D8 gegen D9 | Die Review-Einladung soll aus Einlass und Einlösung entstehen. Die Nutzdaten dürfen keine E-Mail-Adresse tragen, und der Gast hat oft kein Benutzerkonto. Der Empfänger ist nur über eine Lesefunktion des Kerns erreichbar, die es als Vertrag nicht gibt | Auflösung zur Laufzeit (R-5.6); Lesefunktion als additive Änderung empfohlen (F-13) |
| W-3 | D11 und D11b gegen D5 und D9 | D11 verlangt Grenzen und Missbrauchsschutz **im Voucher-Kern**. D5 und D9 sagen, nur der Abnehmer werde neu gebaut und die Kerne blieben unverändert | Der Voucher-Kern bekommt vier neue Einstiegspunkte (Abschnitt 8.3). „Unverändert“ gilt für den Transport der Ereignisse |
| W-4 | D2 gegen D5 und D9 | D2 verlangt vom Lizenzserver versionierte Ereignisse mit Ereigniskennung. D5 und D9 sagen, der Transport seien die vorhandenen Hooks und Kennung und Umschlag setze der Abnehmer | Der Lizenzserver braucht Vertragskonstante, „nach Commit“, Actions ohne Schlüssel und vier neue Actions, aber keinen Umschlag und keine Kennung (Abschnitt 11) |
| W-5 | D2 und D7 gegen die Regel „Kern ohne Lizenzwissen“ | Im Shop verarbeitet Workflow Captain Lizenzereignisse. Der kostenlose Kern darf nichts über Lizenzen wissen, und der Lizenzserver hat kein Add-on, in dem ein Adapter liegen könnte | Der Adapter liegt in Workflow Captain Pro; der Consumer behandelt `license.*` wie jeden anderen Typ (R-11.2) |
| W-6 | D6 gegen den Stand des Kernels | Zwei Lizenzen je Automation, geprüft nur in Add-ons. Der Kernel kann das nicht (D6 vermerkt es selbst). Für Lizenzereignisse gibt es kein zweites Add-on | Aufgabe für `captain-pro` (F-06); für Lizenzereignisse genügt Workflow Pro (Abschnitt 10.1) |
| W-7 | D6 und D7 ohne D3 | D6 und D7 nehmen die Antwort auf D3 teilweise vorweg: Jede Automation mit einem Captain-Produkt braucht Workflow Pro. D3 gilt aber als offen | F-10 |
| W-8 | D12 gegen E8 und G2 | Der Lizenzserver soll vor dem Verkaufsstart keine fest verdrahteten Captain-Schlüssel mehr enthalten (G2, E8). Die sechs vorgemerkten `workflow.*`-Rechte stehen genau dort. Sie wandern in die Katalogdatei des Add-ons, das nach D12 erst später entsteht | Abschnitt 10.2 nennt den heutigen Ort; nach dem Umbau liefert `workflow-captain-pro` die Schlüssel in seiner `wplc-catalog.json` |
| W-9 | D4 gegen den Stand | „Vertragskonstante je Kern, wie Affiliate, Voucher und Pass sie haben“: Der Lizenzserver wird durch D2 Provider und hat keine. Pass Captain versioniert zusätzlich über `_v1` im Namen | R-3.1 gilt für jeden Provider (F-03); `_v1` im Namen bleibt daneben erlaubt |
| W-10 **(entschieden am 16.11.2026, D13)** | D5 und D9 gegen C5 und den Stand in Affiliate | Entschieden war, dass die Workflow-Schnittstelle des Affiliate-Kerns auf die Workflow-Antworten wartet (C5). Sie wurde vorher nach `main` gemergt (`AC:TODO.md`, E9) und baut einen Umschlag im Kern | aufgelöst: Der Provider wird vor dem Release entfernt (F-11) |
| W-11 **(für Voucher entschieden, D13; für Form offen)** | D10 gegen den Stand in Form Captain | Keine Übergabe von Mails. Form Captain hat sie auf seinem Branch gebaut | F-12 |
| W-12 **(entschieden am 16.11.2026: D11b und Antwort zu F-05)** | D11 und D11b in sich | D11b legt vier Befehle mit Grenzen fest, nennt aber weder Zahlen noch den Bezug von „je Tag“ (Website, Empfänger, Gutschein) und nicht, welche Gutscheine ein Workflow einlösen darf | aufgelöst: Zahlen und Bezug stehen in Abschnitt 8.3.1; offen bleibt nur die Währung (F-05a) |
| W-13 | D9 gegen D7 | „Speichern und Wiederholen beim Abnehmer“ garantiert nichts vor dem Speichern. Die Review-Einladung hängt an genau diesen Ereignissen | Verlust wird hingenommen und benannt (R-7.2, F-14) |
