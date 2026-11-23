# Captain Suite: Spezifikation der Ereignisse und Befehle zwischen den Plugins

| | |
|---|---|
| Version | **1.0.0-draft.8** |
| Stand | 16.11.2026; Belege und Stände der anderen Repositories nachgetragen am 23.11.2026 (ohne Regeländerung, Abschnitt 16) |
| Status | Entwurf zur Abnahme durch den Inhaber. Seit dem 16.11.2026 gebaut (Plugin `workflow-captain` 0.2.x, Abweichung von D12). |
| Ort | `hastla007/wp-workflowmaster`, `docs/SPEC-suite-events.md` (Entscheidung D1) |
| Maschinenlesbar | [`schema/suite-event-v1.schema.json`](schema/suite-event-v1.schema.json), Katalog [`catalog/suite-events-v1.json`](catalog/suite-events-v1.json), Beispiele unter [`examples/`](examples/) |
| Offene Punkte | [`SPEC-open-questions.md`](SPEC-open-questions.md) |

Dieses Dokument ist die eine gemeinsame Spezifikation dafür, wie die Plugins der Captain Suite zusammenarbeiten. Wo `konzept.md`, `TODO.md` oder ein Dokument eines anderen Repositorys etwas anderes sagt, gilt dieses Dokument.

Grundlage sind die Entscheidungen des Inhabers vom 16.11.2026 (D1, D2, D4 bis D12) und die Folgeentscheidungen vom Abend desselben Tages (D8b, D11b, D13). Jede Regel nennt die Entscheidung, aus der sie folgt. Regeln ohne Entscheidung sind Vorschläge dieser Spezifikation und in [`SPEC-open-questions.md`](SPEC-open-questions.md) aufgeführt.

## 0. Sprache und Begriffe

**Normative Wörter.** MUSS und DARF NICHT bezeichnen eine Pflicht ohne Ausnahme. SOLL bezeichnet eine Regel, von der nur mit einem aufgeschriebenen Grund abgewichen wird. KANN bezeichnet eine erlaubte Möglichkeit.

**„zu prüfen“** markiert eine Aussage über vorhandenen Code, die beim Schreiben dieses Dokuments nicht am Code oder an der Dokumentation des jeweiligen Repositorys belegt wurde. Abschnitt 15 sammelt alle diese Stellen.

**Belege.** Fundstellen stehen als `Repository:Datei:Zeile`. Gelesen wurden diese Stände:

| Kürzel | Repository | Branch | Commit | Plugin-Version |
|---|---|---|---|---|
| VC | `wp-couponmaster` (Voucher Captain) | `claude/sharp-mendel-xiiwf3` | `df3eee2`; Z-05 bis Z-16 am 16.11.2026 an `ed7b791` geprüft, dazu `hvollo-refactors` `82145ed` und `main` `2d84943` | 1.2.0 (`voucher-captain.php:6`) |
| PC | `wp-passmaster` (Pass Captain) | `claude/sharp-mendel-xiiwf3` | `bca8d85a`; Z-01 bis Z-04 und Z-07 am 16.11.2026 an `dd32087` geprüft (Nachfolger, nur `TODO.md` geändert), dazu `main` `b2b88ea` | 1.5.0 (`pass-captain.php:6`) |
| AC | `wpaffiliatemaster` (Affiliate Captain) | `claude/sharp-mendel-xiiwf3` | `23f06be` (Katalog gelesen zwischen `70c23b7` und `dbb319d`; Entfernung des Workflow-Providers geprüft an `23f06be`); Z-07 bis Z-13 am 16.11.2026 an `6600392` und `main` `2a77ed3` geprüft | 0.15.0 (`affiliate-captain.php:6`) |
| LIC | `wp-license` (Lizenzserver) | `claude/sharp-mendel-xiiwf3` | `241ae7a` (committeter Stand); Z-10 und Z-14 am 16.11.2026 an `241ae7a`, `a113568` und `main` `c269e07` geprüft | 0.2.0 (`wp-license.php:23`) |
| CP | `captain-pro` (Add-ons, Kernel) | `claude/sharp-mendel-xiiwf3` | `5e333f7` (Arbeitsverzeichnis mit uncommitteten Änderungen); am 16.11.2026 gegengeprüft an `07b5365` und `main` `2f23da7`: alle Fundstellen in `packages/addon-kernel` unverändert | – |
| FC | `wp-formmaster` (Form Captain) | `claude/youthful-babbage-wkrqyv` | `0017025`; Z-11 am 16.11.2026 an `main` `6f4785f` und `0e6aea1` geprüft; Katalog 4.6.1 an `main` `6f4785f` und `claude/plugin-todo-liste-2a83v4` `fc1f206` | 1.0.0-dev |
| RC | `wp-reviewmaster` (Review Captain) | `main` | `863b92b` (Pull Request 3, 16.11.2026; Katalog 4.6.3), Vertragskonstante am Branch `claude/plugin-todo-liste-2a83v4`; früher `claude/sleepy-gates-6mm217` `faa5f89`, Z-12 an `b35fb95` | 0.1.0-dev |
| CC | `wp-coursemaster` (Course Captain) | `claude/blissful-curie-tuuf7q` | `ae1bce9`; am 23.11.2026 an `c45e891` (mit Code) und `main` `5af78c3` (nur Dokumente) geprüft | `COURSE_CAPTAIN_CONTRACT_VERSION` = `0.1.0` (nur Branch) |

**Prüfung am 23.11.2026** (alle Suite-Repositories, nur gelesen; Einzelheiten in [`KOMPATIBILITAET.md`](KOMPATIBILITAET.md) und [`ANFORDERUNGEN-andere-repos.md`](ANFORDERUNGEN-andere-repos.md)): `main` von VC `151786c`, PC `b2b88ea`, AC `930b6b1`, LIC `ec9220d`, CP `911c403`, FC `59580d5`, RC `0818df0`, CC `5af78c3`. Die Fundstellen dieser Spezifikation gelten weiter für die oben genannten Stände, soweit nicht ausdrücklich „Stand 23.11.2026“ dabeisteht.

**Begriffe.**

- **Kern:** ein kostenloses Plugin der Suite (Voucher Captain, Pass Captain, Affiliate Captain, künftig Form, Review, Course und Workflow Captain). Ein Kern enthält keinen Lizenzcode und keine Rechteprüfung.
- **Add-on:** die bezahlte Erweiterung eines Kerns aus `captain-pro`. Produktschlüssel, Ordner und Textdomain sind gleich: `voucher-captain-pro`, `pass-captain-pro`, `affiliate-captain-pro`, `form-captain-pro`, `review-captain-pro`, `course-captain-pro`, `workflow-captain-pro`.
- **Recht:** ein Schlüssel wie `workflow.suite_integrations`, den der Lizenzserver einem Tarif zuordnet und den ein Add-on prüft.
- **Ereignis:** die Meldung eines Plugins, dass etwas geschehen ist. Technisch eine PHP-Action (`do_action()`).
- **Umschlag:** der feste Rahmen, in den der Abnehmer ein Ereignis legt (Abschnitt 6).
- **Befehl:** eine Aktion, die ein Workflow in einem Plugin auslöst (Abschnitt 8).

## 1. Geltungsbereich und Nichtziele

**Geregelt wird:**

1. welche vorhandenen PHP-Actions der Kerne und des Lizenzservers Suite-Ereignisse sind und wie sie heißen,
2. der Umschlag, den Workflow Captain daraus baut,
3. Zustellung, Wiederholung, Schutz vor Doppelten und vor Schleifen,
4. welche Befehle ein Workflow auslösen darf,
5. welche Lizenz wofür nötig ist und wo sie geprüft wird,
6. die Versionsregeln und die Nachweise je Rolle.

**Nicht geregelt wird (Nichtziele der Version 1):**

- Die Kerne bekommen keinen neuen Transport: keine Ausgangstabelle (Outbox), keine Warteschlange, keinen eigenen Umschlag, keine HTTP-Zustellung für Suite-Ereignisse (D5, D9). Die ausgehenden Webhooks und Connector-Ereignisse von Pass Captain (`PC:docs/SUITE-EVENTS.md:15-16`) bleiben, sind aber nicht der Weg der Suite.
- Lizenzereignisse werden nicht an Websites von Kunden zugestellt (D2).
- Keine Übergabe von Mails oder Erinnerungen eines Kerns an einen Workflow (D10).
- Keine geldwirksamen Befehle außer den vier Gutschein-Befehlen Ausstellen, Sperren, Reaktivieren und Einlösen in festen Grenzen (D11, D11b).
- Webhooks nach außen und n8n regeln `konzept.md` und [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md); sie nutzen denselben Umschlag. WordPress- und WooCommerce-Ereignisse stehen seit draft.6 in 4.7 (F-20), Ereignisse eigener Connectoren in 4.8 (F-22), netzwerkweite Abläufe in Abschnitt 17 (F-07).
- Der Aufbau des Workflow-Editors, der Tabellen und der Oberfläche steht in `konzept.md`.
- Preise. Die in `konzept.md` genannten Beträge sind nicht bestätigt.

**Rangfolge der Arbeit (D12).** Diese Spezifikation darf jetzt geschrieben und abgenommen werden. Gebaut wird erst nach dem Verkaufsstart der drei vorhandenen Add-ons. *[Auf Anweisung des Inhabers vom 16.11.2026 wird schon vorher gebaut.]*

## 2. Rollen

| Rolle | Wer | Aufgabe | Lizenzprüfung |
|---|---|---|---|
| **Provider** | ein Kern oder der Lizenzserver | löst seine vorhandenen PHP-Actions aus, immer und ohne Bedingung | **keine**, nie |
| **Consumer** | Workflow Captain (kostenloser Kern `workflow-captain`) | speichert Ereignisse, baut den Umschlag, wiederholt, führt Workflows aus | **keine**, nie |
| **Adapter** | Code in einem Add-on | hängt sich an die Actions eines Providers, übersetzt sie in Suite-Ereignisse, entfernt verbotene Werte, führt Befehle im Provider aus | **ja**, über den Add-on-Kernel |

Regeln:

- R-2.1 Ein Provider MUSS seine Actions unabhängig davon auslösen, ob ein Abnehmer vorhanden, aktiv oder lizenziert ist. Belegt für Pass Captain (`PC:docs/SUITE-EVENTS.md:7-8`, `PC:docs/EXTENSIONS.md:48-49`).
- R-2.2 Ein Provider DARF NICHT wissen, dass es Workflow Captain gibt. Er nennt kein anderes Produkt der Suite in seinem Code für diese Anbindung (D7).
- R-2.3 Ein Adapter liegt ausschließlich in einem Add-on, nie in einem Kern (D7). In welchem Add-on er liegt, ist offen (F-08); diese Spezifikation gilt für beide Möglichkeiten.
- R-2.4 Rechte werden ausschließlich in Add-ons geprüft, nie in einem Kern und nie im Consumer (D6). Der Consumer kennt weder Produktschlüssel noch Rechte; er sieht nur, welche Adapter sich bei ihm angemeldet haben.
- R-2.5 Fällt der Consumer, ein Adapter oder eine Lizenz aus, läuft jeder Provider unverändert weiter. Kein Ticket, kein Gutschein, keine Provision und keine Lizenz hängt von Workflow Captain ab.
- R-2.6 Ein Adapter und der Consumer greifen nie direkt auf Tabellen eines Providers zu. Gelesen und geschrieben wird nur über dessen öffentliche Funktionen, Klassen und Hooks.

**Wo Workflow Captain läuft (D2).** Auf Websites von Kunden und im Shop des Herstellers. Im Shop läuft derselbe Consumer; dort hängt sich ein Adapter an die Actions des Lizenzservers im selben WordPress (Abschnitt 11).

## 3. Der Vertrag eines Providers

### 3.1 Vertragskonstante (D4)

- R-3.1 Jeder Provider MUSS in seiner Hauptdatei eine Konstante `<PREFIX>_CONTRACT_VERSION` definieren. `<PREFIX>` ist der Präfix seiner Versionskonstante.
- R-3.2 Der Wert folgt SemVer (`MAJOR.MINOR.PATCH`) und ist unabhängig von der Plugin-Version:
  - MAJOR steigt, wenn eine Action, ein Argument oder eine öffentliche Funktion entfernt oder unverträglich geändert wird.
  - MINOR steigt, wenn Actions, Filter, Funktionen, optionale Argumente oder Felder dazukommen.
  - PATCH steigt bei Klarstellungen ohne Änderung des Verhaltens.
- R-3.3 Fehlt die Konstante, gilt der Provider für den Adapter als „Vertrag unbekannt“. Der Adapter MUSS dann abschalten oder ausdrücklich eine Plugin-Version als Ersatz nennen.

Stand der Konstanten:

| Provider | Konstante | Wert | Fundstelle | Versionsregel dokumentiert in |
|---|---|---|---|---|
| Voucher Captain 1.2.0 | `VCAP_CONTRACT_VERSION` | `1.1.0` (nur `claude/sharp-mendel-xiiwf3`; **Stand 23.11.2026:** das gemergte `main` `151786c` mit Plugin 1.2.0 hat **keine** Konstante, nur `VCAP_VERSION`; Pull Request #19 `fa42cf5` bringt Plugin 1.3.0 mit `1.4.0`) | `VC:voucher-captain.php:29` (Branch) | `VC:CONNECTORS.md:103-106` (innerhalb v1 nur additive Änderungen), `VC:docs/FREE-PREMIUM.md:104` |
| Pass Captain 1.5.0 | `WPCAP_CONTRACT_VERSION` | `1.1.0` (Branch; **Stand 23.11.2026:** `main` `b2b88ea` ohne Konstante; `claude/plugin-todo-liste-2a83v4` `453f9cc` `1.2.0` = Lesefassade, `claude/woocommerce-ticket-plugin-8q4m8q` `0efe8a4` `1.2.0` = `service_date` und `1.3.0` = Lesefassade, PC-7) | `PC:pass-captain.php:166` | `PC:docs/EXTENSIONS.md:33-41` |
| Affiliate Captain 0.15.0 | `WPAC_CONTRACT_VERSION` | `1.4.0` (**Stand 23.11.2026:** `main` `930b6b1` Plugin 0.17.0 mit `1.6.0`, additiv) | `AC:affiliate-captain.php:41` | `AC:docs/CONTRACT.md:5` |
| Form Captain 1.0.0-dev | `FORM_CAPTAIN_CONTRACT_VERSION` | `1.0.0` (**Stand 23.11.2026:** `main` `59580d5` `1.1.0`, FC-2 erledigt) | `FC:form-captain.php:29` | `FC:docs/RELEASE.md:16` |
| Review Captain 0.1.0-dev | `REVIEW_CAPTAIN_CONTRACT_VERSION` (Branch `claude/plugin-todo-liste-2a83v4`; auf `main` `863b92b` fehlt sie) | `1.0.0` (**Stand 23.11.2026:** auf `main` `0818df0` weiter keine Konstante; Branch `claude/sleepy-gates-6mm217` `35af5e0` `1.1.0` mit Sternen im Payload) | `RC:review-captain.php:22` (Branch); auf `main` nur `REVIEW_CAPTAIN_VERSION` (`RC:review-captain.php:20`) und `WorkflowService::CONTRACT` = `1.0` | `RC:docs/contracts/README.md` („Vertragsversion“, Branch), Stabilitätsstufen je Hook in `RC:docs/adr/0007-oeffentliche-hooks-v1.md` |
| Course Captain | `COURSE_CAPTAIN_CONTRACT_VERSION` (**Stand 23.11.2026:** nur Branch `claude/blissful-curie-tuuf7q` `c45e891`) | `0.1.0` | `main` ohne Code | – |
| Lizenzserver 0.2.0 | `WPLC_CONTRACT_VERSION` (**Stand 23.11.2026:** auf `main` `ec9220d`, Pull Request #48; vorher fehlte sie) | `1.0.0` | `LIC:wp-license.php:27` | `LIC:docs/EXTENSION-POINTS.md` |

Die drei vorhandenen Kerne erfüllen R-3.1. Review Captain erfüllt R-3.1 auf seinem Branch `claude/plugin-todo-liste-2a83v4` (RC-2), noch nicht auf `main`. Für Course Captain und den Lizenzserver ist die Konstante eine Aufgabe des jeweiligen Repositorys (F-03). **Stand 23.11.2026:** Auf `main` erfüllen Affiliate Captain, Form Captain und der Lizenzserver R-3.1; Voucher Captain und Pass Captain auf `main` nicht (nur auf Branches), Review Captain und Course Captain nur auf Branches.

### 3.2 „Seit“-Tabelle

- R-3.4 Ein Provider MUSS eine Tabelle führen, die zu jeder öffentlichen Action die Version nennt, seit der es sie gibt.
- R-3.5 Ein Test des Providers SOLL prüfen, dass die Tabelle jede Action des Codes enthält und keine nennt, die es nicht gibt.

| Provider | Tabelle | Test |
|---|---|---|
| Voucher Captain | `VC:docs/HOOKS.md` (220 Hooks, Spalte „Since“, aus dem Code erzeugt, `VC:docs/HOOKS.md:3-9`) | CI prüft mit `php bin/hooks.php --check` (`VC:docs/HOOKS.md:4`) |
| Pass Captain | `PC:docs/EXTENSIONS.md:52` ff. für die 54 versionierten Punkte (`wp_pass_captain_*_v1`). Für die `wpcap_*`-Actions des Katalogs unten gibt es **keine** Angabe je Hook; ermittelt am 16.11.2026 aus dem Git-Verlauf und in 4.2 eingetragen (Z-01), im Kern nachzutragen | `PC:tests/integration/modules/zzzzzzzz_r16_contract.php` (`PC:docs/EXTENSIONS.md:49-50`) |
| Affiliate Captain | keine Tabelle; „seit“ steht im Fließtext und im Änderungsabsatz (`AC:docs/CONTRACT.md:7`); Werte am 16.11.2026 ermittelt und in 4.4 eingetragen | kein Test, der die Tabelle mit dem Code vergleicht (Z-09) |
| Lizenzserver | `LIC:docs/EXTENSION-POINTS.md` (38 Actions und 92 Filter, zusammen 130 Zeilen an `241ae7a`; `main`: 38 und 91; ohne „seit“; geprüft 16.11.2026, Z-14) | `tests/Contract/ExtensionPointsDocTest.php` (`LIC:docs/EXTENSION-POINTS.md:3`) |

Lücken gegenüber R-3.4: „seit“ je `wpcap_*`-Action in Pass Captain (Werte in 4.2 ermittelt, im Kern noch nicht eingetragen), eine Tabelle in Affiliate Captain, „seit“ im Lizenzserver.

### 3.3 Abkündigung

- R-3.6 Eine Action des Katalogs (Abschnitt 4) DARF NICHT ohne neue MAJOR-Version entfernt oder umgedeutet werden.
- R-3.7 Vor dem Entfernen MUSS die Action mindestens zwei MINOR-Versionen des Providers als abgekündigt dokumentiert sein und mit `do_action_deprecated()` weiter ausgelöst werden. Die Frist in Monaten ist offen (F-03).
- R-3.8 Ein unverträglich geändertes Ereignis bekommt einen neuen Namen. Die alte Action läuft parallel bis zum Ende der Frist.

### 3.4 Zeitpunkt

- R-3.9 Eine Action, die eine dauerhafte Buchung meldet, SOLL erst nach dem Commit der Datenbanktransaktion ausgelöst werden. Der Provider dokumentiert je Action, ob das gilt.
- R-3.10 Ist eine Action heute nicht „nach Commit“ oder ist das unbelegt, wird der Provider dafür nicht umgebaut, solange D5 gilt. Der Adapter gleicht das aus (R-7.4).

### 3.5 Wächtertest „kein Lizenzcode im Kern“

- R-3.11 Jeder Kern MUSS einen Test führen, der fehlschlägt, sobald der ausgelieferte Code einen Lizenzclient, einen Aufruf des Lizenzservers, eine Rechteprüfung, einen eigenen Updater oder einen Produktschlüssel eines Add-ons enthält.
- R-3.12 Der Test MUSS in der CI laufen.

| Kern | Wächtertest |
|---|---|
| Voucher Captain | `VC:tests/license-guard.php` |
| Pass Captain | `PC:tests/guard/no-license-code.php` (`PC:docs/EXTENSIONS.md:6-8`) |
| Affiliate Captain | Prüfung „A2“ in `AC:tests/run.php:332-363` |
| Workflow Captain | noch anzulegen (Aufgabe dieses Repositorys) |

## 4. Ereigniskatalog v1

### 4.1 Namensregel

- R-4.1 Ein Suite-Ereignis heißt `<produkt>.<objekt>.<ereignis>` in Kleinbuchstaben mit Unterstrichen. `<produkt>` ist `voucher`, `pass`, `affiliate`, `license`, `form`, `review`, `course`, `workflow`, `wordpress` oder `woocommerce` (4.7) oder `x<schlüssel>` für einen eigenen Connector (4.8). `<ereignis>` steht in der Vergangenheitsform.
- R-4.2 Der Name ist vom Namen der PHP-Action unabhängig. Die Zuordnung steht in den Tabellen unten und nur dort.
- R-4.3 Der Katalog enthält nur Actions, die es heute im Code gibt. Eine Action, die hier nicht steht, ist kein Suite-Ereignis; ein Adapter DARF sie nicht weiterreichen.

Lesehilfe für die Tabellen: „Argumente heute“ ist die Signatur der PHP-Action. „Nicht weiterreichen“ nennt, was der Adapter entfernen MUSS. „Nach Commit“ sagt, ob die Action belegt erst nach dem Commit feuert.

### 4.2 Pass Captain (`source.product` = `pass-captain`, 13 Ereignisse)

Alle Actions übergeben interne Datenbank-IDs; öffentliche IDs (`public_id`) lädt der Empfänger über die Klassen des Kerns (`PC:docs/SUITE-EVENTS.md:24-25`). „Seit“ ist für die `wpcap_*`-Actions im Kern nirgends je Hook ausgewiesen; die Spalte unten ist am 16.11.2026 aus dem Git-Verlauf und den Release-Tags ermittelt (Z-01): erste Plugin-Version, in der die Action vorkommt, und Vertragsversion nach der Regel in `PC:docs/EXTENSIONS.md:29-41` (Vertrag 1.0.0 = Plugin bis 1.4.0, Vertrag 1.1.0 = Plugin 1.5.0). Gelesen an `dd32087` (Nachfolger von `bca8d85a`, nur `TODO.md` geändert). Der Stand auf `main` (Plugin 1.4.0) hat weder `WPCAP_CONTRACT_VERSION` noch `wpcap_event_cancelled` noch `wp_pass_captain_invoice_created_v1`.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `pass.order.paid` | `wpcap_order_paid` | 0.9.0 / 1.0.0 | `int $order_id` | – | ja (`PC:src/Orders.php:1346-1348`; kostenlose Bestellung `:410`, `:456`) |
| `pass.order.cancelled` | `wpcap_order_cancelled` | 0.9.0 / 1.0.0 mit nur `$order_id`; die drei Argumente seit 1.5.0 / 1.1.0 (`b6ef0b4`, nicht im CHANGELOG) | `int $order_id, string $reason, string $previous_payment_status`; beim Ablauf einer Reservierung nur `$order_id` | `$reason` (Freitext, Z-03) | ja (`PC:src/Orders.php:1440-1445`); Ablauf einer Reservierung ja, nach dem COMMIT von `expire_order()` (`:1853`, `:1805`); der Status ist dann `expired`, nicht `cancelled`, und `$reason` fehlt |
| `pass.order.refunded` | `wpcap_order_refunded` | 0.9.0 / 1.0.0 | `int $order_id, string $reason` | `$reason` (fester Schlüssel mit Datensatznummern und Anbieterteilen, Z-03) | ja (`PC:src/Orders.php:1501-1506`) |
| `pass.order.tickets_issued` | `wpcap_tickets_issued` | 0.9.0 / 1.0.0 | `int $order_id, int $count` | – | ja (`PC:src/Orders.php:2044-2046`) |
| `pass.refund.completed` | `wpcap_refund_completed` | 0.9.0 / 1.0.0 | `int $refund_id, int $order_id` | – | ja (`PC:src/Refunds.php:344-357`, `:671-681`) |
| `pass.refund.rejected` | `wpcap_refund_rejected` | 0.9.0 / 1.0.0 | `int $refund_id, int $order_id` | Grund und Fehlermeldung der Erstattung (Freitext, `PC:src/Refunds.php:106`, `:155`) | ja: einzelnes UPDATE im Autocommit, Action nur bei Erfolg (`PC:src/Refunds.php:150-161`) |
| `pass.ticket.revoked` | `wpcap_ticket_revoked` | 0.9.0 / 1.0.0 | `int $ticket_id, string $reason` | `$reason` (Freitext im Admin, Z-03) | ja: alle Wege nach COMMIT oder im Autocommit (`PC:src/Admin/TicketsPage.php:53-56`, `PC:src/Orders.php:701-703` und Aufrufer) |
| `pass.ticket.checked_in` | `wpcap_ticket_checked_in` | 0.9.0 / 1.0.0 | `int $ticket_id, array $context` (`source`, `device_id`, `area_id`, `idempotency_key`) | alle weiteren Schlüssel von `$context` | ja (`PC:src/Checkin.php:191-193`, `:252-259`) |
| `pass.ticket.checked_out` | `wpcap_ticket_checked_out` | 0.9.0 / 1.0.0 | `int $ticket_id, array $context` | wie oben | ja (`PC:src/Checkin.php:308-310`) |
| `pass.occurrence.rescheduled` | `wpcap_occurrence_rescheduled` | 0.9.0 / 1.0.0 | `int $occurrence_id, string $old_starts_at, string $new_starts_at` (UTC) | `location` (Freitext) | ja, UPDATE im Autocommit; **das Ergebnis des UPDATE wird nicht geprüft**, die Action feuert auch bei einem Fehlschlag (`PC:src/Admin/EventsPage.php:207-212`). Gegenlesen nach R-7.4 |
| `pass.occurrence.cancelled` | `wpcap_occurrence_cancelled` | 0.9.0 / 1.0.0 | `int $occurrence_id, int $issued` | – | bei Event-Absage ja (`PC:src/Orders.php:1672-1680`); Admin-Absage eines Termins im Autocommit, Ergebnis des UPDATE ungeprüft (`PC:src/Admin/EventsPage.php:207-218`, `:242-245`); Gegenlesen nach R-7.4 |
| `pass.event.cancelled` | `wpcap_event_cancelled` | 1.5.0 / 1.1.0 | `int $event_id` | – | ja (`PC:src/Orders.php:1672-1720`) |
| `pass.invoice.created` | `wp_pass_captain_invoice_created_v1` | 1.5.0 / 1.1.0 (`PC:docs/EXTENSIONS.md:75`) | `int $id, string $type, array $context` | `$context` bis auf IDs und Belegnummer | ja, für alle drei Belegarten nach COMMIT (`PC:src/Api/Invoices.php:88-90`, `:146-150`, `PC:src/Partners/Settlements.php:493-495`) |

**Ausdrücklich kein Suite-Ereignis** (Action enthält einen Code oder personenbezogene Daten, oder feuert innerhalb der Transaktion; `PC:docs/SUITE-EVENTS.md:34-46`):

| PHP-Action | Grund | Regel für den Adapter |
|---|---|---|
| `wpcap_ticket_created` | feuert **innerhalb** der Ausgabe-Transaktion (`PC:src/Orders.php:2013`); `$order` enthält `customer_email` und den Namen des Käufers | DARF NICHT angehängt werden. Ersatz: `pass.order.tickets_issued` |
| `wpcap_ticket_code_rotated` | `$code` ist der Ticketcode im Klartext | DARF NICHT angehängt werden |
| `wpcap_consent_withdrawn` | `$consent` ist die Einwilligungszeile einer Person | DARF NICHT weitergereicht werden |
| `wpcap_mail_before_send` | Empfängeradresse, Mailtext, Anhänge mit Code | DARF NICHT angehängt werden (zugleich R-9.2) |
| `wpcap_order_creating` | feuert innerhalb der Bestell-Transaktion; `$payload` enthält Eingaben des Käufers | DARF NICHT angehängt werden |

Es gibt kein Ereignis „Teilnahme bestätigt“ oder „Termin beendet“ (`PC:docs/SUITE-EVENTS.md:48-49`). Der Nachweis für eine Review-Einladung ist `pass.ticket.checked_in` (D7).

`subject`: Bestellung und Ticket mit `public_id` (`id_kind` = `public`): 32 Kleinbuchstaben-Hex aus `bin2hex(random_bytes(16))` (`PC:src/Orders.php:330`, `:1995`), eindeutig je Tabelle (`PC:src/Installer.php:120`, `:148`, `:189`, `:199`). Die `public_id` einer Bestellung allein gewährt keinen Zugriff; dafür braucht es zusätzlich ein Zugangstoken (`PC:src/Rest/Controller.php:455-461`). Erstattung, Termin, Event und Beleg haben keine `public_id` (Z-04, geprüft 16.11.2026): `id_kind` = `record` mit `refund_id`, `occurrence_id`, `event_id` (der `slug` ist öffentlich, aber änderbar) und der Belegnummer des jeweiligen Typs (`subject.type` = `invoice`, `credit_note` oder `settlement`).

`$reason` (Z-03, geprüft 16.11.2026) ist bei `wpcap_order_cancelled` und `wpcap_ticket_revoked` Freitext, wenn er aus dem Admin kommt (`PC:src/Admin/OrdersPage.php:69`, `PC:src/Admin/TicketsPage.php:40`), und bei `wpcap_order_cancelled` ein beliebiger Text eines anderen Plugins über `wp_pass_captain_ticket_service_v1` (`PC:src/Integrations/Vouchers/TicketService.php:171`). Sonst sind es feste Schlüssel, oft mit Datensatznummern oder Anbieterteilen (`native_refund_{id}`, `{provider}_payment_failed`). Der Adapter bildet daraus nach dem Präfix einen festen Wert `reason_class`: `admin`, `refund`, `order_cancelled`, `occurrence_cancelled`, `event_cancelled`, `privacy_erasure`, `service_booking`, `woocommerce`, `payment_failed`, `expired`, `voucher`, `pos`, sonst `other`. Der Rohwert wird nie weitergereicht.

**Erlaubte Felder in `data` und Bedeutung von `actor_user_id` (WF-SPEC-03, 16.11.2026).** Positivliste nach R-5.2; Beträge als `*_minor` mit `currency`; Zeitpunkte in RFC 3339 mit `Z`. Werte, die nicht in den Argumenten stehen, liest der Adapter über die Lesefunktionen (8.1). „nicht gesetzt“ heißt: Das Feld `actor_user_id` fehlt.

| Suite-Ereignis | Erlaubte Felder in `data` | `actor_user_id` |
|---|---|---|
| `pass.order.paid` | `status`, `payment_status`, `payment_provider` (Schlüssel), `currency`, `total_minor`, `refunded_minor`, `ticket_count`, `customer_user_id` (wenn größer 0) | nicht gesetzt (Kunde, Personal oder Webhook je nach Weg) |
| `pass.order.cancelled` | `status` (`cancelled` oder `expired`), `previous_payment_status`, `reason_class`, `currency`, `total_minor` | nicht gesetzt |
| `pass.order.refunded` | `status`, `payment_status`, `currency`, `total_minor`, `refunded_minor`, `reason_class`, `refund_id` (wenn aus dem Schlüssel ablesbar) | nicht gesetzt |
| `pass.order.tickets_issued` | `issued_count`, `currency`, `total_minor` | nicht gesetzt |
| `pass.refund.completed` | `order_id`, `amount_minor`, `tax_minor`, `deducted_minor`, `currency`, `ticket_count`, `refund_source` (`native` oder Schlüssel des Anbieters) | nicht gesetzt |
| `pass.refund.rejected` | `order_id`, `amount_minor`, `currency` | das Personal, das ablehnt (`approved_by`, `PC:src/Refunds.php:152-155`) |
| `pass.ticket.revoked` | `order_id`, `ticket_type_id`, `occurrence_id`, `event_id`, `reason_class` | nur auf dem Weg über den Admin das Personal, sonst nicht gesetzt |
| `pass.ticket.checked_in` | `checkin_source`, `device_id`, `area_id`, `occurrence_id`, `event_id`, `ticket_type_id`, `reentry` (Boolean) | das Personal am Scanner (`PC:src/Checkin.php:153`) |
| `pass.ticket.checked_out` | wie `checked_in`, ohne `reentry` | das Personal am Scanner (`PC:src/Checkin.php:277`) |
| `pass.occurrence.rescheduled` | `event_id`, `old_starts_at`, `new_starts_at`, `timezone`, `issued_tickets` | das Personal im Admin |
| `pass.occurrence.cancelled` | `event_id`, `issued_tickets`, `starts_at`, `scope` (`occurrence` oder `event`) | das Personal im Admin |
| `pass.event.cancelled` | `occurrence_count`, `revoked_tickets`, `cancelled_orders` (alle drei optional, nur wenn gelesen) | das Personal im Admin |
| `pass.invoice.created` | `document_type` (`invoice`, `correction`, `settlement`), `document_number`, `order_id`, `invoice_id`, `refund_id`, `partner_id`, `period`, `currency`, `total_minor`, `tax_minor` | nicht gesetzt |

### 4.3 Voucher Captain (`source.product` = `voucher-captain`, 15 Actions, 16 Ereignisse)

„Seit“ stammt aus `VC:docs/HOOKS.md:13-56`. „Nach Commit: ja“ heißt, die Tabelle dort trägt den Vermerk „after COMMIT, dropped on ROLLBACK“ (Regel in `VC:docs/HOOKS.md:5-6`).

Ein `Voucher`-Objekt enthält immer Code, Token sowie Daten von Käufer und Empfänger (`VC:README.md:109`; Eigenschaften `code`, `token`, `buyer_email`, `recipient_email` in `VC:src/Voucher.php:20-48`). Eine Action, die ein `Voucher`-Objekt übergibt, gilt deshalb als „enthält Code und personenbezogene Daten“.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `voucher.order.vouchers_issued` | `vcap_vouchers_issued` | 1.0.0 | `Voucher[] $created, WC_Order $order` | **beide Objekte.** Übrig: Bestell-ID, Anzahl; je Gutschein ein eigenes Ereignis ist nicht vorgesehen | kein Puffer bis COMMIT, aber auch keine eigene Transaktion: feuert nach Schreibvorgängen im Autocommit (geprüft 16.11.2026, Z-05). Gegenlesen nach R-7.4 bleibt (`VC:src/Issuer.php:282`, `:640`) |
| `voucher.voucher.issued_standalone` | `vcap_voucher_issued_standalone` | 1.2.0 | `Voucher $voucher, array $args` | **`Voucher`-Objekt; aus `$args` Namen, E-Mail-Adressen, `message`, `code`** | kein Puffer bis COMMIT, aber auch keine eigene Transaktion: feuert nach Schreibvorgängen im Autocommit (geprüft 16.11.2026, Z-05). Gegenlesen nach R-7.4 bleibt (`VC:src/Issuer.php:1076`) |
| `voucher.voucher.delivered` | `vcap_voucher_delivered` | 1.0.0 | `Voucher $voucher, string[] $addresses` | **`Voucher`-Objekt, alle Adressen** | kein Puffer bis COMMIT, aber auch keine eigene Transaktion: feuert nach Schreibvorgängen im Autocommit (geprüft 16.11.2026, Z-05). Gegenlesen nach R-7.4 bleibt; läuft unter einer MySQL-Sperre `GET_LOCK`, nicht in einer Transaktion (`VC:src/Delivery.php:404`, `:618`) |
| `voucher.voucher.delivery_failed` | `vcap_voucher_delivery_given_up` | 1.2.0 | `Voucher`, Zahl der Fehlversuche (`VC:README.md:119`) | **`Voucher`-Objekt** | kein Puffer bis COMMIT, aber auch keine eigene Transaktion: feuert nach Schreibvorgängen im Autocommit (geprüft 16.11.2026, Z-05). Gegenlesen nach R-7.4 bleibt (`VC:src/Delivery.php:950`) |
| `voucher.voucher.bounced` | `vcap_voucher_bounced` | 1.2.0 | `Voucher`, Rolle (`recipient`/`buyer`), Grund (Freitext des Mailsystems) | **`Voucher`-Objekt**, Grund | kein Puffer bis COMMIT, aber auch keine eigene Transaktion: feuert nach Schreibvorgängen im Autocommit (geprüft 16.11.2026, Z-05). Gegenlesen nach R-7.4 bleibt (`VC:src/Delivery.php:1047`) |
| `voucher.voucher.redeemed` | `vcap_voucher_redeemed` | 1.1.0 | Array: `voucher_id`, `code`, `user_id`, `amount`, `currency`, `order_id`, `channel`, `committed_at`, `source`, `redemption_key`, `target`; am Schalter `till`, `receipt`; über die Einlöse-API `state`, `balance_after`, `expires_at`, `created_at`, `meta` (`VC:README.md:121`, `VC:src/VoucherRepository.php:776-795`) | **`code`**, `meta`, `receipt`, `till`, `target` | ja |
| `voucher.redemption.reserved` | `vcap_redemption_reserved` | 1.1.0 | Array wie bei der Einlöse-API, mit `code` und `user_id` (`VC:README.md:122`) | **`code`**, `meta`, `target` | ja |
| `voucher.redemption.released` | `vcap_redemption_released` | 1.1.0 | dasselbe Array, `bool $expired` | **`code`**, `meta`, `target` | ja |
| `voucher.redemption.refunded` | `vcap_redemption_refunded` | 1.1.0 | dasselbe Array, zurückgebuchter Betrag | **`code`**, `meta`, `target` | ja |
| `voucher.redemption.reversed` | `vcap_redemption_reversed` | 1.2.0 | Gutschein-ID, Journal-ID, Betrag, Grund als Freitext (`VC:README.md:125`, `VC:src/Tickets.php:1417`) | Grund | ja |
| `voucher.voucher.expired` | `vcap_voucher_expired` | 1.2.0 | `Voucher[]` je Stapel der täglichen Wartung | **alle Objekte.** Der Adapter erzeugt je Gutschein ein Ereignis mit der ID | ja |
| `voucher.voucher.reissued` | `vcap_voucher_reissued` | 1.2.0 | `Voucher`, alter Code (`VC:README.md:128`) | **`Voucher`-Objekt, alter Code** | kein Puffer bis COMMIT, aber auch keine eigene Transaktion: feuert nach Schreibvorgängen im Autocommit (geprüft 16.11.2026, Z-05). Gegenlesen nach R-7.4 bleibt (`VC:src/Reissue.php:157`) |
| `voucher.voucher.voided`, `voucher.voucher.reactivated` | `vcap_voucher_event` mit Ereignis `voided` bzw. `reactivated` (`VC:README.md:126`, `:132`) | 1.0.0 | Gutschein-ID, Ereignis, Betrag, Restwert, Notiz als Text, Bestell-ID, Quelle, Benutzer-ID (die letzten beiden nur mit 8 angenommenen Argumenten, `VC:docs/HOOKS.md:50`) | Notiz. Alle anderen Journalereignisse DARF der Adapter nicht weiterreichen | ja |
| `voucher.store_credit.changed` | `vcap_store_credit_changed` | 1.1.0 | Benutzer-ID, Betrag, Saldo, Ereignis, Grund, Bestell-ID | Grund | ja |
| `voucher.gift_card.topped_up` | `vcap_gift_card_topped_up` | 1.2.0 | `Voucher $card, float $amount, WC_Order $order`, Bestellposition | **beide Objekte**, Bestellposition | ja |

Nicht im Katalog: `vcap_voucher_created`, `vcap_voucher_updated`, `vcap_voucher_deleted` (Zwischenzustände der Ausstellung; ein angelegter Gutschein kann wieder entfernt werden, `VC:docs/HOOKS.md:45-46`), `vcap_card_moved_to_credit`, `vcap_ticket_checked_in` und alle Actions der Oberfläche.

`vcap_ticket_checked_in` (geprüft 16.11.2026, Z-06): `Db::action( 'vcap_ticket_checked_in', Voucher $fresh, int $count )` in `Tickets::checkin()` (`VC:src/Tickets.php:574`), gepuffert bis COMMIT. Es meldet den Einlass mit einem Gutschein des Typs `ticket` von Voucher Captain (nicht ein Ticket von Pass Captain), nach der atomaren Erhöhung der Einlässe; `$count` ist die Zahl der Einlässe dieses Vorgangs. Auslöser sind die REST-Route `vcap/v1/checkin` und das Formular der Einlassseite, beide mit Berechtigung `vcap_scan_tickets` oder `manage_woocommerce` (`VC:src/Tickets.php:30-31`). Ein Zurücknehmen des Einlasses (`undo_checkin`, `:578`) meldet nichts. Kandidat für eine MINOR-Version als `voucher.ticket.checked_in`; `actor_user_id` wäre das Personal am Einlass.

**Erlaubte Felder in `data` und Bedeutung von `actor_user_id` (WF-SPEC-03, 16.11.2026).** Positivliste nach R-5.2. Beträge als `*_minor` mit `currency` (R-5.1a); Feldnamen in Schlangenschreibweise wie im Schema. `subject` ist der Gutschein (`voucher_id`, `id_kind` = `record`), außer wo anders genannt. „nicht gesetzt“ heißt: Das Feld `actor_user_id` fehlt.

| Suite-Ereignis | Erlaubte Felder in `data` | `actor_user_id` |
|---|---|---|
| `voucher.order.vouchers_issued` | `order_id`, `voucher_count`, `background` (Boolean: Ausstellung im Hintergrund fertig); `subject` ist die Bestellung (`type` = `order`, `id_kind` = `record`) | nicht gesetzt (Zahlung, Hintergrundaufgabe) |
| `voucher.voucher.issued_standalone` | `amount_minor`, `currency`, `voucher_type` (`voucher`, `giftcard`, `ticket`), `usage_mode`, `status` (`active`, `scheduled`), `paid` (Boolean), `issue_source` (fester Schlüssel: `admin`, `cli`, `import`, `refund-credit`, `remaining-value`, `workflow`; sonst `other`), `send` (Boolean) | der angemeldete Benutzer, der ausstellt (Admin, CLI); im Hintergrund nicht gesetzt |
| `voucher.voucher.delivered` | `address_count`, `roles` (`recipient`, `buyer` oder `recipient,buyer`), `bundled` (Boolean) | nicht gesetzt |
| `voucher.voucher.delivery_failed` | `failures` | nicht gesetzt |
| `voucher.voucher.bounced` | `role` (`recipient`, `buyer`) | nicht gesetzt |
| `voucher.voucher.redeemed` | `amount_minor`, `currency`, `order_id`, `channel`, `redemption_source` (fester Schlüssel), `committed_at`, `balance_after_minor` | `user_id` aus dem Array, wenn größer 0: im Shop und am Schalter der zur Zeit angemeldete Benutzer (Kunde oder Personal), über die Einlöse-API der reservierende Benutzer; bei einem Zahlungs-Webhook 0, dann nicht gesetzt |
| `voucher.redemption.reserved` | `amount_minor`, `currency`, `balance_after_minor`, `expires_at`, `state` | `user_id` aus dem Array, wenn größer 0 |
| `voucher.redemption.released` | wie `reserved`, dazu `expired` (Boolean) | wie `reserved` |
| `voucher.redemption.refunded` | `refunded_minor` (dieser Vorgang), `refunded_total_minor`, `currency` | wie `reserved` |
| `voucher.redemption.reversed` | `reversed_event_id`, `amount_minor`, `currency` (Währung des Gutscheins) | der angemeldete Benutzer (Personal), sonst nicht gesetzt |
| `voucher.voucher.expired` | je Gutschein ein Ereignis: `balance_minor`, `currency`, `voucher_type` | nicht gesetzt (tägliche Wartung) |
| `voucher.voucher.reissued` | `status` | der angemeldete Benutzer (Personal) |
| `voucher.voucher.voided` | `balance_minor`, `currency`, `order_id`, `change_source` (fester Schlüssel: `admin`, `cron`, `rest`, `scanner`, `checkout`, `cli`, `web`), `void_reason` (`refund` oder `manual`, ermittelt über `Issuer::voided_after_refund()`, nie aus der Notiz) | Argument `$user_id`, wenn größer 0 (Personal) |
| `voucher.voucher.reactivated` | `balance_minor`, `currency`, `order_id`, `change_source` | wie `voided` |
| `voucher.store_credit.changed` | `amount_minor` (mit Vorzeichen), `balance_minor`, `currency` (Basiswährung des Shops), `credit_event` (`credit`, `debit`, `refund_credit`, `refund_credit_reversed`), `order_id`; `subject` ist das Guthabenkonto (`type` = `store_credit`, `id` = Benutzer-ID, `id_kind` = `record`) | der Kunde, dem das Guthaben gehört |
| `voucher.gift_card.topped_up` | `amount_minor`, `currency`, `order_id`, `balance_minor` | nicht gesetzt |

- R-4.7 `reference` (Idempotenzreferenz von `issue_standalone`) und `redemption_key` werden nicht weitergereicht. Beide wählt der Aufrufer frei; der Kartenimport bildet die Referenz aus einem Hash des Codes (`VC:src/Admin/CardImport.php:92-94`), aus dem sich ein schwacher Code zurückrechnen ließe. `source` und alle Gründe sind Freitext und gehen nur als fester Schlüssel des Adapters weiter (R-5.1).

Auslöser einer Review-Einladung ist `voucher.voucher.redeemed` (D7), und nur für Gutscheine, die der Betreiber als Leistung oder Erlebnis eingestuft hat (`VC:README.md:98`). Diese Einstufung trifft der Adapter, nicht der Kern.

`subject`: Voucher Captain hat keine öffentliche Kennung eines Gutscheins außer Code und Token. `subject.id` ist die `voucher_id` mit `id_kind` = `record` (F-02).

Beträge übergibt der Kern als `float`. Der Adapter MUSS sie in die kleinste Einheit der Währung umrechnen (`amount_minor`, Integer).

### 4.4 Affiliate Captain (`source.product` = `affiliate-captain`, 21 Actions, 21 Ereignisse)

Vertrag 1.4.0. Alle Actions dieser Tabelle feuern erst nach dem Commit, seit Plugin 0.15.0 auch innerhalb einer äußeren Transaktion; wird eine Buchung zurückgerollt, feuert nichts (`AC:docs/CONTRACT.md:210`). Die Ereignisse sind Benachrichtigungen ohne Kundendaten, mit den dort genannten Ausnahmen. „Seit“ meint die Vertragsversion, „Plugin“ die Plugin-Version (`AC:docs/CONTRACT.md:5`). Die bisher offenen Werte sind am 16.11.2026 aus den Tag-Ständen v0.7.0 bis v0.14.0 und dem Git-Verlauf ermittelt (Z-08; gelesen an `6600392`, Nachfolger von `23f06be`, nur `TODO.md` geändert; `main` `2a77ed3` hat dasselbe `src/`). Geprüft ist auch „nach Commit“: Alle 31 Auslöseorte der 21 Actions liegen in `Db::after_commit` bzw. `PayoutService::after_commit` (`AC:src/Core/Db.php:249-256`, `:278-345`), getestet in `AC:tests/integration/r15-l3.php`. „Äußere Transaktion“ meint nur eine über `Db::transaction()` geöffnete.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute (`AC:docs/CONTRACT.md:187-206`) | Nicht weiterreichen |
|---|---|---|---|---|
| `affiliate.affiliate.created` | `wpac_affiliate_created` | 1.2.0, Plugin 0.11.0 (im Code seit 0.10.0) | `int $affiliate_id, string $status` | – |
| `affiliate.affiliate.status_changed` | `wpac_affiliate_status_changed` | 1.0.0, Plugin 0.1.0 | `int $affiliate_id, string $new, string $old` | – |
| `affiliate.affiliate.recruited` | `wpac_affiliate_recruited` | 1.4.0 | `int $affiliate_id, int $recruiter` (`AC:src/Affiliates/AffiliateRepository.php:363`) | – |
| `affiliate.affiliate.erased` | `wpac_affiliate_erased` | 1.3.0, Plugin 0.13.0 | `int $affiliate_id, bool $open` | – (zugleich Löschsignal, R-12.5) |
| `affiliate.sale.attributed` | `wpac_affiliate_attributed` | 1.0.0, Plugin 0.1.0 | `string $order_id, int $affiliate_id, string $method, string $source` | – |
| `affiliate.commission.created` | `wpac_commission_created` | 1.0.0, Plugin 0.1.0; für jede Buchungsart seit 1.3.0 | `int $commission_id` | – |
| `affiliate.commission.adjusted` | `wpac_commission_adjusted` | 1.0.0, Plugin 0.1.0 | `array $refund, int[] $adjustment_ids` | **Freitext `reason` in `$refund`** (`AC:docs/CONTRACT.md:210`); Listen werden zu einer Anzahl |
| `affiliate.commission.approved` | `wpac_commission_approved` | 1.0.0, Plugin 0.1.0 | `int $commission_id` | – |
| `affiliate.commission.rejected` | `wpac_commission_rejected` | 1.0.0, Plugin 0.1.0 | `int $commission_id` | – |
| `affiliate.commission.paid` | `wpac_commission_paid` | 1.0.0, Plugin 0.1.0 | `int $commission_id` | – |
| `affiliate.commission.pending` | `wpac_commission_pending` | 1.0.0, Plugin 0.1.0 | `int $commission_id` | – |
| `affiliate.commission.review_reopened` | `wpac_commission_review` | 1.3.0, Plugin 0.13.0 (erstmals dokumentiert; technisch über die allgemeine Action seit 1.0.0) | `int $commission_id` | – |
| `affiliate.payout.created` | `wpac_payout_created` | 1.2.0 | `int $payout_id, int $affiliate_id` | – |
| `affiliate.payout.paid` | `wpac_payout_paid` | 1.0.0, Plugin 0.1.0 | `int $payout_id` | – |
| `affiliate.payout.cancelled` | `wpac_payout_cancelled` | 1.3.0 | `int $payout_id` | – |
| `affiliate.correction.generated` | `wpac_correction_generated` | 1.3.0, Plugin 0.12.0 | `int $correction_id, string $path` | **`$path` (Dateipfad)** |
| `affiliate.creative.created` | `wpac_creative_created` | 1.4.0, Plugin 0.15.0 | `int $creative_id, array $info` | `title` |
| `affiliate.creative.updated` | `wpac_creative_updated` | 1.4.0, Plugin 0.15.0 | `int $creative_id, array $info` | `title` |
| `affiliate.code_request.created` | `wpac_code_requested` | 1.4.0, Plugin 0.15.0 | `int $affiliate_id, string $code, int $row_id` | – (`$code` ist der öffentliche Partnercode und erlaubt, D8b; Feld `partner_code`) |
| `affiliate.code_request.approved` | `wpac_code_request_approved` | 1.4.0, Plugin 0.15.0 | `int $affiliate_id, string $code, int $row_id, bool $make_main` | – (Partnercode erlaubt, D8b) |
| `affiliate.code_request.rejected` | `wpac_code_request_rejected` | 1.4.0, Plugin 0.15.0 | `int $affiliate_id, string $code, string $reason, int $row_id` | **`$reason`** (Partnercode erlaubt, D8b) |

Geldbeträge sind erlaubt (D8b): Der Adapter KANN bei `affiliate.sale.attributed`, `affiliate.commission.*`, `affiliate.payout.*` und `affiliate.correction.generated` den Betrag als `amount_minor` (Integer, kleinste Einheit) mit `currency` (ISO 4217) mitgeben. Die Actions übergeben nur IDs; den Betrag liest der Adapter über `wpac_get_commission()` beziehungsweise `wpac_get_payout()` (`AC:docs/CONTRACT.md:7`). Für eine Gutschrift (`affiliate.correction.generated`) gibt es keine Lesefunktion; ihr Betrag kommt nur über das folgende `affiliate.commission.created` mit `kind` = `correction`.

**Erlaubte Felder in `data` und Bedeutung von `actor_user_id` (WF-SPEC-03, 16.11.2026).** Positivliste nach R-5.2; Beträge über die Lesefunktionen als `amount_minor` mit `currency`. `id_kind` ist überall `record`. `actor_user_id` bleibt für Affiliate Captain **nicht gesetzt** (Abschnitt 6), auch wo ein angemeldeter Benutzer bekannt wäre. Nie weitergereicht werden: Name, E-Mail-Adresse, `slug` (aus dem Namen gebildet), `status_reason`, `website`, `channel`, `description`, `reference`, `paypal_email`, `claim` (Reservierungsschlüssel einer Auszahlung, wirkt wie ein Token), `$path`, `title` und jeder Grund.

| Suite-Ereignis | `subject` | Erlaubte Felder in `data` |
|---|---|---|
| `affiliate.affiliate.created` | `affiliate` | `status`, `group_id`, `referred_by` (wenn größer 0), `partner_user_id` (WordPress-Benutzer des Partners, wenn größer 0) |
| `affiliate.affiliate.status_changed` | `affiliate` | `status`, `previous_status` |
| `affiliate.affiliate.recruited` | `affiliate` | `recruiter_id` (Partner-ID), `status` |
| `affiliate.affiliate.erased` | `affiliate` | `deferred` (Wahrheitswert, `$open`) |
| `affiliate.sale.attributed` | `order` (Bestellreferenz der Quelle) | `affiliate_id`, `source` (Schlüssel), `attribution_method` (`link`, `cookie`, `code`, `coupon`, `lifetime`, `manual`, `app`, `renewal`, sonst `other`) |
| `affiliate.commission.created` | `commission` | `affiliate_id`, `kind`, `status`, `amount_minor`, `currency`, `parent_id`, `source`, `order_id`, `payout_id` |
| `affiliate.commission.adjusted` | `order` | `source`, `refund_id`, `adjustment_type` (`refund`, `cancellation`, `chargeback`), `full`, `deleted` (Wahrheitswerte), `adjustment_count`, optional `affiliate_id`, `amount_minor`, `currency`; nie `reason` |
| `affiliate.commission.approved` | `commission` | `affiliate_id`, `amount_minor`, `currency`, `kind`, `previous_status` |
| `affiliate.commission.rejected` | `commission` | `affiliate_id`, `kind`, `amount_minor`, `currency`, `previous_status` |
| `affiliate.commission.paid` | `commission` | `affiliate_id`, `payout_id`, `amount_minor`, `currency` |
| `affiliate.commission.pending` | `commission` | `affiliate_id`, `kind`, `amount_minor`, `currency` |
| `affiliate.commission.review_reopened` | `commission` | `affiliate_id`, `kind`, `amount_minor`, `currency` |
| `affiliate.payout.created` | `payout` | `affiliate_id`, `payout_number`, `status`, `method` (`sepa`, `manual`), `amount_minor`, `currency` |
| `affiliate.payout.paid` | `payout` | `affiliate_id`, `payout_number`, `method`, `amount_minor`, `currency` |
| `affiliate.payout.cancelled` | `payout` | `affiliate_id`, `payout_number`, `amount_minor`, `currency` |
| `affiliate.correction.generated` | `correction` | keine weiteren Felder |
| `affiliate.creative.created`, `affiliate.creative.updated` | `creative` (Versions-ID) | `family_id`, `version`, `status` (`draft`, `approved`, `archived`), `creative_type` (`text`, `image`), `group_id` |
| `affiliate.code_request.created` | `code_request` | `affiliate_id`, `request_id`; `partner_code` erst nach der Antwort auf F-21 |
| `affiliate.code_request.approved` | `code_request` | `affiliate_id`, `partner_code`, `main` |
| `affiliate.code_request.rejected` | `code_request` | `affiliate_id`, `request_id`; `partner_code` erst nach der Antwort auf F-21; nie `$reason` |

`$method` und `$source` von `wpac_affiliate_attributed` sind frei gewählte Schlüssel des Connectors; der Adapter bildet `$method` auf die Liste oben ab. Der Feldname `payout_number` statt `number` hält die Felder eindeutig.

Nicht im Katalog: `wpac_mail_sent`, `wpac_mail_failed` und `wpac_contract_not_queued` (enthalten Kundendaten, `AC:docs/CONTRACT.md:210`), `wpac_referral_captured`, `wpac_refund_after_correction`, `wpac_statement_generated`, `wpac_storno_generated`.

#### 4.4.1 Der vorläufige Workflow-Provider im Affiliate-Kern: entfernt (D13)

Affiliate Captain enthielt bis zum 16.11.2026 eine eigene Provider-Seite für Workflow Captain (`wpac_workflow_event( array $envelope )`, `wpac_workflow_read()`, `wpac_workflow_event_types()`, `docs/WORKFLOW-PROVIDER.md`). Sie entstand vor den Entscheidungen des Inhabers.

**Stand (D13, umgesetzt am 16.11.2026):** Die Provider-Seite ist aus dem Affiliate-Kern entfernt (Commit `a928a14`, Stand `23f06be`). Im Code und in den Dokumenten des Kerns kommt `wpac_workflow` nicht mehr vor; ein Wächtertest in `AC:tests/run.php:365-372` schlägt fehl, sobald die Schnittstelle zurückkehrt, bevor die Aufgabe AFF-WF-08 abgehakt ist. Affiliate Captain 0.15.0 erscheint **ohne** Workflow-Provider; der Vertrag bleibt 1.4.0 (`AC:affiliate-captain.php:41`). Die Wiedereinführung ist dort als AFF-WF-08 geführt und setzt voraus, dass die drei Add-ons im Verkauf sind (D12) und diese Spezifikation abgenommen ist. Für den Voucher-Kern gilt dieselbe Entscheidung für den Provider aus Pull Request 16 (Umschlag `vcap_workflow_event`, Lizenzwissen, Mail-Übergabe); geprüft am 16.11.2026 (Z-16): auf `main` war der Provider nie, auf dem Branch `hvollo-refactors` (`82145ed`) ist er noch vorhanden.

- R-4.4 `wpac_workflow_event`, `wpac_workflow_read()`, `wpac_workflow_event_types()` und `vcap_workflow_event` gibt es in keinem veröffentlichten Kern. Sie sind **nicht normativ**; kein Adapter, kein Consumer und kein Dokument DARF sie voraussetzen. Quelle der Affiliate-Ereignisse sind allein die 21 Actions der Tabelle oben.
- R-4.5 Kein Kern baut einen eigenen Umschlag für die Suite (D5, D9, R-6.1). Ein Kern, der später wieder eine Provider-Seite bekommt, braucht dafür eine MINOR-Version dieser Spezifikation, die sie beschreibt.
- R-4.6 `wpac_workflow_read()` existiert nicht mehr und ist keine Lesefunktion im Sinn von 8.1. Welche Lesefunktionen ein Adapter für Affiliate Captain nutzt, ist eine offene Aufgabe (Abschnitt 8.1, Z-07).

Für die Wiedereinführung (AFF-WF-08) gilt zu den vier dort geführten Abweichungen (AFF-WF-07):

| Nr. | Abweichung | Regel dieser Spezifikation |
|---|---|---|
| a | Umschlag des Kerns ohne Benutzer-ID | unerheblich: `actor_user_id` ist optional und für Affiliate-Ereignisse in Version 1 nicht gesetzt |
| b | `code_request.*` enthält den Partnercode | **erlaubt** (D8b): Der öffentliche Partnercode ist kein geheimer Code |
| c | das entfernte Dokument erlaubte `wpac_create_adjustment()` nach einer menschlichen Aktion | **bleibt verboten** in Version 1 (D11, D11b, Abschnitt 8.2) |
| d | eigene Namen und eigener Umschlag (`version`, `object`, `site_id`, UUID Version 5, Zeit ohne `T` und `Z`, fünf Statuswechsel als `commission.status_changed`, kein Produktpräfix) | es gelten die Namen aus 4.4 und der Umschlag aus Abschnitt 6 |

`subject`: Affiliate Captain nutzt Datensatznummern (`affiliate_id`, `commission_id`, `payout_id`); `id_kind` = `record` (F-02).

### 4.5 Lizenzserver (`source.product` = `wp-license`, 3 Ereignisse, nur im Shop des Herstellers)

Siehe Abschnitt 11 für Rahmen und fehlende Actions. Der Lizenzserver hat keine Vertragskonstante und keine Zusage „nach Commit“ (`LIC:docs/TODO.md`, Abschnitt 12, Absatz „Lizenzereignisse gibt es nur als PHP-Aktionen“). Geprüft am 16.11.2026 an `241ae7a`, `a113568` (neuer Stand des Branches) und `main` `c269e07`; `src/License/` ist in allen drei gleich. Auf keinem der Branches gibt es eine Vertragskonstante, eine der vier fehlenden Actions oder eine Lesefassade (dort WFC-LIC-04, -05, -07 offen, „nach dem Verkaufsstart“). Es gibt keine Tags; „Seit“ ist die Plugin-Version beim ersten Commit der Action. **Stand 23.11.2026:** Seit Pull Request #48 (`main` `ec9220d`) hat der Lizenzserver `WPLC_CONTRACT_VERSION` = `1.0.0` (`LIC:wp-license.php:27`), eine Tabelle mit „Since“ und „After commit“ (`LIC:docs/EXTENSION-POINTS.md`, `WPLC\Events\AfterCommit`), die vier fehlenden Actions und die Lesefunktion `WPLC\Events\LicenseReader`; Katalog `wp-license` mit `min_contract` `1.0.0`.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `license.license.issued` | `wplc/license_issued_followup` | Plugin 0.1.0 (`3a30ef8`; der Docblock nennt 0.2.0, `LIC:src/License/LicenseIssuer.php:873`) | `int $license_id, int $order_id` (`LIC:src/License/LicenseIssuer.php:878`) | – | nach Schreibvorgängen im Autocommit, keine Transaktion (geprüft 16.11.2026, Z-10); keine dokumentierte Zusage, daher Gegenlesen (R-7.4); läuft als Hintergrundaufgabe und kann je Lizenz mehrfach feuern (`:869-870`), der Idempotenzschlüssel fängt das ab |
| `license.license.status_changed` | `wplc/license_status_changed` | Plugin 0.1.0 (`bf72f9d`) | `int $license_id, string $old_status, string $new_status, string $action` (`LIC:src/License/LicenseLifecycle.php:1221`); `$action` ist einer von `order_refund`, `renewal_failed`, `renewal_succeeded`, `chargeback`, `order_cancelled`, `admin_restore`, `license_expired`, `admin_status_change`, `admin_extend` | – | nach dem COMMIT der Verlängerung (`:1724`, `:1736`) oder im Autocommit; während der Lizenzsperre gepuffert bis zu deren Freigabe (`:1207-1211`, `:2213-2222`), außer `announce_renewal()` (`:1604-1606`). Keine dokumentierte Zusage, daher Gegenlesen |
| `license.license.plan_changed` | `wplc/license_plan_changed` | Plugin 0.2.0 (`67fe915`) | `int $license_id` (`LIC:src/License/PlanChange.php:1022`); den vorherigen Tarif übergibt die Action nicht | – | nach Schreibvorgängen im Autocommit, keine Transaktion (geprüft 16.11.2026, Z-10); keine dokumentierte Zusage, daher Gegenlesen (R-7.4); „after all locks are released“ (`:1013`) stimmt für `apply_pending_after_renewal` nicht (`:609`, `:648`) |

**Ausdrücklich kein Suite-Ereignis:**

| PHP-Action | Grund | Regel für den Adapter |
|---|---|---|
| `wplc/license_issued` | **übergibt den Lizenzschlüssel im Klartext:** `$license_id, $key, $order_id` (`LIC:src/License/LicenseIssuer.php:524`, `:823`; `LIC:docs/EXTENSION-POINTS.md:10`) | DARF NICHT angehängt werden. Ersatz: `wplc/license_issued_followup` |
| `wplc/license_key_reissued`, `wplc/license_key_resend_requested` | übergeben den Lizenzschlüssel (`LIC:src/Admin/SupportActions.php:151`, `:189`) | DARF NICHT angehängt werden |

Weitere vorhandene Actions (zum Beispiel `wplc/license_owner_changed`, `wplc/activation_transferred`, `wplc/chargeback`) sind in Version 1 nicht im Katalog.

### 4.6 Form, Review und Course Captain

Die Präfixe `form.`, `review.` und `course.` sind reserviert. Seit 1.0.0-draft.7 (MINOR nach R-13.1: neuer Provider) hat Form Captain einen Katalog (4.6.1, 4.6.2), seit 1.0.0-draft.8 Review Captain (4.6.3). Course Captain hat noch keinen (4.6.4). Eigene Workflow-Verträge dieser Kerne sind nicht normativ (F-12 wie D13, R-13.8): `FC:docs/WORKFLOW-CONTRACT.md` ist zurückgezogen, die Mail-Übergabe `form_captain_mail_handoff` ist entfernt (`FC` `fc1f206`, D10); `RC:docs/contracts/workflow-provider-v1.md` ist als nicht normativ gekennzeichnet (RC-1).

#### 4.6.1 Form Captain (`source.product` = `form-captain`, 8 Actions, 8 Ereignisse)

Grundlage ist der öffentliche Vertrag `FC:docs/spec/api-v1.md:23-80` (Ereignisse, Payloads, Lesefunktionen) und `FC:docs/CONNECTORS.md` (sensible Felder, gesperrte Feldtypen, Einwilligungswiderruf). Gelesen am 16.11.2026 an `main` `6f4785f` und am Branch `claude/plugin-todo-liste-2a83v4` `fc1f206` (gleiches `src/` bis auf die entfernte Mail-Übergabe); die Fundstellen unten gelten für `fc1f206`. Vertragskonstante `FORM_CAPTAIN_CONTRACT_VERSION` = `1.0.0` (`FC:form-captain.php:29`). `entries_imported_v1` und `consent_withdrawn_v1` kamen ohne MINOR-Erhöhung dazu; die Erhöhung ist Aufgabe von Form Captain (dort FC-2). **Stand 23.11.2026:** `main` `59580d5` hat `FORM_CAPTAIN_CONTRACT_VERSION` = `1.1.0` (FC-2 erledigt), die Mail-Übergabe ist auf `main` entfernt. Ein Adapter verlangt Vertrag 1.0.0 und MAJOR 1 (R-13.4) und, wie jeder Adapter eines Captain-Produkts, die Pro-Lizenz von Form Captain Pro (D6, R-10.1); dieses Add-on gibt es noch nicht (`captain-pro` FR-02), bis dahin bleibt der Adapter aus. Alle acht Actions feuern nach dem Commit oder im Autocommit (Z-11); zur einen Ausnahme siehe R-4.21.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `form.form.created` | `form_captain_form_created_v1` | 1.0.0 | `array $form` (Form-DTO `{id, public_id, title, status, version, fields}`) | `title`, `fields` (Beschriftungen, Optionstexte) | ja (`FC:src/Service/FormService.php:102`, `:391`, `:547`) |
| `form.form.updated` | `form_captain_form_updated_v1` | 1.0.0 | `array $form`, `string $change` (`saved`, `published`, `paused`, `unpublished`, `archived`) | wie oben | ja (`FC:src/Service/FormService.php:194`, `:225`, `:316`, `:347`) |
| `form.entry.created` | `form_captain_entry_created_v1` | 1.0.0 | `array $event` (`entry_id`, `form_id`, `form_public_id`, `form_version`, `status`, `created_gmt`, `request_id`, `context`, `values`) | `request_id`; `values` bis auf freigegebene Antworten nach 4.6.2; `context.ref_id`, wenn er nicht nur aus Ziffern und Doppelpunkten besteht | ja (`FC:src/Service/SubmissionService.php:241`, `:496`); im Savepoint-Modus vor dem Commit des Aufrufers (R-4.21). Nie für Spam |
| `form.entry.status_changed` | `form_captain_entry_status_changed_v1` | 1.0.0 | `int $entry_id, string $old, string $new, int $form_id` | – | ja (`FC:src/Service/EntryService.php:97`, `:120`) |
| `form.entry.deleted` | `form_captain_entry_deleted_v1` | 1.0.0 | `int $entry_id, int $form_id, string $reason` (`manual`, `retention`, `erasure`, `anonymized`, `form_deleted`, `consent_withdrawn`, `import_reverted`) | – | ja (`FC:src/Service/EntryDeletionService.php:84`, `FC:src/Privacy/PrivacyIntegration.php:330`, `:378`) |
| `form.form.entries_imported` | `form_captain_entries_imported_v1` | 1.0.0 (nachgetragen, FC-2) | `string $import_id, int $form_id, int $count` | `$import_id` (Hash über Formular und Dateiinhalt, `FC:src/Import/ImportService.php:238-240`; nur als Hash im Diskriminator) | ja (`FC:src/Import/ImportService.php:403`, nach `commit()` `:398`) |
| `form.entry.consent_withdrawn` | `form_captain_consent_withdrawn_v1` | 1.0.0 (nachgetragen, FC-2) | `int $entry_id, int $form_id, string $source, array $event` (`withdrawn_gmt`, `source`, `then`) | – | ja (`FC:src/Service/ConsentService.php:91`) |
| `form.entry.notification_failed` | `form_captain_notification_failed_v1` | 1.0.0 | `int $entry_id, int $form_id, string $job_type, string $error_code` | `$error_code` nur als fester Schlüssel | ja (`FC:src/Jobs/JobRunner.php:110`, nach dem Dead-letter eines `mail.*`-Jobs) |

**Ausdrücklich kein Suite-Ereignis:** `form_captain_loaded_v1` und `form_captain_admin_menu_built_v1` (Lebenszyklus), alle Filter (`form_captain_event_value_allowlist_v1` ist die Freigabe aus 4.6.2, keine Quelle), die Jobs der Outbox (`connector.action`, `core.*`; kein Event, `FC:docs/WORKFLOW-CONTRACT.md` 1), Spam (feuert keine Action).

`subject`: Ein Formular hat eine `public_id` (16 Zeichen `[a-z0-9]`, zufällig, eindeutig, in Shortcode, Block und REST öffentlich; `FC:docs/spec/datenmodell.md:14`, `FC:src/Repository/FormRepository.php:39`); `subject.type` = `form`, `id_kind` = `public`. Ein Eintrag hat keine öffentliche Kennung: `subject.type` = `entry`, `subject.id` = `entry_id`, `id_kind` = `record`. Die `public_id` eines Formulars gewährt keinen Zugriff auf Einträge.

**Erlaubte Felder in `data` und Bedeutung von `actor_user_id`.** Positivliste nach R-5.2. „nicht gesetzt“ heißt: Das Feld `actor_user_id` fehlt.

| Suite-Ereignis | `subject` | Erlaubte Felder in `data` | `actor_user_id` | Diskriminator |
|---|---|---|---|---|
| `form.form.created` | `form` | `form_id`, `status`, `form_version`, `field_count` | angemeldeter Benutzer, sonst nicht gesetzt | `once` |
| `form.form.updated` | `form` | `form_id`, `status`, `form_version`, `field_count`, `change` (`saved`, `published`, `paused`, `unpublished`, `archived`) | angemeldeter Benutzer, sonst nicht gesetzt | `change`, `form_version` und `occurred_at` auf die Sekunde |
| `form.entry.created` | `entry` | `form_id`, `form_public_id`, `form_version`, `status`, `context_purpose`, `context_ref_type`, `context_ref_id`, `context_verified` (Boolean); dazu freigegebene Antworten `answer_<schlüssel>` nach 4.6.2 | nicht gesetzt (Besucher, Servercode oder Import) | `once` |
| `form.entry.status_changed` | `entry` | `form_id`, `status`, `previous_status` | angemeldeter Benutzer (Admin; die Kernaktion `core.set_status` läuft im Hintergrund, dann nicht gesetzt) | alter Status, neuer Status und `occurred_at` auf die Sekunde |
| `form.entry.deleted` | `entry` | `form_id`, `deletion_reason` (Liste oben, sonst `other`) | angemeldeter Benutzer, sonst nicht gesetzt | `deletion_reason` (eine Anonymisierung und eine spätere Löschung sind zwei Ereignisse) |
| `form.form.entries_imported` | `form` | `form_id`, `imported_count` | angemeldeter Benutzer, sonst nicht gesetzt | SHA-256 von `$import_id` (16 Hex-Zeichen), `$count` und `occurred_at` auf die Sekunde (je Importschritt ein Ereignis) |
| `form.entry.consent_withdrawn` | `entry` | `form_id`, `withdrawal_source` (`admin`, `api`, `cli`, `privacy_request`, `system`, sonst `other`), `follow_up` (`keep`, `anonymize`, `delete`), `withdrawn_at` | angemeldeter Benutzer, sonst nicht gesetzt | `once` |
| `form.entry.notification_failed` | `entry` | `form_id`, `notification_type` (`admin` für `mail.admin`, `confirmation` für `mail.confirmation`, sonst `other`), `error_class` (fester Schlüssel aus `$error_code`, `a-z0-9_-`, sonst `other`) | nicht gesetzt (Hintergrundaufgabe) | `$job_type` und `occurred_at` auf die Sekunde |

- R-4.19 Für einen Eintrag kennt der Adapter nur, was die Action übergibt, und das Form-DTO aus `form_captain_get_form_v1()`. `form_captain_get_entry_v1()` verlangt das Recht `form_captain_read_entries` oder eine Job-Freigabe (`FC:src/Api/ContractApi.php:105-111`); der Consumer läuft ohne angemeldeten Benutzer (R-8.5). Der Adapter liest deshalb keine Einträge nach und gleicht keine Eintragsereignisse gegen (R-7.4 entfällt, weil alle Actions belegt nach dem Commit feuern).
- R-4.20 `form_public_id` und `form_version` stehen in `data`, damit ein Workflow ein Formular ohne Lesezugriff erkennt. Titel, Beschriftungen und Optionstexte eines Formulars sind Freitext und werden nie weitergereicht (R-5.1).
- R-4.21 **Savepoint-Modus.** Läuft `form_captain_submit_v1()` innerhalb einer Transaktion des Aufrufers (`@@autocommit` = 0), setzt Form Captain einen Savepoint statt einer eigenen Transaktion und löst `form_captain_entry_created_v1` nach dessen `RELEASE`, also vor dem Commit des Aufrufers aus (`FC:src/Service/SubmissionService.php:356-388`, `:224-241`). Rollt der Aufrufer danach zurück, gibt es den Eintrag nicht. Das ist für die Suite unschädlich, solange der Consumer im selben Aufruf über dieselbe Datenbankverbindung schreibt (R-7.6, R-7.7): Sein Schreibvorgang gehört dann zur Transaktion des Aufrufers und wird mit ihr zurückgerollt. Der Adapter öffnet und schließt keine Transaktion und reicht das Ereignis nicht verzögert weiter. Form Captain dokumentiert das Verhalten (dort FC-2) und ändert es nicht.
- R-4.22 Nach `form.entry.consent_withdrawn` erzeugt Form Captain für diesen Eintrag keine Jobs mehr (`FC:docs/spec/datenschutz.md` 6.5). Ein Workflow, der Daten eines Eintrags weiterverarbeitet, SOLL auf dieses Ereignis hören und seine Läufe für den Eintrag beenden. `form.entry.deleted` mit `deletion_reason` `erasure`, `anonymized` oder `consent_withdrawn` ist zugleich ein Löschsignal; der Consumer KANN gespeicherte Ereignisse mit demselben `subject` dann wie nach R-12.5 leeren.

`form.entry.created` gibt es nicht für importierte Einträge; dafür steht `form.form.entries_imported` (`FC:docs/spec/api-v1.md:32`).

#### 4.6.2 Feldklassen, Einwilligung und freigegebene Antworten

Antworten einer Einsendung sind personenbezogene Daten oder Freitext. Sie gehen grundsätzlich **nicht** in ein Suite-Ereignis. Ausnahme sind Antworten, die der Betreiber freigibt und deren Feldklasse keinen Wert nach R-5.1 tragen kann.

| Feldklasse | Feldtypen von Form Captain (`FC:docs/spec/formularschema.md`) | In `data` |
|---|---|---|
| `choice` | `select`, `radio`, `checkbox` | nach Freigabe: der Optionswert (`^[A-Za-z0-9_-]{1,64}$`, vom Betreiber festgelegter Schlüssel, nicht die Beschriftung); mehrere Werte sortiert und mit Komma verbunden; ein Wert, der keine Option des Feldes ist, entfällt |
| `number` | `number` | nach Freigabe: als Zahl |
| `consent` | `consent` | nach Freigabe: Boolean (erteilt oder nicht). Nie Einwilligungstext, Revision, Hash oder Zeitpunkt; der Nachweis bleibt im Kern (`FC:docs/spec/datenschutz.md` 5) |
| `contact` | `email`, `tel` | **nie** |
| `free_text` | `text`, `textarea`, `hidden`, `date` | **nie** (Freitext; ein Datum kann ein Geburtsdatum sein) |
| `blocked` | `html`, `file`, `password`, `payment`, `signature` und jeder andere oder unbekannte Typ (auch Feldtypen von Pro) | **nie** |

- R-4.23 **Freigabe.** Eine Antwort ist freigegeben, wenn der Schlüssel ihres Feldes für dieses Formular am Filter `form_captain_event_value_allowlist_v1` steht (Standard: leer, `FC:docs/spec/api-v1.md:51`, `:174`). Nur dann übergibt Form Captain den Wert überhaupt; sensible Felder (`sensitive: true`) übergibt es nie (`FC:src/Service/SubmissionService.php:472-482`). Ein Adapter DARF diesen Filter nicht selbst erweitern.
- R-4.24 Der Adapter prüft jede übergebene Antwort zusätzlich gegen das Form-DTO aus `form_captain_get_form_v1()`: Feld mit diesem Schlüssel vorhanden, nicht sensibel, Feldklasse `choice`, `number` oder `consent`. Sonst entfällt die Antwort. Ist das DTO nicht lesbar, entfallen alle Antworten; das Ereignis kommt ohne sie.
- R-4.25 Der Feldname in `data` ist `answer_` und der Schlüssel des Feldes. Fällt der Name unter ein verbotenes Muster des Schemas (zum Beispiel `answer_email`, `answer_name`, Endung `_code`), entfällt die Antwort. Höchstens zwölf Antworten je Ereignis, nach Schlüssel sortiert; jeder Wert höchstens 191 Zeichen. Maschinenlesbar: `data_field_prefix` = `answer_` mit `data_field_prefix_types` (`string`, `number`, `boolean`) im Katalog.
- R-4.26 Freigegebene Antworten sind ein Recht des Betreibers, keine Pflicht des Adapters. Die Datenschutzbewertung der Freigabe trägt der Betreiber (R-12.8); sie steht unter dem Vorbehalt aus Abschnitt 12.

#### 4.6.3 Review Captain (`source.product` = `review-captain`, 3 Actions, 3 Ereignisse)

Seit 1.0.0-draft.8 (MINOR nach R-13.1: neuer Provider, nach F-12 wie D13). Grundlage sind die öffentlichen Hooks der Stufe V aus `RC:docs/adr/0007-oeffentliche-hooks-v1.md` und `RC:docs/hooks.md` (generiert) mit dem Payload `review_captain.review.v1` aus `RC:src/Events/Events.php:30-45`. Gelesen am 16.11.2026 an `main` `863b92b` (Pull Request 3) und am Branch `claude/plugin-todo-liste-2a83v4` (gleiches `src/`, dazu die Vertragskonstante). Die Fundstellen unten gelten für `863b92b`. Der eigene Workflow-Vertrag `RC:docs/contracts/workflow-provider-v1.md` ist nicht normativ (F-12; dort als nicht normativ gekennzeichnet, RC-1).

Vertragskonstante: `REVIEW_CAPTAIN_CONTRACT_VERSION` = `1.0.0` steht auf dem Branch `claude/plugin-todo-liste-2a83v4` in `RC:review-captain.php:22` (RC-2), auf `main` noch nicht; dort gibt es nur `REVIEW_CAPTAIN_VERSION` = `0.1.0-dev` und die Klassenkonstanten `ReviewCaptain\Workflow\WorkflowService::CONTRACT` = `1.0` und `ReviewCaptain\Events\Events::SCHEMA` = `review_captain.review.v1`. Ein Adapter verlangt Vertrag 1.0.0 und MAJOR 1 (R-13.4). **Stand 23.11.2026:** `main` `0818df0` hat weiter keine Konstante; auf Branch `claude/sleepy-gates-6mm217` `35af5e0` steht `REVIEW_CAPTAIN_CONTRACT_VERSION` = `1.1.0` (1.1.0 bringt die Sterne in den Payload, RC-4). Fehlt die Konstante (Stand `main`), meldet er sich nach R-3.3 nur an, wenn `REVIEW_CAPTAIN_VERSION` definiert ist und `Events::SCHEMA` genau `review_captain.review.v1` lautet; `source.contract_version` ist dann `null`. Wie jeder Adapter eines Captain-Produkts verlangt er zusätzlich die Pro-Lizenz von Review Captain Pro (D6, R-10.1); dieses Add-on liegt in `captain-pro` nur auf dem Branch `claude/review-captain-pro` (Pull Request 10, nicht gemergt), bis dahin bleibt der Adapter aus.

Alle drei Actions feuern nach dem Schreibvorgang im Autocommit: `src/` von Review Captain öffnet keine Transaktion (kein `START TRANSACTION`, `COMMIT` oder `ROLLBACK`), die Actions folgen auf den erfolgreichen `INSERT`, das bedingte `UPDATE` (Statuswechsel mit Vergleich auf den alten Status) oder `DELETE`.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `review.review.created` | `review_captain_review_created_v1` | 1.0.0 | `array $payload` (`schema`, `event_id`, `review_uuid`, `kind`, `platform`, `origin`, `status`, `previous_status`, `verification`, `context_type`, `context_id`, `experience_id`, `occurred_at`) | `event_id` (bei jedem Aufruf neu, keine stabile Kennung); `experience_id` (freies Format der Quelle, kann eine Gutschein- oder Bestellreferenz enthalten; nur sein Präfix als `experience_source`); `context_id`, wenn er nicht nur aus Ziffern und Doppelpunkten besteht | ja, Autocommit (`RC:src/Repository/ReviewRepository.php:117`) |
| `review.review.status_changed` | `review_captain_review_status_changed_v1` | 1.0.0 | wie oben, `previous_status` gesetzt | wie oben | ja, nach dem bedingten `UPDATE` (`RC:src/Repository/ReviewRepository.php:302`) |
| `review.review.removed` | `review_captain_review_removed_v1` | 1.0.0 | wie oben; `status` ist der letzte Status | wie oben | ja, nach dem Statuswechsel auf `removed` (`:304`) oder dem `DELETE` (`:324`, `:353`) |

Der Payload enthält nach `RC:src/Events/Events.php:17` nie Bewertungstext, Titel, Name, E-Mail-Adresse oder Token. Personenbezogen sind in Review Captain der Anzeigename (`author_name`), die E-Mail-Adresse (`author_email`), die Benutzer-ID des Verfassers (`author_user_id`), Titel und Text (`title`, `body`), Fotos, IP-bezogene Daten der Ratenbegrenzung und Einwilligungszeitpunkte; keines davon steht im Payload, keines geht in ein Suite-Ereignis.

**Ausdrücklich kein Suite-Ereignis:** alle übrigen Hooks aus `RC:docs/hooks.md` (Lebenszyklus `review_captain_loaded`, `review_captain_installed`, die Register `_connectors_v1`, `_providers_v1`, `_widgets_v1`, `review_captain_process_job_v1`, `review_captain_budget_warning`, `review_captain_instance_confirmed`, alle Filter einschließlich `review_captain_request_planner_v1`). Für Einladungen (Anfragen) löst Core v1 keine Action aus: `review_captain_request_status_changed_v1`, `_reminder_sent_v1`, `_responded_v1` und `review_captain_recipient_opted_out_v1` sind nur reserviert (`RC:docs/adr/0007-oeffentliche-hooks-v1.md`, „Spezifizierte, aber nicht implementierte Namen“; Z-12). Einen Katalog `review.request.*` gibt es deshalb nicht.

`subject`: Eine Bewertung hat eine `review_uuid` (UUID Version 4, zufällig, eindeutig; bei veröffentlichten Bewertungen im HTML sichtbar, `RC:templates/review-card.php:17`, und im Schema-Markup, `RC:src/Seo/SchemaBuilder.php:78`); `subject.type` = `review`, `id_kind` = `public`. Die Datensatznummer wird nicht verwendet.

**Erlaubte Felder in `data` und Bedeutung von `actor_user_id`.** Positivliste nach R-5.2. „nicht gesetzt“ heißt: Das Feld `actor_user_id` fehlt.

| Suite-Ereignis | `subject` | Erlaubte Felder in `data` | `actor_user_id` | Diskriminator |
|---|---|---|---|---|
| `review.review.created` | `review` | `kind` (`review`, `testimonial`), `platform` (`local`, `google`, `trustpilot`, `trusted_shops`, `app_store`, `google_play`, `other`), `origin` (`local_submission`, `manual`, `csv_import`, `provider_sync`, `connector`), `status`, `verification` (`unverified`, `purchase_verified`, `attendance_confirmed`, `external_platform`), `context_type` (`post`, `product`, `event`, `voucher`, `app`, `form`, `course`, `organization`), `context_id`, `experience_source`, `rating_value`, `rating_scale_min`, `rating_scale_max` | angemeldeter Benutzer (Moderator, Kunde mit Konto), sonst nicht gesetzt | `once` |
| `review.review.status_changed` | `review` | wie `review.review.created` und `previous_status` | angemeldeter Benutzer (Moderator), sonst nicht gesetzt (Synchronisation, Aufbewahrung, Abgleich) | alter Status, neuer Status und `occurred_at` auf die Sekunde |
| `review.review.removed` | `review` | `kind`, `platform`, `origin`, `status` (letzter Status), `context_type`, `context_id`, `experience_source`, `removal` (`tombstoned`, `deleted`) | angemeldeter Benutzer, sonst nicht gesetzt | `deleted` bei `removal` = `deleted`; sonst `removal` und `occurred_at` auf die Sekunde |

Status sind die Werte von `RC:src/Domain/ReviewStatus.php` (`pending`, `approved`, `rejected`, `archived`, `flagged`, `removed`); ein anderer Wert wird `other`. Für die übrigen aufgezählten Felder gilt dasselbe.

- R-4.27 **Sterne.** Der Payload enthält keine Bewertung in Sternen. Der Adapter liest `rating_value`, `rating_scale_min` und `rating_scale_max` nach dem Ereignis über `ReviewCaptain\Plugin::instance()->reviews()->find_by_uuid()` (`RC:src/Repository/ReviewRepository.php:52`). Das ist eine Lesefunktion ohne Vertrag (8.1): nur lesend, nur diese drei Felder, alle übrigen Felder des gelesenen Objekts (Text, Name, E-Mail) verwirft er im selben Aufruf (R-5.3). Ist die Bewertung nicht lesbar oder hat sie keine Sterne, fehlen die drei Felder. `rating_value` steht auf der Originalskala der Plattform, nie umgerechnet. Bis Review Captain eine dokumentierte Lesefunktion oder die Sterne im Payload liefert (dort RC-4), nennt der Adapter diese Nutzung in seiner Kompatibilitätsangabe (R-13.4). **Stand 23.11.2026:** Ab Vertrag 1.1.0 (bisher nur Branch `claude/sleepy-gates-6mm217` `35af5e0`) trägt der Payload `review_captain.review.v1` die drei Felder; der Adapter SOLL sie dann aus dem Payload nehmen und die Lesefunktion ohne Vertrag nur unter 1.1.0 nutzen (Katalog `rating_fields_since`).
- R-4.28 **Entfernt.** `review_captain_review_removed_v1` feuert beim Statuswechsel auf `removed` (Grabstein, zusätzlich zu `review.review.status_changed`) und beim endgültigen Löschen (Löschauftrag, Aufbewahrung, Rücknahme eines Imports, Abbruch eines Workflow-Entwurfs). Der Adapter unterscheidet beides mit derselben Lesefunktion: Ist die Bewertung noch lesbar, ist `removal` = `tombstoned`, sonst `deleted`. Ist die Lesefunktion nicht vorhanden, fehlt `removal` und der Diskriminator ist `occurred_at` auf die Sekunde. `review.review.removed` mit `removal` = `deleted` ist ein Löschsignal; der Consumer KANN gespeicherte Ereignisse mit demselben `subject` dann wie nach R-12.5 leeren. **Stand 23.11.2026:** Review Captain liefert `removal` (`tombstoned`/`deleted`) seit `main` `8c60c6f` selbst im Payload `review_captain.review.v1` (`RC:src/Events/Events.php:50`, `:81`); der Adapter SOLL dann diesen Wert nehmen und nur ohne ihn wie oben ermitteln.
- R-4.29 **Kennungen der Quelle.** `context_id` geht nur weiter, wenn er aus Ziffern und höchstens drei Doppelpunkten besteht (`^\d{1,20}(:\d{1,20}){0,3}$`), sonst entfällt er. Aus `experience_id` (Format `<quelle>:<referenz>`, `RC:docs/contracts/experience-event-v1.md`) geht nur die Quelle als `experience_source` weiter (`^[a-z][a-z0-9_-]{0,31}$`, zum Beispiel `voucher`, `pass`, `pass-captain`, `woo`); die Referenz kann bei Voucher Captain die Gutscheinreferenz sein, die nach R-4.7 nicht weitergereicht wird.
- R-4.30 **Keine Befehle.** Für Review Captain gibt es in Version 1 keinen Befehl mit Wirkung im Provider (8.1, D11b). Der Container-Service `workflow` von Review Captain (`RC:src/Workflow/WorkflowService.php`) bietet `create_draft()`, `cancel_draft()`, `plan_request()` und `cancel_request()`; ein Adapter DARF sie nicht als Workflow-Aktion anbieten (F-23). Lesend nutzbar sind `aggregates()` und `request_capabilities()` (8.1). Einwilligung, Opt-out, Sperrliste, Frequenzgrenze, Plattformziel und Moderation bleiben im Kern bzw. im Planer von Review Captain Pro; kein Weg dieser Spezifikation umgeht sie (8.2).
- R-4.31 Eine Einladung zur Bewertung plant in Version 1 nicht ein Workflow, sondern Review Captain Pro nach seinen eigenen Regeln (Empfehlung zu F-08). Ein Workflow bekommt die Ereignisse `review.*` als Auslöser, zum Beispiel für eine Nachricht an den Betreiber bei einer neuen Bewertung mit wenigen Sternen (`notify.admin`).

#### 4.6.4 Course Captain (vorgemerkt)

Kein Code. Produktschlüssel `course-captain-pro` und Präfix `course.` kennt der Lizenzserver an `241ae7a` und `main` nicht (`LIC:src/Catalog/EntitlementCatalog.php:179-183` nennt nur Workflow, Form und Review); auf `a113568` gibt es inzwischen `catalogs/course-captain-pro.json` (F-16). **Stand 23.11.2026:** Auf Branch `claude/blissful-curie-tuuf7q` `c45e891` hat Course Captain Code und `COURSE_CAPTAIN_CONTRACT_VERSION` = `0.1.0`, feuert aber bisher nur `course_captain_loaded_v1`. Ein Katalog `course.*` kommt erst mit einer MINOR-Version dieser Spezifikation, wenn Course Captain die Ereignisse aus seinem Konzept auslöst (CC-1 bis CC-4 in `ANFORDERUNGEN-andere-repos.md`). Course Captain wünscht dort schreibende Workflow-Aktionen (`course.enroll`, `course.revoke`); in Version 1 gilt dafür 8.1 (nur die vier Gutschein-Befehle), die Frage steht als F-24 offen.

### 4.7 WordPress und WooCommerce (`source.product` = `wordpress` bzw. `woocommerce`, F-20)

Entschieden am 16.11.2026 (F-20): Ereignisse aus WordPress und WooCommerce nutzen denselben Umschlag. Der Adapter liegt im **kostenlosen Kern** `workflow-captain`; er braucht keine Lizenz (R-2.4 gilt unverändert). `source.core_version` und `source.contract_version` sind die Version von WordPress (`$wp_version`, auf drei Stellen ergänzt) bzw. WooCommerce (`WC_VERSION`). Eine Vertragskonstante gibt es nicht; die Mindestversionen sind WordPress 7.0 und WooCommerce 11.2 (Entscheidung des Inhabers MINVER-1 vom 17.11.2026, einheitlich für die Suite; vorher 6.4 und 8.2).

- R-4.8 WooCommerce-Bestellungen werden nur über die CRUD-Klassen gelesen (`wc_get_order()`), nie über `wp_posts` oder `wc_orders` direkt. So gilt dasselbe für HPOS und die klassische Speicherung.
- R-4.9 Felder mit Personenbezug (Rechnungs- und Lieferadresse, E-Mail, Telefon, Name, IP, Kundennotiz, Kupon-Codes) werden nie weitergereicht. `customer_user_id` ist erlaubt, wie bei Pass Captain.

| Ereignis | Hook | Gegenstand | Erlaubte Felder in `data` | `actor_user_id` |
|---|---|---|---|---|
| `woocommerce.order.created` | `woocommerce_new_order` | `order` (`record`) | `status`, `currency`, `total_minor`, `item_count`, `created_via`, `customer_user_id` | angemeldeter Benutzer, sonst nicht gesetzt |
| `woocommerce.order.paid` | `woocommerce_payment_complete` | `order` | `currency`, `total_minor`, `item_count`, `payment_method` (Kennung des Gateways), `customer_user_id` | nicht gesetzt (oft ein Webhook) |
| `woocommerce.order.status_changed` | `woocommerce_order_status_changed` | `order` | `from_status`, `to_status`, `currency`, `total_minor` | angemeldeter Benutzer |
| `woocommerce.order.refunded` | `woocommerce_order_refunded` | `order` | `refund_id`, `amount_minor`, `currency`, `refunded_total_minor`, `full_refund` | angemeldeter Benutzer |
| `woocommerce.order.cancelled` | `woocommerce_order_status_cancelled` | `order` | `from_status`, `currency`, `total_minor` | angemeldeter Benutzer |
| `wordpress.user.registered` | `user_register` | `user` | `role` | angemeldeter Benutzer (z. B. ein Admin), sonst nicht gesetzt |
| `wordpress.post.published` | `transition_post_status` (neu `publish`, vorher nicht `publish`) | `post` | `post_type`, `author_user_id`, `previous_status` | angemeldeter Benutzer |

Die Hooks feuern nach dem Speichern, aber nicht zugesagt nach einem Commit; der Adapter liest deshalb gegen (R-7.4). Diskriminator: `woocommerce.order.status_changed` nutzt `to_status`, `woocommerce.order.refunded` die `refund_id`, alle anderen keinen (ein Ereignis je Gegenstand).

### 4.8 Eigene Connectoren (`source.product` = Schlüssel des Connectors, F-22)

Entschieden am 16.11.2026 (F-22).

- R-4.10 Ein Connector hat einen festen Schlüssel aus Kleinbuchstaben, Ziffern und Bindestrich (2 bis 40 Zeichen, nicht mit Bindestrich beginnend, nicht der Schlüssel eines Captain-Produkts, nicht `wordpress` oder `woocommerce`). Der Schlüssel ist `source.product`. Seine Ereignistypen beginnen mit `x` und dem Schlüssel ohne Bindestriche, zum Beispiel `learnboating-connector` → `xlearnboatingconnector.entitlement.changed`. Typ und Produkt MÜSSEN zusammenpassen; der Consumer lehnt sonst ab (`type:product_mismatch`).
- R-4.11 Alle übrigen Regeln gelten unverändert: Umschlag (6), verbotene Werte (5), Idempotenz (7.3), Zustellung und Schleifenschutz (7.6).
- R-4.12 Ein Connector meldet sich mit einem Manifest an (Schlüssel, Name, Version, eigene Vertragsversion, Mindestversion von Workflow Captain, je Ereignistyp die erlaubten Felder mit Datentyp und Datenklasse, je Befehl Berechtigung und Idempotenz). Felder der Datenklasse „personenbezogen“ sind ab Werk aus. Einzelheiten: [`CONNECTOR-CONTRACT.md`](CONNECTOR-CONTRACT.md).
- R-4.13 Ob Connector-Ereignisse Workflow Captain Pro voraussetzen, ist F-18a. Der Kern prüft keine Lizenz (R-2.4).

### 4.9 Zeitpläne des Kerns (`source.product` = `workflow-captain`, WF-CORE-04)

Ein Workflow kann statt auf ein Ereignis auf einen wiederkehrenden Zeitpunkt hören: täglich, wöchentlich (Wochentag 1 bis 7) oder monatlich (Tag 1 bis 28) zu einer lokalen Uhrzeit der Website. Der Worker des Kerns erzeugt dann das Ereignis `workflow.schedule.due`.

| Ereignis | Gegenstand | Erlaubte Felder in `data` | `actor_user_id` |
|---|---|---|---|
| `workflow.schedule.due` | `workflow` (`record`, die ID des Workflows) | `scheduled_for` (fälliger Zeitpunkt in UTC), `delay_seconds` (Verspätung) | nicht gesetzt |

- R-4.14 Das Ereignis startet nur den Workflow, dessen ID im Gegenstand steht. Diskriminator ist der fällige Zeitpunkt; ein doppelter Zeitgeber oder zwei Worker starten einen Lauf.
- R-4.15 Es gelten die Regeln aus `konzept.md` 15.3 („Zeitplansemantik“): Sommerzeitlücke verschiebt nach hinten, doppelte Stunde gilt beim ersten Vorkommen. Ein verpasster Zeitpunkt wird höchstens einmal nachgeholt (nur der letzte) und nie, wenn er älter als die Nachholgrenze ist.

### 4.10 Eingehende Aufrufe (`source.product` = `workflow-captain`, WF-CONNECTOR-06, WF-N8N-05)

Ein Add-on, das externe Aufrufe annimmt (Workflow Captain Pro: Webhooks, n8n), meldet seine Auslöser mit typisierten Feldern am Filter `workflow_captain_inbound_triggers_v1` an. Nach der Prüfung des Aufrufs (`InboundGuard`) übergibt es ihn mit `Consumer::capture_inbound( $trigger, $data, $request_id )`; der Kern speichert ihn als `workflow.inbound.<trigger>`.

- R-4.16 Gegenstand ist `inbound` mit der Kennung der Anfrage (`record`); sie ist zugleich der Diskriminator. Ein wiederholter Aufruf mit derselben Kennung startet keinen zweiten Lauf.
- R-4.17 In `data` stehen nur die angemeldeten Felder mit passendem Typ; Namen nach Abschnitt 5 und Werte mit `@` werden verworfen. `actor_user_id` ist nie gesetzt.
- R-4.18 Nach der Verarbeitung eines Ereignisses durch den Worker feuert `workflow_captain_event_processed_v1( $envelope, $event_id )`, bevor die Daten des Ereignisses verkürzt werden. Ein Add-on legt daraus eine eigene Ausgangswarteschlange an und sendet nie im selben Aufruf; ein fehlerhafter Empfänger ändert den Zustand des Ereignisses nicht.

## 5. Regeln für verbotene Werte (D8)

- R-5.1 Ein Suite-Ereignis enthält öffentliche Kennungen und, wo vorhanden, eine Benutzer-ID. Es enthält **nie** einen geheimen Code (Gutscheincode, Ticketcode, Token, Download-Link, Lizenzschlüssel, Passwort) und nie: E-Mail-Adresse, Name, Anschrift, Telefonnummer, Bankdaten, Freitext (Grund, Notiz, Nachricht), Dateipfad, IP-Adresse.
- R-5.1a „Keine Codes“ meint geheime Codes, also alles, womit sich eine Leistung einlösen oder ein Zugang erlangen lässt (D8b). **Erlaubt** sind der öffentliche Partnercode eines Affiliates (Feld `partner_code`) und Geldbeträge in der kleinsten Einheit mit Währung (`*_minor`, `currency`). Jedes andere Feld, dessen Name auf `code` endet, lehnt das Schema ab.
- R-5.2 Der Adapter MUSS die Nutzdaten aus einer festen Liste erlaubter Felder je Ereignistyp aufbauen (Positivliste). Er DARF NICHT die Argumente der Action kopieren und Verbotenes herausstreichen.
- R-5.3 Übergibt eine Action ein Objekt oder ein Array mit verbotenen Werten (in den Tabellen fett), liest der Adapter daraus nur die erlaubten Felder und verwirft den Rest noch im selben Funktionsaufruf. Verbotene Werte DÜRFEN NICHT in eine Variable außerhalb dieses Aufrufs, in ein Log, in eine Fehlermeldung oder in den Speicher des Consumers gelangen.
- R-5.4 Ist ein Code die einzige Kennung, die eine Action übergibt, ersetzt der Adapter ihn durch die Kennung des Datensatzes, die er über die öffentliche Lesefunktion des Providers ermittelt. Geht das nicht, entfällt das Ereignis.
- R-5.5 Der Consumer MUSS jeden Umschlag vor dem Speichern gegen das Schema prüfen und bei einem Verstoß verwerfen und den Verstoß ohne die Werte protokollieren. Das Schema lehnt verbotene Feldnamen und verschachtelte Werte ab.
- R-5.6 Braucht ein Schritt eines Workflows eine E-Mail-Adresse oder einen Namen (zum Beispiel für eine Nachricht), löst er sie zum Zeitpunkt der Ausführung über die Benutzer-ID oder über die Lesefunktion des Providers auf. Das Ergebnis wird nicht gespeichert.

Geldbeträge stehen immer als Integer in der kleinsten Einheit der Währung mit einem Feld `currency`.

Der zweite Halbsatz von D8 („Datenschutzbewertung → Rechtsprüfung“) gilt für diesen Abschnitt und für Abschnitt 12.

## 6. Der Umschlag

- R-6.1 Den Umschlag baut der **Consumer**. Kein Kern sendet ihn (D5, D9).
- R-6.2 Der Adapter liefert dem Consumer je Ereignis: `type`, `subject`, `data`, optional `actor_user_id`, und den Diskriminator für den Idempotenzschlüssel (R-7.8). Alle anderen Felder setzt der Consumer.
- R-6.3 Das Schema [`schema/suite-event-v1.schema.json`](schema/suite-event-v1.schema.json) ist normativ. Weicht dieser Text vom Schema ab, gilt das Schema.

| Feld | Typ | Pflicht | Gesetzt von | Bedeutung |
|---|---|---|---|---|
| `id` | String, UUID Version 4 | ja | Consumer | Kennung dieses gespeicherten Ereignisses. Zufällig; taugt nicht zum Erkennen von Doppelten (dafür `idempotency_key`) |
| `type` | String | ja | Adapter | Name nach R-4.1 |
| `spec_version` | String `MAJOR.MINOR` | ja | Consumer | Version dieser Spezifikation, hier `1.0` |
| `source.product` | String | ja | Consumer | Ordner und Textdomain des Providers: `voucher-captain`, `pass-captain`, `affiliate-captain`, `wp-license`, `form-captain`, `review-captain`, `workflow-captain`, künftig `course-captain` |
| `source.core_version` | String | ja | Consumer | Plugin-Version des Providers beim Erfassen |
| `source.contract_version` | String oder `null` | ja | Consumer | Wert der Vertragskonstante; `null`, solange der Provider keine hat |
| `occurred_at` | String, RFC 3339, UTC mit `Z` | ja | Consumer | Zeitpunkt, zu dem die Action feuerte |
| `site.blog_id` | Integer ≥ 1 | ja | Consumer | `get_current_blog_id()` beim Erfassen |
| `subject.type` | String | ja | Adapter | Art des Objekts, zum Beispiel `ticket`, `order`, `voucher`, `commission`, `license` |
| `subject.id` | String | ja | Adapter | Kennung des Objekts. Nie ein Code, Token, Schlüssel oder eine E-Mail-Adresse |
| `subject.id_kind` | `public` oder `record` | ja | Adapter | `public`: eigene öffentliche Kennung des Kerns. `record`: Datensatznummer, nur wo der Kern keine öffentliche Kennung hat (F-02) |
| `actor_user_id` | Integer ≥ 1 | nein | Adapter | WordPress-Benutzer, der mit dem Ereignis verbunden ist. Die Bedeutung legt der Ereignistyp fest (siehe unten). Fehlt, wenn es keinen Benutzer gibt; `0` ist nicht erlaubt |
| `data` | Objekt | ja (darf leer sein) | Adapter | erlaubte Felder des Ereignistyps; nur Zeichenketten, Zahlen, Wahrheitswerte und `null`; höchstens 24 Felder |
| `idempotency_key` | String, 64 Hex-Zeichen | ja | Consumer | Schlüssel zum Erkennen von Doppelten (R-7.8) |
| `correlation_id` | String, UUID Version 4 | ja | Consumer | verbindet alles, was zu einem Ablauf gehört. Bei einem Ereignis ohne Vorgänger gleich `id` |
| `causation_id` | String, UUID Version 4, oder `null` | ja | Consumer | `id` des Befehls, der dieses Ereignis ausgelöst hat; `null`, wenn kein Workflow es ausgelöst hat |
| `caused_by_workflow_id` | Integer ≥ 1 | nur wenn `causation_id` gesetzt ist | Consumer | Kennung des Workflows, dessen Aktion das Ereignis ausgelöst hat (R-7.12) |
| `chain_depth` | Integer 0 bis 8 | ja | Consumer | `0` ohne auslösenden Workflow, sonst Tiefe des auslösenden Ereignisses plus 1 |

Weitere Felder auf der obersten Ebene sind nicht erlaubt.

**Bedeutung von `actor_user_id` in v1.** Die Benutzer-ID ist nicht immer die betroffene Person:

| Ereignisse | `actor_user_id` ist |
|---|---|
| `pass.ticket.checked_in`, `pass.ticket.checked_out` | der angemeldete Benutzer am Einlass (`get_current_user_id()`, `PC:src/Checkin.php:153`), nicht der Gast |
| `voucher.voucher.redeemed`, `voucher.redemption.*` | `user_id` aus dem Array der Action; bei der Einlösung im Shop und am Schalter der angemeldete Benutzer (`get_current_user_id()`, `VC:src/VoucherRepository.php:776-795`) |
| `voucher.store_credit.changed` | der Kunde, dessen Guthaben sich geändert hat |
| `license.*` | der Kunde, dem die Lizenz gehört (Abschnitt 11) |
| alle Ereignisse von Pass Captain und Voucher Captain | wie in den Feldtabellen in 4.2 und 4.3 angegeben (16.11.2026); diese gehen den Zeilen oben vor |
| alle Ereignisse von Form Captain (`form.*`) | wie in der Feldtabelle in 4.6.1 angegeben; bei `form.entry.created` nie gesetzt |
| alle Ereignisse von Review Captain (`review.*`) | wie in der Feldtabelle in 4.6.3 angegeben; nie der Verfasser einer Bewertung ohne Anmeldung |
| alle anderen | nicht gesetzt, solange die Tabelle hier nichts nennt |

Der Gast eines Tickets und der Empfänger eines Gutscheins haben oft kein WordPress-Konto. Für sie gibt es dann keine Benutzer-ID; der Adapter erreicht sie nur über die Lesefunktionen des Providers (R-5.6, F-13).

**Erlaubte Felder in `data`.** Für jeden Ereignistyp legt der Adapter eine feste Liste an und veröffentlicht sie mit seinem Add-on. Für Pass Captain und Voucher Captain stehen die Listen in den Feldtabellen in 4.2 und 4.3, für Form Captain in 4.6.1 und 4.6.2, für Review Captain in 4.6.3; maschinenlesbar in [`catalog/suite-events-v1.json`](catalog/suite-events-v1.json), dort mit dem Datentyp je Feld (`data_types`: `integer`, `number`, `boolean`, `string`, `currency`, `datetime`). Außerdem gilt:

| Ereignistyp | Felder |
|---|---|
| `affiliate.code_request.approved` | `affiliate_id` (Integer), `partner_code` (String, öffentlich), `main` (Wahrheitswert) |
| `affiliate.commission.approved` | `affiliate_id`, `amount_minor`, `currency`, `kind` |
| `license.license.status_changed` | `product` (Produktschlüssel), `plan` (`single`, `business`, `agency`), `status`, `previous_status`, `lifecycle_action` |
| `license.license.issued`, `license.license.plan_changed` | `product`, `plan`, `status` (Abschnitt 11) |

Die Listen der übrigen Ereignistypen von Affiliate Captain sind eine Aufgabe dieses Repositorys vor Version 1.0.0 (siehe `TODO.md`, WF-SPEC-03).

Beispiele: [`pass.ticket.checked_in.json`](examples/pass.ticket.checked_in.json), [`voucher.voucher.redeemed.json`](examples/voucher.voucher.redeemed.json), [`voucher.voucher.issued_standalone.json`](examples/voucher.voucher.issued_standalone.json) (durch einen Workflow ausgelöst), [`license.license.status_changed.json`](examples/license.license.status_changed.json). Seit dem 16.11.2026 liegt für jeden Ereignistyp von Pass Captain (13) und Voucher Captain (16) ein Beispiel unter [`examples/`](examples/), aufgebaut nach den Feldtabellen in 4.2 und 4.3. Die Werte sind erfunden; die `public_id` hat das Format des Kerns (32 Hex, 4.2). [`affiliate.code_request.approved.json`](examples/affiliate.code_request.approved.json) zeigt den erlaubten Partnercode, [`affiliate.commission.approved.json`](examples/affiliate.commission.approved.json) einen Geldbetrag (D8b). `python3 bin/validate-spec-examples.py` prüft Schema und Beispiele gegeneinander.

## 7. Zustellung

### 7.1 Garantie (D9)

- R-7.1 Ab dem Speichern im Consumer gilt **mindestens einmal**: Ein gespeichertes Ereignis wird verarbeitet, bis es erledigt ist oder im Zustand `dead_letter` liegt. „Genau einmal“ wird nicht zugesagt.
- R-7.2 Zwischen dem Commit des Providers und dem Speichern im Consumer gilt **höchstens einmal**: Bricht der Prozess dazwischen ab, ist das Ereignis verloren. Das ist die Folge von D5 und D9 (keine Ausgangstabelle im Kern) und wird hingenommen. Ein Workflow DARF NICHT die einzige Stelle sein, an der eine Pflicht des Betreibers erfüllt wird.
- R-7.3 Der Consumer speichert, wiederholt und erkennt Doppelte. Kein Provider wiederholt für die Suite.

### 7.2 Erfassen nach dem Commit

- R-7.4 Der Adapter hängt sich an die Action des Providers:
  - Feuert die Action belegt nach dem Commit (Spalte „Nach Commit: ja“), übergibt der Adapter das Ereignis sofort an den Consumer.
  - Ist das unbelegt („zu prüfen“) oder bekannt falsch, MUSS der Consumer vor dem Start eines Workflows den Zustand des Objekts über die Lesefunktion des Providers gegenlesen. Stimmt er nicht mit dem Ereignis überein, wird das Ereignis mit dem Zustand `discarded` abgelegt.
- R-7.5 Der Adapter registriert seinen Callback mit einer Priorität größer als 10, damit die eigenen Folgeschritte des Kerns vorher laufen.
- R-7.6 Der Callback des Adapters MUSS jede Ausnahme abfangen (`\Throwable`). Ein Fehler im Adapter oder im Consumer DARF den auslösenden Ablauf des Providers (Kauf, Einlass, Einlösung) nie abbrechen oder verlangsamen: im Callback genau ein Schreibvorgang in den Speicher des Consumers, keine HTTP-Aufrufe, keine Mails, keine Ausführung von Workflows.
- R-7.7 Der Adapter öffnet und schließt im Callback keine Datenbanktransaktion.

Stand je Provider:

| Provider | Actions feuern nach dem Commit | Beleg |
|---|---|---|
| Voucher Captain | ja für alle Actions mit Vermerk „after COMMIT“ (9 der 15 Actions in 4.3); die sechs ohne Vermerk feuern nach Schreibvorgängen im Autocommit, ohne Puffer bis COMMIT (Z-05, geprüft 16.11.2026); für sie gilt Gegenlesen | `VC:docs/HOOKS.md:5-6`, `VC:CONNECTORS.md:39` |
| Pass Captain | ja für alle 13 Actions in 4.2, an allen Auslöseorten nach COMMIT oder im Autocommit (Z-02, geprüft 16.11.2026); bei `wpcap_occurrence_rescheduled` und der Admin-Absage eines Termins feuert die Action auch, wenn das UPDATE fehlschlug, daher dort Gegenlesen | Fundstellen in 4.2 |
| Affiliate Captain | ja für alle 21. Der Kern feuert nur diese fachlichen Actions; eine zusätzliche Workflow-Action gibt es seit der Entfernung nach D13 nicht mehr | `AC:docs/CONTRACT.md:210` |
| Lizenzserver | alle 3 nach dem Commit oder im Autocommit, keine in einer offenen Transaktion (Z-10, geprüft 16.11.2026); eine Zusage gibt es nicht, der Lizenzserver sagt selbst „keine Garantie nach dem Commit“; daher Gegenlesen | `LIC:docs/TODO.md`, Abschnitt 12 |
| Review Captain | ja für alle 3 Actions in 4.6.3, im Autocommit (keine Transaktion in `RC:src/`). Kein Gegenlesen; der Adapter liest nur die Sterne und die Art der Entfernung nach (R-4.27, R-4.28) | Fundstellen in 4.6.3 |
| Form Captain | ja für alle 8 Actions in 4.6.1, nach dem Commit oder im Autocommit (Z-11). Ausnahme Savepoint-Modus von `form_captain_entry_created_v1`: vor dem Commit des Aufrufers; der Schreibvorgang des Consumers gehört dann zur selben Transaktion (R-4.21). Kein Gegenlesen (R-4.19) | Fundstellen in 4.6.1 |

### 7.3 Idempotenzschlüssel

- R-7.8 `idempotency_key` = SHA-256 (hexadezimal) über die mit `|` verbundenen Werte: MAJOR der Spezifikation, `site.blog_id`, `type`, `subject.type`, `subject.id`, Diskriminator.
- R-7.9 Der Diskriminator unterscheidet zwei echte Ereignisse desselben Typs am selben Objekt und ist für eine Wiederholung derselben Action gleich:

| Art des Ereignisses | Diskriminator |
|---|---|
| geschieht je Objekt nur einmal (`*.created`, `*.issued*`, `pass.order.paid`, `affiliate.payout.paid`, `affiliate.affiliate.erased`) | die feste Zeichenkette `once` |
| Statuswechsel (`*.status_changed`, `affiliate.commission.approved` und verwandte) | alter Status, neuer Status und `occurred_at` auf die Sekunde |
| `pass.ticket.checked_in`, `pass.ticket.checked_out` | `idempotency_key` aus `$context`, wenn gesetzt; sonst `occurred_at` auf die Sekunde |
| `voucher.voucher.redeemed`, `voucher.redemption.*` | `redemption_key`, wenn nicht leer; sonst `committed_at` |
| `voucher.redemption.reversed` | die Journal-ID |
| Ereignisse von Form Captain (`form.*`) | wie in der Feldtabelle in 4.6.1 angegeben |
| Ereignisse von Review Captain (`review.*`) | wie in der Feldtabelle in 4.6.3 angegeben |
| alle anderen | `occurred_at` auf die Sekunde |

- R-7.10 Der Speicher des Consumers hat einen eindeutigen Index über `site.blog_id` und `idempotency_key`. Ein zweites Ereignis mit demselben Schlüssel wird nicht gespeichert und startet nichts.

### 7.4 Speicher, Zustände und Wiederholung

- R-7.11 Der Consumer speichert jeden Umschlag in einer eigenen Tabelle je Website. Zustände:

| Zustand | Bedeutung |
|---|---|
| `received` | gespeichert, noch nicht verarbeitet |
| `processing` | ein Worker hält das Ereignis mit einer befristeten Sperre |
| `done` | alle passenden Workflows wurden gestartet (oder es passte keiner) |
| `retry_wait` | Verarbeitung fehlgeschlagen, nächster Versuch geplant |
| `dead_letter` | alle Versuche verbraucht; nur ein Mensch kann es erneut einreihen |
| `discarded` | Gegenlesen nach R-7.4 widersprach dem Ereignis |
| `loop_blocked` | die Tiefe der Kette überschritt die Grenze (R-7.13) |
| `paused` | der Adapter war beim Verarbeiten nicht mehr berechtigt (Abschnitt 10) |

- Wiederholung: höchstens **8 Versuche** mit den Abständen 1 Minute, 5 Minuten, 15 Minuten, 1 Stunde, 3 Stunden, 6 Stunden, 12 Stunden, 24 Stunden; danach `dead_letter`. Acht Versuche entsprechen der Warteschlange von Pass Captain (`PC:docs/SUITE-EVENTS.md:15`) und dem Standard von Affiliate Captain (`AC:docs/CONTRACT.md:217`). Die Abstände sind ein Vorschlag (F-15). **Entschieden am 23.11.2026 (F-15, E-S5):** acht Versuche sind die Vorgabe; der Betreiber kann 1 bis 8 einstellen (Einstellung `retry_attempts`, SET-WC-09), die Abstände sind dann die ersten n Stufen.
- Wiederholt wird nur bei vorübergehenden Fehlern (Datenbank, Zeitüberschreitung, Sperre). Fehler im Schema, fehlende Rechte und ein unbekannter Vertrag führen sofort zu `dead_letter` oder `paused`.
- Wiederholt wird die Verarbeitung des Ereignisses, nicht die Action des Providers. Jeder Schritt eines Workflows trägt einen eigenen Idempotenzschlüssel (R-8.4), damit eine Wiederholung keinen Befehl doppelt ausführt.
- Ein Ereignis in `dead_letter` MUSS im Admin sichtbar sein, mit Typ, Objekt, Zeitpunkt und letztem Fehler, ohne verbotene Werte.

### 7.5 Reihenfolge

- Über verschiedene Ereignistypen und verschiedene Objekte hinweg gibt es **keine** Reihenfolge.
- Für dasselbe `subject` verarbeitet der Consumer die Ereignisse nach Möglichkeit in der Reihenfolge des Speicherns. Ein Ereignis in `retry_wait` hält spätere Ereignisse desselben Objekts nicht auf. Ein Workflow, der eine Reihenfolge braucht, MUSS den Zustand des Objekts gegenlesen.

### 7.6 Schutz vor Schleifen

- R-7.12 Löst ein Befehl eines Workflows eine Action eines Providers aus, trägt das daraus entstehende Ereignis `causation_id` (die Kennung des Befehls), `caused_by_workflow_id`, die `correlation_id` des auslösenden Ereignisses und `chain_depth` plus 1. Der Consumer erkennt das auf zwei Wegen:
  1. Während ein Befehl läuft, hält der Consumer dessen Kontext im Prozess; jedes in dieser Zeit erfasste Ereignis erbt ihn.
  2. Für Folgen, die später entstehen (zum Beispiel die Zustellung eines ausgestellten Gutscheins durch eine Hintergrundaufgabe), merkt sich der Consumer das vom Befehl erzeugte Objekt (`subject`) und ordnet spätere Ereignisse an diesem Objekt dem Workflow zu.
- R-7.13 Ein Ereignis mit `chain_depth` größer als **3** startet keinen Workflow und erhält den Zustand `loop_blocked`. Das Schema erlaubt Werte bis 8, damit die Grenze später ohne neue MAJOR-Version angehoben werden kann. Die Zahl 3 ist ein Vorschlag (F-15). **Entschieden am 23.11.2026 (F-15, E-S5):** 3 ist die Vorgabe; der Betreiber kann 1 bis 8 einstellen (Einstellung `max_chain_depth`, SET-WC-09).
- R-7.14 Ein Workflow startet nie durch ein Ereignis, dessen `caused_by_workflow_id` seine eigene Kennung ist. Das gilt ohne Ausnahme und unabhängig von der Tiefe.
- R-7.15 Ereignisse des Typs `workflow.*` (Ereignisse des Consumers über sich selbst) sind in Version 1 nicht definiert.

## 8. Befehle

Ein Befehl ist die einzige Art, auf die ein Workflow etwas in einem Provider bewirkt. Befehle führt der Adapter aus; er ruft dafür eine öffentliche Funktion des Providers auf.

### 8.1 Erlaubte Befehle in Version 1 (D11, D11b)

Ohne Wirkung im Provider:

| Befehl | Wirkung | Führt aus |
|---|---|---|
| Lesende Abfrage (`<produkt>.<objekt>.read`) | Zustand eines Objekts lesen, zum Gegenlesen (R-7.4) und für Bedingungen | Adapter über die öffentlichen Lesefunktionen des Providers |
| `notify.admin` | Nachricht an die Betreiber-Adresse oder an Benutzer mit einer festen Berechtigung | Consumer |
| `notify.user` | zusätzliche Nachricht an einen Benutzer, adressiert über die Benutzer-ID (R-5.6, Abschnitt 9) | Consumer |
| `workflow.tag.set`, `workflow.tag.remove` | Markierung an einem Objekt, gespeichert im Consumer | Consumer |
| `workflow.note.add` | Notiz an einem Objekt, gespeichert im Consumer | Consumer |

Mit Wirkung im Provider, genau vier, alle im Voucher-Kern (D11b):

| Befehl | Wirkung |
|---|---|
| `voucher.issue` | einen Gutschein ausstellen |
| `voucher.void` | einen Gutschein sperren |
| `voucher.reactivate` | einen gesperrten Gutschein reaktivieren |
| `voucher.redeem` | einen Gutschein ganz oder teilweise einlösen |

Lesefunktionen heute: Affiliate Captain hat `wpac_get_affiliate()`, `wpac_get_commission()` und `wpac_get_payout()` (`AC:docs/CONTRACT.md:7`, Vertrag seit 1.3.0, „Rückgabe stabil innerhalb von Version 1“, `AC:docs/CONTRACT.md:306-308`; geprüft am 16.11.2026, Z-07). Sie prüfen den angemeldeten Benutzer nicht. `wpac_get_affiliate()` liefert auch Name und E-Mail-Adresse, `wpac_get_payout()` auch `paypal_email` und `claim`; der Adapter liest daraus nur die Felder der Positivliste. Es fehlen Lesefunktionen für Gutschriften, Werbemittel, den Hauptcode eines Partners und Provisionen je Bestellung; `wpac_workflow_read()` gibt es nicht mehr (R-4.6). Review Captain hat im Container-Service `workflow` (`ReviewCaptain\Plugin::instance()->workflow()`, `RC:src/Workflow/WorkflowService.php`, Vertrag `WorkflowService::CONTRACT` = `1.0`) die lesenden Methoden `aggregates()` (Zähler je Plattform, Status und Monat, Zellen unter 5 maskiert, nie Texte, Namen oder Kennungen einzelner Bewertungen) und `request_capabilities()` (ob ein Planer von Review Captain Pro angemeldet ist); beide prüfen den angemeldeten Benutzer nicht. Eine dokumentierte Lesefunktion für eine einzelne Bewertung gibt es nicht; `reviews()->find_by_uuid()` ist eine Funktion ohne Vertrag (R-4.27, Anforderung RC-4). Im Lizenzserver gibt es seit dem 16.11.2026 die Lesefassade `WPLC\License\ReadFacade::get()` (Branch `claude/plugin-todo-liste-2a83v4`, noch nicht gemergt).

**Anerkannte Lesefunktionen für Voucher Captain und Pass Captain (WF-SPEC-15, geprüft am 16.11.2026, Z-07).** *[Nachtrag 16.11.2026: Beide Kerne haben auf dem Branch `claude/plugin-todo-liste-2a83v4` eine dokumentierte Lesefassade für Zustände mit Vertrag 1.2.0: Pass Captain `wpcap_get_order_state()`, `_ticket_state()`, `_refund_state()`, `_occurrence_state()`, `_event_state()`; Voucher Captain `vcap_read_voucher()`, `_gift_card()`, `_store_credit()`, `_redemption()`, `_redemptions()`, `_order_vouchers()`. Nach dem Merge ersetzen sie die Zeilen „ohne Vertrag“ unten für Zustände; Kontakt und Einwilligung bleiben offen (F-13).]* „Vertrag“ heißt: im Kern als öffentliche Schnittstelle dokumentiert. Eine Funktion ohne Vertrag DARF ein Adapter nur bis zu einer dokumentierten Lesefassade nutzen, nur lesend und nur mit Positivliste (R-5.2, R-5.3); jede Nutzung nennt er in seiner Kompatibilitätsangabe (R-13.4).

| Provider | Zweck | Funktion | Vertrag | Hinweis |
|---|---|---|---|---|
| Voucher Captain | Zustand eines Gutscheins über die `voucher_id` | `vcap()->vouchers->get( int $id )` (`VC:src/VoucherRepository.php:175`) | **nein**, nur erwähnt (`VC:ADAPTERS.md:79`, `VC:CONNECTORS.md:35`) | liefert das ganze `Voucher`-Objekt mit Code |
| Voucher Captain | Gutscheine einer Bestellung | `vcap()->api_v1->order_lines( WC_Order )` (`VC:src/API/V1/VoucherService.php:122`) | ja (`VC:CONNECTORS.md`) | liefert je Position auch den Code |
| Voucher Captain | Zustand über den Code | `vcap()->api_v1->lookup()`, `vcap()->redemptions->check()`, `get()` (`VC:API.md:7`, `:58-63`) | ja | für Workflows **unbrauchbar**: ein Workflow kennt keinen Code (R-5.1) |
| Voucher Captain | Kontakt und Einwilligung des Empfängers | – | – | **fehlt.** Empfänger haben meist kein Benutzerkonto; ein Einwilligungsfeld gibt es nicht (`VC:src/Voucher.php:20-48`). `Workflow\Services::contact()` auf dem Branch von Pull Request 16 entfällt mit D13 |
| Pass Captain | Beleg | `wpcap_get_invoice( $id, $type )`, `PassCaptain\Api\Invoices::get()` (`PC:src/functions.php:11-19`, `PC:docs/EXTENSIONS.md:103`, `:141`), seit 1.5.0 | ja | einzige vertraglich zugesagte Lesefunktion |
| Pass Captain | Bestellung | `PassCaptain\Orders::get( $order_id )` (`PC:src/Orders.php:1544-1547`) | **nein** | liefert die ganze Zeile mit `customer_email`, Name und Zugangshash |
| Pass Captain | Tickets einer Bestellung | `PassCaptain\Orders::tickets_for_order( $order_id )` (`PC:src/Orders.php:1516`) | **nein** | mit `public_id`, Status, Ticketart und Namen, ohne Ticketcode |
| Pass Captain | Erstattung | `PassCaptain\Refunds::get( $refund_id )` (`PC:src/Refunds.php:744`) | **nein** | enthält Freitext `reason` |
| Pass Captain | Kontakt und Einwilligung zu einem Ticket | `PassCaptain\Sales\Consents::for_order()`, `missing_for_ticket()` (`PC:src/Sales/Consents.php:262`, `:140`); Spalte `attendee_email` | **nein** | eine Lesefunktion „Kontakt zu diesem Ticket“ gibt es nicht |

Beobachtet am 16.11.2026, nicht im gelesenen Stand: Auf dem nicht gemergten Branch `claude/youthful-bardeen-7ejd7a` von Pass Captain gibt es `PassCaptain\Integrations\Vouchers\Reconciliation::orders()` mit Versionskonstante (`99cd25a`): Bestellungen, die mit Gutschein bezahlt wurden, mit `order_id`, `public_id`, Status und Zahlstatus. Sie ist für den Abgleich mit Voucher Captain gebaut, nicht als allgemeine Lesefassade; ob ihre Gutschein-Felder einen Code enthalten, ist nicht geprüft. Kandidat für PC-4, sobald sie gemergt ist.

Folge: Für beide Kerne fehlt eine dokumentierte Lesefassade für Zustand, Kontakt und Einwilligung (F-13, W-2; Anforderungen VC-7 und PC-4 in [`ANFORDERUNGEN-andere-repos.md`](ANFORDERUNGEN-andere-repos.md)). Bis dahin gilt die Tabelle oben.

Markierungen und Notizen liegen in Version 1 nur im Consumer. Eine Notiz im Provider ist kein Befehl der Version 1.

### 8.2 Ausdrücklich verboten in Version 1 (D11, D11b)

Ein Workflow DARF NICHT, weder über einen Befehl noch auf einem anderen Weg:

- eine Erstattung auslösen, freigeben oder ablehnen, auch nicht die Erstattung einer Einlösung,
- eine Bestellung, ein Ticket, einen Termin oder ein Event stornieren, widerrufen, sperren oder reaktivieren,
- einen Gutschein in Tickets umtauschen oder ein Ticket ausstellen, übertragen, einchecken oder auschecken,
- den Wert eines Gutscheins oder ein Kundenguthaben (Store Credit) ändern, außer als Folge eines der vier Gutschein-Befehle,
- einen Gutschein neu ausstellen (neuer Code), aufladen oder in Guthaben umwandeln,
- eine Provision buchen, korrigieren, freigeben oder ablehnen (kein Aufruf von `wpac_create_adjustment()` oder `wpac_set_commission_status()`, auch nicht nach einer menschlichen Aktion),
- eine Auszahlung anlegen, beanspruchen, abschließen oder stornieren,
- den Status eines Partners ändern,
- eine Lizenz, eine Aktivierung, ein Site-Limit, einen Tarif oder einen Lizenzschlüssel ändern oder ausgeben,
- eine Mail eines Kerns unterdrücken, ersetzen oder erneut auslösen (Abschnitt 9).

Ein Adapter, der einen dieser Wege anbietet, erfüllt diese Spezifikation nicht.

### 8.3 Die vier Gutschein-Befehle

**Regel (D11, D11b).** Ein Workflow darf Gutscheine ausstellen, sperren, reaktivieren und einlösen. Für jeden der vier Befehle gelten Grenzen, Protokoll, Idempotenz, Berechtigung und Missbrauchsschutz, und alle fünf werden **im Voucher-Kern** durchgesetzt, nicht im Workflow und nicht im Adapter. Der Kern prüft dabei keine Lizenz; die Grenzen gelten für jeden Aufrufer dieser Wege.

#### 8.3.1 Gemeinsame Regeln

- R-8.1 Jeder der vier Befehle hat im Voucher-Kern einen eigenen öffentlichen Einstiegspunkt für automatische Aufrufe. Die Einstiegspunkte kommen als additive Änderung mit einer neuen MINOR-Version von `VCAP_CONTRACT_VERSION`.
- R-8.2 Jeder Aufruf nennt den Gutschein über seine `voucher_id`, **nie** über den Code. Ein Workflow kennt keinen Code (R-5.1).
- R-8.3 Pflichtfelder jedes Aufrufs: `idempotency_key` (nach R-8.4, höchstens 100 Zeichen), `reason` (fester Schlüssel aus einer Liste des Adapters, kein Freitext), `workflow_id`, `run_id`, `step_id`, `authorized_by_user_id` (8.4).
- R-8.3a Ein zweiter Aufruf mit demselben `idempotency_key` bewirkt nichts und liefert das Ergebnis des ersten.
- R-8.3b Zählen und Ausführen sind im Kern atomar: Zwei gleichzeitige Aufrufe dürfen keine Grenze gemeinsam überschreiten.
- R-8.3c Jeder Aufruf, auch ein abgelehnter, steht im Protokoll des Kerns mit Befehl, Workflow, Lauf, Schritt, Gutschein-ID, Betrag, Ergebnis und Zeitpunkt; ohne Code.
- R-8.3d Der Betreiber KANN jede Grenze senken und jeden der vier Befehle einzeln abschalten. Er KANN keine Grenze über den festen Höchstwert des Kerns anheben. Ab Werk sind alle vier ausgeschaltet (entschieden, F-05).
- R-8.3e Das Ergebnis enthält `voucher_id` und Zustand, nie den Code. Mails zu Ausstellung, Sperre und Einlösung versendet der Kern nach seinen eigenen Regeln (D10).
- R-8.3f Die Einstiegspunkte kommen ohne jede Lizenz- oder Rechteprüfung aus (R-2.4).

**Feste Grenzen (Entscheidung des Inhabers vom 16.11.2026 zu F-05).** Die Zahlen sind Voreinstellung und zugleich Obergrenze im Voucher-Kern; der Betreiber kann sie nur senken. Alle vier Befehle sind ab Werk ausgeschaltet.

| Befehl | Höchstbetrag | Anzahl je Kalendertag und Website | Weitere Grenze |
|---|---|---|---|
| `voucher.issue` | 100 EUR je Gutschein | 50 | 1 je Kalendertag und Empfänger |
| `voucher.void` | – | 50 | – |
| `voucher.reactivate` | – | 50 | – |
| `voucher.redeem` | 100 EUR je Einlösung | 50 | – ; **jeder** Gutschein darf eingelöst werden, nicht nur ein vom Workflow ausgestellter |

Ein Aufruf über dem Höchstbetrag wird abgelehnt, nicht gekürzt. Der Kalendertag richtet sich nach der Zeitzone der Website; gezählt wird über alle Workflows zusammen. Die Tageszähler für Sperren und Reaktivieren sind getrennt.

Die Beträge sind in Euro entschieden. Was in einem Shop mit anderer Währung gilt, ist nicht entschieden (F-05a). Bis dahin gilt: Der Einstiegspunkt lehnt Beträge ab, wenn die Shopwährung nicht EUR ist und der Betreiber keinen eigenen, niedrigeren oder gleichen Höchstbetrag in seiner Währung eingetragen hat.

#### 8.3.2 `voucher.issue`

| Feld | Pflicht | Regel |
|---|---|---|
| `recipient_user_id` | ja | WordPress-Benutzer, der den Gutschein bekommt. Eine E-Mail-Adresse wird nicht übergeben; der Kern löst sie aus dem Benutzerkonto auf |
| `amount_minor`, `currency` | ja | fester Betrag in der Währung des Shops; keine Prozent-Gutscheine |
| `product_id` | nein | Gutscheinprodukt, dessen Einstellungen gelten |

Grenzen: siehe Tabelle in 8.3.1.

Stand im Kern heute, `Issuer::issue_standalone( array $args )` (`VC:src/Issuer.php:987`):

| Eigenschaft | Stand heute | Fundstelle |
|---|---|---|
| Idempotenz | vorhanden: `reference` (bis 100 Zeichen); ein zweiter Aufruf stellt nichts aus und liefert `vcap_reference_used` mit der Gutschein-ID | `VC:src/Issuer.php:975-977`, `:1052-1058` |
| Protokoll | Journaleintrag `issued` mit `source`, `reference` und `reason` | `VC:src/Issuer.php:1069` |
| Berechtigung | **keine Prüfung**; der Aufrufer ist verantwortlich. Genannt wird `vcap_manage_vouchers` | `VC:src/Issuer.php:940-942` |
| Höchstwert, Tageszähler | fehlen | – |
| Empfänger über Benutzer-ID | fehlt; erwartet `recipient_email` | `VC:src/Issuer.php:956-957` |
| eigener Code des Aufrufers | möglich (`code`, `allow_weak`) | `VC:src/Issuer.php:966-969` |

Der neue Einstiegspunkt MUSS zusätzlich einen eigenen Code des Aufrufers, `allow_weak`, Prozent-Gutscheine und `paid = true` ablehnen.

#### 8.3.3 `voucher.void` und `voucher.reactivate`

| Feld | Pflicht | Regel |
|---|---|---|
| `voucher_id` | ja | der Gutschein |

Grenzen: siehe Tabelle in 8.3.1. `voucher.void` löst **keine** Erstattung aus und bucht nichts zurück (8.2); `voucher.reactivate` gilt nur für Gutscheine, die nicht wegen einer Erstattung gesperrt wurden.

Stand im Kern heute: `Issuer::void( Voucher $voucher, string $note = '', bool $refund = false ): bool` (`VC:src/Issuer.php:2012`) und `Issuer::reactivate( Voucher $voucher, bool $after_refund = false ): bool` (`VC:src/Issuer.php:2072`). Beide nehmen ein `Voucher`-Objekt, eine freie Notiz und einen Schalter für den Erstattungsfall. Geprüft am 16.11.2026 (Z-15):

| Eigenschaft | `void()` | `reactivate()` |
|---|---|---|
| Berechtigung | keine Prüfung | `vcap_increase_balance` oder `manage_woocommerce` (`Admin\VoucherAdmin::can_increase()`), **nur wenn ein Benutzer angemeldet ist**; Cron und CLI ohne Prüfung (`VC:src/Issuer.php:2077`) |
| Idempotenz | kein Schlüssel; ein schon gesperrter Gutschein liefert `true` und schreibt nichts, außer bei `$refund` auf einen wegen Zahlung ausgesetzten Gutschein | kein Schlüssel; ein nicht gesperrter Gutschein liefert `false` |
| Wirkung des Schalters | `$refund = true` bucht **kein Geld**; es speichert nur die Notiz `voided_refund`, die später `reactivate()` blockiert (`VC:src/Issuer.php:77`, `:2042-2063`). Erstattungen laufen über `void_purchase()`/`void_or_retry()` (`:1918-1981`) | `$after_refund = true` umgeht nur diese Sperre |
| Ereignisse | `vcap_voucher_updated`, `vcap_voucher_event` mit `voided`, beide bis COMMIT gepuffert | `vcap_voucher_event` mit `reactivated` |

Tageszähler und Höchstwerte fehlen in beiden. Der Branch von Pull Request 16 (`hvollo-refactors`, `82145ed`) enthält eigene Dienste `Workflow\Services::void()` und `reactivate()` mit Idempotenzschlüssel, Protokolleintrag `workflow_command` und Berechtigungen (`vcap_manage_vouchers` für Sperren, `vcap_increase_balance` für Reaktivieren); sie gehören zum vorläufigen Provider, der nach D13 entfernt wird (Z-16), und haben weder Höchstwerte noch Tageszähler.

Der neue Einstiegspunkt MUSS `$refund` beziehungsweise `$after_refund` fest auf `false` setzen und statt der freien Notiz nur `reason` als festen Schlüssel annehmen.

#### 8.3.4 `voucher.redeem`

| Feld | Pflicht | Regel |
|---|---|---|
| `voucher_id` | ja | der Gutschein |
| `amount_minor`, `currency` | ja | einzulösender Betrag; nie mehr als der Restwert |
| `target` | ja | fester Schlüssel des Adapters dafür, wofür eingelöst wird |

Grenzen: siehe Tabelle in 8.3.1. Eingelöst wird nie mehr als der Restwert des Gutscheins.

Stand im Kern heute: Die Einlöse-API `API\V1\RedemptionService` arbeitet in zwei Schritten und **über den Code**: `reserve( string $code, array $args )` (`VC:src/API/V1/RedemptionService.php:150`), danach `commit( string $key, array $args )` (`:378`); dazu `release()` (`:389`) und `refund()` (`:408`). Sie hat einen Idempotenzschlüssel (`redemption_key`) und feuert `vcap_voucher_redeemed` genau einmal nach dem Commit (`VC:docs/HOOKS.md:53`). Einen Weg über die `voucher_id`, Tageszähler und einen Höchstbetrag gibt es nicht. Geprüft am 16.11.2026 (Z-15): Die PHP-Klasse prüft keine Berechtigung, nur den Besitz, wenn `user_id` übergeben wird (`VC:src/API/V1/RedemptionService.php:428`, `:635`); die Berechtigung sitzt allein in der REST-Schicht (angemeldet plus Filter `vcap_rest_can_redeem`, Ziele nur für Manager oder `vcap_redeem_api`, `VC:src/API/V1/RestController.php:151`, `:168-179`, `:234`). Der Dienst `Workflow\Services::redeem()` auf dem Branch von Pull Request 16 nimmt weiterhin einen **Code**.

Der neue Einstiegspunkt MUSS den Gutschein über die `voucher_id` annehmen, Reservieren und Bestätigen in einem Aufruf erledigen und DARF `refund()` nicht erreichbar machen.

`VC:README.md:96` sagt heute: „Kein Formular und kein Workflow stellt selbstständig Gutscheine aus.“ Dieser Satz ist durch D11 und D11b überholt und im Voucher-Repository zu ändern.

### 8.4 Berechtigung bei Hintergrundläufen

Ein Workflow läuft meist ohne angemeldeten Benutzer (Cron, Action Scheduler).

- R-8.4 Jeder Befehl trägt einen Idempotenzschlüssel aus Workflow, Version des Workflows, `id` des auslösenden Ereignisses und Schritt. Eine Wiederholung desselben Schritts nutzt denselben Schlüssel.
- R-8.5 Der Consumer DARF NICHT `wp_set_current_user()` aufrufen, um einem Lauf die Rechte eines Administrators zu geben.
- R-8.6 Jeder schreibende Befehl nennt die Berechtigung, die ein Mensch für dieselbe Handlung bräuchte. Für `voucher.issue` ist das `vcap_manage_vouchers` (`VC:src/Issuer.php:940-942`); für Sperren `vcap_manage_vouchers`, für Reaktivieren `vcap_increase_balance` (die Prüfung des Kerns in `reactivate()`, `VC:src/Issuer.php:2077`), für Einlösen `vcap_redeem_api` (die Berechtigung der Einlöse-Ziele, `VC:src/API/V1/RestController.php:151`). Der Kern prüft beim Sperren und Einlösen in PHP heute nichts (Z-15, geklärt am 16.11.2026); die Prüfung leistet der Adapter nach R-8.8.
- R-8.7 Beim Veröffentlichen eines Workflows MUSS der veröffentlichende Benutzer jede dieser Berechtigungen haben. Seine Benutzer-ID wird als `authorized_by_user_id` am Workflow gespeichert.
- R-8.8 Bei jeder Ausführung prüft der Adapter mit `user_can( $authorized_by_user_id, … )`, ob dieser Benutzer noch existiert und die Berechtigung noch hat. Wenn nicht, wird der Schritt nicht ausgeführt und der Workflow pausiert mit Grund.
- R-8.9 Lesende Abfragen und Befehle, die nur im Consumer schreiben, brauchen die Berechtigungen des Consumers beim Veröffentlichen, aber keine Prüfung bei jeder Ausführung.
- R-8.10 Jeder ausgeführte Befehl wird im Laufprotokoll mit `authorized_by_user_id`, Workflow, Lauf, Schritt, Idempotenzschlüssel und Ergebnis festgehalten.

### 8.5 Eigenschaften je Befehl

Diese Tabelle fasst für jeden Befehl der Version 1 zusammen, was 8.1 bis 8.4, 7.4 und 10.1 verlangen. Sie fügt keine neue Pflicht hinzu, außer R-8.11 bis R-8.13.

| Befehl | Idempotenzschlüssel | Seiteneffekt | Wiederholung | Berechtigung beim Veröffentlichen (R-8.7) | Lizenz (Abschnitt 10) | Protokoll |
|---|---|---|---|---|---|---|
| `<produkt>.<objekt>.read` | nach R-8.4; ohne Wirkung, dient nur der Zuordnung im Laufprotokoll | keiner | ja, bei vorübergehenden Fehlern | `activate_workflows` (Consumer) | Workflow Captain Pro und Pro-Lizenz des Produkts | Laufprotokoll nach R-8.10, ohne gelesene Werte |
| `notify.admin` | nach R-8.4; der Consumer bucht ihn vor dem Versand (R-8.12) | eine zusätzliche Mail; nicht rückholbar | nur wenn sicher feststeht, dass nicht versendet wurde (R-8.12) | `activate_workflows` (Consumer) | wie das auslösende Ereignis | Laufprotokoll nach R-8.10 mit Vorlagenschlüssel und Empfänger als Berechtigung oder Rolle, nie als Adresse |
| `notify.user` | wie `notify.admin` | eine zusätzliche Mail an einen Benutzer (R-5.6, Abschnitt 9); nicht rückholbar | wie `notify.admin` | `activate_workflows` (Consumer) | wie das auslösende Ereignis | Laufprotokoll nach R-8.10 mit Vorlagenschlüssel und Benutzer-ID, nie mit Adresse |
| `workflow.tag.set`, `workflow.tag.remove` | nach R-8.4 | Markierung im Speicher des Consumers; von Natur aus idempotent | ja | `activate_workflows` (Consumer) | wie das auslösende Ereignis | Laufprotokoll nach R-8.10 mit Markierung und `subject` |
| `workflow.note.add` | nach R-8.4; eindeutig im Speicher des Consumers | Notiz im Speicher des Consumers; ein zweiter Aufruf legt keine zweite Notiz an | ja | `activate_workflows` (Consumer) | wie das auslösende Ereignis | Laufprotokoll nach R-8.10; die Notiz enthält keine verbotenen Werte (Abschnitt 5) |
| `voucher.issue` | nach R-8.4, an den Kern als `idempotency_key` (R-8.3, R-8.3a) | stellt einen Gutschein aus; der Kern versendet seine Mail (R-8.3e) | ja, mit demselben Schlüssel; Ablehnungen wegen Grenze, Schalter oder Berechtigung sind endgültig (R-8.13) | `vcap_manage_vouchers` (R-8.6) | Workflow Captain Pro und Voucher Captain Pro; im Kern eingeschaltet (R-8.3d) | Laufprotokoll nach R-8.10 und Protokoll des Kerns (R-8.3c) |
| `voucher.void` | wie `voucher.issue` | sperrt einen Gutschein, ohne Erstattung | wie `voucher.issue` | `vcap_manage_vouchers` (R-8.6) | wie `voucher.issue` | wie `voucher.issue` |
| `voucher.reactivate` | wie `voucher.issue` | reaktiviert einen gesperrten Gutschein | wie `voucher.issue` | `vcap_increase_balance` (R-8.6) | wie `voucher.issue` | wie `voucher.issue` |
| `voucher.redeem` | wie `voucher.issue` | löst einen Gutschein ganz oder teilweise ein | wie `voucher.issue` | `vcap_redeem_api` (R-8.6) | wie `voucher.issue` | wie `voucher.issue` |

„Wie das auslösende Ereignis“ heißt: Kommt das Ereignis aus einem Captain-Produkt, gilt R-10.1; kommt es aus WordPress oder WooCommerce, braucht es keine Lizenz (F-20, 4.7). Maschinenlesbar: [`catalog/actions-v1.json`](catalog/actions-v1.json).

- R-8.11 Ein Schritt wird höchstens so oft wiederholt wie ein Ereignis (8 Versuche, Abstände aus 7.4) und nur bei vorübergehenden Fehlern. Danach bleibt der Lauf mit Grund stehen; ein Mensch kann den Schritt erneut einreihen, mit demselben Idempotenzschlüssel.
- R-8.12 Für `notify.admin` und `notify.user` bucht der Consumer den Idempotenzschlüssel als „wird gesendet“, bevor er die Mail übergibt, und danach als „gesendet“ oder „fehlgeschlagen“. Findet er einen Schlüssel im Zustand „wird gesendet“ (Abbruch während des Versands), sendet er nicht von selbst erneut, sondern zeigt den Schritt zur Entscheidung durch einen Menschen. Eine Mail lieber nicht als doppelt.
- R-8.13 Lehnt der Voucher-Kern einen Befehl ab, weil eine Grenze erreicht, der Befehl abgeschaltet, die Währung nicht zugelassen oder die Berechtigung entzogen ist, wiederholt der Consumer nicht. Die Ablehnung steht im Laufprotokoll und im Protokoll des Kerns.

## 9. Mails und Erinnerungen (D10)

- R-9.1 Jeder Kern behält seine Mails und Erinnerungen und versendet sie unverändert, ob ein Workflow existiert oder nicht.
- R-9.2 Ein Workflow DARF nur **zusätzliche** Nachrichten senden. Er DARF NICHT eine Mail eines Kerns unterdrücken, verzögern, ersetzen, ihren Inhalt ändern oder ihren Versand übernehmen. Ein Adapter DARF NICHT Filter eines Kerns nutzen, die dessen Mails abschalten oder umschreiben.
- R-9.3 Eine Übergabe der Zuständigkeit („Ownership-Handshake“) gibt es nicht. Kein Kern bekommt dafür einen Schalter.
- R-9.4 Der Editor SOLL beim Anlegen einer zusätzlichen Nachricht anzeigen, welche Mail des Kerns zum selben Anlass ohnehin versendet wird.
- R-9.5 Eine zusätzliche Nachricht an Kunden braucht dieselbe rechtliche Grundlage wie jede andere Mail des Betreibers. Diese Spezifikation regelt das nicht; es gehört in die Rechtsprüfung.

## 10. Lizenzmatrix (D6)

### 10.1 Wer was braucht

| Vorgang | Workflow Captain (Kern) | Workflow Captain Pro | Pro-Lizenz des Produkts |
|---|---|---|---|
| Ein Kern löst seine Actions aus | – | – | – |
| Workflow aus Ereignissen von WordPress oder WooCommerce | nötig | offen (D3, F-10) | – |
| Automation, die ein Ereignis **eines** Captain-Produkts nutzt (zum Beispiel Pass → Nachricht) | nötig | **nötig** | **nötig** (hier Pass Captain Pro) |
| Automation über **zwei** Captain-Produkte (zum Beispiel Voucher → Affiliate) | nötig | **nötig** | **nötig für jedes beteiligte Produkt** |
| Befehle `voucher.issue`, `voucher.void`, `voucher.reactivate`, `voucher.redeem` | nötig | **nötig** | **nötig** (Voucher Captain Pro) |
| Lizenzereignisse im Shop des Herstellers | nötig | **nötig** | – (der Lizenzserver hat kein Add-on) |

- R-10.1 Eine Automation, die ein Ereignis oder einen Befehl eines Captain-Produkts nutzt, braucht Workflow Captain Pro **und** die Pro-Lizenz des Add-ons dieses Produkts (D6). Welches Recht des Produkts dafür steht, ist offen (F-09).
- R-10.2 Geprüft wird nur in Add-ons (R-2.4). Der Adapter für ein Produkt meldet sich beim Consumer nur an, wenn beide Bedingungen erfüllt sind.
- R-10.3 Der Add-on-Kernel kennt heute je Add-on einen Kern und einen Präfix (`CP:packages/addon-kernel/src/CoreRequirement.php:60-64`); `Feature::requires()` beschreibt, was eine Funktion vom Kern braucht, darunter Vertragskonstanten (`CP:packages/addon-kernel/src/Feature.php:41-52`, `CP:packages/addon-kernel/src/CoreCapabilities.php:35-36`). Ein Recht eines **anderen** Add-ons kann eine Funktion nicht verlangen. Das ist eine Aufgabe von `captain-pro` (F-06).

### 10.2 Rechte von Workflow Captain Pro

Im Lizenzserver vorgemerkt sind sechs Schlüssel (`LIC:src/Catalog/EntitlementCatalog.php:118-125`, Gruppe `workflow`, nicht angeboten; `LIC:docs/TODO.md`, WFC-LIC-02):

| Schlüssel | Stand |
|---|---|
| `workflow.suite_integrations` | vorgemerkt. Dieses Recht schaltet die Adapter dieser Spezifikation frei |
| `workflow.webhooks` | vorgemerkt |
| `workflow.event_correlation` | vorgemerkt |
| `workflow.advanced_logic` | vorgemerkt |
| `workflow.recipe_templates` | vorgemerkt |
| `workflow.extended_logs` | vorgemerkt |
| `workflow.custom_connectors` | **nicht reserviert, Entscheidung offen** (F-01); nur in `konzept.md` |
| `workflow.delays` | **nicht reserviert, Entscheidung offen** (F-01); nur in `konzept.md` |
| `workflow.multisite_network` | **nicht reserviert, Entscheidung offen** (F-01); nur in `konzept.md` |

Tarife: `single`, `business`, `agency` mit 1, 5 und 25 Live-Websites und demselben Funktionsumfang. Preise sind nicht bestätigt.

### 10.3 Wenn eine Lizenz endet

Gilt gleich für Workflow Captain Pro und für die Pro-Lizenz eines beteiligten Produkts:

- R-10.4 Die betroffene Automation wird **pausiert**. Der Workflow bleibt gespeichert, lesbar und bearbeitbar und zeigt den Grund an.
- R-10.5 In keinem Kern ändert sich etwas. Kein Kern erfährt vom Ende der Lizenz.
- R-10.6 Daten bleiben: Workflows, Versionen, Protokolle, Markierungen, Notizen und gespeicherte Ereignisse werden wegen einer abgelaufenen Lizenz nicht gelöscht. Es gelten nur die Aufbewahrungsfristen aus Abschnitt 12.
- R-10.7 Solange die Lizenz fehlt, meldet sich der betroffene Adapter nicht an; Ereignisse dieses Produkts werden nicht erfasst und später nicht nachgeholt. Bereits gespeicherte, noch nicht verarbeitete Ereignisse erhalten den Zustand `paused`.
- R-10.8 Geplante Schritte werden nicht ausgeführt. Nach der Verlängerung holt der Consumer nichts von selbst nach; der Betreiber gibt pausierte Läufe nach einer Vorschau einzeln oder gesammelt frei.
- R-10.9 Ein Workflow, der nur Ereignisse von WordPress oder WooCommerce nutzt, ist vom Ende der Pro-Lizenz eines Produkts nicht betroffen.

## 11. Lizenzereignisse im Shop des Herstellers (D2)

- R-11.1 Lizenzereignisse gibt es nur im WordPress des Herstellers, in dem der Lizenzserver läuft. Der Adapter hängt sich dort an dessen PHP-Actions im selben Prozess. Es gibt keine Zustellung an Websites von Kunden, keinen ausgehenden Webhook und keine Ausgangstabelle dafür.
- R-11.2 Der Adapter für Lizenzereignisse liegt in Workflow Captain Pro (R-2.3; der Lizenzserver hat kein Add-on). Der kostenlose Workflow-Kern kennt keine Lizenzereignisse.
- R-11.3 Der Adapter DARF NICHT auf die Workflow-Lizenz der eigenen Installation reagieren, um sich selbst zu steuern. Ob Workflow Captain Pro lizenziert ist, entscheidet allein der Add-on-Kernel.

**Actions heute** (`LIC:docs/EXTENSION-POINTS.md`, 36 Actions; `LIC:docs/TODO.md`, Abschnitt 12):

| Action | Argumente | Im Katalog |
|---|---|---|
| `wplc/license_status_changed` | `$license_id, $old_status, $new_status, $action` | ja, `license.license.status_changed`. Deckt jeden Statuswechsel und die Verlängerung ab (`LIC:docs/EXTENSION-POINTS.md:37`) |
| `wplc/license_issued` | `$license_id, $key, $order_id` | **nein**, übergibt den Lizenzschlüssel |
| `wplc/license_issued_followup` | `$license_id, $order_id` | ja, `license.license.issued` |
| `wplc/license_plan_changed` | `$license_id` | ja, `license.license.plan_changed` |

**Was fehlte und eine Aufgabe des Lizenzservers war** (dort WFC-LIC-04; **Stand 23.11.2026:** alle vier Actions, Vertragskonstante, „Since“/„After commit“ und `LicenseReader` sind auf `main` `ec9220d`, dazu `wplc/release_withdrawn`. Die Ereignisse bleiben reserviert, bis eine MINOR-Version dieser Spezifikation sie in den Katalog aufnimmt):

| Fehlende Action | Suite-Ereignis (reserviert) |
|---|---|
| Website aktiviert | `license.activation.created` |
| Website deaktiviert | `license.activation.removed` |
| Site-Limit erreicht | `license.license.site_limit_reached` |
| Release veröffentlicht | `license.release.published` |

Dazu für alle Lizenz-Actions des Katalogs: eine Vertragskonstante (R-3.1), „seit“ je Action (R-3.4), die Zusage „nach dem Commit“ (R-3.9) und eine Lesefassade mit ausschließlich lesenden Methoden (dort WFC-LIC-05). Eine eigene Ereigniskennung oder einen Umschlag braucht der Lizenzserver nach dieser Spezifikation nicht; beides setzt der Consumer (R-6.1). Siehe dazu den Widerspruch W-4 in [`SPEC-open-questions.md`](SPEC-open-questions.md).

**Erlaubte Nutzdaten eines Lizenzereignisses:**

| Feld | Inhalt |
|---|---|
| `subject` | die Referenz der Lizenz. Heute gibt es nur die Datensatznummer `license_id` (`id_kind` = `record`); D2 erlaubt sie, weil Sender und Empfänger im selben WordPress laufen. Eine eigene öffentliche Lizenzreferenz gibt es nicht; `key_prefix`, das das Kundenkonto als „reference“ zeigt (`LIC:src/Account/CustomerPortal.php:616`), ist ein Teil des Schlüssels und wird nicht verwendet (F-02) |
| `data.product` | Produktschlüssel, zum Beispiel `pass-captain-pro` |
| `data.plan` | Tarifschlüssel `single`, `business` oder `agency`. Im Lizenzserver ist `plan_key` freier Text; die drei Werte sind Konvention (`LIC:docs/CATALOG-CSV.md:23`). Ein anderer Wert wird weitergereicht, wenn er dem Muster eines Schlüssels entspricht, sonst entfällt das Feld |
| `data.status`, `data.previous_status`, `data.lifecycle_action` | Zustand und Anlass |
| `actor_user_id` | Benutzer-ID des Kunden aus `licenses.customer_id`; bei 0 nicht gesetzt. Keine Action übergibt einen Benutzer |

**Lesen ohne Lesefassade (geprüft 16.11.2026):** Die öffentlichen Methoden `PlanChange::get_license()` und `Notifications::get_license()` liefern die ganze Zeile mit Schlüssel-Hash, `key_prefix`, `license_uuid` (aus ihr wird der Schlüssel abgeleitet) und Zahlungsreferenzen. Bis zur Lesefassade (WFC-LIC-05) liest der Adapter nur die Spalten `product_key`, `plan_key`, `status` und `customer_id`. **Stand 23.11.2026:** Die Lesefunktion `WPLC\Events\LicenseReader` ist auf `main` `ec9220d`; ab `WPLC_CONTRACT_VERSION` 1.0.0 liest der Adapter nur über sie, der Rückfall über `PlanChange::get_license()` gilt nur noch für ältere Stände.

**Nie:** der Lizenzschlüssel oder ein Teil davon, `license_uuid`, Zahlungsdaten, E-Mail-Adresse, Name, Adresse oder Kennung einer aktivierten Website, Signaturschlüssel.

## 12. Datenschutz

> **Vorbehalt.** Dieser Abschnitt ist eine technische Einordnung und keine Rechtsberatung. Er steht unter dem Vorbehalt der Rechtsprüfung durch den Inhaber (D8; Entscheidung A6). Bis dahin ist keine Aussage dieses Abschnitts eine Zusage an Betreiber oder Kunden.

- R-12.1 Die Benutzer-ID ist ein personenbezogenes Datum. Zusammen mit `subject` zeigt ein gespeichertes Ereignis, dass eine Person etwas getan hat (Einlass, Einlösung, Lizenzstatus).
- R-12.2 **Zweck.** Der Consumer speichert Ereignisse nur, um die vom Betreiber eingerichteten Workflows auszuführen, Fehler zu wiederholen und Läufe nachvollziehbar zu machen. Eine Auswertung über Personen hinweg ist kein Zweck.
- R-12.3 **Sparsamkeit.** Es gilt Abschnitt 5. Der Speicher des Consumers enthält keine E-Mail-Adressen, Namen oder Codes, auch nicht in Protokollen und Fehlermeldungen.
- R-12.4 **Aufbewahrung.** Der Consumer löscht gespeicherte Ereignisse nach einer festen Frist. Vorschlag: erledigte Ereignisse nach 30 Tagen, `dead_letter` und `paused` nach 90 Tagen; `actor_user_id` und `data` werden schon geleert, sobald kein Lauf mehr darauf wartet. Die Fristen sind offen (F-04) und gehören in die Rechtsprüfung. **Entschieden am 23.11.2026 (F-04, E-S7):** 30 und 90 Tage sind die Vorgaben; der Betreiber kann jede Frist zwischen 1 und 180 Tagen einstellen, darüber nur mit Workflow Captain Pro (Filter `workflow_captain_retention_max_days`). Welche Frist rechtlich nötig ist, bleibt Sache der Rechtsprüfung. Laufprotokolle haben eigene Fristen (`konzept.md`).
- R-12.5 **Auskunft und Löschung.** Der Consumer MUSS sich bei WordPress als Exporteur und als Löscher persönlicher Daten anmelden (`wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers`) und dabei alle gespeicherten Ereignisse, Markierungen und Notizen mit der Benutzer-ID der Person liefern beziehungsweise entfernen oder anonymisieren. Er MUSS außerdem auf das Löschen eines Benutzerkontos (`deleted_user`) und auf `affiliate.affiliate.erased` reagieren.
- R-12.6 **Mandantentrennung.** Daten liegen je Website (`site.blog_id`). Ein Ereignis verlässt seine Website nur in einem netzwerkweiten Ablauf nach Abschnitt 17, mit den dort freigegebenen Feldern.
- R-12.7 **Shop des Herstellers.** Lizenzereignisse betreffen Kunden des Herstellers. Verantwortlich ist dort der Hersteller selbst; die Datenschutzhinweise des Shops müssen die Verarbeitung nennen.
- R-12.8 Der Betreiber einer Website ist für den Inhalt seiner Workflows verantwortlich, insbesondere für zusätzliche Nachrichten (R-9.5).

## 13. Versionen und Verträglichkeit

Drei Versionen sind voneinander unabhängig:

| Version | Wo | Wer erhöht |
|---|---|---|
| Spezifikation | Kopf dieses Dokuments; `spec_version` im Umschlag; `-v1` im Namen des Schemas | dieses Repository |
| Vertrag eines Providers | `<PREFIX>_CONTRACT_VERSION` | der Provider |
| Plugin-Versionen von Provider, Consumer und Add-on | jeweilige Hauptdatei | das jeweilige Repository |

- R-13.1 Die Spezifikation folgt SemVer. MAJOR: ein Pflichtfeld des Umschlags fällt weg oder ändert Typ oder Bedeutung, ein Ereignis des Katalogs wird umbenannt oder entfernt, eine Garantie aus Abschnitt 7 wird schwächer, ein Verbot aus 8.2 fällt. MINOR: neue Ereignistypen, neue optionale Felder, neue erlaubte Felder in `data`, neue Befehle, ein neuer Provider. PATCH: Klarstellungen und Berichtigungen von Fundstellen.
- R-13.2 `1.0.0-draft` ist nicht stabil. Bis `1.0.0` darf sich alles ändern.
- R-13.3 Der Consumer MUSS unbekannte Felder in `data` hinnehmen und unbekannte Ereignistypen speichern, ohne einen Workflow zu starten.
- R-13.4 Ein Adapter nennt je Provider die niedrigste Vertragsversion, die er braucht, und die MAJOR-Version, die er kennt. Ist der Vertrag des Providers älter oder hat er eine höhere MAJOR-Version, meldet sich der Adapter nicht an und zeigt den Grund. In `captain-pro` geschieht das über `Feature::requires()` mit dem Eintrag `constants` (`CP:packages/addon-kernel/src/CoreCapabilities.php:35-36`, `:92`).
- R-13.5 Ändert ein Provider eine Action des Katalogs additiv (neues Argument am Ende, neues Feld im Array), bleibt der Katalog gültig. Der Adapter gibt neue Werte erst weiter, wenn eine MINOR-Version dieser Spezifikation sie erlaubt (R-5.2).
- R-13.6 Mindestversionen für Version 1: Voucher Captain 1.2.0 mit Vertrag 1.1.0; Pass Captain 1.5.0 mit Vertrag 1.1.0; Affiliate Captain 0.15.0 mit Vertrag 1.4.0 (erscheint ohne Workflow-Provider, D13); Form Captain mit Vertrag 1.0.0 (seit 1.0.0-draft.7, 4.6.1); Review Captain mit Vertrag 1.0.0 oder, solange `main` keine Konstante hat, Plugin 0.1.0-dev mit `Events::SCHEMA` = `review_captain.review.v1` (seit 1.0.0-draft.8, 4.6.3). Für die vier Gutschein-Befehle zusätzlich die Vertragsversion von Voucher Captain, die die Einstiegspunkte aus 8.3 bringt.
- R-13.7 Der Consumer führt selbst eine Vertragskonstante für die Schnittstelle, an der sich Adapter anmelden. Ihr Name ist offen (F-03).
- R-13.8 Ein Dokument eines anderen Repositorys, das Ereignisse dieses Katalogs beschreibt, verweist auf diese Spezifikation und legt keine eigenen Namen, Umschläge oder Garantien für die Suite fest.

## 14. Nachweise je Rolle (Konformität)

Jede Zeile ist durch einen automatischen Test zu belegen. „Erfüllt“ heißt: Der Test existiert, läuft in der CI und ist grün.

### 14.1 Ein Kern (Provider)

| Nr. | Nachweis |
|---|---|
| P-1 | `<PREFIX>_CONTRACT_VERSION` ist definiert und folgt SemVer (R-3.1) |
| P-2 | Die „Seit“-Tabelle enthält jede Action des Katalogs und keine, die es nicht gibt (R-3.4, R-3.5) |
| P-3 | Der Wächtertest „kein Lizenzcode im Kern“ läuft in der CI (R-3.11) |
| P-4 | Jede Action des Katalogs feuert mit den dokumentierten Argumenten in der dokumentierten Reihenfolge |
| P-5 | Jede als „nach Commit“ dokumentierte Action feuert nicht, wenn die Transaktion zurückgerollt wird |
| P-6 | Wirft ein Callback eine Ausnahme, bleibt die Buchung des Kerns bestehen (Zustand nach dem Fehler geprüft) |
| P-7 | Der Kern verhält sich ohne Callback genauso wie mit; er fragt nie ab, ob ein Abnehmer oder ein Add-on vorhanden ist |
| P-8 | nur Voucher Captain: Jeder der vier Einstiegspunkte aus 8.3 lehnt ab bei Überschreiten seiner Grenzen und bei fehlendem `idempotency_key`; ein zweiter Aufruf mit demselben Schlüssel bewirkt nichts; zwei gleichzeitige Aufrufe überschreiten keine Grenze; jeder Aufruf steht im Protokoll; keiner löst eine Erstattung aus; `voucher.issue` lehnt einen eigenen Code ab |

### 14.2 Ein Adapter

| Nr. | Nachweis |
|---|---|
| A-1 | Er liegt in einem Add-on; im Kern gibt es keine Zeile von ihm. Ausnahme: die Adapter für WordPress und WooCommerce (4.7) liegen im Kern und brauchen keine Lizenz |
| A-2 | Ohne Workflow Captain Pro oder ohne die Pro-Lizenz des Produkts ist kein Callback an einer Action des Providers registriert (R-10.2) |
| A-3 | Für jede Action des Katalogs: Aus Argumenten mit Code, Token, E-Mail-Adresse, Name und Freitext entsteht ein Ereignis, das keinen dieser Werte enthält, auch nicht als Teil einer anderen Zeichenkette (Test mit Markerwerten) |
| A-4 | Actions außerhalb des Katalogs und die ausdrücklich ausgeschlossenen Actions werden nicht angehängt |
| A-5 | Eine Ausnahme im Adapter oder im Consumer erreicht den Provider nicht (R-7.6) |
| A-6 | Jedes erzeugte Ereignis besteht die Prüfung gegen das Schema |
| A-7 | Zweimal dieselbe Action ergibt denselben Idempotenzschlüssel; zwei echte Ereignisse ergeben verschiedene (R-7.9) |
| A-8 | Bei älterem Vertrag oder höherer MAJOR-Version des Providers meldet er sich nicht an (R-13.4) |
| A-9 | Er bietet keinen Befehl aus 8.2 an; die vier Gutschein-Befehle rufen nur die Einstiegspunkte aus 8.3 auf, nie über einen Code, nie mit Erstattung |
| A-10 | Ein schreibender Befehl wird nicht ausgeführt, wenn `authorized_by_user_id` die Berechtigung nicht mehr hat (R-8.8) |
| A-11 | Endet eine der beiden Lizenzen, werden die Callbacks entfernt; im Kern ändert sich nichts (R-10.5) |

### 14.3 Der Consumer

| Nr. | Nachweis |
|---|---|
| C-1 | Der Wächtertest „kein Lizenzcode im Kern“ läuft in der CI; der Consumer kennt keinen Produktschlüssel und kein Recht |
| C-2 | Jeder gespeicherte Umschlag besteht die Prüfung gegen das Schema; ein ungültiger wird verworfen und ohne Werte protokolliert (R-5.5) |
| C-3 | `id` ist eine UUID Version 4; `occurred_at` ist UTC |
| C-4 | Ein zweites Ereignis mit demselben Idempotenzschlüssel wird nicht gespeichert und startet nichts (R-7.10) |
| C-5 | Nach einem vorübergehenden Fehler folgen die Versuche dem Plan aus 7.4; nach dem achten liegt das Ereignis in `dead_letter` und ist im Admin sichtbar |
| C-6 | Ein abgebrochener Worker hinterlässt kein Ereignis dauerhaft in `processing` |
| C-7 | Ein Ereignis mit unbelegtem „nach Commit“ wird vor dem Start gegengelesen; bei Widerspruch `discarded` (R-7.4) |
| C-8 | Ein Workflow startet nicht durch ein Ereignis, das er selbst ausgelöst hat; ab Tiefe 4 `loop_blocked` (R-7.13, R-7.14) |
| C-9 | Ein Ereignis, das ein Befehl im selben Prozess oder an einem von ihm erzeugten Objekt später auslöst, trägt `causation_id`, `caused_by_workflow_id` und die richtige Tiefe (R-7.12) |
| C-10 | Eine Wiederholung führt keinen Befehl doppelt aus (R-8.4) |
| C-11 | Er ruft nie `wp_set_current_user()` für einen Lauf auf (R-8.5) |
| C-12 | Er verändert und unterdrückt keine Mail eines Kerns (R-9.2) |
| C-13 | Export und Löschung persönlicher Daten liefern beziehungsweise entfernen alle Einträge einer Benutzer-ID (R-12.5) |
| C-14 | Die Aufbewahrungsfrist löscht fristgerecht und lässt wartende Läufe unberührt (R-12.4) |
| C-15 | Meldet sich ein Adapter ab, erhalten seine offenen Ereignisse `paused`; nichts wird gelöscht und nichts nachgeholt (R-10.6 bis R-10.8) |
| C-16 | In Multisite sieht eine Website keine Ereignisse einer anderen (R-12.6) |

## 15. Alles, was als „zu prüfen“ markiert ist

| Nr. | Was | Wo zu prüfen |
|---|---|---|
| Z-01 | „Seit“ je `wpcap_*`-Action des Katalogs (12 Actions) | Pass Captain: Git-Verlauf, `CHANGELOG.md`; danach in `docs/HOOKS.md` nachtragen — **Geklärt am 16.11.2026 aus Git-Verlauf und Tags: Werte in 4.2; Eintrag in `docs/HOOKS.md` bleibt Aufgabe von Pass Captain (PC-1)** |
| Z-02 | „Nach Commit“ für `wpcap_refund_rejected`, `wpcap_ticket_revoked`, `wpcap_occurrence_rescheduled`, die Admin-Absage eines Termins (`wpcap_occurrence_cancelled`), den Ablauf einer Reservierung (`wpcap_order_cancelled`) und `wp_pass_captain_invoice_created_v1` | Pass Captain: `src/Refunds.php:160`, `src/Orders.php:702`, `:1805`, `src/Admin/TicketsPage.php:56`, `src/Admin/EventsPage.php:212-245`, `src/Api/Invoices.php:107` — **Geklärt am 16.11.2026: alle nach COMMIT oder im Autocommit; zwei Admin-Wege ohne Prüfung des UPDATE (4.2, 7.2)** |
| Z-03 | Ob `$reason` bei `wpcap_order_cancelled`, `wpcap_order_refunded` und `wpcap_ticket_revoked` Freitext sein kann | Pass Captain: Aufrufer der drei Actions — **Geklärt am 16.11.2026: Freitext bei `wpcap_order_cancelled` und `wpcap_ticket_revoked` möglich; Abbildung auf `reason_class` (4.2)** |
| Z-04 | Öffentliche Kennungen für Erstattung, Termin, Event und Beleg in Pass Captain; Format der `public_id` | Pass Captain: Tabellen und Klassen des Kerns — **Geklärt am 16.11.2026: nur Bestellung und Ticket haben `public_id` (32 Hex); sonst `record` (4.2)** |
| Z-05 | „Nach Commit“ für `vcap_vouchers_issued`, `vcap_voucher_issued_standalone`, `vcap_voucher_delivered`, `vcap_voucher_delivery_given_up`, `vcap_voucher_bounced`, `vcap_voucher_reissued` (ohne Vermerk in der Hook-Tabelle) | Voucher Captain: `src/Issuer.php`, `src/Delivery.php`, `src/Reissue.php` — **Geklärt am 16.11.2026: keine der sechs Actions ist bis COMMIT gepuffert, keine läuft in einer eigenen Transaktion; sie feuern nach Schreibvorgängen im Autocommit (Tabelle 4.3). Gegenlesen nach R-7.4 bleibt** |
| Z-06 | Was `vcap_ticket_checked_in` meldet | Voucher Captain: `src/Tickets.php` — **Geklärt am 16.11.2026: siehe Absatz unter der Tabelle 4.3** |
| Z-07 | Genaue Lesefunktionen für Voucher Captain, Pass Captain und Affiliate Captain (für Gegenlesen und Empfängerauflösung); `wpac_workflow_read()` entfällt (D13) | `VC:API.md`, `PC:docs/EXTENSIONS.md` — **Für Voucher und Pass geklärt am 16.11.2026: Tabelle in 8.1; für Affiliate geklärt am selben Tag (8.1)** |
| Z-08 | „Seit“ (Vertragsversion) der Affiliate-Actions ohne Angabe in 4.4 (13 Actions) | Affiliate Captain: Git-Verlauf; danach als Tabelle in `docs/CONTRACT.md` — **Geklärt am 16.11.2026 aus Tag-Ständen und Git-Verlauf: Werte in 4.4; die Tabelle in `docs/CONTRACT.md` bleibt Aufgabe von Affiliate Captain (AC-1)** |
| Z-09 | Ob Affiliate Captain einen Test hat, der die dokumentierten Actions gegen den Code prüft | Affiliate Captain: `tests/` — **Geklärt am 16.11.2026: Es gibt keinen Test, der die Tabelle in `docs/CONTRACT.md` mit dem Code vergleicht; für `wpac_commission_rejected`, `wpac_commission_pending` und `wpac_code_request_approved` gibt es gar keinen Test (AC-2)** |
| Z-10 | „Seit“ und „nach Commit“ für die drei Lizenz-Actions des Katalogs | Lizenzserver: `src/License/LicenseLifecycle.php`, `LicenseIssuer.php`, `PlanChange.php` — **Geklärt am 16.11.2026: Werte in 4.5; alle nach dem Commit oder im Autocommit, ohne Zusage** |
| Z-11 | Ob der Code von Form Captain die sechs dokumentierten Actions auslöst | Form Captain: `src/` — **Geklärt am 16.11.2026: alle acht Actions werden ausgelöst (4.6), Ausnahme Savepoint-Modus** |
| Z-12 | Ob der Code von Review Captain die Actions für Anfragen auslöst, die sein Workflow-Vertrag nennt | Review Captain: `src/`, `docs/hooks.md` — **Geklärt am 16.11.2026: Die Actions für Anfragen löst der Code nicht aus (4.6)** |
| Z-13 | Der Stand von `captain-pro` wurde im Arbeitsverzeichnis gelesen, das uncommittete Änderungen enthielt; in Affiliate Captain war während des Lesens zeitweise eine Datei uncommittet | **Für `captain-pro` geklärt am 16.11.2026:** Die Fundstellen `CoreRequirement.php:60-64`, `Feature.php:41-52`, `CoreCapabilities.php:35-36` und `:92` stimmen im committeten Stand `07b5365` und auf `main` (`2f23da7`); der Kernel kennt weiterhin keine Prüfung der Lizenz eines anderen Add-ons (F-06 bleibt offen). Für Affiliate Captain geklärt am 16.11.2026: Die Fundstellen stimmen an `6600392` und `main`; der D13-Wächter steht in `AC:tests/run.php:365-397`. |
| Z-15 | Für Sperren, Reaktivieren und Einlösen im Voucher-Kern: Berechtigungsprüfung, Idempotenz, Wirkung von `$refund`/`$after_refund`; ob der Branch von Pull Request 16 dafür eigene Dienste enthält | Voucher Captain: `src/Issuer.php:2012-2110`, `src/API/V1/RedemptionService.php`, Branch `hvollo-refactors` — **Geklärt am 16.11.2026: siehe 8.3.3, 8.3.4 und R-8.6** |
| Z-16 | Ob der vorläufige Workflow-Provider aus Pull Request 16 im Voucher-Kern schon entfernt ist (D13) | Voucher Captain: Branches `hvollo-refactors` und `main` — **Geklärt am 16.11.2026: auf `main` nie vorhanden; auf `hvollo-refactors` (`82145ed`) noch vorhanden (`src/Workflow/Provider.php`, `Services.php`, `Handover.php`, Hook `vcap_workflow_event`); Entfernen bleibt Aufgabe des Voucher-Kerns (dort VOUCH-WF-10)** |
| Z-14 | Die Aufgabenstellung nennt 129 Hooks im Lizenzserver; gezählt wurden 36 Actions und 94 Filter, zusammen 130 | Lizenzserver: `docs/EXTENSION-POINTS.md` — **Geklärt am 16.11.2026: 38 Actions und 92 Filter an `241ae7a` (130); `main` hat 129, weil `wplc_offer_planned_entitlements` fehlt (3.2)** |

## 16. Änderungsverlauf

| Version | Datum | Änderung |
|---|---|---|
| 1.0.0-draft.8 (Nachtrag) | 23.11.2026 | Ohne Versionswechsel, keine Regel geändert: Stände aller Suite-Repositories nachgetragen (0, 3.1, 4.4, 4.6.1, 4.6.3, R-4.27, 4.6.4, 11). Lizenzserver mit Vertragskonstante 1.0.0 auf `main` (Katalog `min_contract` `1.0.0`), Form Captain 1.1.0 auf `main`, Voucher und Pass Captain auf `main` ohne Konstante, Review Captain 1.1.0 mit Sternen nur auf Branch (Katalog `rating_fields_since`), Course Captain mit Code auf Branch; neue Frage F-24. |
| 1.0.0-draft.8 | 16.11.2026 | MINOR nach R-13.1 (neuer Provider), nach F-12 (wie D13): Katalog `review.*` mit 3 Ereignissen aus den öffentlichen Hooks der Stufe V von Review Captain (`main` `863b92b`, 4.6.3, R-4.27 bis R-4.31): Positivliste ohne Namen, E-Mail-Adressen, Texte und IP-Adressen; Sterne über eine Lesefunktion ohne Vertrag (R-4.27); Grabstein und endgültiges Löschen unterschieden (R-4.28); `experience_id` nur als Quelle (R-4.29). Keine Befehle für Review Captain in Version 1 (R-4.30, neue Frage F-23). Vertragskonstante `REVIEW_CAPTAIN_CONTRACT_VERSION` = `1.0.0` auf dem Branch von Review Captain (3.1, RC-2); ohne sie Rückfall nach R-3.3. Angepasst: 0 (Belege), 3.1, 6, 7.2, 7.3, 8.1, R-13.6. Katalog: 3 Ereignisse und Beispiele. |
| 1.0.0-draft.7 (Nachtrag) | 16.11.2026 | Abschnitt 17: R-17.10 bis R-17.15 (Routing-Vertrag mit netzwerkfähigen Ereignissen und Datenschutzklassen, Netzwerkvertrag einer Aktion, Benutzer, Grenzen des Ziels, Betrieb je Website, Plätze je Unterwebsite). Keine Änderung am Umschlag, am Schema oder am Katalog. |
| 1.0.0-draft.7 | 16.11.2026 | MINOR nach R-13.1 (neuer Provider), nach F-12 (wie D13): Katalog `form.*` mit 8 Ereignissen aus den öffentlichen Actions von Form Captain (4.6.1), Feldklassen, Einwilligung und freigegebene Antworten `answer_<schlüssel>` (4.6.2, R-4.19 bis R-4.26), Savepoint-Modus von `form_captain_entry_created_v1` geklärt (R-4.21). `review.*` vorgesehen, aber leer, bis RC-2 und F-08 (4.6.3; `main` von Review Captain hat keinen Code). Angepasst: 6 (`source.product`, `actor_user_id`), 7.2, 7.3, R-13.6. Katalog: 8 Ereignisse und Beispiele, neue Angaben `data_field_prefix` und `data_field_prefix_types`; der Validator und das Prüfkit für Adapter kennen sie. |
| 1.0.0-draft.6 | 16.11.2026 | Entscheidungen F-07, F-20 und F-22 eingearbeitet: neue Abschnitte 4.7 (WordPress und WooCommerce, Adapter im freien Kern), 4.8 (eigene Connectoren mit Präfix `x<schlüssel>`, R-4.10 bis R-4.13) und 17 (netzwerkweite Abläufe). R-4.1, R-12.6 und die Nichtziele angepasst. Abschnitt 4.9 (Zeitpläne des Kerns, `workflow.schedule.due`), Abschnitt 4.10 (eingehende Aufrufe, `workflow_captain_event_processed_v1`). Schema: `type` und `source.product` erweitert. Katalog um sieben Ereignisse und Beispiele ergänzt. |
| 1.0.0-draft.5 | 16.11.2026 | Alle übrigen Stellen „zu prüfen“ geklärt: Affiliate Captain (Z-07, Z-08, Z-09, Z-13), Lizenzserver (Z-10, Z-14), Form Captain (Z-11), Review Captain (Z-12). Erlaubte Felder für die 21 Ereignistypen von Affiliate Captain (4.4) und Beispiele; neue Frage F-21 (beantragter Partnercode). Lesefunktionen von Affiliate Captain in 8.1; Lesen von Lizenzen ohne Fassade in 11. Berichtigt: Hook-Zahlen des Lizenzservers (3.2), acht statt sechs Actions in Form Captain (4.6), Course Captain im Katalog auf `a113568`. Erster Code des Consumers (Plugin 0.1.0) setzt diese Fassung um. |
| 1.0.0-draft.4 | 16.11.2026 | Stellen „zu prüfen“ für Pass Captain (Z-01 bis Z-04), Voucher Captain (Z-05, Z-06, Z-15, Z-16), Lesefunktionen (Z-07 für beide) und `captain-pro` (Z-13) am Code geklärt und Tabellen 3.2, 4.2, 4.3, 7.2, 8.3.3, 8.3.4 und R-8.6 berichtigt. Erlaubte Felder und `actor_user_id` für alle 29 Ereignistypen von Pass und Voucher Captain (4.2, 4.3), je ein Beispiel, maschinenlesbarer Katalog `catalog/suite-events-v1.json`. Neue Regel R-4.7 (`reference`, `redemption_key` nicht weiterreichen); Beispiele `voucher.voucher.issued_standalone` und `voucher.voucher.redeemed` entsprechend ohne diese Felder; `public_id` im Beispiel im Format des Kerns. Tabelle der Lesefunktionen in 8.1 (WF-SPEC-15). Neuer Abschnitt 8.5 mit den Eigenschaften je Befehl und R-8.11 bis R-8.13. Offen bleiben die Stellen für Affiliate, Lizenzserver, Form und Review. Nachträge am selben Tag: Beispiele `license.license.issued` und `license.license.plan_changed`; Hinweis auf `Reconciliation::orders()` (Pass Captain, nicht gemergt) in 8.1. |
| 1.0.0-draft.3 | 16.11.2026 | F-05 entschieden: feste Grenzen der vier Gutschein-Befehle als Tabelle in 8.3.1 (100 EUR und 50 je Tag; Einlösen jedes Gutscheins); neue Teilfrage F-05a für Shops ohne Euro. D13 im Affiliate-Kern umgesetzt: 4.4.1, R-4.4 bis R-4.6, 7.2 und R-13.6 nennen die Entfernung als Stand; neue Stelle Z-16 für den Voucher-Kern. |
| 1.0.0-draft.2 | 16.11.2026 | Folgeentscheidungen eingearbeitet. D13: Abschnitt 4.4.1 neu, die vorläufigen Workflow-Provider werden vor dem Release aus den Kernen entfernt, `wpac_workflow_read()` ist keine anerkannte Lesefunktion. D8b: öffentlicher Partnercode und Geldbeträge erlaubt (R-5.1a, Katalog 4.4, Schema, zwei neue Beispiele). D11b: vier Gutschein-Befehle statt einem (Abschnitt 8 neu, Verbotsliste, Lizenzmatrix, R-13.6, P-8, A-9, neue Stelle Z-15). **Berichtigung:** Abschnitt 7.2 nannte für Voucher Captain „10 der 15“ Actions nach Commit und „fünf“ ohne Vermerk; richtig sind 9 und sechs. |
| 1.0.0-draft | 16.11.2026 | Erste Fassung nach den Entscheidungen D1, D2 und D4 bis D12 vom 16.11.2026. Katalog: Pass Captain 13, Voucher Captain 16, Affiliate Captain 21, Lizenzserver 3 Ereignisse. Schema und vier Beispiele. |

## 17. Netzwerkweite Abläufe in Multisite (F-07)

Entschieden am 16.11.2026 (F-07): Netzwerkweite Abläufe werden gebaut. Sie sind eine Funktion von Workflow Captain Pro (Recht `workflow.multisite_network`, F-01) und laufen nur, wenn Kern und Pro netzwerkweit aktiviert sind. Ohne sie gilt alles je Website.

- R-17.1 **Umfang.** Ein Netzwerk-Workflow hört auf ein Ereignis einer ausdrücklich gewählten Quell-Website und führt Schritte auf einer oder mehreren vorab festgelegten Ziel-Websites aus. Ziele sind feste Blog-IDs; „alle Websites“ gibt es nicht.
- R-17.2 **Freigabe.** Jede beteiligte Website gibt ihre Teilnahme selbst frei: die Quelle je Ereignisfamilie und Feld, das Ziel je Befehl. Ein Widerruf wirkt sofort; laufende Schritte dieser Website pausieren (`paused_site_consent`).
- R-17.3 **Daten.** Über die Website-Grenze gehen nur die freigegebenen Felder aus `data` sowie `type`, `subject`, `occurred_at` und die IDs aus 17.5. `actor_user_id` geht nie über die Grenze.
- R-17.4 **Ausführung.** Auf der Ziel-Website läuft ein Schritt in deren eigener Warteschlange und mit deren Berechtigungen. Der veröffentlichende Benutzer ist Super-Admin; vor jedem Schritt prüft die Engine zusätzlich, dass die Aktion auf der Ziel-Website erlaubt ist (R-8.8 sinngemäß). `switch_to_blog()` wird nur zum Einplanen genutzt und immer in `try`/`finally` zurückgesetzt.
- R-17.5 **Kennungen.** Der Umschlag eines Netzwerkschritts trägt `network.network_id`, `network.source_blog_id`, `network.workflow_id` und `network.run_id` in einer eigenen Hülle neben dem Umschlag, nicht im Umschlag (der Umschlag bleibt unverändert, R-13.1).
- R-17.6 **Saga statt Transaktion.** Ein Netzwerklauf hat einen Elternlauf und je Ziel-Website einen Kindschritt. Zustände: `accepted`, `waiting_site_worker`, `running`, `succeeded`, `partial_failure`, `paused_site_consent`, `blocked_provider`, `cancelled`. Ein Fehlschlag auf einer Website hält die anderen nicht an.
- R-17.7 **Schleifen.** Die Kausalkette zählt über Websites hinweg; das Limit aus R-7.14 gilt netzwerkweit.
- R-17.8 **Lebenszyklus.** Wird eine Ziel-Website archiviert, gelöscht oder ihre Blog-ID wiederverwendet, pausiert der Netzwerk-Workflow, bis ein Super-Admin das Ziel neu bestätigt.
- R-17.9 **Lizenz.** Jede beteiligte Website belegt einen Platz der Lizenz (B2). Läuft die Lizenz ab, starten keine neuen Netzwerkläufe; offene pausieren (10.3).

Ergänzt am 16.11.2026 (TODO WF-MS-02, -05, -07, -12, -14, -17, -20, -25; gebaut in Workflow Captain Pro, captain-pro Branch `wt/ms`):

- R-17.10 **Routing-Vertrag.** Ein Netzwerk-Workflow hört nur auf Ereignistypen, die ihr Provider (oder dessen Adapter) ausdrücklich als netzwerkfähig erklärt, mit einer festen Feldliste und je Feld einer Datenschutzklasse: `operational` (ohne Personenbezug), `reference` (ID eines Objekts der Quelle) oder `personal` (bezieht sich auf eine Person, z. B. `…user_id`). Über die Grenze gehen nur Felder, die der Provider erklärt, der Workflow nennt und die Quelle freigibt (R-17.3). Der Elternlauf hält den Routing-Vertrag Version `1.0`: `network_id`, `source_blog_id`, `event_id`, `event_type`, `spec_version`, `source.product`, `source.core_version`, `source.contract_version`, `correlation_id`, `causation_id`, `chain_depth` und die Klasse jedes übertragenen Felds. Die Hülle nach R-17.5 bleibt unverändert. Zieht der Provider die Erklärung zurück, starten keine Läufe mehr, und eingeplante Schritte pausieren (`event_not_publishable`).
- R-17.11 **Netzwerkvertrag einer Aktion.** Eine Aktion läuft in einem Netzwerk-Workflow nur mit einem Netzwerkvertrag: `network_safe`, Vertragskonstante mit Mindestversion und MAJOR, nötige Fachplugin-Version, erlaubte Sitekontexte (Haupt- oder Unterwebsite), Nebenwirkungen, erlaubte Datenklassen, Idempotenz, Wiederholungs- und Wiederzustellungsverhalten, Benutzerbezug, nötige Zugangsdaten und eine Service-Klasse des Providers als Handler. Kein Vertrag nennt eine Tabelle eines anderen Plugins oder einen freien Hook. Geprüft wird im Worker der Ziel-Website, mit deren Plugins; im Kern bleibt `Engine\NetworkSafe` Voraussetzung (R-17.4). Die fünf netzwerkfähigen Aktionen des Kerns haben feste Verträge; `notify.admin` und Markierungen bekommen keine `personal`-Felder.
- R-17.12 **Benutzer.** Es wird kein Benutzerkontext erfunden, und kein Schritt läuft als Super-Admin eines Requests. Der veröffentlichende Super-Admin wird beim Einplanen und vor jedem Schritt erneut geprüft; fehlt das Recht, blockiert der Schritt (`publisher_not_authorized`), bis ein Super-Admin fortsetzt. Empfänger kommen nur aus freigegebenen `…user_id`-Feldern und müssen Mitglied der Ziel-Website sein.
- R-17.13 **Grenzen des Ziels.** Eine Ziel-Website kann eine erlaubte Aktion auf Datenfelder, Rollen der Empfänger und Objektarten (`subject.type`) begrenzen. Die Kopie für das Ziel enthält nur die angenommenen Felder; eine Verletzung pausiert den Schritt (`paused_site_consent`).
- R-17.14 **Betrieb je Website.** Jede Website meldet aus ihrem eigenen Request ihren Worker (Backend, nächster Lauf, WP-Cron aus) und ihren Lizenzplatz; `switch_to_blog()` zeigt die Plugins einer Website nicht und wird dafür nicht benutzt. Ein Schritt, der länger als 15 Minuten auf den Worker seiner Website wartet, gilt als verspätet und wird angezeigt; er bleibt in der Warteschlange dieser Website. Widerrufene Zugangsdaten eines Providers blockieren nur die Schritte dieser Website mit dieser Aktion. Kein Schritt wechselt je auf eine andere Website (R-17.8).
- R-17.15 **Plätze je Unterwebsite.** Nach B2 aktiviert jede Unterwebsite selbst und belegt beim Lizenzserver einen Live-Platz; der Lizenzserver zählt Aktivierungen, nicht die Adresse des Netzwerks. Ein Netzwerk-Workflow verlangt für jede beteiligte Blog-ID eine eigene aktive Aktivierung; jede verschiedene Blog-ID zählt einmal, auch mit gemappter Domain. Grenze ist das signierte `site_limit`. Gibt eine Website ihren Platz ab, blockieren ihre offenen Schritte sofort; bekommt sie ihn zurück, läuft nichts ohne Prüfung und Bestätigung eines Super-Admins weiter (R-17.9).
