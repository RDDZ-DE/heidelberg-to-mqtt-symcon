# Heidelberg-to-MQTT Symcon-Modul

IP-Symcon-Modul für die [Heidelberg-to-MQTT](https://github.com/RDDZ-DE/heidelberg-to-mqtt)
Firmware (ESP32-Bridge für die Heidelberg Wallbox Energy Control). Bildet die
per MQTT veröffentlichten Daten als Symcon-Variablen ab und erlaubt die
Steuerung des Ladestroms.

## Enthaltene Module

- **HeidelbergToMQTTDiscovery** (Configurator): findet automatisch alle
  Wallboxen, die per MQTT ihre Info-Daten veröffentlicht haben, und bietet sie
  zum Anlegen als Geräte-Instanz an.
- **HeidelbergToMQTTDevice** (Device): eine Instanz pro Wallbox. Bildet die
  gewünschten Werte als Variablen ab (per Checkbox auswählbar) und kann den
  Ladestrom setzen.

## Voraussetzung

Ein bereits eingerichteter MQTT-Client/-Splitter in Symcon (z.B. "MQTT Client
Socket"), der mit deinem Broker verbunden ist - dieselbe Instanz, die du
vermutlich schon für andere MQTT-Geräte nutzt.

## Installation

**Über die Modules Control (empfohlen):**
1. In Symcon: Modules Control (Kern-Instanzen) → "+" → Git-URL dieses Repos
   eintragen (`https://github.com/RDDZ-DE/heidelberg-to-mqtt-symcon`)
2. Modul aktualisieren/laden lassen

**Alternativ lokal** (z.B. unter Proxmox, per Bind-Mount o.ä.):
```
cd /var/lib/symcon/modules
git clone https://github.com/RDDZ-DE/heidelberg-to-mqtt-symcon.git
```
Danach in Symcon: Modules Control → "Module neu laden".

## Einrichtung

1. **Discovery-Instanz anlegen:** Objektbaum → Instanz hinzufügen → "Heidelberg
   to MQTT Suche" suchen → anlegen → als Parent deinen bestehenden
   MQTT-Client/-Splitter auswählen.
2. Sobald deine Wallbox(en) mindestens einmal gebootet haben (und dabei die
   `info/hw_max_ampere`-Nachricht - retained - veröffentlicht haben), erscheinen
   sie in der Geräteliste der Discovery-Instanz (ggf. einmal die
   Konfigurationsseite neu öffnen).
3. Über die Geräteliste eine **HeidelbergToMQTTDevice**-Instanz je Wallbox
   anlegen - die Chip-ID wird automatisch übernommen.
4. Alternativ: Device-Instanz manuell anlegen und Chip-ID von Hand eintragen
   (z.B. `47f7a608`, siehe `heidelberg-to-mqtt/<chip-id>/...` in deinem
   MQTT-Broker).
5. In der Device-Instanz per Checkbox auswählen, welche Werte als Variablen
   angelegt werden sollen. Abgewählte, bereits angelegte Variablen werden
   beim Speichern automatisch wieder entfernt.
6. Optional: "Ladestrom-Vorgabe" aktivieren, um den Ladestrom direkt aus
   Symcon heraus zu setzen (0-16 A, publiziert an `.../set/strom`).

## Nach MQTT-Verbindungsproblemen

Falls die Werte nach einem Broker-Neustart nicht mehr aktualisiert werden:
Button "Erneut abonnieren" in der Device-Instanz nutzen (sendet die
MQTT-Subscription neu).

## Bekannte Einschränkungen (Stand aktuelle Version)

- Die Chip-ID muss (noch) von Hand oder über die Discovery-Liste übernommen
  werden - kein automatisches Update, falls sich die Chip-ID einer Wallbox
  ändert (z.B. nach Hardware-Tausch).
- Getestet gegen eine einzelne Wallbox-Installation - mehrere Wallboxen
  sollten über mehrere Device-Instanzen funktionieren, wurden aber noch nicht
  in der Praxis mit mehr als einem Gerät geprüft.
