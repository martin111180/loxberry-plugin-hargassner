import serial
import json
import os
import time
import sys

# --- KONFIGURATION ---
SERIAL_PORT = '/dev/ttyUSB0'
BAUD_RATE = 19200
MAPPING_FILE = '/opt/loxberry/bin/hargassner_mapping.json'

def load_mapping():
    if os.path.exists(MAPPING_FILE):
        try:
            with open(MAPPING_FILE, 'r') as f:
                return json.load(f)
        except: return {}
    return {}

def save_mapping(mapping):
    with open(MAPPING_FILE, 'w') as f:
        json.dump(mapping, f, indent=4)

def print_ui(current_values, mapping, last_update):
    os.system('clear')
    age = round(time.time() - last_update, 1)
    print(f"HARGASSNER MONITOR | Update vor: {age}s | Beenden/Benennen: STRG+C drücken")
    # Header verbreitert auf ca. 130 Zeichen
    print("-" * 130)
    
    num_items = len(current_values)
    rows_per_col = (num_items + 2) // 3 
    
    for r in range(rows_per_col):
        line = ""
        for c in range(3):
            idx = r + (c * rows_per_col)
            if idx < num_items:
                idx_str = str(idx)
                val = str(current_values[idx])[:12] # Wert auf 12 Zeichen begrenzen
                name = mapping.get(idx_str, "")[:15]
                
                color = "\033[92m" if name else "\033[91m"
                reset = "\033[0m"
                
                # Formatierung angepasst: Index(3), Wert(12), Name(15)
                entry = f"{color}{idx:>2}{reset} {val:>12} {name:<15}"
                line += f"{entry} | "
        print(line)
    print("-" * 130)

def main():
    mapping = load_mapping()
    current_data = []
    last_update = time.time()
    
    try:
        ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=0.1)
        ser.flushInput()
        buffer = ""
        
        while True:
            if ser.in_waiting > 0:
                chunk = ser.read(ser.in_waiting).decode('ascii', errors='ignore')
                buffer += chunk
                
                if "pm" in buffer:
                    lines = buffer.split('pm')
                    if len(lines) > 1:
                        raw_content = lines[-2].strip()
                        if raw_content:
                            parts = raw_content.split()
                            current_data = ["pm"] + parts
                            last_update = time.time()
                            print_ui(current_data, mapping, last_update)
                        buffer = "pm" + lines[-1]
            time.sleep(0.05)

    except KeyboardInterrupt:
        print("\n" + "="*40)
        print("MENÜ: 'idx Name' (z.B. 3 Aussen) | 's' Speichern | 'q' Beenden")
        cmd = input("Eingabe: ").strip()
        
        if cmd.lower() == 'q':
            print("Programm beendet.")
            # Wir nutzen os._exit um den Prozess sofort zu killen
            os._exit(0)
        elif cmd.lower() == 's':
            save_mapping(mapping)
            print("Mapping gespeichert!")
            time.sleep(1)
            main()
        elif " " in cmd:
            try:
                idx_part, name_part = cmd.split(" ", 1)
                mapping[idx_part] = name_part
                save_mapping(mapping)
                main()
            except:
                main()
        else:
            main()
    finally:
        try:
            ser.close()
        except:
            pass

if __name__ == "__main__":
    main()
