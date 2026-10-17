<?php

declare(strict_types=1);

/**
 * Treiber-Schnittstelle. Neuer Gerätetyp = neue Klasse + Eintrag in AktorController::DRIVERS.
 *
 * Drei getrennte Operationen, kein Sonderwert wie "0 = dauerhaft":
 *   on()      EIN ohne Zeit (Dauer-EIN)
 *   onTimed() EIN mit Zeit in Sekunden
 *   off()     AUS
 */
interface AktorDriver
{
    /** true: das Gerät beendet die Zeit selbst, IP-Symcon muss nicht ausschalten. */
    public function isNative(): bool;

    public function on(int $switchVar): bool;

    public function onTimed(int $switchVar, int $seconds): bool;

    public function off(int $switchVar): bool;
}

/**
 * Basisklasse: Debug-Ausgabe über einen Callback aus dem Modul (SendDebug ist dort protected).
 */
abstract class AktorDriverBase implements AktorDriver
{
    /** @var callable */
    private $logger;

    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    protected function log(string $message): void
    {
        ($this->logger)(static::class, $message);
    }
}

/**
 * HomeMatic und HmIP: natives ON_TIME auf dem Kanal der STATE-Variable.
 * Die Zeit läuft im Gerät und überlebt IPS-Neustarts.
 *
 * REGEL (getestet): Dauer-EIN sendet ausschließlich STATE = true.
 * Niemals ON_TIME schreiben, auch nicht ON_TIME = 0.
 */
final class AktorDriverHomeMatic extends AktorDriverBase
{
    public function isNative(): bool
    {
        return true;
    }

    public function on(int $switchVar): bool
    {
        return $this->writeState($switchVar, true, 'EIN (Dauer)');
    }

    public function onTimed(int $switchVar, int $seconds): bool
    {
        if (!$this->isStateVariable($switchVar)) {
            $this->log(sprintf('Keine HM-STATE-Variable :: [%d] %s', $switchVar, IPS_GetLocation($switchVar)));
            return false;
        }
        // Erst ON_TIME schreiben, dann einschalten.
        error_clear_last();
        $ok = (bool) @HM_WriteValueFloat(IPS_GetParent($switchVar), 'ON_TIME', (float) $seconds);
        if (!$ok) {
            $this->log(sprintf('ON_TIME %d s FEHLER%s :: [%d] %s', $seconds, $this->lastError(), $switchVar, IPS_GetLocation($switchVar)));
            return false;
        }
        $this->log(sprintf('ON_TIME %d s gesetzt :: [%d] %s', $seconds, $switchVar, IPS_GetLocation($switchVar)));
        return $this->writeState($switchVar, true, sprintf('EIN (ON_TIME %d s)', $seconds));
    }

    public function off(int $switchVar): bool
    {
        return $this->writeState($switchVar, false, 'AUS');
    }

    private function writeState(int $switchVar, bool $value, string $what): bool
    {
        if (!$this->isStateVariable($switchVar)) {
            $this->log(sprintf('Keine HM-STATE-Variable :: [%d] %s', $switchVar, IPS_GetLocation($switchVar)));
            return false;
        }
        error_clear_last();
        $ok = (bool) @HM_WriteValueBoolean(IPS_GetParent($switchVar), 'STATE', $value);
        $this->log(sprintf('%s STATE=%s %s%s :: [%d] %s', $what, $value ? 'true' : 'false', $ok ? 'OK' : 'FEHLER', $ok ? '' : $this->lastError(), $switchVar, IPS_GetLocation($switchVar)));
        return $ok;
    }

    private function isStateVariable(int $switchVar): bool
    {
        return function_exists('HM_WriteValueFloat')
            && IPS_VariableExists($switchVar)
            && IPS_GetObject($switchVar)['ObjectIdent'] === 'STATE';
    }

    private function lastError(): string
    {
        $error = error_get_last();
        return $error === null ? '' : ' (' . $error['message'] . ')';
    }
}

/**
 * Alles andere (Hue, Shelly, ...): Schalten über die Standardaktion der Variable.
 * Das Ausschalten nach Ablauf übernimmt der Timer im Modul.
 */
final class AktorDriverGeneric extends AktorDriverBase
{
    public function isNative(): bool
    {
        return false;
    }

    public function on(int $switchVar): bool
    {
        return $this->set($switchVar, true, 'EIN (Dauer)');
    }

    public function onTimed(int $switchVar, int $seconds): bool
    {
        return $this->set($switchVar, true, sprintf('EIN (Modul-Timer %d s)', $seconds));
    }

    public function off(int $switchVar): bool
    {
        return $this->set($switchVar, false, 'AUS');
    }

    private function set(int $switchVar, bool $value, string $what): bool
    {
        try {
            $ok = (bool) RequestAction($switchVar, $value);
        } catch (Throwable $e) {
            $this->log(sprintf('%s FEHLER (%s) :: [%d] %s', $what, $e->getMessage(), $switchVar, IPS_GetLocation($switchVar)));
            return false;
        }
        $this->log(sprintf('%s %s :: [%d] %s', $what, $ok ? 'OK' : 'FEHLER', $switchVar, IPS_GetLocation($switchVar)));
        return $ok;
    }
}
