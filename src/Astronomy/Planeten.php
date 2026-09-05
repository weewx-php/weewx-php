<?php

declare(strict_types=1);

namespace WeewxPhp\Astronomy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Wo die Planeten stehen, ohne etwas ausserhalb von PHP zu fragen.
 *
 * `Solar` und `Moon` beantworten die beiden Gestirne, die jede Wetterseite
 * zeigt. Hier stehen die übrigen: Merkur bis Neptun, und Pluto, der 2006
 * aufgehört hat, ein Planet zu sein, und deswegen nicht aufgehört hat,
 * abends am Himmel zu stehen.
 *
 * Das Verfahren ist Meeus, Kapitel 32 (die heliozentrische Stellung), 33
 * (daraus wird, was jemand von hier aus sieht) und 22 bis 25 (Nutation und
 * Aberration, die beiden Schritte zwischen einer geometrischen und einer
 * scheinbaren Stellung). Die Reihen selbst sind VSOP87 und stehen in
 * `app/modell/planeten-reihen.json`, von dreissigtausend Termen auf zweitausend
 * gekürzt — sechs Bogensekunden im schlimmsten Fall über zwei Jahrhunderte,
 * und der schlimmste ist die Venus in ihrer grössten Nähe, wo ein kleiner
 * Fehler in ihrem Ort ein grösserer in ihrer Richtung wird.
 *
 * Pluto steht nicht in VSOP87 — für Bretagnon und Francou war er auch keiner.
 * Meeus 37 hat eine eigene Reihe für ihn, dreiundvierzig Terme in den
 * mittleren Längen von Jupiter, Saturn und Pluto; sie liegen in derselben
 * Datei unter `pluto`, weshalb die nach den Planeten heisst und nicht nach
 * VSOP87. Brauchbar ist sie von 1885 bis
 * 2099. Ausserhalb dieser Jahre ist sie nicht falsch, sondern gegenstandslos,
 * und es gibt keine Sperre dagegen — aus demselben Grund, aus dem keine für
 * das Jahr 3000 dasteht: eine Wetterstation wird nach heute gefragt.
 *
 * Diese Fassung ist die Übersetzung von `weewx_evo/planets.py` aus dem
 * Schwesterprojekt weewx-evo, wo sie an sechs Orten von Tromsø bis Ushuaia
 * über vierzig Jahre gegen pyephem gemessen wurde: drei Bogensekunden für
 * die Stellung, zwei Sekunden für Aufgang, Untergang und Kulmination. Dass
 * PHP dasselbe rechnet wie Python, hält `tests/Domain/PlanetenTest` fest —
 * gegen Prüfvektoren aus dem Original, die zusammen mit den Reihen von
 * `tools/uebernimm_planeten.py` erzeugt werden.
 *
 * Drei Dinge sind Entscheidungen und keine Selbstverständlichkeiten:
 *
 * **Terrestrische Zeit, nicht die Uhr an der Wand.** Jede Reihe hier läuft
 * in TT, das gut eine Minute vor UTC liegt und weiter auseinanderdriftet.
 * Eine Minute ist für Neptun nichts und für Merkur sechs Bogensekunden — er
 * legt zwei Grad am Tag zurück. Deshalb wird `deltaT()` angebracht, bevor
 * eine Reihe angefasst wird. Die Sternzeit dagegen läuft in UT: sie sagt,
 * wie weit die Erde sich gedreht hat, und das ist eine Frage an die Uhr.
 *
 * **Die scheinbare Stellung, nicht die astrometrische.** Aberration sind
 * zwanzig Bogensekunden und Nutation siebzehn, beides das Vierfache des
 * Fehlers der gekürzten Reihen. Sie fortzulassen wäre der grösste Fehler in
 * dieser Datei. Fortgelassen ist die FK5-Korrektur aus Meeus 32.3: unter
 * einer zehntel Bogensekunde, zwei Grössenordnungen unter dem Schnitt der
 * Reihen — das hier hinzuschreiben ist mehr wert als vier Zeilen, die
 * niemand nachmessen kann.
 *
 * **Der Aufgang wird gesucht, nicht gelöst.** Dieselbe Form wie in `Moon`:
 * den Himmel abschreiten und finden, wo die Höhe die Schwelle kreuzt. Ein
 * Planet bewegt sich in zwei Tagen aber kaum gegen die Sterne, und deshalb
 * wird der teure Teil — zweitausend Kosinus, dreimal wegen der Lichtzeit —
 * nur an wenigen Zeitpunkten gerechnet und dazwischen eingeschoben. Das
 * macht `Planetenbahn`.
 *
 * Was das Ganze kostet, gemessen auf dem Entwicklungsrechner: die Datei
 * einlesen 1,5 ms, danach 0,8 ms für die Stellung aller acht Körper und
 * 8 ms für Aufgang, Untergang und Kulmination aller acht.
 *
 * Alle Zeiten sind Unix-Epoch UTC, alle Winkel Grad, alle Entfernungen
 * astronomische Einheiten.
 */
final class Planeten
{
    /**
     * Was gefragt werden kann. Die Erde steht in den Reihen, weil alles
     * andere von ihr aus gemessen wird, und sie steht nicht hier, weil
     * niemand, der auf ihr steht, ihren Aufgang wissen will.
     */
    public const KOERPER = [
        'merkur', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptun',
        'pluto',
    ];

    /** Julianisches Datum des Unix-Nullpunkts. */
    private const JD_UNIX_EPOCH = 2440587.5;

    /** Die astronomische Einheit in Kilometern, für die Parallaxe. */
    private const AE_KM = 149597870.7;

    /** Äquatorradius der Erde in Kilometern. Dieselbe Zahl wie in `Moon`. */
    private const ERDRADIUS_KM = 6378.14;

    /** Um so viel Grad hebt die Refraktion etwas am Horizont an. */
    private const REFRAKTION = 0.5667;

    /**
     * Tage, die das Licht für eine astronomische Einheit braucht. Meeus 33:
     * ein Planet wird gesehen, wo er war, nicht wo er ist — bei Neptun sind
     * das vier Stunden.
     */
    private const LICHTZEIT_TAGE = 0.0057755183;

    /**
     * Schrittweite der Aufgangssuche in Sekunden. Zehn Minuten: ein Planet
     * steht nie so kurz über dem Horizont, dass ein Zehnminutenraster den
     * ganzen Bogen überspringt.
     */
    private const SCHRITT_S = 600;

    /**
     * Die Koeffizientendatei, sobald sie einmal gebraucht wurde.
     *
     * @var array{koerper: array<string, array<int, array<int, list<array{float,float,float}>>>>,
     *            pluto: list<array{int,int,int,int,int,int,int,int,int}>}|null
     */
    private static ?array $daten = null;

    private function __construct() {}

    /**
     * Heliozentrische Länge und Breite in Grad, Radiusvektor in AE.
     *
     * Bezogen auf mittlere Ekliptik und Tagundnachtgleiche des Datums —
     * darin steht VSOP87 in der Fassung D, und deshalb ist es diese Fassung.
     *
     * @return array{laenge:float,breite:float,radius:float}
     */
    public static function heliozentrisch(int $ts, string $koerper): array
    {
        [$l, $b, $r] = self::helioRad(self::julianisch($ts), $koerper);

        return [
            'laenge' => self::mod360(rad2deg($l)),
            'breite' => rad2deg($b),
            'radius' => $r,
        ];
    }

    /**
     * Scheinbare geozentrische ekliptikale Länge, Breite und Entfernung.
     *
     * Grad, Grad, astronomische Einheiten, vom Erdmittelpunkt aus. Scheinbar
     * heisst: um die Laufzeit des Lichts berichtigt, um die Eigenbewegung
     * der Erde quer dazu, und um das Taumeln der Erdachse.
     *
     * @return array{lambda:float,beta:float,distanz:float}
     */
    public static function ekliptik(int $ts, string $koerper): array
    {
        return self::scheinbar(self::julianisch($ts), $koerper);
    }

    /**
     * Scheinbare Rektaszension, Deklination und Entfernung.
     *
     * Grad, Grad, AE. Die Schiefe ist die wahre — die mittlere Neigung plus
     * ihre Nutation —, weil die Länge, auf die sie angewandt wird, die
     * Nutation in der Länge bereits trägt. Eine ohne die andere setzt die
     * Antwort eine halbe Bogensekunde daneben, und zwar so, dass es wie eine
     * vertippte Konstante aussieht.
     *
     * @return array{ra:float,dec:float,distanz:float}
     */
    public static function aequatorial(int $ts, string $koerper): array
    {
        $jd = self::julianisch($ts);
        $e  = self::scheinbar($jd, $koerper);
        [$ra, $dec] = self::nachAequator(
            $e['lambda'],
            $e['beta'],
            self::jahrhunderte($jd),
        );

        return ['ra' => $ra, 'dec' => $dec, 'distanz' => $e['distanz']];
    }

    /**
     * Höhe über dem Horizont und Azimut, beide in Grad, von einem Ort aus.
     *
     * Azimut von Nord über Ost, wie eine Kompassrose gelesen wird.
     *
     * @return array{hoehe:float,azimut:float}
     */
    public static function horizont(int $ts, float $lat, float $lon, string $koerper): array
    {
        $a = self::aequatorial($ts, $koerper);

        return self::horizontAus($a['ra'], $a['dec'], $a['distanz'], $ts, $lat, $lon);
    }

    /**
     * Wie weit ein Planet über den Meridian hinaus ist, in Grad, -180 bis 180.
     *
     * Null bei der Kulmination, und gemeint ist damit der Durchgang durch den
     * Meridian und nicht der höchste Punkt der Höhenkurve. Bei einem Planeten
     * liegen die beiden Sekunden auseinander.
     */
    public static function stundenwinkel(int $ts, float $lon, string $koerper): float
    {
        return self::stundenwinkelAus(self::aequatorial($ts, $koerper)['ra'], $ts, $lon);
    }

    /**
     * Höhe und Azimut aus einer bereits bekannten äquatorialen Stellung.
     *
     * Öffentlich wegen `Planetenbahn`: die schiebt Rektaszension, Deklination
     * und Entfernung zwischen wenigen gerechneten Stellungen ein und braucht
     * von hier ab denselben Weg wie eine frisch gerechnete. Für sich genommen
     * beantwortet sie „wo am Himmel steht etwas, dessen Koordinaten ich habe" —
     * das gilt für einen Stern so gut wie für einen Planeten.
     *
     * @return array{hoehe:float,azimut:float}
     */
    public static function horizontAus(
        float $ra,
        float $dec,
        float $distanz,
        int|float $ts,
        float $lat,
        float $lon,
    ): array {
        $h   = deg2rad(self::stundenwinkelAus($ra, $ts, $lon));
        $phi = deg2rad($lat);
        $d   = deg2rad($dec);

        $hoehe  = asin(sin($phi) * sin($d) + cos($phi) * cos($d) * cos($h));
        $azimut = atan2(sin($h), cos($h) * sin($phi) - tan($d) * cos($phi));

        /*
         * Die Parallaxe ist abgezogen. Beim Mond ist sie fast ein Grad; bei
         * der Venus in ihrer grössten Nähe eine halbe Bogenminute und bei
         * Neptun nichts — aber sie ist eine Zeile, und die Alternative wäre
         * ein Kommentar darüber, bei welchen Planeten man sich entschieden
         * hat, sie zu lassen.
         */
        $parallaxe = asin(self::ERDRADIUS_KM / ($distanz * self::AE_KM));

        /*
         * atan2 misst hier von Süd über West, was die ältere Übereinkunft ist
         * und hundertachtzig Grad von dem entfernt, was jemand abzulesen
         * erwartet.
         */
        return [
            'hoehe'  => rad2deg($hoehe - $parallaxe * cos($hoehe)),
            'azimut' => self::mod360(rad2deg($azimut) + 180.0),
        ];
    }

    /**
     * Der Stundenwinkel zu einer bekannten Rektaszension, -180 bis 180 Grad.
     *
     * Öffentlich aus demselben Grund wie `horizontAus()`.
     */
    public static function stundenwinkelAus(float $ra, int|float $ts, float $lon): float
    {
        $winkel = self::mod360(Lunar::_gmst($ts) + $lon - $ra);

        return $winkel > 180.0 ? $winkel - 360.0 : $winkel;
    }

    /**
     * Die Höhe in Grad, ab der ein Planet als aufgegangen gilt.
     *
     * Nur die Refraktion. Der Mond braucht eine Schwelle, die sich ändert,
     * weil seine Scheibe ein halbes Grad misst und dabei schwankt; die Venus
     * misst in ihrer grössten Nähe eine drittel Bogenminute, das sind zwei
     * Sekunden Aufgangszeit, und grösser wird kein Planet je.
     */
    public static function schwelle(string $koerper): float
    {
        self::pruefe($koerper);

        return -self::REFRAKTION;
    }

    /**
     * Der nächste Aufgang, Untergang und Meridiandurchgang nach einem Moment.
     *
     * Der nächste, nicht „der von heute" — dieselbe Frage, die `Moon::riseSet`
     * für einen Kalendertag beantwortet, hier aber vom Zeitpunkt aus, weil ein
     * Planet keinen Bezug zum Kalendertag hat. `null`, wo es keinen gibt: weit
     * genug im Norden bleibt ein Planet wochenlang oben, und eine Seite, die
     * dafür eine Uhrzeit erfände, löge, statt leer zu bleiben.
     *
     * @return array{rise:int|null,set:int|null,transit:int|null}
     */
    public static function ereignisse(int $ab, float $lat, float $lon, string $koerper): array
    {
        self::pruefe($koerper);

        /** @var array{rise:int|null,set:int|null,transit:int|null} $out */
        $out = ['rise' => null, 'set' => null, 'transit' => null];

        // Zwei Tage. Ein Planet wandert rund vier Minuten am Tag gegen die
        // Sterne, hat also — anders als der Mond — fast immer morgen einen
        // Aufgang, wenn er heute einen hatte. Der zweite Tag ist für die
        // wenigen, die das nicht tun.
        $ende  = $ab + 2 * 86400;
        $bahn  = new Planetenbahn($koerper, $ab, $ende);
        $kante = self::schwelle($koerper);

        $vorT     = $ab;
        $vorH     = $bahn->hoehe($ab, $lat, $lon) - $kante;
        $vorWink  = $bahn->stundenwinkel($ab, $lon);

        for ($t = $ab + self::SCHRITT_S; $t <= $ende; $t += self::SCHRITT_S) {
            $h = $bahn->hoehe($t, $lat, $lon) - $kante;

            if ($out['rise'] === null && $vorH < 0.0 && $h >= 0.0) {
                $out['rise'] = self::kreuzung($bahn, $vorT, $t, $lat, $lon, $kante);
            }
            if ($out['set'] === null && $vorH >= 0.0 && $h < 0.0) {
                $out['set'] = self::kreuzung($bahn, $vorT, $t, $lat, $lon, $kante);
            }

            $wink = $bahn->stundenwinkel($t, $lon);
            /*
             * Der Meridiandurchgang ist der Stundenwinkel, der von hinten
             * nach vorn wechselt. Der andere Vorzeichenwechsel, von +180 auf
             * -180, ist die Gegenseite des Himmels und kein Durchgang; die
             * Sprunggrösse hält die beiden auseinander.
             */
            if ($out['transit'] === null && $vorWink < 0.0 && $wink >= 0.0
                && $wink - $vorWink < 180.0
            ) {
                $out['transit'] = self::meridian($bahn, $vorT, $t, $lon);
            }

            if ($out['rise'] !== null && $out['set'] !== null && $out['transit'] !== null) {
                break;
            }

            $vorT    = $t;
            $vorH    = $h;
            $vorWink = $wink;
        }

        return $out;
    }

    /**
     * Die Höhe über dem Horizont über eine Zeitspanne, in gleichen Schritten.
     *
     * Für das Sichtbarkeitsdiagramm der Nacht: eine Kurve je Planet auf
     * derselben Zeitachse. Sie geht über `Planetenbahn` und kostet damit
     * dasselbe wie ein Auf- und Untergang, gleich wie fein der Schritt ist —
     * die volle Stellung wird ohnehin nur alle vier Stunden gerechnet.
     *
     * Der Rückgabewert ist so geschnitten, wie `Anzeige::zeitPfad()` ihn
     * erwartet: zwei gleich lange Listen, Zeit und Wert getrennt.
     *
     * @param  int $schritt Abstand der Stützpunkte in Sekunden, mindestens 60
     * @return array{t: list<int>, hoehe: list<float>}
     */
    public static function hoehenverlauf(
        int $von,
        int $bis,
        int $schritt,
        float $lat,
        float $lon,
        string $koerper,
    ): array {
        self::pruefe($koerper);

        if ($bis <= $von) {
            return ['t' => [], 'hoehe' => []];
        }

        $schritt = max(60, $schritt);
        $bahn    = new Planetenbahn($koerper, $von, $bis);

        $t     = [];
        $hoehe = [];
        for ($ts = $von; $ts <= $bis; $ts += $schritt) {
            $t[]     = $ts;
            $hoehe[] = $bahn->hoehe($ts, $lat, $lon);
        }

        return ['t' => $t, 'hoehe' => $hoehe];
    }

    // -- die Suche --------------------------------------------------------

    /** Der Moment zwischen zwei Proben, in dem der Horizont gekreuzt wird. */
    private static function kreuzung(
        Planetenbahn $bahn,
        int|float $vor,
        int|float $nach,
        float $lat,
        float $lon,
        float $kante,
    ): int {
        $start = $bahn->hoehe($vor, $lat, $lon) - $kante;

        for ($i = 0; $i < 40; $i++) {
            $mitte = ($vor + $nach) / 2.0;
            $h     = $bahn->hoehe($mitte, $lat, $lon) - $kante;
            if (($h < 0.0) === ($start < 0.0)) {
                $vor = $mitte;
            } else {
                $nach = $mitte;
            }
            if ($nach - $vor < 1.0) {
                break;
            }
        }

        return (int) round(($vor + $nach) / 2.0);
    }

    /** Der Moment zwischen zwei Proben, in dem der Meridian gekreuzt wird. */
    private static function meridian(
        Planetenbahn $bahn,
        int|float $vor,
        int|float $nach,
        float $lon,
    ): int {
        for ($i = 0; $i < 40; $i++) {
            $mitte = ($vor + $nach) / 2.0;
            if ($bahn->stundenwinkel($mitte, $lon) < 0.0) {
                $vor = $mitte;
            } else {
                $nach = $mitte;
            }
            if ($nach - $vor < 1.0) {
                break;
            }
        }

        return (int) round(($vor + $nach) / 2.0);
    }

    // -- die Stellung -----------------------------------------------------

    /**
     * Das julianische Datum eines Zeitstempels, in terrestrischer Zeit.
     *
     * Jede Reihe in dieser Datei läuft in TT, ein Zeitstempel nicht. Der
     * Abstand ist derzeit gut eine Minute — für Neptun nichts, für Merkur
     * sechs Bogensekunden.
     */
    private static function julianisch(int|float $ts): float
    {
        $jd   = $ts / 86400.0 + self::JD_UNIX_EPOCH;
        $jahr = 2000.0 + ($jd - 2451545.0) / 365.25;

        return $jd + self::deltaT($jahr) / 86400.0;
    }

    /**
     * Terrestrische Zeit minus UTC, in Sekunden.
     *
     * Die Anpassung der NASA, für 2005 und danach und für die zwei Jahrzehnte
     * davor. Zurzeit rund neunundsechzig Sekunden, mit steigender Tendenz.
     */
    private static function deltaT(float $jahr): float
    {
        if ($jahr < 2005.0) {
            $t = $jahr - 2000.0;

            return 63.86 + 0.3345 * $t - 0.060374 * $t ** 2;
        }

        return 64.69 + 0.2930 * ($jahr - 2005.0);
    }

    private static function jahrhunderte(float $jd): float
    {
        return ($jd - 2451545.0) / 36525.0;
    }

    /**
     * Eine VSOP87-Grösse: ein Polynom in tau, dessen Koeffizienten Summen sind.
     *
     * Nach Horner, also eine Multiplikation je Potenz statt einer Potenzierung,
     * und ohne die Stellen zu verlieren, die am äusseren Ende hängen.
     *
     * @param array<int, list<array{float,float,float}>> $potenzen
     */
    private static function reihe(array $potenzen, float $tau): float
    {
        $summe = 0.0;

        for ($p = count($potenzen) - 1; $p >= 0; $p--) {
            $teil = 0.0;
            foreach ($potenzen[$p] as [$a, $b, $c]) {
                $teil += $a * cos($b + $c * $tau);
            }
            $summe = $summe * $tau + $teil;
        }

        return $summe;
    }

    /**
     * Heliozentrische Länge und Breite in Bogenmass, Radius in AE.
     *
     * @return array{float,float,float}
     */
    private static function helioRad(float $jd, string $koerper): array
    {
        if ($koerper === 'pluto') {
            return self::pluto($jd);
        }

        $tabellen = self::reihen()[$koerper] ?? null;
        if ($tabellen === null) {
            self::pruefe($koerper);
        }
        /** @var array<int, array<int, list<array{float,float,float}>>> $tabellen */

        $tau = ($jd - 2451545.0) / 365250.0;

        return [
            self::mod2pi(self::reihe($tabellen[0], $tau)),
            self::reihe($tabellen[1], $tau),
            self::reihe($tabellen[2], $tau),
        ];
    }

    /**
     * Pluto, in denselben Bezugsrahmen gebracht wie alles andere hier.
     *
     * Meeus gibt ihn in der Ekliptik von J2000, VSOP87 Fassung D gibt die
     * Planeten in der Ekliptik des Datums. Das eine auf das andere zu
     * präzedieren macht 2026 ein Viertelgrad aus — ein fester Versatz, und
     * genau so sieht ein vertippter Koeffizient aus.
     *
     * @return array{float,float,float}
     */
    private static function pluto(float $jd): array
    {
        [$l, $b, $r] = self::plutoJ2000($jd);
        [$l, $b]     = self::praezession($l, $b, self::jahrhunderte($jd));

        return [self::mod2pi($l), $b, $r];
    }

    /**
     * Pluto aus den mittleren Längen von Jupiter, Saturn und ihm selbst.
     *
     * Bogenmass und AE, in der Ekliptik von J2000 — dem Rahmen, in dem Meeus
     * 37 geschrieben ist. Getrennt von der Präzession darüber, damit der Test
     * ihn gegen das Rechenbeispiel des Buches halten kann: drei Zahlen, die
     * alle dreihundertsiebenundachtzig Koeffizienten prüfen, und der einzige
     * Prüfstein hier, der ohne ein fremdes Programm auskommt.
     *
     * Brauchbar von 1885 bis 2099 und nur dort: die Reihe ist eine Anpassung
     * an ein Jahrhundert Beobachtungen und keine Theorie, und ausserhalb ihres
     * Fensters wird sie nicht ungenauer, sondern bedeutungslos.
     *
     * @return array{float,float,float}
     */
    private static function plutoJ2000(float $jd): array
    {
        $t       = self::jahrhunderte($jd);
        $jupiter = deg2rad(34.35 + 3034.9057 * $t);
        $saturn  = deg2rad(50.08 + 1222.1138 * $t);
        $pluto   = deg2rad(238.96 + 144.96 * $t);

        $sumL = 0.0;
        $sumB = 0.0;
        $sumR = 0.0;
        foreach (self::plutoTerme() as [$cj, $cs, $cp, $ls, $lc, $bs, $bc, $rs, $rc]) {
            $winkel = $cj * $jupiter + $cs * $saturn + $cp * $pluto;
            $sin    = sin($winkel);
            $cos    = cos($winkel);
            $sumL  += $ls * $sin + $lc * $cos;
            $sumB  += $bs * $sin + $bc * $cos;
            $sumR  += $rs * $sin + $rc * $cos;
        }

        return [
            deg2rad(238.958116 + 144.96 * $t + $sumL / 1000000.0),
            deg2rad(-3.908239 + $sumB / 1000000.0),
            40.7241346 + $sumR / 10000000.0,
        ];
    }

    /**
     * Ekliptikale Koordinaten von J2000 auf die Tagundnachtgleiche des Datums.
     *
     * Meeus 21.5 und 21.7, mit J2000 als fester Ausgangsepoche — das lässt
     * jeden Term in T0 wegfallen und übrig bleiben drei kurze Polynome.
     *
     * @return array{float,float}
     */
    private static function praezession(float $laenge, float $breite, float $t): array
    {
        $eta  = deg2rad((47.0029 * $t - 0.03302 * $t ** 2 + 0.000060 * $t ** 3) / 3600.0);
        $knot = deg2rad(174.876384 - (869.8089 * $t - 0.03536 * $t ** 2) / 3600.0);
        $drift = deg2rad((5029.0966 * $t + 1.11113 * $t ** 2 - 0.000006 * $t ** 3) / 3600.0);

        $dreh = $knot - $laenge;
        $a = cos($eta) * cos($breite) * sin($dreh) - sin($eta) * sin($breite);
        $b = cos($breite) * cos($dreh);
        $c = cos($eta) * sin($breite) + sin($eta) * cos($breite) * sin($dreh);

        return [$drift + $knot - atan2($a, $b), asin($c)];
    }

    /**
     * Die scheinbare geozentrische ekliptikale Stellung. Grad, Grad, AE.
     *
     * Meeus 33. Die Erde wird genommen, wo sie jetzt ist, und der Planet, wo
     * er war, als das Licht ihn verliess — das ist die planetare Aberration
     * ganz. Was die Eigenbewegung der Erde mit der Richtung anstellt, aus der
     * das Licht ankommt, ist die andere, und die kommt unten dazu.
     *
     * Die zurückgegebene Entfernung ist die geometrische: wie weit der Planet
     * jetzt weg ist, nicht wie weit das Licht gelaufen ist, um zu sagen, wo er
     * war. Die beiden liegen bei Mars vierzehntausend Kilometer auseinander.
     * Es kostet nichts: der erste Durchlauf unten hat noch keine Lichtzeit in
     * sich und ist damit schon die geometrische Antwort.
     *
     * @return array{lambda:float,beta:float,distanz:float}
     */
    private static function scheinbar(float $jd, string $koerper): array
    {
        if ($koerper === 'erde') {
            /*
             * Die Reihe der Erde steht in der Modelldatei, weil alles andere
             * gegen sie gemessen wird. Diese Frage hier wäre, wo die Erde von
             * der Erde aus steht: die Entfernung käme als Null heraus, und die
             * teilt zwei Funktionen später durch Null — was dann wie ein
             * Fehler in der Parallaxe aussieht.
             */
            throw new InvalidArgumentException('Die Erde hat keine geozentrische Stellung.');
        }

        [$el, $eb, $er] = self::helioRad($jd, 'erde');
        $ex = $er * cos($eb) * cos($el);
        $ey = $er * cos($eb) * sin($el);
        $ez = $er * sin($eb);

        $distanz = 0.0;
        $geometrisch = 0.0;
        $lambda = 0.0;
        $beta   = 0.0;

        // Drei Durchläufe. Der erste hat gar keine Lichtzeit, der zweite ist
        // auf wenige Meter an der Antwort, der dritte ist dort.
        for ($i = 0; $i < 3; $i++) {
            [$pl, $pb, $pr] = self::helioRad($jd - $distanz * self::LICHTZEIT_TAGE, $koerper);
            $x = $pr * cos($pb) * cos($pl) - $ex;
            $y = $pr * cos($pb) * sin($pl) - $ey;
            $z = $pr * sin($pb) - $ez;

            $distanz = sqrt($x * $x + $y * $y + $z * $z);
            $lambda  = atan2($y, $x);
            $beta    = atan2($z, hypot($x, $y));

            if ($i === 0) {
                $geometrisch = $distanz;
            }
        }

        $t = self::jahrhunderte($jd);
        // Die geometrische Länge der Sonne ist die der Erde, umgedreht.
        $sonne = $el + M_PI;
        [$abL, $abB] = self::aberration($lambda, $beta, $sonne, $t);

        $lambda += $abL + deg2rad(self::nutation($t)[0]);
        $beta   += $abB;

        return [
            'lambda'  => self::mod360(rad2deg($lambda)),
            'beta'    => rad2deg($beta),
            'distanz' => $geometrisch,
        ];
    }

    /** @return array{float, float} Apparent equatorial coordinates from a star's mean position of date. */
    public static function scheinbarerStern(int $at, float $ra, float $dec): array
    {
        $jd = self::julianisch($at);
        $t = self::jahrhunderte($jd);
        $eps = deg2rad(self::schiefe($t) - self::nutation($t)[1]);
        $a = deg2rad($ra);
        $d = deg2rad($dec);
        $lambda = atan2(sin($a) * cos($eps) + tan($d) * sin($eps), cos($a));
        $beta = asin(sin($d) * cos($eps) - cos($d) * sin($eps) * sin($a));
        [$earth] = self::helioRad($jd, 'erde');
        [$abL, $abB] = self::aberration($lambda, $beta, $earth + M_PI, $t);
        return self::nachAequator(rad2deg($lambda + $abL) + self::nutation($t)[0], rad2deg($beta + $abB), $t);
    }

    /**
     * Was die Eigenbewegung der Erde mit einer Richtung macht. Meeus 23.2.
     *
     * Zwanzig Bogensekunden, das Dreifache des ganzen Fehlers der gekürzten
     * Reihen — das ist keine Verfeinerung, sondern der Unterschied zwischen
     * einer Antwort und einer falschen. Bogenmass hinein, Bogenmass heraus.
     *
     * @return array{float,float}
     */
    private static function aberration(float $lambda, float $beta, float $sonne, float $t): array
    {
        $kappa = deg2rad(20.49552 / 3600.0);
        $exz   = 0.016708634 - 0.000042037 * $t - 0.0000001267 * $t ** 2;
        $peri  = deg2rad(102.93735 + 1.71946 * $t + 0.00046 * $t ** 2);

        return [
            (-$kappa * cos($sonne - $lambda) + $exz * $kappa * cos($peri - $lambda))
                / cos($beta),
            -$kappa * sin($beta) * (sin($sonne - $lambda) - $exz * sin($peri - $lambda)),
        ];
    }

    /**
     * Das Taumeln der Erdachse, in Grad. Meeus 22, die kurze Fassung.
     *
     * Vier Terme statt dreiundsechzig, das ist eine halbe Bogensekunde — ein
     * Zehntel dessen, was die gekürzten Reihen ohnehin kosten. Der Term in der
     * Länge erreicht siebzehn Bogensekunden und kann deshalb nicht weg; der
     * Rest der Tabelle schon.
     *
     * @return array{float,float} Nutation in Länge und in Schiefe, Grad
     */
    private static function nutation(float $t): array
    {
        $knoten = deg2rad(125.04452 - 1934.136261 * $t);
        $sonne  = deg2rad(280.4665 + 36000.7698 * $t);
        $mond   = deg2rad(218.3165 + 481267.8813 * $t);

        $inLaenge = -17.20 * sin($knoten) - 1.32 * sin(2 * $sonne)
            - 0.23 * sin(2 * $mond) + 0.21 * sin(2 * $knoten);
        $inSchiefe = 9.20 * cos($knoten) + 0.57 * cos(2 * $sonne)
            + 0.10 * cos(2 * $mond) - 0.09 * cos(2 * $knoten);

        return [$inLaenge / 3600.0, $inSchiefe / 3600.0];
    }

    /** Die wahre Schiefe der Erdachse, in Grad. Meeus 22.2 plus 22. */
    private static function schiefe(float $t): float
    {
        $mittel = 23.0 + (26.0 + (21.448 - $t * (46.8150 + $t * (0.00059 - $t * 0.001813)))
            / 60.0) / 60.0;

        return $mittel + self::nutation($t)[1];
    }

    /**
     * Ekliptikale Grade zu Rektaszension und Deklination. Meeus 13.3.
     *
     * @return array{float,float}
     */
    private static function nachAequator(float $lambda, float $beta, float $t): array
    {
        $l   = deg2rad($lambda);
        $b   = deg2rad($beta);
        $eps = deg2rad(self::schiefe($t));

        return [
            self::mod360(rad2deg(atan2(
                sin($l) * cos($eps) - tan($b) * sin($eps),
                cos($l),
            ))),
            rad2deg(asin(sin($b) * cos($eps) + cos($b) * sin($eps) * sin($l))),
        ];
    }

    /**
     * Die VSOP87-Reihen je Körper.
     *
     * @return array<string, array<int, array<int, list<array{float,float,float}>>>>
     */
    private static function reihen(): array
    {
        return self::gelesen()['koerper'];
    }

    /**
     * Plutos dreiundvierzig Terme aus Meeus 37.A.
     *
     * Je Zeile die Vielfachen der mittleren Längen von Jupiter, Saturn und
     * Pluto, dann Sinus- und Kosinusglied für Länge, Breite und Radiusvektor.
     * Länge und Breite in Millionstel Grad, der Radius in Zehnmillionstel
     * einer astronomischen Einheit.
     *
     * @return list<array{int,int,int,int,int,int,int,int,int}>
     */
    private static function plutoTerme(): array
    {
        return self::gelesen()['pluto'];
    }

    /**
     * Die Koeffizienten, beim ersten Zugriff geladen.
     *
     * Siebzig Kilobyte Zahlen, die eine Seite ohne Planetenabschnitt nicht
     * anfasst — deshalb erst hier und nicht beim Einbinden der Klasse.
     *
     * Auch Plutos kurze Tabelle steht dort und nicht als Konstante hier: sie
     * ist erzeugt wie die übrigen, veraltet mit ihnen und wird mit demselben
     * Lauf erneuert. Eine Hälfte der Koeffizienten im Quelltext und die
     * andere in einer Datei wäre eine Stelle mehr, an die zu denken ist.
     *
     * @return array{koerper: array<string, array<int, array<int, list<array{float,float,float}>>>>,
     *               pluto: list<array{int,int,int,int,int,int,int,int,int}>}
     */
    private static function gelesen(): array
    {
        if (self::$daten === null) {
            $pfad = __DIR__ . '/planeten-reihen.json';
            $roh  = @file_get_contents($pfad);
            $alle = $roh === false ? null : json_decode($roh, true);

            if (!is_array($alle) || !is_array($alle['koerper'] ?? null) || !is_array($alle['pluto'] ?? null)) {
                throw new RuntimeException(
                    "Die Planetenkoeffizienten fehlen oder sind unlesbar: {$pfad}. "
                    . 'Neu erzeugen mit tools/uebernimm_planeten.py.',
                );
            }

            /** @var array{koerper: array<string, array<int, array<int, list<array{float,float,float}>>>>, pluto: list<array{int,int,int,int,int,int,int,int,int}>} $alle */
            self::$daten = $alle;
        }

        return self::$daten;
    }

    /**
     * Wirft, wenn der Name kein Planet ist.
     *
     * Beim Namen genannt und nicht mit den Achseln gezuckt, weil die beiden
     * Namen, die am ehesten hier ankommen, die beiden sind, die woanders
     * hingehören.
     */
    private static function pruefe(string $koerper): void
    {
        if (!in_array($koerper, self::KOERPER, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s ist keiner der Planeten. Die Sonne steht in Solar, der Mond in Moon; hier sind es %s.',
                $koerper,
                implode(', ', self::KOERPER),
            ));
        }
    }

    private static function mod360(float $grad): float
    {
        $r = fmod($grad, 360.0);

        return $r < 0.0 ? $r + 360.0 : $r;
    }

    private static function mod2pi(float $bogen): float
    {
        $r = fmod($bogen, 2.0 * M_PI);

        return $r < 0.0 ? $r + 2.0 * M_PI : $r;
    }
}
