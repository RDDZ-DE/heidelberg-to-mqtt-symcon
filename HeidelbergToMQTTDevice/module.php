<?php

declare(strict_types=1);

class HeidelbergToMQTTDevice extends IPSModule
{
    // GUID, die unser MQTT-Splitter-Parent als "Implemented" anbietet (Nachrichten VOM Splitter AN uns)
    private const MQTT_RX_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    // GUID, die der Splitter als "Implemented" erwartet (Nachrichten VON uns AN den Splitter)
    private const MQTT_TX_GUID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    // Feld-Definition: Property-Suffix => [Ident, Name, VariablenTyp, MQTT-Subtopic, Werttyp, Profil]
    // VariablenTyp: 0=Boolean, 1=Integer, 2=Float, 3=String (Symcon-Standardkonstanten)
    private const FELDER = [
        'LayoutVersion'    => ['LayoutVersion',    'Modbus Layout-Version',   1, 'info/layout_version',          'int',    ''],
        'HardwareVariante' => ['HardwareVariante',  'Hardware-Variante',      1, 'info/hardware_variante',       'int',    ''],
        'SoftwareRevision' => ['SoftwareRevision',  'Software-Revision',      1, 'info/software_revision',       'int',    ''],
        'HwMinAmpere'      => ['HwMinAmpere',       'Hardware Min. Ladestrom',1, 'info/hw_min_ampere',           'int',    'HTMQ.AmpereInt'],
        'HwMaxAmpere'      => ['HwMaxAmpere',       'Hardware Max. Ladestrom',1, 'info/hw_max_ampere',           'int',    'HTMQ.AmpereInt'],

        'Ladezustand'      => ['Ladezustand',       'Ladezustand (Rohwert)',  1, 'status/ladezustand',           'int',    ''],
        'StromL1'          => ['StromL1',           'Ladestrom L1',           2, 'status/strom_l1',              'float',  'HTMQ.Ampere'],
        'StromL2'          => ['StromL2',           'Ladestrom L2',           2, 'status/strom_l2',              'float',  'HTMQ.Ampere'],
        'StromL3'          => ['StromL3',           'Ladestrom L3',           2, 'status/strom_l3',              'float',  'HTMQ.Ampere'],
        'Temperatur'       => ['Temperatur',        'Temperatur',             2, 'status/temperatur',            'float',  'HTMQ.Celsius'],
        'SpannungL1'       => ['SpannungL1',        'Spannung L1',            1, 'status/spannung_l1',           'int',    'HTMQ.Volt'],
        'SpannungL2'       => ['SpannungL2',        'Spannung L2',            1, 'status/spannung_l2',           'int',    'HTMQ.Volt'],
        'SpannungL3'       => ['SpannungL3',        'Spannung L3',            1, 'status/spannung_l3',           'int',    'HTMQ.Volt'],
        'LeistungVA'       => ['LeistungVA',        'Leistung',               1, 'status/leistung_va',           'int',    'HTMQ.VA'],
        'EnergieGesamt'    => ['EnergieGesamt',     'Energie gesamt',         1, 'status/energie_gesamt_wh',     'int',    'HTMQ.Wh'],
        'ExternGesperrt'   => ['ExternGesperrt',    'Extern gesperrt',        0, 'status/extern_gesperrt',       'bool',   '~Switch'],
        'ModbusFehler'     => ['ModbusFehler',      'Modbus-Fehlerzähler',    1, 'status/modbus_fehler',         'int',    ''],

        'Ssid'             => ['Ssid',              'WLAN SSID',              3, 'wifi/ssid',                   'string', ''],
        'Bssid'            => ['Bssid',             'WLAN Access Point',      3, 'wifi/bssid',                  'string', ''],
        'RssiDbm'          => ['RssiDbm',           'WLAN Signal',            1, 'wifi/rssi_dbm',               'int',    'HTMQ.Dbm'],
        'RssiProzent'      => ['RssiProzent',       'WLAN Signal',            1, 'wifi/rssi_prozent',           'int',    'HTMQ.Prozent'],
        'Ip'               => ['Ip',                'IP-Adresse',             3, 'wifi/ip',                     'string', ''],
        'UptimeS'          => ['UptimeS',           'Laufzeit seit Boot',     1, 'wifi/uptime_s',               'int',    'HTMQ.Sekunden'],
        'FreierSpeicher'   => ['FreierSpeicher',    'Freier Speicher',        1, 'wifi/freier_speicher_bytes',  'int',    'HTMQ.Bytes'],
    ];

    // Eigene Variablenprofile: Name => [VariablenTyp, Suffix]. Bewusst KEINE eingebauten
    // Symcon-Profile wie ~Watt/~Ampere verwendet - die haben teils andere Typen als erwartet
    // (z.B. ~Ampere ist Float) und führen bei falscher Zuordnung zu Laufzeitfehlern.
    private const PROFILE = [
        'HTMQ.Ampere'    => [2, ' A'],
        'HTMQ.AmpereInt' => [1, ' A'],
        'HTMQ.Volt'      => [1, ' V'],
        'HTMQ.VA'        => [1, ' VA'],
        'HTMQ.Wh'        => [1, ' Wh'],
        'HTMQ.Celsius'   => [2, ' °C'],
        'HTMQ.Dbm'       => [1, ' dBm'],
        'HTMQ.Prozent'   => [1, ' %'],
        'HTMQ.Bytes'     => [1, ' Bytes'],
        'HTMQ.Sekunden'  => [1, ' s'],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ChipID', '');

        foreach (self::FELDER as $key => $info) {
            // Alles außer den Fehlerzähler/Diagnosewerten standardmäßig aktiv, damit man nach dem
            // ersten Anlegen direkt sinnvolle Werte sieht - abwählen kann man jederzeit über das Formular.
            $this->RegisterPropertyBoolean('Import_' . $key, true);
        }
        $this->RegisterPropertyBoolean('Import_LadezustandText', true);

        // Prüft den Parent-Status regelmäßig selbst nach, statt sich allein auf IOChangeState zu
        // verlassen - falls der MQTT-Client schon vor uns aktiv war, feuert IOChangeState nämlich
        // nie, weil sich für Symcon in dem Fall gar nichts "ändert".
        $this->RegisterTimer('StatusPruefen', 30000, 'HTMQ_PruefeVerbindung($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $chipId = $this->ReadPropertyString('ChipID');
        if ($chipId === '') {
            $this->SetStatus(201);
            return;
        }

        foreach (self::PROFILE as $profilName => $profilInfo) {
            [$profilTyp, $suffix] = $profilInfo;
            $this->SicherstelleProfil($profilName, $profilTyp, $suffix);
        }

        foreach (self::FELDER as $key => $info) {
            [$ident, $name, $varType, , , $profil] = $info;
            $aktiv = $this->ReadPropertyBoolean('Import_' . $key);
            $this->MaintainVariable($ident, $name, $varType, $profil, 0, $aktiv);
        }

        // Schreibbare Steuervariable: immer angelegt, da sie den eigentlichen Zweck des Moduls ausmacht
        $this->MaintainVariable('LadestromSoll', 'Ladestrom-Vorgabe', 1, 'HTMQ.AmpereInt', 100, true);
        $this->EnableAction('LadestromSoll');

        // Lesbare Text-Variante des Ladezustands, aus dem Rohwert übersetzt
        $this->MaintainVariable('LadezustandText', 'Ladezustand', 3, '', 5, $this->ReadPropertyBoolean('Import_LadezustandText'));

        $this->PruefeVerbindung();
    }

    /**
     * Prüft den Parent-Verbindungsstatus und korrigiert unseren eigenen Status + abonniert bei
     * Bedarf neu. Wird sowohl von ApplyChanges als auch alle 30s per Timer aufgerufen, sowie von
     * IOChangeState bei einem echten Verbindungswechsel - dreifach abgesichert, damit der Status
     * nie dauerhaft falsch hängen bleibt.
     */
    public function PruefeVerbindung(): void
    {
        $chipId = $this->ReadPropertyString('ChipID');
        if ($chipId === '') {
            return; // Status 201 (keine Chip-ID) bleibt unverändert bestehen
        }

        if ($this->HasActiveParent()) {
            $warAktiv = ($this->GetStatus() == 102);
            $this->SetStatus(102);
            if (!$warAktiv) {
                $this->AbonniereNeu(); // nur bei echtem Wechsel neu abonnieren, nicht bei jedem Timer-Tick
            }
        } else {
            $this->SetStatus(202);
        }
    }

    /**
     * Wird von Symcon automatisch aufgerufen, sobald sich der Verbindungsstatus der
     * übergeordneten Instanz (MQTT-Client) ändert. Zusätzliche, sofortige Absicherung neben
     * dem 30s-Timer.
     */
    public function IOChangeState($State)
    {
        $this->PruefeVerbindung();
    }

    /**
     * Abonniert alle Topics der konfigurierten Wallbox neu. Öffentlich aufrufbar (Button im
     * Formular, Prefix HTMQ), falls die MQTT-Verbindung zwischenzeitlich unterbrochen war und
     * die Wallbox seitdem keine neue Nachricht mehr gesendet hat.
     */
    public function AbonniereNeu(): void
    {
        $chipId = $this->ReadPropertyString('ChipID');
        if ($chipId === '' || !$this->HasActiveParent()) {
            return;
        }

        $this->SendDataToParent(json_encode([
            'DataID'           => self::MQTT_TX_GUID,
            'PacketType'       => 8, // SUBSCRIBE
            'QualityOfService' => 0,
            'Retain'           => false, // vom Interface für JEDES Paket verlangt, auch bei SUBSCRIBE ohne eigentliche Bedeutung
            'Payload'          => '',    // dito
            'Topic'            => 'heidelberg-to-mqtt/' . $chipId . '/#'
        ]));
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if (!isset($data->Topic) || !isset($data->Payload)) {
            return '';
        }

        $chipId = $this->ReadPropertyString('ChipID');
        $praefix = 'heidelberg-to-mqtt/' . $chipId . '/';
        if (strncmp($data->Topic, $praefix, strlen($praefix)) !== 0) {
            return ''; // Nachricht gehört zu einer anderen Wallbox/einem anderen Topic
        }
        $subtopic = substr($data->Topic, strlen($praefix));
        $payload = (string) $data->Payload;

        foreach (self::FELDER as $key => $info) {
            [$ident, $name, $varType, $mqttSubtopic, $werttyp] = $info;
            if ($mqttSubtopic !== $subtopic) {
                continue;
            }
            if ($this->ReadPropertyBoolean('Import_' . $key)) {
                $this->SetValue($ident, $this->WandleWert($payload, $werttyp));
            }

            // Zusätzlich: Ladezustand als Klartext, unabhängig vom Rohwert-Checkbox-Zustand
            if ($key === 'Ladezustand' && $this->ReadPropertyBoolean('Import_LadezustandText')) {
                $this->SetValue('LadezustandText', $this->LadezustandZuText((int) $payload));
            }
            return '';
        }

        return '';
    }

    private function LadezustandZuText(int $wert): string
    {
        // Rohwerte laut Heidelberg-Registerdokumentation (Register 5), IEC-61851-Ladezustände
        switch ($wert) {
            case 2:  return 'Nicht verbunden';
            case 3:  return 'Nicht verbunden';
            case 4:  return 'Verbunden, lädt nicht';
            case 5:  return 'Verbunden, lädt nicht';
            case 6:  return 'Verbunden, lädt';
            case 7:  return 'Verbunden, lädt';
            case 8:  return 'Lädt (reduziert)';
            case 9:  return 'Fehler';
            case 10: return 'Gesperrt';
            case 11: return 'Fehler';
            default: return 'Unbekannt (' . $wert . ')';
        }
    }

    private function SicherstelleProfil(string $name, int $typ, string $suffix): void
    {
        if (IPS_VariableProfileExists($name)) {
            $vorhandenerTyp = IPS_GetVariableProfile($name)['ProfileType'];
            if ($vorhandenerTyp !== $typ) {
                // Falscher Typ von früherer Version -> neu anlegen, sonst SetVariableProfileText-Fehler
                IPS_DeleteVariableProfile($name);
            }
        }
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $typ);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
    }

    private function WandleWert(string $payload, string $werttyp)
    {
        switch ($werttyp) {
            case 'bool':
                return ($payload === '1' || strtolower($payload) === 'true');
            case 'int':
                return (int) $payload;
            case 'float':
                return (float) $payload;
            default:
                return $payload;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'LadestromSoll') {
            $chipId = $this->ReadPropertyString('ChipID');
            if ($chipId === '' || !$this->HasActiveParent()) {
                return;
            }
            $this->SendDataToParent(json_encode([
                'DataID'           => self::MQTT_TX_GUID,
                'PacketType'       => 3, // PUBLISH
                'QualityOfService' => 0,
                'Retain'           => false,
                'Topic'            => 'heidelberg-to-mqtt/' . $chipId . '/set/strom',
                'Payload'          => (string) $Value
            ]));
            $this->SetValue($Ident, $Value); // optimistisch setzen, echte Bestätigung kommt über den Status-Ladezustand zurück
            return;
        }

        throw new Exception('Invalid Ident: ' . $Ident);
    }
}
