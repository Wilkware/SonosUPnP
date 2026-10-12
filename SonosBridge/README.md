# Sonos Bridge

[![Home](https://img.shields.io/badge/Home-wilkware.de-0b1830.svg?style=flat-square)](https://wilkware.de/module/sonos/)
[![Version](https://img.shields.io/badge/Symcon-PHP--Modul-red.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.0.20261012-orange.svg?style=flat-square)](https://github.com/Wilkware/SonosUPnP)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/SonosUPnP/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/SonosUPnP/actions)

Die Bridge ist die zentrale Instanz der Bibliothek. Sie erkennt die Sonos-Lautsprecher automatisch im lokalen Netzwerk und stellt den Lautsprecher-Instanzen deren aktuelle IP-Adresse sowie die globale Senderliste bereit.

## Inhaltverzeichnis

1. [Funktionsumfang](#user-content-1-funktionsumfang)
2. [Voraussetzungen](#user-content-2-voraussetzungen)
3. [Installation](#user-content-3-installation)
4. [Einrichtung](#user-content-4-einrichtung)
5. [Statusvariablen](#user-content-5-statusvariablen)
6. [Darstellungen](#user-content-6-darstellungen)
7. [Visualisierung](#user-content-7-visualisierung)
8. [Befehlsreferenz](#user-content-8-befehlsreferenz)
9. [Versionshistorie](#user-content-9-versionshistorie)

### 1. Funktionsumfang

* Automatische Erkennung aller Sonos-Lautsprecher im Netzwerk per SSDP (UPnP-Discovery).
* Auslesen der Gerätedaten (Raumname, Modell, Generation, Software-/Hardware-Version, Seriennummer, MAC- und IP-Adresse).
* Zuordnung der Lautsprecher über ihre eindeutige ID (UUID) statt über die IP-Adresse - per DHCP geänderte Adressen werden automatisch erkannt.
* Regelmäßige Netzwerksuche alle 5 Minuten sowie kurz nach dem Start von Symcon.
* Sofortige, zeitlich begrenzte Neusuche, sobald ein Lautsprecher unter seiner bekannten Adresse nicht antwortet.
* Lautsprecher, die vorübergehend nicht antworten (z. B. ausgeschaltet), bleiben 24 Stunden bekannt.
* Verwaltung einer globalen Senderliste (Internetradio), die von allen Lautsprecher-Instanzen genutzt wird, sofern diese keine eigene Liste haben.

### 2. Voraussetzungen

* Symcon ab Version 8.1
* UPnP in der Sonos-App aktiviert (_Einstellungen → System → Netzwerk → UPnP_, Pfad je nach App-Version)
* Sonos-Lautsprecher im selben Netzwerk (Subnetz) wie Symcon
* Multicast im Netzwerk erlaubt (SSDP, UDP-Port 1900) sowie Zugriff auf die Lautsprecher über TCP-Port 1400
* Bei Betrieb in einem Docker-Container: Host-Netzwerk (`network_mode: host`), da Multicast sonst nicht durchgereicht wird

### 3. Installation

* Über den Modul Store die Bibliothek _SonosUPnP_ installieren.
* Alternativ über das Modul Control folgende URL hinzufügen.  
`https://github.com/Wilkware/SonosUPnP` oder `git://github.com/Wilkware/SonosUPnP.git`

### 4. Einrichtung

* Unter 'Instanz hinzufügen' ist das _Sonos Bridge_-Modul unter dem Hersteller 'Sonos' aufgeführt.
* In der Regel wird die Bridge automatisch beim Anlegen des _Sonos Konfigurators_ als übergeordnete Instanz erzeugt.
* Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Einstellungsbereich:

> 📻 Globale Senderliste ...

Name                               | Beschreibung
---------------------------------- | -----------------------------------------------------------------
Name                               | Anzeigename des Senders (erscheint in der Senderauswahl und als Album bei Radiostreams)
Stream-URL                         | Direkte Adresse des Audio-Streams (z. B. MP3/AAC)
Icon-URL                           | Optionales Senderlogo, wird als Cover verwendet, wenn der Stream selbst keins liefert

Änderungen an der Liste werden beim Übernehmen automatisch an alle Lautsprecher-Instanzen weitergegeben.

_Aktionsbereich:_

> ❓ Abfrage von ...

Aktion                  | Beschreibung
----------------------- | ---------------------------------
LAUTSPRECHER            | Startet sofort eine Netzwerksuche und zeigt die gefundenen Lautsprecher (Raum, UUID, Modell, IP, MAC) an

### 5. Statusvariablen

Es werden keine Statusvariablen angelegt.

### 6. Darstellungen

Es werden keine Darstellungen oder Profile benötigt.

### 7. Visualisierung

Die Bridge hat keine eigene Darstellung in der Visualisierung.

### 8. Befehlsreferenz

```php
string WWXSU_GetPlayerIP(int $InstanzID, string $UUID);
```

Liefert die zuletzt bekannte IP-Adresse des Lautsprechers mit der übergebenen UUID (z. B. `RINCON_000E58xxxxxx01400`).  
Ist die UUID unbekannt, wird eine Neusuche eingeplant und eine Exception ausgelöst.

Beispiel:

```php
$ip = WWXSU_GetPlayerIP(12345, 'RINCON_000E58xxxxxx01400');
```

### 9. Versionshistorie

v1.0.20261012

* _NEU_: Initialversion

## Entwickler

Seit nunmehr über 10 Jahren fasziniert mich das Thema Haussteuerung. In den letzten Jahren betätige ich mich auch intensiv in der Symcon Community und steuere dort verschiedenste Skript und Module bei. Ihr findet mich dort unter dem Namen @pitti ;-)

[![GitHub](https://img.shields.io/badge/GitHub-@wilkware-181717.svg?style=for-the-badge&logo=github)](https://wilkware.github.io/)

## Spenden

Die Software ist für die nicht kommerzielle Nutzung kostenlos, über eine Spende bei Gefallen des Moduls würde ich mich freuen.

[![PayPal](https://img.shields.io/badge/PayPal-spenden-00457C.svg?style=for-the-badge&logo=paypal)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8816166)

## Lizenz

Namensnennung - Nicht-kommerziell - Weitergabe unter gleichen Bedingungen 4.0 International

[![Licence](https://img.shields.io/badge/License-CC_BY--NC--SA_4.0-EF9421.svg?style=for-the-badge&logo=creativecommons)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
