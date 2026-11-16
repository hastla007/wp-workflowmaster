# Workflow Captain und Yoast SEO

Stand: 16.11.2026, Plugin 0.2.x. Aufgaben WF-SEO-04 bis WF-SEO-09 in [`../TODO.md`](../TODO.md).

## Kurz

Yoast SEO ist **optional**. Workflow Captain funktioniert mit Yoast aktiv, deaktiviert oder nicht installiert gleich. Es gibt keine Abhängigkeit, keinen Aufruf einer Yoast-Klasse und keinen Zugriff auf Yoast-Daten.

## Was gesichert ist

| Regel | Wie gesichert |
|---|---|
| Keine Ausgabe auf öffentlichen Seiten, keine REST-Routen, keine Sitemaps (WF-SEO-02, -03) | Test in `tests/security-tests.php` |
| Keine Kopplung an Yoast-Indexables, interne Klassen (`Yoast\WP\SEO\…`, `WPSEO_…`) oder Meta-Keys `_yoast_wpseo_*` (WF-SEO-05) | Test in `tests/security-tests.php` schlägt fehl, sobald solcher Code im Kern auftaucht |
| Beiträge werden nie per SQL geschrieben; `wp_insert_post`, `wp_update_post`, `update_post_meta` und `wp_set_object_terms` kommen im Kern nicht vor (WF-SEO-04) | derselbe Test |
| Das Ereignis `wordpress.post.published` liest nur Beitragstyp, Autor und vorherigen Status; Titel, Inhalt und SEO-Felder werden nicht gelesen | Spezifikation 4.7, Positivliste im Katalog |

## Kompatibilität und Supportgrenze (WF-SEO-06)

| Yoast SEO | Stand |
|---|---|
| nicht installiert | unterstützt |
| installiert und deaktiviert | unterstützt |
| aktiv, Versionen ab 22.0 (2024) | unterstützt; keine Abhängigkeit, daher keine Versionsbindung |
| Yoast SEO Premium, News, Video, WooCommerce SEO | unterstützt in demselben Umfang; nicht eigens getestet |

Weil der Kern keine Yoast-Funktion aufruft, kann ein Yoast-Update keinen fatalen Fehler in Workflow Captain auslösen. Support leisten wir für das Zusammenspiel „Workflow Captain ändert nichts an Yoast-Ausgaben“, nicht für Yoast selbst.

## Beitragsaktionen (WF-SEO-04, -08)

Workflow Captain hat heute **keine** Aktion, die Beiträge anlegt oder ändert. Kommt eine, gilt:

1. nur über `wp_insert_post()`/`wp_update_post()` bzw. die REST-API von WordPress, mit Prüfung von Beitragstyp, Status, Autor, Taxonomien und Berechtigung des veröffentlichenden Benutzers (R-8.6 bis R-8.8);
2. vorhandene Meta-Felder anderer Plugins (auch Yoast) werden nie überschrieben; die Aktion schreibt nur die Felder, die sie ausdrücklich konfiguriert;
3. vor der Freigabe die Prüfungen aus WF-SEO-07 und WF-SEO-08 in einer echten Installation: Classic Editor und Block Editor, öffentlicher und privater Status, Yoast-Metabox und -Sidebar unverändert, SEO-Felder nach dem Lauf erhalten.

Der Test in `tests/security-tests.php` verlangt dann eine bewusste Ausnahme für genau diese Aktion.

## Regressionstest in einer echten Installation (WF-SEO-07)

Steht in [`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md): Frontend-HTML und REST-Antworten einer Seite, eines Beitrags und eines Produkts mit und ohne Workflow Captain vergleichen (Title, Meta-Description, Canonical, Robots, Open Graph, Schema-Graph, XML-Sitemap). Erwartet: keine Unterschiede, keine Daten von Workflow Captain in öffentlichen Antworten. Braucht eine echte WordPress-Installation mit Yoast und ist hier nicht ausführbar.

## Headless (WF-SEO-09)

Zurückgestellt, bis es eine konkrete Produktanforderung gibt. Falls ja: nur die dokumentierten Felder `yoast_head` und `yoast_head_json` der REST-API lesen; nie Daten von Workflow Captain an öffentliche SEO-Antworten anhängen.
