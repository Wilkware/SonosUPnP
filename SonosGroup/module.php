<?php

declare(strict_types=1);

/** Generell funktions */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\SonosUPnP\DebugHelper;
use Wilkware\SonosUPnP\FormHelper;
use Wilkware\SonosUPnP\PlayerHelper;
use Wilkware\SonosUPnP\SonosHelper;
use Wilkware\SonosUPnP\VariableHelper;

/**
 * CLASS SonosGroup
 *
 * Represents the Sonos group the configured main speaker currently belongs to.
 * The group structure is read via ZoneGroupTopology, transport and station
 * commands are routed to the group coordinator and the track info/cover
 * is taken from the coordinator. Volume and mute stay per speaker.
 */
class SonosGroup extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormHelper;
    use PlayerHelper;
    use SonosHelper;
    use VariableHelper;

    // -------------------------------------------------------------------------
    // GUIDs
    // -------------------------------------------------------------------------

    /** @var string ModulID (Sonos Bridge), required by RequireParent */
    private const SONOS_BRIDGE_MODULE_GUID = '{FB07CB8D-A0BD-45B9-C334-02FFCCFD148C}';

    /** @var int Instance status: more than one main speaker selected */
    private const STATUS_MULTIPLE_MAIN = 202;

    /** @var int Instance status: no main speaker selected */
    private const STATUS_NO_MAIN = 203;

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

        if ((float) IPS_GetKernelVersion() < 8.2 && method_exists($this, 'RequireParent')) {
            $this->RequireParent(self::SONOS_BRIDGE_MODULE_GUID);
        }

        // Speakers of the group (UUID, Main). The main speaker leads the group, the instance shows the group it
        // currently belongs to. The others are rejoined automatically on Play and station selection,
        // because the fixed groups of the Sonos app are not visible via UPnP.
        $this->RegisterPropertyString('GroupMembers', '[]');

        // Poll interval for group structure, playback state and track metadata.
        $this->RegisterPropertyInteger('UpdateInterval', self::DEFAULT_UPDATE_INTERVAL);

        // Report an unreachable group as error (201) or only as inactive (104),
        // e.g. for speakers that are intentionally disconnected from power.
        $this->RegisterPropertyBoolean('OfflineAsError', true);

        // Optional group specific station list, empty = use the global list of SonosBridge
        $this->RegisterPropertyString('StationOverride', '[]');

        // Caches the station list currently shown in the Playlist variable,
        // so RequestAction() can resolve a selected index back to a stream URI.
        $this->RegisterAttributeString('ActiveStations', '[]');

        // Caches an identifier of the currently displayed track (see SonosSpeaker).
        $this->RegisterAttributeString('LastTrackKey', '');

        // Timer for polling the group structure, playback state and track metadata.
        $this->RegisterTimer('UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Update", 0);');

        // Kernel start: dataflow to the parent is only allowed once the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Create the instance variables (Playback, Artist, Title, Album, Playlist, Members)
        $this->CreateVariables();
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

        // Offer all speakers known to the bridge
        $this->ModifyFormElement($form['elements'], 'GroupMembers', function (array &$element)
        {
            $element['columns'][0]['edit']['options'] = $this->GetPlayerOptions();
        });

        // Update Version in form
        $this->ModifyFormElement($form['actions'], 'Version', function (array &$element)
        {
            // Extract Version
            $ins = IPS_GetInstance($this->InstanceID);
            $mod = IPS_GetModule($ins['ModuleInfo']['ModuleID']);
            $lib = IPS_GetLibrary($mod['LibraryID']);

            $element['caption'] = sprintf('v%s.%d', $lib['Version'], $lib['Build']);
        });

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

        // The bridge only pushes station list changes down to its children
        $this->SetReceiveDataFilter('.*stationsChanged.*');

        // Track (re)connections to the bridge
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // Exactly one main speaker is required, the setting is never corrected automatically
        $mainCount = count(array_filter($this->GetGroupSpeakers(), fn (array $speaker): bool => $speaker['Main']));
        if ($mainCount !== 1 || $this->GetAnchorUuid() === '') {
            $this->SetStatus($mainCount > 1 ? self::STATUS_MULTIPLE_MAIN : self::STATUS_NO_MAIN);
            $this->SetTimerInterval('UpdateStatus', 0);
            return;
        }

        // Active (102), or keep the offline state (201/104) - the next poll corrects it either way
        $this->ApplyReachabilityStatus();

        // Everything else is deferred until IPS_KERNELSTARTED (see MessageSink)
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterParent();

        // Rebuild the playlist from the effective station list (override or global)
        $this->RefreshPlaylistVariable();

        // Set the timer interval for polling the group structure, playback state and track metadata.
        $this->SetTimerInterval('UpdateStatus', $this->GetCurrentPollInterval() * 1000);
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
        switch ($message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                if (IPS_GetKernelRunlevel() !== KR_READY) {
                    break;
                }
                $this->RegisterParent();
                if ($this->HasActiveParent()) {
                    $this->RefreshPlaylistVariable();
                }
                break;
            case IM_CHANGESTATUS:
                // Bridge became active (again) - station list may have been empty so far
                if ($senderID === (int) $this->GetBuffer('ParentID') && ($data[0] ?? 0) === IS_ACTIVE) {
                    $this->LogDebug(__FUNCTION__, 'Parent became active, refreshing playlist.');
                    $this->RefreshPlaylistVariable();
                }
                break;
        }
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed  $value The value to be set
     *
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        $this->LogDebug(__FUNCTION__, $ident . ' => ' . var_export($value, true));

        switch ($ident) {
            case 'Playback':
                if ((int) $value === self::PLAYBACK_PLAY) {
                    $this->RejoinMembers();
                }
                $this->HandlePlaybackAction((int) $value);
                break;
            case 'Playlist':
                $this->RejoinMembers();
                $this->HandlePlaylistAction((int) $value);
                break;
            case 'Update':
                // Value 1 = triggered manually from the configuration form (show result), 0 = timer
                $error = $this->PollStatus();
                if ((bool) $value) {
                    $this->ShowStatusPopup($error, ['Members', 'Playback', 'Artist', 'Title', 'Album']);
                }
                break;
            default:
                $this->LogDebug(__FUNCTION__, 'There was no reaction to the action.');
        }
    }

    /**
     * Handles data pushed down from the parent SonosBridge.
     *
     * @param string $json JSON-encoded data from the parent.
     *
     * @return string
     */
    public function ReceiveData(string $json): string
    {
        $data = json_decode($json, true);

        if (($data['Action'] ?? '') === 'stationsChanged') {
            $this->LogDebug(__FUNCTION__, 'Station list changed on SonosBridge, refreshing.');
            $this->RefreshPlaylistVariable();
        }

        return '';
    }

    /**
     * Polls the group structure, playback state and track metadata
     * and updates the instance variables accordingly.
     *
     * @return bool True if the group answered, false otherwise
     */
    public function UpdateGroupStatus(): bool
    {
        return $this->PollStatus() === '';
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    /**
     * Performs the actual status poll. Called periodically via timer, via
     * UpdateGroupStatus() and via the "Update status" button of the form.
     *
     * @return string Empty string on success, otherwise a translated error text
     */
    private function PollStatus(): string
    {
        if (!$this->HasActiveParent()) {
            $this->LogDebug(__FUNCTION__, 'Aborting: no active parent (SonosBridge).');
            return $this->Translate('No active parent (Sonos Bridge).');
        }

        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            // The bridge has already scheduled a rescan for unknown UUIDs
            $this->SetOffline('IP address could not be resolved');
            return $this->Translate('IP address could not be resolved.');
        }

        $groups = $this->GetZoneGroups($ip);
        if ($groups === false) {
            $this->ReportUnreachable();
            $this->SetOffline(sprintf('no response from %s', $ip));
            return sprintf($this->Translate('Speaker not reachable (IP: %s).'), $ip);
        }

        $group = $this->FindGroup($groups);
        if ($group === null) {
            $this->LogDebug(__FUNCTION__, 'Reference speaker not found in topology: ' . json_encode($groups));
            return $this->Translate('Speaker not found in the group structure.');
        }
        $this->LogDebug(__FUNCTION__, 'Group: ' . json_encode($group));

        $trackInfo = $this->GetPlayerTrackInfo($group['ip']);
        $this->LogDebug(__FUNCTION__, 'GetPlayerTrackInfo() returned: ' . var_export($trackInfo, true));
        if ($trackInfo === false) {
            $this->SetOffline(sprintf('coordinator %s does not answer', $group['ip']));
            return sprintf($this->Translate('Coordinator not reachable (IP: %s).'), $group['ip']);
        }

        if ($this->IsOffline()) {
            $this->SetOnline();
        }

        $this->SetValueString('Members', $this->FormatMembers($group));
        $this->UpdateTrackVariables($trackInfo);

        return '';
    }

    /**
     * Brings configured group members back into the group, e.g. after a
     * speaker left it to play on its own. Same as ticking the room again
     * in the Sonos app; a member playing solo stops its own playback.
     *
     * @return void
     */
    private function RejoinMembers(): void
    {
        $members = array_filter($this->GetGroupSpeakers(), fn (array $speaker): bool => !$speaker['Main']);
        if (count($members) === 0) {
            return;
        }

        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return;
        }

        $groups = $this->GetZoneGroups($ip);
        $group = $groups !== false ? $this->FindGroup($groups) : null;
        if ($group === null) {
            $this->LogDebug(__FUNCTION__, 'Group structure not available, no members rejoined.');
            return;
        }

        $current = array_column($group['members'], 'uuid');
        foreach ($members as $member) {
            $uuid = $member['UUID'];
            if ($uuid === '' || in_array($uuid, $current, true)) {
                continue;
            }

            $memberIp = $this->ResolvePlayerIp($uuid);
            if ($memberIp === null) {
                $this->LogDebug(__FUNCTION__, sprintf('Member %s could not be resolved, skipped.', $uuid));
                continue;
            }

            if ($this->JoinPlayerGroup($memberIp, $group['coordinator'])) {
                $this->LogDebug(__FUNCTION__, sprintf('Member %s rejoined the group of %s.', $uuid, $group['coordinator']));
            }
        }
    }

    /**
     * Formats the member list for the Members variable, coordinator first.
     *
     * @param array{coordinator:string,members:array<int,array{uuid:string,zoneName:string,ip:string}>,ip:string} $group Group of FindGroup()
     *
     * @return string Comma separated room names
     */
    private function FormatMembers(array $group): string
    {
        $names = [];
        foreach ($group['members'] as $member) {
            if ($member['uuid'] === $group['coordinator']) {
                array_unshift($names, $member['zoneName']);
            } else {
                $names[] = $member['zoneName'];
            }
        }
        return implode(', ', $names);
    }

    // -------------------------------------------------------------------------
    // PlayerHelper implementation
    // -------------------------------------------------------------------------

    /**
     * Transport and station commands go to the current group coordinator.
     * The topology is read fresh, the group may have changed since the last poll.
     *
     * @return string|null IP address of the coordinator, or null if not available
     */
    private function ResolveTransportIp(): ?string
    {
        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return null;
        }

        return $this->ResolveCoordinator($ip)['ip'] ?? null;
    }

    /**
     * Returns the group specific station list if configured, otherwise the global list of SonosBridge.
     *
     * @return array<int,array{Name:string,StreamURL:string,IconURL:string}>
     */
    private function GetEffectiveStations(): array
    {
        $override = json_decode($this->ReadPropertyString('StationOverride'), true);
        if (is_array($override) && count($override) > 0) {
            return $override;
        }

        return $this->GetGlobalStations();
    }

    /**
     * Returns a human readable label for log messages.
     *
     * @return string "Group" followed by the instance name
     */
    private function GetLogLabel(): string
    {
        return 'Group ' . IPS_GetName($this->InstanceID);
    }

    /**
     * The anchor is the main speaker of the group.
     *
     * @return string UUID of the main speaker, empty if none or more than one is selected
     */
    private function GetAnchorUuid(): string
    {
        $main = array_values(array_filter($this->GetGroupSpeakers(), fn (array $speaker): bool => $speaker['Main']));
        return count($main) === 1 ? $main[0]['UUID'] : '';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the configured speakers of the group.
     *
     * @return array<int,array{UUID:string,Main:bool}>
     */
    private function GetGroupSpeakers(): array
    {
        $rows = json_decode($this->ReadPropertyString('GroupMembers'), true);
        if (!is_array($rows)) {
            return [];
        }

        $speakers = [];
        foreach ($rows as $row) {
            $speakers[] = [
                'UUID' => (string) ($row['UUID'] ?? ''),
                'Main' => (bool) ($row['Main'] ?? false)
            ];
        }
        return $speakers;
    }

    /**
     * Builds the speaker options of the speaker list from the players known
     * to the bridge. Configured UUIDs are always kept selectable.
     *
     * @return array<int,array{caption:string,value:string}>
     */
    private function GetPlayerOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select'), 'value' => '']];

        $players = [];
        if ($this->HasActiveParent()) {
            $response = $this->SendDataToParent(json_encode([
                'DataID'   => self::SONOS_BRIDGE_GUID,
                'Endpoint' => 'discoveredPlayers'
            ]));
            $decoded = json_decode($response, true);
            $players = is_array($decoded) ? $decoded : [];
        }

        $known = [];
        foreach ($players as $player) {
            // Hidden stereo pair halves and home theater satellites are part of their visible room
            if (!($player['visible'] ?? true)) {
                continue;
            }
            $options[] = [
                'caption' => sprintf('%s (%s)', $player['roomName'], $player['modelName']),
                'value'   => $player['uuid']
            ];
            $known[] = $player['uuid'];
        }

        foreach ($this->GetGroupSpeakers() as $speaker) {
            if ($speaker['UUID'] !== '' && !in_array($speaker['UUID'], $known, true)) {
                $options[] = ['caption' => $speaker['UUID'], 'value' => $speaker['UUID']];
                $known[] = $speaker['UUID'];
            }
        }

        return $options;
    }

    /**
     * Creates the instance variables for the module.
     *
     * @return void
     */
    private function CreateVariables(): void
    {
        $this->RegisterVariableInteger('Playback', $this->Translate('Playback'), '~PlaybackPreviousNext', 10);
        $this->EnableAction('Playback');

        $this->RegisterVariableString('Members', $this->Translate('Members'), '', 20);

        $this->RegisterVariableString('Artist', $this->Translate('Artist'), '~Artist', 40);
        $this->RegisterVariableString('Title', $this->Translate('Title'), '~Song', 50);
        $this->RegisterVariableString('Album', $this->Translate('Album'), '', 60);

        $this->RegisterVariableString('Playlist', $this->Translate('Stations'), '~Playlist', 70);
        $this->EnableAction('Playlist');

        $this->CreateCoverMedia(5);
    }
}
