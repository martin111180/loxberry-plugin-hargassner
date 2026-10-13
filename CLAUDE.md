# CLAUDE.md

LoxBerry-Plugin, das den Datenstrom einer Hargassner-Heizung (seriell oder über einen Seriell-Ethernet-Wandler
per TCP) liest und die Werte per MQTT an den LoxBerry-Broker schickt (→ MQTT Gateway → Loxone Miniserver).
Aufbau wie das Schwester-Plugin `../loxberry-plugin-mammotion`.

* Sprache: Deutsch (UI, Logs, Doku, Commit-Nachrichten)
* Plugin-Inhalt liegt in `plugin/`, Release-Ablauf in `docs/entwicklung.md`
* Vor jedem Commit: `bash -n` für Shell-Skripte, `python -m unittest discover -s tests` und `python tools/build_zip.py` müssen durchlaufen
* Versionsnummer nur in `plugin/plugin.cfg` ändern; `release.cfg` pflegt der Workflow
* `[AUTHOR]` in `plugin/plugin.cfg` nie ändern (LoxBerry erkennt das Plugin daran)
* Keine festen `/opt/loxberry`-Pfade – LoxBerry-Platzhalter wie `REPLACELBPBINDIR` verwenden
* Python-Abhängigkeiten exakt pinnen; Updates kommen über Dependabot-PRs
* Texte der Weboberfläche nur in `plugin/templates/lang/language_de.ini` und `language_en.ini` – beide immer gemeinsam pflegen
* Index-Zählung (Index 0 = "pm", Datensatz bis vor das nächste "pm") nie ändern – die gespeicherten Feldnamen hängen daran
* Per MQTT nur benannte Werte und benannte Bits senden (keine idx-Topics) – was an den Miniserver geht, entscheidet das MQTT Gateway
* Was doppelt existiert, gleich halten: `slug()` in Python und PHP, `DEFAULT_CONFIG`/`default_config()`, `STATUS_FILE`/`$hg_statusfile`
* Keine Passwörter in Dateien des Repos; Sicherungen enthalten kein MQTT-Passwort
* Keine automatischen MQTT-Abos: welche Topics an den Miniserver gehen, entscheidet der Nutzer im MQTT Gateway
