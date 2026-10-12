<?php

declare(strict_types=1);

/** Generell funktions */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\SonosUPnP\DebugHelper;
use Wilkware\SonosUPnP\FormHelper;
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
    use SonosHelper;
    use VariableHelper;

    // -------------------------------------------------------------------------
    // GUIDs
    // -------------------------------------------------------------------------

    /** @var string Dataflow interface GUID (child -> Sonos Bridge, SendDataToParent/ForwardData) */
    private const SONOS_BRIDGE_GUID = '{48975ECF-9DF0-5CD7-F937-CEFCAFA4A4B9}';

    /** @var string ModulID (Sonos Bridge), required by RequireParent */
    private const SONOS_BRIDGE_MODULE_GUID = '{FB07CB8D-A0BD-45B9-C334-02FFCCFD148C}';

    // -------------------------------------------------------------------------
    // Playback profile values
    // -------------------------------------------------------------------------

    // Verified against IPS_GetVariableProfile('~PlaybackPreviousNext')['Associations']:
    // 0=Zurueck (Previous), 1=Stop, 2=Play, 3=Pause, 4=Weiter (Next).
    // This differs from the values used by the official Sonos Cloud module's
    // own custom profile - do NOT reuse those assumptions for this built-in profile.
    private const PLAYBACK_PREVIOUS = 0;
    private const PLAYBACK_STOP = 1;
    private const PLAYBACK_PLAY = 2;
    private const PLAYBACK_PAUSE = 3;
    private const PLAYBACK_NEXT = 4;

    /** @var int Default interval for updating player status (in seconds) */
    private const DEFAULT_UPDATE_INTERVAL = 10; // seconds

    /** @var int Poll interval while the speaker is unreachable (in seconds) */
    private const OFFLINE_UPDATE_INTERVAL = 30; // seconds

    /** @var int Instance status: speaker does not answer */
    private const STATUS_UNREACHABLE = 201;

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

        if ((float) IPS_GetKernelVersion() < 8.2) {
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
            $this->SetStatus(104);
            $this->SetTimerInterval('UpdateStatus', 0);
            return;
        }

        // Set status to active (102), the next poll switches to 201 if the speaker does not answer
        $this->SetStatus(102);

        // Everything else is deferred until IPS_KERNELSTARTED (see MessageSink)
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterParent();

        // Rebuild the playlist from the effective station list
        $this->RefreshPlaylistVariable();

        // Set the timer interval for polling the current playback state, volume, mute and track metadata.
        $this->SetTimerInterval('UpdateStatus', $this->GetUpdateInterval() * 1000);
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
                $this->HandlePlaybackAction((int) $value);
                break;
            case 'Volume':
                $this->HandleVolumeAction((int) $value);
                break;
            case 'Mute':
                $this->HandleMuteAction((bool) $value);
                break;
            case 'Playlist':
                $this->HandlePlaylistAction((int) $value);
                break;
            case 'Update':
                // Value 1 = triggered manually from the configuration form (show result), 0 = timer
                $error = $this->PollStatus();
                if ((bool) $value) {
                    $this->ShowStatusPopup($error);
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

        if ($this->GetStatus() === self::STATUS_UNREACHABLE) {
            $this->SetOnline();
        }

        // This presentation reflects the raw current transport state
        // directly. Sonos reports several distinct states here
        // (PLAYING, PAUSED_PLAYBACK, STOPPED, TRANSITIONING,
        // NO_MEDIA_PRESENT) - map them explicitly instead of collapsing
        // everything non-PLAYING into Pause.
        $this->SetValueInteger('Playback', match ($trackInfo['state']) {
            'PLAYING'         => self::PLAYBACK_PLAY,
            'PAUSED_PLAYBACK' => self::PLAYBACK_PAUSE,
            'STOPPED'         => self::PLAYBACK_STOP,
            default           => self::PLAYBACK_STOP // TRANSITIONING, NO_MEDIA_PRESENT, unknown
        });

        if ($this->LooksLikeJunkMetadata($trackInfo['title'])) {
            // TuneIn sometimes reports an ad-bumper tracking URL as the
            // "title" during ad breaks (e.g. "...mp3?aw_0_1st...&..."),
            // instead of real track info. Keep the previous values
            // rather than overwriting them with tracking junk.
            $this->LogDebug(__FUNCTION__, 'Ignoring junk metadata during ad break: ' . $trackInfo['title']);
        } else {
            $matchedStation = $this->FindStationByUri($trackInfo['uri']);

            // For radio broadcasts (stationTitle set), upnp:album is
            // usually empty - fall back to the user's own configured
            // station name first ("Arabella"), then the raw technical
            // station title as a last resort ("radioarabella-muenchen...").
            $album = $trackInfo['album'];
            if ($album === '' && $trackInfo['stationTitle'] !== '') {
                $album = $matchedStation['Name'] ?? $trackInfo['stationTitle'];
            }

            $this->SetValueString('Artist', $trackInfo['artist']);
            $this->SetValueString('Title', $trackInfo['title']);
            $this->SetValueString('Album', $album);

            $trackKey = $trackInfo['artist'] . '|' . $trackInfo['title'] . '|' . $album . '|' . $trackInfo['uri'];
            $coverUrl = $trackInfo['coverUrl'] !== '' ? $trackInfo['coverUrl'] : ($matchedStation['IconURL'] ?? '');
            $this->UpdateCover($coverUrl, $trackKey);
        }

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

    /**
     * Shows the result of a manual status poll in a popup: either the
     * error text or a summary of the current status variables.
     *
     * @param string $error Error text of PollStatus(), empty on success
     *
     * @return void
     */
    private function ShowStatusPopup(string $error): void
    {
        if ($error !== '') {
            $this->EchoMessage($error);
            return;
        }

        $msg = '';
        foreach (['Playback', 'Artist', 'Title', 'Album', 'Volume', 'Mute'] as $ident) {
            $msg .= sprintf("%s: %s\n", $this->Translate($ident), GetValueFormatted($this->GetIDForIdent($ident)));
        }
        $this->EchoMessage(rtrim($msg));
    }

    /**
     * Show message via popup
     *
     * @param string $caption echo message (already translated)
     *
     * @return void
     */
    private function EchoMessage(string $caption): void
    {
        $this->UpdateFormField('EchoMessage', 'caption', $caption);
        $this->UpdateFormField('EchoPopup', 'visible', true);
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    /**
     * Handles playback control actions (Previous, Stop, Play, Pause, Next).
     *
     * @param int $command Playback command to send.
     *
     * @return void
     */
    private function HandlePlaybackAction(int $command): void
    {
        $this->LogDebug(__FUNCTION__, sprintf('Transport command %d!', $command));
        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return;
        }

        $success = match ($command) {
            self::PLAYBACK_PREVIOUS => $this->SendTransportCommand($ip, 'previous'),
            self::PLAYBACK_STOP     => $this->SendTransportCommand($ip, 'stop'),
            self::PLAYBACK_PLAY     => $this->SendTransportCommand($ip, 'play'),
            self::PLAYBACK_PAUSE    => $this->SendTransportCommand($ip, 'pause'),
            self::PLAYBACK_NEXT     => $this->SendTransportCommand($ip, 'next'),
            default                 => false
        };

        if (!$success) {
            $this->LogDebug(__FUNCTION__, sprintf('Transport command %d failed for %s', $command, $ip));
            return;
        }

        // Optimistic UI update: directly mirror the resulting state.
        // Next/Previous don't change the play/pause/stop state itself, so
        // the Playback value is left untouched here; the next poll corrects
        // it based on the actual transport state.
        if (in_array($command, [
            self::PLAYBACK_PLAY,
            self::PLAYBACK_PAUSE,
            self::PLAYBACK_STOP
        ], true)) {
            $this->SetValueInteger('Playback', $command);
        }
    }

    /**
     * Heuristically detects ad-bumper tracking URLs that TuneIn sometimes
     * returns as "title" during ad breaks (e.g. filenames with tracking
     * query parameters like "...mp3?aw_0_1st.playerid=...&..."). Real song
     * titles essentially never contain both "?" and "&" characters.
     *
     * @param string $title Track title to check.
     *
     * @return bool True if the title looks like a junk tracking URL, false otherwise.
     */
    private function LooksLikeJunkMetadata(string $title): bool
    {
        return str_contains($title, '?') && str_contains($title, '&');
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

    /**
     * Handles playlist control actions.
     *
     * @param int $index Index of the station to play.
     *
     * @return void
     */
    private function HandlePlaylistAction(int $index): void
    {
        $ip = $this->ResolvePlayerIp();
        if ($ip === null) {
            return;
        }

        $stations = json_decode($this->ReadAttributeString('ActiveStations'), true);
        if (!is_array($stations) || !isset($stations[$index])) {
            $this->LogDebug(__FUNCTION__, sprintf('Invalid playlist index %d', $index));
            return;
        }

        $station = $stations[$index];

        if ($this->PlayStreamUri($ip, $station['StreamURL'], $station['Name'])) {
            $this->SetValueInteger('Playback', self::PLAYBACK_PLAY);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the configured poll interval (in seconds, at least 1).
     *
     * @return int Poll interval in seconds
     */
    private function GetUpdateInterval(): int
    {
        return max(1, $this->ReadPropertyInteger('UpdateInterval'));
    }

    /**
     * Switches the instance into the "unreachable" state: status 201,
     * playback shown as stopped and a slower poll interval (backoff).
     * Only logs once per outage.
     *
     * @param string $reason Reason for the log entry
     *
     * @return void
     */
    private function SetOffline(string $reason): void
    {
        if ($this->GetStatus() === self::STATUS_UNREACHABLE) {
            $this->LogDebug(__FUNCTION__, 'Still unreachable: ' . $reason);
            return;
        }

        $this->LogMessage(sprintf('Speaker %s is not reachable: %s', $this->GetSpeakerLabel(), $reason), KL_WARNING);
        $this->SetStatus(self::STATUS_UNREACHABLE);
        $this->SetValueInteger('Playback', self::PLAYBACK_STOP);
        $this->SetTimerInterval('UpdateStatus', max(self::OFFLINE_UPDATE_INTERVAL, $this->GetUpdateInterval()) * 1000);
    }

    /**
     * Leaves the "unreachable" state: status 102, regular poll interval,
     * station list and cover are refreshed.
     *
     * @return void
     */
    private function SetOnline(): void
    {
        $this->LogMessage(sprintf('Speaker %s is reachable again', $this->GetSpeakerLabel()), KL_NOTIFY);
        $this->SetStatus(102);
        $this->SetTimerInterval('UpdateStatus', $this->GetUpdateInterval() * 1000);

        // Force a cover reload, the track may have changed during the outage
        $this->WriteAttributeString('LastTrackKey', '');
        $this->RefreshPlaylistVariable();
    }

    /**
     * Tells the bridge that the cached IP did not answer, so it can
     * schedule a (rate limited) rescan to pick up a changed DHCP address.
     *
     * @return void
     */
    private function ReportUnreachable(): void
    {
        $this->SendDataToParent(json_encode([
            'DataID'   => self::SONOS_BRIDGE_GUID,
            'Endpoint' => 'playerUnreachable',
            'UUID'     => $this->ReadPropertyString('PlayerUUID')
        ]));
    }

    /**
     * Returns a human readable label for log messages.
     *
     * @return string Room name, or UUID if no room name is configured
     */
    private function GetSpeakerLabel(): string
    {
        $room = $this->ReadPropertyString('RoomName');
        return $room !== '' ? $room : $this->ReadPropertyString('PlayerUUID');
    }

    /**
     * Keeps the IM_CHANGESTATUS registration in sync with the current
     * parent (bridge), so a re-activated bridge triggers a playlist refresh.
     *
     * @return void
     */
    private function RegisterParent(): void
    {
        $oldID = (int) $this->GetBuffer('ParentID');
        $newID = IPS_GetInstance($this->InstanceID)['ConnectionID'];

        if ($oldID === $newID) {
            return;
        }

        if ($oldID > 0) {
            $this->UnregisterMessage($oldID, IM_CHANGESTATUS);
        }
        if ($newID > 0) {
            $this->RegisterMessage($newID, IM_CHANGESTATUS);
        }
        $this->SetBuffer('ParentID', (string) $newID);
    }

    /**
     * Resolves the current IP address of this speaker via SonosBridge.
     * Returns null (and logs) if the UUID is not configured or the
     * bridge could not resolve it (e.g. device offline).
     *
     * @return string|null The resolved IP address, or null if not available.
     */
    private function ResolvePlayerIp(): ?string
    {
        $uuid = $this->ReadPropertyString('PlayerUUID');
        if ($uuid === '') {
            return null;
        }

        if (!$this->HasActiveParent()) {
            $this->LogDebug(__FUNCTION__, 'No active parent (SonosBridge) connected yet.');
            return null;
        }

        $response = $this->SendDataToParent(json_encode([
            'DataID'   => self::SONOS_BRIDGE_GUID,
            'Endpoint' => 'playerIP',
            'UUID'     => $uuid
        ]));

        $this->LogDebug(__FUNCTION__, 'SendDataToParent() response: ' . $response);

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || isset($decoded['error'])) {
            $this->LogDebug(__FUNCTION__, sprintf('Could not resolve IP for %s: %s', $uuid, $decoded['error'] ?? 'no response'));
            return null;
        }

        return $decoded['ip'] ?? null;
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

        if (!$this->HasActiveParent()) {
            return [];
        }

        $response = $this->SendDataToParent(json_encode([
            'DataID'   => self::SONOS_BRIDGE_GUID,
            'Endpoint' => 'stations'
        ]));

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Rebuilds the Playlist variable (~Playlist profile) from the currently
     * effective station list and caches the list so RequestAction() can map
     * a selected index back to a stream URL.
     *
     * @return void
     */
    private function RefreshPlaylistVariable(): void
    {
        $stations = $this->GetEffectiveStations();
        $this->WriteAttributeString('ActiveStations', json_encode($stations));

        $entries = [];
        foreach ($stations as $station) {
            $entries[] = [
                'song'     => $station['Name'],
                'artist'   => '',
                'duration' => 0,
                'uri'      => $station['StreamURL']
            ];
        }

        $playlist = json_encode(['entries' => $entries, 'current' => 0]);
        $this->SetValueString('Playlist', $playlist);
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

        $this->RegisterVariableInteger('Volume', $this->Translate('Volume'), '~Intensity.100', 20);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', $this->Translate('Mute'), '~Switch', 30);
        $this->EnableAction('Mute');

        $this->RegisterVariableString('Artist', $this->Translate('Artist'), '~Artist', 40);
        $this->RegisterVariableString('Title', $this->Translate('Title'), '~Song', 50);
        $this->RegisterVariableString('Album', $this->Translate('Album'), '', 60);

        $this->RegisterVariableString('Playlist', $this->Translate('Stations'), '~Playlist', 70);
        $this->EnableAction('Playlist');

        // Cover image, following the same pattern as the official Sonos Cloud module.
        if (!@IPS_GetObjectIDByIdent('Cover', $this->InstanceID)) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, 'Cover');
            IPS_SetMediaCached($mediaID, true);
            // Connect to file
            IPS_SetMediaFile($mediaID, $this->GetCoverFile(), false);
            IPS_SetPosition($mediaID, 5);
            IPS_SetName($mediaID, $this->Translate('Cover'));
        }
    }

    /**
     * Returns the media file of the cover image, relative to the kernel
     * directory. The instance ID keeps it unique per speaker.
     *
     * @return string Relative path of the cover file
     */
    private function GetCoverFile(): string
    {
        return 'media' . DIRECTORY_SEPARATOR . $this->InstanceID . '.jpg';
    }

    /**
     * Looks up the configured station whose StreamURL matches the
     * currently playing track URI - used both as a fallback cover (when
     * the stream itself provides no per-track album art) and to derive a
     * human-readable Album fallback from the user's own station naming.
     *
     * @param string $uri The track URI to look up.
     *
     * @return array{Name:string,StreamURL:string,IconURL:string}|null
     */
    private function FindStationByUri(string $uri): ?array
    {
        if ($uri === '') {
            return null;
        }

        $stations = json_decode($this->ReadAttributeString('ActiveStations'), true);
        if (!is_array($stations)) {
            return null;
        }

        foreach ($stations as $station) {
            $streamUrl = $station['StreamURL'] ?? '';
            // Sonos sometimes prefixes the TrackURI with an internal protocol
            // scheme (e.g. "aac://https://..."), so compare by suffix instead
            // of exact equality.
            if ($streamUrl !== '' && str_ends_with($uri, $streamUrl)) {
                return $station;
            }
        }

        return null;
    }

    /**
     * Downloads the given cover image and stores it in the Cover media
     * object, but only if the track actually changed since the last poll.
     * Comparing the track identity (not the cover URL) is necessary because
     * Sonos' "getaa" cover endpoint returns a static URL per speaker whose
     * image content changes per song - the URL itself never changes.
     *
     * Falls back to the bundled placeholder image (libs/noCover.png) if
     * no cover URL is available at all, or the download fails.
     *
     * @param string $coverUrl The URL of the cover image to download.
     * @param string $trackKey An identifier of the currently playing track (artist|title|album|uri).
     *
     * @return void
     */
    private function UpdateCover(string $coverUrl, string $trackKey): void
    {
        $this->LogDebug(__FUNCTION__, 'Update cover: ' . $coverUrl . ' (track key: ' . $trackKey . ')');
        if ($trackKey === $this->ReadAttributeString('LastTrackKey')) {
            $this->LogDebug(__FUNCTION__, 'Track has not changed since last poll, skipping cover update.');
            return; // same track as last poll, nothing to do
        }
        $this->LogDebug(__FUNCTION__, 'Cover URL: ' . $coverUrl);

        $content = false;

        if ($coverUrl !== '') {
            $this->LogDebug(__FUNCTION__, 'Downloading cover: ' . $coverUrl);
            // No 'ignore_errors': a 4xx/5xx response must return false instead of storing an error page as cover
            $context = stream_context_create(['http' => ['timeout' => 4]]);
            $content = @file_get_contents($coverUrl, false, $context);

            if ($content === false) {
                $this->LogDebug(__FUNCTION__, 'Failed to download cover: ' . $coverUrl);
            }
        }

        if ($content === false) {
            $content = @file_get_contents(__DIR__ . '/../imgs/noCover.png');
            if ($content === false) {
                $this->LogDebug(__FUNCTION__, 'Placeholder image imgs/noCover.png is missing.');
                return;
            }
        }

        $mediaID = @IPS_GetObjectIDByIdent('Cover', $this->InstanceID);
        if ($mediaID === false) {
            $this->LogDebug(__FUNCTION__, 'Media object not found for cover: ' . $coverUrl);
            return;
        }

        IPS_SetMediaContent($mediaID, base64_encode($content));
        IPS_SendMediaEvent($mediaID);

        $this->WriteAttributeString('LastTrackKey', $trackKey);
    }
}
