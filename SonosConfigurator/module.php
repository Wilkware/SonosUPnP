<?php

declare(strict_types=1);

/** Generell funktions  */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\SonosUPnP\DebugHelper;
use Wilkware\SonosUPnP\FormHelper;
use Wilkware\SonosUPnP\VariableHelper;

/**
 * CLASS SonosConfigurator
 */
class SonosConfigurator extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormHelper;
    use VariableHelper;

    // -------------------------------------------------------------------------
    // GUIDs
    // -------------------------------------------------------------------------

    /** @var string Dataflow interface GUID (child -> Sonos Bridge, SendDataToParent/ForwardData) */
    private const SONOS_BRIDGE_GUID = '{48975ECF-9DF0-5CD7-F937-CEFCAFA4A4B9}';

    /** @var string ModulID (Sonos Bridge), required by RequireParent */
    private const SONOS_BRIDGE_MODULE_GUID = '{FB07CB8D-A0BD-45B9-C334-02FFCCFD148C}';

    /** @var string ModulID (Sonos Speaker) */
    private const SONOS_SPEAKER_GUID = '{EC026937-3197-3501-6A3E-7F9D8B6A3D55}';

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // From Symcon 8.2 on the parent is created automatically via parentRequirements
        if ((float) IPS_GetKernelVersion() < 8.2) {
            $this->RequireParent(self::SONOS_BRIDGE_MODULE_GUID);
        }
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     *
     * @return string Content of the configuration page.
     */
    public function GetConfigurationForm(): string
    {
        // Get Form
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Update Version in form
        $this->ModifyFormElement($form['actions'], 'Version', function (array &$element)
        {
            // Extract Version
            $ins = IPS_GetInstance($this->InstanceID);
            $mod = IPS_GetModule($ins['ModuleInfo']['ModuleID']);
            $lib = IPS_GetLibrary($mod['LibraryID']);

            $element['caption'] = sprintf('v%s.%d', $lib['Version'], $lib['Build']);
        });

        // Return if parent is not confiured
        if (!$this->HasActiveParent()) {
            return json_encode($form);
        }

        $values = [];
        foreach ($this->GetDiscoveredPlayers() as $player) {
            $values[] = [
                'name'       => $player['roomName'],
                'model'      => $player['modelName'],
                'gen'        => $player['modelGeneration'],
                'software'   => $player['softwareVersion'],
                'hardware'   => $player['hardwareVersion'],
                'serial'     => $player['serialNumber'],
                'mac'        => $player['macAddress'],
                'ip'         => $player['ip'],
                'instanceID' => $this->FindExistingSpeakerInstance($player['uuid']),
                'create'     => [
                    'moduleID'      => self::SONOS_SPEAKER_GUID,
                    'configuration' => [
                        'PlayerUUID' => $player['uuid'],
                        'RoomName'   => $player['roomName']
                    ]
                ]
            ];
        }

        // Update form with discovered players
        foreach ($form['actions'] as &$element) {
            if (($element['name'] ?? '') === 'Players') {
                $element['values'] = $values;
            }
        }
        unset($element);

        return json_encode($form);
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Set status
        $this->SetStatus(102);
    }

    /**
     * Returns the list of discovered players from the cache.
     *
     * @return array<int,array{uuid:string,ip:string,roomName:string,modelName:string,modelGeneration:string,softwareVersion:string,hardwareVersion:string,serialNumber:string,macAddress:string,descriptionUrl:string}>
     */
    private function GetDiscoveredPlayers(): array
    {
        $response = $this->RequestDataFromParent('discoveredPlayers');
        $this->LogDebug(__FUNCTION__, $response);
        return json_decode($response, true) ?? [];
    }

    /**
     * Looks up an already-existing SonosSpeaker instance for the given
     * device UUID, so the configurator shows "open instance" instead of
     * "create instance" for devices that were already added earlier.
     *
     * @param string $uuid UUID of the Sonos device to look up.
     *
     * @return int Instance ID of the existing SonosSpeaker instance, or 0 if none exists.
     */
    private function FindExistingSpeakerInstance(string $uuid): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::SONOS_SPEAKER_GUID) as $instanceID) {
            if (IPS_GetProperty($instanceID, 'PlayerUUID') === $uuid) {
                return $instanceID;
            }
        }

        return 1;
    }

    /**
     * Returns the ascending list of category names for a given category id
     *
     * @param string $endpoint API endpoint request.
     *
     * @return string Result of the API call.
     */
    private function RequestDataFromParent(string $endpoint): string
    {
        return $this->SendDataToParent(json_encode([
            'DataID'      => self::SONOS_BRIDGE_GUID,
            'Endpoint'    => $endpoint,
        ]));
    }
}