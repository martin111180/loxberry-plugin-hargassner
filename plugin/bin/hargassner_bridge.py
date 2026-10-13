#!/usr/bin/env python3
"""Hargassner -> MQTT Bridge für LoxBerry.

Liest den Datenstrom der Hargassner-Steuerung – direkt über die serielle Schnittstelle oder über einen
Seriell-Ethernet-Wandler (z. B. Waveshare RS232/485 TO ETH im Modus "TCP Server") – und veröffentlicht
die Werte per MQTT auf dem LoxBerry-Broker. Das MQTT Gateway des LoxBerry leitet sie an den Miniserver.

Topics (Basis-Topic standardmäßig "hargassner/kessel"):
    <base>/idx_<n>              Rohwert an Position n (idx_0 = "pm"), wie in den bisherigen Skripten
    <base>/idx_<n>/bit_<b>      0/1 – nur für Felder, die als Bitmaske (hex/dezimal) eingestellt sind
    <base>/<name>               Wert unter dem in der Weboberfläche vergebenen Namen
    <base>/<bitname>            0/1 für benannte Bits (z. B. "stoerlampe")
    <base>/bridge/status        online / offline (Last Will)
    <base>/bridge/connected     1 = Daten von der Heizung kommen an
    <base>/bridge/error         Fehlertext, "OK" wenn alles in Ordnung

Gesendet wird nur, wenn sich ein Wert ändert (retained); alle `republish_seconds` wird alles erneut gesendet.
"""

from __future__ import annotations

import argparse
import json
import logging
from logging.handlers import RotatingFileHandler
import os
import signal
import socket
import sys
import threading
import time
from typing import Any, Dict, List, Optional, Set

import paho.mqtt.client as mqtt
import serial

from hargassner_protocol import FrameSplitter, FrameValidator, build_topics, normalize_fields

PLUGIN = "hargassner"
LBHOMEDIR = os.environ.get("LBHOMEDIR", "REPLACELBHOMEDIR")  # Platzhalter, ersetzt der LoxBerry bei der Installation
# Live-Werte für die Weboberfläche: im RAM (tmpfs), damit die SD-Karte nicht jede Sekunde beschrieben wird.
# Gleicher Pfad wie $hg_statusfile in index.php.
STATUS_FILE = "/dev/shm/loxberry-plugin-hargassner/status.json"
STATUS_INTERVAL = 2.0
RETAINED_SCAN_SECONDS = 5.0

DEFAULT_CONFIG: Dict[str, Any] = {
    "enabled": True,
    "source": "serial",
    "serial": {"device": "/dev/ttyUSB0", "baudrate": 19200},
    "tcp": {"host": "", "port": 4196},
    "topic": "hargassner/kessel",
    "publish_index_topics": True,
    "publish_named_topics": True,
    "republish_seconds": 300,
    "data_timeout_seconds": 30,
    "expected_fields": 0,
    "cleanup_retained": True,
    "loglevel": "INFO",
    "mqtt": {"use_loxberry": True, "host": "localhost", "port": 1883, "user": "", "password": ""},
    "fields": {},
}

log = logging.getLogger("hargassner_bridge")


# ----------------------------------------------------------------------------
# Konfiguration
# ----------------------------------------------------------------------------


def deep_merge(base: dict, override: dict) -> dict:
    result = dict(base)
    for key, value in (override or {}).items():
        if isinstance(value, dict) and isinstance(result.get(key), dict) and key != "fields":
            result[key] = deep_merge(result[key], value)
        else:
            result[key] = value
    return result


def load_config(path: str) -> dict:
    try:
        with open(path, encoding="utf-8") as fh:
            return deep_merge(DEFAULT_CONFIG, json.load(fh))
    except FileNotFoundError:
        log.warning("Konfiguration %s nicht gefunden – verwende Standardwerte", path)
        return dict(DEFAULT_CONFIG)


def loxberry_mqtt_settings() -> dict:
    """Broker-Zugangsdaten des LoxBerry (ab LB 3.0 in general.json, sonst MQTT-Gateway-Plugin)."""
    try:
        with open(f"{LBHOMEDIR}/config/system/general.json", encoding="utf-8") as fh:
            m = json.load(fh).get("Mqtt") or {}
        if m.get("Brokerhost"):
            return {
                "host": m.get("Brokerhost", "localhost"),
                "port": int(m.get("Brokerport") or 1883),
                "user": m.get("Brokeruser", ""),
                "password": m.get("Brokerpass", ""),
            }
    except (OSError, ValueError):
        pass
    try:
        gw_dir = f"{LBHOMEDIR}/config/plugins/mqttgateway"
        with open(f"{gw_dir}/mqtt.json", encoding="utf-8") as fh:
            addr = json.load(fh)["Main"]["brokeraddress"]
        with open(f"{gw_dir}/cred.json", encoding="utf-8") as fh:
            cred = json.load(fh)["Credentials"]
        host, _, port = addr.partition(":")
        return {
            "host": host or "localhost",
            "port": int(port or 1883),
            "user": cred.get("brokeruser", ""),
            "password": cred.get("brokerpass", ""),
        }
    except (OSError, ValueError, KeyError):
        pass
    log.warning("Keine LoxBerry-MQTT-Zugangsdaten gefunden – verwende localhost:1883 ohne Login")
    return {"host": "localhost", "port": 1883, "user": "", "password": ""}


# ----------------------------------------------------------------------------
# Datenquellen
# ----------------------------------------------------------------------------


class SerialSource:
    """USB-/RS232-Adapter direkt am LoxBerry."""

    def __init__(self, device: str, baudrate: int) -> None:
        self.device = device
        self.baudrate = baudrate
        self._port: Optional[serial.Serial] = None

    def describe(self) -> str:
        return f"seriell {self.device} @ {self.baudrate} Baud"

    @property
    def is_open(self) -> bool:
        return self._port is not None

    def open(self) -> None:
        # exclusive=True: ein zweites Programm am selben Port (z. B. das alte hargassner_mqtt.py)
        # bekommt "Device or resource busy", statt dass sich beide die Bytes gegenseitig wegnehmen.
        self._port = serial.Serial(self.device, self.baudrate, timeout=1, exclusive=True)
        self._port.reset_input_buffer()

    def read(self) -> bytes:
        assert self._port is not None
        # wartet höchstens 1 s auf das erste Byte, liefert dann alles Vorhandene
        return self._port.read(self._port.in_waiting or 1)

    def close(self) -> None:
        if self._port is not None:
            try:
                self._port.close()
            except Exception:  # noqa: BLE001
                pass
        self._port = None


class TcpSource:
    """Seriell-Ethernet-Wandler im Modus "TCP Server" (Waveshare: Standard-Port 4196)."""

    def __init__(self, host: str, port: int) -> None:
        self.host = host
        self.port = port
        self._sock: Optional[socket.socket] = None

    def describe(self) -> str:
        return f"TCP {self.host}:{self.port}"

    @property
    def is_open(self) -> bool:
        return self._sock is not None

    def open(self) -> None:
        sock = socket.create_connection((self.host, self.port), timeout=10)
        sock.settimeout(1.0)
        # Abgerissene Verbindungen (Wandler stromlos, Kabel gezogen) nach ~1 min erkennen
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        for opt, value in (("TCP_KEEPIDLE", 30), ("TCP_KEEPINTVL", 10), ("TCP_KEEPCNT", 3)):
            if hasattr(socket, opt):
                sock.setsockopt(socket.IPPROTO_TCP, getattr(socket, opt), value)
        self._sock = sock

    def read(self) -> bytes:
        assert self._sock is not None
        try:
            data = self._sock.recv(4096)
        except socket.timeout:
            return b""
        if not data:
            raise ConnectionError("Verbindung vom Wandler geschlossen")
        return data

    def close(self) -> None:
        if self._sock is not None:
            try:
                self._sock.close()
            except OSError:
                pass
        self._sock = None


def make_source(cfg: dict):
    if cfg.get("source") == "tcp":
        host = (cfg["tcp"].get("host") or "").strip()
        if not host:
            raise ValueError("Keine IP-Adresse für den Seriell-Ethernet-Wandler eingetragen")
        return TcpSource(host, int(cfg["tcp"].get("port") or 4196))
    return SerialSource(cfg["serial"].get("device") or "/dev/ttyUSB0", int(cfg["serial"].get("baudrate") or 19200))


# ----------------------------------------------------------------------------
# MQTT
# ----------------------------------------------------------------------------


class MqttPublisher:
    def __init__(self, cfg: dict, base: str) -> None:
        self.base = base
        self._cache: Dict[str, str] = {}
        self._lock = threading.Lock()
        self._scan: Optional[Set[str]] = None
        self.connected = False
        settings = loxberry_mqtt_settings() if cfg["mqtt"].get("use_loxberry", True) else cfg["mqtt"]
        self.host = settings.get("host") or "localhost"
        self.port = int(settings.get("port") or 1883)
        self.client = mqtt.Client(
            mqtt.CallbackAPIVersion.VERSION2,
            client_id=f"loxberry-hargassner-{socket.gethostname()}",
        )
        if settings.get("user"):
            self.client.username_pw_set(settings["user"], settings.get("password") or None)
        self.client.will_set(f"{base}/bridge/status", "offline", qos=1, retain=True)
        self.client.on_connect = self._on_connect
        self.client.on_disconnect = self._on_disconnect
        self.client.on_message = self._on_message
        self.client.reconnect_delay_set(min_delay=2, max_delay=60)

    def start(self) -> None:
        log.info("Verbinde mit MQTT-Broker %s:%s", self.host, self.port)
        self.client.connect_async(self.host, self.port, keepalive=60)
        self.client.loop_start()

    def stop(self) -> None:
        try:
            self.client.publish(f"{self.base}/bridge/status", "offline", qos=1, retain=True).wait_for_publish(3)
        except Exception:  # noqa: BLE001
            pass
        self.client.disconnect()
        self.client.loop_stop()

    def _on_connect(self, client, userdata, flags, reason_code, properties) -> None:
        if reason_code.is_failure:
            log.error("MQTT-Verbindung abgelehnt: %s", reason_code)
            return
        log.info("MQTT verbunden")
        self.connected = True
        client.publish(f"{self.base}/bridge/status", "online", qos=1, retain=True)
        # Während der Trennung wurde nichts gesendet -> aktuellen Stand komplett nachliefern
        self.republish_all()

    def _on_disconnect(self, client, userdata, flags, reason_code, properties) -> None:
        self.connected = False
        if reason_code != 0:
            log.warning("MQTT-Verbindung getrennt (%s) – versuche Reconnect", reason_code)

    def _on_message(self, client, userdata, msg) -> None:
        # Nur während des Aufräum-Scans abonniert: gespeicherte (retained) Topics einsammeln
        with self._lock:
            if self._scan is not None and msg.retain and msg.payload:
                self._scan.add(msg.topic)

    def publish(self, sub_topic: str, payload: str) -> None:
        topic = f"{self.base}/{sub_topic}"
        with self._lock:
            if self._cache.get(topic) == payload:
                return
            self._cache[topic] = payload
        if self.connected:
            self.client.publish(topic, payload, qos=0, retain=True)

    def republish_all(self) -> None:
        with self._lock:
            items = list(self._cache.items())
        for topic, payload in items:
            self.client.publish(topic, payload, qos=0, retain=True)

    # --- Verwaiste Topics löschen (z. B. idx_182 aus der alten Skript-Version oder umbenannte Felder)

    def begin_retained_scan(self) -> None:
        with self._lock:
            self._scan = set()
        self.client.subscribe(f"{self.base}/#")

    def finish_retained_scan(self) -> List[str]:
        self.client.unsubscribe(f"{self.base}/#")
        with self._lock:
            found, self._scan = self._scan or set(), None
            current = set(self._cache)
        stale = sorted(t for t in found - current if not t.startswith(f"{self.base}/bridge/"))
        for topic in stale:
            self.client.publish(topic, b"", qos=1, retain=True)  # leere retained Nachricht = löschen
        return stale


# ----------------------------------------------------------------------------
# Bridge
# ----------------------------------------------------------------------------


class Bridge:
    def __init__(self, cfg: dict, status_file: str) -> None:
        self.cfg = cfg
        self.status_file = status_file
        self.base = cfg["topic"].strip("/") or "hargassner/kessel"
        self.fields = normalize_fields(cfg.get("fields"))
        self.timeout = max(5, int(cfg.get("data_timeout_seconds") or 30))
        self.republish = int(cfg.get("republish_seconds") or 0)
        self.stop_event = threading.Event()
        self.mqtt = MqttPublisher(cfg, self.base)
        self.splitter = FrameSplitter()
        self.validator = FrameValidator(expected=int(cfg.get("expected_fields") or 0))
        self.source = None
        self.source_text = ""
        self.error = ""
        self.connected = False
        self.started = time.time()
        self.opened_at = 0.0
        self.last_frame_time = 0.0
        self.values: List[str] = []
        self.scan_state = "pending" if cfg.get("cleanup_retained", True) else "off"
        self.scan_started = 0.0

    # --- Zustand

    def set_connected(self, value: bool) -> None:
        if value != self.connected:
            self.connected = value
            self.mqtt.publish("bridge/connected", "1" if value else "0")

    def set_error(self, text: str) -> None:
        if text != self.error:
            if text:
                log.warning("%s", text)
            elif self.error:
                log.info("Fehler behoben")
            self.error = text
            self.mqtt.publish("bridge/error", text or "OK")  # leere retained Nachricht würde das Topic löschen

    def write_status(self) -> None:
        status = {
            "updated": time.time(),
            "started": self.started,
            "source": self.source_text,
            "connected": self.connected,
            "mqtt_connected": self.mqtt.connected,
            "error": self.error,
            "last_frame": self.last_frame_time,
            "field_count": self.validator.length or 0,
            "frames_ok": self.validator.accepted,
            "frames_rejected": self.validator.rejected,
            "last_rejected_length": self.validator.last_rejected_length,
            "values": self.values,
        }
        try:
            os.makedirs(os.path.dirname(self.status_file), exist_ok=True)
            tmp = self.status_file + ".tmp"
            with open(tmp, "w", encoding="utf-8") as fh:
                json.dump(status, fh, ensure_ascii=False)
            os.replace(tmp, self.status_file)
        except OSError as exc:
            log.debug("Statusdatei nicht schreibbar: %s", exc)

    # --- Datensätze

    def handle_frame(self, frame: List[str]) -> None:
        rejected_before = self.validator.rejected
        if not self.validator.check(frame):
            if self.validator.rejected > rejected_before:
                log.debug("Datensatz mit %d statt %s Werten verworfen", len(frame), self.validator.length)
            return
        if self.validator.relocked_from is None and self.validator.accepted == 1:
            log.info("Datenstrom erkannt: %d Werte pro Datensatz", len(frame))
        elif self.validator.relocked_from is not None:
            log.warning("Anzahl der Werte hat sich von %d auf %d geändert (Firmware-Update?)",
                        self.validator.relocked_from, len(frame))
        self.last_frame_time = time.time()
        self.values = frame
        self.set_connected(True)
        self.set_error("")
        topics = build_topics(
            frame,
            self.fields,
            publish_index=self.cfg.get("publish_index_topics", True),
            publish_names=self.cfg.get("publish_named_topics", True),
        )
        for sub_topic, payload in topics.items():
            self.mqtt.publish(sub_topic, payload)

    def maintain_retained(self, now: float) -> None:
        if self.scan_state == "pending" and self.mqtt.connected and self.values:
            self.mqtt.begin_retained_scan()
            self.scan_state, self.scan_started = "running", now
        elif self.scan_state == "running" and now - self.scan_started >= RETAINED_SCAN_SECONDS:
            stale = self.mqtt.finish_retained_scan()
            self.scan_state = "done"
            if stale:
                log.info("%d verwaiste Topics gelöscht: %s", len(stale), ", ".join(stale[:20]) + (" …" if len(stale) > 20 else ""))

    # --- Hauptschleife

    def close_source(self) -> None:
        if self.source is not None:
            self.source.close()
        self.splitter.reset()

    def run(self) -> None:
        self.mqtt.start()
        self.mqtt.publish("bridge/connected", "0")
        self.mqtt.publish("bridge/error", "OK")
        try:
            self.source = make_source(self.cfg)
            self.source_text = self.source.describe()
        except ValueError as exc:
            self.set_error(str(exc))
        backoff = 2.0
        next_status = next_republish = 0.0
        while not self.stop_event.is_set():
            now = time.time()
            if now >= next_status:
                self.write_status()
                next_status = now + STATUS_INTERVAL
            if self.republish and now >= next_republish:
                if next_republish:
                    self.mqtt.republish_all()
                next_republish = now + self.republish
            self.maintain_retained(now)

            if self.source is None:
                self.stop_event.wait(STATUS_INTERVAL)
                continue

            if not self.source.is_open:
                try:
                    self.source.open()
                except (OSError, ValueError) as exc:
                    self.set_connected(False)
                    self.set_error(f"{self.source_text}: {exc}")
                    self.stop_event.wait(backoff)
                    backoff = min(60.0, backoff * 2)
                    continue
                # Bei Neuverbindung durch den Watchdog (Heizung sendet nicht) nicht jedes Mal ins Log schreiben
                log.log(logging.DEBUG if self.error else logging.INFO, "Verbunden: %s", self.source_text)
                backoff = 2.0
                self.opened_at = time.time()

            try:
                data = self.source.read()
            except (OSError, serial.SerialException) as exc:
                self.set_connected(False)
                self.set_error(f"{self.source_text}: {exc}")
                self.close_source()
                continue

            if data:
                for frame in self.splitter.feed(data):
                    self.handle_frame(frame)

            # Watchdog: keine gültigen Daten -> Verbindung neu aufbauen (hilft bei hängenden TCP-Verbindungen)
            if time.time() - max(self.last_frame_time, self.opened_at) > self.timeout:
                self.set_connected(False)
                self.set_error(f"Seit {self.timeout} s keine gültigen Daten von der Heizung ({self.source_text})")
                self.close_source()

        self.close_source()
        self.set_connected(False)
        self.write_status()
        self.mqtt.stop()


# ----------------------------------------------------------------------------
# Start
# ----------------------------------------------------------------------------


def setup_logging(logfile: str, level: str) -> None:
    os.makedirs(os.path.dirname(logfile), exist_ok=True)
    handler = RotatingFileHandler(logfile, maxBytes=1_000_000, backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(getattr(logging, str(level).upper(), logging.INFO))
    logging.getLogger("paho").setLevel(logging.WARNING)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--config", default=f"{LBHOMEDIR}/config/plugins/{PLUGIN}/hargassner.json")
    parser.add_argument("--log", default=f"{LBHOMEDIR}/log/plugins/{PLUGIN}/hargassner.log")
    parser.add_argument("--status", default=STATUS_FILE)
    args = parser.parse_args()

    cfg = load_config(args.config)
    setup_logging(args.log, cfg.get("loglevel", "INFO"))
    log.info("Hargassner-Bridge startet (Python %s)", sys.version.split()[0])

    bridge = Bridge(cfg, args.status)
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: bridge.stop_event.set())
    try:
        bridge.run()
    except Exception:  # noqa: BLE001
        log.exception("Unerwarteter Fehler – Bridge wird beendet (der Watchdog startet sie neu)")
        return 1
    log.info("Hargassner-Bridge beendet")
    return 0


if __name__ == "__main__":
    sys.exit(main())
