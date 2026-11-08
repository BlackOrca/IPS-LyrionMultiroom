# Lyrion Multiroom
Eigenständige Anbindung des Lyrion Music Server (LMS) an IP-Symcon mit eigener Kachel für die Kachel-Visualisierung. Schwerpunkt sind Multiroom-Zonen und mehrere Spotify-Konten (LMS-Plugin SpotOn) in einem Haushalt. Die Kachel ist für das Smartphone optimiert und wechselt auf breiten Kacheln in ein Tablet-Layout.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Alle Player des LMS werden automatisch erkannt. Neue Player erscheinen ohne weitere Einrichtung in der Kachel.
* Bereiche (Räume, Terrasse, Garten …) zu synchronen Zonen verbinden, einen Bereich an eine laufende Zone anhängen, „Ganzes Haus“, Zone auflösen. Die Kachel warnt, wenn ein Bereich dabei seine eigene Wiedergabe verliert.
* Lautstärke pro Bereich und für die ganze Zone. Die Zonen-Lautstärke verschiebt alle Bereiche gemeinsam, die Abstände bleiben erhalten.
* Spotify über SpotOn: Konto pro Zone wählen (in einer Zone zählt der Master), Home und Bibliothek durchblättern, suchen, abspielen, als Nächstes einreihen, anhängen.
* Now Playing mit großem Cover, Hintergrund in der Farbe des Covers, Spulen, Zufall, Wiederholen, Ein/Aus.
* Live-Aktualisierung über den CLI-Port: Änderungen aus anderen Apps (Material Skin, Spotify Connect, Tasten am Player) kommen sofort an.
* Bereiche optional in Gruppen (z. B. Etagen) zusammenfassen, sortieren und ausblenden.
* Skriptfunktionen `LMR_*`, Player per ID (MAC) oder per Name ansprechbar.

### 2. Voraussetzungen

- IP-Symcon ab Version 9.0
- Lyrion Music Server ab Version 8, Web-Port (Standard 9000) und CLI-Port (Standard 9090) aus Symcon erreichbar
- Optional für Spotify: LMS-Plugin [SpotOn](https://github.com/stiefenm/spoton) mit mindestens einem gekoppelten Konto

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen:  
  `https://github.com/BlackOrca/IPS-LyrionMultiroom`

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'Lyrion Multiroom'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

Beim Anlegen erstellt Symcon automatisch einen Client Socket als übergeordnete Instanz. Er muss nicht von Hand eingerichtet werden: Das Modul trägt Host und CLI-Port selbst ein, öffnet ihn, sobald ein Host eingetragen ist, und benennt ihn „Lyrion CLI (Host)“. Ob die Live-Verbindung steht, zeigt die erste Zeile im Aktionsbereich der Konfigurationsseite.

__Konfigurationsseite__:

Name                      | Beschreibung
------------------------- | ------------------
Host                      | IP-Adresse oder Name des LMS, ohne Port und ohne `http://`
Web-Port                  | Port der Weboberfläche bzw. von JSON-RPC (Standard 9000)
CLI-Port                  | Port der Kommandozeile für Live-Ereignisse (Standard 9090)
Benutzername / Passwort   | Nur nötig, wenn am LMS ein Passwortschutz aktiv ist
Player                    | Liste aller bekannten Player. Neu entdeckte sind grün markiert und werden mit „Übernehmen“ gespeichert. Grau markierte sind aktuell nicht im LMS bekannt.
Player → Gruppe           | Fasst Bereiche in der Kachel zusammen, z. B. „Erdgeschoss“ oder „Außen“
Player → Sichtbar         | Ausgeblendete Player erscheinen nicht in der Kachel
Player → Reihenfolge      | Zeilen per Ziehen sortieren; neue Player werden alphabetisch angehängt
Spotify-Plugin            | CLI-Befehl des Spotify-Plugins im LMS (Standard `spoton`)
Cover-Größe               | Kantenlänge der Cover in Pixel, die vom LMS geladen werden
Player einschalten        | Schaltet Player ein, wenn sie einer Zone beitreten
Rückfall-Aktualisierung   | Intervall in Sekunden, in dem zusätzlich alles neu gelesen wird (0 = aus)
Verbindung testen         | Zeigt LMS-Version, Anzahl der Player, gefundene Spotify-Konten und ob Live-Ereignisse aktiv sind

### 5. Statusvariablen und Profile

Das Modul legt keine Statusvariablen und keine Profile an. Der gesamte Zustand (Player, Zonen, Spotify-Konten) steht der Kachel direkt zur Verfügung und kann in Skripten mit `LMR_GetState` abgefragt werden.

### 6. Visualisierung

Die Instanz bringt eine eigene Kachel für die Kachel-Visualisierung mit. Die Darstellung richtet sich nach der Größe der Kachel:

Größe der Kachel                       | Darstellung
-------------------------------------- | ------------------
unter 300 px breit oder unter 240 px hoch | Mini-Player mit Cover, Titel und Play/Pause der laufenden Zone
bis 900 px breit                       | Smartphone: Bereiche, Now Playing und Spotify mit Tab-Leiste
ab 900 px breit                        | Tablet: drei Spalten mit Bereichen, Wiedergabe und Spotify

* __Bereiche__: aktive Zonen mit Cover, Titel, Konto und Lautstärke (bei Zonen gemeinsam, über das Regler-Symbol je Bereich); darunter die übrigen Bereiche, nach Gruppen sortiert. Mit `+` wird ein Bereich an die laufende Zone gehängt.
* __Zone bearbeiten__: Bereiche per Häkchen auswählen; der zuerst gewählte Bereich ist Master und bestimmt Quelle und Spotify-Konto.
* __Now Playing__: Cover in der verfügbaren Größe, Spulen, Zufall, Wiederholen, Lautstärke, Spotify-Konto, Zone und Spotify direkt erreichbar.
* __Spotify__: Kontoumschalter, Auswahl der Zone („Abspielen in“), Suche, Listen mit Vorschaubildern; Titel und Playlists lassen sich sofort abspielen, als Nächstes einreihen oder anhängen.

Die Kachel scrollt nicht. Symcon nutzt Wischgesten auf Kacheln selbst zum Scrollen der Seite, deshalb wäre Scrollen innerhalb der Kachel vor allem in der App unzuverlässig. Stattdessen passt sich der Inhalt der Höhe der Kachel an: Lange Listen (Bereiche, Spotify, Zone bearbeiten) werden mit ‹ › seitenweise geblättert, im Browser zusätzlich per Mausrad.

Farben und Schrift übernimmt die Kachel aus dem gewählten Design der Visualisierung (hell oder dunkel, Akzentfarbe, Kachelfarbe). Alle Flächen und Linien werden aus diesen Farben abgeleitet; der Kacheltitel von Symcon bleibt frei.

Bei nur einem Player blendet die Kachel die Zonen-Funktionen aus. Sie erscheinen automatisch, sobald ein zweiter Player im LMS auftaucht.

Cover und Vorschaubilder lädt das Modul beim LMS und schickt sie direkt an die Kachel. Sie werden deshalb auch unterwegs über Symcon Connect angezeigt, ohne dass der LMS von außen erreichbar sein muss.

### 7. PHP-Befehlsreferenz

`$Player` ist jeweils die Player-ID (MAC-Adresse) oder der Name des Players, z. B. `'Wohnzimmer'`.

`boolean LMR_Refresh(integer $InstanzID);`
Liest Player, Zonen und Spotify-Konten neu vom LMS ein.

Beispiel:
`LMR_Refresh(12345);`

`string LMR_GetState(integer $InstanzID);`
Liefert den Zustand als JSON: Player, Zonen (mit Titel, Modus, Konto) und Spotify-Konten.

Beispiel:
`$state = json_decode(LMR_GetState(12345), true);`

`string LMR_GetPlayers(integer $InstanzID);`
Liefert alle sichtbaren Player als JSON (ID, Name, Gruppe, verbunden, eingeschaltet, Modus, Lautstärke).

Beispiel:
`$players = json_decode(LMR_GetPlayers(12345), true);`

`boolean LMR_Play(integer $InstanzID, string $Player);`
Startet die Wiedergabe bzw. setzt eine pausierte Wiedergabe fort. Ein ausgeschalteter Player wird dabei eingeschaltet.

Beispiel:
`LMR_Play(12345, 'Wohnzimmer');`

`boolean LMR_Pause(integer $InstanzID, string $Player);`
Pausiert die Wiedergabe.

Beispiel:
`LMR_Pause(12345, 'Wohnzimmer');`

`boolean LMR_TogglePause(integer $InstanzID, string $Player);`
Wechselt zwischen Wiedergabe und Pause.

Beispiel:
`LMR_TogglePause(12345, 'Küche');`

`boolean LMR_Stop(integer $InstanzID, string $Player);`
Stoppt die Wiedergabe.

Beispiel:
`LMR_Stop(12345, 'Küche');`

`boolean LMR_Next(integer $InstanzID, string $Player);`
Springt zum nächsten Titel.

Beispiel:
`LMR_Next(12345, 'Wohnzimmer');`

`boolean LMR_Previous(integer $InstanzID, string $Player);`
Springt zum vorherigen Titel.

Beispiel:
`LMR_Previous(12345, 'Wohnzimmer');`

`boolean LMR_SetVolume(integer $InstanzID, string $Player, integer $Volume);`
Setzt die Lautstärke (0 bis 100).

Beispiel:
`LMR_SetVolume(12345, 'Küche', 25);`

`boolean LMR_SetPower(integer $InstanzID, string $Player, boolean $Power);`
Schaltet den Player ein oder aus.

Beispiel:
`LMR_SetPower(12345, 'Bad', false);`

`boolean LMR_PlayURL(integer $InstanzID, string $Player, string $URL);`
Spielt eine URL bzw. einen Stream ab.

Beispiel:
`LMR_PlayURL(12345, 'Küche', 'http://example.com/stream.mp3');`

`boolean LMR_SetZone(integer $InstanzID, string $Master, string $Members);`
Setzt die Mitglieder einer Zone. `$Members` ist eine kommagetrennte Liste, der erste Eintrag wird Master. Eine leere Liste löst die Zone auf.

Beispiel:
`LMR_SetZone(12345, 'Wohnzimmer', 'Wohnzimmer,Küche,Esszimmer');`

`boolean LMR_JoinZone(integer $InstanzID, string $Master, string $Player);`
Hängt einen Player an die Zone des angegebenen Masters.

Beispiel:
`LMR_JoinZone(12345, 'Wohnzimmer', 'Terrasse');`

`boolean LMR_LeaveZone(integer $InstanzID, string $Player);`
Löst einen Player aus seiner Zone.

Beispiel:
`LMR_LeaveZone(12345, 'Terrasse');`

`string LMR_GetSpotifyAccounts(integer $InstanzID);`
Liefert die in SpotOn gekoppelten Konten als JSON `[{"id": "...", "name": "..."}]`.

Beispiel:
`$accounts = json_decode(LMR_GetSpotifyAccounts(12345), true);`

`boolean LMR_SetSpotifyAccount(integer $InstanzID, string $Player, string $Account);`
Wählt das Spotify-Konto für einen Player, per Konto-ID oder Anzeigename. In einer Zone gilt das Konto des Masters.

Beispiel:
`LMR_SetSpotifyAccount(12345, 'Wohnzimmer', 'Familie');`

`string LMR_SendCommand(integer $InstanzID, string $Player, string $Command);`
Sendet einen beliebigen LMS-Befehl als JSON-Array und liefert das Ergebnis als JSON. Für Serverbefehle `$Player` leer lassen.

Beispiel:
`LMR_SendCommand(12345, 'Küche', '["playlist","play","http://example.com/stream.mp3"]');`
