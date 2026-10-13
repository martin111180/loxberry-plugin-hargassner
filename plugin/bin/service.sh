#!/bin/bash
# Steuerung der Hargassner-Bridge: start | stop | restart | status | watchdog
PLUGIN=hargassner
LBHOMEDIR=${LBHOMEDIR:-REPLACELBHOMEDIR}

# Nie als root laufen lassen
if [ "$(id -u)" = "0" ]; then
	exec su loxberry -s /bin/bash -c "LBHOMEDIR=$LBHOMEDIR $0 $*"
fi

BIN=$LBHOMEDIR/bin/plugins/$PLUGIN
DATA=$LBHOMEDIR/data/plugins/$PLUGIN
CFG=$LBHOMEDIR/config/plugins/$PLUGIN/hargassner.json
LOGDIR=$LBHOMEDIR/log/plugins/$PLUGIN
PY=$DATA/venv/bin/python
PIDFILE=$DATA/bridge.pid

mkdir -p "$LOGDIR" "$DATA"

is_running() {
	[ -f "$PIDFILE" ] || return 1
	local pid
	pid=$(cat "$PIDFILE" 2>/dev/null)
	[ -n "$pid" ] && kill -0 "$pid" 2>/dev/null && grep -q hargassner_bridge "/proc/$pid/cmdline" 2>/dev/null
}

is_enabled() {
	[ -x "$PY" ] || return 1
	[ "$("$PY" -c 'import json,sys; print(1 if json.load(open(sys.argv[1])).get("enabled", True) else 0)' "$CFG" 2>/dev/null)" = "1" ]
}

start() {
	if is_running; then
		echo "Läuft bereits (PID $(cat "$PIDFILE"))"
		return 0
	fi
	if [ ! -x "$PY" ]; then
		echo "Python-Umgebung fehlt ($PY) – Plugin bitte neu installieren"
		return 1
	fi
	if ! is_enabled; then
		echo "Bridge ist in der Konfiguration deaktiviert"
		return 0
	fi
	cd "$DATA" || return 1
	LBHOMEDIR=$LBHOMEDIR nohup "$PY" -u "$BIN/hargassner_bridge.py" </dev/null >>"$LOGDIR/stdout.log" 2>&1 &
	echo $! >"$PIDFILE"
	sleep 1
	if is_running; then
		echo "Gestartet (PID $(cat "$PIDFILE"))"
	else
		echo "Start fehlgeschlagen – siehe $LOGDIR/stdout.log"
		return 1
	fi
}

stop() {
	if ! is_running; then
		rm -f "$PIDFILE"
		echo "Läuft nicht"
		return 0
	fi
	local pid i
	pid=$(cat "$PIDFILE")
	kill "$pid" 2>/dev/null
	for i in $(seq 1 15); do
		kill -0 "$pid" 2>/dev/null || break
		sleep 1
	done
	kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null
	rm -f "$PIDFILE"
	echo "Gestoppt"
}

case "$1" in
	start) start ;;
	stop) stop ;;
	restart) stop; start ;;
	status)
		if is_running; then echo "running $(cat "$PIDFILE")"; else echo "stopped"; exit 3; fi
		;;
	watchdog)
		# Vom Cron aufgerufen: nur starten, wenn aktiviert und nicht laufend
		if is_enabled && ! is_running; then start >/dev/null; fi
		if ! is_enabled && is_running; then stop >/dev/null; fi
		;;
	*)
		echo "Verwendung: $0 {start|stop|restart|status|watchdog}"
		exit 1
		;;
esac
