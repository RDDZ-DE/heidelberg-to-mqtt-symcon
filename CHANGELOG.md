# Changelog

## [1.0.1] - 2026-08-01

### Behoben
- MQTT-Pakete (SUBSCRIBE) fehlten die vom Symcon-Interface zwingend
  geforderten Felder `Retain` und `Payload` - führte zu "Cannot find
  required field ..."-Fehlern beim (Neu-)Abonnieren.
- Status blieb nach dem Anlegen einer Instanz teils dauerhaft auf "Kein
  MQTT-Parent verbunden" hängen, obwohl die Verbindung tatsächlich stand -
  betraf sowohl Device- als auch Discovery-Modul. Grund: der Status wurde
  nur einmalig beim Speichern geprüft; war der MQTT-Client zu dem Zeitpunkt
  noch nicht ganz bereit (oder schon vorher aktiv, sodass gar kein
  Wechsel-Ereignis stattfand), blieb die Anzeige falsch. Behoben durch
  einen 30-Sekunden-Timer, der die Verbindung laufend selbst nachprüft,
  zusätzlich zum `IOChangeState`-Hook für sofortige Reaktion bei echten
  Verbindungswechseln.

### Geändert
- Ladestrom-Vorgabe (Steuerung) wird jetzt immer angelegt, nicht mehr per
  Checkbox optional - sie ist der eigentliche Zweck des Moduls.

### Hinzugefügt
- Neue Variable "Ladezustand" (Klartext, z.B. "Verbunden, lädt") zusätzlich
  zum bisherigen Rohwert - übersetzt die Werte 2-11 aus Register 5 in
  lesbaren Text.

## [1.0.0] - 2026-08-01

### Hinzugefügt
- HeidelbergToMQTTDiscovery: automatisches Erkennen von Wallboxen per MQTT
  (retained `info/hw_max_ampere`-Topic), Anlegen von Geräte-Instanzen per Klick.
- HeidelbergToMQTTDevice: per Checkbox auswählbare Variablen für Info-,
  Status- und WLAN-Diagnosedaten; schreibbare Ladestrom-Vorgabe (optional).
