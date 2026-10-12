<?php

/**
 * SonosHelper.php
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
 * Helper class for the sonos upnp communication.
 */
trait SonosHelper
{
    /** @var string Sonos UPnP Service-Typ, which the SonosSpeaker needs for SOAP-Calls */
    public const SONOS_SERVICE_AVTRANSPORT = 'urn:schemas-upnp-org:service:AVTransport:1';

    /** @var string Sonos UPnP Service-Typ, which the SonosSpeaker needs for SOAP-Calls */
    public const SONOS_SERVICE_RENDERING = 'urn:schemas-upnp-org:service:RenderingControl:1';

    /** @var string Sonos UPnP Service-Typ, which the SonosSpeaker needs for SOAP-Calls */
    public const SONOS_SERVICE_GROUP_MGMT = 'urn:schemas-upnp-org:service:GroupManagement:1';

    /** @var string Sonos UPnP Service-Typ, which the SonosSpeaker needs for SOAP-Calls */
    public const SONOS_SERVICE_ZONE_TOPOLOGY = 'urn:schemas-upnp-org:service:ZoneGroupTopology:1';

    /** @var int Sonos UPnP Port */
    public const SONOS_UPNP_PORT = 1400;

    /**
     * Performs an SSDP scan of the local network.
     *
     * @return array<int,array{uuid:string,ip:string,roomName:string,modelName:string,modelGeneration:string,softwareVersion:string,hardwareVersion:string,serialNumber:string,macAddress:string,descriptionUrl:string}>|false
     *         Array of discovered players, or false if the scan itself failed
     *         (e.g. socket could not be created/sent). An empty array is a
     *         valid, successful result meaning "scan ran, nothing found".
     */
    private function ScanNetwork(): array|false
    {
        $client = new \Wilkware\SonosUPnP\SsdpClient();

        try {
            $devices = $client->discover();
        } catch (\Wilkware\SonosUPnP\SsdpDiscoveryException) {
            return false;
        }

        $players = [];
        foreach ($devices as $device) {
            $players[] = [
                'uuid'            => $device->uuid,
                'ip'              => $device->ip,
                'roomName'        => $device->roomName,
                'modelName'       => $device->modelName,
                'modelGeneration' => $device->modelGeneration,
                'softwareVersion' => $device->softwareVersion,
                'hardwareVersion' => $device->hardwareVersion,
                'serialNumber'    => $device->serialNumber,
                'macAddress'      => $device->macAddress,
                'descriptionUrl'  => $device->descriptionUrl
            ];
        }

        return $players;
    }

    /**
     * Sends a transport command (play/pause/stop/next/previous) to a speaker.
     *
     * @return bool true on success, false if the SOAP call failed
     */
    private function SendTransportCommand(string $ip, string $command): bool
    {
        try {
            $client = new \Wilkware\SonosUPnP\SonosSoapClient($ip);
            match ($command) {
                'play'     => $client->play(),
                'pause'    => $client->pause(),
                'stop'     => $client->stop(),
                'next'     => $client->next(),
                'previous' => $client->previous(),
                default    => throw new \InvalidArgumentException('Unknown transport command: ' . $command)
            };
            return true;
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return false;
        }
    }

    /**
     * Loads a stream/track URI into the transport and starts playback.
     *
     * @return bool true on success, false if the SOAP call failed
     */
    private function PlayStreamUri(string $ip, string $uri, string $title): bool
    {
        try {
            (new \Wilkware\SonosUPnP\SonosSoapClient($ip))->playUri($uri, $title);
            return true;
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves the current track information from a player.
     *
     * @return array{state:string,uri:string,title:string,artist:string,album:string,coverUrl:string,stationTitle:string}|false
     */
    private function GetPlayerTrackInfo(string $ip): array|false
    {
        try {
            return (new \Wilkware\SonosUPnP\SonosSoapClient($ip))->getTrackInfo();
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return false;
        }
    }

    /**
     * Sets the volume of a player.
     *
     * @param string $ip     IP address of the Sonos player
     * @param int    $volume Volume level (0-100)
     *
     * @return bool true on success, false if the SOAP call failed
     */
    private function SetPlayerVolume(string $ip, int $volume): bool
    {
        try {
            (new \Wilkware\SonosUPnP\SonosSoapClient($ip))->setVolume($volume);
            return true;
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves the current volume level of a player.
     *
     * @param string $ip IP address of the Sonos player
     *
     * @return int|false Volume level (0-100) on success, false if the SOAP call failed
     */
    private function GetPlayerVolume(string $ip): int|false
    {
        try {
            return (new \Wilkware\SonosUPnP\SonosSoapClient($ip))->getVolume();
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return false;
        }
    }

    /**
     * Sets the mute state of a player.
     *
     * @param string $ip   IP address of the Sonos player
     * @param bool   $mute True to mute, false to unmute
     *
     * @return bool true on success, false if the SOAP call failed
     */
    private function SetPlayerMute(string $ip, bool $mute): bool
    {
        try {
            (new \Wilkware\SonosUPnP\SonosSoapClient($ip))->setMute($mute);
            return true;
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves the current mute state of a player.
     *
     * @param string $ip IP address of the Sonos player
     *
     * @return bool|null true if muted, false if not muted, null if the SOAP call failed
     */
    private function GetPlayerMute(string $ip): bool|null
    {
        try {
            return (new \Wilkware\SonosUPnP\SonosSoapClient($ip))->getMute();
        } catch (\Wilkware\SonosUPnP\SonosSoapException $e) {
            $this->LogDebug(__FUNCTION__, $e->getMessage());
            return null;
        }
    }

}

/**
 * Thrown when the SSDP discovery itself fails (socket could not be created or no packet could be sent).
 */
class SsdpDiscoveryException extends \RuntimeException
{
}

/**
 * SsdpDevice
 *
 * Immutable data object describing one discovered Sonos device.
 */
class SsdpDevice
{
    /**
     * Creates a new SsdpDevice instance.
     *
     * @param string $uuid            Unique device ID (e.g. "RINCON_...")
     * @param string $ip              IP address the device answered from
     * @param string $roomName        Room name configured in the Sonos app
     * @param string $modelName       Model name (e.g. "Sonos One")
     * @param string $modelGeneration Software generation (S1/S2)
     * @param string $softwareVersion Installed firmware version
     * @param string $hardwareVersion Hardware revision
     * @param string $serialNumber    Serial number
     * @param string $macAddress      MAC address of the network interface
     * @param string $descriptionUrl  URL of the device description XML
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $ip,
        public readonly string $roomName,
        public readonly string $modelName,
        public readonly string $modelGeneration,
        public readonly string $softwareVersion,
        public readonly string $hardwareVersion,
        public readonly string $serialNumber,
        public readonly string $macAddress,
        public readonly string $descriptionUrl
    ) {
    }
}

/**
 * SsdpClient
 *
 * Performs an SSDP M-SEARCH multicast scan on the local network and
 * then reads the Sonos device description (XML) of each
 * responding device to determine the UUID and room name.
 */
class SsdpClient
{
    /** @var string Multicast address for SSDP */
    private const MULTICAST_ADDRESS = '239.255.255.250';

    /** @var int Multicast port for SSDP */
    private const MULTICAST_PORT = 1900;

    /** @var string Sonos search target for SSDP */
    private const SONOS_SEARCH_TARGET = 'urn:schemas-upnp-org:device:ZonePlayer:1';

    /** @var int Number of M-SEARCH packets sent per scan (UDP has no delivery guarantee) */
    private const SEARCH_REPEAT = 3;

    /** @var int Pause between two M-SEARCH packets (in microseconds) */
    private const SEARCH_REPEAT_GAP = 300000;

    /** @var int Multicast TTL, enough for one router hop as recommended by the UPnP spec */
    private const MULTICAST_TTL = 2;

    /**
     * Creates a new SsdpClient instance.
     *
     * @param int $timeoutSeconds Timeout in seconds for the SSDP discovery process
     * @param int $httpTimeoutSeconds Timeout in seconds for HTTP requests to fetch device descriptions
     */
    public function __construct(
        private int $timeoutSeconds = 3,
        private int $httpTimeoutSeconds = 2
    ) {
    }

    /**
     * Sends an M-SEARCH and collects all Sonos responses within
     * $timeoutSeconds. Returns a list of unique devices
     * (deduplicated by UUID), including resolved metadata.
     *
     * @return SsdpDevice[]
     */
    public function discover(): array
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new SsdpDiscoveryException('Unable to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, self::MULTICAST_TTL);

        $searchMessage = "M-SEARCH * HTTP/1.1\r\n"
            . 'HOST: ' . self::MULTICAST_ADDRESS . ':' . self::MULTICAST_PORT . "\r\n"
            . "MAN: \"ssdp:discover\"\r\n"
            . "MX: 2\r\n"
            . 'ST: ' . self::SONOS_SEARCH_TARGET . "\r\n"
            . "\r\n";

        // Send several times - a speaker waking up from standby easily misses a single packet.
        // The scan only fails if not a single packet could be sent.
        $sentCount = 0;
        $lastError = 0;
        for ($i = 0; $i < self::SEARCH_REPEAT; $i++) {
            if ($i > 0) {
                usleep(self::SEARCH_REPEAT_GAP);
            }
            if (@socket_sendto($socket, $searchMessage, strlen($searchMessage), 0, self::MULTICAST_ADDRESS, self::MULTICAST_PORT) === false) {
                $lastError = socket_last_error($socket);
            } else {
                $sentCount++;
            }
        }

        if ($sentCount === 0) {
            socket_close($socket);
            throw new SsdpDiscoveryException('M-SEARCH could not be sent: ' . socket_strerror($lastError));
        }

        // Responses to earlier packets are already queued in the socket buffer
        $locations = [];
        $endTime = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $endTime) {
            $buffer = '';
            $fromIp = '';
            $fromPort = 0;

            $bytes = @socket_recvfrom($socket, $buffer, 4096, 0, $fromIp, $fromPort);

            if ($bytes === false || $bytes === 0) {
                continue; // Timeout on this reception attempt; continue waiting until endTime
            }

            $location = $this->extractHeader($buffer, 'LOCATION');
            if ($location !== null) {
                $locations[$location] = $fromIp; // deduplicated by LOCATION-URL
            }
        }

        socket_close($socket);

        $devices = [];
        foreach ($locations as $location => $ip) {
            $device = $this->fetchDeviceDescription($location, $ip);
            if ($device !== null) {
                $devices[$device->uuid] = $device; // deduplicated by UUID (Group Coordinator answers sometimes multiple times)
            }
        }

        return array_values($devices);
    }

    /**
     * Extracts the value of an HTTP header from a raw SSDP response.
     *
     * @param string $response   Raw SSDP response
     * @param string $headerName Header name (case-insensitive)
     *
     * @return string|null Header value, or null if the header is missing
     */
    private function extractHeader(string $response, string $headerName): ?string
    {
        if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.+)\r?$/mi', $response, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Loads and parses the device description XML of a responding device.
     *
     * @param string $url        LOCATION URL from the SSDP response
     * @param string $fallbackIp IP address the SSDP response came from
     *
     * @return SsdpDevice|null Device data, or null if the description could not be loaded or parsed
     */
    private function fetchDeviceDescription(string $url, string $fallbackIp): ?SsdpDevice
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $this->httpTimeoutSeconds
            ]
        ]);

        $xml = @file_get_contents($url, false, $context);
        if ($xml === false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($doc === false || !isset($doc->device)) {
            return null;
        }

        // Direct property navigation to the outer <device> element only.
        // The description also contains nested MediaServer/MediaRenderer
        // <device> elements inside <deviceList> - those must be ignored,
        // which an unqualified "//device" XPath would not reliably do.
        $device = $doc->device;

        $udn = (string) $device->UDN;
        if ($udn === '') {
            return null;
        }

        $uuid = str_replace('uuid:', '', $udn);
        $roomName = (string) $device->roomName;
        $modelName = (string) $device->modelName;
        $modelGeneration = (string) $device->swGen;
        $softwareVersion = (string) $device->softwareVersion;
        $hardwareVersion = (string) $device->hardwareVersion;
        $serialNumber = (string) $device->serialNum;
        $macAddress = (string) $device->MACAddress;

        return new SsdpDevice(
            uuid: $uuid,
            ip: $fallbackIp,
            roomName: $roomName !== '' ? $roomName : $uuid,
            modelName: $modelName,
            modelGeneration: $modelGeneration,
            softwareVersion: $softwareVersion,
            hardwareVersion: $hardwareVersion,
            serialNumber: $serialNumber,
            macAddress: $macAddress,
            descriptionUrl: $url
        );
    }
}

/**
 * Thrown when a SOAP call to a Sonos device fails (network error or UPnP fault).
 */
class SonosSoapException extends \RuntimeException
{
}

/**
 * SonosSoapClient
 *
 * Pure UPnP/SOAP client for the two Sonos services needed for playback
 * control: AVTransport (play/pause/skip/queue a URI) and RenderingControl
 * (volume/mute). Knows nothing about IPSModule - only builds SOAP
 * envelopes, sends them via HTTP POST and parses the XML response.
 */
class SonosSoapClient
{
    /** @var array{type: string, controlUrl: string} AVTransport service definition */
    private const SERVICE_AVTRANSPORT = [
        'type'       => 'urn:schemas-upnp-org:service:AVTransport:1',
        'controlUrl' => '/MediaRenderer/AVTransport/Control'
    ];

    /** @var array{type: string, controlUrl: string} RenderingControl service definition */
    private const SERVICE_RENDERING = [
        'type'       => 'urn:schemas-upnp-org:service:RenderingControl:1',
        'controlUrl' => '/MediaRenderer/RenderingControl/Control'
    ];

    /**
     * Creates a new SonosSoapClient instance.
     *
     * @param string $ip             IP address of the Sonos device
     * @param int    $port           Port number (default 1400)
     * @param int    $timeoutSeconds Timeout in seconds for HTTP requests (default 3)
     */
    public function __construct(
        private string $ip,
        private int $port = 1400,
        private int $timeoutSeconds = 3
    ) {
    }

    /**
     * Sends a Play command to the device.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function play(): void
    {
        $this->callAvTransport('Play', ['Speed' => '1']);
    }

    /**
     * Pauses playback on the device.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function pause(): void
    {
        $this->callAvTransport('Pause', []);
    }

    /**
     * Stops playback on the device.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function stop(): void
    {
        $this->callAvTransport('Stop', []);
    }

    /**
     * Skips to the next track.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function next(): void
    {
        $this->callAvTransport('Next', []);
    }

    /**
     * Skips to the previous track.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function previous(): void
    {
        $this->callAvTransport('Previous', []);
    }

    /**
     * Loads a stream/track URI into the transport and starts playback.
     * DIDL-Lite metadata is intentionally minimal (title only) - Sonos
     * fills in artist/album/cover art itself once the stream provides it.
     *
     * @param string $uri   URI of the stream/track to play
     * @param string $title Title of the stream/track (used in the Sonos
     *
     * @return void
     *
     * @throws SonosSoapException if the SOAP call fails
     */
    public function playUri(string $uri, string $title): void
    {
        $didl = $this->buildDidlLite($uri, $title);

        $this->callAvTransport('SetAVTransportURI', [
            'CurrentURI'         => $uri,
            'CurrentURIMetaData' => $didl
        ]);

        $this->play();
    }

    /**
     * Retrieves information about the currently playing track.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return array{state:string,uri:string,title:string,artist:string,album:string,coverUrl:string,stationTitle:string}
     */
    public function getTrackInfo(): array
    {
        $transportInfo = $this->callAvTransport('GetTransportInfo', []);
        $positionInfo = $this->callAvTransport('GetPositionInfo', ['Unit' => 'REL_TIME']);

        $metadata = $positionInfo['TrackMetaData'] ?? '';
        $parsed = $this->parseDidlLite($metadata);

        return [
            'state'        => $transportInfo['CurrentTransportState'] ?? 'STOPPED',
            'uri'          => $positionInfo['TrackURI'] ?? '',
            'title'        => $parsed['title'],
            'artist'       => $parsed['artist'],
            'album'        => $parsed['album'],
            'coverUrl'     => $this->resolveCoverUrl($parsed['coverUrl']),
            'stationTitle' => $parsed['stationTitle']
        ];
    }

    /**
     * Sets the volume of the device.
     *
     * @param int $volume Volume level (0-100)
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function setVolume(int $volume): void
    {
        $this->callRenderingControl('SetVolume', [
            'Channel'        => 'Master',
            'DesiredVolume'  => (string) max(0, min(100, $volume))
        ]);
    }

    /**
     * Retrieves the current volume level.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return int Volume level (0-100)
     */
    public function getVolume(): int
    {
        $result = $this->callRenderingControl('GetVolume', ['Channel' => 'Master']);
        return (int) ($result['CurrentVolume'] ?? 0);
    }

    /**
     * Retrieves the current mute state.
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return bool Mute state (true for muted, false for unmuted)
     */
    public function getMute(): bool
    {
        $result = $this->callRenderingControl('GetMute', ['Channel' => 'Master']);
        return (string) ($result['CurrentMute'] ?? '0') === '1';
    }

    /**
     * Sets the mute state of the device.
     *
     * @param bool $mute Mute state (true for muted, false for unmuted)
     *
     * @throws SonosSoapException if the SOAP call fails
     *
     * @return void
     */
    public function setMute(bool $mute): void
    {
        $this->callRenderingControl('SetMute', [
            'Channel'      => 'Master',
            'DesiredMute'  => $mute ? '1' : '0'
        ]);
    }

    /**
     * Calls the AVTransport service.
     *
     * @param string $action SOAP action to call
     * @param array<string,string> $params Parameters for the SOAP action
     *
     * @return array<string,string>
     */
    private function callAvTransport(string $action, array $params): array
    {
        return $this->call(self::SERVICE_AVTRANSPORT, $action, $params);
    }

    /**
     * Calls the RenderingControl service.
     *
     * @param string $action SOAP action to call
     * @param array<string,string> $params Parameters for the SOAP action
     *
     * @return array<string,string>
     */
    private function callRenderingControl(string $action, array $params): array
    {
        return $this->call(self::SERVICE_RENDERING, $action, $params);
    }

    /**
     * Performs a SOAP call to the specified service and action with the given parameters.
     *
     * @param array{type:string,controlUrl:string} $service Service definition (type and control URL)
     * @param string $action SOAP action to call
     * @param array<string,string> $params Parameters for the SOAP action
     *
     * @return array<string,string> Flattened response arguments
     */
    private function call(array $service, string $action, array $params): array
    {
        // InstanceID is required by every AVTransport/RenderingControl action
        $params = array_merge(['InstanceID' => '0'], $params);

        $argsXml = '';
        foreach ($params as $key => $value) {
            $argsXml .= sprintf('<%s>%s</%s>', $key, htmlspecialchars((string) $value, ENT_XML1), $key);
        }

        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" '
            . 's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body>'
            . '<u:' . $action . ' xmlns:u="' . $service['type'] . '">'
            . $argsXml
            . '</u:' . $action . '>'
            . '</s:Body>'
            . '</s:Envelope>';

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: text/xml; charset=\"utf-8\"\r\n"
                    . 'SOAPACTION: "' . $service['type'] . '#' . $action . '"',
                'content'       => $envelope,
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true
            ]
        ]);

        $url = sprintf('http://%s:%d%s', $this->ip, $this->port, $service['controlUrl']);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new SonosSoapException(sprintf('SOAP call %s to %s failed (no response).', $action, $this->ip));
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($response);
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw new SonosSoapException(sprintf('SOAP call %s to %s returned invalid XML.', $action, $this->ip));
        }

        $body = $doc->children('http://schemas.xmlsoap.org/soap/envelope/')->Body;
        $fault = $body->children('http://schemas.xmlsoap.org/soap/envelope/')->Fault ?? null;

        if ($fault !== null && (string) $fault->faultcode !== '') {
            throw new SonosSoapException(sprintf(
                'SOAP call %s to %s returned a fault: %s',
                $action,
                $this->ip,
                (string) ($fault->detail->UPnPError->errorDescription ?? $fault->faultstring ?? 'unknown')
            ));
        }

        // The response element itself (e.g. <u:GetTransportInfoResponse>) lives
        // in the service's namespace (prefix "u"), but its own children
        // (CurrentTransportState, etc.) have no namespace of their own -
        // so we must navigate into the service namespace explicitly first,
        // then iterate children() without a namespace filter.
        $responseElement = $body->children($service['type'])->{$action . 'Response'};

        $result = [];
        foreach ($responseElement->children() as $key => $value) {
            $result[$key] = (string) $value;
        }

        return $result;
    }

    /**
     * Builds a minimal DIDL-Lite XML string for a given URI and title.
     *
     * @param string $uri   URI of the stream/track
     * @param string $title Title of the stream/track
     *
     * @return string DIDL-Lite XML string
     */
    private function buildDidlLite(string $uri, string $title): string
    {
        $didl = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">'
            . '<item id="1" parentID="0" restricted="1">'
            . '<dc:title>' . htmlspecialchars($title, ENT_XML1) . '</dc:title>'
            . '<upnp:class>object.item.audioItem.audioBroadcast</upnp:class>'
            . '<res protocolInfo="http-get:*:audio/mpeg:*">' . htmlspecialchars($uri, ENT_XML1) . '</res>'
            . '</item>'
            . '</DIDL-Lite>';

        return htmlspecialchars($didl, ENT_XML1);
    }

    /**
     * Parses a DIDL-Lite XML string and extracts relevant information.
     *
     * @param string $didl DIDL-Lite XML string
     *
     * @return array{title:string,artist:string,album:string,coverUrl:string,stationTitle:string}
     */
    private function parseDidlLite(string $didl): array
    {
        $empty = ['title' => '', 'artist' => '', 'album' => '', 'coverUrl' => '', 'stationTitle' => ''];

        if ($didl === '' || $didl === 'NOT_IMPLEMENTED') {
            return $empty;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($didl);
        libxml_use_internal_errors($previous);

        if ($doc === false || !isset($doc->item)) {
            return $empty;
        }

        $dc = $doc->item->children('http://purl.org/dc/elements/1.1/');
        $upnp = $doc->item->children('urn:schemas-upnp-org:metadata-1-0/upnp/');
        $rincon = $doc->item->children('urn:schemas-rinconnetworks-com:metadata-1-0/');

        $title = (string) $dc->title;
        $artist = (string) $upnp->artist ?: (string) $dc->creator;
        $stationTitle = '';

        // For internet radio (TuneIn etc.), dc:title is just the station
        // name and the actual "Artist - Song" text for what's currently
        // playing lives in the Sonos-specific r:streamContent extension.
        $streamContent = (string) $rincon->streamContent;
        if ($streamContent !== '') {
            $stationTitle = $title; // preserve before it gets overwritten below

            if (str_contains($streamContent, ' - ')) {
                [$streamArtist, $streamTitle] = array_map('trim', explode(' - ', $streamContent, 2));
                $artist = $streamArtist;
                $title = $streamTitle;
            } else {
                $title = $streamContent;
            }
        }

        return [
            'title'        => $title,
            'artist'       => $artist,
            'album'        => (string) $upnp->album,
            'coverUrl'     => (string) $upnp->albumArtURI,
            // Raw station name (technical, e.g. "radioarabella-muenchen?..."),
            // only set for radio broadcasts. Last-resort fallback for the
            // Album field when the station isn't in the user's own list.
            'stationTitle' => $stationTitle
        ];
    }

    /**
     * Sonos typically returns a relative path (e.g. "/getaa?s=1&u=...") for
     * the album art, meant to be fetched from the speaker's own HTTP
     * server. Absolute URLs (streaming services with their own CDN) are
     * passed through unchanged.
     *
     * @param string $url Album art URL (relative or absolute)
     *
     * @return string Resolved absolute URL for the album art
     */
    private function resolveCoverUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return sprintf('http://%s:%d%s', $this->ip, $this->port, $url);
    }
}