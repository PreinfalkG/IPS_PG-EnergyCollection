<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EnergyForecastCommon.php';

/**
 * OPENMETEOForecast
 * -------------------------------------------------------------
 * IP-Symcon Modul für die Open-Meteo Weather Forecast API (https://open-meteo.com/,
 * kostenlos für nicht-kommerzielle Nutzung, kein API-Key nötig).
 *
 * Holt mit EINEM API-Call die Rohdaten GHI (shortwave_radiation), DNI
 * (direct_normal_irradiance) und DHI (diffuse_radiation) sowie ein paar
 * Wetterwerte auf Stundenbasis. Aus diesen Rohdaten wird die PV-Leistung für
 * bis zu 4 konfigurierbare, einzeln ein-/ausschaltbare Modulflächen NICHT
 * gespeichert, sondern bei jedem Aufruf live berechnet:
 *
 *   GHI/DNI/DHI + Sonnenstand  --Transposition (isotrop)-->  GTI je Fläche
 *   GTI + Umgebungstemp + Wind --Faiman-Modell-->             Zelltemperatur
 *   GTI + Zelltemperatur + kWp --Temp.-Derating-->             Leistung (kW)
 *
 * Die Series (LoadSeries()) enthält daher bewusst nur die API-Rohdaten pro
 * Zeitscheibe - keine berechnete Leistung. GetPowerAt()/GetEnergyBetween()/
 * FindBestWindow() überschreiben deshalb die Trait-Standardimplementierung
 * und rechnen bei jedem Aufruf die aktuell aktiven Flächen zusammen.
 * GetWeatherAt() und LoadSeries() funktionieren dagegen unverändert über den
 * Trait, da die Rohdaten bereits die erwarteten Schlüssel 'ts'/'w'/'t' enthalten.
 *
 * Modul-Präfix: OPENMETEO
 * -------------------------------------------------------------
 */
class OPENMETEOForecast extends IPSModule
{
    use EnergyForecastCommon;

    private const API_BASE = 'https://api.open-meteo.com/v1/forecast';
    private const SITE_COUNT = 4;

    private $enableDebug = false;
    private $seriesVariableIdent = 'Buffer';

    // Übernommen als Vorbelegung aus dem FSOLAR-Modul, damit man mit denselben
    // 4 Flächen sofort vergleichen kann.
    private const SITE_DEFAULTS = [
        1 => ['Name' => 'PV Hausdach',    'Declination' => 7,  'Azimuth' => 0,   'kWp' => 13.12],
        2 => ['Name' => 'PV Zaun',        'Declination' => 83, 'Azimuth' => 0,   'kWp' => 3.28],
        3 => ['Name' => 'PV Garage Ost',  'Declination' => 10, 'Azimuth' => -90, 'kWp' => 1.3],
        4 => ['Name' => 'PV Garage West', 'Declination' => 10, 'Azimuth' => 90,  'kWp' => 1.3]
    ];

    // Zusätzliche, im Formular auswählbare Stundenwerte. Werden - falls angehakt -
    // nur als Zusatzfeld je Zeitscheibe in der Series abgelegt (kein eigenes Objekt),
    // um die Objektzahl in IP-Symcon klein zu halten.
    private const OPTIONAL_HOURLY = [
        'precipitation'        => 'Niederschlag (mm)',
        'rain'                  => 'Regen (mm)',
        'showers'               => 'Schauer (mm)',
        'snowfall'              => 'Schneefall (cm)',
        'relative_humidity_2m'  => 'Luftfeuchte (%)',
        'surface_pressure'      => 'Luftdruck (hPa)',
        'uv_index'              => 'UV-Index',
        'visibility'            => 'Sichtweite (m)',
    ];

    // Zusätzliche, im Formular auswählbare Tageswerte. Werden - falls angehakt -
    // sowohl im DailyJSON-Puffer (LoadDaily()) als auch als eigene "Heute"-Variable
    // angelegt, da Tageswerte im Gegensatz zu Stundenwerten meist einzeln interessant sind.
    private const OPTIONAL_DAILY = [
        'sunshine_duration'          => ['Sonnenscheindauer heute', 'h', 1 / 3600, 2],
        'daylight_duration'          => ['Tageslichtdauer heute', 'h', 1 / 3600, 2],
        'rain_sum'                   => ['Regensumme heute', 'mm', 1, 1],
        'showers_sum'                => ['Schauersumme heute', 'mm', 1, 1],
        'snowfall_sum'               => ['Schneesumme heute', 'cm', 1, 1],
        'precipitation_hours'        => ['Niederschlagsstunden heute', 'h', 1, 1],
        'temperature_2m_max'         => ['Temperatur Max heute', '°C', 1, 1],
        'temperature_2m_min'         => ['Temperatur Min heute', '°C', 1, 1],
        'wind_direction_10m_dominant' => ['Windrichtung dominant heute', '°', 1, 0],
        'uv_index_max'               => ['UV-Index Max heute', '', 1, 1],
        'shortwave_radiation_sum'    => ['Strahlungssumme heute', 'MJ/m²', 1, 2],
    ];

    public function __construct($InstanceID) {
    	parent::__construct($InstanceID);		// Diese Zeile nicht löschen

        $this->enableDebug = @$this->ReadPropertyBoolean("EnableDebug");
    }

    public function Create()
    {
        parent::Create();

        // Standort (eine Wetterabfrage deckt alle 4 Flächen ab)
        $this->RegisterPropertyFloat('Latitude', 48.325634);
        $this->RegisterPropertyFloat('Longitude', 14.426263);
        $this->RegisterPropertyString('Timezone', 'auto');

        $this->RegisterPropertyInteger('ForecastDays', 3);
        $this->RegisterPropertyInteger('PastDays', 1);
        $this->RegisterPropertyInteger('UpdateIntervalMinutes', 60);

        // globale PV-Berechnungsparameter
        $this->RegisterPropertyFloat('TempCoeffPercent', -0.4);   // %/°C bezogen auf 25°C Zelltemp.
        $this->RegisterPropertyFloat('Albedo', 0.2);               // Bodenreflexion
        $this->RegisterPropertyFloat('SystemLossPercent', 14.0);   // Verkabelung/Verschmutzung/Wechselrichter etc.

        // 4 Modulflächen, jede einzeln ein-/ausschaltbar
        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            $d = self::SITE_DEFAULTS[$i];
            $this->RegisterPropertyBoolean("Site{$i}Active", true);
            $this->RegisterPropertyString("Site{$i}Name", $d['Name']);
            $this->RegisterPropertyInteger("Site{$i}Declination", $d['Declination']);
            $this->RegisterPropertyInteger("Site{$i}Azimuth", $d['Azimuth']);
            $this->RegisterPropertyFloat("Site{$i}kWp", $d['kWp']);
            // Faiman-Wärmeverlustkoeffizienten - hängen von der Montageart ab,
            // daher je Fläche überschreibbar (Default: freistehend, gut hinterlüftet)
            $this->RegisterPropertyFloat("Site{$i}ThermalLossUc", 25.0);
            $this->RegisterPropertyFloat("Site{$i}ThermalLossUv", 6.84);
        }

        foreach (self::OPTIONAL_HOURLY as $key => $label) {
            $this->RegisterPropertyBoolean('Hourly_' . $key, false);
        }
        foreach (self::OPTIONAL_DAILY as $key => $meta) {
            $this->RegisterPropertyBoolean('Daily_' . $key, false);
        }

        $this->RegisterPropertyBoolean('EnableDebug', false);

        $this->RegisterTimer('UpdateTimer', 0, 'OPENMETEO_UpdateForecast($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfileIfNotExists('OPENMETEO.Power', '', '', ' kW', 0, 0, 0, 2, VARIABLETYPE_FLOAT);
        $this->RegisterProfileIfNotExists('OPENMETEO.Energy', '', '', ' kWh', 0, 0, 0, 2, VARIABLETYPE_FLOAT);
        $this->RegisterProfileIfNotExists('OPENMETEO.CloudCover', '', '', ' %', 0, 100, 1, 0, VARIABLETYPE_FLOAT);
        $this->RegisterProfileIfNotExists('OPENMETEO.WindSpeed', '', '', ' m/s', 0, 0, 0, 1, VARIABLETYPE_FLOAT);
        $this->RegisterOwnWeatherCodeProfile();

        // Statusinfos zum Abruf
        $this->MaintainVariable('LastUpdate', 'Letzter erfolgreicher Abruf', VARIABLETYPE_INTEGER, '~UnixTimestamp', 5, true);
        $this->MaintainVariable('NextUpdate', 'Nächster geplanter Abruf', VARIABLETYPE_INTEGER, '~UnixTimestamp', 6, true);

        // PV-Summenwerte (aktive Flächen)
        $this->MaintainVariable('CurrentPower', 'Aktuelle PV Leistung (berechnet)', VARIABLETYPE_FLOAT, 'OPENMETEO.Power', 10, true);
        $this->MaintainVariable('TodayForecastEnergy', 'Prognose Energie heute', VARIABLETYPE_FLOAT, 'OPENMETEO.Energy', 11, true);
        $this->MaintainVariable('TodayRemainingEnergy', 'Prognose Restenergie heute', VARIABLETYPE_FLOAT, 'OPENMETEO.Energy', 12, true);
        $this->MaintainVariable('TomorrowForecastEnergy', 'Prognose Energie morgen', VARIABLETYPE_FLOAT, 'OPENMETEO.Energy', 13, true);

        // "Jetzt"-Wetter, aus der stündlichen Reihe abgeleitet (kein separater API-Aufruf nötig)
        $this->MaintainVariable('WeatherCode', 'Wettercode aktuell', VARIABLETYPE_INTEGER, 'OPENMETEO.WeatherCode', 20, true);
        $this->MaintainVariable('WeatherText', 'Wetter aktuell', VARIABLETYPE_STRING, '', 21, true);
        $this->MaintainVariable('Temperature', 'Temperatur aktuell', VARIABLETYPE_FLOAT, '~Temperature', 22, true);
        $this->MaintainVariable('WindSpeed', 'Windgeschwindigkeit aktuell', VARIABLETYPE_FLOAT, 'OPENMETEO.WindSpeed', 23, true);
        $this->MaintainVariable('CloudCover', 'Bewölkung aktuell', VARIABLETYPE_FLOAT, 'OPENMETEO.CloudCover', 24, true);

        // optionale Tageswerte "heute" - nur anlegen, wenn im Formular ausgewählt
        $pos = 30;
        foreach (self::OPTIONAL_DAILY as $key => $meta) {
            $ident = 'Daily_' . $key;
            if ($this->ReadPropertyBoolean($ident)) {
                [$label, $unit] = $meta;
                $profile = $this->RegisterOptionalDailyProfile($key, $unit, $meta[3]);
                $this->MaintainVariable($ident, $label, VARIABLETYPE_FLOAT, $profile, $pos, true);
            } else {
                // sauber entfernen, falls zuvor aktiviert und jetzt wieder deaktiviert
                if (@$this->GetIDForIdent($ident)) {
                    $this->UnregisterVariable($ident);
                }
            }
            $pos++;
        }

        // gemeinsame Update-Statistik (Erfolg/Fehler inkl. Grund) - siehe EnergyForecastCommon
        $this->RegisterUpdateStatsVariables(85);

        $this->MaintainVariable('ForecastChart', 'Open-Meteo PV Prognose Chart', VARIABLETYPE_STRING, '~HTMLBox', 100, true);

        $this->MaintainVariable('Buffer', 'Wetter-Rohdaten je Zeitscheibe (JSON)', VARIABLETYPE_STRING, '', 110, true);
        $this->MaintainVariable('DailyJSON', 'Tageswerte Rohdaten (JSON)', VARIABLETYPE_STRING, '', 111, true);
        IPS_SetHidden($this->GetIDForIdent('Buffer'), true);
        IPS_SetHidden($this->GetIDForIdent('DailyJSON'), true);

        $interval = $this->ReadPropertyInteger('UpdateIntervalMinutes');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 60 * 1000);
            $this->dbg(__METHOD__, 'ApplyChanges', sprintf('UpdateTimer set to %d Min', $interval));
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->dbg(__METHOD__, 'ApplyChanges', 'UpdateTimer DEAKTIVIERT');
        }

        $anyActive = false;
        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            if ($this->ReadPropertyBoolean("Site{$i}Active")) {
                $anyActive = true;
                break;
            }
        }
        $this->SetStatus($anyActive ? 102 : 201);
    }

    // ==================== ÖFFENTLICHE MODULFUNKTIONEN ====================
    // LoadSeries() und GetWeatherAt() kommen unverändert aus dem Trait EnergyForecastCommon
    // (die Rohdaten enthalten bereits 'ts'/'w'/'t'). GetPowerAt()/GetEnergyBetween()/
    // FindBestWindow() werden hier überschrieben, da die Leistung nicht gespeichert,
    // sondern aus den Rohdaten live berechnet wird (siehe BuildDerivedSeries()).

    public function GetPowerAt(int $Timestamp): ?float
    {
        return $this->ComputePowerAt($this->BuildDerivedSeries(), $Timestamp);
    }

    public function GetEnergyBetween(int $From, int $To): float
    {
        return $this->ComputeEnergyBetween($this->BuildDerivedSeries(), $From, $To);
    }

    public function FindBestWindow(int $From, int $To, int $WindowSeconds): ?array
    {
        return $this->ComputeBestWindow($this->BuildDerivedSeries(), $From, $To, $WindowSeconds);
    }

    /**
     * Wie GetPowerAt(), aber nur für eine einzelne Fläche (1-4) statt der Summe
     * aller aktiven Flächen. Praktisch, um z.B. Ost-/West-Fläche einzeln zu vergleichen.
     */
    public function GetPowerAtSite(int $Timestamp, int $SiteIndex): ?float
    {
        return $this->ComputePowerAt($this->BuildDerivedSeries([$SiteIndex]), $Timestamp);
    }

    /**
     * Liefert die zuletzt abgerufenen Tageswerte (nur die im Formular ausgewählten
     * Felder) als PHP-Array, z.B. für eigene Skripte: OPENMETEO_LoadDaily($id).
     * @return array<int,array<string,mixed>>|null
     */
    public function LoadDaily(): ?array
    {
        $raw = $this->GetValue('DailyJSON');
        if ($raw === '' || $raw === null) {
            return null;
        }
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Ruft die Wetter-Rohdaten von Open-Meteo ab und aktualisiert alle Variablen.
     * Aufrufbar per Button im Konfigurationsformular oder per Timer.
     */
    public function UpdateForecast(): void
    {
        try {
            $url = $this->BuildUrl();
            $result = $this->PerformRequest($url);
            $this->AssertSuccessful($result);
            $this->ProcessForecast($result['data']);

            $this->SetValue('LastUpdate', time());
            $intervalMinutes = $this->ReadPropertyInteger('UpdateIntervalMinutes');
            $this->SetValue('NextUpdate', time() + $intervalMinutes * 60);
            $this->SetStatus(102);
            $this->RecordUpdateSuccess();
        } catch (Exception $e) {
            $this->LogMessage('OPENMETEO: Fehler beim Abruf: ' . $e->getMessage(), KL_WARNING);
            $this->dbg(__METHOD__, 'Error', $e->getMessage());
            $this->RecordUpdateError($this->ClassifyError($e), $e->getMessage());
            $this->SetStatus(202);
            // Alte Werte (Buffer, Chart, ...) bleiben bewusst unverändert - besser der
            // letzte gute Stand als ein überschriebener Fehlwert.
        }
    }

    public function DecodeWeatherCode(int $Code): string
    {
        // dieselben WMO-Codes wie bei PVNODE - Klartext hier lokal dupliziert, da beide
        // Module unabhängig bleiben sollen (nur das Trait-File ist gemeinsamer Code).
        $codes = [
            0 => 'Klarer Himmel', 1 => 'Überwiegend klar', 2 => 'Teilweise bewölkt', 3 => 'Bedeckt',
            45 => 'Nebel', 48 => 'Reifnebel',
            51 => 'Leichter Nieselregen', 53 => 'Mäßiger Nieselregen', 55 => 'Starker Nieselregen',
            56 => 'Leichter gefrierender Nieselregen', 57 => 'Starker gefrierender Nieselregen',
            61 => 'Leichter Regen', 63 => 'Mäßiger Regen', 65 => 'Starker Regen',
            66 => 'Leichter gefrierender Regen', 67 => 'Starker gefrierender Regen',
            71 => 'Leichter Schneefall', 73 => 'Mäßiger Schneefall', 75 => 'Starker Schneefall', 77 => 'Schneegriesel',
            80 => 'Leichte Regenschauer', 81 => 'Mäßige Regenschauer', 82 => 'Heftige Regenschauer',
            85 => 'Leichte Schneeschauer', 86 => 'Starke Schneeschauer',
            95 => 'Gewitter', 96 => 'Gewitter mit leichtem Hagel', 99 => 'Gewitter mit starkem Hagel',
        ];
        return $codes[$Code] ?? ('Unbekannter Wettercode (' . $Code . ')');
    }

    // ==================== INTERNE HILFSFUNKTIONEN ====================

    private function ClassifyError(Exception $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'cURL-Fehler')) {
            return 'Network';
        }
        if (str_contains($msg, 'HTTP 429')) {
            return 'RateLimit';
        }
        if (str_contains($msg, 'HTTP 400')) {
            return 'Config';
        }
        if (str_contains($msg, 'HTTP ')) {
            return 'Http';
        }
        if (str_contains($msg, 'JSON') || str_contains($msg, 'Antwortstruktur') || str_contains($msg, 'Leere Antwort')) {
            return 'ParseError';
        }
        return 'Other';
    }

    private function RegisterOwnWeatherCodeProfile(): void
    {
        if (!IPS_VariableProfileExists('OPENMETEO.WeatherCode')) {
            IPS_CreateVariableProfile('OPENMETEO.WeatherCode', VARIABLETYPE_INTEGER);
            for ($code = 0; $code <= 99; $code++) {
                $text = $this->DecodeWeatherCode($code);
                if (strpos($text, 'Unbekannter') === false) {
                    IPS_SetVariableProfileAssociation('OPENMETEO.WeatherCode', $code, $text, '', -1);
                }
            }
        }
    }

    private function RegisterOptionalDailyProfile(string $key, string $unit, int $digits): string
    {
        $name = 'OPENMETEO.Daily.' . $key;
        $this->RegisterProfileIfNotExists($name, '', '', $unit !== '' ? (' ' . $unit) : '', 0, 0, 0, $digits, VARIABLETYPE_FLOAT);
        return $name;
    }

    private function BuildUrl(): string
    {
        $hourlyParams = array_merge(
            ['shortwave_radiation', 'direct_normal_irradiance', 'diffuse_radiation', 'temperature_2m', 'wind_speed_10m', 'weather_code', 'cloud_cover'],
            array_keys(array_filter(self::OPTIONAL_HOURLY, fn ($label, $key) => $this->ReadPropertyBoolean('Hourly_' . $key), ARRAY_FILTER_USE_BOTH))
        );

        $dailyParams = array_keys(array_filter(self::OPTIONAL_DAILY, fn ($meta, $key) => $this->ReadPropertyBoolean('Daily_' . $key), ARRAY_FILTER_USE_BOTH));

        $params = [
            'latitude'        => $this->FormatNumber($this->ReadPropertyFloat('Latitude'), 6),
            'longitude'       => $this->FormatNumber($this->ReadPropertyFloat('Longitude'), 6),
            'hourly'          => implode(',', $hourlyParams),
            'timezone'        => $this->ReadPropertyString('Timezone'),
            'past_days'       => (string) $this->ReadPropertyInteger('PastDays'),
            'forecast_days'   => (string) $this->ReadPropertyInteger('ForecastDays'),
            'wind_speed_unit' => 'ms', // damit das Faiman-Modell direkt m/s bekommt
        ];
        if (count($dailyParams) > 0) {
            $params['daily'] = implode(',', $dailyParams);
        }

        return self::API_BASE . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function PerformRequest(string $url): array
    {
        $this->dbg(__METHOD__, 'Request', $url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->dbg(__METHOD__, 'Response', sprintf('HTTP %d, %d Bytes, curlErrno=%d', $httpCode, is_string($body) ? strlen($body) : 0, $curlErrNo));

        if ($curlErrNo !== 0) {
            throw new RuntimeException("cURL-Fehler ($curlErrNo): $curlErr");
        }

        $data = null;
        if ($httpCode === 200) {
            if ($body === '' || $body === false) {
                throw new RuntimeException('Leere Antwort von Open-Meteo.');
            }
            $data = json_decode((string) $body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('JSON-Fehler: ' . json_last_error_msg());
            }
            if (!isset($data['hourly']['time']) || !is_array($data['hourly']['time']) || !isset($data['timezone'])) {
                throw new RuntimeException('Antwortstruktur unerwartet (hourly/timezone fehlt).');
            }
        }

        return ['httpCode' => $httpCode, 'data' => $data, 'body' => (string) $body];
    }

    private function AssertSuccessful(array $result): void
    {
        if ($result['httpCode'] === 200) {
            return;
        }
        $hint = 'Unerwarteter HTTP-Status.';
        if ($result['httpCode'] === 400) {
            $hint = 'Ungültige Parameter (z.B. Koordinaten oder Variablenname prüfen).';
        } elseif ($result['httpCode'] === 429) {
            $hint = 'Rate Limit erreicht - Abfrageintervall erhöhen.';
        } elseif ($result['httpCode'] >= 500) {
            $hint = 'Open-Meteo Serverfehler, später erneut versuchen.';
        }
        throw new RuntimeException('HTTP ' . $result['httpCode'] . ' - ' . $hint . ' Antwort: ' . substr($result['body'], 0, 300));
    }

    private function ProcessForecast(array $data): void
    {
        $tz = new DateTimeZone($data['timezone']);
        $now = time();

        $hourly = $data['hourly'];
        $times = $hourly['time'];

        $series = [];
        foreach ($times as $i => $timeStr) {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $timeStr, $tz);
            if ($dt === false) {
                continue; // defensiv: unerwartetes Zeitformat überspringen
            }
            $point = [
                'ts'   => $dt->getTimestamp(),
                'ghi'  => (float) ($hourly['shortwave_radiation'][$i] ?? 0),
                'dni'  => (float) ($hourly['direct_normal_irradiance'][$i] ?? 0),
                'dhi'  => (float) ($hourly['diffuse_radiation'][$i] ?? 0),
                't'    => (float) ($hourly['temperature_2m'][$i] ?? 0),
                'wind' => (float) ($hourly['wind_speed_10m'][$i] ?? 0), // m/s (wind_speed_unit=ms)
                'w'    => (int) ($hourly['weather_code'][$i] ?? 0),
                'cloud' => (float) ($hourly['cloud_cover'][$i] ?? 0),
            ];
            foreach (self::OPTIONAL_HOURLY as $key => $label) {
                if ($this->ReadPropertyBoolean('Hourly_' . $key) && isset($hourly[$key][$i])) {
                    $point[$key] = $hourly[$key][$i];
                }
            }
            $series[] = $point;
        }
        usort($series, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        $this->dbg(__METHOD__, 'Parse', sprintf('SeriesCnt=%d Timezone=%s', count($series), $data['timezone']));

        $this->SetValue('Buffer', json_encode([
            'generated_at' => $now,
            'timezone'     => $data['timezone'],
            'series'       => $series,
        ], JSON_UNESCAPED_SLASHES));

        // Tageswerte (nur ausgewählte Felder)
        $dailyRows = [];
        if (isset($data['daily']['time']) && is_array($data['daily']['time'])) {
            foreach ($data['daily']['time'] as $i => $dateStr) {
                $row = ['date' => $dateStr];
                foreach (self::OPTIONAL_DAILY as $key => $meta) {
                    if ($this->ReadPropertyBoolean('Daily_' . $key) && isset($data['daily'][$key][$i])) {
                        $row[$key] = $data['daily'][$key][$i];
                    }
                }
                $dailyRows[] = $row;
            }
        }
        $this->SetValue('DailyJSON', json_encode($dailyRows, JSON_UNESCAPED_SLASHES));

        // "Heute"-Variablen für ausgewählte Tageswerte
        $todayStr = (new DateTime('now', $tz))->format('Y-m-d');
        foreach ($dailyRows as $row) {
            if ($row['date'] !== $todayStr) {
                continue;
            }
            foreach (self::OPTIONAL_DAILY as $key => $meta) {
                $ident = 'Daily_' . $key;
                if (isset($row[$key]) && @$this->GetIDForIdent($ident)) {
                    $factor = $meta[2];
                    $this->SetValue($ident, round(((float) $row[$key]) * $factor, $meta[3]));
                }
            }
        }

        // "Jetzt"-Wetter aus der stündlichen Reihe ableiten (nächste Zeitscheibe >= jetzt, sonst letzte bekannte)
        $nowPoint = null;
        foreach ($series as $point) {
            if ($point['ts'] >= $now) {
                $nowPoint = $point;
                break;
            }
        }
        if ($nowPoint === null && count($series) > 0) {
            $nowPoint = end($series);
        }
        if ($nowPoint !== null) {
            $this->SetValue('WeatherCode', $nowPoint['w']);
            $this->SetValue('WeatherText', $this->DecodeWeatherCode($nowPoint['w']));
            $this->SetValue('Temperature', round($nowPoint['t'], 1));
            $this->SetValue('WindSpeed', round($nowPoint['wind'], 1));
            $this->SetValue('CloudCover', round($nowPoint['cloud'], 0));
        }

        // PV-Summenwerte (aktive Flächen) live berechnen
        $derived = $this->BuildDerivedSeries();
        $todayStart = $this->GetTimeOnDay(0);
        $todayEnd = $this->GetTimeOnDay(1);
        $tomorrowEnd = $this->GetTimeOnDay(2);

        $this->SetValue('CurrentPower', round($this->ComputePowerAt($derived, $now) ?? 0, 3));
        $this->SetValue('TodayForecastEnergy', $this->ComputeEnergyBetween($derived, $todayStart, $todayEnd));
        $this->SetValue('TodayRemainingEnergy', $this->ComputeEnergyBetween($derived, $now, $todayEnd));
        $this->SetValue('TomorrowForecastEnergy', $this->ComputeEnergyBetween($derived, $todayEnd, $tomorrowEnd));

        $this->dbg(__METHOD__, 'Result', sprintf('CurrentPower=%s TodayRemaining=%s', $this->GetValue('CurrentPower'), $this->GetValue('TodayRemainingEnergy')));

        // Chart über den gesamten abgerufenen Horizont (past_days .. forecast_days)
        $this->SetValue('ForecastChart', $this->BuildForecastChartHtml($derived, $series));
    }

    /**
     * Berechnet für die übergebene Rohdaten-Zeitreihe (Standard: LoadSeries(), also alle
     * abgerufenen Zeitscheiben) die Summenleistung/-energie der aktiven Flächen je Zeitscheibe.
     * $OnlySites erlaubt, nur eine Teilmenge der Flächen zu berücksichtigen (siehe GetPowerAtSite()).
     * @return array<int,array{ts:int,p:float,e:float}>
     */
    private function BuildDerivedSeries(?array $OnlySites = null): array
    {
        $raw = $this->LoadSeries();
        if ($raw === null) {
            return [];
        }

        $activeSites = [];
        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            if ($OnlySites !== null && !in_array($i, $OnlySites, true)) {
                continue;
            }
            if ($this->ReadPropertyBoolean("Site{$i}Active")) {
                $activeSites[$i] = [
                    'tilt'    => (float) $this->ReadPropertyInteger("Site{$i}Declination"),
                    'azimuth' => (float) $this->ReadPropertyInteger("Site{$i}Azimuth"),
                    'kwp'     => $this->ReadPropertyFloat("Site{$i}kWp"),
                    'uc'      => $this->ReadPropertyFloat("Site{$i}ThermalLossUc"),
                    'uv'      => $this->ReadPropertyFloat("Site{$i}ThermalLossUv"),
                ];
            }
        }

        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        $albedo = $this->ReadPropertyFloat('Albedo');
        $tempCoeff = $this->ReadPropertyFloat('TempCoeffPercent');
        $sysLoss = $this->ReadPropertyFloat('SystemLossPercent');

        $result = [];
        foreach ($raw as $point) {
            $ts = $point['ts'] ?? null;
            if ($ts === null) {
                continue;
            }
            if (count($activeSites) === 0) {
                $result[] = ['ts' => $ts, 'p' => 0.0, 'e' => 0.0];
                continue;
            }

            $sunPos = $this->SolarPosition($ts, $lat, $lon);
            $totalPower = 0.0;
            foreach ($activeSites as $site) {
                $gti = $this->CalculateGti(
                    (float) ($point['ghi'] ?? 0),
                    (float) ($point['dni'] ?? 0),
                    (float) ($point['dhi'] ?? 0),
                    $site['tilt'],
                    $site['azimuth'],
                    $sunPos['elevation'],
                    $sunPos['azimuth'],
                    $albedo
                );
                $cellTemp = $this->CalculateCellTemperature((float) ($point['t'] ?? 0), $gti, (float) ($point['wind'] ?? 0), $site['uc'], $site['uv']);
                $totalPower += $this->CalculatePvPower($gti, $cellTemp, $site['kwp'], $tempCoeff, $sysLoss);
            }

            // Open-Meteo liefert stündliche Zeitscheiben -> Energie (kWh) == Leistung (kW) * 1h
            $result[] = ['ts' => $ts, 'p' => round($totalPower, 4), 'e' => round($totalPower, 4)];
        }

        return $result;
    }

    /**
     * Baut den Inhalt der HTMLBox-Variable ForecastChart: berechnete PV-Summenleistung
     * (kW) sowie zur Einordnung GHI (W/m²) auf einer zweiten Achse.
     */
    private function BuildForecastChartHtml(array $derivedSeries, array $rawSeries): string
    {
        $labels = [];
        $dataPower = [];
        $dataGhi = [];

        $rawByTs = [];
        foreach ($rawSeries as $p) {
            $rawByTs[$p['ts']] = $p;
        }

        foreach ($derivedSeries as $entry) {
            $labels[] = date('d.m. H:i', $entry['ts']);
            $dataPower[] = round($entry['p'], 3);
            $dataGhi[] = round($rawByTs[$entry['ts']]['ghi'] ?? 0, 0);
        }

        $chartId = 'openmeteoChart' . $this->InstanceID;
        $payload = json_encode([
            'labels' => $labels,
            'power'  => $dataPower,
            'ghi'    => $dataGhi,
        ]);

        return <<<HTML
<div style="width:100%;height:300px;">
    <canvas id="{$chartId}"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    var d = {$payload};
    var ctx = document.getElementById('{$chartId}').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: d.labels,
            datasets: [
                {
                    label: 'PV Leistung (berechnet)',
                    data: d.power,
                    borderColor: '#f2a900',
                    backgroundColor: 'rgba(242,169,0,0.15)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 2,
                    yAxisID: 'y'
                },
                {
                    label: 'GHI (W/m²)',
                    data: d.ghi,
                    borderColor: '#8e44ad',
                    backgroundColor: 'transparent',
                    borderDash: [4, 3],
                    borderWidth: 1.25,
                    fill: false,
                    tension: 0.3,
                    pointRadius: 0,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y:  { position: 'left', title: { display: true, text: 'kW' } },
                y1: { position: 'right', title: { display: true, text: 'W/m²' }, grid: { drawOnChartArea: false } }
            }
        }
    });
})();
</script>
HTML;
    }
}
