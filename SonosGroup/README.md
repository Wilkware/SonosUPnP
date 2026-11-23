# Sonos Gruppe

[![Home](https://img.shields.io/badge/Home-wilkware.de-0b1830.svg?style=flat-square)](https://wilkware.de/module/sonos/)
[![Version](https://img.shields.io/badge/Symcon-PHP--Modul-red.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.2.20261123-orange.svg?style=flat-square)](https://github.com/Wilkware/SonosUPnP)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/SonosUPnP/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/SonosUPnP/actions)

Das Modul steuert eine feste Gruppe aus mehreren Sonos-Lautsprechern über die lokale UPnP-Schnittstelle und zeigt an, was in der Gruppe gerade gespielt wird.  
Die Gruppe entspricht den gespeicherten Gruppen der Sonos-App, die über UPnP nicht abrufbar sind und deshalb in Symcon festgelegt werden.

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

* Die Lautsprecher der Gruppe werden in einer Liste festgelegt, einer davon ist als Hauptlautsprecher markiert. Die Instanz bildet immer die Wiedergabegruppe ab, zu der der Hauptlautsprecher gerade gehört. Spielt er allein, besteht die Gruppe nur aus ihm.
* Die Gruppenstruktur (Koordinator und Mitglieder) wird zyklisch über die Sonos-Topologie (ZoneGroupTopology) gelesen.
* Wiedergabesteuerung (Zurück, Stopp, Play, Pause, Weiter) und Senderauswahl werden immer an den aktuellen Gruppenkoordinator geschickt.
* Auswahl eines Internetradio-Senders aus der globalen Senderliste der Bridge oder einer eigenen Liste pro Gruppe.
* Interpret, Titel, Album bzw. Sendername und Cover werden vom Gruppenkoordinator übernommen.
* Anzeige der Gruppenmitglieder (Koordinator zuerst).
* Play und Senderwahl an der Gruppe holen die weiteren Lautsprecher der Liste, die die Gruppe verlassen haben, automatisch zurück - wie das erneute Anhaken des Raums in der Sonos-App.
* Lautstärke und Stummschaltung bleiben bewusst beim einzelnen _Sonos Lautsprecher_.
* Erkennung einer nicht erreichbaren Gruppe mit automatischer Wiederaufnahme.

_Hinweis:_ Fest eingerichtete (gespeicherte) Gruppen der Sonos-App sind über die lokale UPnP-Schnittstelle nicht sichtbar. Die Instanz arbeitet mit der aktuellen Wiedergabegruppe; welche Lautsprecher dazugehören sollen, wird deshalb über ihre Lautsprecherliste festgelegt.

### 2. Voraussetzungen

* Symcon ab Version 8.1
* Eine _Sonos Bridge_ als übergeordnete Instanz

### 3. Installation

* Über den Modul Store die Bibliothek _SonosUPnP_ installieren.
* Alternativ über das Modul Control folgende URL hinzufügen.  
`https://github.com/Wilkware/SonosUPnP` oder `git://github.com/Wilkware/SonosUPnP.git`

### 4. Einrichtung

* Unter 'Instanz hinzufügen' ist das _Sonos Gruppe_-Modul unter dem Hersteller 'Sonos' aufgeführt.
* Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Einstellungsbereich:

> 🔊 Gruppe ...

Name                               | Beschreibung
---------------------------------- | -----------------------------------------------------------------
Lautsprecher der Gruppe            | Liste der Lautsprecher (Auswahl aus allen Lautsprechern, die die Bridge gefunden hat). Genau ein Eintrag wird als _Hauptlautsprecher_ angehakt: Er führt die Gruppe, die Instanz bildet die Wiedergabegruppe ab, zu der er gerade gehört. Die weiteren Lautsprecher werden bei Play oder Senderwahl automatisch wieder hinzugefügt, falls sie fehlen; eine eigene Wiedergabe dieser Lautsprecher endet dabei. Soll die Gruppe nur in der Sonos-App verwaltet werden, genügt der Hauptlautsprecher allein.

> ⏱️ Statusaktualisierung ...

Name                               | Beschreibung
---------------------------------- | -----------------------------------------------------------------
Aktualisierungsintervall           | Zeitspanne in Sekunden für die Abfrage von Gruppenstruktur und Wiedergabestatus (5..60s, Standard 10s)
Nicht erreichbar als Fehler melden | Aktiviert (Standard): eine nicht erreichbare Gruppe wird als Fehler (201) gemeldet. Deaktiviert: Status inaktiv (104), kein Fehler, z.B. für absichtlich vom Strom getrennte Lautsprecher

> 📻 Senderliste ...

Name                               | Beschreibung
---------------------------------- | -----------------------------------------------------------------
Eigene Senderliste                 | Optionale Senderliste nur für diese Gruppe (Name, Stream-URL, Icon-URL). Leer lassen, um die globale Liste der Bridge zu verwenden.

_Aktionsbereich:_

> 🧪 Test ...

Aktion                  | Beschreibung
----------------------- | ---------------------------------
ZURÜCK / STOPP / PLAY / PAUSE / WEITER | Wiedergabesteuerung der Gruppe
STATUS ABFRAGEN         | Gruppenstruktur und Wiedergabestatus sofort abfragen und das Ergebnis (bzw. die Fehlerursache) in einem Popup anzeigen

### 5. Statusvariablen

Die Statusvariablen werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

Ident    | Name         | Typ     | Beschreibung
-------- | ------------ | ------- | ------------------------------
Playback | Wiedergabe   | Integer | Steuerung und Anzeige der Wiedergabe der Gruppe (Zurück, Stopp, Play, Pause, Weiter)
Members  | Mitglieder   | String  | Raumnamen der Gruppenmitglieder, Koordinator zuerst
Artist   | Interpret    | String  | Interpret des aktuellen Titels
Title    | Titel        | String  | Aktueller Titel
Album    | Album        | String  | Album des aktuellen Titels bzw. Sendername bei Radiostreams
Playlist | Sender       | String  | Auswahl des Radiosenders aus der eigenen Senderliste der Gruppe bzw. der globalen Senderliste der Bridge

_Hinweis:_ Zusätzlich wird ein Medienobjekt 'Cover' (Ident `Cover`) für das Cover des aktuellen Titels angelegt.

_Status:_ Ist kein Hauptlautsprecher angehakt, steht die Instanz auf _Kein Hauptlautsprecher ausgewählt_ (203), bei mehr als einem auf _Mehr als ein Hauptlautsprecher ausgewählt_ (202). In beiden Fällen wird nichts abgefragt oder gesteuert.

_Erreichbarkeit:_ Antwortet der Hauptlautsprecher oder der Gruppenkoordinator nicht, wechselt die Instanz in den Status _Gruppe nicht erreichbar_ (201, Fehler) bzw. bei deaktivierter Option _Nicht erreichbar als Fehler melden_ in _Gruppe nicht erreichbar (ausgeschaltet)_ (104, inaktiv, kein Fehler), die Wiedergabe wird als gestoppt angezeigt und nur noch alle 30 Sekunden abgefragt. Sobald die Gruppe wieder antwortet, wird die Instanz automatisch wieder aktiv.

### 6. Darstellungen

Es werden keine eigenen Darstellungen oder Profile angelegt, verwendet werden ausschließlich die Standardprofile von Symcon (`~PlaybackPreviousNext`, `~Artist`, `~Song`, `~Playlist`).

### 7. Visualisierung

Alle Statusvariablen und das Cover können direkt in der Visualisierung (Kachel- und WebFront) verwendet werden.  
Wiedergabe und Senderauswahl sind schaltbar.

### 8. Befehlsreferenz

```php
bool WWXSU_UpdateGroupStatus(int $InstanzID);
```

Fragt Gruppenstruktur, Wiedergabestatus und Titelinformationen sofort ab und aktualisiert die Statusvariablen.  
Liefert `true`, wenn die Gruppe geantwortet hat, sonst `false`.

Beispiel:

```php
if (!WWXSU_UpdateGroupStatus(12345)) {
    echo "Gruppe nicht erreichbar";
}
```

Die Steuerung erfolgt über `RequestAction` auf die jeweilige Statusvariable, z. B.:

```php
// Wiedergabe der Gruppe starten (0 = Zurück, 1 = Stopp, 2 = Play, 3 = Pause, 4 = Weiter)
RequestAction(IPS_GetObjectIDByIdent('Playback', 12345), 2);
// Ersten Sender der Senderliste in der Gruppe abspielen
RequestAction(IPS_GetObjectIDByIdent('Playlist', 12345), 0);
```

### 9. Versionshistorie

v1.2.20261123

* _FIX_: Lautsprecherauswahl zeigt nur noch Räume wie die Sonos-App (keine Stereo-Partner, Heimkino-Satelliten oder fremden UPnP-Geräte)

v1.1.20261123

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
