#!/bin/bash
# Läuft als loxberry vor einem Update: Bridge stoppen und Konfiguration sichern.
PFOLDER=$3
LBHOMEDIR=$5
BACKUP=/tmp/${1##*/}_upgrade

"$LBHOMEDIR/bin/plugins/$PFOLDER/service.sh" stop

mkdir -p "$BACKUP/config"
cp -p -v "$LBHOMEDIR/config/plugins/$PFOLDER/hargassner.json" "$BACKUP/config/" 2>/dev/null
echo "<OK> Konfiguration gesichert"
exit 0
