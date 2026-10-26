<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/Drivers.php';
require_once __DIR__ . '/libs/ExportImport.php';

/**
 * Aktor Controller
 *
 * Hauptfunktionen:
 *  - Aktoren normal EIN/AUS schalten (SwitchOn / SwitchOff), einheitlich über Treiber
 *  - Time Switch: Integer-Variable pro Aktor (Sekunden), Restlaufzeit, Modul-Timer (auch für HomeMatic ON_TIME)
 *  - Betriebszeit pro Aktor (TotalOnSeconds)
 *
 * Schlüssel einer Zeile ist die Schaltvariable (SwitchVar). Die Time-Switch-Variable ist optional.
 */
class AktorController extends IPSModule
{
    use AktorControllerExportImport;

    private const DRIVERS = [
        'hm'      => AktorDriverHomeMatic::class,
        'hmdim'   => AktorDriverHomeMaticDimmer::class,
        'generic' => AktorDriverGeneric::class,
    ];

    private const PROFILE_DURATION = 'AKTC.Duration';
    private const PROFILE_TOTAL = 'AKTC.TotalSeconds';
    private const DEFAULT_PERMANENT = 999999;
    private const VERIFY_AFTER_COMMAND = 10;          // Sekunden bis zur Kontrolle nach einem AUS-Befehl / zwischen Wiederholungen
    private const STAT_IDENT_PREFIX = 'TotalOnSeconds_';
    private const PROFILE_GROUP = 'AKTC.GroupState';
    private const GROUP_IDENT_PREFIX = 'Group_';
    private const GROUP_INFO_PREFIX = 'GroupInfo_';
    private const TIME_IDENT_PREFIX = 'TimeSwitch_'; 

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('PermanentValue', self::DEFAULT_PERMANENT);
        $this->RegisterPropertyInteger('VerifyDelay', 10);
        $this->RegisterPropertyInteger('VerifyRetries', 2);
        $this->RegisterPropertyInteger('RemainingUpdate', 1);
        $this->RegisterPropertyInteger('StatsInterval', 60);
        $this->RegisterPropertyString('Groups', '[]');                 // [{Name}]
        $this->RegisterPropertyBoolean('GroupSkipUnchanged', true);    // Gruppenbefehl überspringt Aktoren, die schon im Zielzustand sind

        // Laufzeitdaten, alle nach SwitchVar indiziert
        $this->RegisterAttributeString('Timers', '{}');      // {sw: {End, Mode: sw|native, Seconds}}
        $this->RegisterAttributeString('Verify', '{}');      // {sw: {Check, Retry, Reason}}  Kontrolle "ist wirklich AUS"
        $this->RegisterAttributeString('OnSince', '{}');     // {sw: Zeitstempel}              Betriebszeit-Checkpoint
        $this->RegisterAttributeString('ManagedVars', '[]'); // Time-Switch-Variablen mit gesetzter Custom Action

        $this->RegisterTimer('Tick', 0, 'AKTC_Tick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StatsTick', 0, 'AKTC_StatsTick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $this->ensureProfiles();

        $this->RegisterVariableInteger('activTimer', 'Aktive Timer', '', 100);
        $this->RegisterVariableInteger('tickCnt', 'Tick Cnt', '', 900);

        // Proxy-Skript: Custom Actions akzeptieren nur Skripte, daher Weiterleitung an die Instanz
        $scriptID = $this->RegisterScript(
            'ActionProxy',
            'Action Proxy',
            '<?php IPS_RequestAction(' . $this->InstanceID . ', $_IPS[\'VARIABLE\'], $_IPS[\'VALUE\']);'
        );
        IPS_SetHidden($scriptID, true);

        $devices = $this->getDevices(true);

        // Betriebszeit-Variablen anlegen (gleiche Ebene wie die Time-Switch-Variable, sonst wie die Schaltvariable)
        $created = false;
        foreach ($devices as $device) {
            if ($device['Count'] && $device['StatVar'] === 0) {
                $this->createStatVar($device);
                $created = true;
            }
        }
        if ($created) {
            $devices = $this->getDevices();
        }

        // Aus der Liste entfernte Time-Switch-Variablen: Custom Action zurücksetzen, sofern sie auf unser Skript zeigt
        $currentTimeVars = [];
        foreach ($devices as $device) {
            if ($device['TimeVar'] > 0) {
                $currentTimeVars[] = $device['TimeVar'];
            }
        }
        foreach (json_decode($this->ReadAttributeString('ManagedVars'), true) ?: [] as $oldVar) {
            $oldVar = (int) $oldVar;
            if (!in_array($oldVar, $currentTimeVars, true) && IPS_VariableExists($oldVar)
                && IPS_GetVariable($oldVar)['VariableCustomAction'] === $scriptID) {
                IPS_SetVariableCustomAction($oldVar, 0);
            }
            // Vom Modul vergebenen Ident wieder freigeben
            if (!in_array($oldVar, $currentTimeVars, true) && IPS_VariableExists($oldVar)
                && strpos(IPS_GetObject($oldVar)['ObjectIdent'], self::TIME_IDENT_PREFIX) === 0) {
                @IPS_SetIdent($oldVar, '');
            }
        }
        $this->WriteAttributeString('ManagedVars', json_encode($currentTimeVars));

        foreach ($devices as $device) {
            if ($device['TimeVar'] > 0) {
                $timeVar = $device['TimeVar'];
                IPS_SetVariableCustomAction($timeVar, $scriptID);
                $this->ensureTimeIdent($device);  
                // Profil nur setzen, wenn die Variable noch keines hat
                $var = IPS_GetVariable($timeVar);
                if ($var['VariableProfile'] === '' && $var['VariableCustomProfile'] === '') {
                    IPS_SetVariableCustomProfile($timeVar, self::PROFILE_DURATION);
                }
            }
            $this->RegisterMessage($device['SwitchVar'], VM_UPDATE);
        }

        $this->reconcile($devices);
        $this->syncGroupVariables($devices);
        $this->refreshRunState(true);
        $this->SetStatus(102);
    }

    // ------------------------------------------------------------------
    // Aktion der Time-Switch-Variable
    // ------------------------------------------------------------------

    /** Wird über die Aktion der Time-Switch-Variable aufgerufen. */
    public function RequestAction($Ident, $Value)
    {
        $this->SendDebug(__FUNCTION__, 'Ident=' . json_encode($Ident) . ' Value=' . json_encode($Value), 0);

        // Gruppenvariable (Instanzvariable mit EnableAction: Ident ist ein Text)
        if (is_string($Ident) && strpos($Ident, self::GROUP_IDENT_PREFIX) === 0) {
            $this->requestGroup($Ident, (int) $Value);
            return;
        }

        $switchVar = $this->resolveSwitchVar($Ident);
        if ($switchVar === null) {
            $this->LogMessage('RequestAction: keine Zuordnung für Ident ' . json_encode($Ident), KL_WARNING);
            return;
        }

        $seconds = max(0, (int) $Value);
        if ($seconds === 0) {
            $this->SwitchOff($switchVar);
        } elseif ($seconds === $this->permanentValue()) {
            $this->SwitchOn($switchVar);
        } else {
            $this->SwitchTimed($switchVar, $seconds);
        }
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (AKTC_...)
    // ------------------------------------------------------------------

    /** AKTC_SwitchOn($id, $SwitchVar) – EIN ohne Zeit (Dauer-EIN). */
    public function SwitchOn(int $SwitchVar): bool
    {
        $device = $this->getDevices()[$SwitchVar] ?? null;
        if ($device === null) {
            $this->LogMessage('SwitchOn: Schaltvariable ' . $SwitchVar . ' ist nicht konfiguriert', KL_WARNING);
            return false;
        }
        return $this->switchOnInternal($device, 0);
    }

    /** AKTC_SwitchTimed($id, $SwitchVar, $Seconds) – EIN für $Seconds Sekunden. */
    public function SwitchTimed(int $SwitchVar, int $Seconds): bool
    {
        $device = $this->getDevices()[$SwitchVar] ?? null;
        if ($device === null) {
            $this->LogMessage('SwitchTimed: Schaltvariable ' . $SwitchVar . ' ist nicht konfiguriert', KL_WARNING);
            return false;
        }
        if ($Seconds <= 0) {
            return false;
        }
        // Der Dauer-EIN-Wert der Time-Switch-Variable ist nur ein Anzeigewert, kein Timer
        return $this->switchOnInternal($device, $Seconds === $this->permanentValue() ? 0 : $Seconds);
    }

    /** AKTC_SwitchOff($id, $SwitchVar) – AUS, beendet einen laufenden Timer. */
    public function SwitchOff(int $SwitchVar): bool
    {
        $device = $this->getDevices()[$SwitchVar] ?? null;
        if ($device === null) {
            $this->LogMessage('SwitchOff: Schaltvariable ' . $SwitchVar . ' ist nicht konfiguriert', KL_WARNING);
            return false;
        }
        return $this->switchOffInternal($device, 'Befehl');
    }

    /** Konfigurierte und gültige Zeilen, indiziert nach SwitchVar. */
    public function GetConfiguredDevices(): array
    {
        return $this->getDevices();
    }

    /**
     * AKTC_GetActivTimer($id, $asText) – alle laufenden Timer (Modul-Timer und HomeMatic ON_TIME)
     * plus abgelaufene, die noch auf die Kontrolle "wirklich AUS" warten.
     */
    public function GetActivTimer(bool $asText)
    {
        $now = time();
        $list = [];
        foreach ($this->readTable('Timers') as $sw => $timer) {
            $list[] = [
                'SwitchVar' => $sw,
                'Name'      => IPS_VariableExists($sw) ? IPS_GetLocation($sw) : '?',
                'Mode'      => $timer['Mode'],
                'Phase'     => 'run',
                'Seconds'   => (int) $timer['Seconds'],
                'End'       => (int) $timer['End'],
                'Remaining' => max(0, (int) $timer['End'] - $now),
            ];
        }
        foreach ($this->readTable('Verify') as $sw => $verify) {
            $list[] = [
                'SwitchVar' => $sw,
                'Name'      => IPS_VariableExists($sw) ? IPS_GetLocation($sw) : '?',
                'Mode'      => (string) $verify['Reason'],
                'Phase'     => 'verify',
                'Seconds'   => 0,
                'End'       => (int) $verify['Check'],
                'Remaining' => 0,
                'Retry'     => (int) $verify['Retry'],
            ];
        }
        if (!$asText) {
            return $list;
        }

        $text = sprintf("Aktive Timer: %d\n", count($list));
        foreach ($list as $entry) {
            if ($entry['Phase'] === 'run') {
                $text .= sprintf("[%d] %s | %s | noch %s (Ende %s)\n", $entry['SwitchVar'], $entry['Name'], $entry['Mode'] === 'native' ? 'HM ON_TIME' : 'Modul-Timer', $this->formatDuration($entry['Remaining']), date('H:i:s', $entry['End']));
            } else {
                $text .= sprintf("[%d] %s | Kontrolle AUS in %d s (Versuch %d/%d, Grund: %s)\n", $entry['SwitchVar'], $entry['Name'], max(0, $entry['End'] - $now), $entry['Retry'], $this->ReadPropertyInteger('VerifyRetries'), $entry['Mode']);
            }
        }
        return $text;
    }

    /** AKTC_GetActiveDevices($id, $asText) – alle gerade eingeschalteten Aktoren (auch Dauer-EIN / extern). */
    public function GetActiveDevices(bool $asText)
    {
        $now = time();
        $timers = $this->readTable('Timers');
        $list = [];
        foreach ($this->getDevices() as $sw => $device) {
            if (!(bool) GetValue($sw)) {
                continue;
            }
            $list[] = [
                'SwitchVar' => $sw,
                'Name'      => IPS_GetLocation($sw),
                'Mode'      => isset($timers[$sw]) ? 'timer' : 'dauer',
                'Remaining' => isset($timers[$sw]) ? max(0, (int) $timers[$sw]['End'] - $now) : 0,
            ];
        }
        if (!$asText) {
            return $list;
        }
        $text = sprintf("Eingeschaltete Aktoren: %d\n", count($list));
        foreach ($list as $entry) {
            $text .= sprintf("[%d] %s | %s\n", $entry['SwitchVar'], $entry['Name'], $entry['Mode'] === 'timer' ? 'noch ' . $this->formatDuration($entry['Remaining']) : 'Dauer-EIN / extern');
        }
        return $text;
    }

    /** AKTC_GroupOn($id, $Group) – alle Mitglieder der Gruppe EIN (Dauer-EIN). true, wenn kein Befehl fehlgeschlagen ist. */
    public function GroupOn(string $Group): bool
    {
        return $this->groupCommand($Group, 'on', 0);
    }

    /** AKTC_GroupTimed($id, $Group, $Seconds) – alle Mitglieder der Gruppe für $Seconds Sekunden EIN. */
    public function GroupTimed(string $Group, int $Seconds): bool
    {
        if ($Seconds <= 0) {
            return false;
        }
        return $this->groupCommand($Group, 'on', $Seconds === $this->permanentValue() ? 0 : $Seconds);
    }

    /** AKTC_GroupOff($id, $Group) – alle Mitglieder der Gruppe AUS. */
    public function GroupOff(string $Group): bool
    {
        return $this->groupCommand($Group, 'off', 0);
    }

    /** AKTC_GetGroupList($id, $asText) – alle Gruppen mit Zustand (0 = alle aus, 1 = teilweise, 2 = alle ein). */
    public function GetGroupList(bool $asText)
    {
        $list = [];
        foreach ($this->getGroups($this->getDevices()) as $group) {
            $on = $this->countOn($group['Members']);
            $list[] = [
                'Name'     => $group['Name'],
                'Members'  => count($group['Members']),
                'On'       => $on,
                'State'    => $this->groupState($on, count($group['Members'])),
                'Variable' => (int) @$this->GetIDForIdent($group['Ident']),
            ];
        }
        if (!$asText) {
            return $list;
        }
        $text = sprintf("Gruppen: %d\n", count($list));
        foreach ($list as $entry) {
            $text .= sprintf("%s | %d/%d EIN\n", $entry['Name'], $entry['On'], $entry['Members']);
        }
        return $text;
    }

    /** AKTC_GetGroupState($id, $Group, $asText) – Zustand und Mitglieder einer Gruppe, false bei unbekannter Gruppe. */
    public function GetGroupState(string $Group, bool $asText)
    {
        $group = $this->findGroup($this->getGroups($this->getDevices()), $Group);
        if ($group === null) {
            $this->LogMessage(sprintf('Gruppe "%s" ist nicht konfiguriert', $Group), KL_WARNING);
            return false;
        }
        $now = time();
        $timers = $this->readTable('Timers');
        $devices = [];
        foreach ($group['Members'] as $sw) {
            $devices[] = [
                'SwitchVar' => $sw,
                'Name'      => IPS_GetLocation($sw),
                'On'        => (bool) GetValue($sw),
                'Remaining' => isset($timers[$sw]) ? max(0, (int) $timers[$sw]['End'] - $now) : null,
            ];
        }
        $total = count($devices);
        $on = count(array_filter($devices, fn($d) => $d['On']));
        $result = [
            'Name'    => $group['Name'],
            'Members' => $total,
            'On'      => $on,
            'State'   => $this->groupState($on, $total),
            'Devices' => $devices,
        ];
        if (!$asText) {
            return $result;
        }
        $text = sprintf("Gruppe %s: %d/%d EIN\n", $result['Name'], $on, $total);
        foreach ($devices as $d) {
            $text .= sprintf("[%d] %s | %s\n", $d['SwitchVar'], $d['Name'], $d['On'] ? ($d['Remaining'] !== null ? 'EIN, noch ' . $this->formatDuration($d['Remaining']) : 'EIN') : 'AUS');
        }
        return $text;
    }

    /** AKTC_GetTotalOnSeconds($id, $SwitchVar) – gesamte Einschaltdauer inklusive des aktuell laufenden Abschnitts. */
    public function GetTotalOnSeconds(int $SwitchVar): int
    {
        $device = $this->getDevices()[$SwitchVar] ?? null;
        if ($device === null || $device['StatVar'] === 0) {
            return 0;
        }
        $total = (int) GetValue($device['StatVar']);
        $since = $this->readTable('OnSince')[$SwitchVar] ?? null;
        if ($since !== null) {
            $total += max(0, time() - (int) $since);
        }
        return $total;
    }

    /** AKTC_ResetTotalOnSeconds($id, $SwitchVar) – Betriebszeit auf 0 (z. B. nach Leuchtmitteltausch). */
    public function ResetTotalOnSeconds(int $SwitchVar): bool
    {
        $device = $this->getDevices()[$SwitchVar] ?? null;
        if ($device === null || $device['StatVar'] === 0) {
            return false;
        }
        SetValue($device['StatVar'], 0);
        if (isset($this->readTable('OnSince')[$SwitchVar])) {
            $this->mutate('OnSince', $SwitchVar, time());
        }
        $this->SendDebug(__FUNCTION__, sprintf('Betriebszeit zurückgesetzt :: [%d] %s', $SwitchVar, IPS_GetLocation($SwitchVar)), 0);
        return true;
    }

    public function Export(): string
    {
        return $this->exportConfig();
    }

    public function Import(string $Json, bool $Merge): void
    {
        $this->importConfig($Json, $Merge);
    }

    // ------------------------------------------------------------------
    // Timer
    // ------------------------------------------------------------------

    /** Sekundentakt, nur aktiv solange Timer oder Kontrollen anstehen. */
    public function Tick()
    {
        $tickCnt = $this->GetIDForIdent('tickCnt');
        SetValue($tickCnt, (int) GetValue($tickCnt) + 1);

        $devices = $this->getDevices();
        $now = time();

        foreach ($this->readTable('Timers') as $sw => $timer) {
            if (!isset($devices[$sw])) {
                $this->mutate('Timers', $sw, null);
                continue;
            }
            $remaining = (int) $timer['End'] - $now;
            if ($remaining > 0) {
                $this->showRemaining($devices[$sw], $remaining);
                continue;
            }
            // Zwischenzeitlich (z. B. durch OFF-Meldung) schon entfernt?
            $current = $this->readTable('Timers')[$sw] ?? null;
            if ($current !== null) {
                $this->expire($devices[$sw], $current);
            }
        }

        foreach ($this->readTable('Verify') as $sw => $verify) {
            if (!isset($devices[$sw])) {
                $this->mutate('Verify', $sw, null);
                continue;
            }
            if ((int) $verify['Check'] > $now) {
                continue;
            }
            $current = $this->readTable('Verify')[$sw] ?? null;
            if ($current !== null) {
                $this->verify($devices[$sw], $current);
            }
        }
    }

    /** Betriebszeit-Checkpoint, nur aktiv solange mindestens ein Aktor EIN ist. */
    public function StatsTick()
    {
        $devices = $this->getDevices();
        $now = time();
        foreach ($this->readTable('OnSince') as $sw => $since) {
            if (!isset($devices[$sw]) || $devices[$sw]['StatVar'] === 0) {
                $this->mutate('OnSince', $sw, null);
                continue;
            }
            $seconds = $now - (int) $since;
            if ($seconds <= 0) {
                continue;
            }
            $this->addOnSeconds($devices[$sw], $seconds);
            $this->mutate('OnSince', $sw, $now);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        $devices = $this->getDevices();
        $device = $devices[$SenderID] ?? null;
        if ($device === null) {
            return;
        }
        $on = (bool) $Data[0];
        $this->trackOnTime($device, $on);
        $this->syncState($device, $on);
        if ($device['Groups'] !== []) {
            $this->updateGroupStates($this->getGroups($devices), $device['Groups']);
        }
    }

    // ------------------------------------------------------------------
    // Schalten
    // ------------------------------------------------------------------

    /** $seconds = 0: Dauer-EIN ohne Zeit, sonst EIN mit Zeit. */
    private function switchOnInternal(array $device, int $seconds): bool
    {
        $sw = $device['SwitchVar'];
        $timeVar = $device['TimeVar'];
        $driver = $this->driver($device['Driver']);

        $this->SendDebug(__FUNCTION__, sprintf('%s :: [%d] %s', $seconds > 0 ? $seconds . ' s' : 'Dauer-EIN', $sw, IPS_GetLocation($sw)), 0);

        // Anzeigewert zuerst setzen, damit die VM_UPDATE-Meldung des Aktors nicht als "extern eingeschaltet" gedeutet wird
        $old = null;
        if ($timeVar > 0) {
            $old = (int) GetValue($timeVar);
            $this->setIfChanged($timeVar, $seconds > 0 ? $seconds : $this->permanentValue());
        }

        $ok = $seconds > 0 ? $driver->onTimed($sw, $seconds) : $driver->on($sw);
        if (!$ok) {
            if ($timeVar > 0 && $old !== null) {
                $this->setIfChanged($timeVar, $old);
            }
            $this->LogMessage(sprintf('Einschalten fehlgeschlagen :: [%d] %s (Treiber %s)', $sw, IPS_GetLocation($sw), $device['Driver']), KL_ERROR);
            return false;
        }

        $this->mutate('Verify', $sw, null);
        if ($seconds > 0) {
            $this->mutate('Timers', $sw, [
                'End'     => time() + $seconds,
                'Mode'    => $driver->isNative() ? 'native' : 'sw',
                'Seconds' => $seconds,
            ]);
        } else {
            $this->mutate('Timers', $sw, null);
        }
        return true;
    }

    private function switchOffInternal(array $device, string $reason): bool
    {
        $sw = $device['SwitchVar'];
        $driver = $this->driver($device['Driver']);

        $this->SendDebug(__FUNCTION__, sprintf('Grund=%s :: [%d] %s', $reason, $sw, IPS_GetLocation($sw)), 0);

        $ok = $driver->off($sw);
        $this->mutate('Timers', $sw, null);
        if ($ok) {
            if ($device['TimeVar'] > 0) {
                $this->setIfChanged($device['TimeVar'], 0);
            }
        } else {
            $this->LogMessage(sprintf('Ausschalten fehlgeschlagen :: [%d] %s (Grund %s), Wiederholung folgt', $sw, IPS_GetLocation($sw), $reason), KL_WARNING);
        }
        // Abschlusskontrolle: ist der Aktor wirklich aus? (Bei Fehler wiederholt sie den Befehl.)
        $this->mutate('Verify', $sw, ['Check' => time() + self::VERIFY_AFTER_COMMAND, 'Retry' => 0, 'Reason' => $reason]);
        return $ok;
    }

    /** Timer abgelaufen. */
    private function expire(array $device, array $timer): void
    {
        $sw = $device['SwitchVar'];
        if ($timer['Mode'] === 'native') {
            // Das Gerät beendet die Zeit selbst: nicht schalten, Karenz abwarten, dann Zustand prüfen
            $delay = max(1, $this->ReadPropertyInteger('VerifyDelay'));
            $this->SendDebug(__FUNCTION__, sprintf('Native Zeit abgelaufen, warte %d s auf AUS-Meldung :: [%d] %s', $delay, $sw, IPS_GetLocation($sw)), 0);
            $this->mutate('Timers', $sw, null);
            $this->mutate('Verify', $sw, ['Check' => time() + $delay, 'Retry' => 0, 'Reason' => 'native']);
            if ($device['TimeVar'] > 0) {
                $this->setIfChanged($device['TimeVar'], 0);
            }
            return;
        }
        $this->SendDebug(__FUNCTION__, sprintf('Modul-Timer abgelaufen, schalte AUS :: [%d] %s', $sw, IPS_GetLocation($sw)), 0);
        $this->switchOffInternal($device, 'Timer');
    }

    /** Kontrolle, ob der Aktor nach dem Ablauf / AUS-Befehl wirklich aus ist. */
    private function verify(array $device, array $verify): void
    {
        $sw = $device['SwitchVar'];

        if (!(bool) GetValue($sw)) {
            $this->SendDebug(__FUNCTION__, sprintf('AUS bestätigt :: [%d] %s', $sw, IPS_GetLocation($sw)), 0);
            $this->mutate('Verify', $sw, null);
            return;
        }

        $unreach = $this->isUnreachable($sw) ? ' (Gerät meldet UNREACH)' : '';
        $retry = (int) $verify['Retry'];
        if ($retry >= $this->ReadPropertyInteger('VerifyRetries')) {
            $this->LogMessage(sprintf('Aktor [%d] %s ist trotz AUS-Anforderung weiterhin EIN, gebe nach %d Versuchen auf%s', $sw, IPS_GetLocation($sw), $retry, $unreach), KL_ERROR);
            $this->mutate('Verify', $sw, null);
            if ($device['TimeVar'] > 0) {
                $this->setIfChanged($device['TimeVar'], $this->permanentValue()); // Ist-Zustand anzeigen
            }
            return;
        }

        $this->SendDebug(__FUNCTION__, sprintf('Noch EIN, AUS-Versuch %d/%d%s :: [%d] %s', $retry + 1, $this->ReadPropertyInteger('VerifyRetries'), $unreach, $sw, IPS_GetLocation($sw)), 0);
        $this->driver($device['Driver'])->off($sw);
        $this->mutate('Verify', $sw, ['Check' => time() + self::VERIFY_AFTER_COMMAND, 'Retry' => $retry + 1, 'Reason' => (string) $verify['Reason']]);
    }

    /** Restlaufzeit in die Time-Switch-Variable schreiben (nur wenn sich der Wert um mindestens das Intervall unterscheidet). */
    private function showRemaining(array $device, int $remaining): void
    {
        $interval = $this->ReadPropertyInteger('RemainingUpdate');
        if ($device['TimeVar'] === 0 || $interval <= 0) {
            return;
        }
        $shown = (int) GetValue($device['TimeVar']);
        if (abs($shown - $remaining) >= $interval) {
            SetValue($device['TimeVar'], $remaining);
        }
    }

    // ------------------------------------------------------------------
    // Zustandsabgleich (VM_UPDATE der Schaltvariable)
    // ------------------------------------------------------------------

    /** Time-Switch-Variable, Timer und Kontrolle mit dem Ist-Zustand des Aktors abgleichen. */
    private function syncState(array $device, bool $on): void
    {
        $sw = $device['SwitchVar'];
        $timers = $this->readTable('Timers');
        $verify = $this->readTable('Verify');

        if ($on) {
            // Extern eingeschaltet (Taster, App, anderes Skript): als Dauer-EIN anzeigen.
            // Nicht während Timer oder Kontrolle: dann ist das Einschalten bekannt bzw. die Zeit gerade abgelaufen.
            if ($device['TimeVar'] > 0 && (int) GetValue($device['TimeVar']) === 0 && !isset($timers[$sw]) && !isset($verify[$sw])) {
                $this->SendDebug(__FUNCTION__, sprintf('Extern EIN, zeige Dauer-EIN :: [%d] %s', $sw, IPS_GetLocation($sw)), 0);
                SetValue($device['TimeVar'], $this->permanentValue());
            }
            return;
        }

        // Aus (Ablauf, manuell, Taster): Anzeige zurücksetzen, Timer verwerfen, Kontrolle ist erfüllt
        if ($device['TimeVar'] > 0 && (int) GetValue($device['TimeVar']) > 0) {
            SetValue($device['TimeVar'], 0);
        }
        if (isset($timers[$sw])) {
            $this->SendDebug(__FUNCTION__, sprintf('AUS gemeldet, Timer verworfen :: [%d] %s', $sw, IPS_GetLocation($sw)), 0);
            $this->mutate('Timers', $sw, null);
        }
        if (isset($verify[$sw])) {
            $this->SendDebug(__FUNCTION__, sprintf('AUS bestätigt durch Meldung des Aktors :: [%d] %s', $sw, IPS_GetLocation($sw)), 0);
            $this->mutate('Verify', $sw, null);
        }
    }

    // ------------------------------------------------------------------
    // Betriebszeit
    // ------------------------------------------------------------------

    private function trackOnTime(array $device, bool $on): void
    {
        if ($device['StatVar'] === 0) {
            return;
        }
        $sw = $device['SwitchVar'];
        $since = $this->readTable('OnSince')[$sw] ?? null;
        $now = time();
        if ($on && $since === null) {
            $this->mutate('OnSince', $sw, $now);
        } elseif (!$on && $since !== null) {
            $this->addOnSeconds($device, $now - (int) $since);
            $this->mutate('OnSince', $sw, null);
        }
    }

    private function addOnSeconds(array $device, int $seconds): void
    {
        if ($device['StatVar'] === 0 || $seconds <= 0) {
            return;
        }
        SetValue($device['StatVar'], (int) GetValue($device['StatVar']) + $seconds);
        $this->SendDebug(__FUNCTION__, sprintf('+%d s :: [%d] %s', $seconds, $device['SwitchVar'], IPS_GetLocation($device['SwitchVar'])), 0);
    }

    /** Ident der Time-Switch-Variable: TimeSwitch_<SwitchVar>. Ein selbst vergebener fremder Ident bleibt unangetastet. */
    private function ensureTimeIdent(array $device): void
    {
        $timeVar = $device['TimeVar'];
        $ident = self::TIME_IDENT_PREFIX . $device['SwitchVar'];
        $current = IPS_GetObject($timeVar)['ObjectIdent'];
        if ($current === $ident) {
            return;
        }
        if ($current !== '' && strpos($current, self::TIME_IDENT_PREFIX) !== 0) {
            $this->SendDebug(__FUNCTION__, sprintf('Time Switch [%d] hat bereits den eigenen Ident "%s", unverändert', $timeVar, $current), 0);
            return;
        }
        $existing = @IPS_GetObjectIDByIdent($ident, IPS_GetParent($timeVar));
        if ($existing && (int) $existing !== $timeVar) {
            $this->LogMessage(sprintf('Ident %s ist bereits an [%d] vergeben, Time Switch [%d] bekommt keinen Ident', $ident, (int) $existing, $timeVar), KL_WARNING);
            return;
        }
        if (!@IPS_SetIdent($timeVar, $ident)) {
            $this->LogMessage(sprintf('Ident %s konnte für Time Switch [%d] nicht gesetzt werden', $ident, $timeVar), KL_WARNING);
            return;
        }
        $this->SendDebug(__FUNCTION__, sprintf('Ident %s gesetzt :: [%d]', $ident, $timeVar), 0);
    }

    private function createStatVar(array $device): void
    {
        $parent = $device['TimeVar'] > 0 ? IPS_GetParent($device['TimeVar']) : IPS_GetParent($device['SwitchVar']);
        $id = IPS_CreateVariable(1);
        IPS_SetParent($id, $parent);
        IPS_SetIdent($id, self::STAT_IDENT_PREFIX . $device['SwitchVar']);
        IPS_SetName($id, 'Total Power On Seconds');
        IPS_SetInfo($id, 'Betriebszeit, gepflegt von Aktor Controller [' . $this->InstanceID . '] für Schaltvariable ' . $device['SwitchVar']);
        IPS_SetVariableCustomProfile($id, self::PROFILE_TOTAL);
        $this->SendDebug(__FUNCTION__, sprintf('Betriebszeit-Variable [%d] angelegt unter [%d] :: %s', $id, $parent, IPS_GetLocation($device['SwitchVar'])), 0);
    }

    // ------------------------------------------------------------------
    // Abgleich beim Übernehmen / Start
    // ------------------------------------------------------------------

    private function reconcile(array $devices): void
    {
        $now = time();
        $timers = array_intersect_key($this->readTable('Timers'), $devices);
        $verify = array_intersect_key($this->readTable('Verify'), $devices);
        $onSince = array_intersect_key($this->readTable('OnSince'), $devices);

        foreach ($devices as $sw => $device) {
            $on = (bool) GetValue($sw);

            // Betriebszeit
            if ($device['StatVar'] === 0) {
                unset($onSince[$sw]);
            } else {
                $since = $onSince[$sw] ?? null;
                if ($on && $since === null) {
                    $onSince[$sw] = $now;
                } elseif (!$on && $since !== null) {
                    // AUS-Meldung verpasst: bis zur letzten Änderung der Schaltvariable zählen
                    $changed = (int) IPS_GetVariable($sw)['VariableChanged'];
                    $end = min($now, max((int) $since, $changed));
                    $this->addOnSeconds($device, $end - (int) $since);
                    unset($onSince[$sw]);
                }
            }

            // Time-Switch-Anzeige und Timer
            if (!$on) {
                unset($timers[$sw]);
                if ($device['TimeVar'] > 0 && (int) GetValue($device['TimeVar']) > 0) {
                    SetValue($device['TimeVar'], 0);
                }
            } elseif ($device['TimeVar'] > 0 && (int) GetValue($device['TimeVar']) === 0 && !isset($timers[$sw]) && !isset($verify[$sw])) {
                SetValue($device['TimeVar'], $this->permanentValue());
            }
        }

        $this->writeTable('Timers', $timers);
        $this->writeTable('Verify', $verify);
        $this->writeTable('OnSince', $onSince);
    }

    // ------------------------------------------------------------------
    // Laufzeitdaten
    // ------------------------------------------------------------------

    private function readTable(string $attribute): array
    {
        $raw = json_decode($this->ReadAttributeString($attribute), true) ?: [];
        $table = [];
        foreach ($raw as $key => $value) {
            $table[(int) $key] = $value;
        }
        return $table;
    }

    private function writeTable(string $attribute, array $table): void
    {
        $this->WriteAttributeString($attribute, json_encode((object) $table));
    }

    /**
     * Genau einen Eintrag einer Tabelle ändern (frisch lesen, schreiben).
     * Es werden bewusst keine Treiberaufrufe zwischen Lesen und Schreiben gemacht.
     *
     * @param array|int|null $entry null = Eintrag löschen
     */
    private function mutate(string $attribute, int $sw, $entry): void
    {
        $table = $this->readTable($attribute);
        if ($entry === null) {
            if (!isset($table[$sw])) {
                return;
            }
            unset($table[$sw]);
        } else {
            $table[$sw] = $entry;
        }
        $this->writeTable($attribute, $table);
        $this->refreshRunState(false);
    }

    /** Anzahl aktiver Timer anzeigen; Tick und StatsTick nur laufen lassen, solange sie gebraucht werden. */
    private function refreshRunState(bool $force): void
    {
        $timers = $this->readTable('Timers');
        $pending = count($timers) + count($this->readTable('Verify'));

        $activTimer = $this->GetIDForIdent('activTimer');
        if ((int) GetValue($activTimer) !== count($timers)) {
            SetValue($activTimer, count($timers));
        }

        // SetTimerInterval startet den Timer neu, daher nur bei Zustandswechsel aufrufen
        $tick = $pending > 0 ? '1' : '0';
        if ($force || $this->GetBuffer('Tick') !== $tick) {
            $this->SetTimerInterval('Tick', $pending > 0 ? 1000 : 0);
            $this->SetBuffer('Tick', $tick);
            if ($pending === 0) {
                SetValue($this->GetIDForIdent('tickCnt'), 0);
            }
        }

        $statsInterval = $this->ReadPropertyInteger('StatsInterval');
        $stats = (count($this->readTable('OnSince')) > 0 && $statsInterval > 0) ? '1' : '0';
        if ($force || $this->GetBuffer('Stats') !== $stats) {
            $this->SetTimerInterval('StatsTick', $stats === '1' ? $statsInterval * 1000 : 0);
            $this->SetBuffer('Stats', $stats);
        }
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function permanentValue(): int
    {
        $value = $this->ReadPropertyInteger('PermanentValue');
        return $value > 0 ? $value : self::DEFAULT_PERMANENT;
    }

    private function driver(string $name): AktorDriver
    {
        $class = self::DRIVERS[$name];
        return new $class(function (string $source, string $text): void {
            $this->SendDebug($source, $text, 0);
        });
    }

    private function setIfChanged(int $variable, int $value): void
    {
        if ((int) GetValue($variable) !== $value) {
            SetValue($variable, $value);
        }
    }

    private function isUnreachable(int $switchVar): bool
    {
        $id = @IPS_GetObjectIDByIdent('UNREACH', IPS_GetParent($switchVar));
        return $id && (bool) GetValue((int) $id);
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Gültige Zeilen der Konfiguration, indiziert nach SwitchVar.
     *
     * @return array<int,array{SwitchVar:int,TimeVar:int,Driver:string,Count:bool,StatVar:int,Groups:string[]}>
     */
    private function getDevices(bool $report = false): array
    {
        $result = [];
        $usedTimeVars = [];
        $rows = json_decode($this->ReadPropertyString('Devices'), true) ?: [];
        $groupNames = $this->configuredGroupNames($report);

        foreach ($rows as $index => $row) {
            $label = '#' . ($index + 1);
            $sw = (int) ($row['SwitchVar'] ?? 0);
            $timeVar = (int) ($row['TimeVar'] ?? 0);
            $driver = (string) ($row['Driver'] ?? '');
            $count = (bool) ($row['Count'] ?? true);

            if (!isset(self::DRIVERS[$driver])) {
                $this->warn($report, $label . ': unbekannter Treiber "' . $driver . '", Zeile ignoriert');
                continue;
            }
            if (!IPS_VariableExists($sw)) {
                $this->warn($report, $label . ': Schaltvariable nicht vorhanden, Zeile ignoriert');
                continue;
            }
            if (isset($result[$sw])) {
                $this->warn($report, $label . ': Schaltvariable ' . $sw . ' ist doppelt, Zeile ignoriert');
                continue;
            }
            if ($timeVar > 0) {
                if (!IPS_VariableExists($timeVar) || IPS_GetVariable($timeVar)['VariableType'] !== 1 || $timeVar === $sw) {
                    $this->warn($report, $label . ': Time Switch ist keine gültige Integer-Variable, Zeile läuft ohne Time Switch');
                    $timeVar = 0;
                } elseif (isset($usedTimeVars[$timeVar])) {
                    $this->warn($report, $label . ': Time Switch ' . $timeVar . ' ist doppelt vergeben, Zeile läuft ohne Time Switch');
                    $timeVar = 0;
                } else {
                    $usedTimeVars[$timeVar] = true;
                }
            }

            $statVar = 0;
            if ($count) {
                $parent = $timeVar > 0 ? IPS_GetParent($timeVar) : IPS_GetParent($sw);
                $found = @IPS_GetObjectIDByIdent(self::STAT_IDENT_PREFIX . $sw, $parent);
                $statVar = $found ? (int) $found : 0;
            }

            $groups = [];
            foreach ($this->parseTags((string) ($row['Groups'] ?? '')) as $tag) {
                $key = mb_strtolower($tag);
                if (!isset($groupNames[$key])) {
                    $this->warn($report, $label . ': Gruppe "' . $tag . '" steht nicht in der Gruppenliste, ignoriert');
                    continue;
                }
                if (!in_array($groupNames[$key], $groups, true)) {
                    $groups[] = $groupNames[$key];
                }
            }

            $result[$sw] = [
                'SwitchVar' => $sw,
                'TimeVar'   => $timeVar,
                'Driver'    => $driver,
                'Count'     => $count,
                'StatVar'   => $statVar,
                'Groups'    => $groups,
            ];
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Gruppen
    // ------------------------------------------------------------------

    /** $action: 'on' (mit $seconds = 0 für Dauer-EIN) oder 'off'. */
    private function groupCommand(string $name, string $action, int $seconds): bool
    {
        $devices = $this->getDevices();
        $groups = $this->getGroups($devices);
        $group = $this->findGroup($groups, $name);
        if ($group === null) {
            $this->LogMessage(sprintf('Gruppe "%s" ist nicht konfiguriert', $name), KL_WARNING);
            return false;
        }
        if ($group['Members'] === []) {
            $this->LogMessage(sprintf('Gruppe "%s" hat keine Mitglieder', $group['Name']), KL_WARNING);
            return false;
        }

        $skipUnchanged = $this->ReadPropertyBoolean('GroupSkipUnchanged');
        $timers = $this->readTable('Timers');
        $verify = $this->readTable('Verify');
        $started = microtime(true);
        $done = 0;
        $skipped = 0;
        $failed = [];

        foreach ($group['Members'] as $sw) {
            $on = (bool) GetValue($sw);
            if ($skipUnchanged) {
                // AUS: schon aus und nichts offen. Dauer-EIN: schon ein ohne Timer. Befristetes EIN wird immer gesendet.
                $unchanged = false;
                if ($action === 'off') {
                    $unchanged = !$on && !isset($timers[$sw]) && !isset($verify[$sw]);
                } elseif ($seconds === 0) {
                    $unchanged = $on && !isset($timers[$sw]) && !isset($verify[$sw]);
                }
                if ($unchanged) {
                    $skipped++;
                    continue;
                }
            }
            $ok = $action === 'off'
                ? $this->switchOffInternal($devices[$sw], 'Gruppe ' . $group['Name'])
                : $this->switchOnInternal($devices[$sw], $seconds);
            if ($ok) {
                $done++;
            } else {
                $failed[] = $sw;
            }
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $this->SendDebug(__FUNCTION__, sprintf('Gruppe %s %s%s: %d geschaltet, %d übersprungen, %d fehlgeschlagen, %d ms', $group['Name'], $action === 'off' ? 'AUS' : 'EIN', $seconds > 0 ? ' ' . $seconds . ' s' : '', $done, $skipped, count($failed), $ms), 0);
        if ($failed !== []) {
            $names = array_map(fn($sw) => '[' . $sw . '] ' . IPS_GetLocation($sw), $failed);
            $this->LogMessage(sprintf('Gruppe %s: %d von %d Befehlen fehlgeschlagen: %s', $group['Name'], count($failed), count($group['Members']), implode('; ', $names)), KL_WARNING);
        }
        if ($ms > 5000) {
            $this->LogMessage(sprintf('Gruppe %s: der Befehl dauerte %d ms bei %d Aktoren', $group['Name'], $ms, count($group['Members'])), KL_WARNING);
        }
        $this->updateGroupStates($groups, [$group['Name']]);
        return $failed === [];
    }

    /** Aktion einer Gruppenvariable: 0 = alle AUS, 2 = alle EIN (Dauer), 1 (Teilweise) ist nur eine Anzeige. */
    private function requestGroup(string $ident, int $value): void
    {
        foreach ($this->getGroups($this->getDevices()) as $group) {
            if ($group['Ident'] !== $ident) {
                continue;
            }
            if ($value === 0) {
                $this->groupCommand($group['Name'], 'off', 0);
            } elseif ($value === 2) {
                $this->groupCommand($group['Name'], 'on', 0);
            } else {
                $this->SendDebug(__FUNCTION__, sprintf('Wert %d bei Gruppe %s ignoriert (nur 0 = Aus und 2 = Ein schalten)', $value, $group['Name']), 0);
            }
            return;
        }
        $this->LogMessage('RequestAction: unbekannte Gruppenvariable ' . $ident, KL_WARNING);
    }

    /** Gruppenvariablen (Zustand und Text) als Instanzvariablen anlegen, nicht mehr benötigte entfernen. */
    private function syncGroupVariables(array $devices): void
    {
        $groups = $this->getGroups($devices);
        $keep = [];
        $position = 200;
        foreach ($groups as $group) {
            $this->RegisterVariableInteger($group['Ident'], $group['Name'], self::PROFILE_GROUP, $position);
            $this->EnableAction($group['Ident']);
            $this->RegisterVariableString($group['InfoIdent'], $group['Name'] . ' Status', '', $position + 1);
            $keep[$group['Ident']] = true;
            $keep[$group['InfoIdent']] = true;
            $position += 2;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            if (!IPS_VariableExists($child)) {
                continue;
            }
            $ident = IPS_GetObject($child)['ObjectIdent'];
            $isGroupVar = strpos($ident, self::GROUP_IDENT_PREFIX) === 0 || strpos($ident, self::GROUP_INFO_PREFIX) === 0;
            if ($isGroupVar && !isset($keep[$ident])) {
                $this->SendDebug(__FUNCTION__, sprintf('Gruppenvariable %s entfernt (Gruppe nicht mehr konfiguriert)', $ident), 0);
                $this->UnregisterVariable($ident);
            }
        }
        $this->updateGroupStates($groups, null);
    }

    /** Zustand (0/1/2) und Text der Gruppenvariablen aktualisieren. $onlyNames: nur diese Gruppen. */
    private function updateGroupStates(array $groups, ?array $onlyNames): void
    {
        $only = null;
        if ($onlyNames !== null) {
            $only = [];
            foreach ($onlyNames as $name) {
                $only[mb_strtolower($name)] = true;
            }
        }
        foreach ($groups as $key => $group) {
            if ($only !== null && !isset($only[$key])) {
                continue;
            }
            $total = count($group['Members']);
            $on = $this->countOn($group['Members']);

            $stateVar = @$this->GetIDForIdent($group['Ident']);
            if ($stateVar) {
                $this->setIfChanged((int) $stateVar, $this->groupState($on, $total));
            }
            $infoVar = @$this->GetIDForIdent($group['InfoIdent']);
            if ($infoVar) {
                $text = $total === 0 ? 'keine Mitglieder' : sprintf('%d/%d EIN', $on, $total);
                if (GetValue((int) $infoVar) !== $text) {
                    SetValue((int) $infoVar, $text);
                }
            }
        }
    }

    private function countOn(array $members): int
    {
        $on = 0;
        foreach ($members as $sw) {
            if ((bool) GetValue($sw)) {
                $on++;
            }
        }
        return $on;
    }

    private function groupState(int $on, int $total): int
    {
        if ($on === 0) {
            return 0;
        }
        return $on === $total ? 2 : 1;
    }

    /**
     * Gruppen aus der Gruppenliste mit ihren Mitgliedern. Schlüssel = Name in Kleinbuchstaben.
     *
     * @return array<string,array{Name:string,Ident:string,InfoIdent:string,Members:int[]}>
     */
    private function getGroups(array $devices): array
    {
        $groups = [];
        $usedIdents = [];
        foreach ($this->configuredGroupNames(false) as $key => $name) {
            $slug = $this->groupSlug($name);
            if (isset($usedIdents[$slug])) {
                $slug .= '_' . substr(md5($name), 0, 6);
            }
            $usedIdents[$slug] = true;
            $groups[$key] = [
                'Name'      => $name,
                'Ident'     => self::GROUP_IDENT_PREFIX . $slug,
                'InfoIdent' => self::GROUP_INFO_PREFIX . $slug,
                'Members'   => [],
            ];
        }
        foreach ($devices as $sw => $device) {
            foreach ($device['Groups'] as $name) {
                $groups[mb_strtolower($name)]['Members'][] = $sw;
            }
        }
        return $groups;
    }

    private function findGroup(array $groups, string $name): ?array
    {
        return $groups[mb_strtolower(trim($name))] ?? null;
    }

    /** Namen aus der Gruppenliste: Kleinbuchstaben => Name. Leere, doppelte und Namen mit Komma/Semikolon werden ignoriert. */
    private function configuredGroupNames(bool $report): array
    {
        $names = [];
        foreach (json_decode($this->ReadPropertyString('Groups'), true) ?: [] as $index => $row) {
            $name = trim((string) ($row['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (strpbrk($name, ',;') !== false) {
                $this->warn($report, sprintf('Gruppe #%d "%s": Komma und Semikolon sind im Namen nicht erlaubt, ignoriert', $index + 1, $name));
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($names[$key])) {
                $this->warn($report, sprintf('Gruppe #%d "%s" ist doppelt, ignoriert', $index + 1, $name));
                continue;
            }
            $names[$key] = $name;
        }
        return $names;
    }

    /** "EG, Büro; Abwesend" => ['EG', 'Büro', 'Abwesend'] */
    private function parseTags(string $text): array
    {
        $tags = [];
        foreach (preg_split('/[,;]/', $text) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /** Ident-Teil aus dem Namen: nur a-z, 0-9 und _ (Umlaute ersetzt). */
    private function groupSlug(string $name): string
    {
        $slug = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $slug), '_'));
        return $slug !== '' ? $slug : substr(md5($name), 0, 8);
    }

    private function warn(bool $report, string $text): void
    {
        if ($report) {
            $this->LogMessage($text, KL_WARNING);
        }
        $this->SendDebug('Konfiguration', $text, 0);
    }

    private function resolveSwitchVar($Ident): ?int
    {
        $devices = $this->getDevices();
        foreach ($devices as $sw => $device) {
            if ($device['TimeVar'] === 0) {
                continue;
            }
            if (is_numeric($Ident) && $device['TimeVar'] === (int) $Ident) {
                return $sw;
            }
            if ((string) $Ident !== '' && IPS_GetObject($device['TimeVar'])['ObjectIdent'] === (string) $Ident) {
                return $sw;
            }
        }
        return null;
    }

    /** Vom Modul verwaltetes Profil; wird bei jeder Übernahme neu aufgebaut. */
    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_TOTAL)) {
            IPS_CreateVariableProfile(self::PROFILE_TOTAL, 1);
            IPS_SetVariableProfileValues(self::PROFILE_TOTAL, 0, 0, 0);
            IPS_SetVariableProfileText(self::PROFILE_TOTAL, '', ' s');
            IPS_SetVariableProfileIcon(self::PROFILE_TOTAL, 'clock');
        }

        if (!IPS_VariableProfileExists(self::PROFILE_DURATION)) {
            IPS_CreateVariableProfile(self::PROFILE_DURATION, 1);
            IPS_SetVariableProfileValues(self::PROFILE_DURATION, 0, 0, 0);
        }
        // Werte ohne passende Association (Restlaufzeit) werden mit Suffix angezeigt
        //IPS_SetVariableProfileText(self::PROFILE_DURATION, '', ' s');

        // Bestehende Associations entfernen (leere Caption = löschen)
        foreach (IPS_GetVariableProfile(self::PROFILE_DURATION)['Associations'] as $association) {
            IPS_SetVariableProfileAssociation(self::PROFILE_DURATION, $association['Value'], '', '', -1);
        }

        // Wert => [Caption, Icon, Farbe (0xRRGGBB oder -1 für keine)]
        $associations = [
            0                       => ['Aus [%ds]',       'light-switch-off', 0xFF0000],
            30                      => ['30 Sec [%ds]',    'timer',            0xC07EEC],
            60                      => ['1 Min [%ds]',     'timer',            0x00FFDD],
            180                     => ['3 Min [%ds]',     'timer',            0x00FFDD],
            300                     => ['5 Min [%ds]',     'timer',            0x00FFDD],
            600                     => ['10 Min [%ds]',    'timer',            0x00FFDD],
            1200                    => ['20 Min [%ds]',    'timer',            0x00FFDD],
            1800                    => ['30 Min [%ds]',    'timer',            0x00FFDD],
            3600                    => ['1 Std [%ds]',     'timer',            0x00FFDD],
            5400                    => ['1,5 Std [%ds]',   'timer',            0x00FFDD],
            7200                    => ['2 Std [%ds]',     'timer',            0x00FFDD],
            10800                   => ['3 Std [%ds]',     'timer',            0x00FFDD],
            18000                   => ['5 Std [%ds]',     'timer',            0x00FFDD],
            36000                   => ['10 Std[%ds]',      'timer',            0x00FFDD],
            $this->permanentValue() => ['Dauer-EIN',        'light-switch-on',  0x00FF00],
        ];

        foreach ($associations as $value => [$caption, $icon, $color]) {
            IPS_SetVariableProfileAssociation(self::PROFILE_DURATION, $value, $caption, $icon, $color);
        }

        // Gruppenzustand: 0 = alle aus, 1 = teilweise ein (nur Anzeige), 2 = alle ein
        if (!IPS_VariableProfileExists(self::PROFILE_GROUP)) {
            IPS_CreateVariableProfile(self::PROFILE_GROUP, 1);
            IPS_SetVariableProfileValues(self::PROFILE_GROUP, 0, 0, 0);
        }
        foreach (IPS_GetVariableProfile(self::PROFILE_GROUP)['Associations'] as $association) {
            IPS_SetVariableProfileAssociation(self::PROFILE_GROUP, $association['Value'], '', '', -1);
        }
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP, 0, 'Aus', 'light-switch-off', 0xFF0000);
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP, 1, 'Teilweise', 'light-switch', 0xFFC800);
        IPS_SetVariableProfileAssociation(self::PROFILE_GROUP, 2, 'Ein', 'light-switch-on', 0x00FF00);
    }
}
