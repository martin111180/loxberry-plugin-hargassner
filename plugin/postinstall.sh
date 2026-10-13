#!/bin/bash
# Läuft als Benutzer loxberry nach dem Kopieren der Dateien.
# Argumente: $1 Temp-Ordner, $2 Plugin-Name, $3 Plugin-Ordner, $4 Version, $5 LoxBerry-Basisordner
PFOLDER=$3
LBHOMEDIR=$5
PDATA=$LBHOMEDIR/data/plugins/$PFOLDER
PCONFIG=$LBHOMEDIR/config/plugins/$PFOLDER
PBIN=$LBHOMEDIR/bin/plugins/$PFOLDER

chmod +x "$PBIN"/*.sh "$PBIN"/hargassner_bridge.py

# Eigene Python-Umgebung mit exakt gepinnten Paketen (das System-Python bleibt unverändert;
# ab Debian 12 ist "pip install" ins System ohnehin gesperrt)
echo "<INFO> Erzeuge Python-Umgebung in $PDATA/venv ..."
if ! python3 -m venv "$PDATA/venv"; then
	echo "<FAIL> python3 -m venv fehlgeschlagen (Paket python3-venv fehlt?)"
	exit 2
fi
echo "<INFO> Installiere paho-mqtt und pyserial ..."
if ! "$PDATA/venv/bin/python" -m pip install --disable-pip-version-check --upgrade -r "$PCONFIG/requirements.txt"; then
	echo "<FAIL> Installation der Python-Pakete fehlgeschlagen (Internetverbindung?)"
	exit 2
fi
echo "<OK> $("$PDATA/venv/bin/python" --version), $("$PDATA/venv/bin/python" -c 'import importlib.metadata as m; print("paho-mqtt", m.version("paho-mqtt"), "/ pyserial", m.version("pyserial"))')"

chmod 600 "$PCONFIG/hargassner.json"

"$PBIN/service.sh" restart
exit 0
