<?php

declare(strict_types=1);

namespace WeewxPhp\Astronomy;

/**
 * Wo ein Planet über eine Zeitspanne steht, aus wenigen vollen Stellungen.
 *
 * Die Aufgangssuche in `Planeten::ereignisse()` fragt bis zu 288 Mal nach der
 * Höhe und ebenso oft nach dem Stundenwinkel. Jedes Mal die volle scheinbare
 * Stellung zu rechnen heisst, die ganzen VSOP87-Reihen viermal durchzugehen —
 * einmal für die Erde und dreimal für die Lichtzeitschleife. Gemessen kostet
 * eine solche Stellung 0,09 ms, macht 26 ms je Planet und eine fünftel
 * Sekunde für alle acht. So gerechnet sind es 8 ms für alle acht.
 *
 * Deshalb wird die Stellung alle vier Stunden gerechnet und dazwischen
 * eingeschoben. Ein Planet legt gegen die Sterne höchstens zwei Grad am Tag
 * zurück, und durch drei Stützstellen passt über vier Stunden eine Parabel,
 * die deutlich unter einer Bogensekunde bleibt. Der schnelle Teil der
 * Bewegung — fünfzehn Grad in der Stunde — ist die Erddrehung, und die steht
 * in der Sternzeit, wo sie zu jedem Moment genau ist.
 *
 * Nur von `Planeten` benutzt und nur dafür gedacht.
 *
 * @internal
 */
final class Planetenbahn
{
    /**
     * Vier Stunden. Halbieren verschiebt nichts Messbares und verdoppelt die
     * Rechenzeit.
     */
    private const SCHRITT_S = 14400;

    /**
     * Rektaszension, Deklination und Entfernung an den Stützstellen.
     *
     * @var list<array{float,float,float}>
     */
    private array $proben = [];

    public function __construct(string $koerper, private readonly int $start, int $ende)
    {
        $anzahl  = (int) ceil(($ende - $start) / self::SCHRITT_S) + 2;
        $vorher  = null;

        for ($i = 0; $i < $anzahl; $i++) {
            $a  = Planeten::aequatorial($start + $i * self::SCHRITT_S, $koerper);
            $ra = $a['ra'];

            /*
             * Die Rektaszension wird beim Sammeln aufgewickelt. Zwischen 359
             * und 1 den kurzen Weg zu interpolieren ist der Unterschied
             * zwischen einem Planeten in den Fischen und einem, der nirgends
             * steht.
             */
            if ($vorher !== null) {
                $ra += 360.0 * round(($vorher - $ra) / 360.0);
            }
            $vorher = $ra;

            $this->proben[] = [$ra, $a['dec'], $a['distanz']];
        }
    }

    /** Die Höhe über dem Horizont in Grad, zu einem Moment. */
    public function hoehe(int|float $ts, float $lat, float $lon): float
    {
        [$ra, $dec, $distanz] = $this->bei($ts);

        return Planeten::horizontAus(self::mod360($ra), $dec, $distanz, $ts, $lat, $lon)['hoehe'];
    }

    /** Der Stundenwinkel in Grad, -180 bis 180, zu einem Moment. */
    public function stundenwinkel(int|float $ts, float $lon): float
    {
        return Planeten::stundenwinkelAus(self::mod360($this->bei($ts)[0]), $ts, $lon);
    }

    /**
     * Rektaszension, Deklination und Entfernung zu einem Moment.
     *
     * @return array{float,float,float}
     */
    private function bei(int|float $ts): array
    {
        $stelle = ($ts - $this->start) / self::SCHRITT_S;

        // Die mittlere von drei Stützstellen, an beiden Enden um eine
        // hereingehalten, damit die Parabel immer zwischen Punkten liegt und
        // nie über das letzte hinausläuft.
        $mitte = (int) round($stelle);
        $mitte = max(1, min($mitte, count($this->proben) - 2));
        $n     = $stelle - $mitte;

        [$eins, $zwei, $drei] = array_slice($this->proben, $mitte - 1, 3);

        return [
            self::dazwischen($eins[0], $zwei[0], $drei[0], $n),
            self::dazwischen($eins[1], $zwei[1], $drei[1], $n),
            self::dazwischen($eins[2], $zwei[2], $drei[2], $n),
        ];
    }

    /**
     * Meeus 3.3: eine Parabel durch drei gleich weit auseinanderliegende Werte.
     *
     * `$n` sagt, wo die Antwort zwischen ihnen liegt, gemessen in einem
     * Abstand; null ist der mittlere Wert.
     */
    private static function dazwischen(float $vor, float $mitte, float $nach, float $n): float
    {
        return $mitte + $n * (($nach - $vor) / 2.0 + $n * ($vor + $nach - 2.0 * $mitte) / 2.0);
    }

    /**
     * Die aufgewickelte Rektaszension zurück auf 0 bis 360 Grad.
     *
     * Aufgewickelt wird im Konstruktor, damit die Parabel den kurzen Weg
     * nicht falsch nimmt; nach aussen erwartet jede Formel wieder einen
     * Winkel im Vollkreis.
     */
    private static function mod360(float $grad): float
    {
        $r = fmod($grad, 360.0);

        return $r < 0.0 ? $r + 360.0 : $r;
    }
}
