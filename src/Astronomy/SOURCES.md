# Sources of the astronomy calculations

Imported on 2026-09-05 from our existing sibling projects:

| Source | SHA-256 before import |
|---|---|
| wetter/app/src/Domain/Planeten.php | `39c1615e551c5982dd8e378d311957b53805aebe2d5e106d95d044f8836fafa2` |
| wetter/app/src/Domain/Planetenbahn.php | `393d9649e09606348fe5402507d7e8adf6cca253bda6e99ca25b8ad503b5b808` |
| wetter/app/modell/planeten-reihen.json | `0c9b8b5cc4dff5e437a1832193db09e74de63d17641b824c0f7078bfb2e2b3a0` |
| weewx-evo/src/weewx_evo/moon.py | `2fee5efe8fbcb190016ec22ebe7c9e257dd4db2926915eb32ceafe3888853641` |
| weewx-evo/src/weewx_evo/sun.py | `830f073a6814be5303a68861285ea4cbe63d1f37bd8eFd3f03783244f94fe02b` |

`Planeten`/`Planetenbahn` are the existing PHP versions with an adjusted namespace,
data path and shared sidereal time. The existing aberration and nutation
calculations also apply to stars. `Lunar` and `Seasons` port the pure calculation
functions to PHP. `tests/conformance/port_astronomy.py` reproduces these two files;
run the PHP formatter afterward. No Python runs at runtime.
Sun/refraction: `Weewx/Sun.php`.

The methods are documented in the original files (NOAA, Meeus, VSOP87).
No additional astronomy library was installed as a runtime dependency.

`stars.txt` contains `ephem.stars.db` from PyEphem 4.2 in the test environment.
MIT license: `STARS-LICENSE`. Source:
https://github.com/brandon-rhodes/pyephem/blob/master/ephem/stars.py

The PHP facade uses a bounded search horizon and consistent degree and Unix
units; differences are described in `docs/frontend.md`.
