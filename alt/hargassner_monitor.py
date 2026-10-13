import serial
import os
import sys
import time
import math

SERIAL_PORT = '/dev/ttyUSB0'
BAUD_RATE = 19200

def print_display(parts):
    # Terminal löschen
    sys.stdout.write("\033[2J\033[H")

    print(f"--- Hargassner Live-Analyse | {time.strftime('%H:%M:%S')} | {len(parts)} Werte ---")
    print("-" * 85)

    num_parts = len(parts)
    # Wir berechnen die Zeilen für 3 Spalten
    rows = math.ceil(num_parts / 3)

    for r in range(rows):
        line = ""
        for c in range(3):
            index = r + (c * rows)
            if index < num_parts:
                val = str(parts[index])
                # Wir lassen jetzt 15 Zeichen Platz pro Wert (ideal für Datum/lange Zahlen)
                cell = f"{index:>2}: {val:<15}"
                line += f"{cell} | "
        print(line)

    print("-" * 85)
    print("Beenden mit STRG+C")

def main():
    try:
        ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=0.1)
        ser.flushInput()
        buffer = ""

        while True:
            waiting = ser.in_waiting
            if waiting > 0:
                chunk = ser.read(waiting).decode('ascii', errors='ignore')
                buffer += chunk

                if "pm" in buffer:
                    lines = buffer.split('pm')
                    if len(lines) > 1:
                        raw_content = lines[-2].strip()
                        if raw_content:
                            parts = ["pm"] + raw_content.split()
                            if len(parts) > 10:
                                print_display(parts)
                                buffer = "pm" + lines[-1]

            time.sleep(0.1)

    except KeyboardInterrupt:
        print("\nAnalyse beendet.")
    except Exception as e:
        print(f"\nFehler: {e}")
    finally:
        if 'ser' in locals():
            ser.close()

if __name__ == "__main__":
    main()
