# Wettbewerbszeitleiste

WordPress-Plugin zur Verwaltung und responsiven Darstellung schulischer Wettbewerbe und Termine als interaktive Zeitleiste.

## Voraussetzungen

- WordPress 6.8 oder neuer
- PHP 7.4 oder neuer
- ACF PRO

## Funktionen

- Verwaltung über ACF-PRO-Options-Pages, ohne Custom Post Types und ohne eigene Datenbanktabellen
- Wettbewerbe mit Fächern, Klassenstufen, Links, Schuljahren und Terminen
- erweiterbare Fächer und Terminarten
- Archivieren, Wiederherstellen und endgültiges Löschen
- JSON-Import/-Export und CSV-Terminimport
- responsive Swimlane nach Fächern und Wettbewerben
- mobile chronologische Listenansicht
- Filter nach Fach, Klassenstufe und Terminart
- Zeitfenster „Ganzes Jahr“, „6 Monate“ und „3 Monate“ ohne springende Zoomposition
- Desktop-Hovervorschau, Touch-Vorschau und Detailmodal
- Shortcodes `[wettbewerbszeitleiste]` und `[wettbewerbe_naechste]`
- native WordPress-Updates über stabile GitHub Releases

## Releases und WordPress-Updates

Die Hauptdatei enthält eine `Update URI` auf dieses Repository. WordPress fragt das neueste stabile GitHub Release ab und verwendet ausschließlich das dort angehängte Plugin-ZIP.

Ein Push auf `main` veröffentlicht automatisch ein neues Release, **wenn die Versionsnummer in `wettbewerbszeitleiste.php` noch nicht als GitHub Release existiert**. Der GitHub-Actions-Workflow erzeugt dabei ein korrekt strukturiertes `wettbewerbszeitleiste-VERSION.zip`.

Für eine neue Version daher zuerst die Versionsnummer im Plugin-Header und in `WZ_VERSION` erhöhen und anschließend nach `main` pushen.

## Version 0.2.4

- automatische Unterspuren für zeitlich überlappende Termine innerhalb eines Wettbewerbs
- dynamische Zeilenhöhe mit maximal drei kompakten Unterspuren
- bei höherer Dichte erscheint `+ N weitere`; die Zeile kann vollständig aufgeklappt und wieder reduziert werden
- kürzere, intelligent reduzierte Beschriftungen direkt in der Zeitleiste; vollständige Titel bleiben in Vorschau und Modal erhalten
- Kollisionsprüfung berücksichtigt die tatsächliche grafische Breite von Punkten und Zeiträumen

## Version 0.2.3

- erste über GitHub veröffentlichte Version mit nativer WordPress-Updatequelle
- mobile Filterleiste kompakter und gleichmäßig angeordnet
- Fachgruppen mit sauberem Chevron-Button und abgesicherter Textbreite
- Zeitsteuerung über verständliche Zeitfenster statt `+`/`−`
- stabiler zeitlicher Mittelpunkt beim Wechsel des Zeitfensters
- mobile Umschaltung zwischen Zeitleiste und chronologischer Liste
