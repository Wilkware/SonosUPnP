# Sonos Konfigurator

[![Home](https://img.shields.io/badge/Home-wilkware.de-0b1830.svg?style=flat-square)](https://wilkware.de/module/sonos/)
[![Version](https://img.shields.io/badge/Symcon-PHP--Modul-red.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.0.20261012-orange.svg?style=flat-square)](https://github.com/Wilkware/SonosUPnP)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/SonosUPnP/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/SonosUPnP/actions)

Der Konfigurator listet alle von der _Sonos Bridge_ gefundenen Lautsprecher auf und legt für jeden Lautsprecher per Klick eine passende _Sonos Lautsprecher_-Instanz an.

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

* Übersicht aller im Netzwerk gefundenen Sonos-Lautsprecher mit Raumname, Modell, Generation, Software-/Hardware-Version, Seriennummer, MAC- und IP-Adresse.
* Anlegen einer _Sonos Lautsprecher_-Instanz per Klick, vorkonfiguriert mit UUID und Raumname.
* Bereits vorhandene Instanzen werden erkannt und in der Liste zugeordnet (Prüfen statt doppelt Anlegen).

### 2. Voraussetzungen

* Symcon ab Version 8.1
* Eine _Sonos Bridge_ als übergeordnete Instanz (wird bei Bedarf automatisch angelegt)

### 3. Installation

* Über den Modul Store die Bibliothek _SonosUPnP_ installieren.
* Alternativ über das Modul Control folgende URL hinzufügen.  
`https://github.com/Wilkware/SonosUPnP` oder `git://github.com/Wilkware/SonosUPnP.git`

### 4. Einrichtung

* Unter 'Instanz hinzufügen' ist das _Sonos Konfigurator_-Modul unter dem Hersteller 'Sonos' aufgeführt.
* Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Der Konfigurator hat keine Einstellungen.

_Aktionsbereich:_

Spalte                  | Beschreibung
----------------------- | ---------------------------------
Name (Raum)             | In der Sonos-App vergebener Raumname
Modell                  | Modellbezeichnung (z. B. Sonos One, Era 100)
Generation              | Software-Generation (S1/S2)
Software-Version        | Installierte Firmware
Hardware-Version        | Hardware-Revision
Seriennummer            | Seriennummer des Geräts
MAC-Adresse             | MAC-Adresse der Netzwerkschnittstelle
IP                      | Zuletzt bekannte IP-Adresse

Über die Schaltflächen des Konfigurators wird eine neue Instanz erstellt bzw. eine bestehende Instanz geprüft.  
_Hinweis:_ Bleibt die Liste leer, ist meist UPnP in der Sonos-App deaktiviert oder Multicast im Netzwerk blockiert (siehe [Voraussetzungen der Bridge](../SonosBridge#user-content-2-voraussetzungen)).  
Die Liste zeigt den Stand der letzten Netzwerksuche der Bridge. Ein neu hinzugekommener Lautsprecher erscheint spätestens nach 5 Minuten, sofort nach einer manuellen Suche in der Bridge.

### 5. Statusvariablen

Es werden keine Statusvariablen angelegt.

### 6. Darstellungen

Es werden keine Darstellungen oder Profile benötigt.

### 7. Visualisierung

Der Konfigurator hat keine Darstellung in der Visualisierung.

### 8. Befehlsreferenz

Das Modul stellt keine direkten Funktionsaufrufe zur Verfügung.

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
