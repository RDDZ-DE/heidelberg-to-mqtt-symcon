<?php

declare(strict_types=1);

class HeidelbergToMQTTDiscovery extends IPSModule
{
    // GUID, die unser MQTT-Splitter-Parent als "Implemented" anbietet (Nachrichten VOM Splitter AN uns)
    private const MQTT_RX_GUID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    // GUID, die der Splitter als "Implemented" erwartet (Nachrichten VON uns AN den Splitter, z.B. Subscribe)
    private const MQTT_TX_GUID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    // Schmales, garantiert einmalig+retained veröffentlichtes Topic zur Erkennung neuer Geräte.
    // Jede Heidelberg-to-MQTT-Firmware veröffentlicht das beim Boot unter ihrer eigenen Chip-ID.
    private const DISCOVERY_TOPIC_FILTER = 'heidelberg-to-mqtt/+/info/hw_max_ampere';

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('DiscoveredDevices', '[]');
        // Kein ConnectParent() mit geratener Modul-ID - der passende MQTT-Splitter/Client wird beim
        // ersten Anlegen der Instanz ganz normal im Objektbaum (Reiter "Anschluss") ausgewählt.

        // Prüft den Parent-Status regelmäßig selbst nach, statt sich allein auf IOChangeState zu
        // verlassen - falls der MQTT-Client schon vor uns aktiv war, feuert IOChangeState nämlich
        // nie, weil sich für Symcon in dem Fall gar nichts "ändert".
        $this->RegisterTimer('StatusPruefen', 30000, 'HTMD_PruefeVerbindung($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->PruefeVerbindung();
    }

    /**
     * Prüft den Parent-Verbindungsstatus und korrigiert unseren eigenen Status + abonniert bei
     * Bedarf neu. Wird von ApplyChanges, alle 30s per Timer, sowie von IOChangeState bei einem
     * echten Verbindungswechsel aufgerufen - dreifach abgesichert.
     */
    public function PruefeVerbindung(): void
    {
        if ($this->HasActiveParent()) {
            $warAktiv = ($this->GetStatus() == 102);
            $this->SetStatus(102);
            if (!$warAktiv) {
                $this->AbonniereDiscoveryTopic();
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

    private function AbonniereDiscoveryTopic(): void
    {
        if ($this->HasActiveParent()) {
            $this->SendDataToParent(json_encode([
                'DataID'           => self::MQTT_TX_GUID,
                'PacketType'       => 8, // SUBSCRIBE
                'QualityOfService' => 0,
                'Retain'           => false, // vom Interface für JEDES Paket verlangt, auch bei SUBSCRIBE
                'Payload'          => '',    // dito
                'Topic'            => self::DISCOVERY_TOPIC_FILTER
            ]));
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if (!isset($data->Topic)) {
            return '';
        }

        // Erwartetes Topic: heidelberg-to-mqtt/<chip-id>/info/hw_max_ampere
        $teile = explode('/', $data->Topic);
        if (count($teile) < 4 || $teile[0] !== 'heidelberg-to-mqtt') {
            return '';
        }
        $chipId = $teile[1];

        $bekannt = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        if (!is_array($bekannt)) {
            $bekannt = [];
        }
        if (!in_array($chipId, $bekannt, true)) {
            $bekannt[] = $chipId;
            $this->WriteAttributeString('DiscoveredDevices', json_encode($bekannt));
        }

        return '';
    }

    public function GetConfigurationForm()
    {
        $bekannt = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        if (!is_array($bekannt)) {
            $bekannt = [];
        }

        $values = [];
        foreach ($bekannt as $chipId) {
            $instanzId = $this->FindeInstanzId($chipId);

            $eintrag = [
                'name'   => 'Heidelberg Wallbox (' . $chipId . ')',
                'chipId' => $chipId
            ];

            if ($instanzId > 0) {
                // Instanz existiert schon -> Klick öffnet die bestehende Instanz
                $eintrag['instanceID'] = $instanzId;
            } else {
                // Noch keine Instanz -> Klick legt eine neue mit dieser Chip-ID an
                $eintrag['create'] = [
                    'moduleID'      => '{95DD1529-893B-43E6-89C6-A32B6A9F0E21}',
                    'configuration' => [
                        'ChipID' => $chipId
                    ]
                ];
            }

            $values[] = $eintrag;
        }

        return json_encode([
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Gefunden werden Geräte automatisch, sobald ihre Firmware mindestens einmal '
                        . 'gebootet und dabei per MQTT veröffentlicht hat. Liste erscheint erst nach einem Neustart '
                        . 'dieser Instanz oder einem Reconnect zum MQTT-Broker, falls die Wallbox schon vorher lief.'
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Configurator',
                    'name'    => 'devices',
                    'caption' => 'Gefundene Heidelberg-to-MQTT Wallboxen',
                    'rowCount' => 20,
                    'add'     => false,
                    'delete'  => false,
                    'sort'    => [
                        'column'    => 'name',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto'
                        ],
                        [
                            'caption' => 'Chip-ID',
                            'name'    => 'chipId',
                            'width'   => '150px'
                        ]
                    ],
                    'values' => $values
                ]
            ]
        ]);
    }

    private function FindeInstanzId(string $chipId): int
    {
        foreach (IPS_GetInstanceListByModuleID('{95DD1529-893B-43E6-89C6-A32B6A9F0E21}') as $instanzId) {
            if (@IPS_GetProperty($instanzId, 'ChipID') === $chipId) {
                return $instanzId;
            }
        }
        return 0;
    }
}
