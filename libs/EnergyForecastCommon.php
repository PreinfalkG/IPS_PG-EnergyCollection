<?php

declare(strict_types=1);

/**
 * EnergyForecastCommon
 * -------------------------------------------------------------
 * Gemeinsame Basis-Funktionalität für die drei PV-Prognose-Module
 * (ForecastSolar/FSOLAR, SOLCAST, PVNODE) dieser Bibliothek.
 *
 * Alle drei Module speichern ihre (Summen-)Zeitreihe in einer
 * eigenen JSON-Puffer-Variable als Liste von Zeitscheiben im
 * einheitlichen Format:
 *
 *   [
 *     'ts' => int,          // Ende der Zeitscheibe, Unix-Timestamp
 *     'p'  => float,        // Leistung in kW (Momentanwert am Ende der Scheibe / Durchschnitt)
 *     'e'  => float,        // Energie dieser Zeitscheibe in kWh
 *     'p10'=> float|null,   // optional: pessimistische Bandbreite (nur SOLCAST)
 *     'p90'=> float|null,   // optional: optimistische Bandbreite (nur SOLCAST)
 *     'w'  => int|null,     // optional: Wettercode (nur PVNODE)
 *     't'  => float|null,   // optional: Temperatur °C (nur PVNODE)
 *   ]
 *
 * Damit lassen sich modulübergreifend dieselben öffentlichen
 * Funktionen anbieten:
 *   <PREFIX>_LoadSeries($id)
 *   <PREFIX>_GetPowerAt($id, $Timestamp)
 *   <PREFIX>_GetEnergyBetween($id, $From, $To)
 *   <PREFIX>_FindBestWindow($id, $From, $To, $WindowSeconds)
 *   <PREFIX>_GetWeatherAt($id, $Timestamp)
 *
 * Voraussetzung für die verwendende Klasse:
 *   - Eigenschaft $this->enableDebug (bool) muss gesetzt sein
 *   - Eigenschaft $this->seriesVariableIdent (string) muss auf die
 *     Variable zeigen, die die Zeitreihe (oder ein Objekt mit
 *     Schlüssel 'series') als JSON enthält
 * -------------------------------------------------------------
 */
trait EnergyForecastCommon
{
    // ==================== DEBUG ====================

    /**
     * Einheitlicher Debug-Ausgabe-Helfer. Schreibt nur, wenn EnableDebug
     * in der Instanz aktiviert ist. $context hilft, an welcher Stelle
     * im Ablauf die Meldung entstanden ist (z.B. "Request", "Parse", "Compute").
     */
    private function dbg(string $method, string $context, string $message): void
    {
        if ($this->enableDebug) {
            $this->SendDebug($method, '[' . $context . '] ' . $message, 0);
        }
    }

    // ==================== GEMEINSAME PROFIL-/FORMAT-HELFER ====================

    private function RegisterProfileIfNotExists(string $name, string $icon, string $prefix, string $suffix, float $minValue, float $maxValue, float $stepSize, int $digits, int $variableType): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $variableType);
        }
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileIcon($name, $icon);
        // Werte-/Nachkommastellen gibt es nur bei numerischen Profilen - bei String-/Boolean-Profilen
        // wirft IPS_SetVariableProfileValues()/Digits() sonst eine Warning (Code -32603).
        if ($variableType === VARIABLETYPE_INTEGER || $variableType === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileValues($name, $minValue, $maxValue, $stepSize);
            IPS_SetVariableProfileDigits($name, $digits);
        }
    }

    private function FormatNumber(float $value, int $decimals): string
    {
        $formatted = rtrim(rtrim(sprintf('%.' . $decimals . 'f', $value), '0'), '.');
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    // ==================== UPDATE-STATISTIK (Erfolg/Fehler inkl. Grund) ====================

    /**
     * Legt die gemeinsamen Statistik-Variablen für Update-Zyklen an.
     * $posBase ist die Sortierposition der ersten Variable, die folgenden
     * werden fortlaufend dahinter einsortiert.
     */
    private function RegisterUpdateStatsVariables(int $posBase = 85): void
    {
        $this->MaintainVariableGeneric('UpdateSuccessCount', 'Erfolgreiche Abrufe (gesamt)', VARIABLETYPE_INTEGER, '', $posBase, true);
        $this->MaintainVariableGeneric('UpdateErrorCount', 'Fehlgeschlagene Abrufe (gesamt)', VARIABLETYPE_INTEGER, '', $posBase + 1, true);
        $this->MaintainVariableGeneric('LastErrorType', 'Letzter Fehlergrund', VARIABLETYPE_STRING, '', $posBase + 2, true);
        $this->MaintainVariableGeneric('LastErrorMessage', 'Letzte Fehlermeldung', VARIABLETYPE_STRING, '', $posBase + 3, true);
        $this->MaintainVariableGeneric('LastErrorTime', 'Zeitpunkt letzter Fehler', VARIABLETYPE_INTEGER, '~UnixTimestamp', $posBase + 4, true);
        @IPS_SetHidden($this->GetIDForIdent('LastErrorMessage'), true);
    }

    /**
     * Kapselt MaintainVariable, damit auch Module nutzbar sind, die (noch)
     * keine eigene MaintainVariable-Konvention haben - fällt bei Bedarf
     * auf Register-/SetPosition zurück.
     */
    private function MaintainVariableGeneric(string $ident, string $name, int $type, string $profile, int $position, bool $keepValue): void
    {
        if (method_exists($this, 'MaintainVariable')) {
            $this->MaintainVariable($ident, $name, $type, $profile, $position, $keepValue);
            return;
        }
        switch ($type) {
            case VARIABLETYPE_INTEGER:
                $this->RegisterVariableInteger($ident, $name, $profile, $position);
                break;
            case VARIABLETYPE_FLOAT:
                $this->RegisterVariableFloat($ident, $name, $profile, $position);
                break;
            case VARIABLETYPE_STRING:
                $this->RegisterVariableString($ident, $name, $profile, $position);
                break;
            case VARIABLETYPE_BOOLEAN:
                $this->RegisterVariableBoolean($ident, $name, $profile, $position);
                break;
        }
    }

    /**
     * Vermerkt einen erfolgreichen Update-Zyklus: erhöht den Erfolgszähler.
     * Der letzte Fehlergrund bleibt zu Diagnosezwecken bewusst stehen -
     * LastUpdate/LastSuccess zeigen ohnehin, ob er noch aktuell ist.
     */
    private function RecordUpdateSuccess(): void
    {
        $count = $this->GetValue('UpdateSuccessCount') + 1;
        $this->SetValue('UpdateSuccessCount', $count);
        $this->dbg(__METHOD__, 'Stats', 'UpdateSuccessCount = ' . $count);
    }

    /**
     * Setzt die Update-Statistik (Erfolg/Fehler-Zähler + letzten Fehler) zurück.
     * Aufrufbar per Button im Konfigurationsformular oder eigene Skripte:
     * <PREFIX>_ResetUpdateStats($id).
     */
    public function ResetUpdateStats(): void
    {
        $this->SetValue('UpdateSuccessCount', 0);
        $this->SetValue('UpdateErrorCount', 0);
        $this->SetValue('LastErrorType', '');
        $this->SetValue('LastErrorMessage', '');
        $this->SetValue('LastErrorTime', 0);
        $this->dbg(__METHOD__, 'Stats', 'Update-Statistik zurückgesetzt');
    }

    /**
     * Vermerkt einen fehlgeschlagenen Update-Zyklus inkl. kategorisiertem Grund,
     * damit z.B. "API Limit erreicht" von "Netzwerkfehler" oder "sonstiger Fehler"
     * unterschieden werden kann, ohne jedes Mal die Logmeldungen durchsuchen zu müssen.
     *
     * Übliche $type-Werte: 'RateLimit', 'Network', 'Http', 'ParseError', 'Config'
     */
    private function RecordUpdateError(string $type, string $message): void
    {
        $count = $this->GetValue('UpdateErrorCount') + 1;
        $this->SetValue('UpdateErrorCount', $count);
        $this->SetValue('LastErrorType', $type);
        $this->SetValue('LastErrorMessage', $message);
        $this->SetValue('LastErrorTime', time());
        $this->dbg(__METHOD__, 'Stats', sprintf('UpdateErrorCount = %d | Type = %s | Message = %s', $count, $type, $message));
    }

    // ==================== ZEITSTEMPEL-HELFER ====================
    // Bequeme, DST-sichere Alternative zu eigenen strtotime()-Konstrukten - stehen als
    // <PREFIX>_GetDayRange($id,...), <PREFIX>_GetTimeOnDay($id,...) und
    // <PREFIX>_GetRelativeTimestamp($id,...) direkt zur Verfügung und lassen sich beliebig
    // mit GetPowerAt()/GetEnergyBetween()/FindBestWindow() kombinieren.

    /**
     * Start- und Ende-Zeitpunkt eines ganzen Tages (00:00:00 bis 23:59:59), relativ zu heute.
     * $DayOffset: 0 = heute, 1 = morgen, 2 = übermorgen, -1 = gestern, usw.
     *
     * Beispiel: [$start, $end] = FSOLAR_GetDayRange($id, 1); // "morgen" komplett
     *
     * @return array{start:int,end:int}
     */
    public function GetDayRange(int $DayOffset = 0): array
    {
        $start = $this->GetTimeOnDay($DayOffset, 0, 0, 0);
        $end = $this->GetTimeOnDay($DayOffset + 1, 0, 0, 0) - 1;
        return ['start' => $start, 'end' => $end];
    }

    /**
     * Zeitpunkt einer bestimmten Uhrzeit an einem relativen Tag (DST-sicher, da über
     * DateTime::setTime() statt über Sekunden-Arithmetik berechnet).
     * $DayOffset: 0 = heute, 1 = morgen, 2 = übermorgen, -1 = gestern, usw.
     *
     * Beispiel: $morgenMittag = FSOLAR_GetTimeOnDay($id, 1, 12); // morgen 12:00:00
     *
     * @return int Unix-Timestamp
     */
    public function GetTimeOnDay(int $DayOffset, int $Hour = 0, int $Minute = 0, int $Second = 0): int
    {
        $dt = new DateTime('today');
        if ($DayOffset !== 0) {
            $dt->modify(($DayOffset >= 0 ? '+' : '') . $DayOffset . ' day');
        }
        $dt->setTime($Hour, $Minute, $Second);
        return $dt->getTimestamp();
    }

    /**
     * Fallback für alles, was GetDayRange()/GetTimeOnDay() nicht direkt abdeckt: reicht einen
     * beliebigen PHP-Zeitausdruck (wie bei strtotime()) durch, optional relativ zu einem
     * eigenen Basiszeitpunkt statt "jetzt".
     *
     * Beispiel: $inZweiWochen = FSOLAR_GetRelativeTimestamp($id, '+2 weeks');
     *
     * @throws InvalidArgumentException wenn der Ausdruck nicht interpretiert werden kann
     */
    public function GetRelativeTimestamp(string $Modifier, ?int $Base = null): int
    {
        $ts = strtotime($Modifier, $Base ?? time());
        if ($ts === false) {
            throw new InvalidArgumentException('Ungültiger Zeitausdruck: ' . $Modifier);
        }
        return $ts;
    }

    // ==================== EINHEITLICHE ZEITREIHEN-FUNKTIONEN ====================

    /**
     * Liefert die zuletzt gespeicherte Zeitreihe (Rohdaten je Zeitscheibe)
     * als PHP-Array, z.B. für eigene Skripte: <PREFIX>_LoadSeries($id).
     * Gibt null zurück, wenn noch kein erfolgreicher Abruf stattgefunden hat.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function LoadSeries(): ?array
    {
        $raw = $this->GetValue($this->seriesVariableIdent);
        if ($raw === '' || $raw === null) {
            return null;
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return null;
        }
        // Puffer kann entweder direkt die Liste sein (FSOLAR/SOLCAST) oder
        // ein Objekt mit Metadaten + 'series' Schlüssel (PVNODE) - beides erlauben.
        if (isset($data['series']) && is_array($data['series'])) {
            return $data['series'];
        }
        return $data;
    }

    /**
     * Liefert die prognostizierte Leistung (kW) zum angegebenen Zeitpunkt.
     * Sucht die Zeitscheibe, deren Ende ('ts') den Zeitpunkt als erstes
     * abdeckt. Liegt der Zeitpunkt nach der letzten bekannten Scheibe, wird
     * die letzte bekannte Leistung geliefert; liegt er vor der ersten
     * bekannten Scheibe, die erste bekannte Leistung (z.B. vor Sonnenaufgang).
     */
    public function GetPowerAt(int $Timestamp): ?float
    {
        $series = $this->LoadSeries();
        if ($series === null || count($series) === 0) {
            return null;
        }

        foreach ($series as $point) {
            if (($point['ts'] ?? 0) >= $Timestamp) {
                return (float) ($point['p'] ?? 0);
            }
        }
        $last = end($series);
        return (float) ($last['p'] ?? 0);
    }

    /**
     * Summiert die prognostizierte Energie (kWh) aller Zeitscheiben mit
     * 'ts' im Bereich [$From, $To). Nutzt die bereits je Zeitscheibe
     * vorliegende Energie 'e', keine Annahme über die Scheibenlänge nötig.
     */
    public function GetEnergyBetween(int $From, int $To): float
    {
        if ($To <= $From) {
            return 0.0;
        }
        $series = $this->LoadSeries();
        if ($series === null) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($series as $point) {
            $ts = $point['ts'] ?? null;
            if ($ts !== null && $ts >= $From && $ts < $To) {
                $sum += (float) ($point['e'] ?? 0);
            }
        }
        return round($sum, 3);
    }

    /**
     * Findet das zusammenhängende Zeitfenster einer bestimmten Dauer innerhalb
     * von [$From, $To), in dem am meisten PV-Energie erwartet wird. Praktisch
     * z.B. für "wann soll das EV/E-Bike in den nächsten 12h laden".
     * @return array{start:int,end:int,energy_kwh:float}|null
     */
    public function FindBestWindow(int $From, int $To, int $WindowSeconds): ?array
    {
        if ($WindowSeconds <= 0 || $To <= $From) {
            return null;
        }
        $series = $this->LoadSeries();
        if ($series === null) {
            return null;
        }

        $relevant = array_values(array_filter($series, function ($p) use ($From, $To) {
            $ts = $p['ts'] ?? null;
            return $ts !== null && $ts >= $From && $ts < $To;
        }));
        if (count($relevant) === 0) {
            return null;
        }

        $best = null;
        foreach ($relevant as $start) {
            $windowEnd = $start['ts'] + $WindowSeconds;
            $sum = 0.0;
            foreach ($relevant as $p) {
                if ($p['ts'] >= $start['ts'] && $p['ts'] < $windowEnd) {
                    $sum += (float) ($p['e'] ?? 0);
                }
            }
            if ($best === null || $sum > $best['energy_kwh']) {
                $best = ['start' => $start['ts'], 'end' => $windowEnd, 'energy_kwh' => round($sum, 3)];
            }
        }
        $this->dbg(__METHOD__, 'Compute', sprintf('From=%d To=%d Window=%ds -> Best=%s', $From, $To, $WindowSeconds, json_encode($best)));
        return $best;
    }

    /**
     * Liefert Wettercode, Klartext und Temperatur zur Zeitscheibe, deren Ende
     * ('ts') den angegebenen Zeitpunkt als erstes abdeckt. Nur PVNODE liefert
     * Wetterdaten - Forecast.Solar und Solcast geben hier immer null zurück,
     * da deren APIs keine Wetterdaten liefern.
     * @return array{code:int,text:string,temp:?float}|null
     */
    public function GetWeatherAt(int $Timestamp): ?array
    {
        $series = $this->LoadSeries();
        if ($series === null) {
            return null;
        }

        $best = null;
        foreach ($series as $point) {
            if (!isset($point['w'])) {
                continue;
            }
            if (($point['ts'] ?? 0) >= $Timestamp) {
                $best = $point;
                break;
            }
            $best = $point; // letzte bekannte Scheibe mit Wetterdaten als Fallback
        }
        if ($best === null) {
            return null;
        }

        $text = method_exists($this, 'DecodeWeatherCode') ? $this->DecodeWeatherCode((int) $best['w']) : (string) $best['w'];
        return [
            'code' => (int) $best['w'],
            'text' => $text,
            'temp' => isset($best['t']) ? (float) $best['t'] : null,
        ];
    }
}
