# Prüfung der Gemini-Lösung (Stand 14.01.2026)

Geprüft wurden der Gemini-Verlauf („Unterhaltung mit Gemini.docx“) und die daraus entstandenen Skripte
(jetzt in `alt/`). Die meisten Erkenntnisse waren brauchbar. Einige Aussagen waren aber falsch, und die
Skripte hatten Schwächen, die im Dauerbetrieb Probleme machen. Das Plugin behebt alle Punkte unten.

## Was stimmt und übernommen wurde

* Datenstrom: ASCII, etwa 1 Datensatz pro Sekunde, beginnt mit `pm`, Werte durch Leerzeichen getrennt,
  19200 Baud 8N1 (mit `stty … && cat` bestätigt).
* `readline()` funktioniert nicht zuverlässig; deshalb wird der Puffer selbst nach `pm` zerlegt.
* Index-Zählung ab 0 mit Index 0 = `pm`. Das Plugin zählt **genau gleich**, damit die bisher ermittelten
  Zuordnungen (`hargassner_mapping.json`) weiter stimmen. Gesendet werden aber nur noch benannte Werte.
* MQTT statt UDP: Der Miniserver bekommt nur Änderungen und abonniert nur, was er braucht.

## Falsche Aussagen von Gemini

| Aussage | Tatsächlich |
|---|---|
| `ff` am Ende ist eine Checksumme | Es gibt keine Prüfsumme. `ff1` und `ffff` sind **hexadezimale** Digitalwerte (Bitmasken), wie im Doku-Beispiel `0015`. |
| Digitalwert `2129` ist dezimal, Störung = `(v & 128)` | Hargassner sendet Digitalwerte **immer hexadezimal** (so lesen sie auch die DAQ-Kanallisten der Steuerung und die Home-Assistant-Integrationen). `2129` ist also 0x2129, und `128` bedeutet nicht Bit 7, sondern die Bits 3, 5 und 8. Das Plugin zerlegt Bitmasken hexadezimal mit bis zu 32 Bits und zeigt sie live an. |
| Formel `(I1 & 128) > 0` bzw. `v1 & 128` in Loxone | Loxone-Formeln kennen kein bitweises UND. Das Plugin zerlegt die Bits selbst und sendet jedes Bit als eigenes Topic (0/1). |
| `idx_182` entsteht durch doppelte Leerzeichen; Abhilfe `split()` ohne Argument | `split()` ohne Argument war schon vorher im Skript, die „Korrektur“ hat nichts geändert. Ursache: Geht ein `pm` verloren (Störung, Start mitten im Datensatz), verschmelzen zwei Datensätze, und alle Werte rutschen auf falsche Indizes. Das Plugin verwirft Datensätze mit falscher Länge. |
| Indexbelegung (3 = Außentemperatur, 8 = Kessel, 10/11 = Puffer …) | Geraten. Die eigene Zuordnung aus `hargassner_mapping.json` (z. B. 4 = Kesseltemp, 6 = Außentemp) ist maßgeblich. |
| `pip3 install paho-mqtt` | Ab Debian 12 (LoxBerry 3) blockiert pip Installationen ins System-Python. Das Plugin nutzt eine eigene Python-Umgebung mit festen Versionen. |
| Bei Bedarf `mosquitto.db` löschen | Löscht die gespeicherten Werte **aller** Geräte. Das Plugin löscht beim Start nur seine eigenen verwaisten Topics. |

## Schwächen der Skripte

1. **Passwort im Klartext** im Skript und im Gemini-Verlauf (`MQTT_PASS`, `mosquitto_sub -P …`).
   → Das Plugin liest die Zugangsdaten aus der LoxBerry-Konfiguration. **Empfehlung:** Das
   MQTT-Passwort im LoxBerry ändern, weil es im Verlauf steht. Den Verlauf nicht weitergeben
   (`*.docx` steht in `.gitignore`).
2. **Jede Sekunde ~92 Nachrichten** an den Broker (retained), auch wenn sich nichts ändert.
   → Das Plugin sendet nur Änderungen und frischt alle 5 min komplett auf.
3. **Kein Wiederverbinden:** Abgezogener USB-Adapter oder Broker-Neustart beenden das Skript (systemd
   startet es dann neu). Die MQTT-Verbindung wird schon beim Import aufgebaut, und ist der Broker dabei
   nicht erreichbar, stürzt das Skript ab.
   → Das Plugin verbindet sich selbst neu (seriell, TCP und MQTT), mit Watchdog bei ausbleibenden Daten
   und einem Last Will `bridge/status = offline`.
4. **Veraltete MQTT-API** (`Callback API version 1 is deprecated`).
   → paho-mqtt 2.x mit `CallbackAPIVersion.VERSION2`.
5. **Port-Konflikt:** Liefen Monitor und MQTT-Skript gleichzeitig, nahmen sie sich gegenseitig die Bytes
   weg; Linux sperrt serielle Ports nicht von selbst.
   → Das Plugin öffnet den Port exklusiv. Die Live-Ansicht in der Weboberfläche ersetzt den Monitor und
   den ID-Finder und braucht den Port nicht.
6. **Handarbeit außerhalb des LoxBerry:** Skripte in `/opt/loxberry/bin`, systemd-Dienst in `/etc`,
   Root-Zugriff nötig. Das geht bei einer Neuinstallation verloren.
   → Das Plugin bringt alles mit (Dienst, Cron-Watchdog, Updates) und sichert die Einstellungen als Datei.
7. **Busy-Polling** (`in_waiting` + `sleep(0.1)`) statt blockierendem Lesen mit Timeout.
8. **`finally: ser.close()`** wirft einen Folgefehler, wenn das Öffnen schon fehlgeschlagen ist.
9. **Keine Prüfung der Daten:** Jeder Datensatz wurde ungeprüft gesendet, auch zu kurze oder
   verschmolzene.

## Umstieg

1. Plugin am zentralen LoxBerry installieren, Verbindung „Netzwerk“ mit IP/Port des Waveshare-Wandlers.
2. Unter „Sicherung“ die Datei `alt/hargassner_mapping.json` einspielen (nur „Feldnamen übernehmen“).
3. Bis der Wandler da ist: Plugin auf dem dezentralen LoxBerry installieren (Verbindung „Seriell“) und den
   alten Dienst abschalten: `sudo systemctl disable --now hargassner.service`
   (die Weboberfläche warnt, solange er noch läuft).
4. Das Plugin sendet nur benannte Werte (z. B. `hargassner/kesseltemp`), keine `idx_…`-Topics mehr. Loxone-Eingänge
   auf `hargassner_kessel_idx_…` auf die Namen umstellen. Nach dem Umzug das Plugin am dezentralen LoxBerry stoppen oder deinstallieren, damit nicht
   zwei Quellen dieselben Eingänge beschreiben.
