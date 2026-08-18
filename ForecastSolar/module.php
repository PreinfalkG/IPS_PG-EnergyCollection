<?php

declare(strict_types=1);

/**
 * ForecastSolarForecast
 *
 * IP-Symcon Modul für die Forecast.Solar API (https://doc.forecast.solar/doku.php?id=api:estimate),
 * Public Plan (ohne API Key, Rate Limit 12 Requests/Stunde je IP).
 *
 * Fragt bis zu vier PV-Flächen einzeln ab (1 Request je Fläche) und berechnet
 * zusätzlich die Summe aller Flächen je Zeitscheibe.
 *
 * Modul-Präfix: FSOLAR
 */
class ForecastSolarForecast extends IPSModule
{
    private const API_BASE = 'https://api.forecast.solar/';
    private const SITE_COUNT = 4;

    private const SITE_DEFAULTS = [
        1 => ['Name' => 'PV Hausdach',     'Latitude' => 48.325634, 'Longitude' => 14.426263, 'Declination' => 7,  'Azimuth' => 0,   'kWp' => 13.12],
        2 => ['Name' => 'PV Zaun',         'Latitude' => 48.325634, 'Longitude' => 14.426263, 'Declination' => 83, 'Azimuth' => 0,   'kWp' => 3.28],
        3 => ['Name' => 'PV Garage Ost',   'Latitude' => 48.325634, 'Longitude' => 14.426263, 'Declination' => 10, 'Azimuth' => -90, 'kWp' => 1.3],
        4 => ['Name' => 'PV Garage West',  'Latitude' => 48.325634, 'Longitude' => 14.426263, 'Declination' => 10, 'Azimuth' => 90,  'kWp' => 1.3]
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyInteger('MaxRequestsPerHour', 12);
        $this->RegisterPropertyInteger('UpdateIntervalMinutes', 30);

        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            $d = self::SITE_DEFAULTS[$i];
            $this->RegisterPropertyBoolean("Site{$i}Active", true);
            $this->RegisterPropertyString("Site{$i}Name", $d['Name']);
            $this->RegisterPropertyFloat("Site{$i}Latitude", $d['Latitude']);
            $this->RegisterPropertyFloat("Site{$i}Longitude", $d['Longitude']);
            $this->RegisterPropertyInteger("Site{$i}Declination", $d['Declination']);
            $this->RegisterPropertyInteger("Site{$i}Azimuth", $d['Azimuth']);
            $this->RegisterPropertyFloat("Site{$i}kWp", $d['kWp']);
        }

        $this->RegisterAttributeInteger('RequestsThisHour', 0);
        $this->RegisterAttributeString('RequestsResetHour', '');

        $this->RegisterTimer('UpdateTimer', 0, 'FSOLAR_RequestForecast($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfileIfNotExists('FSOLAR.Power', '', '', ' kW', 0, 0, 0, 2, VARIABLETYPE_FLOAT);
        $this->RegisterProfileIfNotExists('FSOLAR.Energy', '', '', ' kWh', 0, 0, 0, 2, VARIABLETYPE_FLOAT);

        // Summenvariablen
        $this->RegisterVariableFloat('CurrentPower', 'Aktuelle PV Prognose', 'FSOLAR.Power', 10);
        $this->RegisterVariableFloat('TodayForecastEnergy', 'Prognose Energie heute', 'FSOLAR.Energy', 20);
        $this->RegisterVariableFloat('TodayRemainingEnergy', 'Prognose Restenergie heute', 'FSOLAR.Energy', 30);
        $this->RegisterVariableFloat('TomorrowForecastEnergy', 'Prognose Energie morgen', 'FSOLAR.Energy', 40);
        $this->RegisterVariableString('ForecastChart', 'Prognose Chart', '~HTMLBox', 45);
        $this->RegisterVariableString('ForecastJSON', 'Prognose Summe (JSON)', '', 50);
        IPS_SetHidden($this->GetIDForIdent('ForecastJSON'), true);

        // je PV-Fläche
        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            $name = $this->ReadPropertyString("Site{$i}Name");
            $base = 100 + $i * 10;
            $this->RegisterVariableFloat("Site{$i}CurrentPower", 'Aktuelle PV Prognose - ' . $name, 'FSOLAR.Power', $base + 1);
            $this->RegisterVariableFloat("Site{$i}TodayForecastEnergy", 'Prognose Energie heute - ' . $name, 'FSOLAR.Energy', $base + 2);
            $this->RegisterVariableFloat("Site{$i}TodayRemainingEnergy", 'Prognose Restenergie heute - ' . $name, 'FSOLAR.Energy', $base + 3);
            $this->RegisterVariableFloat("Site{$i}TomorrowForecastEnergy", 'Prognose Energie morgen - ' . $name, 'FSOLAR.Energy', $base + 4);
            $this->RegisterVariableString("Site{$i}ForecastJSON", 'Prognose ' . $name . ' (JSON)', '', $base + 5);
            IPS_SetHidden($this->GetIDForIdent("Site{$i}ForecastJSON"), true);
        }

        // Statusinfos zum Abruf
        $this->RegisterVariableInteger('LastUpdate', 'Letzter erfolgreicher Abruf', '~UnixTimestamp', 80);
        $this->RegisterVariableInteger('NextUpdate', 'Nächster geplanter Abruf', '~UnixTimestamp', 81);
        $this->RegisterVariableInteger('RequestsThisHourInfo', 'API Requests diese Stunde', '', 82);

        $intervalMinutes = $this->ReadPropertyInteger('UpdateIntervalMinutes');
        $this->SetTimerInterval('UpdateTimer', $intervalMinutes * 60 * 1000);

        $anyActive = false;
        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            if ($this->ReadPropertyBoolean("Site{$i}Active")) {
                $anyActive = true;
                break;
            }
        }
        $this->SetStatus($anyActive ? 102 : 201);
    }

    /**
     * Fragt alle aktiven PV-Flächen ab, berechnet die Summe je Zeitscheibe
     * und schreibt Statusvariablen + JSON-Puffer + Chart.
     * Aufrufbar per Button im Konfigurationsformular oder per Timer.
     */
    public function RequestForecast()
    {
        $activeSites = [];
        for ($i = 1; $i <= self::SITE_COUNT; $i++) {
            if ($this->ReadPropertyBoolean("Site{$i}Active")) {
                $activeSites[] = $i;
            }
        }

        if (empty($activeSites)) {
            $this->SetStatus(201);
            $this->LogMessage('ForecastSolarForecast: Keine aktive PV-Fläche konfiguriert.', KL_WARNING);
            return false;
        }

        if (!$this->CheckAndConsumeRequestBudget(count($activeSites))) {
            $this->SetStatus(203);
            $this->LogMessage('ForecastSolarForecast: Stundenlimit an API Requests erreicht, Abruf übersprungen.', KL_WARNING);
            return false;
        }

        $siteSeries = [];  // $i => [ts => ['period_end'=>ts,'power'=>kW,'energy'=>kWh]]
        $siteDaily = [];   // $i => ['Y-m-d' => kWh]
        $errorOccurred = false;

        foreach ($activeSites as $i) {
            $result = $this->FetchSiteForecast($i, $errorOccurred);
            $siteSeries[$i] = $result['series'];
            $siteDaily[$i] = $result['daily'];
        }

        if ($errorOccurred && count(array_filter($siteSeries)) === 0) {
            $this->SetStatus(202);
            return false;
        }

        // Summenreihe und Summentagesenergie über alle aktiven Flächen
        $sumSeries = $this->MergeAndSumSeries($siteSeries);
        $sumDaily = $this->MergeAndSumDaily($siteDaily);

        $now = time();
        $todayKey = date('Y-m-d', $now);
        $tomorrowKey = date('Y-m-d', strtotime('+1 day', $now));
        $todayEnd = strtotime('tomorrow 00:00', $now);

        // Summenvariablen
        $this->SetValue('CurrentPower', $this->FindCurrentPower($sumSeries, $now));
        $this->SetValue('TodayForecastEnergy', round($sumDaily[$todayKey] ?? 0, 3));
        $this->SetValue('TodayRemainingEnergy', $this->CalculateRemainingEnergy($sumSeries, $now, $todayEnd));
        $this->SetValue('TomorrowForecastEnergy', round($sumDaily[$tomorrowKey] ?? 0, 3));
        $this->SetValue('ForecastJSON', json_encode(array_values($sumSeries)));

        // je PV-Fläche
        $siteNames = [];
        foreach ($activeSites as $i) {
            $siteNames[$i] = $this->ReadPropertyString("Site{$i}Name");
            $series = $siteSeries[$i];
            $daily = $siteDaily[$i];

            $this->SetValue("Site{$i}CurrentPower", $this->FindCurrentPower($series, $now));
            $this->SetValue("Site{$i}TodayForecastEnergy", round($daily[$todayKey] ?? 0, 3));
            $this->SetValue("Site{$i}TodayRemainingEnergy", $this->CalculateRemainingEnergy($series, $now, $todayEnd));
            $this->SetValue("Site{$i}TomorrowForecastEnergy", round($daily[$tomorrowKey] ?? 0, 3));
            $this->SetValue("Site{$i}ForecastJSON", json_encode(array_values($series)));
        }

        // Chart (heute 00:00 bis übermorgen 00:00)
        $this->SetValue('ForecastChart', $this->BuildForecastChartHtml(
            $siteSeries,
            $sumSeries,
            $siteNames,
            strtotime('today 00:00', $now),
            strtotime('+1 day', $todayEnd)
        ));

        $this->SetValue('LastUpdate', $now);
        $intervalMinutes = $this->ReadPropertyInteger('UpdateIntervalMinutes');
        $this->SetValue('NextUpdate', $now + $intervalMinutes * 60);

        $this->SetStatus($errorOccurred ? 202 : 102);
        return true;
    }

    /**
     * Liefert die zuletzt berechnete Summenprognose als PHP-Array,
     * z.B. für Nutzung in eigenen Skripten: FSOLAR_GetForecastArray($id).
     */
    public function GetForecastArray(): array
    {
        $json = $this->GetValue('ForecastJSON');
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }

    // -----------------------------------------------------------------
    // Interne Hilfsfunktionen
    // -----------------------------------------------------------------

    private function FetchSiteForecast(int $siteIndex, bool &$errorOccurred): array
    {
        $lat = $this->ReadPropertyFloat("Site{$siteIndex}Latitude");
        $lon = $this->ReadPropertyFloat("Site{$siteIndex}Longitude");
        $dec = $this->ReadPropertyInteger("Site{$siteIndex}Declination");
        $az = $this->ReadPropertyInteger("Site{$siteIndex}Azimuth");
        $kwp = $this->ReadPropertyFloat("Site{$siteIndex}kWp");
        $apiKey = $this->ReadPropertyString('APIKey');

        $url = self::API_BASE
            . ($apiKey !== '' ? rawurlencode($apiKey) . '/' : '')
            . 'estimate/'
            . $this->FormatNumber($lat, 6) . '/'
            . $this->FormatNumber($lon, 6) . '/'
            . $dec . '/'
            . $az . '/'
            . $this->FormatNumber($kwp, 3);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $empty = ['series' => [], 'daily' => []];

        if ($response === false || $curlError !== '') {
            $this->LogMessage('ForecastSolarForecast: cURL Fehler für Fläche ' . $siteIndex . ': ' . $curlError, KL_ERROR);
            $errorOccurred = true;
            return $empty;
        }

        if ($httpCode === 429) {
            $this->LogMessage('ForecastSolarForecast: Rate Limit (429) für Fläche ' . $siteIndex . ' erreicht - Intervall erhöhen oder Anzahl Flächen reduzieren.', KL_ERROR);
            $errorOccurred = true;
            return $empty;
        }

        if ($httpCode !== 200) {
            $this->LogMessage('ForecastSolarForecast: HTTP ' . $httpCode . ' für Fläche ' . $siteIndex . ': ' . substr((string) $response, 0, 300), KL_ERROR);
            $errorOccurred = true;
            return $empty;
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !isset($data['result']['watt_hours_period']) || !is_array($data['result']['watt_hours_period'])) {
            $this->LogMessage('ForecastSolarForecast: Unerwartetes Antwortformat für Fläche ' . $siteIndex, KL_ERROR);
            $errorOccurred = true;
            return $empty;
        }

        $watts = $data['result']['watts'] ?? [];
        $wattHoursPeriod = $data['result']['watt_hours_period'];
        $wattHoursDay = $data['result']['watt_hours_day'] ?? [];

        $series = [];
        foreach ($wattHoursPeriod as $timeKey => $whPeriod) {
            $timestamp = strtotime($timeKey);
            if ($timestamp === false) {
                continue;
            }
            $watt = $watts[$timeKey] ?? 0;
            $series[$timestamp] = [
                'period_end' => $timestamp,
                'power'      => round(((float) $watt) / 1000, 4),
                'energy'     => round(((float) $whPeriod) / 1000, 4)
            ];
        }
        ksort($series);

        $daily = [];
        foreach ($wattHoursDay as $dateKey => $wh) {
            $daily[$dateKey] = round(((float) $wh) / 1000, 3);
        }

        return ['series' => $series, 'daily' => $daily];
    }

    private function MergeAndSumSeries(array $siteSeries): array
    {
        $timestamps = [];
        foreach ($siteSeries as $series) {
            $timestamps = array_merge($timestamps, array_keys($series));
        }
        $timestamps = array_unique($timestamps);
        sort($timestamps);

        $result = [];
        foreach ($timestamps as $ts) {
            $power = 0.0;
            $energy = 0.0;
            foreach ($siteSeries as $series) {
                $power += $series[$ts]['power'] ?? 0;
                $energy += $series[$ts]['energy'] ?? 0;
            }
            $result[$ts] = [
                'period_end' => $ts,
                'power'      => round($power, 4),
                'energy'     => round($energy, 4)
            ];
        }
        return $result;
    }

    private function MergeAndSumDaily(array $siteDaily): array
    {
        $dates = [];
        foreach ($siteDaily as $daily) {
            $dates = array_merge($dates, array_keys($daily));
        }
        $dates = array_unique($dates);

        $result = [];
        foreach ($dates as $date) {
            $sum = 0.0;
            foreach ($siteDaily as $daily) {
                $sum += $daily[$date] ?? 0;
            }
            $result[$date] = round($sum, 3);
        }
        return $result;
    }

    /**
     * Sucht die Zeitscheibe, die "jetzt" abdeckt (period_end ist das ENDE der Zeitscheibe).
     * Fällt auf die letzte bekannte Leistung zurück, falls "jetzt" außerhalb der Prognose liegt.
     */
    private function FindCurrentPower(array $series, int $now): float
    {
        if (empty($series)) {
            return 0.0;
        }
        foreach ($series as $entry) {
            if ($entry['period_end'] >= $now) {
                return (float) ($entry['power'] ?? 0);
            }
        }
        $last = end($series);
        return (float) ($last['power'] ?? 0);
    }

    /**
     * Summiert die Energie (kWh) aller Zeitscheiben mit period_end im Bereich (rangeStart, rangeEnd].
     * Nutzt die von Forecast.Solar bereits periodengenau gelieferte watt_hours_period.
     */
    private function CalculateRemainingEnergy(array $series, int $rangeStart, int $rangeEnd): float
    {
        $sum = 0.0;
        foreach ($series as $entry) {
            if ($entry['period_end'] > $rangeStart && $entry['period_end'] <= $rangeEnd) {
                $sum += (float) ($entry['energy'] ?? 0);
            }
        }
        return round($sum, 3);
    }

    /**
     * Prüft und verbraucht das Stunden-Request-Budget (Forecast.Solar Public Plan: 12/Stunde/IP).
     */
    private function CheckAndConsumeRequestBudget(int $requestsNeeded): bool
    {
        $currentHour = date('Y-m-d-H');
        $resetHour = $this->ReadAttributeString('RequestsResetHour');
        $usedThisHour = $this->ReadAttributeInteger('RequestsThisHour');

        if ($resetHour !== $currentHour) {
            $usedThisHour = 0;
            $this->WriteAttributeString('RequestsResetHour', $currentHour);
        }

        $maxPerHour = $this->ReadPropertyInteger('MaxRequestsPerHour');
        if ($usedThisHour + $requestsNeeded > $maxPerHour) {
            return false;
        }

        $usedThisHour += $requestsNeeded;
        $this->WriteAttributeInteger('RequestsThisHour', $usedThisHour);
        $this->SetValue('RequestsThisHourInfo', $usedThisHour);
        return true;
    }

    /**
     * Baut den Inhalt der HTMLBox-Variable ForecastChart: ein Chart.js Liniendiagramm
     * mit einer Kurve je PV-Fläche sowie der Summe im Bereich [rangeStart, rangeEnd).
     * Forecast.Solar liefert im Public Plan keine P10/P90-Bandbreite, daher kein Unsicherheitsband.
     */
    private function BuildForecastChartHtml(array $siteSeries, array $sumSeries, array $siteNames, int $rangeStart, int $rangeEnd): string
    {
        $labels = [];
        foreach ($sumSeries as $entry) {
            $ts = $entry['period_end'];
            if ($ts < $rangeStart || $ts >= $rangeEnd) {
                continue;
            }
            $labels[$ts] = date('d.m. H:i', $ts);
        }

        $dataSum = [];
        foreach (array_keys($labels) as $ts) {
            $dataSum[] = round($sumSeries[$ts]['power'] ?? 0, 3);
        }

        $siteData = [];
        foreach ($siteSeries as $i => $series) {
            $vals = [];
            foreach (array_keys($labels) as $ts) {
                $vals[] = round($series[$ts]['power'] ?? 0, 3);
            }
            $siteData[] = [
                'name' => $siteNames[$i] ?? ('Fläche ' . $i),
                'data' => $vals
            ];
        }

        $colors = ['#f2a900', '#2e86de', '#8e44ad', '#e74c3c'];

        $chartId = 'fsolarChart' . $this->InstanceID;
        $payload = json_encode([
            'labels' => array_values($labels),
            'sites'  => $siteData,
            'sum'    => $dataSum,
            'colors' => $colors
        ]);

        return <<<HTML
<div style="width:100%;height:300px;">
    <canvas id="{$chartId}"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    var d = {$payload};
    var datasets = d.sites.map(function(site, idx) {
        return {
            label: site.name,
            data: site.data,
            borderColor: d.colors[idx % d.colors.length],
            backgroundColor: 'transparent',
            fill: false,
            tension: 0.3,
            pointRadius: 0,
            borderWidth: 1.5
        };
    });
    datasets.push({
        label: 'Summe',
        data: d.sum,
        borderColor: '#2ecc71',
        backgroundColor: 'rgba(46,204,113,0.10)',
        fill: true,
        borderWidth: 2,
        tension: 0.3,
        pointRadius: 0
    });
    var ctx = document.getElementById('{$chartId}').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: { labels: d.labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { title: { display: true, text: 'kW' } }
            }
        }
    });
})();
</script>
HTML;
    }

    private function FormatNumber(float $value, int $decimals): string
    {
        $formatted = rtrim(rtrim(sprintf('%.' . $decimals . 'f', $value), '0'), '.');
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function RegisterProfileIfNotExists(string $name, string $icon, string $prefix, string $suffix, float $minValue, float $maxValue, float $stepSize, int $digits, int $variableType)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $variableType);
        }
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileValues($name, $minValue, $maxValue, $stepSize);
        IPS_SetVariableProfileDigits($name, $digits);
    }
}
