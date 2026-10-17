# Aktor Controller (Präfix AKTC)

Neuaufbau des Moduls "Time Switch Controller". Keine Migration der alten Konfiguration, Aktoren neu anlegen.

## Funktionen
- Aktoren normal EIN/AUS schalten, einheitlich über Treiber (`hm`, `generic`)
- Time Switch (optional pro Aktor): Integer-Variable in Sekunden, `0` = Aus, Dauer-EIN-Wert (Standard 999999) = EIN ohne Timer
- Modul-Timer für alle Aktoren, auch HomeMatic (ON_TIME bleibt, Modul zählt parallel mit)
- Restlaufzeit in der Time-Switch-Variable
- Betriebszeit `TotalOnSeconds_<SwitchVar>` auf derselben Ebene wie die Time-Switch-Variable (ohne Time Switch: neben der Schaltvariable)

## Regeln
- Schlüssel einer Zeile ist die Schaltvariable, die Time-Switch-Variable ist optional.
- HomeMatic Dauer-EIN sendet nur `STATE = true`, nie `ON_TIME` (auch nicht 0).
- Treiber haben drei getrennte Aufrufe `on()`, `onTimed()`, `off()`, keinen Sonderwert 0.
- Ablauf bei HomeMatic: Modul schaltet nicht aus, wartet `VerifyDelay`, prüft dann den Zustand und wiederholt AUS höchstens `VerifyRetries` mal.
- Jeder AUS-Befehl und jeder Modul-Timer-Ablauf wird nach 5 s kontrolliert (Zustand AUS?), sonst Wiederholung.
- Betriebszeitvariablen werden nie automatisch gelöscht.

## Funktionen für Skripte
| Funktion | Zweck |
|---|---|
| `AKTC_SwitchOn($id, $SwitchVar)` | Dauer-EIN |
| `AKTC_SwitchTimed($id, $SwitchVar, $Seconds)` | EIN mit Zeit |
| `AKTC_SwitchOff($id, $SwitchVar)` | AUS |
| `AKTC_GetActivTimer($id, $asText)` | alle laufenden Timer (Modul und HM) und wartende Kontrollen |
| `AKTC_GetActiveDevices($id, $asText)` | alle eingeschalteten Aktoren |
| `AKTC_GetTotalOnSeconds($id, $SwitchVar)` | Betriebszeit live |
| `AKTC_ResetTotalOnSeconds($id, $SwitchVar)` | Betriebszeit zurücksetzen |
| `AKTC_GetConfiguredDevices($id)` | gültige Zeilen |
| `AKTC_Export($id)` / `AKTC_Import($id, $Json, $Merge)` | Konfiguration |

## Neue Daten
Attribute `Timers`, `Verify`, `OnSince` (alle nach SwitchVar indiziert), Variablen `activTimer`, `tickCnt`, Profile `AKTC.Duration`, `AKTC.TotalSeconds`.
