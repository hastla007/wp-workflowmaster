# Captain Suite: Spezifikation der Ereignisse und Befehle zwischen den Plugins

| | |
|---|---|
| Version | **1.0.0-draft.3** |
| Stand | 16.11.2026 |
| Status | Entwurf zur Abnahme durch den Inhaber. Gebaut ist davon nichts. |
| Ort | `hastla007/wp-workflowmaster`, `docs/SPEC-suite-events.md` (Entscheidung D1) |
| Maschinenlesbar | [`schema/suite-event-v1.schema.json`](schema/suite-event-v1.schema.json), Beispiele unter [`examples/`](examples/) |
| Offene Punkte | [`SPEC-open-questions.md`](SPEC-open-questions.md) |

Dieses Dokument ist die eine gemeinsame Spezifikation dafür, wie die Plugins der Captain Suite zusammenarbeiten. Wo `konzept.md`, `TODO.md` oder ein Dokument eines anderen Repositorys etwas anderes sagt, gilt dieses Dokument.

Grundlage sind die Entscheidungen des Inhabers vom 16.11.2026 (D1, D2, D4 bis D12) und die Folgeentscheidungen vom Abend desselben Tages (D8b, D11b, D13). Jede Regel nennt die Entscheidung, aus der sie folgt. Regeln ohne Entscheidung sind Vorschläge dieser Spezifikation und in [`SPEC-open-questions.md`](SPEC-open-questions.md) aufgeführt.

## 0. Sprache und Begriffe

**Normative Wörter.** MUSS und DARF NICHT bezeichnen eine Pflicht ohne Ausnahme. SOLL bezeichnet eine Regel, von der nur mit einem aufgeschriebenen Grund abgewichen wird. KANN bezeichnet eine erlaubte Möglichkeit.

**„zu prüfen“** markiert eine Aussage über vorhandenen Code, die beim Schreiben dieses Dokuments nicht am Code oder an der Dokumentation des jeweiligen Repositorys belegt wurde. Abschnitt 15 sammelt alle diese Stellen.

**Belege.** Fundstellen stehen als `Repository:Datei:Zeile`. Gelesen wurden diese Stände:

| Kürzel | Repository | Branch | Commit | Plugin-Version |
|---|---|---|---|---|
| VC | `wp-couponmaster` (Voucher Captain) | `claude/sharp-mendel-xiiwf3` | `df3eee2` | 1.2.0 (`voucher-captain.php:6`) |
| PC | `wp-passmaster` (Pass Captain) | `claude/sharp-mendel-xiiwf3` | `bca8d85a` | 1.5.0 (`pass-captain.php:6`) |
| AC | `wpaffiliatemaster` (Affiliate Captain) | `claude/sharp-mendel-xiiwf3` | `23f06be` (Katalog gelesen zwischen `70c23b7` und `dbb319d`; Entfernung des Workflow-Providers geprüft an `23f06be`) | 0.15.0 (`affiliate-captain.php:6`) |
| LIC | `wp-license` (Lizenzserver) | `claude/sharp-mendel-xiiwf3` | `241ae7a` (committeter Stand) | 0.2.0 (`wp-license.php:23`) |
| CP | `captain-pro` (Add-ons, Kernel) | `claude/sharp-mendel-xiiwf3` | `5e333f7` (Arbeitsverzeichnis mit uncommitteten Änderungen); am 16.11.2026 gegengeprüft an `07b5365` und `main` `2f23da7`: alle Fundstellen in `packages/addon-kernel` unverändert | – |
| FC | `wp-formmaster` (Form Captain) | `claude/youthful-babbage-wkrqyv` | `0017025` | 1.0.0-dev |
| RC | `wp-reviewmaster` (Review Captain) | `claude/sleepy-gates-6mm217` | `faa5f89` | 0.1.0-dev |
| CC | `wp-coursemaster` (Course Captain) | `claude/blissful-curie-tuuf7q` | `ae1bce9` | kein Code |

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
- WordPress- und WooCommerce-Ereignisse, eigene Connectoren von Kunden, Webhooks nach außen, n8n und netzwerkweite Abläufe in Multisite sind nicht Teil dieser Fassung. Sie brauchen eigene Abschnitte in einer späteren Minor-Version.
- Der Aufbau des Workflow-Editors, der Tabellen und der Oberfläche steht in `konzept.md`.
- Preise. Die in `konzept.md` genannten Beträge sind nicht bestätigt.

**Rangfolge der Arbeit (D12).** Diese Spezifikation darf jetzt geschrieben und abgenommen werden. Gebaut wird erst nach dem Verkaufsstart der drei vorhandenen Add-ons.

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
| Voucher Captain 1.2.0 | `VCAP_CONTRACT_VERSION` | `1.1.0` | `VC:voucher-captain.php:29` | `VC:CONNECTORS.md:103-106` (innerhalb v1 nur additive Änderungen), `VC:docs/FREE-PREMIUM.md:104` |
| Pass Captain 1.5.0 | `WPCAP_CONTRACT_VERSION` | `1.1.0` | `PC:pass-captain.php:166` | `PC:docs/EXTENSIONS.md:33-41` |
| Affiliate Captain 0.15.0 | `WPAC_CONTRACT_VERSION` | `1.4.0` | `AC:affiliate-captain.php:41` | `AC:docs/CONTRACT.md:5` |
| Form Captain 1.0.0-dev | `FORM_CAPTAIN_CONTRACT_VERSION` | `1.0.0` | `FC:form-captain.php:29` | `FC:docs/RELEASE.md:16` |
| Review Captain 0.1.0-dev | fehlt | – | nur `REVIEW_CAPTAIN_VERSION` (`RC:review-captain.php:20`) | Stabilitätsstufen je Hook in `RC:docs/adr/0007-oeffentliche-hooks-v1.md` |
| Course Captain | fehlt | – | kein Code | – |
| Lizenzserver 0.2.0 | fehlt | – | nur `WPLC_VERSION` (`LIC:wp-license.php:23`) | – |

Die drei vorhandenen Kerne erfüllen R-3.1. Für Review Captain, Course Captain und den Lizenzserver ist die Konstante eine Aufgabe des jeweiligen Repositorys (F-03).

### 3.2 „Seit“-Tabelle

- R-3.4 Ein Provider MUSS eine Tabelle führen, die zu jeder öffentlichen Action die Version nennt, seit der es sie gibt.
- R-3.5 Ein Test des Providers SOLL prüfen, dass die Tabelle jede Action des Codes enthält und keine nennt, die es nicht gibt.

| Provider | Tabelle | Test |
|---|---|---|
| Voucher Captain | `VC:docs/HOOKS.md` (220 Hooks, Spalte „Since“, aus dem Code erzeugt, `VC:docs/HOOKS.md:3-9`) | CI prüft mit `php bin/hooks.php --check` (`VC:docs/HOOKS.md:4`) |
| Pass Captain | `PC:docs/EXTENSIONS.md:52` ff. für die 54 versionierten Punkte (`wp_pass_captain_*_v1`). Für die `wpcap_*`-Actions des Katalogs unten gibt es **keine** Angabe je Hook | `PC:tests/integration/modules/zzzzzzzz_r16_contract.php` (`PC:docs/EXTENSIONS.md:49-50`) |
| Affiliate Captain | keine Tabelle; „seit“ steht im Fließtext und im Änderungsabsatz (`AC:docs/CONTRACT.md:7`) | zu prüfen |
| Lizenzserver | `LIC:docs/EXTENSION-POINTS.md` (36 Actions und 94 Filter, zusammen 130 Zeilen; ohne „seit“) | `tests/Contract/ExtensionPointsDocTest.php` (`LIC:docs/EXTENSION-POINTS.md:3`) |

Lücken gegenüber R-3.4: „seit“ je `wpcap_*`-Action in Pass Captain, eine Tabelle in Affiliate Captain, „seit“ im Lizenzserver.

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

- R-4.1 Ein Suite-Ereignis heißt `<produkt>.<objekt>.<ereignis>` in Kleinbuchstaben mit Unterstrichen. `<produkt>` ist `voucher`, `pass`, `affiliate`, `license`, `form`, `review`, `course` oder `workflow`. `<ereignis>` steht in der Vergangenheitsform.
- R-4.2 Der Name ist vom Namen der PHP-Action unabhängig. Die Zuordnung steht in den Tabellen unten und nur dort.
- R-4.3 Der Katalog enthält nur Actions, die es heute im Code gibt. Eine Action, die hier nicht steht, ist kein Suite-Ereignis; ein Adapter DARF sie nicht weiterreichen.

Lesehilfe für die Tabellen: „Argumente heute“ ist die Signatur der PHP-Action. „Nicht weiterreichen“ nennt, was der Adapter entfernen MUSS. „Nach Commit“ sagt, ob die Action belegt erst nach dem Commit feuert.

### 4.2 Pass Captain (`source.product` = `pass-captain`, 13 Ereignisse)

Alle Actions übergeben interne Datenbank-IDs; öffentliche IDs (`public_id`) lädt der Empfänger über die Klassen des Kerns (`PC:docs/SUITE-EVENTS.md:24-25`). „Seit“ ist für die `wpcap_*`-Actions nirgends je Hook ausgewiesen (zu prüfen); sie gehören zum Vertrag 1.0.0 oder 1.1.0.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `pass.order.paid` | `wpcap_order_paid` | zu prüfen | `int $order_id` | – | ja (`PC:src/Orders.php:1346-1348`; kostenlose Bestellung `:410`, `:456`) |
| `pass.order.cancelled` | `wpcap_order_cancelled` | zu prüfen | `int $order_id, string $reason, string $previous_payment_status`; beim Ablauf einer Reservierung nur `$order_id` | `$reason` (Freitext möglich, zu prüfen) | ja (`PC:src/Orders.php:1440-1445`); Ablauf einer Reservierung `:1805` zu prüfen |
| `pass.order.refunded` | `wpcap_order_refunded` | zu prüfen | `int $order_id, string $reason` | `$reason` | ja (`PC:src/Orders.php:1501-1506`) |
| `pass.order.tickets_issued` | `wpcap_tickets_issued` | zu prüfen | `int $order_id, int $count` | – | ja (`PC:src/Orders.php:2044-2046`) |
| `pass.refund.completed` | `wpcap_refund_completed` | zu prüfen | `int $refund_id, int $order_id` | – | ja (`PC:src/Refunds.php:344-357`, `:671-681`) |
| `pass.refund.rejected` | `wpcap_refund_rejected` | zu prüfen | `int $refund_id, int $order_id` | – | zu prüfen (`PC:src/Refunds.php:160`) |
| `pass.ticket.revoked` | `wpcap_ticket_revoked` | zu prüfen | `int $ticket_id, string $reason` | `$reason`, soweit kein fester Schlüssel | zu prüfen (`PC:src/Orders.php:702`, `PC:src/Admin/TicketsPage.php:56`) |
| `pass.ticket.checked_in` | `wpcap_ticket_checked_in` | zu prüfen | `int $ticket_id, array $context` (`source`, `device_id`, `area_id`, `idempotency_key`) | alle weiteren Schlüssel von `$context` | ja (`PC:src/Checkin.php:191-193`, `:252-259`) |
| `pass.ticket.checked_out` | `wpcap_ticket_checked_out` | zu prüfen | `int $ticket_id, array $context` | wie oben | ja (`PC:src/Checkin.php:308-310`) |
| `pass.occurrence.rescheduled` | `wpcap_occurrence_rescheduled` | zu prüfen | `int $occurrence_id, string $old_starts_at, string $new_starts_at` (UTC) | – | zu prüfen (`PC:src/Admin/EventsPage.php:212`) |
| `pass.occurrence.cancelled` | `wpcap_occurrence_cancelled` | zu prüfen | `int $occurrence_id, int $issued` | – | bei Event-Absage ja (`PC:src/Orders.php:1672-1680`); Admin-Absage eines Termins zu prüfen (`PC:src/Admin/EventsPage.php:218`, `:245`) |
| `pass.event.cancelled` | `wpcap_event_cancelled` | zu prüfen | `int $event_id` | – | ja (`PC:src/Orders.php:1672-1720`) |
| `pass.invoice.created` | `wp_pass_captain_invoice_created_v1` | 1.5.0 (`PC:docs/EXTENSIONS.md:75`) | `int $id, string $type, array $context` | `$context` bis auf IDs und Belegnummer | ja laut `PC:docs/SUITE-EVENTS.md:44`; am Code zu prüfen (`PC:src/Api/Invoices.php:107`) |

**Ausdrücklich kein Suite-Ereignis** (Action enthält einen Code oder personenbezogene Daten, oder feuert innerhalb der Transaktion; `PC:docs/SUITE-EVENTS.md:34-46`):

| PHP-Action | Grund | Regel für den Adapter |
|---|---|---|
| `wpcap_ticket_created` | feuert **innerhalb** der Ausgabe-Transaktion (`PC:src/Orders.php:2013`); `$order` enthält `customer_email` und den Namen des Käufers | DARF NICHT angehängt werden. Ersatz: `pass.order.tickets_issued` |
| `wpcap_ticket_code_rotated` | `$code` ist der Ticketcode im Klartext | DARF NICHT angehängt werden |
| `wpcap_consent_withdrawn` | `$consent` ist die Einwilligungszeile einer Person | DARF NICHT weitergereicht werden |
| `wpcap_mail_before_send` | Empfängeradresse, Mailtext, Anhänge mit Code | DARF NICHT angehängt werden (zugleich R-9.2) |
| `wpcap_order_creating` | feuert innerhalb der Bestell-Transaktion; `$payload` enthält Eingaben des Käufers | DARF NICHT angehängt werden |

Es gibt kein Ereignis „Teilnahme bestätigt“ oder „Termin beendet“ (`PC:docs/SUITE-EVENTS.md:48-49`). Der Nachweis für eine Review-Einladung ist `pass.ticket.checked_in` (D7).

`subject`: Bestellung und Ticket mit `public_id` (`id_kind` = `public`). Für Erstattung, Termin, Event und Beleg ist zu prüfen, ob es eine öffentliche ID gibt; sonst `id_kind` = `record` (F-02).

### 4.3 Voucher Captain (`source.product` = `voucher-captain`, 15 Actions, 16 Ereignisse)

„Seit“ stammt aus `VC:docs/HOOKS.md:13-56`. „Nach Commit: ja“ heißt, die Tabelle dort trägt den Vermerk „after COMMIT, dropped on ROLLBACK“ (Regel in `VC:docs/HOOKS.md:5-6`).

Ein `Voucher`-Objekt enthält immer Code, Token sowie Daten von Käufer und Empfänger (`VC:README.md:109`; Eigenschaften `code`, `token`, `buyer_email`, `recipient_email` in `VC:src/Voucher.php:20-48`). Eine Action, die ein `Voucher`-Objekt übergibt, gilt deshalb als „enthält Code und personenbezogene Daten“.

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `voucher.order.vouchers_issued` | `vcap_vouchers_issued` | 1.0.0 | `Voucher[] $created, WC_Order $order` | **beide Objekte.** Übrig: Bestell-ID, Anzahl; je Gutschein ein eigenes Ereignis ist nicht vorgesehen | zu prüfen (kein Vermerk; `VC:src/Issuer.php:282`, `:640`) |
| `voucher.voucher.issued_standalone` | `vcap_voucher_issued_standalone` | 1.2.0 | `Voucher $voucher, array $args` | **`Voucher`-Objekt; aus `$args` Namen, E-Mail-Adressen, `message`, `code`** | zu prüfen (kein Vermerk; `VC:src/Issuer.php:1076`) |
| `voucher.voucher.delivered` | `vcap_voucher_delivered` | 1.0.0 | `Voucher $voucher, string[] $addresses` | **`Voucher`-Objekt, alle Adressen** | zu prüfen (kein Vermerk) |
| `voucher.voucher.delivery_failed` | `vcap_voucher_delivery_given_up` | 1.2.0 | `Voucher`, Zahl der Fehlversuche (`VC:README.md:119`) | **`Voucher`-Objekt** | zu prüfen |
| `voucher.voucher.bounced` | `vcap_voucher_bounced` | 1.2.0 | `Voucher`, Rolle (`recipient`/`buyer`), Grund | **`Voucher`-Objekt**, Grund | zu prüfen |
| `voucher.voucher.redeemed` | `vcap_voucher_redeemed` | 1.1.0 | Array: `voucher_id`, `code`, `user_id`, `amount`, `currency`, `order_id`, `channel`, `committed_at`, `source`, `redemption_key`, `target`; am Schalter `till`, `receipt`; über die Einlöse-API `state`, `balance_after`, `expires_at`, `created_at`, `meta` (`VC:README.md:121`, `VC:src/VoucherRepository.php:776-795`) | **`code`**, `meta`, `receipt`, `till`, `target` | ja |
| `voucher.redemption.reserved` | `vcap_redemption_reserved` | 1.1.0 | Array wie bei der Einlöse-API, mit `code` und `user_id` (`VC:README.md:122`) | **`code`**, `meta`, `target` | ja |
| `voucher.redemption.released` | `vcap_redemption_released` | 1.1.0 | dasselbe Array, `bool $expired` | **`code`**, `meta`, `target` | ja |
| `voucher.redemption.refunded` | `vcap_redemption_refunded` | 1.1.0 | dasselbe Array, zurückgebuchter Betrag | **`code`**, `meta`, `target` | ja |
| `voucher.redemption.reversed` | `vcap_redemption_reversed` | 1.2.0 | Gutschein-ID, Journal-ID, Betrag, Grund (`VC:README.md:125`) | Grund | ja |
| `voucher.voucher.expired` | `vcap_voucher_expired` | 1.2.0 | `Voucher[]` je Stapel der täglichen Wartung | **alle Objekte.** Der Adapter erzeugt je Gutschein ein Ereignis mit der ID | ja |
| `voucher.voucher.reissued` | `vcap_voucher_reissued` | 1.2.0 | `Voucher`, alter Code (`VC:README.md:128`) | **`Voucher`-Objekt, alter Code** | zu prüfen |
| `voucher.voucher.voided`, `voucher.voucher.reactivated` | `vcap_voucher_event` mit Ereignis `voided` bzw. `reactivated` (`VC:README.md:126`, `:132`) | 1.0.0 | Gutschein-ID, Ereignis, Betrag, Restwert, Notiz als Text, Bestell-ID, Quelle, Benutzer-ID (die letzten beiden nur mit 8 angenommenen Argumenten, `VC:docs/HOOKS.md:50`) | Notiz. Alle anderen Journalereignisse DARF der Adapter nicht weiterreichen | ja |
| `voucher.store_credit.changed` | `vcap_store_credit_changed` | 1.1.0 | Benutzer-ID, Betrag, Saldo, Ereignis, Grund, Bestell-ID | Grund | ja |
| `voucher.gift_card.topped_up` | `vcap_gift_card_topped_up` | 1.2.0 | `Voucher $card, float $amount, WC_Order $order`, Bestellposition | **beide Objekte**, Bestellposition | ja |

Nicht im Katalog: `vcap_voucher_created`, `vcap_voucher_updated`, `vcap_voucher_deleted` (Zwischenzustände der Ausstellung; ein angelegter Gutschein kann wieder entfernt werden, `VC:docs/HOOKS.md:45-46`), `vcap_card_moved_to_credit`, `vcap_ticket_checked_in` (ohne Beschreibung, `VC:docs/HOOKS.md:40`) und alle Actions der Oberfläche.

Auslöser einer Review-Einladung ist `voucher.voucher.redeemed` (D7), und nur für Gutscheine, die der Betreiber als Leistung oder Erlebnis eingestuft hat (`VC:README.md:98`). Diese Einstufung trifft der Adapter, nicht der Kern.

`subject`: Voucher Captain hat keine öffentliche Kennung eines Gutscheins außer Code und Token. `subject.id` ist die `voucher_id` mit `id_kind` = `record` (F-02).

Beträge übergibt der Kern als `float`. Der Adapter MUSS sie in die kleinste Einheit der Währung umrechnen (`amount_minor`, Integer).

### 4.4 Affiliate Captain (`source.product` = `affiliate-captain`, 21 Actions, 21 Ereignisse)

Vertrag 1.4.0. Alle Actions dieser Tabelle feuern erst nach dem Commit, seit Plugin 0.15.0 auch innerhalb einer äußeren Transaktion; wird eine Buchung zurückgerollt, feuert nichts (`AC:docs/CONTRACT.md:210`). Die Ereignisse sind Benachrichtigungen ohne Kundendaten, mit den dort genannten Ausnahmen. „Seit“ meint die Vertragsversion, „Plugin“ die Plugin-Version (`AC:docs/CONTRACT.md:5`).

| Suite-Ereignis | PHP-Action | Seit | Argumente heute (`AC:docs/CONTRACT.md:187-206`) | Nicht weiterreichen |
|---|---|---|---|---|
| `affiliate.affiliate.created` | `wpac_affiliate_created` | zu prüfen | `int $affiliate_id, string $status` | – |
| `affiliate.affiliate.status_changed` | `wpac_affiliate_status_changed` | zu prüfen | `int $affiliate_id, string $new, string $old` | – |
| `affiliate.affiliate.recruited` | `wpac_affiliate_recruited` | 1.4.0 | `int $affiliate_id, int $recruiter` (`AC:src/Affiliates/AffiliateRepository.php:363`) | – |
| `affiliate.affiliate.erased` | `wpac_affiliate_erased` | zu prüfen | `int $affiliate_id, bool $open` | – (zugleich Löschsignal, R-12.5) |
| `affiliate.sale.attributed` | `wpac_affiliate_attributed` | zu prüfen | `string $order_id, int $affiliate_id, string $method, string $source` | – |
| `affiliate.commission.created` | `wpac_commission_created` | zu prüfen; für jede Buchungsart seit 1.3.0 | `int $commission_id` | – |
| `affiliate.commission.adjusted` | `wpac_commission_adjusted` | zu prüfen | `array $refund, int[] $adjustment_ids` | **Freitext `reason` in `$refund`** (`AC:docs/CONTRACT.md:210`); Listen werden zu einer Anzahl |
| `affiliate.commission.approved` | `wpac_commission_approved` | zu prüfen | `int $commission_id` | – |
| `affiliate.commission.rejected` | `wpac_commission_rejected` | zu prüfen | `int $commission_id` | – |
| `affiliate.commission.paid` | `wpac_commission_paid` | zu prüfen | `int $commission_id` | – |
| `affiliate.commission.pending` | `wpac_commission_pending` | zu prüfen | `int $commission_id` | – |
| `affiliate.commission.review_reopened` | `wpac_commission_review` | zu prüfen | `int $commission_id` | – |
| `affiliate.payout.created` | `wpac_payout_created` | 1.2.0 | `int $payout_id, int $affiliate_id` | – |
| `affiliate.payout.paid` | `wpac_payout_paid` | zu prüfen | `int $payout_id` | – |
| `affiliate.payout.cancelled` | `wpac_payout_cancelled` | 1.3.0 | `int $payout_id` | – |
| `affiliate.correction.generated` | `wpac_correction_generated` | zu prüfen | `int $correction_id, string $path` | **`$path` (Dateipfad)** |
| `affiliate.creative.created` | `wpac_creative_created` | 1.4.0, Plugin 0.15.0 | `int $creative_id, array $info` | `title` |
| `affiliate.creative.updated` | `wpac_creative_updated` | 1.4.0, Plugin 0.15.0 | `int $creative_id, array $info` | `title` |
| `affiliate.code_request.created` | `wpac_code_requested` | 1.4.0, Plugin 0.15.0 | `int $affiliate_id, string $code, int $row_id` | – (`$code` ist der öffentliche Partnercode und erlaubt, D8b; Feld `partner_code`) |
| `affiliate.code_request.approved` | `wpac_code_request_approved` | 1.4.0, Plugin 0.15.0 | `int $affiliate_id, string $code, int $row_id, bool $make_main` | – (Partnercode erlaubt, D8b) |
| `affiliate.code_request.rejected` | `wpac_code_request_rejected` | 1.4.0, Plugin 0.15.0 | `int $affiliate_id, string $code, string $reason, int $row_id` | **`$reason`** (Partnercode erlaubt, D8b) |

Geldbeträge sind erlaubt (D8b): Der Adapter KANN bei `affiliate.sale.attributed`, `affiliate.commission.*`, `affiliate.payout.*` und `affiliate.correction.generated` den Betrag als `amount_minor` (Integer, kleinste Einheit) mit `currency` (ISO 4217) mitgeben. Die Actions übergeben nur IDs; den Betrag liest der Adapter über `wpac_get_commission()` beziehungsweise `wpac_get_payout()` (`AC:docs/CONTRACT.md:7`).

Nicht im Katalog: `wpac_mail_sent`, `wpac_mail_failed` und `wpac_contract_not_queued` (enthalten Kundendaten, `AC:docs/CONTRACT.md:210`), `wpac_referral_captured`, `wpac_refund_after_correction`, `wpac_statement_generated`, `wpac_storno_generated`.

#### 4.4.1 Der vorläufige Workflow-Provider im Affiliate-Kern: entfernt (D13)

Affiliate Captain enthielt bis zum 16.11.2026 eine eigene Provider-Seite für Workflow Captain (`wpac_workflow_event( array $envelope )`, `wpac_workflow_read()`, `wpac_workflow_event_types()`, `docs/WORKFLOW-PROVIDER.md`). Sie entstand vor den Entscheidungen des Inhabers.

**Stand (D13, umgesetzt am 16.11.2026):** Die Provider-Seite ist aus dem Affiliate-Kern entfernt (Commit `a928a14`, Stand `23f06be`). Im Code und in den Dokumenten des Kerns kommt `wpac_workflow` nicht mehr vor; ein Wächtertest in `AC:tests/run.php:365-372` schlägt fehl, sobald die Schnittstelle zurückkehrt, bevor die Aufgabe AFF-WF-08 abgehakt ist. Affiliate Captain 0.15.0 erscheint **ohne** Workflow-Provider; der Vertrag bleibt 1.4.0 (`AC:affiliate-captain.php:41`). Die Wiedereinführung ist dort als AFF-WF-08 geführt und setzt voraus, dass die drei Add-ons im Verkauf sind (D12) und diese Spezifikation abgenommen ist. Für den Voucher-Kern gilt dieselbe Entscheidung für den Provider aus Pull Request 16 (Umschlag `vcap_workflow_event`, Lizenzwissen, Mail-Übergabe); ob sie dort schon umgesetzt ist, ist zu prüfen (Z-16).

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

Siehe Abschnitt 11 für Rahmen und fehlende Actions. Der Lizenzserver hat keine Vertragskonstante und keine Zusage „nach Commit“ (`LIC:docs/TODO.md`, Abschnitt 12, Absatz „Lizenzereignisse gibt es nur als PHP-Aktionen“).

| Suite-Ereignis | PHP-Action | Seit | Argumente heute | Nicht weiterreichen | Nach Commit |
|---|---|---|---|---|---|
| `license.license.issued` | `wplc/license_issued_followup` | zu prüfen | `int $license_id, int $order_id` (`LIC:src/License/LicenseIssuer.php:878`) | – | zu prüfen |
| `license.license.status_changed` | `wplc/license_status_changed` | zu prüfen | `int $license_id, string $old_status, string $new_status, string $action` (`LIC:src/License/LicenseLifecycle.php:1221`) | – | zu prüfen |
| `license.license.plan_changed` | `wplc/license_plan_changed` | zu prüfen | `int $license_id` (`LIC:src/License/PlanChange.php:1022`; „after all locks are released“) | – | zu prüfen |

**Ausdrücklich kein Suite-Ereignis:**

| PHP-Action | Grund | Regel für den Adapter |
|---|---|---|
| `wplc/license_issued` | **übergibt den Lizenzschlüssel im Klartext:** `$license_id, $key, $order_id` (`LIC:src/License/LicenseIssuer.php:524`, `:823`; `LIC:docs/EXTENSION-POINTS.md:10`) | DARF NICHT angehängt werden. Ersatz: `wplc/license_issued_followup` |
| `wplc/license_key_reissued`, `wplc/license_key_resend_requested` | übergeben den Lizenzschlüssel (`LIC:src/Admin/SupportActions.php:151`, `:189`) | DARF NICHT angehängt werden |

Weitere vorhandene Actions (zum Beispiel `wplc/license_owner_changed`, `wplc/activation_transferred`, `wplc/chargeback`) sind in Version 1 nicht im Katalog.

### 4.6 Form, Review und Course Captain (vorgemerkt, kein Teil von v1)

Die Präfixe `form.`, `review.` und `course.` sind reserviert. Ihre Kataloge kommen in einer Minor-Version dieser Spezifikation, sobald der jeweilige Kern veröffentlicht ist. Stand:

- **Form Captain:** `FC:docs/spec/api-v1.md:23-32` nennt sechs Actions „nach Commit“: `form_captain_form_created_v1`, `form_captain_form_updated_v1`, `form_captain_entry_created_v1`, `form_captain_entry_status_changed_v1`, `form_captain_entry_deleted_v1`, `form_captain_notification_failed_v1`. Ob der Code sie auslöst, ist zu prüfen.
- **Review Captain:** Im Code belegt sind `review_captain_review_created_v1`, `review_captain_review_status_changed_v1` und `review_captain_review_removed_v1`. `RC:docs/contracts/workflow-provider-v1.md` nennt weitere Actions für Anfragen; ob der Code sie auslöst, ist zu prüfen. Der Vertrag dort beschreibt einen eigenen Descriptor und eigene Nutzdaten und ist an diese Spezifikation anzupassen (F-12).
- **Course Captain:** kein Code. Produktschlüssel `course-captain-pro` und Präfix `course.` kennt der Lizenzserver nicht (`LIC:src/Catalog/EntitlementCatalog.php:179-183` nennt nur Workflow, Form und Review).

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
| `source.product` | String | ja | Consumer | Ordner und Textdomain des Providers: `voucher-captain`, `pass-captain`, `affiliate-captain`, `wp-license`, künftig `form-captain`, `review-captain`, `course-captain`, `workflow-captain` |
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
| alle anderen | nicht gesetzt, solange die Tabelle hier nichts nennt |

Der Gast eines Tickets und der Empfänger eines Gutscheins haben oft kein WordPress-Konto. Für sie gibt es dann keine Benutzer-ID; der Adapter erreicht sie nur über die Lesefunktionen des Providers (R-5.6, F-13).

**Erlaubte Felder in `data`.** Für jeden Ereignistyp legt der Adapter eine feste Liste an und veröffentlicht sie mit seinem Add-on. Für die sechs Beispiele dieser Fassung gilt:

| Ereignistyp | Felder |
|---|---|
| `pass.ticket.checked_in` | `checkin_source` (String), `device_id` (Integer), `area_id` (Integer) |
| `voucher.voucher.redeemed` | `amount_minor` (Integer), `currency` (ISO 4217), `order_id` (Integer), `channel` (`shop`, `counter`, `external`), `redemption_source` (String), `committed_at` (UTC), `redemption_key` (String) |
| `voucher.voucher.issued_standalone` | `amount_minor`, `currency`, `issue_source` (String), `reference` (String, der Idempotenzschlüssel des Aufrufs) |
| `affiliate.code_request.approved` | `affiliate_id` (Integer), `partner_code` (String, öffentlich), `main` (Wahrheitswert) |
| `affiliate.commission.approved` | `affiliate_id`, `amount_minor`, `currency`, `kind` |
| `license.license.status_changed` | `product` (Produktschlüssel), `plan` (`single`, `business`, `agency`), `status`, `previous_status`, `lifecycle_action` |

Die Listen aller übrigen Ereignistypen sind eine Aufgabe dieses Repositorys vor Version 1.0.0 (siehe `TODO.md`, WF-SPEC-03).

Beispiele: [`pass.ticket.checked_in.json`](examples/pass.ticket.checked_in.json), [`voucher.voucher.redeemed.json`](examples/voucher.voucher.redeemed.json), [`voucher.voucher.issued_standalone.json`](examples/voucher.voucher.issued_standalone.json) (durch einen Workflow ausgelöst), [`license.license.status_changed.json`](examples/license.license.status_changed.json). Die Werte sind erfunden; das Format der `public_id` eines Tickets ist zu prüfen. [`affiliate.code_request.approved.json`](examples/affiliate.code_request.approved.json) zeigt den erlaubten Partnercode, [`affiliate.commission.approved.json`](examples/affiliate.commission.approved.json) einen Geldbetrag (D8b). `python3 bin/validate-spec-examples.py` prüft Schema und Beispiele gegeneinander.

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
| Voucher Captain | ja für alle Actions mit Vermerk „after COMMIT“ (9 der 15 Actions in 4.3); für die sechs ohne Vermerk zu prüfen (Z-05) | `VC:docs/HOOKS.md:5-6`, `VC:CONNECTORS.md:39` |
| Pass Captain | ja für 9 der 13 Actions in 4.2 (zum Teil nur für einen von mehreren Auslöseorten); 4 zu prüfen | Fundstellen in 4.2 |
| Affiliate Captain | ja für alle 21. Der Kern feuert nur diese fachlichen Actions; eine zusätzliche Workflow-Action gibt es seit der Entfernung nach D13 nicht mehr | `AC:docs/CONTRACT.md:210` |
| Lizenzserver | zu prüfen für alle 3; der Lizenzserver sagt selbst „keine Garantie nach dem Commit“ | `LIC:docs/TODO.md`, Abschnitt 12 |

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

- Wiederholung: höchstens **8 Versuche** mit den Abständen 1 Minute, 5 Minuten, 15 Minuten, 1 Stunde, 3 Stunden, 6 Stunden, 12 Stunden, 24 Stunden; danach `dead_letter`. Acht Versuche entsprechen der Warteschlange von Pass Captain (`PC:docs/SUITE-EVENTS.md:15`) und dem Standard von Affiliate Captain (`AC:docs/CONTRACT.md:217`). Die Abstände sind ein Vorschlag (F-15).
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
- R-7.13 Ein Ereignis mit `chain_depth` größer als **3** startet keinen Workflow und erhält den Zustand `loop_blocked`. Das Schema erlaubt Werte bis 8, damit die Grenze später ohne neue MAJOR-Version angehoben werden kann. Die Zahl 3 ist ein Vorschlag (F-15).
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

Lesefunktionen heute: Affiliate Captain hat `wpac_get_affiliate()`, `wpac_get_commission()` und `wpac_get_payout()` (`AC:docs/CONTRACT.md:7`); `wpac_workflow_read()` gibt es nicht mehr (R-4.6). Für Voucher Captain (`VC:API.md`) und Pass Captain (Klassen des Kerns, `PC:docs/SUITE-EVENTS.md:24-25`) sind die genauen Funktionen zu prüfen (Z-07). Im Lizenzserver fehlt eine Lesefassade (`LIC:docs/TODO.md`, WFC-LIC-05). Die Liste der anerkannten Lesefunktionen je Provider ist eine offene Aufgabe dieses Repositorys (`TODO.md`, WF-SPEC-15).

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

Stand im Kern heute: `Issuer::void( Voucher $voucher, string $note = '', bool $refund = false ): bool` (`VC:src/Issuer.php:2012`) und `Issuer::reactivate( Voucher $voucher, bool $after_refund = false ): bool` (`VC:src/Issuer.php:2072`). Beide nehmen ein `Voucher`-Objekt, eine freie Notiz und einen Schalter für den Erstattungsfall. Ob sie eine Berechtigung prüfen, einen Idempotenzschlüssel kennen und was `$refund = true` bucht, ist zu prüfen (Z-15). Tageszähler fehlen. Der Branch von Pull Request 16 soll eigene Dienste für Sperren und Reaktivieren enthalten; das war im gelesenen Stand nicht prüfbar (Z-15).

Der neue Einstiegspunkt MUSS `$refund` beziehungsweise `$after_refund` fest auf `false` setzen und statt der freien Notiz nur `reason` als festen Schlüssel annehmen.

#### 8.3.4 `voucher.redeem`

| Feld | Pflicht | Regel |
|---|---|---|
| `voucher_id` | ja | der Gutschein |
| `amount_minor`, `currency` | ja | einzulösender Betrag; nie mehr als der Restwert |
| `target` | ja | fester Schlüssel des Adapters dafür, wofür eingelöst wird |

Grenzen: siehe Tabelle in 8.3.1. Eingelöst wird nie mehr als der Restwert des Gutscheins.

Stand im Kern heute: Die Einlöse-API `API\V1\RedemptionService` arbeitet in zwei Schritten und **über den Code**: `reserve( string $code, array $args )` (`VC:src/API/V1/RedemptionService.php:150`), danach `commit( string $key, array $args )` (`:378`); dazu `release()` (`:389`) und `refund()` (`:408`). Sie hat einen Idempotenzschlüssel (`redemption_key`) und feuert `vcap_voucher_redeemed` genau einmal nach dem Commit (`VC:docs/HOOKS.md:53`). Einen Weg über die `voucher_id`, Tageszähler und einen Höchstbetrag gibt es nicht; ob die API eine Berechtigung prüft, ist zu prüfen (Z-15).

Der neue Einstiegspunkt MUSS den Gutschein über die `voucher_id` annehmen, Reservieren und Bestätigen in einem Aufruf erledigen und DARF `refund()` nicht erreichbar machen.

`VC:README.md:96` sagt heute: „Kein Formular und kein Workflow stellt selbstständig Gutscheine aus.“ Dieser Satz ist durch D11 und D11b überholt und im Voucher-Repository zu ändern.

### 8.4 Berechtigung bei Hintergrundläufen

Ein Workflow läuft meist ohne angemeldeten Benutzer (Cron, Action Scheduler).

- R-8.4 Jeder Befehl trägt einen Idempotenzschlüssel aus Workflow, Version des Workflows, `id` des auslösenden Ereignisses und Schritt. Eine Wiederholung desselben Schritts nutzt denselben Schlüssel.
- R-8.5 Der Consumer DARF NICHT `wp_set_current_user()` aufrufen, um einem Lauf die Rechte eines Administrators zu geben.
- R-8.6 Jeder schreibende Befehl nennt die Berechtigung, die ein Mensch für dieselbe Handlung bräuchte. Für `voucher.issue` ist das `vcap_manage_vouchers` (`VC:src/Issuer.php:940-942`); für Sperren, Reaktivieren und Einlösen ist die Berechtigung des Kerns zu prüfen (Z-15).
- R-8.7 Beim Veröffentlichen eines Workflows MUSS der veröffentlichende Benutzer jede dieser Berechtigungen haben. Seine Benutzer-ID wird als `authorized_by_user_id` am Workflow gespeichert.
- R-8.8 Bei jeder Ausführung prüft der Adapter mit `user_can( $authorized_by_user_id, … )`, ob dieser Benutzer noch existiert und die Berechtigung noch hat. Wenn nicht, wird der Schritt nicht ausgeführt und der Workflow pausiert mit Grund.
- R-8.9 Lesende Abfragen und Befehle, die nur im Consumer schreiben, brauchen die Berechtigungen des Consumers beim Veröffentlichen, aber keine Prüfung bei jeder Ausführung.
- R-8.10 Jeder ausgeführte Befehl wird im Laufprotokoll mit `authorized_by_user_id`, Workflow, Lauf, Schritt, Idempotenzschlüssel und Ergebnis festgehalten.

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

**Was fehlt und eine Aufgabe des Lizenzservers ist** (dort WFC-LIC-04):

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
| `data.plan` | Tarifschlüssel `single`, `business` oder `agency` |
| `data.status`, `data.previous_status`, `data.lifecycle_action` | Zustand und Anlass |
| `actor_user_id` | Benutzer-ID des Kunden |

**Nie:** der Lizenzschlüssel oder ein Teil davon, Zahlungsdaten, E-Mail-Adresse, Name, Adresse oder Kennung einer aktivierten Website, Signaturschlüssel.

## 12. Datenschutz

> **Vorbehalt.** Dieser Abschnitt ist eine technische Einordnung und keine Rechtsberatung. Er steht unter dem Vorbehalt der Rechtsprüfung durch den Inhaber (D8; Entscheidung A6). Bis dahin ist keine Aussage dieses Abschnitts eine Zusage an Betreiber oder Kunden.

- R-12.1 Die Benutzer-ID ist ein personenbezogenes Datum. Zusammen mit `subject` zeigt ein gespeichertes Ereignis, dass eine Person etwas getan hat (Einlass, Einlösung, Lizenzstatus).
- R-12.2 **Zweck.** Der Consumer speichert Ereignisse nur, um die vom Betreiber eingerichteten Workflows auszuführen, Fehler zu wiederholen und Läufe nachvollziehbar zu machen. Eine Auswertung über Personen hinweg ist kein Zweck.
- R-12.3 **Sparsamkeit.** Es gilt Abschnitt 5. Der Speicher des Consumers enthält keine E-Mail-Adressen, Namen oder Codes, auch nicht in Protokollen und Fehlermeldungen.
- R-12.4 **Aufbewahrung.** Der Consumer löscht gespeicherte Ereignisse nach einer festen Frist. Vorschlag: erledigte Ereignisse nach 30 Tagen, `dead_letter` und `paused` nach 90 Tagen; `actor_user_id` und `data` werden schon geleert, sobald kein Lauf mehr darauf wartet. Die Fristen sind offen (F-04) und gehören in die Rechtsprüfung. Laufprotokolle haben eigene Fristen (`konzept.md`).
- R-12.5 **Auskunft und Löschung.** Der Consumer MUSS sich bei WordPress als Exporteur und als Löscher persönlicher Daten anmelden (`wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers`) und dabei alle gespeicherten Ereignisse, Markierungen und Notizen mit der Benutzer-ID der Person liefern beziehungsweise entfernen oder anonymisieren. Er MUSS außerdem auf das Löschen eines Benutzerkontos (`deleted_user`) und auf `affiliate.affiliate.erased` reagieren.
- R-12.6 **Mandantentrennung.** Daten liegen je Website (`site.blog_id`). Ein Ereignis verlässt seine Website nicht (F-07).
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
- R-13.6 Mindestversionen für Version 1: Voucher Captain 1.2.0 mit Vertrag 1.1.0; Pass Captain 1.5.0 mit Vertrag 1.1.0; Affiliate Captain 0.15.0 mit Vertrag 1.4.0 (erscheint ohne Workflow-Provider, D13). Für die vier Gutschein-Befehle zusätzlich die Vertragsversion von Voucher Captain, die die Einstiegspunkte aus 8.3 bringt.
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
| A-1 | Er liegt in einem Add-on; im Kern gibt es keine Zeile von ihm |
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
| Z-01 | „Seit“ je `wpcap_*`-Action des Katalogs (12 Actions) | Pass Captain: Git-Verlauf, `CHANGELOG.md`; danach in `docs/HOOKS.md` nachtragen |
| Z-02 | „Nach Commit“ für `wpcap_refund_rejected`, `wpcap_ticket_revoked`, `wpcap_occurrence_rescheduled`, die Admin-Absage eines Termins (`wpcap_occurrence_cancelled`), den Ablauf einer Reservierung (`wpcap_order_cancelled`) und `wp_pass_captain_invoice_created_v1` | Pass Captain: `src/Refunds.php:160`, `src/Orders.php:702`, `:1805`, `src/Admin/TicketsPage.php:56`, `src/Admin/EventsPage.php:212-245`, `src/Api/Invoices.php:107` |
| Z-03 | Ob `$reason` bei `wpcap_order_cancelled`, `wpcap_order_refunded` und `wpcap_ticket_revoked` Freitext sein kann | Pass Captain: Aufrufer der drei Actions |
| Z-04 | Öffentliche Kennungen für Erstattung, Termin, Event und Beleg in Pass Captain; Format der `public_id` | Pass Captain: Tabellen und Klassen des Kerns |
| Z-05 | „Nach Commit“ für `vcap_vouchers_issued`, `vcap_voucher_issued_standalone`, `vcap_voucher_delivered`, `vcap_voucher_delivery_given_up`, `vcap_voucher_bounced`, `vcap_voucher_reissued` (ohne Vermerk in der Hook-Tabelle) | Voucher Captain: `src/Issuer.php`, `src/Delivery.php`, `src/Reissue.php` |
| Z-06 | Was `vcap_ticket_checked_in` meldet | Voucher Captain: `src/Tickets.php` |
| Z-07 | Genaue Lesefunktionen für Voucher Captain, Pass Captain und Affiliate Captain (für Gegenlesen und Empfängerauflösung); `wpac_workflow_read()` entfällt (D13) | `VC:API.md`, `PC:docs/EXTENSIONS.md` |
| Z-08 | „Seit“ (Vertragsversion) der Affiliate-Actions ohne Angabe in 4.4 (13 Actions) | Affiliate Captain: Git-Verlauf; danach als Tabelle in `docs/CONTRACT.md` |
| Z-09 | Ob Affiliate Captain einen Test hat, der die dokumentierten Actions gegen den Code prüft | Affiliate Captain: `tests/` |
| Z-10 | „Seit“ und „nach Commit“ für die drei Lizenz-Actions des Katalogs | Lizenzserver: `src/License/LicenseLifecycle.php`, `LicenseIssuer.php`, `PlanChange.php` |
| Z-11 | Ob der Code von Form Captain die sechs dokumentierten Actions auslöst | Form Captain: `src/` |
| Z-12 | Ob der Code von Review Captain die Actions für Anfragen auslöst, die sein Workflow-Vertrag nennt | Review Captain: `src/`, `docs/hooks.md` |
| Z-13 | Der Stand von `captain-pro` wurde im Arbeitsverzeichnis gelesen, das uncommittete Änderungen enthielt; in Affiliate Captain war während des Lesens zeitweise eine Datei uncommittet | **Für `captain-pro` geklärt am 16.11.2026:** Die Fundstellen `CoreRequirement.php:60-64`, `Feature.php:41-52`, `CoreCapabilities.php:35-36` und `:92` stimmen im committeten Stand `07b5365` und auf `main` (`2f23da7`); der Kernel kennt weiterhin keine Prüfung der Lizenz eines anderen Add-ons (F-06 bleibt offen). Für Affiliate Captain offen (kein Zugriff aus dieser Sitzung). |
| Z-15 | Für Sperren, Reaktivieren und Einlösen im Voucher-Kern: Berechtigungsprüfung, Idempotenz, Wirkung von `$refund`/`$after_refund`; ob der Branch von Pull Request 16 dafür eigene Dienste enthält | Voucher Captain: `src/Issuer.php:2012-2110`, `src/API/V1/RedemptionService.php`, Branch `hvollo-refactors` |
| Z-16 | Ob der vorläufige Workflow-Provider aus Pull Request 16 im Voucher-Kern schon entfernt ist (D13) | Voucher Captain: Branches `hvollo-refactors` und `main` |
| Z-14 | Die Aufgabenstellung nennt 129 Hooks im Lizenzserver; gezählt wurden 36 Actions und 94 Filter, zusammen 130 | Lizenzserver: `docs/EXTENSION-POINTS.md` |

## 16. Änderungsverlauf

| Version | Datum | Änderung |
|---|---|---|
| 1.0.0-draft.3 | 16.11.2026 | F-05 entschieden: feste Grenzen der vier Gutschein-Befehle als Tabelle in 8.3.1 (100 EUR und 50 je Tag; Einlösen jedes Gutscheins); neue Teilfrage F-05a für Shops ohne Euro. D13 im Affiliate-Kern umgesetzt: 4.4.1, R-4.4 bis R-4.6, 7.2 und R-13.6 nennen die Entfernung als Stand; neue Stelle Z-16 für den Voucher-Kern. |
| 1.0.0-draft.2 | 16.11.2026 | Folgeentscheidungen eingearbeitet. D13: Abschnitt 4.4.1 neu, die vorläufigen Workflow-Provider werden vor dem Release aus den Kernen entfernt, `wpac_workflow_read()` ist keine anerkannte Lesefunktion. D8b: öffentlicher Partnercode und Geldbeträge erlaubt (R-5.1a, Katalog 4.4, Schema, zwei neue Beispiele). D11b: vier Gutschein-Befehle statt einem (Abschnitt 8 neu, Verbotsliste, Lizenzmatrix, R-13.6, P-8, A-9, neue Stelle Z-15). **Berichtigung:** Abschnitt 7.2 nannte für Voucher Captain „10 der 15“ Actions nach Commit und „fünf“ ohne Vermerk; richtig sind 9 und sechs. |
| 1.0.0-draft | 16.11.2026 | Erste Fassung nach den Entscheidungen D1, D2 und D4 bis D12 vom 16.11.2026. Katalog: Pass Captain 13, Voucher Captain 16, Affiliate Captain 21, Lizenzserver 3 Ereignisse. Schema und vier Beispiele. |
