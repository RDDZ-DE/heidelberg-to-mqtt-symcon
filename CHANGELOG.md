# Changelog

## [1.0.0] - 2026-08-01

### Hinzugefügt
- HeidelbergToMQTTDiscovery: automatisches Erkennen von Wallboxen per MQTT
  (retained `info/hw_max_ampere`-Topic), Anlegen von Geräte-Instanzen per Klick.
- HeidelbergToMQTTDevice: per Checkbox auswählbare Variablen für Info-,
  Status- und WLAN-Diagnosedaten; schreibbare Ladestrom-Vorgabe (optional).
