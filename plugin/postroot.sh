#!/bin/bash
# Läuft als root nach der Installation.
# Für den direkten Anschluss per USB-Adapter braucht der Benutzer loxberry Zugriff auf /dev/ttyUSB*.
if getent group dialout >/dev/null && ! id -nG loxberry | grep -qw dialout; then
	usermod -a -G dialout loxberry && echo "<OK> Benutzer loxberry zur Gruppe dialout hinzugefügt (serielle Schnittstellen)"
fi
exit 0
