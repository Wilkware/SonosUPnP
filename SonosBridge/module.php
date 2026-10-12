<?php

declare(strict_types=1);

/** Generell funktions  */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\SonosUPnP\DebugHelper;
use Wilkware\SonosUPnP\FormHelper;
use Wilkware\SonosUPnP\SonosHelper;
use Wilkware\SonosUPnP\VariableHelper;

/**
 * CLASS SonosBridge
 */
class SonosBridge extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormHelper;
    use SonosHelper;
    use VariableHelper;

    // -------------------------------------------------------------------------
    // GUIDs
    // -------------------------------------------------------------------------

    /** @var string Child interface GUID
     * Note: this is the childRequirements/implemented pair (parent -> child,
     * SendDataToChildren/ReceiveData) - NOT the same GUID used for the
     * opposite direction (SendDataToParent/ForwardData, playerIP/stations queries).
     */
    private const SONOS_CHILD_INTERFACE_GUID = '{EFD75742-5FD6-CF66-26BF-CBBD4EF9365A}';

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var int Interval for periodic Sonos network scans (in milliseconds) */
    private const SONOS_SCAN_INTERVALL = 5 * 60 * 1000; // 5 minutes

    /** @var int Delay of the first scan after kernel start or ApplyChanges (in milliseconds) */
    private const SONOS_SCAN_INITIAL_DELAY = 5 * 1000; // 5 seconds

    /** @var int Minimum gap between two on-demand scans (in seconds) */
    private const SONOS_SCAN_MIN_GAP = 60;

    /** @var int Players not seen for this long are dropped from the cache (in seconds) */
    private const SONOS_PLAYER_EXPIRY = 24 * 60 * 60; // 24 hours

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
        parent::Create();

        // Global station list, used as a fallback by SonosSpeaker instances
        // unless they define their own override.
        $this->RegisterPropertyString('Stations', '[]');

        // Cache of the most recently discovered players (UUID -> IP/room name/model).
        $this->RegisterAttributeString('DiscoveredPlayers', '[]');

        // Periodic background scan so IP changes (DHCP) are picked up automatically.
        $this->RegisterTimer('PeriodicScan', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "scan", false);');

        // Kernel start: dataflow to children is only allowed once the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        //Never delete this line!
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

        // Debug output
        //$this->LogDebug(__FUNCTION__, $form);
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

        // Set the status to active (102) to indicate that the module is running and ready.
        $this->SetStatus(102);

        // Everything else is deferred until IPS_KERNELSTARTED (see MessageSink)
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Rescan shortly after start, IP addresses may have changed while Symcon was down.
        // Scan() resets the timer to the regular interval afterwards.
        $this->SetTimerInterval('PeriodicScan', self::SONOS_SCAN_INITIAL_DELAY);

        // Notify all child instances that the station list has changed (in case it was updated).
        $this->NotifyChildrenOfStationChange();
    }

    /**
     * The content of the function can be overwritten in order to react to messages registered via RegisterMessage.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $senderID  Reference ID of the sender
     * @param int   $message   ID of the message
     * @param array<mixed> $data Data of the message
     *
     * @return void
     */
    public function MessageSink(int $timestamp, int $senderID, int $message, array $data): void
    {
        if ($message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed $value The value to be set
     *
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        // Debug output
        $this->LogDebug(__FUNCTION__, $ident . ' => ' . $value);
        switch ($ident) {
            case 'scan':
                $this->Scan($value);
                break;
            default:
                break;
        }
    }

    /**
     * This function is called by Symcon and processes sent data and forwards it to the parent instance.
     * Data can be sent using the SendDataToParent function.
     * Further information on data forwarding can be found under Dataflow.
     *
     * @param string $json
     *
     * @return string Result of the function, which is returned to the calling child instance
     */
    public function ForwardData(string $json): string
    {
        $this->LogDebug(__FUNCTION__, $json);
        $data = json_decode($json, true);
        $result = '[]';

        if (isset($data['Endpoint'])) {
            switch ($data['Endpoint']) {
                case 'discoveredPlayers':
                    $result = $this->ReadAttributeString('DiscoveredPlayers');
                    break;
                case 'playerIP':
                    try {
                        $ip = $this->GetPlayerIP((string) ($data['UUID'] ?? ''));
                        $result = json_encode(['ip' => $ip]);
                    } catch (\RuntimeException $e) {
                        $result = json_encode(['error' => $e->getMessage()]);
                    }
                    break;
                case 'stations':
                    $result = $this->ReadPropertyString('Stations');
                    break;
                case 'playerUnreachable':
                    // The cached IP did not answer - most likely changed via DHCP
                    $this->LogDebug(__FUNCTION__, sprintf('Player %s reported unreachable, requesting rescan.', $data['UUID'] ?? ''));
                    $this->RequestScan();
                    break;
                default:
                    $this->LogDebug(__FUNCTION__, sprintf('Unknown endpoint "%s"', $data['Endpoint']));
                    break;
            }
        }
        return $result;
    }

    /**
     * Returns the last known IP address of a player identified by its UUID.
     * Used by SonosSpeaker before every SOAP call so that DHCP-induced IP
     * changes are automatically accounted for.
     *
     * @param string $uuid The UUID of the player for which to retrieve the IP address
     *
     * @throws \RuntimeException if the UUID is unknown (an asynchronous rescan is requested)
     *
     * @return string The last known IP address of the player
     */
    public function GetPlayerIP(string $uuid): string
    {
        foreach ($this->GetDiscoveredPlayers() as $player) {
            if ($player['uuid'] === $uuid) {
                return $player['ip'];
            }
        }

        // Never scan synchronously here - this runs inside ForwardData for
        // every poll of every speaker and would block PHP threads.
        $this->RequestScan();

        throw new \RuntimeException(sprintf('Sonos speaker with UUID %s was not found on the network.', $uuid));
    }

    /**
     * Performs an SSDP search on the local network and merges the result
     * into the cached list of discovered players. Triggered manually
     * (button on the configuration page), periodically via timer and
     * on demand via RequestScan().
     *
     * @param bool $manual Whether the scan was triggered manually (true) or via timer (false).
     *
     * @return void
     */
    protected function Scan(bool $manual = false): void
    {
        // Back to the regular interval, an initial or on-demand delay may be active
        $this->SetTimerInterval('PeriodicScan', self::SONOS_SCAN_INTERVALL);

        // Only one scan at a time, parallel scans would race on the cache attribute
        $semaphore = 'SonosBridge.Scan.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            $this->LogDebug(__FUNCTION__, 'Scan already running, skipped.');
            return;
        }

        try {
            $this->SetBuffer('LastScan', (string) time());
            $found = $this->ScanNetwork();

            if ($found === false) {
                $this->LogDebug(__FUNCTION__, 'SSDP scan failed');
                return;
            }

            $players = $this->MergePlayers($this->GetDiscoveredPlayers(), $found);
            $this->WriteAttributeString('DiscoveredPlayers', json_encode($players));
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        if ($manual) {
            $this->EchoMessage($this->PlayerPrint($found));
        }

        $this->LogDebug(__FUNCTION__, sprintf('Found %d Sonos device(s), %d cached', count($found), count($players)));
    }

    /**
     * Schedules an asynchronous rescan via the PeriodicScan timer, rate
     * limited to one scan per SONOS_SCAN_MIN_GAP seconds. Repeated calls
     * within the gap all target the same point in time.
     *
     * @return void
     */
    private function RequestScan(): void
    {
        $elapsed = time() - (int) $this->GetBuffer('LastScan');
        $delay = max(1, self::SONOS_SCAN_MIN_GAP - $elapsed);
        $this->LogDebug(__FUNCTION__, sprintf('Rescan scheduled in %d s', $delay));
        $this->SetTimerInterval('PeriodicScan', $delay * 1000);
    }

    /**
     * Merges a fresh scan result into the cached player list. Players
     * that did not answer this time (standby, lost UDP packet, network
     * not ready yet) are kept with their last known data and only dropped
     * after SONOS_PLAYER_EXPIRY.
     *
     * @param array<int,array<string,mixed>> $cached Currently cached players
     * @param array<int,array<string,mixed>> $found  Players found by the current scan
     *
     * @return array<int,array<string,mixed>> Merged player list
     */
    private function MergePlayers(array $cached, array $found): array
    {
        $now = time();
        $merged = [];

        foreach ($cached as $player) {
            // Entries cached by older module versions have no timestamp yet
            $player['lastSeen'] ??= $now;
            if (($now - (int) $player['lastSeen']) < self::SONOS_PLAYER_EXPIRY) {
                $merged[$player['uuid']] = $player;
            } else {
                $this->LogDebug(__FUNCTION__, sprintf('Dropping expired player %s (%s)', $player['roomName'], $player['uuid']));
            }
        }

        foreach ($found as $player) {
            $old = $merged[$player['uuid']]['ip'] ?? null;
            if ($old !== null && $old !== $player['ip']) {
                $this->LogMessage(sprintf('IP of %s changed from %s to %s', $player['roomName'], $old, $player['ip']), KL_NOTIFY);
            }
            $player['lastSeen'] = $now;
            $merged[$player['uuid']] = $player;
        }

        return array_values($merged);
    }

    /**
     * Broadcasts a "stations changed" notification to every connected
     * SonosSpeaker via the standard parent-to-child dataflow (ReceiveData).
     * Speakers with their own StationOverride configured are effectively
     * unaffected (GetEffectiveStations() keeps preferring their own list),
     * while speakers inheriting the global list automatically pick up
     * additions, updates and removals - no separate diffing needed.
     *
     * @return void
     */
    private function NotifyChildrenOfStationChange(): void
    {
        $this->SendDataToChildren(json_encode([
            'DataID' => self::SONOS_CHILD_INTERFACE_GUID,
            'Action' => 'stationsChanged'
        ]));
    }

    /**
     * Returns the list of discovered players from the cache.
     *
     * @return array<int,array{uuid:string,ip:string,roomName:string,modelName:string,modelGeneration:string,softwareVersion:string,hardwareVersion:string,serialNumber:string,macAddress:string,descriptionUrl:string}>
     */
    private function GetDiscoveredPlayers(): array
    {
        $decoded = json_decode($this->ReadAttributeString('DiscoveredPlayers'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Show message via popup
     *
     * @param string $caption echo message
     *
     * @return void
     */
    private function EchoMessage(string $caption): void
    {
        $this->UpdateFormField('EchoMessage', 'caption', $this->Translate($caption));
        $this->UpdateFormField('EchoPopup', 'visible', true);
    }

    /**
     * Format the players array for display in the configuration page popup.
     *
     * @param array<int,array{uuid:string,ip:string,roomName:string,modelName:string,modelGeneration:string,softwareVersion:string,hardwareVersion:string,serialNumber:string,macAddress:string,descriptionUrl:string}> $players Player data
     *
     * @return string Formatted message
     */
    private function PlayerPrint(array $players): string
    {
        $msg = '';
        foreach ($players as $player) {
            $msg .= sprintf(
                "%s (%s): %s - %s [%s]\n",
                $player['roomName'],
                $player['uuid'],
                $player['modelName'],
                $player['ip'],
                $player['macAddress']
            );
        }
        return $msg;
    }
}
