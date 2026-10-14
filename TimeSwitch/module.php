<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/Drivers.php';

class TimeSwitchController extends IPSModule
{
    private const DRIVERS = [
        'hm'      => TimeSwitchDriverHomeMatic::class,
        'generic' => TimeSwitchDriverGeneric::class,
    ];

    private const PROFILE = 'TSCT.Duration';
    private const DEFAULT_PERMANENT = 999999;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('PermanentValue', self::DEFAULT_PERMANENT);
        $this->RegisterAttributeString('EndTimes', '{}');
        $this->RegisterAttributeString('ManagedVars', '[]');
        $this->RegisterTimer('Tick', 0, 'TSCT_Tick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $this->ensureProfile();

        $this->RegisterVariableInteger("activTimer", "Aktive Timer", "", 100);
        $this->RegisterVariableInteger("tickCnt", "Tick Cnt", "", 900);

        // Proxy-Skript: Custom Actions akzeptieren nur Skripte, daher Weiterleitung an die Instanz
        $scriptID = $this->RegisterScript(
            'ActionProxy',
            'Action Proxy',
            '<?php IPS_RequestAction(' . $this->InstanceID . ', $_IPS[\'VARIABLE\'], $_IPS[\'VALUE\']);'
        );
        IPS_SetHidden($scriptID, true);

        $devices = $this->getDevices();

        // Aus der Liste entfernte Variablen: Custom Action zurücksetzen, sofern sie auf unser Skript zeigt
        $previous = json_decode($this->ReadAttributeString('ManagedVars'), true) ?: [];
        foreach ($previous as $oldVar) {
            $oldVar = (int) $oldVar;
            if (!isset($devices[$oldVar]) && IPS_VariableExists($oldVar)
                && IPS_GetVariable($oldVar)['VariableCustomAction'] === $scriptID) {
                IPS_SetVariableCustomAction($oldVar, 0);
            }
        }
        $this->WriteAttributeString('ManagedVars', json_encode(array_keys($devices)));

        foreach ($devices as $timeVar => $device) {
            IPS_SetVariableCustomAction($timeVar, $scriptID);
            // Profil nur setzen, wenn die Variable noch keines hat
            $var = IPS_GetVariable($timeVar);
            if ($var['VariableProfile'] === '' && $var['VariableCustomProfile'] === '') {
                IPS_SetVariableCustomProfile($timeVar, self::PROFILE);
            }
            $this->RegisterMessage($device['SwitchVar'], VM_UPDATE);
        }

        // Laufzeiten von entfernten Zeilen verwerfen
        $end = array_intersect_key($this->readEnd(), $devices);
        $this->writeEnd($end);
        $this->SetStatus(102);
    }

    /** Wird über die Aktion der Time-Switch-Variable aufgerufen. */
    public function RequestAction($Ident, $Value)
    {
        //$this->SendDebug('RequestAction', 'Ident=' . json_encode($Ident) . ' Value=' . json_encode($Value), 0);
        $this->SendDebug(__METHOD__, 'Ident=' . json_encode($Ident) . ' Value=' . json_encode($Value), 0);

        $timeVar = $this->resolveTimeVar($Ident);
        if ($timeVar === null) {
            $this->LogMessage('RequestAction: keine Zuordnung für Ident ' . json_encode($Ident), KL_WARNING);
            return;
        }

        $seconds = max(0, (int) $Value);
        if ($seconds > 0) {
            $this->Start($timeVar, $seconds);
        } else {
            $this->Cancel($timeVar);
        }
    }

    /**
     * TSCT_Start($id, $TimeVar, $Seconds) – auch per Skript nutzbar.
     * $Seconds == PermanentValue schaltet dauerhaft ein (ohne Timer, ON_TIME = 0).
     */
    public function Start(int $TimeVar, int $Seconds): bool
    {
        $devices = $this->getDevices();
        if (!isset($devices[$TimeVar]) || $Seconds <= 0) {
            return false;
        }
        $device = $devices[$TimeVar];
        $driver = self::DRIVERS[$device['Driver']];
        $permanent = ($Seconds === $this->permanentValue());

        // Wert zuerst setzen, damit die VM_UPDATE-Meldung des Aktors ihn nicht als "extern eingeschaltet" deutet
        $old = (int) GetValue($TimeVar);
        SetValue($TimeVar, $Seconds);

        if (!$driver::start($device['SwitchVar'], $permanent ? 0 : $Seconds)) {
            SetValue($TimeVar, $old);
            $this->LogMessage(sprintf('Start fehlgeschlagen (Time Switch %d, Treiber %s)', $TimeVar, $device['Driver']), KL_ERROR);
            return false;
        }

        $end = $this->readEnd();
        if ($permanent || $driver::isNative()) {
            unset($end[$TimeVar]);
        } else {
            $end[$TimeVar] = time() + $Seconds;
        }
        $this->writeEnd($end);
        return true;
    }

    /** TSCT_Cancel($id, $TimeVar) */
    public function Cancel(int $TimeVar): bool
    {
        $devices = $this->getDevices();
        if (!isset($devices[$TimeVar])) {
            return false;
        }
        $device = $devices[$TimeVar];
        $driver = self::DRIVERS[$device['Driver']];

        $ok = $driver::stop($device['SwitchVar']);
        SetValue($TimeVar, 0);

        $end = $this->readEnd();
        unset($end[$TimeVar]);
        $this->writeEnd($end);
        return $ok;
    }

    /** Sekundentakt, nur aktiv solange Software-Timer laufen. */
    public function Tick()
    {
        $varId_tickCnt = $this->GetIDForIdent("tickCnt");
        SetValue($varId_tickCnt, GetValue($varId_tickCnt) + 1); 
        $devices = $this->getDevices();
        $end = $this->readEnd();
        $now = time();
       
        foreach ($end as $timeVar => $timestamp) {
            if ($timestamp > $now) {
                continue;
            }
            unset($end[$timeVar]);
            if (isset($devices[$timeVar])) {
                $driver = self::DRIVERS[$devices[$timeVar]['Driver']];
                $driver::stop($devices[$timeVar]['SwitchVar']);
                SetValue($timeVar, 0);
            }
        }
        $this->writeEnd($end);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        $on = (bool) $Data[0];
        $end = $this->readEnd();

        foreach ($this->getDevices() as $timeVar => $device) {
            if ($device['SwitchVar'] !== $SenderID) {
                continue;
            }
            $current = (int) GetValue($timeVar);
            if ($on && $current === 0) {
                // Extern eingeschaltet (Taster, App, anderes Skript): als Dauer-EIN anzeigen
                SetValue($timeVar, $this->permanentValue());
            } elseif (!$on && $current > 0) {
                // Aus (Ablauf, manuell, Taster): zurücksetzen, Software-Timer verwerfen
                SetValue($timeVar, 0);
                unset($end[$timeVar]);
            }
        }
        $this->writeEnd($end);
    }

    private function permanentValue(): int
    {
        $value = $this->ReadPropertyInteger('PermanentValue');
        return $value > 0 ? $value : self::DEFAULT_PERMANENT;
    }

    /** Vom Modul verwaltetes Profil; wird bei jeder Übernahme neu aufgebaut. */
    private function ensureProfile(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE)) {
            IPS_CreateVariableProfile(self::PROFILE, 1);
            IPS_SetVariableProfileValues(self::PROFILE, 0, 0, 0);
        }
        foreach (IPS_GetVariableProfile(self::PROFILE)['Associations'] as $association) {
            IPS_SetVariableProfileAssociation(self::PROFILE, $association['Value'], '', '', -1);
        }
        $associations = [
            0                       => 'Aus',
            30                      => '30 Sec',
            60                      => '1 Min',
            180                     => '3 Min',
            300                     => '5 Min',
            600                     => '10 Min',
            1200                    => '20 Min',
            1800                    => '30 Min',
            3600                    => '1 Std',
            5400                    => '1,5 Std',
            7200                    => '2 Std',
            10800                   => '3 Std',
            18000                   => '5 Std',   
            36000                   => '10 Std',   
            $this->permanentValue() => 'Dauer-EIN',
        ];
        foreach ($associations as $value => $caption) {
            IPS_SetVariableProfileAssociation(self::PROFILE, $value, $caption, '', -1);
        }
    }

    /** @return array<int,array{TimeVar:int,SwitchVar:int,Driver:string}> indexiert nach TimeVar */
    private function getDevices(): array
    {
        $result = [];
        $rows = json_decode($this->ReadPropertyString('Devices'), true) ?: [];
        foreach ($rows as $row) {
            $timeVar = (int) ($row['TimeVar'] ?? 0);
            $switchVar = (int) ($row['SwitchVar'] ?? 0);
            $driver = (string) ($row['Driver'] ?? '');
            if (!isset(self::DRIVERS[$driver])
                || !IPS_VariableExists($timeVar)
                || !IPS_VariableExists($switchVar)) {
                continue;
            }
            $result[$timeVar] = ['TimeVar' => $timeVar, 'SwitchVar' => $switchVar, 'Driver' => $driver];
        }
        return $result;
    }

    private function resolveTimeVar($Ident): ?int
    {
        $devices = $this->getDevices();
        if (is_numeric($Ident) && isset($devices[(int) $Ident])) {
            return (int) $Ident;
        }
        if ((string) $Ident !== '') {
            foreach ($devices as $timeVar => $device) {
                if (IPS_GetObject($timeVar)['ObjectIdent'] === (string) $Ident) {
                    return $timeVar;
                }
            }
        }
        return null;
    }

    private function readEnd(): array
    {
        $raw = json_decode($this->ReadAttributeString('EndTimes'), true) ?: [];
        $end = [];
        foreach ($raw as $timeVar => $timestamp) {
            $end[(int) $timeVar] = (int) $timestamp;
        }
        return $end;
    }
    
    public function GetTimrSwitchDevices() : array {
        return $this->getDevices();
    }

    public function GetActivTimer(bool $asText) {
        $timerArr = $this->readEnd();
        if($asText) {
            $activTimerInfo = "Activ Timer Info\n";
            foreach($timerArr as $timeVar => $timestamp) {
                $activTimerInfo .= sprintf("[%d] %s | %d OFF in %d Sec\n", $timeVar, IPS_GetLocation($timeVar), $timestamp, $timestamp - time()); 
            }
            return $activTimerInfo;
        } else {
            return $timerArr;
        }

    }


    private function writeEnd(array $end): void
    {
        $this->WriteAttributeString('EndTimes', json_encode((object) $end));

        SetValue($this->GetIDForIdent("activTimer"), count($end)); 

        //$this->SetTimerInterval('Tick', count($end) > 0 ? 1000 : 0);
        if(count($end) > 0) {
            $this->SetTimerInterval('Tick', 1000);
        } else {
            SetValue($this->GetIDForIdent("tickCnt"), 0); 
            $this->SetTimerInterval('Tick', 0);
        }
        
    }
}
