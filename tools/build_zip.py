#!/usr/bin/env python3
"""Baut das installierbare LoxBerry-Plugin-ZIP aus dem Ordner plugin/.

Verwendung:  python tools/build_zip.py            -> dist/loxberry-plugin-hargassner-<version>.zip
             python tools/build_zip.py --version  -> gibt nur die Version aus plugin.cfg aus

Skripte erhalten im ZIP Unix-Ausführungsrechte (wichtig, wenn unter Windows gebaut wird).
"""

from __future__ import annotations

import configparser
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "plugin"
DIST = ROOT / "dist"
EXECUTABLE = {
    "postinstall.sh",
    "preupgrade.sh",
    "postupgrade.sh",
    "bin/service.sh",
    "bin/hargassner_bridge.py",
    "postroot.sh",
    "daemon/daemon",
    "cron/cron.05min",
    "uninstall/uninstall",
}


def plugin_version() -> str:
    cfg = configparser.ConfigParser(interpolation=None)
    cfg.read(SRC / "plugin.cfg", encoding="utf-8")
    return cfg["PLUGIN"]["VERSION"].strip().strip("'\"")


def build() -> Path:
    version = plugin_version()
    DIST.mkdir(exist_ok=True)
    out = DIST / f"loxberry-plugin-hargassner-{version}.zip"
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(p for p in SRC.rglob("*") if p.is_file()):
            rel = path.relative_to(SRC).as_posix()
            if "__pycache__" in rel:
                continue
            data = path.read_bytes()
            if b"\r\n" in data and path.suffix != ".png":
                sys.exit(f"FEHLER: {rel} hat Windows-Zeilenenden (CRLF) – LoxBerry braucht LF")
            info = zipfile.ZipInfo(f"hargassner/{rel}", date_time=(2026, 1, 1, 0, 0, 0))
            info.create_system = 3  # Unix
            info.external_attr = (0o100755 if rel in EXECUTABLE else 0o100644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zf.writestr(info, data)
    return out


if __name__ == "__main__":
    if "--version" in sys.argv:
        print(plugin_version())
    else:
        print(build())
