import serial
import time
import paho.mqtt.client as mqtt

# --- KONFIGURATION ---
SERIAL_PORT = '/dev/ttyUSB0'
BAUD_RATE = 19200
MQTT_BROKER = "localhost"
MQTT_PORT = 1883
MQTT_USER = "loxberry"
MQTT_PASS = "***entfernt***"  # Passwort stand hier im Klartext – nicht mehr verwenden, Plugin nutzt die LoxBerry-Zugangsdaten
MQTT_TOPIC_PREFIX = "hargassner/kessel"

client = mqtt.Client()
client.username_pw_set(MQTT_USER, MQTT_PASS)
client.connect(MQTT_BROKER, MQTT_PORT, 60)
client.loop_start()

def main():
    try:
        ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=2)
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
                        # Das "magische" split() gegen doppelte Leerzeichen:
                        parts = raw_content.split() 
                        full_data = ["pm"] + parts
                        for i, value in enumerate(full_data):
                            client.publish(f"{MQTT_TOPIC_PREFIX}/idx_{i}", value, retain=True)
                        buffer = "pm" + lines[-1]
            time.sleep(0.1)
    except Exception as e:
        print(f"Fehler: {e}")
    finally:
        ser.close()

if __name__ == "__main__":
    main()
