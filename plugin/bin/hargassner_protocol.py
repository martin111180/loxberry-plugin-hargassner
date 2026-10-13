"""Hargassner-Datenstrom zerlegen und in MQTT-Topics übersetzen.

Die Steuerung sendet etwa jede Sekunde einen ASCII-Datensatz, z. B.

    pm 100 1 7.1 68 202 0.9 ... 2129 9 ff1 5 ffff 0 0 tm 2026-01-14 09:38:36

Ein Datensatz reicht vom Token "pm" bis vor das nächste "pm". Die Werte werden ab 0 durchnummeriert
(0 = "pm"), genau wie in den bisherigen Skripten. Per MQTT gesendet werden nur Werte mit Namen.

Dieses Modul hat keine Abhängigkeiten und ist ohne Hardware testbar (tests/test_protocol.py).
"""

from __future__ import annotations

import re
from typing import Any, Dict, List, Optional

FRAME_START = "pm"
# Mehr Werte hat kein Hargassner-Datensatz – alles darüber ist Datenmüll (z. B. fehlendes "pm")
MAX_TOKENS = 400
# Bits 0–31 einer Bitmaske können benannt werden (Digitalwerte reichen bei Hargassner bis Bit 20 und mehr)
BIT_COUNT = 32

# Alles außer druckbaren ASCII-Zeichen trennt Werte (Leerzeichen, \r, \n, Tab, Nullbytes, Störungen)
_SEPARATOR = re.compile(r"[^\x21-\x7e]+")
_UMLAUTS = str.maketrans({"ä": "ae", "ö": "oe", "ü": "ue", "Ä": "ae", "Ö": "oe", "Ü": "ue", "ß": "ss"})

# "hex" = Bitmaske; Hargassner sendet Digitalwerte immer hexadezimal (z. B. ff1, ffff, 5c)
FIELD_TYPES = ("value", "hex")
BIT_BASE = {"hex": 16}
# Diese Topic-Namen erzeugt das Plugin selbst – Feldnamen dürfen nicht darauf abgebildet werden
RESERVED_SLUGS = ("bridge",)


class FrameSplitter:
    """Sammelt die Bytes der Schnittstelle und liefert vollständige Datensätze (Liste von Werten)."""

    def __init__(self) -> None:
        self.reset()

    def reset(self) -> None:
        self._rest = ""
        self._current: Optional[List[str]] = None  # None = noch kein "pm" gesehen
        self.overflows = 0

    def feed(self, data: bytes) -> List[List[str]]:
        text = self._rest + data.decode("ascii", errors="ignore")
        tokens = _SEPARATOR.split(text)
        # Das letzte Stück kann ein angeschnittener Wert sein -> bis zum nächsten Aufruf zurückhalten
        self._rest = tokens.pop()
        if len(self._rest) > 64:
            self._rest = ""
        frames: List[List[str]] = []
        for token in tokens:
            if not token:
                continue
            if token == FRAME_START:
                if self._current is not None:
                    frames.append(self._current)
                self._current = [token]
            elif self._current is not None:
                self._current.append(token)
                if len(self._current) > MAX_TOKENS:
                    # Startkennung ging verloren – verwerfen und auf das nächste "pm" warten
                    self._current = None
                    self.overflows += 1
        return frames


class FrameValidator:
    """Lässt nur Datensätze mit der richtigen Anzahl Werte durch.

    Geht ein "pm" verloren (Übertragungsfehler, Anschluss mitten im Datensatz), verschmelzen zwei
    Datensätze zu einem doppelt so langen – die Werte landen dann auf falschen Indizes (das war die
    Ursache der "idx_182"-Topics). Deshalb wird die Länge festgehalten:

    * expected > 0: genau diese Anzahl Werte
    * expected = 0 (automatisch): die Länge wird übernommen, sobald `lock_after` Datensätze in Folge
      gleich lang sind. Kommen danach `relock_after` Datensätze in Folge mit einer anderen, gleichen Länge
      (z. B. nach einem Firmware-Update der Heizung), wird die neue Länge übernommen.
    """

    def __init__(self, expected: int = 0, lock_after: int = 3, relock_after: int = 20, min_fields: int = 10) -> None:
        self.expected = max(0, int(expected))
        self.lock_after = lock_after
        self.relock_after = relock_after
        self.min_fields = min_fields
        self.length: Optional[int] = self.expected or None
        self._candidate = 0
        self._candidate_count = 0
        self.accepted = 0
        self.rejected = 0
        self.last_rejected_length = 0
        self.relocked_from: Optional[int] = None  # gesetzt, wenn sich die Länge zuletzt geändert hat

    def check(self, frame: List[str]) -> bool:
        n = len(frame)
        self.relocked_from = None
        if n >= self.min_fields:
            if self.length is not None and n == self.length:
                self._candidate_count = 0
                self.accepted += 1
                return True
            if not self.expected:
                if n == self._candidate:
                    self._candidate_count += 1
                else:
                    self._candidate, self._candidate_count = n, 1
                needed = self.lock_after if self.length is None else self.relock_after
                if self._candidate_count >= needed:
                    self.relocked_from = self.length
                    self.length = n
                    self._candidate_count = 0
                    self.accepted += 1
                    return True
                if self.length is None:
                    return False  # Länge wird noch ermittelt – kein Fehler
        self.rejected += 1
        self.last_rejected_length = n
        return False


def slug(name: str) -> str:
    """Feldname -> Topic-Teil ("Fremdwärme" -> "fremdwaerme"). Gleiche Regel wie slug() in index.php."""
    text = str(name).translate(_UMLAUTS).lower()
    return re.sub(r"[^a-z0-9]+", "_", text).strip("_")


def parse_int(raw: str, base: int) -> Optional[int]:
    try:
        return int(raw, base)
    except ValueError:
        return None


def normalize_fields(raw: Any) -> Dict[str, dict]:
    """Feldeinstellungen tolerant einlesen.

    Akzeptiert {"4": {"name": "Kesseltemp", "type": "value", "bits": {"7": "Störlampe"}}}, Listen statt
    Objekten (so schreibt PHP Arrays mit Schlüsseln 0, 1, 2 …) und das alte Format {"4": "Kesseltemp"}.
    """
    items = enumerate(raw) if isinstance(raw, list) else (raw.items() if isinstance(raw, dict) else ())
    fields: Dict[str, dict] = {}
    for key, value in items:
        if isinstance(value, str):
            value = {"name": value}
        if not isinstance(value, dict) or not str(key).isdigit():
            continue
        bits_raw = value.get("bits") or {}
        bit_items = enumerate(bits_raw) if isinstance(bits_raw, list) else (bits_raw.items() if isinstance(bits_raw, dict) else ())
        field_type = value.get("type", "value")
        if field_type == "dec":  # früherer Typ "Bitmaske dezimal" – Digitalwerte sind immer hex
            field_type = "hex"
        fields[str(key)] = {
            "name": str(value.get("name") or ""),
            "type": field_type if field_type in FIELD_TYPES else "value",
            "bits": {str(int(b)): str(n) for b, n in bit_items if n and str(b).isdigit()},
        }
    return fields


def _allowed(topic: str) -> bool:
    return bool(topic) and topic not in RESERVED_SLUGS


def build_topics(frame: List[str], fields: Dict[str, dict]) -> Dict[str, str]:
    """Datensatz -> {Unter-Topic: Payload}. Gesendet werden nur benannte Werte:

    * <name>       Rohwert unter dem vergebenen Namen, z. B. "kesseltemp"
    * <bitname>    0/1 für benannte Bits einer Bitmaske, z. B. "stoerlampe"
    """
    topics: Dict[str, str] = {}
    for key, field in fields.items():
        index = int(key)
        if index >= len(frame):
            continue
        raw = frame[index]
        name = slug(field.get("name", ""))
        if _allowed(name):
            topics[name] = raw

        base = BIT_BASE.get(field.get("type", "value"))
        number = parse_int(raw, base) if base else None
        if number is None:
            continue
        for bit, bit_label in (field.get("bits") or {}).items():
            bit_name = slug(bit_label)
            if _allowed(bit_name) and 0 <= int(bit) < BIT_COUNT:
                topics[bit_name] = "1" if number >> int(bit) & 1 else "0"
    return topics
