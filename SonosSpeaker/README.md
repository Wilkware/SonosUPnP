# Sonos Lautsprecher

[![Home](https://img.shields.io/badge/Home-wilkware.de-0b1830.svg?style=flat-square)](https://wilkware.de/module/sonos/)
[![Version](https://img.shields.io/badge/Symcon-PHP--Modul-red.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.0.20261012-orange.svg?style=flat-square)](https://github.com/Wilkware/SonosUPnP)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/SonosUPnP/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/SonosUPnP/actions)

Das Modul steuert einen einzelnen Sonos-Lautsprecher über die lokale UPnP-Schnittstelle und zeigt an, was gerade gespielt wird.

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

* Wiedergabesteuerung: Zurück, Stopp, Play, Pause, Weiter.
* Lautstärke einstellen und Stummschaltung.
* Auswahl eines Internetradio-Senders aus der globalen Senderliste der Bridge oder einer eigenen Liste pro Lautsprecher.
* Anzeige von Interpret, Titel und Album bzw. Sendername - auch bei Radiostreams, die den aktuellen Titel nur im Stream mitliefern.
* Cover des aktuellen Titels; ersatzweise das Senderlogo aus der Senderliste oder ein Platzhalterbild.
* Werbe-Einblendungen mancher Radiostreams (Tracking-Adressen statt Titel) werden erkannt und nicht angezeigt.
* Zyklische Abfrage des Wiedergabestatus.
* Erkennung nicht erreichbarer Lautsprecher mit automatischer Wiederaufnahme, sobald der Lautsprecher wieder antwortet (siehe [Statusvariablen](#user-content-5-statusvariablen)).

### 2. Voraussetzungen

* Symcon ab Version 8.1
* Eine _Sonos Bridge_ als übergeordnete Instanz

### 3. Installation

* Über den Modul Store die Bibliothek _SonosUPnP_ installieren.
* Alternativ über das Modul Control folgende URL hinzufügen.  
`https://github.com/Wilkware/SonosUPnP` oder `git://github.com/Wilkware/SonosUPnP.git`

### 4. Einrichtung

* Die Instanz wird am einfachsten über den _Sonos Konfigurator_ angelegt - UUID und Raumname werden dabei automatisch gesetzt.
* Alternativ ist unter 'Instanz hinzufügen' das _Sonos Lautsprecher_-Modul unter dem Hersteller 'Sonos' aufgeführt.
* Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Einstellungsbereich:

> ⏱️ Statusaktualisierung ...

Name                               | Beschreibung
---------------------------------- | -----------------------------------------------------------------
Aktualisierungsintervall           | Zeitspanne in Sekunden für die Abfrage des Wiedergabestatus (5..60s, Standard 10s)

> 📻 Senderliste ...

Name                               | Beschreibung
---------------------------------- | -----------------------------------------------------------------
Eigene Senderliste                 | Optionale Senderliste nur für diesen Lautsprecher (Name, Stream-URL, Icon-URL). Leer lassen, um die globale Liste der Bridge zu verwenden.

_Aktionsbereich:_

> 🧪 Test ...

Aktion                  | Beschreibung
----------------------- | ---------------------------------
ZURÜCK / STOPP / PLAY / PAUSE / WEITER | Wiedergabesteuerung des Lautsprechers
Lautstärke              | Schieberegler, vorbelegt mit der aktuellen Lautstärke
STUMM EIN / STUMM AUS   | Stummschaltung ein- bzw. ausschalten
STATUS ABFRAGEN         | Wiedergabestatus sofort abfragen und das Ergebnis (bzw. die Fehlerursache) in einem Popup anzeigen

### 5. Statusvariablen

Die Statusvariablen werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

Ident    | Name         | Typ     | Beschreibung
-------- | ------------ | ------- | ------------------------------
Playback | Wiedergabe   | Integer | Steuerung und Anzeige der Wiedergabe (Zurück, Stopp, Play, Pause, Weiter)
Volume   | Lautstärke   | Integer | Lautstärke des Lautsprechers (0..100%)
Mute     | Stumm        | Boolean | Stummschaltung des Lautsprechers
Artist   | Interpret    | String  | Interpret des aktuellen Titels
Title    | Titel        | String  | Aktueller Titel
Album    | Album        | String  | Album des aktuellen Titels bzw. Sendername bei Radiostreams
Playlist | Sender       | String  | Auswahl des Radiosenders aus der Senderliste

_Hinweis:_ Zusätzlich wird ein Medienobjekt 'Cover' (Ident `Cover`) für das Cover des aktuellen Titels angelegt.

_Erreichbarkeit:_ Antwortet der Lautsprecher nicht (ausgeschaltet, neue IP-Adresse), wechselt die Instanz in den Status _Lautsprecher nicht erreichbar_ (201), die Wiedergabe wird als gestoppt angezeigt und nur noch alle 30 Sekunden abgefragt. Die Bridge sucht dann automatisch nach einer geänderten IP-Adresse. Sobald der Lautsprecher wieder antwortet, wird die Instanz automatisch wieder aktiv.

### 6. Darstellungen

Es werden keine eigenen Darstellungen oder Profile angelegt, verwendet werden ausschließlich die Standardprofile von Symcon (`~PlaybackPreviousNext`, `~Intensity.100`, `~Switch`, `~Artist`, `~Song`, `~Playlist`).

### 7. Visualisierung

Alle Statusvariablen und das Cover können direkt in der Visualisierung (Kachel- und WebFront) verwendet werden.  
Wiedergabe, Lautstärke, Stumm und Senderauswahl sind schaltbar.

### 8. Befehlsreferenz

```php
bool WWXSU_UpdateStatus(int $InstanzID);
```

Fragt Wiedergabestatus, Titelinformationen, Lautstärke und Stummschaltung sofort ab und aktualisiert die Statusvariablen.  
Liefert `true`, wenn der Lautsprecher geantwortet hat, sonst `false`.

Beispiel:

```php
if (!WWXSU_UpdateStatus(12345)) {
    echo "Lautsprecher nicht erreichbar";
}
```

Die Steuerung erfolgt über `RequestAction` auf die jeweilige Statusvariable, z. B.:

```php
// Lautstärke auf 20% setzen
RequestAction(IPS_GetObjectIDByIdent('Volume', 12345), 20);
// Wiedergabe starten (0 = Zurück, 1 = Stopp, 2 = Play, 3 = Pause, 4 = Weiter)
RequestAction(IPS_GetObjectIDByIdent('Playback', 12345), 2);
// Ersten Sender der Senderliste abspielen
RequestAction(IPS_GetObjectIDByIdent('Playlist', 12345), 0);
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
