<?php

declare(strict_types=1);

/**
 * Export/Import der Zuordnungstabelle und der Einstellungen als JSON.
 * Einbinden in der Modulklasse mit `use AktorControllerExportImport;`
 *
 * Exportiert werden pro Zeile die Variablen-IDs UND ihre Pfade (IPS_GetLocation).
 * Beim Import wird zuerst die ID genommen (wenn der Pfad noch passt), sonst der Pfad aufgelöst.
 * So funktioniert der Import auch nach einer Neuinstallation oder auf einem anderen System.
 *
 * Betriebszeiten (TotalOnSeconds) sind Laufzeitdaten und nicht Teil der Konfiguration.
 */
trait AktorControllerExportImport
{
    private const EXCHANGE_SETTINGS = ['PermanentValue', 'VerifyDelay', 'VerifyRetries', 'RemainingUpdate', 'StatsInterval'];

    protected function exportConfig(): string
    {
        $rows = json_decode($this->ReadPropertyString('Devices'), true) ?: [];
        $devices = [];
        foreach ($rows as $row) {
            $switchVar = (int) ($row['SwitchVar'] ?? 0);
            $timeVar = (int) ($row['TimeVar'] ?? 0);
            $devices[] = [
                'SwitchVar'     => $switchVar,
                'SwitchVarPath' => IPS_VariableExists($switchVar) ? IPS_GetLocation($switchVar) : '',
                'TimeVar'       => $timeVar,
                'TimeVarPath'   => IPS_VariableExists($timeVar) ? IPS_GetLocation($timeVar) : '',
                'Driver'        => (string) ($row['Driver'] ?? ''),
                'Count'         => (bool) ($row['Count'] ?? true),
            ];
        }

        $settings = [];
        foreach (self::EXCHANGE_SETTINGS as $name) {
            $settings[$name] = $this->ReadPropertyInteger($name);
        }

        $json = json_encode([
            'Format'   => 'AktorController',
            'Version'  => 1,
            'Settings' => $settings,
            'Devices'  => $devices,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->UpdateFormField('ExchangeText', 'value', $json);
        $this->UpdateFormField('ExchangeResult', 'caption', sprintf('Export: %d Zeilen (gespeicherter Stand).', count($devices)));
        return $json;
    }

    protected function importConfig(string $json, bool $merge): void
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || ($doc['Format'] ?? '') !== 'AktorController' || !is_array($doc['Devices'] ?? null)) {
            $this->UpdateFormField('ExchangeResult', 'caption', 'Import abgebrochen: kein gültiges AktorController-JSON.');
            return;
        }

        $bySwitchVar = [];
        if ($merge) {
            foreach (json_decode($this->ReadPropertyString('Devices'), true) ?: [] as $row) {
                $bySwitchVar[(int) $row['SwitchVar']] = [
                    'SwitchVar' => (int) $row['SwitchVar'],
                    'TimeVar'   => (int) ($row['TimeVar'] ?? 0),
                    'Driver'    => (string) $row['Driver'],
                    'Count'     => (bool) ($row['Count'] ?? true),
                ];
            }
        }

        $loaded = 0;
        $messages = [];
        foreach ($doc['Devices'] as $index => $entry) {
            $label = '#' . ($index + 1);
            $driver = (string) ($entry['Driver'] ?? '');
            if (!isset(self::DRIVERS[$driver])) {
                $messages[] = $label . ': unbekannter Treiber';
                continue;
            }

            $switchVar = $this->resolveImportVariable((int) ($entry['SwitchVar'] ?? 0), (string) ($entry['SwitchVarPath'] ?? ''), $w1);
            if ($switchVar === 0) {
                $messages[] = $label . ': Schaltvariable nicht gefunden';
                continue;
            }
            if ($w1 !== null) {
                $messages[] = $label . ': ' . $w1;
            }

            // Time Switch ist optional
            $timeVar = 0;
            if ((int) ($entry['TimeVar'] ?? 0) > 0 || (string) ($entry['TimeVarPath'] ?? '') !== '') {
                $timeVar = $this->resolveImportVariable((int) ($entry['TimeVar'] ?? 0), (string) ($entry['TimeVarPath'] ?? ''), $w2);
                if ($timeVar === 0) {
                    $messages[] = $label . ': Time Switch nicht gefunden, Zeile ohne Time Switch';
                } elseif (IPS_GetVariable($timeVar)['VariableType'] !== 1) {
                    $messages[] = $label . ': Time Switch ist keine Integer-Variable, Zeile ohne Time Switch';
                    $timeVar = 0;
                } elseif ($w2 !== null) {
                    $messages[] = $label . ': ' . $w2;
                }
            }

            $bySwitchVar[$switchVar] = [
                'SwitchVar' => $switchVar,
                'TimeVar'   => $timeVar,
                'Driver'    => $driver,
                'Count'     => (bool) ($entry['Count'] ?? true),
            ];
            $loaded++;
        }

        $this->UpdateFormField('Devices', 'values', json_encode(array_values($bySwitchVar)));
        if (!$merge && is_array($doc['Settings'] ?? null)) {
            foreach (self::EXCHANGE_SETTINGS as $name) {
                if (isset($doc['Settings'][$name])) {
                    $this->UpdateFormField($name, 'value', (int) $doc['Settings'][$name]);
                }
            }
        }

        $skipped = count($doc['Devices']) - $loaded;
        $text = sprintf('Import: %d Zeilen geladen, %d übersprungen. Bitte "Übernehmen" klicken.', $loaded, $skipped);
        if ($messages !== []) {
            $text .= ' Hinweise: ' . mb_substr(implode('; ', $messages), 0, 500);
        }
        $this->UpdateFormField('ExchangeResult', 'caption', $text);
    }

    /** ID nehmen, wenn sie noch zum Pfad passt; sonst Pfad auflösen; sonst ID mit Warnung; sonst 0. */
    private function resolveImportVariable(int $id, string $path, ?string &$warning): int
    {
        $warning = null;
        if ($id > 0 && IPS_VariableExists($id) && ($path === '' || IPS_GetLocation($id) === $path)) {
            return $id;
        }
        if ($path !== '') {
            $found = $this->findVariableByPath($path);
            if ($found > 0) {
                return $found;
            }
        }
        if ($id > 0 && IPS_VariableExists($id)) {
            $warning = 'Pfad weicht ab, ID ' . $id . ' verwendet';
            return $id;
        }
        return 0;
    }

    private function findVariableByPath(string $path): int
    {
        $parent = 0;
        foreach (explode('\\', $path) as $name) {
            $child = @IPS_GetObjectIDByName($name, $parent);
            if (!$child) {
                return 0;
            }
            $parent = $child;
        }
        return IPS_VariableExists($parent) ? $parent : 0;
    }
}
