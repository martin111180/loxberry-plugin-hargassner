# LoxBerry-Plugin: Hargassner Heizung

Liest den Datenstrom einer Hargassner-Steuerung (Hackgut/Pellets, „pm“-Datensätze, ca. 1/s) und
veröffentlicht die Werte per MQTT auf dem LoxBerry-Broker. Das MQTT Gateway des LoxBerry gibt sie an den
Loxone Miniserver weiter.

* **Zwei Anschlussarten:** USB-RS232-Adapter direkt am LoxBerry oder Seriell-Ethernet-Wandler im Netzwerk
  (z. B. Waveshare RS232/485/422 TO POE ETH, Modus „TCP Server“, Port 4196)
* **Live-Ansicht** aller Werte mit Änderungszähler zum Zuordnen der Indizes (ersetzt Monitor und ID-Finder)
* **Feldnamen und Bitmasken:** Digitalwerte (hex oder dezimal) werden in einzelne Bits zerlegt, z. B. die Störlampe
* **Sicherung als Datei** (Einstellungen und Feldnamen): nach einer Neuinstallation einfach wieder einspielen
* Sendet nur Änderungen, verwirft fehlerhafte Datensätze, verbindet sich selbst neu, mit Watchdog

## Installation

ZIP aus den [Releases](https://github.com/martin111180/loxberry-plugin-hargassner/releases) in der
LoxBerry-Plugin-Verwaltung installieren (ab LoxBerry 3.0). Danach unter **Einstellungen** die Verbindung wählen.

## MQTT-Topics

Basis-Topic standardmäßig `hargassner/kessel` (wie bei den früheren Skripten):

| Topic | Inhalt |
|---|---|
| `…/idx_<n>` | Rohwert an Position n (`idx_0` = `pm`), wie bisher |
| `…/idx_<n>/bit_<b>` | 0/1 je Bit, nur für Felder vom Typ Bitmaske |
| `…/<name>` | Wert unter dem vergebenen Feldnamen, z. B. `…/kesseltemp` |
| `…/<bitname>` | 0/1 für benannte Bits, z. B. `…/stoerlampe` |
| `…/bridge/status` | `online` / `offline` (Last Will) |
| `…/bridge/connected` | 1 = Daten von der Heizung kommen an |
| `…/bridge/error` | Fehlertext, `OK` = alles in Ordnung |

Im MQTT Gateway wird daraus z. B. der Eingang `hargassner_kessel_stoerlampe`.

## Die Störlampe finden

1. Unter **Werte** die Digitalwerte am Ende des Datensatzes (z. B. Index 82–86) auf **Bitmaske hex** stellen.
2. Bei der nächsten Störung beobachten, welches Bit umspringt (die Zeile blinkt, der Zähler steigt).
3. Dem Bit einen Namen geben, z. B. `7: Störlampe`. Loxone abonniert dann `hargassner_kessel_stoerlampe` (0/1).

## Weitere Doku

* [Prüfung der Gemini-Lösung und Umstieg von den alten Skripten](docs/pruefung-gemini.md)
* [Entwicklung und Releases](docs/entwicklung.md)
