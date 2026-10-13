# Datenprotokoll der Hargassner-Steuerung

## Quelle

„Serielle Datenübertragung für Produkt HAICO-HSV15“, Hainzl Industriesysteme GmbH & Co KG, Linz, 17.08.2007
(5 Seiten). Hainzl entwickelt und fertigt die Steuerungen für Hargassner. Das Dokument beschreibt den
**Pelletskessel HSV15** – nicht die Hackgut-Anlage, an der das Plugin entwickelt wurde. Es liegt nicht im
Repository; hier steht nur, was daraus für das Plugin wichtig ist.

## Was allgemein gilt (bestätigt durch die Anlage)

* 19200 Baud, 8 Datenbits, 1 Stoppbit, keine Parität, ASCII
* jede Sekunde ein Datensatz, beginnt mit `pm`, endet mit `\r`, Werte durch Leerzeichen getrennt
* die Messdaten stammen aus dem internen Busprotokoll; Hainzl nennt die zugehörige Prüfstands-Software „DAQ-Access“
  (daher der Name der DAQ-Dateien neuerer Steuerungen)
* **Digitalwerte sind hexadezimal** (Typ „Hex“ im Protokoll, Beispiel `0015` = Bits 0, 2, 4)

## Belegung HSV15 (Element 1 = erster Wert nach `pm`)

| Element | Bezeichnung | Einheit |
|---|---|---|
| 1 | Kesselzustand | – |
| 2 | Sauerstoff im Rauchgas (im Dokument „CO2“, Bereich 0–21) | % |
| 3 | Kesseltemperatur | °C |
| 4 | Rauchgastemperatur | °C |
| 5 | Außentemperatur aktuell | °C |
| 6 | Außentemperatur gemittelt | °C |
| 7 / 8 | Vorlauf HK1 / HK2 | °C |
| 9 / 10 | Vorlauf Soll HK1 / HK2 | °C |
| 11 | Boilertemperatur | °C |
| 12 | Puffertemperatur | °C |
| 13 | Brennstoffmenge | % |
| 14 | Kesselsolltemperatur | °C |
| 15 / 16 | Fernbedienung HK1 / HK2 | °C |
| 17 | Strom Einschubschnecke | mA |
| 18 | Strom Raumaustragung | A |
| 19 | Strom Schieberost | mA |
| 20 | Strom Putzeinrichtung | A |
| 21 | Luftmenge | % |
| 22–26 | Digitalwert 1–5 (hex) | |
| 27–29 | immer 0 | |

### Digitalwerte HSV15

| Bit | Digitalwert 1 | Digitalwert 2 | Digitalwert 3 | Digitalwert 4 | Digitalwert 5 |
|---|---|---|---|---|---|
| 0 | Mischer HK1 zu | Pufferladepumpe | Putzeinrichtung Freigabe | STB | Fernbedienung HK1 Uhr |
| 1 | Mischer HK1 auf | – | Putzeinrichtung ein | Füllstand | Fernbedienung HK1 Nacht |
| 2 | Mischer HK2 zu | Raumaustragung | Schieberost ein | Externe Heizkreisanforderung | Fernbedienung HK1 Tag |
| 3 | Mischer HK2 auf | Raumaustragung rück | Schieberost rück | – | Fernbedienung HK2 Uhr |
| 4 | Pumpe HK1 | Netzrelais | Zündung Gebläse | – | Fernbedienung HK2 Nacht |
| 5 | Pumpe HK2 | Zündung Heizung | Einschubschnecke rück | – | Fernbedienung HK2 Tag |
| 6 | Boilerpumpe | Pellets-Sauger | Einschubschnecke ein | – | – |
| 7 | **Störlampe** | Lambdaheizung | – | – | – |

## Vergleich mit der Hackgut-Anlage (I/O-Platine 30-100, Serie 06-10)

Die Anlage sendet **91 Werte** nach `pm` (HSV15: 29) – Aufbau und Reihenfolge sind also anders, das Dokument
ist nur ein Anhaltspunkt:

* Der Anfang ist gleich aufgebaut, aber um eine Stelle verschoben: die Anlage hat an Index 1 einen zusätzlichen
  Wert (0/100). Danach Kesselzustand (2), O2 (3), Kesseltemperatur (4), Rauchgas (5), Außen aktuell (6) und
  gemittelt (7) – genau wie HSV15 Element 1–6.
* Ab den Heizkreisen weicht die Reihenfolge ab (Anlage: 8/9 = HK1/HK2 Soll, 10/11 = HK1/HK2 Ist).
* Digitalwerte: Die Anlage sendet am Ende **sieben** Hex-Werte (Index 82–88), zum Teil mit mehr als 8 Bits
  (`df1`, `ffff`). HSV15 hat fünf 8-Bit-Werte. Die Bitbedeutungen oben sind deshalb **Kandidaten**: Welche Bits
  bei der Hackgut-Steuerung was bedeuten, muss in der Live-Ansicht bestätigt werden (z. B. Pumpe HK1 schalten
  und schauen, welches Bit umspringt).
