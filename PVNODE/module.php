<?php

declare(strict_types=1);

/**
 * Class PVNODEForecast
 * -------------------------------------------------------------
 * IP-Symcon Modul für die pvnode V2 Forecast-API.
 *
 * Funktioniert bewusst auch mit dem kostenlosen pvnode-Plan:
 *  - fordert standardmäßig KEIN festes forecast_days an, sondern
 *    lässt den Server automatisch das Planmaximum liefern
 *  - fällt bei fehlender Wetter-Berechtigung (HTTP 403) automatisch
 *    auf eine Anfrage ohne Wetterdaten zurück, statt fehlzuschlagen
 *  - Abfrageintervall ist frei konfigurierbar, damit das monatliche
 *    Cache-Request-Limit (250/Monat im Free-Plan) nicht gesprengt wird
 *
 * Da IP-Symcon keine "zukünftigen" Variablenwerte speichern kann,
 * werden nur aussagekräftige "Jetzt"-Werte als Variablen abgelegt.
 * Der komplette Rest der Zeitreihe liegt zusätzlich als JSON-Buffer
 * vor und ist über PVNODE_GetPowerAt() / PVNODE_GetEnergyBetween() /
 * PVNODE_FindBestWindow() / PVNODE_GetWeatherAt() für beliebige
 * Zukunftszeitpunkte abfragbar.
 * -------------------------------------------------------------
 */
class PVNODEForecast extends IPSModule
{
    private const API_BASE_URL = 'https://api.pvnode.com/v2/forecast/';
    private const ERROR_STATUS_THRESHOLD = 5;
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_ERROR = 201;

    private $enableDebug = false;

    // WMO-Wettercodes gemäß https://pvnode.com/docs/en/v2/guides/weather-codes
    private const WEATHER_CODES = [
        0  => 'Klarer Himmel',
        1  => 'Überwiegend klar',
        2  => 'Teilweise bewölkt',
        3  => 'Bedeckt',
        45 => 'Nebel',
        48 => 'Reifnebel',
        51 => 'Leichter Nieselregen',
        53 => 'Mäßiger Nieselregen',
        55 => 'Starker Nieselregen',
        56 => 'Leichter gefrierender Nieselregen',
        57 => 'Starker gefrierender Nieselregen',
        61 => 'Leichter Regen',
        63 => 'Mäßiger Regen',
        65 => 'Starker Regen',
        66 => 'Leichter gefrierender Regen',
        67 => 'Starker gefrierender Regen',
        71 => 'Leichter Schneefall',
        73 => 'Mäßiger Schneefall',
        75 => 'Starker Schneefall',
        77 => 'Schneegriesel',
        80 => 'Leichte Regenschauer',
        81 => 'Mäßige Regenschauer',
        82 => 'Heftige Regenschauer',
        85 => 'Leichte Schneeschauer',
        86 => 'Starke Schneeschauer',
        95 => 'Gewitter',
        96 => 'Gewitter mit leichtem Hagel',
        99 => 'Gewitter mit starkem Hagel',
    ];

    public function __construct($InstanceID) {
    	parent::__construct($InstanceID);		// Diese Zeile nicht löschen

        $this->enableDebug = @$this->ReadPropertyBoolean("EnableDebug");
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyString('SiteID', '');
        $this->RegisterPropertyInteger('ForecastDays', 0); // 0 = Planmaximum (empfohlen, funktioniert auf jedem Plan)
        $this->RegisterPropertyInteger('PastDays', 0); 
        $this->RegisterPropertyBoolean('IncludeDefault', true);
        $this->RegisterPropertyBoolean('IncludeWeather', true);
        $this->RegisterPropertyBoolean('IncludeIrradiance', false);
        $this->RegisterPropertyBoolean('IncludeClearsky', false);
        $this->RegisterPropertyBoolean('IncludeStrings', false);
        $this->RegisterPropertyBoolean('IncludeVariability', false);
        $this->RegisterPropertyInteger('UpdateInterval', 180); // Minuten - siehe Hinweis im Formular zum Free-Plan-Limit
        $this->RegisterPropertyInteger('Timeout', 15);
        $this->RegisterPropertyBoolean('EnableDebug', false);

        $this->RegisterTimer('UpdateTimer', 0, 'PVNODE_UpdateForecast($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return; // Boot-Reihenfolge: erst nach vollständigem Hochfahren aktiv werden
        }

        $this->RegisterProfiles();
        $this->MaintainVariables();

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if($interval > 0) {
            if ($interval < 5) { $interval = 5; }
            $this->SetTimerInterval('UpdateTimer', $interval * 60 * 1000);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("UpdateTimer set to %d Min", $interval), 0); }
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, "UpdateTimer DEAKTIVIERT", 0); }
        }

        if ($this->ReadPropertyString('APIKey') !== '' && $this->ReadPropertyString('SiteID') !== '') {
            $this->SetStatus(self::STATUS_ACTIVE);
        } else {
            $this->SetStatus(self::STATUS_INACTIVE);
        }
    }

    // ==================== ÖFFENTLICHE MODULFUNKTIONEN ====================
    // Automatisch verfügbar als PVNODE_<Funktionsname>($InstanceID, ...)

    /**
     * Ruft die Prognose ab und aktualisiert alle Variablen. Wird per Timer
     * periodisch aufgerufen, kann aber auch manuell (Button im Formular,
     * eigene Skripte) getriggert werden: PVNODE_UpdateForecast($id);
     */
    public function UpdateForecast(): void
    {
        $apiKey = $this->ReadPropertyString('APIKey');
        $siteId = $this->ReadPropertyString('SiteID');

        if ($apiKey === '' || $siteId === '') {
            $this->SetValue('Status', 'FEHLER: API-Key oder Site-ID nicht konfiguriert');
            $this->SetValue('LastUpdate', time());
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }

        $timeout = $this->ReadPropertyInteger('Timeout');
        $forecastDays = $this->ReadPropertyInteger('ForecastDays');
        $pastDays = $this->ReadPropertyInteger('PastDays');
        $includeDefault = $this->ReadPropertyBoolean('IncludeDefault');
        $includeWeather = $this->ReadPropertyBoolean('IncludeWeather');
        $includeIrradiance = $this->ReadPropertyBoolean('IncludeIrradiance');
        $includeClearsky = $this->ReadPropertyBoolean('IncludeClearsky');
        $includeStrings = $this->ReadPropertyBoolean('IncludeStrings');
        $includeVariability = $this->ReadPropertyBoolean('IncludeVariability');

        try {
           $url = $this->BuildUrl($siteId, $forecastDays, $pastDays, $includeDefault, $includeWeather, $includeIrradiance, $includeClearsky, $includeStrings, $includeVariability, "local");
           $result = $this->PerformRequest($url, $apiKey, $timeout);

            /*
            // Free/Light-Plan enthält evtl. keine Wetterdaten -> automatisch ohne erneut versuchen,
            // statt das ganze Update fehlschlagen zu lassen.
            if ($result['httpCode'] === 403 && $includeWeather) {
                $this->LogMessage('PVNODE: Wetterdaten laut Plan nicht verfügbar (403) - erneuter Versuch ohne Wetter.', KL_NOTIFY);
                $includeWeather = false;
                $url = $this->BuildUrl($siteId, $forecastDays, false);
                $result = $this->PerformRequest($url, $apiKey, $timeout);
            }
            */

            $this->AssertSuccessful($result);
            $this->ProcessForecast($result['data'], $includeDefault, $includeWeather, $includeIrradiance, $includeClearsky, $includeStrings, $includeVariability);

            $this->SetValue('Status', 'OK');
            $this->SetValue('ErrorCount', 0);
            $this->SetValue('LastSuccess', time());
            $this->SetStatus(self::STATUS_ACTIVE);
        } catch (Exception $e) {
            $errCount = $this->GetValue('ErrorCount') + 1;
            $this->SetValue('ErrorCount', $errCount);
            $this->SetValue('Status', 'FEHLER: ' . $e->getMessage());
            $this->LogMessage("PVNODE: Fehler beim Abruf ($errCount. in Folge): " . $e->getMessage(), KL_WARNING);

            // Alte Werte (Leistung, Buffer, Tagesprognosen...) bleiben bewusst unverändert -
            // besser der letzte gute Wert als ein überschriebener Fehlwert.
            if ($errCount >= self::ERROR_STATUS_THRESHOLD) {
                $this->SetStatus(self::STATUS_ERROR);
            }
        }

        $this->SetValue('LastUpdate', time());
    }

    /**
     * Liefert die prognostizierte PV-Leistung (W) zu einem beliebigen Zeitpunkt
     * (Vergangenheit im Buffer, aktuell oder Zukunft innerhalb des Prognosehorizonts).
     * Nimmt den letzten bekannten Slot <= $Timestamp. Gibt null zurück, wenn dazu
     * keine Daten vorliegen.
     */
    public function GetPowerAt(int $Timestamp): ?float
    {
        $series = $this->LoadSeries();
        if ($series === null) {
            return null;
        }

        $best = null;
        foreach ($series as $point) {
            if ($point['ts'] <= $Timestamp) {
                $best = $point['p'];
            } else {
                break; // Serie ist zeitlich aufsteigend sortiert
            }
        }
        return $best !== null ? (float)$best : null;
    }

    /**
     * Summiert die prognostizierte Energie (kWh) im Intervall [$From, $To).
     * Nützlich z.B. für "wie viel PV-Ertrag erwarte ich in den nächsten 2 Stunden"
     * oder zur Planung eines EV-Ladefensters.
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

        $sumKwh = 0.0;
        foreach ($series as $point) {
            if ($point['ts'] >= $From && $point['ts'] < $To) {
                $sumKwh += (float)$point['p'] * 0.25 / 1000.0; // 15-Min-Slot als Rechteck angenähert
            }
        }
        return round($sumKwh, 3);
    }

    /**
     * Liefert Wettercode, Klartext und Temperatur zum nächsten Slot <= $Timestamp,
     * sofern IncludeWeather aktiv ist und Daten vorliegen. Gibt sonst null zurück.
     * @return array{code:int,text:string,temp:float}|null
     */
    public function GetWeatherAt(int $Timestamp): ?array
    {
        $series = $this->LoadSeries();
        if ($series === null) {
            return null;
        }

        $best = null;
        foreach ($series as $point) {
            if ($point['ts'] <= $Timestamp && isset($point['w'])) {
                $best = $point;
            } elseif ($point['ts'] > $Timestamp) {
                break;
            }
        }
        if ($best === null) {
            return null;
        }
        return [
            'code' => (int)$best['w'],
            'text' => $this->DecodeWeatherCode((int)$best['w']),
            'temp' => isset($best['t']) ? (float)$best['t'] : null,
        ];
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
            return $p['ts'] >= $From && $p['ts'] < $To;
        }));
        if (count($relevant) === 0) {
            return null;
        }

        $best = null;
        foreach ($relevant as $start) {
            $windowEnd = $start['ts'] + $WindowSeconds;
            $sumKwh = 0.0;
            foreach ($relevant as $p) {
                if ($p['ts'] >= $start['ts'] && $p['ts'] < $windowEnd) {
                    $sumKwh += (float)$p['p'] * 0.25 / 1000.0;
                }
            }
            if ($best === null || $sumKwh > $best['energy_kwh']) {
                $best = ['start' => $start['ts'], 'end' => $windowEnd, 'energy_kwh' => round($sumKwh, 3)];
            }
        }
        return $best;
    }

    /**
     * Übersetzt einen pvnode-Wettercode (WMO) in einen deutschen Klartext.
     * Unbekannte Codes liefern einen generischen Hinweis statt eines Fehlers.
     */
    public function DecodeWeatherCode(int $Code): string
    {
        return self::WEATHER_CODES[$Code] ?? ('Unbekannter Wettercode (' . $Code . ')');
    }

    // ==================== INTERNE HILFSFUNKTIONEN ====================

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('PVNODE.WeatherCode')) {
            IPS_CreateVariableProfile('PVNODE.WeatherCode', VARIABLETYPE_INTEGER);
            foreach (self::WEATHER_CODES as $code => $text) {
                IPS_SetVariableProfileAssociation('PVNODE.WeatherCode', $code, $text, '', -1);
            }
        }
    }

    private function MaintainVariables(): void
    {
        $this->MaintainVariable('Status', 'Status', VARIABLETYPE_STRING, '', 0, true);
        $this->MaintainVariable('LastUpdate', 'Letzter Abrufversuch', VARIABLETYPE_INTEGER, '~UnixTimestamp', 1, true);
        $this->MaintainVariable('LastSuccess', 'Letzter erfolgr. Abruf', VARIABLETYPE_INTEGER, '~UnixTimestamp', 2, true);
        $this->MaintainVariable('ErrorCount', 'Fehler in Folge', VARIABLETYPE_INTEGER, '', 3, true);

        $this->MaintainVariable('CurrentPower', 'Aktuelle Leistung (W)', VARIABLETYPE_FLOAT, '~Watt', 10, true);
        $this->MaintainVariable('Next1h', 'Prognose nächste 1h', VARIABLETYPE_FLOAT, '~Electricity', 11, true);
        $this->MaintainVariable('Next4h', 'Prognose nächste 4h', VARIABLETYPE_FLOAT, '~Electricity', 12, true);
        $this->MaintainVariable('RemainingToday', 'Rest heute', VARIABLETYPE_FLOAT, '~Electricity', 13, true);
        $this->MaintainVariable('TodayTotal', 'Tagesertrag heute', VARIABLETYPE_FLOAT, '~Electricity', 14, true);
        $this->MaintainVariable('Tomorrow', 'Tagesertrag morgen', VARIABLETYPE_FLOAT, '~Electricity', 15, true);

        // Wetter-Variablen immer anlegen: daily.weather_code/temp_min/temp_max liefert pvnode
        // erfahrungsgemäß auch ohne include=weather mit; die Variablen bleiben einfach leer/0,
        // falls im jeweiligen Plan wirklich keine Wetterdaten enthalten sind.
        $this->MaintainVariable('WeatherCode', 'Wettercode aktuell', VARIABLETYPE_INTEGER, 'PVNODE.WeatherCode', 20, true);
        $this->MaintainVariable('WeatherText', 'Wetter aktuell', VARIABLETYPE_STRING, '', 21, true);
        $this->MaintainVariable('Temperature', 'Temperatur aktuell', VARIABLETYPE_FLOAT, '~Temperature', 22, true);
        $this->MaintainVariable('WeatherCodeToday', 'Wettercode heute (dominant)', VARIABLETYPE_INTEGER, 'PVNODE.WeatherCode', 23, true);

        $this->MaintainVariable('Buffer', 'Prognose-Buffer (JSON)', VARIABLETYPE_STRING, '', 90, true);
        IPS_SetHidden($this->GetIDForIdent('Buffer'), true); // technischer Buffer, im WebFront nicht relevant
    }

    private function BuildUrl(string $siteId, int $forecastDays=0, int $past_days=0, bool $includeDefault=true, bool $includeWeather=false, bool $includeIrradiance=false, bool $includeClearsky=false, bool $includeStrings=false, bool $includeVariability=false, string $timezone="local"): string
    {
        $params = [];
        if ($forecastDays > 0) {
            $params[] = 'forecast_days=' . $forecastDays;
        } // 0 = Parameter weglassen -> Server liefert automatisch das Planmaximum
        if ($past_days > 0) {
            $params[] = 'past_days=' . $past_days;
        }
        if ($includeDefault) {
            $params[] = 'include=default';
        }
        if ($includeWeather) {
            $params[] = 'include=weather';
        }
        if ($includeIrradiance) {
            $params[] = 'include=irradiance';
        }     
        if ($includeClearsky) {
            $params[] = 'include=clearsky';
        }             
        if ($includeStrings) {
            $params[] = 'include=strings';
        } 
        if ($includeVariability) {
            $params[] = 'include=variability';
        }     
        $params[] = 'timezone=' . $timezone;

        $query = count($params) > 0 ? '?' . implode('&', $params) : '';
        return self::API_BASE_URL . rawurlencode($siteId) . $query;
    }

    /**
     * Führt den HTTP-Request aus. Wirft nur bei Netzwerk-/Transportfehlern und
     * bei ungültigem JSON eine Exception - HTTP-Fehlercodes werden im Ergebnis
     * zurückgegeben, damit der Aufrufer (z.B. für den Wetter-Fallback) reagieren kann.
     * @return array{httpCode:int, data:?array}
     */
    private function PerformRequest(string $url, string $apiKey, int $timeoutSec): array
    {
        if($this->enableDebug) { $this->SendDebug(__METHOD__, $url, 0); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body      = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr   = curl_error($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            throw new RuntimeException("cURL-Fehler ($curlErrNo): $curlErr");
        }

        $data = null;
        if ($httpCode === 200) {
            if ($body === '' || $body === false) {
                throw new RuntimeException('Leere Antwort vom pvnode-Server.');
            }
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('JSON-Fehler: ' . json_last_error_msg());
            }
            if (!isset($data['values']) || !is_array($data['values']) || !isset($data['timezone'])) {
                throw new RuntimeException('Antwortstruktur unerwartet (values/timezone fehlt).');
            }
        }

        if(false) {
            $this->SendDebug(__METHOD__, (string)$body, 0);
            //$this->SendDebug(__METHOD__, (string)$data, 0);
        }
        return ['httpCode' => $httpCode, 'data' => $data, 'body' => (string)$body];
    }

    private function AssertSuccessful(array $result): void
    {
        if ($result['httpCode'] === 200) {
            return;
        }

        $hint = 'Unerwarteter HTTP-Status.';
        if ($result['httpCode'] === 401 || $result['httpCode'] === 403) {
            $hint = 'API-Key ungültig oder Zugriff im aktuellen Plan nicht enthalten.';
        } elseif ($result['httpCode'] === 404) {
            $hint = 'Site-ID nicht gefunden - Konfiguration prüfen.';
        } elseif ($result['httpCode'] === 429) {
            $hint = 'Monatliches Request-Limit erreicht - Abfrageintervall im Formular erhöhen.';
        } elseif ($result['httpCode'] >= 500) {
            $hint = 'pvnode-Serverfehler, später erneut versuchen.';
        }
        throw new RuntimeException('HTTP ' . $result['httpCode'] . ' - ' . $hint . ' Antwort: ' . substr($result['body'], 0, 300));
    }

    private function ProcessForecast(array $data, bool $includeDefault, bool $includeWeather, bool $includeIrradiance, bool $includeClearsky, bool $includeStrings, bool $includeVariability): void
    {
        $tz  = new DateTimeZone($data['timezone']);
        $now = new DateTime('now', $tz);
        $nowTs = $now->getTimestamp();
        $today = $now->format('Y-m-d');
        $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');

        $in1h = $nowTs + 3600;
        $in4h = $nowTs + 4 * 3600;

        $currentPower   = null;
        $currentWeather = null;
        $currentTemp    = null;
        $next1hKwh      = 0.0;
        $next4hKwh      = 0.0;
        $remainingToday = 0.0;
        $series         = [];

        foreach ($data['values'] as $row) {

            if (!isset($row['timestamp'], $row['pv_power'])) {
                //if($this->enableDebug) { $this->SendDebug(__METHOD__, print_r($row, true), 0); }
                continue; // defensiv: unvollständige Zeile überspringen
            }
            $slotDt = DateTime::createFromFormat('Y-m-d\TH:i:s', $row['timestamp'], $tz);
            if ($slotDt === false) {
                continue; // defensiv: unerwartetes Zeitformat überspringen
            }
            $slotTs = $slotDt->getTimestamp();
            $power  = (float)$row['pv_power'];
            $energyKwh = $power * 0.25 / 1000.0;

            //if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("%s :: %s | %s", $slotTs, $power, $energyKwh), 0); }

            if ($slotTs <= $nowTs) {
                $currentPower = $power;
                if ($includeWeather && isset($row['weather_code'])) {
                    $currentWeather = (int)$row['weather_code'];
                    $currentTemp = isset($row['temp']) ? (float)$row['temp'] : null;
                }
            }

            if ($slotTs >= $nowTs) {
                $point = ['ts' => $slotTs, 'p' => $power];
                if ($includeWeather && isset($row['weather_code'])) {
                    $point['w'] = (int)$row['weather_code'];
                    if (isset($row['temp'])) {
                        $point['t'] = (float)$row['temp'];
                    }
                }

                if ($includeIrradiance) {
                    $point['ghi'] = isset($row['ghi']) ? (float)$row['ghi'] : null;
                    $point['dhi'] = isset($row['dhi']) ? (float)$row['dhi'] : null;
                    $point['bni'] = isset($row['bni']) ? (float)$row['bni'] : null;
                }

                if ($includeClearsky) {
                    $point['pv_power_clearsky'] = isset($row['pv_power_clearsky']) ? (float)$row['pv_power_clearsky'] : null;
                }    
                
                if ($includeStrings) {
                    $point['string_id'] = isset($row['string_id']) ? (float)$row['string_id'] : null;
                }                  

                if ($includeVariability) {
                    $point['pv_power_min'] = isset($row['pv_power_min']) ? (float)$row['pv_power_min'] : null;
                    $point['pv_power_max'] = isset($row['pv_power_max']) ? (float)$row['pv_power_max'] : null;
                } 

                if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("Point :: %s ", print_r($point, true)), 0); }
                $series[] = $point;

                if ($slotTs < $in1h) {
                    $next1hKwh += $energyKwh;
                }
                if ($slotTs < $in4h) {
                    $next4hKwh += $energyKwh;
                }
                if ($slotDt->format('Y-m-d') === $today) {
                    $remainingToday += $energyKwh;
                }
                if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("1h: %s  | 4h: %s | remainingToday: %s", $next1hKwh, $next4hKwh, $remainingToday), 0); }
                //if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("%s >= %s", $slotTs, $nowTs), 0); }
            } else {
                if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("%s < %s", $slotTs, $nowTs), 0); }
            }
        }

        if ($currentPower === null && count($series) > 0) {
            $currentPower = $series[0]['p']; // vor Sonnenaufgang o.ä.
            $currentWeather = $series[0]['w'] ?? null;
            $currentTemp = $series[0]['t'] ?? null;
        }
        if ($currentPower === null) {
            $currentPower = 0.0;
        }

        // - - - - DAILY Forecase - - - -
        $todayTotalKwh = null;
        $tomorrowTotalKwh = null;
        $todayWeatherCode = null;
        foreach ($data['daily'] ?? [] as $d) {
            if (($d['date'] ?? '') === $today) {
                $todayTotalKwh = (float)$d['pv_energy_kwh'];
                if (isset($d['weather_code'])) {
                    $todayWeatherCode = (int)$d['weather_code'];
                }
            }
            if (($d['date'] ?? '') === $tomorrow) {
                $tomorrowTotalKwh = (float)$d['pv_energy_kwh'];
            }
        }

        // ---- Werte schreiben ----
        $this->SetValue('CurrentPower', round($currentPower, 1));
        $this->SetValue('Next1h', round($next1hKwh, 2));
        $this->SetValue('Next4h', round($next4hKwh, 2));
        $this->SetValue('RemainingToday', round($remainingToday, 2));
        if ($todayTotalKwh !== null) {
            $this->SetValue('TodayTotal', round($todayTotalKwh, 2));
        }
        if ($tomorrowTotalKwh !== null) {
            $this->SetValue('Tomorrow', round($tomorrowTotalKwh, 2));
        }

        if ($currentWeather !== null) {
            $this->SetValue('WeatherCode', $currentWeather);
            $this->SetValue('WeatherText', $this->DecodeWeatherCode($currentWeather));
        }
        if ($currentTemp !== null) {
            $this->SetValue('Temperature', round($currentTemp, 1));
        }
        if ($todayWeatherCode !== null) {
            $this->SetValue('WeatherCodeToday', $todayWeatherCode);
        }

        $this->SetValue('Buffer', json_encode([
            'generated_at' => $nowTs,
            'timezone'     => $data['timezone'],
            'computed_at'  => $data['computed_at'] ?? null,
            'series'       => $series, // nur aktuelle + zukünftige Slots -> Buffer bleibt kompakt
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Lädt und dekodiert die im Buffer gespeicherte Zeitreihe.
     * Gibt null zurück, wenn noch kein erfolgreicher Abruf stattgefunden hat.
     */
    public function LoadSeries(): ?array
    {
        $raw = $this->GetValue('Buffer');
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['series']) || !is_array($data['series'])) {
            return null;
        }
        return $data['series'];
    }
}
