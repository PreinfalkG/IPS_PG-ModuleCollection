<?php

declare(strict_types=1);

/**
 * Export/Import der Zuordnungstabelle als JSON.
 * Einbinden in der Modulklasse mit `use TimeSwitchExportImport;`
 *
 * Exportiert werden pro Zeile die Variablen-IDs UND ihre Pfade (IPS_GetLocation).
 * Beim Import wird zuerst die ID genommen (wenn der Pfad noch passt), sonst der Pfad aufgelöst.
 * So funktioniert der Import auch nach einer Neuinstallation oder auf einem anderen System.
 */
trait TimeSwitchExportImport
{
    protected function exportConfig(): string
    {
        $rows = json_decode($this->ReadPropertyString('Devices'), true) ?: [];
        $devices = [];
        foreach ($rows as $row) {
            $timeVar = (int) ($row['TimeVar'] ?? 0);
            $switchVar = (int) ($row['SwitchVar'] ?? 0);
            $devices[] = [
                'TimeVar'       => $timeVar,
                'TimeVarPath'   => IPS_VariableExists($timeVar) ? IPS_GetLocation($timeVar) : '',
                'SwitchVar'     => $switchVar,
                'SwitchVarPath' => IPS_VariableExists($switchVar) ? IPS_GetLocation($switchVar) : '',
                'Driver'        => (string) ($row['Driver'] ?? ''),
            ];
        }

        $json = json_encode([
            'Format'         => 'TimeSwitch',
            'Version'        => 1,
            'PermanentValue' => $this->ReadPropertyInteger('PermanentValue'),
            'Devices'        => $devices,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->UpdateFormField('ExchangeText', 'value', $json);
        $this->UpdateFormField('ExchangeResult', 'caption', sprintf('Export: %d Zeilen (gespeicherter Stand).', count($devices)));
        return $json;
    }

    protected function importConfig(string $json, bool $merge): void
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || ($doc['Format'] ?? '') !== 'TimeSwitch' || !is_array($doc['Devices'] ?? null)) {
            $this->UpdateFormField('ExchangeResult', 'caption', 'Import abgebrochen: kein gültiges TimeSwitch-JSON.');
            return;
        }

        $byTimeVar = [];
        if ($merge) {
            foreach (json_decode($this->ReadPropertyString('Devices'), true) ?: [] as $row) {
                $byTimeVar[(int) $row['TimeVar']] = [
                    'TimeVar'   => (int) $row['TimeVar'],
                    'SwitchVar' => (int) $row['SwitchVar'],
                    'Driver'    => (string) $row['Driver'],
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

            $timeVar = $this->resolveImportVariable((int) ($entry['TimeVar'] ?? 0), (string) ($entry['TimeVarPath'] ?? ''), $w1);
            $switchVar = $this->resolveImportVariable((int) ($entry['SwitchVar'] ?? 0), (string) ($entry['SwitchVarPath'] ?? ''), $w2);
            if ($timeVar === 0 || $switchVar === 0) {
                $messages[] = $label . ': ' . ($timeVar === 0 ? 'Time Switch' : 'Schaltvariable') . ' nicht gefunden';
                continue;
            }
            if (IPS_GetVariable($timeVar)['VariableType'] !== 1) {
                $messages[] = $label . ': Time Switch ist keine Integer-Variable';
                continue;
            }
            foreach ([$w1, $w2] as $warning) {
                if ($warning !== null) {
                    $messages[] = $label . ': ' . $warning;
                }
            }

            $byTimeVar[$timeVar] = ['TimeVar' => $timeVar, 'SwitchVar' => $switchVar, 'Driver' => $driver];
            $loaded++;
        }

        $this->UpdateFormField('Devices', 'values', json_encode(array_values($byTimeVar)));
        if (!$merge && (int) ($doc['PermanentValue'] ?? 0) > 0) {
            $this->UpdateFormField('PermanentValue', 'value', (int) $doc['PermanentValue']);
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
