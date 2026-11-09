# Lyrion Multiroom

Folgende Module beinhaltet das Lyrion Multiroom Repository:

- __Lyrion Multiroom__ ([Dokumentation](LyrionMultiroom))  
	Bedienung des Lyrion Music Server (LMS) über eine eigene Kachel in der Kachel-Visualisierung: Bereiche (Räume, Terrasse, Garten …) werden automatisch erkannt, lassen sich zu synchronen Zonen verbinden. Musik kommt aus der eigenen LMS-Bibliothek, den LMS-Favoriten und optional aus Spotify (Plugin SpotOn) mit mehreren Konten pro Zone. Optimiert für Smartphone, mit Tablet-Layout und Mini-Player.

## Tests

Die Tests laufen ohne Symcon gegen einen nachgebauten Lyrion-Server (`tests/fake_lms.php`) und nutzen die [SymconStubs](https://github.com/symcon/SymconStubs).

```bash
git clone https://github.com/symcon/SymconStubs.git tests/stubs
php tests/run_tests.php
```

Die Kachel lässt sich zusätzlich im Browser prüfen (Python mit Playwright und Chrome):

```bash
php tests/dump_tile.php tests/output/multi
php tests/dump_tile.php tests/output/single single
python3 tests/ui_check.py tests/output/multi tests/output/single tests/output/screenshots
```
