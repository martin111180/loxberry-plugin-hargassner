# Entwicklung und Releases

## Aufbau

* `plugin/`: genau der Inhalt des LoxBerry-Plugin-ZIPs (im ZIP liegt er im Ordner `hargassner/`)
  * `bin/hargassner_protocol.py`: Parser (Datensätze trennen, Länge prüfen, Topics bilden), ohne Abhängigkeiten
  * `bin/hargassner_bridge.py`: Dienst (seriell/TCP lesen, MQTT, Watchdog, Live-Status für die Weboberfläche)
  * `bin/service.sh`: start/stop/restart/status/watchdog; startet die Bridge als Benutzer `loxberry`
  * `webfrontend/htmlauth/index.php`: Weboberfläche (Werte und Feldnamen, Einstellungen, Sicherung)
  * `config/hargassner.json`: Standardkonfiguration; `config/requirements.txt`: Python-Pakete (exakt gepinnt)
  * `postroot.sh`: fügt `loxberry` der Gruppe `dialout` hinzu (Zugriff auf `/dev/ttyUSB*`)
* `tests/`: Unit-Tests des Parsers mit einem echten Datensatz der Anlage
* `tools/build_zip.py`: baut `dist/loxberry-plugin-hargassner-<version>.zip`
* `alt/`: die früheren Gemini-Skripte und `hargassner_mapping.json` (importierbar), nur zur Referenz

Alle Textdateien brauchen LF-Zeilenenden (`.gitattributes` erzwingt das; `build_zip.py` bricht bei CRLF ab).

## Laufzeit

* Python-Umgebung: `REPLACELBPDATADIR/venv` (wird von `postinstall.sh` mit `python3 -m venv` angelegt)
* Live-Status: `/dev/shm/loxberry-plugin-hargassner/status.json` (im RAM, alle 2 s, schont die SD-Karte)
* Log: `REPLACELBPLOGDIR/hargassner.log`; Ausgaben beim Start: `stdout.log`
* Die Weboberfläche ruft nach jedem Speichern `service.sh restart` auf; die Bridge liest die Konfiguration
  nur beim Start.

## Tests

```bash
python -m unittest discover -s tests -v
python tools/build_zip.py
```

Die CI (`.github/workflows/release.yml`) führt beides mit Python 3.11 (LoxBerry 3) und 3.13 (LoxBerry 4)
aus und prüft außerdem Shell- und PHP-Syntax.

Ohne Heizung testen: Datenstrom per TCP simulieren und die Bridge auf „Netzwerk“ stellen, z. B.

```bash
while true; do echo -ne "pm 100 1 7.1 68 202 ... tm $(date +%F) $(date +%T)\r\n"; sleep 1; done | nc -lk 4196
```

## Neue Version veröffentlichen

1. In `plugin/plugin.cfg` `VERSION=` erhöhen und committen.
2. `git tag v0.1.1 && git push origin main --tags`
3. Der Workflow prüft, ob Tag und `VERSION` übereinstimmen, baut das ZIP, legt das GitHub-Release an und
   setzt `release.cfg` auf `main`. Ab dann bieten die LoxBerrys das Update an.

## Pfade

Nie `/opt/loxberry` fest eintragen. Stattdessen die Platzhalter verwenden, die der LoxBerry bei der
Installation ersetzt: `REPLACELBHOMEDIR`, `REPLACELBPBINDIR`, `REPLACELBPCONFIGDIR`, `REPLACELBPDATADIR`,
`REPLACELBPLOGDIR`.
