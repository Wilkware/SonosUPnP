# 🔊 Sonos (UPnP)

[![Home](https://img.shields.io/badge/Home-wilkware.de-0b1830.svg?style=flat-square)](https://wilkware.de/module/sonos/)
[![Version](https://img.shields.io/badge/Symcon-PHP--Bibliothek-purple.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Licence](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/SonosUPnP/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/SonosUPnP/actions)

Symcon Modulbibliothek zur Steuerung von Sonos-Lautsprechern über die lokale UPnP-Schnittstelle.  
Die Kommunikation erfolgt ausschließlich im lokalen Netzwerk - es wird weder ein Sonos-Konto noch ein Cloud-Zugang oder API-Schlüssel benötigt.

Folgende Module beinhaltet diese Bibliothek:

- __Sonos Bridge__ ([Dokumentation](SonosBridge))  
    Zentrale Instanz: findet die Lautsprecher automatisch im Netzwerk, löst deren IP-Adressen auf und verwaltet die globale Senderliste.

- __Sonos Konfigurator__ ([Dokumentation](SonosConfigurator))  
    Listet alle gefundenen Lautsprecher auf und legt per Klick die passenden Lautsprecher-Instanzen an.

- __Sonos Lautsprecher__ ([Dokumentation](SonosSpeaker))  
    Steuerung eines einzelnen Lautsprechers (Wiedergabe, Lautstärke, Stumm, Radiosender) inklusive Anzeige von Titel, Interpret, Album und Cover.

## 🪧 Voraussetzung

* __UPnP in der Sonos-App aktiviert__  
    Die Bibliothek nutzt ausschließlich die lokale UPnP-Schnittstelle der Lautsprecher. Ist UPnP deaktiviert, werden die Lautsprecher weder gefunden noch lassen sie sich steuern.  
    Die Einstellung findet sich in der Sonos-App unter _Einstellungen → System → Netzwerk → UPnP_ (Bezeichnung und Pfad können je nach App-Version abweichen).
* __Symcon und Lautsprecher im selben Netzwerk__  
    Die Lautsprecher werden per Multicast (SSDP, UDP-Port 1900) gesucht und über TCP-Port 1400 gesteuert. Liegen die Lautsprecher in einem anderen Subnetz/VLAN (z. B. IoT- oder Gästenetz), müssen Multicast und diese Ports zwischen den Netzen weitergeleitet werden.
* __Docker__  
    Läuft Symcon in einem Container, ist das Host-Netzwerk (`network_mode: host`) notwendig, da Multicast sonst nicht durchgereicht wird.

## 📜 Historie

- 2026-10-12: Initialversion mit den Modulen *Sonos Bridge*, *Sonos Konfigurator* und *Sonos Lautsprecher*

## 👨‍💻 Entwickler

Seit nunmehr über 10 Jahren fasziniert mich das Thema Haussteuerung. In den letzten Jahren betätige ich mich auch intensiv in der Symcon Community und steuere dort verschiedenste Skript und Module bei. Ihr findet mich dort unter dem Namen @pitti ;-)

[![GitHub](https://img.shields.io/badge/GitHub-@wilkware-181717.svg?style=for-the-badge&logo=github)](https://wilkware.github.io/)

## 💰 Spenden

Die Software ist für die nicht kommerzielle Nutzung kostenlos, über eine Spende bei Gefallen der Bibliothek würde ich mich freuen.

[![PayPal](https://img.shields.io/badge/PayPal-spenden-00457C.svg?style=for-the-badge&logo=paypal)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8816166)

## ©️ Lizenz

Namensnennung - Nicht-kommerziell - Weitergabe unter gleichen Bedingungen 4.0 International

[![Licence](https://img.shields.io/badge/License-CC_BY--NC--SA_4.0-EF9421.svg?style=for-the-badge&logo=creativecommons)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
