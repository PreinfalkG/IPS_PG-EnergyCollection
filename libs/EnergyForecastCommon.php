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
        // number_format() liefert im Gegensatz zu sprintf('%f', ...) IMMER einen Punkt als
        // Dezimaltrennzeichen, unabhängig vom Server-Locale (sprintf('%f') ist auf manchen
        // PHP-Installationen locale-abhängig und lieferte z.B. bei deutschem Locale ein Komma -
        // das hat u.a. Latitude/Longitude in API-URLs unbrauchbar gemacht).
        $formatted = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
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
        return $this->ComputePowerAt($this->LoadSeries(), $Timestamp);
    }

    /**
     * Kernlogik von GetPowerAt() auf einer beliebigen Zeitreihe (statt zwingend der
     * gespeicherten LoadSeries()). Damit können Module wie OPENMETEO, die aus den
     * gespeicherten Rohdaten die Leistung erst bei Bedarf berechnen (statt sie zu
     * speichern), dieselbe Fenster-/Fallback-Logik wiederverwenden.
     */
    private function ComputePowerAt(?array $series, int $Timestamp): ?float
    {
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
        return $this->ComputeEnergyBetween($this->LoadSeries(), $From, $To);
    }

    /** Kernlogik von GetEnergyBetween() auf einer beliebigen Zeitreihe (siehe ComputePowerAt()). */
    private function ComputeEnergyBetween(?array $series, int $From, int $To): float
    {
        if ($To <= $From || $series === null) {
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
        return $this->ComputeBestWindow($this->LoadSeries(), $From, $To, $WindowSeconds);
    }

    /** Kernlogik von FindBestWindow() auf einer beliebigen Zeitreihe (siehe ComputePowerAt()). */
    private function ComputeBestWindow(?array $series, int $From, int $To, int $WindowSeconds): ?array
    {
        if ($WindowSeconds <= 0 || $To <= $From || $series === null) {
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
     * ('ts') den angegebenen Zeitpunkt als erstes abdeckt. Nur PVNODE und OPENMETEO
     * liefern Wetterdaten - Forecast.Solar und Solcast geben hier immer null zurück,
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

    // ==================== SONNENSTAND / GTI-TRANSPOSITION / FAIMAN (für OPENMETEO) ====================
    // Rein rechnerische Helfer, keine externen Daten nötig - werden vom OPENMETEO-Modul
    // genutzt, um aus GHI/DNI/DHI je Fläche live die Bestrahlungsstärke (GTI) und daraus
    // über das Faiman-Modell die Zelltemperatur und Leistung zu berechnen.

    /**
     * Sonnenstand (Elevation, Azimuth) zu einem Zeitpunkt an einem Standort.
     * Azimuth-Konvention wie bei FSOLAR: 0° = Süden, -90° = Osten, +90° = Westen.
     *
     * @return array{elevation:float,azimuth:float} Grad
     */
    private function SolarPosition(int $Timestamp, float $Latitude, float $Longitude): array
    {
        $dayOfYear = (int) gmdate('z', $Timestamp) + 1;
        $hourUtc = (int) gmdate('G', $Timestamp) + ((int) gmdate('i', $Timestamp)) / 60 + ((int) gmdate('s', $Timestamp)) / 3600;

        $gamma = 2 * M_PI / 365 * ($dayOfYear - 1 + ($hourUtc - 12) / 24);

        $eqTime = 229.18 * (
            0.000075
            + 0.001868 * cos($gamma)
            - 0.032077 * sin($gamma)
            - 0.014615 * cos(2 * $gamma)
            - 0.040849 * sin(2 * $gamma)
        );

        $decl = 0.006918
            - 0.399912 * cos($gamma) + 0.070257 * sin($gamma)
            - 0.006758 * cos(2 * $gamma) + 0.000907 * sin(2 * $gamma)
            - 0.002697 * cos(3 * $gamma) + 0.00148 * sin(3 * $gamma);

        $trueSolarTime = fmod($hourUtc * 60 + $eqTime + 4 * $Longitude, 1440);
        if ($trueSolarTime < 0) {
            $trueSolarTime += 1440;
        }
        $hourAngleDeg = $trueSolarTime / 4 - 180; // -180..+180

        $latRad = deg2rad($Latitude);
        $haRad = deg2rad($hourAngleDeg);

        $cosZenith = sin($latRad) * sin($decl) + cos($latRad) * cos($decl) * cos($haRad);
        $cosZenith = max(-1.0, min(1.0, $cosZenith));
        $zenith = acos($cosZenith);
        $elevation = 90 - rad2deg($zenith);

        if (sin($zenith) < 1e-6) {
            // Sonne (fast) im Zenit - Azimuth nicht sinnvoll definierbar, Süden annehmen.
            $azimuthNorth = 180.0;
        } else {
            $cosAz = (sin($decl) - sin($latRad) * $cosZenith) / (cos($latRad) * sin($zenith));
            $cosAz = max(-1.0, min(1.0, $cosAz));
            $azimuthNorth = rad2deg(acos($cosAz));
            if ($hourAngleDeg > 0) {
                $azimuthNorth = 360 - $azimuthNorth;
            }
        }

        // von "0=Norden, im Uhrzeigersinn" auf FSOLAR-Konvention "0=Süden" umrechnen, auf -180..180 normalisieren
        $azimuthSouth = $azimuthNorth - 180;
        if ($azimuthSouth > 180) {
            $azimuthSouth -= 360;
        } elseif ($azimuthSouth < -180) {
            $azimuthSouth += 360;
        }

        return ['elevation' => $elevation, 'azimuth' => $azimuthSouth];
    }

    /**
     * Transponiert GHI/DNI/DHI (horizontal) auf eine geneigte Fläche (isotropes
     * Himmelsmodell nach Liu-Jordan). $Tilt/$Azimuth wie bei FSOLAR (0°=Süden).
     * Liefert die Bestrahlungsstärke auf der Modulebene (GTI) in W/m².
     */
    private function CalculateGti(float $Ghi, float $Dni, float $Dhi, float $Tilt, float $Azimuth, float $SunElevation, float $SunAzimuth, float $Albedo = 0.2): float
    {
        if ($SunElevation <= 0) {
            return 0.0;
        }

        $zenithRad = deg2rad(90 - $SunElevation);
        $tiltRad = deg2rad($Tilt);
        $azDiffRad = deg2rad($SunAzimuth - $Azimuth);

        $cosIncidence = cos($zenithRad) * cos($tiltRad) + sin($zenithRad) * sin($tiltRad) * cos($azDiffRad);
        $cosIncidence = max(0.0, $cosIncidence);

        $beam = $Dni * $cosIncidence;
        $diffuse = $Dhi * (1 + cos($tiltRad)) / 2;
        $reflected = $Ghi * $Albedo * (1 - cos($tiltRad)) / 2;

        return max(0.0, $beam + $diffuse + $reflected);
    }

    /**
     * Zelltemperatur nach dem Faiman-Modell aus Umgebungstemperatur, Bestrahlungsstärke
     * (GTI, W/m²) und Windgeschwindigkeit (m/s). $Uc/$Uv sind die Wärmeverlustkoeffizienten
     * der Montageart (Default 25 / 6.84 - freistehende, gut hinterlüftete Aufständerung).
     */
    private function CalculateCellTemperature(float $AmbientTemp, float $Gti, float $WindSpeedMs, float $Uc = 25.0, float $Uv = 6.84): float
    {
        $denominator = $Uc + $Uv * $WindSpeedMs;
        if ($denominator <= 0) {
            return $AmbientTemp;
        }
        return $AmbientTemp + $Gti / $denominator;
    }

    /**
     * DC-Leistung (kW) einer Fläche aus GTI, Zelltemperatur und Anlagendaten.
     * $TempCoeffPercent ist der Temperaturkoeffizient in %/°C (üblich ca. -0.35 bis -0.45,
     * daher negativ angeben), bezogen auf 25°C Zelltemperatur (STC).
     */
    private function CalculatePvPower(float $Gti, float $CellTemp, float $Kwp, float $TempCoeffPercent, float $SystemLossPercent): float
    {
        $tempFactor = 1 + ($TempCoeffPercent / 100) * ($CellTemp - 25);
        $tempFactor = max(0.0, $tempFactor);
        $lossFactor = max(0.0, 1 - $SystemLossPercent / 100);

        return max(0.0, ($Gti / 1000) * $Kwp * $tempFactor * $lossFactor);
    }
}
