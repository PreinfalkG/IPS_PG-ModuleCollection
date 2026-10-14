<?php

declare(strict_types=1);

/**
 * Treiber-Schnittstelle. Neuer Gerätetyp = neue Klasse + Eintrag in TimeSwitch::DRIVERS.
 */
interface TimeSwitchDriver
{
    /** true: das Gerät beendet die Zeit selbst, IP-Symcon muss nicht ausschalten. */
    public static function isNative(): bool;

    /** Einschalten für $seconds Sekunden ($seconds = 0: dauerhaft ohne Timer). Rückgabe false = Fehler. */
    public static function start(int $switchVar, int $seconds): bool;

    /** Sofort ausschalten. */
    public static function stop(int $switchVar): bool;
}

/**
 * HomeMatic und HmIP: natives ON_TIME auf dem Kanal der STATE-Variable.
 * Die Zeit läuft im Gerät und überlebt IPS-Neustarts.
 */
final class TimeSwitchDriverHomeMatic implements TimeSwitchDriver
{
    public static function isNative(): bool
    {
        return true;
    }

    public static function start(int $switchVar, int $seconds): bool
    {
        if (!self::isStateVariable($switchVar)) {
            return false;
        }
        $instance = IPS_GetParent($switchVar);

        if($seconds < 999900) {
            if (!@HM_WriteValueFloat($instance, 'ON_TIME', (float) $seconds)) {
                //$this->SendDebug(__METHOD__, sprintf("ON HM ON_TIME %d sec :: [%d] - %s", $seconds, $switchVar, IPS_GetLocation($switchVar)), 0);
                return false;
            }
        }
        //$this->SendDebug(__METHOD__, sprintf("ON HM STATE :: [%d] - %s", $switchVar, IPS_GetLocation($switchVar)), 0);
        return (bool) @HM_WriteValueBoolean($instance, 'STATE', true);
    }

    public static function stop(int $switchVar): bool
    {
        if (!self::isStateVariable($switchVar)) {
            return false;
        }
        //$this->SendDebug(__METHOD__, sprintf("OFF HM STATE :: [%d] - %s", $switchVar, IPS_GetLocation($switchVar)), 0);
        return (bool) @HM_WriteValueBoolean(IPS_GetParent($switchVar), 'STATE', false);
    }

    private static function isStateVariable(int $switchVar): bool
    {
        return function_exists('HM_WriteValueFloat')
            && IPS_GetObject($switchVar)['ObjectIdent'] === 'STATE';
    }
}

/**
 * Alles andere (Hue, Shelly, ...): Schalten über die Standardaktion der Variable.
 * Das Ausschalten übernimmt der Timer im Modul.
 */
final class TimeSwitchDriverGeneric implements TimeSwitchDriver
{
    public static function isNative(): bool
    {
        return false;
    }

    public static function start(int $switchVar, int $seconds): bool
    {
        return self::set($switchVar, true);
    }

    public static function stop(int $switchVar): bool
    {
        return self::set($switchVar, false);
    }

    private static function set(int $switchVar, bool $value): bool
    {
        try {
            return (bool) RequestAction($switchVar, $value);
        } catch (Throwable $e) {
            return false;
        }
    }
}
