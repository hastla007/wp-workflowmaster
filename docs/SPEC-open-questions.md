# Offene Fragen zur Suite-Spezifikation

Stand: 16.11.2026 (Abend), zu [`SPEC-suite-events.md`](SPEC-suite-events.md) Version 1.0.0-draft.6. Die Folgeentscheidungen D8b, D11b und D13, Entscheidung 17 (Kundenconnectoren) und die Preisbestätigung für Form Captain Pro sind eingearbeitet; erledigte Fragen und Widersprüche bleiben stehen und tragen den Vermerk „Entschieden am 16.11.2026“.

**Noch offen:** F-02, F-03, F-06, F-08 bis F-10, F-14, F-16, F-19 (ohne Form), F-21, F-24 (neu am 23.11.2026) und W-1 bis W-9, W-13. **Am 23.11.2026 entschieden:** F-04, F-05a, F-13, F-15, F-23 (ja: Review-Befehle, Spezifikation 1.0.0-draft.9). **Am 16.11.2026 entschieden:** F-01, F-07, F-12 (Form und Review), F-18a, F-20, F-22, W-11; dazu: Lizenzserver liest über `LicenseReader` (Branch `claude/zen-babbage-7q4nac`), die eigene Lesefassade `ReadFacade` ist zurückgezogen; Pass Captain 1.6.0 und Voucher Captain 1.3.0 erhöhen ihre Plugin-Version für die neuen Lesefunktionen.

Hier steht alles, was die Entscheidungen des Inhabers vom 16.11.2026 nicht festlegen. Jede Frage nennt, was die Spezifikation bis zur Antwort annimmt, und eine Empfehlung. Teil 2 nennt Widersprüche zwischen den Entscheidungen selbst.

Die Kürzel der Repositories und die gelesenen Stände stehen in Abschnitt 0 der Spezifikation.

## Teil 1: Fragen

### F-01 · Drei Workflow-Rechte ohne Vormerkung — **entschieden am 16.11.2026**

**Entscheidung:** `workflow.custom_connectors` und `workflow.multisite_network` gehören zu Workflow Captain Pro (wie empfohlen); `workflow.delays` bleibt ohne Recht (Verzögerungen sind im kostenlosen Kern).

**Stand 16.11.2026:** `workflow.custom_connectors` und `workflow.multisite_network` sind im Lizenzserver als geplant vorgemerkt (wp-license, Branch `claude/plugin-todo-liste-2a83v4`, `410ca78`), wie empfohlen; `workflow.delays` nicht. Vom Inhaber am 16.11.2026 abends ausdrücklich bestätigt.

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

### F-04 · Aufbewahrung im Ereignisspeicher — **entschieden am 23.11.2026**

**Entscheidung der Inhaberin („mach was du empfiehlst“, E-S7 in `SETTINGS-ANALYSE.md`):** Vorgaben 30 Tage (erledigt) und 90 Tage (fehlgeschlagen) bleiben; der Betreiber kann bis 180 Tage frei verlängern, darüber nur mit Workflow Captain Pro (`workflow.extended_logs`). Fristen mit rechtlichem Gewicht bleiben Sache der Rechtsprüfung.


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

### F-05a · Höchstbeträge in Shops ohne Euro — **entschieden am 23.11.2026**

**Entscheidung (gemeldet aus der Gutschein-Sitzung):** Höchstbetrag 100 auch in CHF, USD und GBP; in anderen Währungen trägt der Betreiber den Betrag selbst ein, bis dahin bleiben die Befehle aus. Umsetzung: wp-couponmaster Pull Request #20.


- **Frage:** Die Höchstbeträge sind in Euro entschieden. Was gilt in einem Shop mit anderer Währung?
- **Annahme der Spezifikation:** Ohne eigenen Eintrag des Betreibers lehnt der Einstiegspunkt Beträge in einer anderen Währung ab (Abschnitt 8.3.1).
- **Empfehlung:** Der Kern rechnet nicht um und kennt keine Wechselkurse. Er führt je Währung einen festen Höchstbetrag: für EUR 100; für andere Währungen dieselbe Zahl 100 in der Shopwährung nur für eine kurze, im Kern gepflegte Liste von Währungen mit ähnlichem Wert (zum Beispiel CHF, USD, GBP), sonst muss der Betreiber den Betrag einmal selbst eintragen, und bis dahin bleiben `voucher.issue` und `voucher.redeem` aus. Hängt an der offenen Entscheidung zu mehreren Währungen (G6).

### F-06 · Eine Funktion braucht das Recht eines anderen Add-ons

- **Frage:** Wie prüft eine Funktion in einem Add-on die Lizenz eines anderen Add-ons, und wie arbeitet ein Add-on mit mehreren Kernen?
- **Stand:** Der Kernel kennt je Add-on einen Kern und einen Präfix (`CP:packages/addon-kernel/src/CoreRequirement.php:60-64`); `Feature::requires()` beschreibt nur Anforderungen an den eigenen Kern (`CP:packages/addon-kernel/src/Feature.php:41-52`). D6 vermerkt die Lücke selbst.
- **Empfehlung:** Zwei Erweiterungen in `captain-pro`: (1) `requires()` bekommt einen Eintrag für weitere Kerne mit Versions- und Vertragskonstante; (2) eine gemeinsame, nur lesende Auskunft des Kernels, ob ein anderes Add-on auf dieser Website eine gültige Lizenz hat, ohne dass ein Add-on den Lizenzstatus eines anderen verändern oder dessen Schlüssel lesen kann. Beides vor dem ersten Adapter bauen.

### F-07 · Multisite — **entschieden am 16.11.2026**

**Entscheidung:** Netzwerkweite Abläufe werden jetzt gebaut (Spezifikation Abschnitt 17, Workflow Captain Pro).

**Entschieden am 16.11.2026:** Netzwerkweite Abläufe werden jetzt gebaut (Abschnitt 17 der Spezifikation, Pro, Recht `workflow.multisite_network`).

- **Frage:** Wie verhalten sich Ereignisse in einem WordPress-Netzwerk?
- **Stand:** Entschieden ist nur, dass jede Unterseite einen Platz der Lizenz belegt (B2). `konzept.md` plant netzwerkweite Abläufe von Website A nach Website B.
- **Annahme der Spezifikation:** Alles gilt je Website. Ereignisse verlassen ihre Website nicht; netzwerkweite Abläufe sind nicht Teil dieser Fassung (Abschnitt 1, R-12.6).
- **Empfehlung:** So für Version 1 bestätigen und die netzwerkweiten Abläufe auf eine spätere MINOR-Version dieser Spezifikation verschieben. Sie brauchen eigene Regeln für Freigabe, Datenschutz und Lizenzplätze.

### F-08 · In welchem Add-on liegt ein Adapter

- **Frage:** Liegt der Adapter für ein Produkt in Workflow Captain Pro oder im Add-on des Produkts? Und wer plant eine Review-Einladung: ein Workflow oder Review Captain Pro?
- **Stand:** D7 sagt „nur in Add-ons“. `konzept.md` legt alle Suite-Provider in Workflow Captain Pro. Review Captain plant eigene Adapter in Review Captain Pro (`RC:docs/contracts/workflow-provider-v1.md`).
- **Stand 16.11.2026 (Spezifikation 1.0.0-draft.8):** Review Captain hat auf `main` (`863b92b`) die Kern-Schnittstelle `plan_request()`/`cancel_request()`, die an einen Planer von Review Captain Pro delegiert (Filter `review_captain_request_planner_v1`); Review Captain Pro (Branch `claude/review-captain-pro`, Pull Request 10) meldet noch keinen Planer an. Der Katalog `review.*` (4.6.3) steht; der Adapter liegt in Workflow Captain Pro (`ReviewAdapter`) und verlangt zusätzlich Review Captain Pro. Ob ein Workflow Einladungen planen darf, ist F-23.
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

### F-12 · Eigene Workflow-Verträge in Form Captain und Review Captain — **entschieden am 16.11.2026: für Voucher D13, für Form und Review wie D13**

**Entscheidung für Form und Review:** wie D13. Form Captain nimmt die Übergabe von Mails an Workflow Captain heraus bzw. schaltet sie ab; der beschriebene Vertrag von Review Captain wird als nicht normativ gekennzeichnet. Normativ ist nur der Suite-Vertrag dieser Spezifikation.

- **Frage:** Was geschieht mit den Verträgen, die Form Captain und Review Captain auf ihren Branches schon gebaut oder beschrieben haben?
- **Stand:** Form Captain hat eine Übergabe von Mails an einen Workflow mit Rückfall abgehakt (`FC:TODO.md:235`), was D10 widerspricht. Review Captain beschreibt einen eigenen Descriptor, eigene Nutzdaten und eine Rechteprüfung je Auslöser (`RC:docs/contracts/workflow-provider-v1.md`).
- **Entschieden (D13) für den Voucher-Kern:** Der Provider aus Pull Request 16 mit Lizenzwissen und Mail-Übergabe wird vor dem Release entfernt.
- **Empfehlung für Form und Review:** Genauso verfahren wie D13 für Affiliate und Voucher: nicht normativ, vor dem ersten Release herausnehmen. In Form Captain die Übergabe der Mails entfernen oder abschalten. Die Kataloge für `form.*` und `review.*` kommen in einer MINOR-Version dieser Spezifikation, bevor einer der beiden Kerne veröffentlicht wird.

### F-13 · Empfänger ohne Benutzerkonto — **entschieden am 23.11.2026**

**Entscheidung (gemeldet aus der Gutschein-Sitzung):** „Ja, nach Rechtsprüfung“. Je eine dokumentierte Lesefunktion für Kontakt und Einwilligung, ab Werk aus: Voucher Captain Vertrag 1.5.0 (wp-couponmaster Pull Request #20), Pass Captain mit 1.7.0 (PASS-E17-01).


- **Frage:** Wie erreicht eine Review-Einladung den Gast eines Tickets oder den Empfänger eines Gutscheins, wenn es kein WordPress-Konto gibt und das Ereignis keine E-Mail-Adresse tragen darf?
- **Stand:** Beim Einlass ist die Benutzer-ID die des Personals (`PC:src/Checkin.php:153`). Einen Abruf „Kontakt zu diesem Ticket“ für andere Plugins beschreibt Pass Captain nicht als Vertrag.
- **Annahme der Spezifikation:** Der Adapter liest den Empfänger zur Laufzeit über die Lesefunktionen des Providers und speichert ihn nicht (R-5.6).
- **Empfehlung:** In Pass Captain und Voucher Captain je eine kleine, dokumentierte Lesefunktion „Kontakt und Einwilligung zu dieser Kennung“ als additive Vertragsänderung vorsehen. Das ist eine Änderung an den Kernen und steht damit in Spannung zu D9 (W-2); ohne sie müsste der Adapter interne Klassen nutzen.

### F-14 · Verlorene Ereignisse

- **Frage:** Wird hingenommen, dass ein Ereignis verloren geht, wenn der Prozess zwischen dem Commit des Kerns und dem Speichern im Consumer abbricht?
- **Annahme der Spezifikation:** ja (R-7.2).
- **Empfehlung:** Hinnehmen für Version 1 und im Handbuch sagen. Später kann ein Adapter über die Lesefunktionen nachsehen, ob ihm etwas fehlt; dafür braucht kein Kern eine Ausgangstabelle.

### F-15 · Zahlen für Wiederholung und Schleifenschutz — **entschieden am 23.11.2026**

**Entscheidung der Inhaberin (E-S5):** acht Versuche mit den Abständen aus 7.4 und Tiefe 3 als Vorgabe; beides vom Betreiber einstellbar zwischen 1 und 8 (SET-WC-09).


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

### F-18 · Kundenconnectoren im Umfang der ersten Version — **Entschieden am 16.11.2026 (Entscheidung 17)**

- **Frage:** Bleiben die Connectoren für BoatingSchool und LearnBoating Aufgaben von Workflow Captain 1.0?
- **Stand:** `TODO.md` führt 19 Aufgaben dafür. Nach E6 ist der Learnboating-Connector ein eigenes Kundenprojekt und kein Teil von Voucher Pro. **Entschieden am 16.11.2026 (Entscheidung 17):** Alle Connectoren von BoatingSchool und LearnBoating sind eigene, nicht verkaufte Projektprodukte mit eigener Lizenz, außerhalb von Workflow Captain und Workflow Captain Pro. Die Aufgaben dazu stehen in `TODO.md` (WF-E17-01 bis -03).
- **Bestätigt am 16.11.2026 auf Rückfrage:** Es gilt die Fassung „eigene Projektprodukte mit eigener Lizenz“. Eine abweichende Fassung („in Workflow Captain Pro enthalten“, mit Antworten O1 bis O3) steht auf dem nicht gemergten Branch `claude/sleepy-ptolemy-n32guj` dieses Repositorys und in `captain-pro` auf `claude/youthful-bardeen-7ejd7a` (`dc70c7d`, `60be620`); sie gilt nicht und darf nicht gemergt werden, ohne sie vorher anzugleichen (W-14).
- **Empfehlung:** Beide als Kundenprojekte außerhalb der ersten Version führen; die Spezifikation regelt sie nicht. Die Entscheidung folgt der Empfehlung. Die Aufgaben gehen an die Projekt-Repositories (`docs/UEBERGABE-E17-kundenconnectoren.md`).

### F-18a · Setzt ein Kundenconnector Workflow Captain Pro voraus? — **entschieden am 16.11.2026**

**Entscheidung:** Ja. Kundenconnectoren brauchen Workflow Captain Pro mit dem Recht `workflow.custom_connectors` als Laufzeit. Der Kern bietet dafür den Filter `workflow_captain_connector_runtime_allowed_v1` (Ergebnis `runtime_not_allowed`); Pro hängt sich ein. Ihre eigene Lizenz prüft jeder Connector selbst (Entscheidung 17).

- **Frage:** Ein Connector für BoatingSchool oder LearnBoating hat nach Entscheidung 17 seine eigene Lizenz. Braucht er auf der Website zusätzlich ein installiertes und lizenziertes Workflow Captain Pro, oder genügt der kostenlose Kern `workflow-captain`?
- **Stand:** `konzept.md` 16.1 und WF-CONNECTOR-12 binden die Ausführung eigener Connectoren an das Recht `workflow.custom_connectors` von Workflow Captain Pro. Dieses Recht ist nicht reserviert (F-01). WF-CUST2-02 nennt eine „Lizenz-/Capability-Prüfung“ ohne zu sagen, welche Lizenz.
- **Annahme bis zur Antwort:** Der Connector prüft nur seine eigene Lizenz. Ob er Workflow Captain Pro braucht, ist eine technische Abhängigkeit (wo laufen Connector-Ausführung und Webhook-Verbindungen?), keine Deckung durch die Pro-Lizenz.
- **Empfehlung:** Die Antwort an F-01 koppeln. Wird `workflow.custom_connectors` ein Recht von Workflow Captain Pro, braucht jeder fremde Connector Workflow Captain Pro als Laufzeit, auch ein Projektprodukt; das hält die Regel „Pro-Funktionen nur mit Pro“ einfach. Soll ein Kunde ohne Workflow Captain Pro auskommen, muss die Connector-Ausführung in den kostenlosen Kern, und `workflow.custom_connectors` entfällt. Für LearnBoating (lokal, ohne Webhook) wäre der kostenlose Kern technisch ausreichend; BoatingSchool braucht die geschützte Webhook-Verbindung (`workflow.webhooks`, Pro).
- **Danach:** WF-CUST2-02 (im Übergabedokument) und WF-CONNECTOR-12 nach der Antwort umformulieren (`TODO.md` WF-E17-03).

### F-19 · Preise — **für Form Captain Pro entschieden am 16.11.2026; sonst offen**

- **Entschieden:** Form Captain Pro kostet 59, 129 und 249 Euro pro Jahr netto, zzgl. USt., für 1, 5 und 25 Websites (`single`, `business`, `agency`; Entscheidung des Inhabers vom 16.11.2026, berichtigt von brutto auf netto).
- **Offen:** die Preise aller anderen Produkte. `konzept.md` und `TODO.md` dieses Repositorys nennen für Workflow Captain Pro 129, 229 und 399 Euro als festgelegt; bestätigt sind die Beträge nicht. Entschieden am 16.11.2026: Alle Preise aller Pro-Add-ons und Tarife sind netto, zzgl. USt.
- **Empfehlung:** Keine; Geschäftsentscheidung. Bis dahin gelten die Beträge für Workflow als Vorschlag.

### F-20 · Format für Ereignisse aus WordPress und WooCommerce an externe Ziele — **entschieden am 16.11.2026**

**Entscheidung:** WordPress- und WooCommerce-Auslöser gehören in den kostenlosen Kern (Spezifikation 4.7).

**Entschieden am 16.11.2026:** wie empfohlen, im kostenlosen Kern (Abschnitt 4.7 der Spezifikation).

- **Frage:** In welchem Format sendet Workflow Captain Ereignisse aus WordPress und WooCommerce (zum Beispiel „Bestellung bezahlt“) an n8n und andere Webhook-Ziele?
- **Stand:** Der Umschlag dieser Spezifikation gilt nur für Ereignisse der Captain-Produkte; das Schema erlaubt in `type` nur deren Präfixe. `konzept.md` 17.12 nennt „WooCommerce-Bestellung bezahlt → n8n“ als Szenario der Version 1.
- **Empfehlung:** Denselben Umschlag verwenden und im Schema die Präfixe `wordpress` und `woocommerce` zulassen (`source.product` = `wordpress` bzw. `woocommerce`, `contract_version` = Version von WordPress bzw. WooCommerce). Die erlaubten Felder je Typ legt dann eine MINOR-Version dieser Spezifikation fest; die Regeln für verbotene Werte gelten unverändert. So brauchen Empfänger nur ein Format.

### F-21 · Ist ein beantragter Partnercode ein „öffentlicher Partnercode“?

- **Frage:** D8b erlaubt den öffentlichen Partnercode in den Nutzdaten. Gilt das auch für einen Code, den ein Partner nur **beantragt** hat (`wpac_code_requested`) oder dessen Antrag abgelehnt wurde (`wpac_code_request_rejected`)?
- **Stand:** Ein beantragter Code ist noch nicht aktiv und wird vom Partner frei gewählt; das Beispiel im Kern ist „MARIA10“, er kann also einen Namen enthalten. Nach einer Ablehnung ist er wieder frei (geprüft am 16.11.2026, `AC:src/Codes/CodeRequests.php:270`, `:524`).
- **Annahme der Spezifikation:** `partner_code` nur bei `affiliate.code_request.approved`; bei `created` und `rejected` nicht (4.4).
- **Empfehlung:** So bestätigen. Öffentlich ist ein Code erst, wenn er freigegeben ist.

### F-22 · Ereignisse eigener Connectoren im Umschlag — **entschieden am 16.11.2026**

**Entscheidung:** Ereignistypen eigener Connectoren tragen das Präfix `x<key>` (Spezifikation 4.8).

**Entschieden am 16.11.2026:** wie empfohlen (Abschnitt 4.8 der Spezifikation).

- **Frage:** Wie heißen Ereignisse eines eigenen Connectors (zum Beispiel der Projektprodukte für BoatingSchool und LearnBoating), wenn das Schema in `type` und `source.product` nur die Captain-Produkte zulässt?
- **Stand:** Adapter- und Aktions-Schnittstelle des Kerns (Plugin 0.2.0) passen auch für Connectoren; nur das Schema schließt sie aus. `TODO.md` WF-CONNECTOR-01 verlangt einen öffentlichen, versionierten Connector-Vertrag.
- **Empfehlung:** Präfix `x<schlüssel>` für die Typen und der Connector-Schlüssel als `source.product`, als MINOR-Version der Spezifikation; alle übrigen Regeln unverändert. Entwurf: [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md).

### F-23 · Darf ein Workflow eine Review-Einladung planen oder einen Bewertungsentwurf anlegen? — **entschieden am 23.11.2026**

**Entscheidung der Inhaberin (23.11.2026): ja.** Workflows dürfen Einladungen von Review Captain als Befehle planen und abbrechen: `review.request.plan` und `review.request.cancel`, angeboten vom Review-Adapter von Workflow Captain Pro, ausgeführt über `plan_request()`/`cancel_request()` des Service `workflow` (Vertrag `WorkflowService::CONTRACT` 1.0), der an den Planer von Review Captain Pro am Filter `review_captain_request_planner_v1` delegiert; ohne Planer scheitert der Schritt endgültig mit `rc.contract.unavailable`. Einwilligung, Opt-out, Sperrliste, Frequenzgrenze, Plattformziel und Moderation bleiben in Review Captain (Pro). Lizenz: Workflow Captain Pro und Recht `review.review_requests` (D6). `create_draft()` wird kein Befehl. Umgesetzt in Spezifikation 1.0.0-draft.9 (R-4.30, R-4.31, 8.1, 8.2, 8.5, 10.1) und `catalog/actions-v1.json`. Offen außerhalb dieses Repositorys: Die Review-Sitzung meldet den `RequestPlanner` am Kern-Filter an; der Adapter in captain-pro bietet die Befehle an.

- **Frage:** Review Captain bietet seit `main` `863b92b` im Container-Service `workflow` `plan_request()`/`cancel_request()` (Einladung planen oder abbrechen; Einwilligung, Opt-out, Sperrliste, Frequenzgrenze und Plattformziel prüft der Planer von Review Captain Pro, Rückgabe mit Status) und `create_draft()`/`cancel_draft()` (genau ein lokaler, nicht veröffentlichter Entwurf, idempotent). Sollen das Befehle der Suite werden (`review.request.plan`, `review.request.cancel`, `review.draft.create`)?
- **Stand:** 8.1 lässt in Version 1 nur die vier Gutschein-Befehle mit Wirkung im Provider zu (D11b); 8.2 verbietet das Unterdrücken oder erneute Auslösen von Mails eines Kerns. Eine Einladung ist eine Mail an einen Kunden (Abschnitt 9, D10). Der Planer von Review Captain Pro ist noch nicht gebaut; ohne ihn antwortet der Kern `rc.contract.unavailable`.
- **Annahme der Spezifikation (bis 1.0.0-draft.8):** keine Befehle für Review Captain in Version 1 (R-4.30); der Adapter bietet die Methoden nicht an.
- **Empfehlung:** Für Version 1 so bestätigen. Eine Einladung plant Review Captain Pro aus seinen eigenen Quellen (F-08). Später in einer MINOR-Version höchstens `review.request.plan` und `review.request.cancel` zulassen, nur über den Planer von Review Captain Pro mit allen Schutzregeln, idempotent über den Schlüssel des Aufrufers, mit Rückgabe des Status und ohne Kontaktdaten im Workflow; `create_draft()` nicht als Befehl, weil ein Entwurf Bewertungstext und Namen bräuchte (R-5.1).

### F-24 · Schreibende Workflow-Aktionen für Course Captain (neu am 23.11.2026)

- **Frage:** Course Captain hat entschieden (`CC:docs/r0/ENTSCHEIDUNGEN.md`, E-39 und E-39b auf Branch `claude/blissful-curie-tuuf7q` `c45e891`), dass ein Workflow schon in Version 1 Lernzugänge anlegen und entziehen darf (`course.enroll`, `course.revoke`), über die Grant-Quelle `workflow` im Connector von Course Captain Pro, mit Idempotenz und Audit. Dort steht es als Änderungsauftrag an Workflow Captain. Soll die Suite-Spezifikation das zulassen?
- **Stand:** 8.1 lässt in Version 1 nur die vier Gutschein-Befehle mit Wirkung in einem Provider zu (D11b). Course Captain liest `konzept.md` so, dass schreibende Aktionen für Lernplattformen ausgeschlossen sind (sein Verweis R0-32); `konzept.md` 17.10 schließt für den Kundenconnector BoatingSchool Aktionen zum Ändern von Kursrechten ausdrücklich aus. Course Captain hat auf `main` (`5af78c3`) keinen Code; auf dem Branch feuert es bisher nur `course_captain_loaded_v1`, einen Katalog `course.*` gibt es nicht (4.6.4).
- **Annahme der Spezifikation:** keine Befehle für Course Captain in Version 1; der Katalog `course.*` kommt erst mit einer MINOR-Version, und Befehle kämen nach den Regeln aus 8 (feste Grenzen, Idempotenz über den Schlüssel des Aufrufers, Protokoll, ab Werk aus, Berechtigung im Kern) als weitere MINOR-Version.
- **Empfehlung:** Für Version 1 so lassen. Erst die Ereignisse (`course.*`, CC-3), dann `course.enroll`/`course.revoke` als Befehle nach dem Muster der Gutschein-Befehle (8.3): Einstiegspunkte im Course-Kern mit Pflichtfeldern, Idempotenz, Tagesgrenzen und Abschaltbarkeit, Aktion in Workflow Captain Pro, nicht in Course Captain Pro, damit es nur einen Weg gibt.

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
| W-11 **(entschieden: Voucher D13, Form wie D13)** | D10 gegen den Stand in Form Captain | Keine Übergabe von Mails. Form Captain hat sie auf seinem Branch gebaut | F-12 |
| W-12 **(entschieden am 16.11.2026: D11b und Antwort zu F-05)** | D11 und D11b in sich | D11b legt vier Befehle mit Grenzen fest, nennt aber weder Zahlen noch den Bezug von „je Tag“ (Website, Empfänger, Gutschein) und nicht, welche Gutscheine ein Workflow einlösen darf | aufgelöst: Zahlen und Bezug stehen in Abschnitt 8.3.1; offen bleibt nur die Währung (F-05a) |
| W-13 | D9 gegen D7 | „Speichern und Wiederholen beim Abnehmer“ garantiert nichts vor dem Speichern. Die Review-Einladung hängt an genau diesen Ereignissen | Verlust wird hingenommen und benannt (R-7.2, F-14) |
| W-14 | Entscheidung 17 in zwei Fassungen | `main` (Commit `4ebaf33`) führt die Kundenconnectoren als eigene Projektprodukte; die Branches `claude/sleepy-ptolemy-n32guj` (hier) und `claude/youthful-bardeen-7ejd7a` (`captain-pro`) führen sie als Teil von Workflow Captain Pro | Auf Rückfrage am 16.11.2026 bestätigt: eigene Projektprodukte (F-18). Die beiden Branches sind vor einem Merge anzugleichen. Dieselbe abweichende Fassung steht in `wp-formmaster` `docs/ENTSCHEIDUNGEN.md` Zeile 23 auf den Branches `claude/sleepy-ptolemy-n32guj` (`c5cbf68`) und `claude/youthful-bardeen-7ejd7a` (`3f808cb`); der in `TODO.md` zitierte Stand `0e6aea1` sagt „eigenes Projektprodukt“ |
