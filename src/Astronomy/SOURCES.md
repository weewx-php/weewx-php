# Herkunft der Astronomie-Berechnungen

Übernommen am 2026-09-05 aus unseren vorhandenen Schwesterprojekten:

| Quelle | SHA-256 vor der Übernahme |
|---|---|
| wetter/app/src/Domain/Planeten.php | `39c1615e551c5982dd8e378d311957b53805aebe2d5e106d95d044f8836fafa2` |
| wetter/app/src/Domain/Planetenbahn.php | `393d9649e09606348fe5402507d7e8adf6cca253bda6e99ca25b8ad503b5b808` |
| wetter/app/modell/planeten-reihen.json | `0c9b8b5cc4dff5e437a1832193db09e74de63d17641b824c0f7078bfb2e2b3a0` |
| weewx-evo/src/weewx_evo/moon.py | `2fee5efe8fbcb190016ec22ebe7c9e257dd4db2926915eb32ceafe3888853641` |
| weewx-evo/src/weewx_evo/sun.py | `830f073a6814be5303a68861285ea4cbe63d1f37bd8eFd3f03783244f94fe02b` |

`Planeten`/`Planetenbahn` sind die vorhandenen PHP-Fassungen mit angepasstem
Namespace, Datenpfad und gemeinsamer Sternzeit. Die vorhandene Aberrations- und
Nutationsrechnung wird auch für Sterne verwendet. `Lunar` und `Seasons` übertragen
die reinen Rechenfunktionen nach PHP. `tests/conformance/port_astronomy.py`
reproduziert diese beiden Dateien; anschließend den PHP-Formatter ausführen.
Zur Laufzeit wird kein Python ausgeführt. Sonne/Refraktion: `Weewx/Sun.php`.

Die Verfahren sind in den Originaldateien dokumentiert (NOAA, Meeus, VSOP87).
Es wurde keine zusätzliche Astronomie-Bibliothek als Laufzeitabhängigkeit installiert.

`stars.txt` enthält `ephem.stars.db` aus PyEphem 4.2 der Testumgebung. MIT-Lizenz:
`STARS-LICENSE`. Quelle:
https://github.com/brandon-rhodes/pyephem/blob/master/ephem/stars.py

Die PHP-Fassade verwendet einen begrenzten Suchhorizont und einheitliche Grad-
und Unix-Einheiten; Unterschiede sind in `docs/frontend.md` beschrieben.
