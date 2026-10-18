# Aktor Controller (Präfix AKTC)

Neuaufbau des Moduls "Time Switch Controller". Keine Migration der alten Konfiguration, Aktoren neu anlegen.

## Funktionen
- Aktoren normal EIN/AUS schalten, einheitlich über Treiber (`hm`, `generic`)
- Time Switch (optional pro Aktor): Integer-Variable in Sekunden, `0` = Aus, Dauer-EIN-Wert (Standard 999999) = EIN ohne Timer
- Modul-Timer für alle Aktoren, auch HomeMatic (ON_TIME bleibt, Modul zählt parallel mit)
- Restlaufzeit in der Time-Switch-Variable
- Gruppen: Aktoren mehreren Gruppen zuordnen und ganze Gruppen EIN/AUS schalten (siehe unten)
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
| `AKTC_GroupOn($id, $Group)` | alle Mitglieder Dauer-EIN |
| `AKTC_GroupTimed($id, $Group, $Seconds)` | alle Mitglieder EIN mit Zeit |
| `AKTC_GroupOff($id, $Group)` | alle Mitglieder AUS |
| `AKTC_GetGroupState($id, $Group, $asText)` | Zustand und Mitglieder einer Gruppe |
| `AKTC_GetGroupList($id, $asText)` | alle Gruppen mit Zustand |
| `AKTC_GetConfiguredDevices($id)` | gültige Zeilen |
| `AKTC_Export($id)` / `AKTC_Import($id, $Json, $Merge)` | Konfiguration |

## Neue Daten
Attribute `Timers`, `Verify`, `OnSince` (alle nach SwitchVar indiziert), Variablen `activTimer`, `tickCnt`, Profile `AKTC.Duration`, `AKTC.TotalSeconds`.

## Gruppen
- Gruppen stehen in der Liste "Gruppen" (nur der Name). In der Spalte "Gruppen" jeder Aktor-Zeile stehen die Namen, getrennt durch Komma oder Semikolon, Groß-/Kleinschreibung egal. Namen, die nicht in der Gruppenliste stehen, werden mit Warnung ignoriert. Komma und Semikolon sind im Gruppennamen nicht erlaubt.
- Pro Gruppe zwei Instanzvariablen: `Group_<name>` (Integer, 0 = alle aus, 1 = teilweise, 2 = alle ein, Aktion: 0 = alle AUS, 2 = alle EIN, 1 ist nur Anzeige) und `GroupInfo_<name>` (Text "3/7 EIN"). Der Ident-Teil entsteht aus dem Namen (Umlaute ersetzt). Eine umbenannte Gruppe ist eine neue Gruppe, die alte Variable wird entfernt.
- Gruppenbefehle laufen über dieselben Funktionen wie das Schalten einzelner Aktoren: Time Switch, Timer, Kontrolle "wirklich AUS" und Betriebszeit gelten also auch hier. HomeMatic bekommt bei Dauer-EIN nur `STATE = true`.
- Option "Gruppenbefehle überspringen Aktoren im Zielzustand" (Standard an): AUS überspringt Aktoren, die schon aus sind und nichts offen haben. Dauer-EIN überspringt Aktoren, die schon ohne Timer ein sind. Befristetes EIN wird immer gesendet.
- Dauert ein Gruppenbefehl länger als 5 s, gibt es eine Warnung. Die Dauer steht immer im Debug ("Gruppe X AUS: n geschaltet, m übersprungen, k fehlgeschlagen, t ms").
- Export/Import: Version 2 enthält Gruppenliste und Gruppen je Zeile. Version-1-Dateien lassen sich weiter importieren.
