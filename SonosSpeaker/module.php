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
 * CLASS SonosSpeaker
 */
class SonosSpeaker extends IPSModuleStrict
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

    /** @var int Instance status: no player selected */
    private const STATUS_NO_PLAYER = 202;

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

        // UUID of the Sonos device this instance represents (e.g. "RINCON_...")
        $this->RegisterPropertyString('PlayerUUID', '');

        // Room name as reported by the device at creation time, purely
        // informational (the instance name itself is set by the configurator).
        $this->RegisterPropertyString('RoomName', '');

        // Optional per-speaker station list. Empty (default) means "inherit
        // the global list from SonosBridge".
        $this->RegisterPropertyString('StationOverride', '[]');

        // Poll interval for playback state/volume/track metadata.
        $this->RegisterPropertyInteger('UpdateInterval', self::DEFAULT_UPDATE_INTERVAL);

        // Report an unreachable speaker as error (201) or only as inactive (104),
        // e.g. for speakers that are intentionally disconnected from power.
        $this->RegisterPropertyBoolean('OfflineAsError', true);

        // Caches the station list currently shown in the Playlist variable,
        // so RequestAction() can resolve a selected index back to a stream URI.
        $this->RegisterAttributeString('ActiveStations', '[]');

        // Caches an identifier of the currently displayed track (not the
        // cover URL - Sonos' "getaa" cover endpoint is a static URL whose
        // returned image content changes per song, so the URL itself is
        // useless as a change indicator).
        $this->RegisterAttributeString('LastTrackKey', '');

        // Timer for polling the current playback state, volume, mute and track metadata.
        $this->RegisterTimer('UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Update", 0);');

        // Kernel start: dataflow to the parent is only allowed once the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Create the instance variables (Playback, Volume, Mute, Artist, Title, Album, Playlist)
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

        // Preset the test slider with the current volume
        $this->ModifyFormElement($form['actions'], 'TestVolume', function (array &$element)
        {
            $element['value'] = GetValueInteger($this->GetIDForIdent('Volume'));
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

        // The bridge only pushes station list changes down to the speakers
        $this->SetReceiveDataFilter('.*stationsChanged.*');

        // Track (re)connections to the bridge
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $uuid = $this->ReadPropertyString('PlayerUUID');

        if ($uuid === '') {
            $this->SetStatus(self::STATUS_NO_PLAYER);
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

        // Rebuild the playlist from the effective station list
        $this->RefreshPlaylistVariable();

        // Set the timer interval for polling the current playback state, volume, mute and track metadata.
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
            case 'FormPlayback':
                // FormPlayback = test buttons of the configuration form, hints are shown as popup there
                if (!$this->HandleGroupMemberAction('Playback', (int) $value, $ident === 'FormPlayback')) {
                    $this->HandlePlaybackAction((int) $value);
                }
                break;
            case 'Volume':
                $this->HandleVolumeAction((int) $value);
                break;
            case 'Mute':
                $this->HandleMuteAction((bool) $value);
                break;
            case 'Playlist':
                $index = $this->ParsePlaylistIndex($value);
                if ($index !== null && !$this->HandleGroupMemberAction($ident, $index)) {
                    $this->HandlePlaylistAction($index);
                }
                break;
            case 'Update':
                // Value 1 = triggered manually from the configuration form (show result), 0 = timer
                $error = $this->PollStatus();
                if ((bool) $value) {
                    $this->ShowStatusPopup($error, ['Playback', 'Artist', 'Title', 'Album', 'Volume', 'Mute']);
                }
                break;
            default:
                $this->LogDebug(__FUNCTION__, 'There was no reaction to the action.');
        }
    }

    /**
     * Handles data pushed down from the parent SonosBridge (the standard
     * Symcon parent-to-child dataflow, counterpart to SendDataToParent /
     * ForwardData used for the opposite direction).
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
     * Polls the current playback state, volume, mute and track metadata
     * from the speaker and updates the instance variables accordingly.
     *
     * @return bool True if the speaker answered, false otherwise
     */
    public function UpdateStatus(): bool
    {
        return $this->PollStatus() === '';
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    /**
     * Performs the actual status poll. Called periodically via timer, via
     * UpdateStatus() and via the "Update status" button of the form.
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
        $this->LogDebug(__FUNCTION__, 'Resolved IP: ' . $ip);

        $trackInfo = $this->GetPlayerTrackInfo($ip);
        $this->LogDebug(__FUNCTION__, 'GetPlayerTrackInfo() returned: ' . var_export($trackInfo, true));
        if ($trackInfo === false) {
            // Skip the remaining calls - each would wait for the full SOAP timeout again
            $this->ReportUnreachable();
            $this->SetOffline(sprintf('no response from %s', $ip));
            return sprintf($this->Translate('Speaker not reachable (IP: %s).'), $ip);
        }

        if ($this->IsOffline()) {
            $this->SetOnline();
        }

        // As a group member the speaker reports no usable metadata of its own,
        // track info and cover are taken from the group coordinator instead
        $coordinator = $this->ResolveCoordinator($ip);
        if ($coordinator !== null && $coordinator['uuid'] !== $this->ReadPropertyString('PlayerUUID')) {
            $this->LogDebug(__FUNCTION__, 'Group member, reading track info from coordinator ' . $coordinator['ip']);
            $coordinatorInfo = $this->GetPlayerTrackInfo($coordinator['ip']);
            if ($coordinatorInfo !== false) {
                $trackInfo = $coordinatorInfo;
            } else {
                $this->LogDebug(__FUNCTION__, 'Coordinator did not answer, keeping own track info.');
            }
        }

        $this->UpdateTrackVariables($trackInfo);

        $volume = $this->GetPlayerVolume($ip);
        $this->LogDebug(__FUNCTION__, 'GetPlayerVolume() returned: ' . var_export($volume, true));
        if ($volume !== false) {
            $this->SetValueInteger('Volume', $volume);
        }

        $mute = $this->GetPlayerMute($ip);
        $this->LogDebug(__FUNCTION__, 'GetPlayerMute() returned: ' . var_export($mute, true));
        if ($mute !== null) {
            $this->SetValueBoolean('Mute', $mute);
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    /**
     * Applies the group rules to a playback or station action, same as the
     * Sonos app: while the speaker's group is playing, the group must be
     * controlled via the Sonos Group instance (only a hint is shown). If the
     * group is idle, Play and station selection take the speaker out of the
     * group, so it plays on its own. This applies to members and to the
     * coordinator alike (Sonos hands the coordinator role over to a remaining
     * member); a speaker playing on its own is not affected.
     *
     * @param string $ident    Ident of the action (Playback or Playlist)
     * @param int    $value    Playback command or station index
     * @param bool   $fromForm Triggered from the configuration form (hint as popup instead of echo)
     *
     * @return bool True if the action was handled (or rejected) here, false if the regular handler should run
     */
    private function HandleGroupMemberAction(string $ident, int $value, bool $fromForm = false): bool
    {
        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return false;
        }

        $groups = $this->GetZoneGroups($ip);
        $group = $groups !== false ? $this->FindGroup($groups) : null;
        if ($group === null || count($group['members']) < 2) {
            return false;
        }

        $groupInfo = $this->GetPlayerTrackInfo($group['ip']);
        if ($groupInfo !== false && in_array($groupInfo['state'], ['PLAYING', 'TRANSITIONING'], true)) {
            $this->LogDebug(__FUNCTION__, 'Group is playing, action rejected.');
            $hint = $this->Translate('This speaker belongs to a playing group, please use the Sonos Group instance.');
            if ($fromForm) {
                $this->EchoMessage($hint);
            } else {
                // The visualization shows the output of RequestAction as message
                echo $hint;
            }
            return true;
        }

        // Stop, pause or skip on an idle group: nothing to do for a single speaker
        if ($ident === 'Playback' && $value !== self::PLAYBACK_PLAY) {
            $this->LogDebug(__FUNCTION__, sprintf('Group is idle, command %d ignored.', $value));
            return true;
        }

        // Read the group's source before leaving, a member has none of its own
        $media = $ident === 'Playback' ? $this->GetPlayerMediaInfo($group['ip']) : false;

        $this->LogDebug(__FUNCTION__, 'Group is idle, leaving the group to play solo.');
        if (!$this->LeavePlayerGroup($ip)) {
            return true;
        }

        // Queues (x-rincon-queue:) belong to the coordinator and cannot be taken over, the speaker
        // then plays its own queue; radio streams (e.g. x-rincon-mp3radio:) are taken over fine
        $transferable = $media !== false && $media['uri'] !== ''
            && !str_starts_with($media['uri'], 'x-rincon-queue:') && !str_starts_with($media['uri'], 'x-rincon:');
        if ($transferable) {
            $this->SetPlayerUri($ip, $media['uri'], $media['metadata']);
        }

        return false;
    }

    /**
     * Handles volume control actions.
     *
     * @param int $volume Volume level to set.
     *
     * @return void
     */
    private function HandleVolumeAction(int $volume): void
    {
        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return;
        }

        if ($this->SetPlayerVolume($ip, $volume)) {
            $this->LogDebug(__FUNCTION__, $volume);
            $this->SetValueInteger('Volume', $volume);
        }
    }

    /**
     * Handles mute control actions.
     *
     * @param bool $mute Mute state to set.
     *
     * @return void
     */
    private function HandleMuteAction(bool $mute): void
    {
        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return;
        }

        if ($this->SetPlayerMute($ip, $mute)) {
            $this->SetValueBoolean('Mute', $mute);
        }
    }

    // -------------------------------------------------------------------------
    // PlayerHelper implementation
    // -------------------------------------------------------------------------

    /**
     * Transport and station commands go directly to this speaker, the
     * group cases are handled beforehand by HandleGroupMemberAction().
     *
     * @return string|null IP address of the speaker, or null if not available
     */
    private function ResolveTransportIp(): ?string
    {
        return $this->ResolvePlayerIp();
    }

    /**
     * Returns the effective station list: the per-speaker override if
     * configured, otherwise the global list from SonosBridge.
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
     * @return string "Speaker" followed by the room name, or UUID if no room name is configured
     */
    private function GetLogLabel(): string
    {
        $room = $this->ReadPropertyString('RoomName');
        return 'Speaker ' . ($room !== '' ? $room : $this->ReadPropertyString('PlayerUUID'));
    }

    /**
     * The anchor is the speaker itself.
     *
     * @return string UUID of the speaker
     */
    private function GetAnchorUuid(): string
    {
        return $this->ReadPropertyString('PlayerUUID');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates the instance variables for the module.
     *
     * @return void
     */
    private function CreateVariables(): void
    {
        $this->RegisterVariableInteger('Playback', $this->Translate('Playback'), '~PlaybackPreviousNext', 10);
        $this->EnableAction('Playback');

        $this->RegisterVariableInteger('Volume', $this->Translate('Volume'), '~Volume', 20);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', $this->Translate('Mute'), '~Mute', 30);
        $this->EnableAction('Mute');

        $this->RegisterVariableString('Artist', $this->Translate('Artist'), '~Artist', 40);
        $this->RegisterVariableString('Title', $this->Translate('Title'), '~Song', 50);
        $this->RegisterVariableString('Album', $this->Translate('Album'), '', 60);

        $this->RegisterVariableString('Playlist', $this->Translate('Stations'), '~Playlist', 70);
        $this->EnableAction('Playlist');

        $this->CreateCoverMedia(5);
    }
}
