<?php

/**
 * PlayerHelper.php
 *
 * Part of the Library for Symcon Modules.
 *
 * @package       traits
 * @author        Heiko Wilknitz <heiko@wilkware.de>
 * @copyright     2026 Heiko Wilknitz
 * @link          https://wilkware.de
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

declare(strict_types=1);

/** @symcon-namespace */

namespace Wilkware\SonosUPnP;

/**
 * Shared playback logic of the SonosSpeaker and SonosGroup modules:
 * transport commands, station list, track metadata, cover and reachability.
 *
 * Requires the using module to implement GetAnchorUuid() and to register the
 * attributes "ActiveStations" and "LastTrackKey", the timer "UpdateStatus"
 * and the variables Playback, Artist, Title, Album and Playlist.
 */
trait PlayerHelper
{
    /** @var string Dataflow interface GUID (child -> Sonos Bridge, SendDataToParent/ForwardData) */
    private const SONOS_BRIDGE_GUID = '{48975ECF-9DF0-5CD7-F937-CEFCAFA4A4B9}';

    // Verified against IPS_GetVariableProfile('~PlaybackPreviousNext')['Associations']:
    // 0=Zurueck (Previous), 1=Stop, 2=Play, 3=Pause, 4=Weiter (Next).
    // This differs from the values used by the official Sonos Cloud module's
    // own custom profile - do NOT reuse those assumptions for this built-in profile.
    private const PLAYBACK_PREVIOUS = 0;
    private const PLAYBACK_STOP = 1;
    private const PLAYBACK_PLAY = 2;
    private const PLAYBACK_PAUSE = 3;
    private const PLAYBACK_NEXT = 4;

    /** @var int Default interval for updating the playback status (in seconds) */
    private const DEFAULT_UPDATE_INTERVAL = 10;

    /** @var int Poll interval while the speaker is unreachable (in seconds) */
    private const OFFLINE_UPDATE_INTERVAL = 30;

    /** @var int Instance status: speaker does not answer (reported as error) */
    private const STATUS_UNREACHABLE = 201;

    /** @var int Instance status: speaker does not answer, no error (IS_INACTIVE, e.g. intentionally powered off) */
    private const STATUS_OFFLINE = 104;

    /**
     * Returns the IP address transport and station commands are sent to
     * (the speaker itself, or the coordinator of a group).
     *
     * @return string|null IP address, or null if it could not be determined
     */
    abstract private function ResolveTransportIp(): ?string;

    /**
     * Returns the station list shown in the Playlist variable.
     *
     * @return array<int,array{Name:string,StreamURL:string,IconURL:string}>
     */
    abstract private function GetEffectiveStations(): array;

    /**
     * Returns a human readable label of the instance for log messages.
     *
     * @return string Label, e.g. "Speaker Wohnzimmer"
     */
    abstract private function GetLogLabel(): string;

    /**
     * Returns the UUID of the anchor speaker: the speaker itself, or the
     * main speaker of a group instance.
     *
     * @return string UUID (e.g. "RINCON_..."), empty if not configured
     */
    abstract private function GetAnchorUuid(): string;

    // -------------------------------------------------------------------------
    // Track metadata
    // -------------------------------------------------------------------------

    /**
     * Updates Playback, Artist, Title, Album and Cover from a track info
     * result of GetPlayerTrackInfo().
     *
     * @param array{state:string,uri:string,title:string,artist:string,album:string,coverUrl:string,stationTitle:string} $trackInfo Track info
     *
     * @return void
     */
    private function UpdateTrackVariables(array $trackInfo): void
    {
        // This presentation reflects the raw current transport state
        // directly. Sonos reports several distinct states here
        // (PLAYING, PAUSED_PLAYBACK, STOPPED, TRANSITIONING,
        // NO_MEDIA_PRESENT) - map them explicitly instead of collapsing
        // everything non-PLAYING into Pause. TRANSITIONING is a short
        // intermediate state (e.g. right after Play) and keeps the
        // current value, otherwise it would briefly flip to Stop.
        $playback = match ($trackInfo['state']) {
            'PLAYING'         => self::PLAYBACK_PLAY,
            'PAUSED_PLAYBACK' => self::PLAYBACK_PAUSE,
            'TRANSITIONING'   => null,
            default           => self::PLAYBACK_STOP // STOPPED, NO_MEDIA_PRESENT, unknown
        };
        if ($playback !== null) {
            $this->SetValueInteger('Playback', $playback);
        }

        if ($this->IsTransientMetadata($trackInfo['title']) || $this->IsTransientMetadata($trackInfo['artist'])) {
            // While connecting/buffering a stream Sonos reports internal
            // placeholders like "ZPSTR_CONNECTING" - keep the previous values.
            $this->LogDebug(__FUNCTION__, 'Ignoring transient metadata: ' . $trackInfo['artist'] . ' - ' . $trackInfo['title']);
            return;
        }

        if ($this->LooksLikeJunkMetadata($trackInfo['title'])) {
            // TuneIn sometimes reports an ad-bumper tracking URL as the
            // "title" during ad breaks (e.g. "...mp3?aw_0_1st...&..."),
            // instead of real track info. Keep the previous values
            // rather than overwriting them with tracking junk.
            $this->LogDebug(__FUNCTION__, 'Ignoring junk metadata during ad break: ' . $trackInfo['title']);
            return;
        }

        $matchedStation = $this->FindStationByUri($trackInfo['uri']);

        // For radio broadcasts (stationTitle set), upnp:album is
        // usually empty - fall back to the user's own configured
        // station name first ("Arabella"), then the raw technical
        // station title as a last resort ("radioarabella-muenchen...").
        // An ad-bumper URL as station title is never used.
        $stationTitle = $this->LooksLikeJunkMetadata($trackInfo['stationTitle']) ? '' : $trackInfo['stationTitle'];
        $album = $trackInfo['album'];
        if ($album === '' && $trackInfo['stationTitle'] !== '') {
            $album = $matchedStation['Name'] ?? $stationTitle;
        }

        $this->SetValueString('Artist', $trackInfo['artist']);
        $this->SetValueString('Title', $trackInfo['title']);
        $this->SetValueString('Album', $album);

        $trackKey = $trackInfo['artist'] . '|' . $trackInfo['title'] . '|' . $album . '|' . $trackInfo['uri'];
        $coverUrl = $trackInfo['coverUrl'] !== '' ? $trackInfo['coverUrl'] : ($matchedStation['IconURL'] ?? '');
        $this->UpdateCover($coverUrl, $trackKey);
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
     * Detects the internal placeholders Sonos reports while a stream is
     * connecting or buffering (e.g. "ZPSTR_CONNECTING", "ZPSTR_BUFFERING").
     *
     * @param string $value Metadata value to check.
     *
     * @return bool True if the value is a transient Sonos placeholder.
     */
    private function IsTransientMetadata(string $value): bool
    {
        return str_contains($value, 'ZPSTR_');
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
        $ip = $this->ResolveTransportIp();
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
     * Handles playlist control actions.
     *
     * @param int $index Index of the station to play.
     *
     * @return void
     */
    private function HandlePlaylistAction(int $index): void
    {
        $ip = $this->ResolveTransportIp();
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
    // Reachability
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
     * Returns whether the instance is currently in one of the "unreachable" states.
     *
     * @return bool True if status is 201 (error) or 104 (inactive)
     */
    private function IsOffline(): bool
    {
        return in_array($this->GetStatus(), [self::STATUS_UNREACHABLE, self::STATUS_OFFLINE], true);
    }

    /**
     * Returns the status an unreachable instance should show, depending on
     * the property OfflineAsError.
     *
     * @return int 201 (error) or 104 (inactive)
     */
    private function GetOfflineStatus(): int
    {
        return $this->ReadPropertyBoolean('OfflineAsError') ? self::STATUS_UNREACHABLE : self::STATUS_OFFLINE;
    }

    /**
     * Returns the current poll interval: the configured one, or the slower
     * backoff interval while the instance is unreachable.
     *
     * @return int Poll interval in seconds
     */
    private function GetCurrentPollInterval(): int
    {
        return $this->IsOffline() ? max(self::OFFLINE_UPDATE_INTERVAL, $this->GetUpdateInterval()) : $this->GetUpdateInterval();
    }

    /**
     * Sets the instance status in ApplyChanges: an unreachable instance keeps
     * its offline state (mapped to the current OfflineAsError setting), so the
     * form does not briefly show "Active" until the next poll.
     *
     * @return void
     */
    private function ApplyReachabilityStatus(): void
    {
        $this->SetStatus($this->IsOffline() ? $this->GetOfflineStatus() : 102);
    }

    /**
     * Switches the instance into the "unreachable" state: status 201 (error)
     * or 104 (inactive, see property OfflineAsError), playback shown as
     * stopped and a slower poll interval (backoff). Only logs once per outage.
     *
     * @param string $reason Reason for the log entry
     *
     * @return void
     */
    private function SetOffline(string $reason): void
    {
        if ($this->IsOffline()) {
            $this->LogDebug(__FUNCTION__, 'Still unreachable: ' . $reason);
            return;
        }

        $asError = $this->ReadPropertyBoolean('OfflineAsError');
        $this->LogMessage(sprintf('%s is not reachable: %s', $this->GetLogLabel(), $reason), $asError ? KL_WARNING : KL_NOTIFY);
        $this->SetStatus($this->GetOfflineStatus());
        $this->SetValueInteger('Playback', self::PLAYBACK_STOP);
        $this->SetTimerInterval('UpdateStatus', $this->GetCurrentPollInterval() * 1000);
    }

    /**
     * Leaves the "unreachable" state: status 102, regular poll interval,
     * station list and cover are refreshed.
     *
     * @return void
     */
    private function SetOnline(): void
    {
        $this->LogMessage(sprintf('%s is reachable again', $this->GetLogLabel()), KL_NOTIFY);
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
            'UUID'     => $this->GetAnchorUuid()
        ]));
    }

    // -------------------------------------------------------------------------
    // Grouping
    // -------------------------------------------------------------------------

    /**
     * Finds the group the anchor speaker (GetAnchorUuid) belongs to and
     * determines the IP address of its coordinator.
     *
     * @param array<int,array{coordinator:string,members:array<int,array{uuid:string,zoneName:string,ip:string}>}> $groups Group list of GetZoneGroups()
     *
     * @return array{coordinator:string,members:array<int,array{uuid:string,zoneName:string,ip:string}>,ip:string}|null
     *         The group including the coordinator IP, or null if not found
     */
    private function FindGroup(array $groups): ?array
    {
        $uuid = $this->GetAnchorUuid();

        foreach ($groups as $group) {
            if (!in_array($uuid, array_column($group['members'], 'uuid'), true)) {
                continue;
            }

            $ip = '';
            foreach ($group['members'] as $member) {
                if ($member['uuid'] === $group['coordinator']) {
                    $ip = $member['ip'];
                }
            }
            // Fall back to the bridge if the topology carries no usable location
            if ($ip === '') {
                $ip = $this->ResolvePlayerIp($group['coordinator']) ?? '';
            }
            if ($ip === '') {
                return null;
            }

            $group['ip'] = $ip;
            return $group;
        }

        return null;
    }

    /**
     * Determines the coordinator of the group the configured speaker
     * currently belongs to. A speaker playing on its own is its own coordinator.
     *
     * @param string $ip IP address of the configured speaker (any player knows the full topology)
     *
     * @return array{uuid:string,ip:string}|null Coordinator, or null if the topology could not be read
     */
    private function ResolveCoordinator(string $ip): ?array
    {
        $groups = $this->GetZoneGroups($ip);
        if ($groups === false) {
            return null;
        }

        $group = $this->FindGroup($groups);
        if ($group === null) {
            return null;
        }

        return ['uuid' => $group['coordinator'], 'ip' => $group['ip']];
    }

    // -------------------------------------------------------------------------
    // Bridge communication
    // -------------------------------------------------------------------------

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
     * Resolves the current IP address of a speaker via SonosBridge.
     * Returns null (and logs) if the UUID is not configured or the
     * bridge could not resolve it (e.g. device offline).
     *
     * @param string $uuid UUID of the speaker, empty for the anchor speaker
     *
     * @return string|null The resolved IP address, or null if not available.
     */
    private function ResolvePlayerIp(string $uuid = ''): ?string
    {
        if ($uuid === '') {
            $uuid = $this->GetAnchorUuid();
        }
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
     * Returns the global station list of SonosBridge.
     *
     * @return array<int,array{Name:string,StreamURL:string,IconURL:string}>
     */
    private function GetGlobalStations(): array
    {
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

    // -------------------------------------------------------------------------
    // Station list
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Cover
    // -------------------------------------------------------------------------

    /**
     * Creates the Cover media object (once) and fills it with the
     * placeholder image, so the media file exists right from the start.
     *
     * @param int $position Position of the media object
     *
     * @return void
     */
    private function CreateCoverMedia(int $position): void
    {
        // Following the same pattern as the official Sonos Cloud module.
        if (@IPS_GetObjectIDByIdent('Cover', $this->InstanceID)) {
            return;
        }

        $mediaID = IPS_CreateMedia(1);
        IPS_SetParent($mediaID, $this->InstanceID);
        IPS_SetIdent($mediaID, 'Cover');
        IPS_SetMediaCached($mediaID, true);
        // Connect to file
        IPS_SetMediaFile($mediaID, $this->GetCoverFile(), false);
        IPS_SetPosition($mediaID, $position);
        IPS_SetName($mediaID, $this->Translate('Cover'));
        // Write the placeholder right away, otherwise the media file does not exist until the first track change
        $placeholder = @file_get_contents(__DIR__ . '/../imgs/noCover.png');
        if ($placeholder !== false) {
            IPS_SetMediaContent($mediaID, base64_encode($placeholder));
        } else {
            $this->LogDebug(__FUNCTION__, 'Placeholder image imgs/noCover.png is missing.');
        }
    }

    /**
     * Returns the media file of the cover image, relative to the kernel
     * directory. The instance ID keeps it unique per instance.
     *
     * @return string Relative path of the cover file
     */
    private function GetCoverFile(): string
    {
        return 'media' . DIRECTORY_SEPARATOR . $this->InstanceID . '.jpg';
    }

    /**
     * Downloads the given cover image and stores it in the Cover media
     * object, but only if the track actually changed since the last poll.
     * Comparing the track identity (not the cover URL) is necessary because
     * Sonos' "getaa" cover endpoint returns a static URL per speaker whose
     * image content changes per song - the URL itself never changes.
     *
     * Falls back to the bundled placeholder image (imgs/noCover.png) if
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

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    /**
     * Shows the result of a manual status poll in a popup: either the
     * error text or a summary of the given status variables.
     *
     * @param string        $error  Error text of the poll, empty on success
     * @param array<string> $idents Idents of the variables to list
     *
     * @return void
     */
    private function ShowStatusPopup(string $error, array $idents): void
    {
        if ($error !== '') {
            $this->EchoMessage($error);
            return;
        }

        $msg = '';
        foreach ($idents as $ident) {
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
}
