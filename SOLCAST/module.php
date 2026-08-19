<?php

declare(strict_types=1);

/**
 * SOLCAST
 *
 * IP-Symcon Modul für die Solcast Rooftop Sites Forecast API
 * (https://toolkit.solcast.com.au/, Free "Hobbyist" Plan).
 *
 * Fragt bis zu zwei PV-Flächen (Resource IDs) einzeln ab und
 * berechnet zusätzlich die Summe beider Flächen je Zeitscheibe (30 Minuten).
 *
 * Modul-Präfix: SOLCAST
 */
class SOLCASTForecast extends IPSModule
{
    private const API_BASE = 'https://api.solcast.com.au/rooftop_sites/';

    private $enableDebug = false;

    public function __construct($InstanceID) {
    	parent::__construct($InstanceID);		// Diese Zeile nicht löschen

        $this->enableDebug = @$this->ReadPropertyBoolean("EnableDebug");
    }

    public function Create()
    {
        parent::Create();

        // --- API Zugang ---
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyInteger('MaxRequestsPerDay', 10);

        // --- PV-Fläche 1 ---
        $this->RegisterPropertyBoolean('Site1Active', true);
        $this->RegisterPropertyString('Site1Name', 'PV-Fläche 1');
        $this->RegisterPropertyString('Site1ResourceID', '');
        $this->RegisterPropertyFloat('Site1ACCapacity', 0);

        // --- PV-Fläche 2 ---
        $this->RegisterPropertyBoolean('Site2Active', true);
        $this->RegisterPropertyString('Site2Name', 'PV-Fläche 2');
        $this->RegisterPropertyString('Site2ResourceID', '');
        $this->RegisterPropertyFloat('Site2ACCapacity', 0);

        // --- Abrufeinstellungen ---
        $this->RegisterPropertyInteger('UpdateIntervalHours', 6);
        $this->RegisterPropertyInteger('ForecastVariant', 0); // 0=P50, 1=P10, 2=P90

        // --- interner Zustand (nicht im Formular sichtbar) ---
        $this->RegisterAttributeInteger('RequestsToday', 0);
        $this->RegisterAttributeString('RequestsResetDay', '');

        $this->RegisterPropertyBoolean('EnableDebug', false);

        $this->RegisterTimer('UpdateTimer', 0, 'SOLCAST_RequestForecast($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfileIfNotExists('SOLCAST.Power', '', '', ' kW', 0, 0, 0, 2, VARIABLETYPE_FLOAT);
        $this->RegisterProfileIfNotExists('SOLCAST.Energy', '', '', ' kWh', 0, 0, 0, 2, VARIABLETYPE_FLOAT);

        $site1Name = $this->ReadPropertyString('Site1Name');
        $site2Name = $this->ReadPropertyString('Site2Name');

        // aktuelle Leistung
        $this->RegisterVariableFloat('CurrentPower', 'Aktuelle PV Prognose (Summe)', 'SOLCAST.Power', 10);
        $this->RegisterVariableFloat('CurrentPowerSite1', 'Aktuelle PV Prognose - ' . $site1Name, 'SOLCAST.Power', 11);
        $this->RegisterVariableFloat('CurrentPowerSite2', 'Aktuelle PV Prognose - ' . $site2Name, 'SOLCAST.Power', 12);

        // Prognose Restenergie heute (ab jetzt bis 24:00)
        $this->RegisterVariableFloat('TodayRemainingEnergy', 'Prognose Restenergie heute (Summe)', 'SOLCAST.Energy', 20);
        $this->RegisterVariableFloat('TodayRemainingEnergySite1', 'Prognose Restenergie heute - ' . $site1Name, 'SOLCAST.Energy', 21);
        $this->RegisterVariableFloat('TodayRemainingEnergySite2', 'Prognose Restenergie heute - ' . $site2Name, 'SOLCAST.Energy', 22);

        // Prognose Energie heute (gesamter Tag, 00:00-24:00)
        $this->RegisterVariableFloat('TodayForecastEnergy', 'Prognose Energie heute (Summe)', 'SOLCAST.Energy', 30);
        $this->RegisterVariableFloat('TodayForecastEnergySite1', 'Prognose Energie heute - ' . $site1Name, 'SOLCAST.Energy', 31);
        $this->RegisterVariableFloat('TodayForecastEnergySite2', 'Prognose Energie heute - ' . $site2Name, 'SOLCAST.Energy', 32);


        // Prognose Energie morgen (gesamter Tag)
        $this->RegisterVariableFloat('TomorrowForecastEnergy', 'Prognose Energie morgen (Summe)', 'SOLCAST.Energy', 40);
        $this->RegisterVariableFloat('TomorrowForecastEnergySite1', 'Prognose Energie morgen - ' . $site1Name, 'SOLCAST.Energy', 41);
        $this->RegisterVariableFloat('TomorrowForecastEnergySite2', 'Prognose Energie morgen - ' . $site2Name, 'SOLCAST.Energy', 42);

        // Rohdaten / Zeitreihen als JSON-Puffer für eigene Visualisierungen (z.B. HTML-Box, Skripte)
        $this->RegisterVariableString('ForecastJSON', 'Prognose Summe (JSON)', '', 50);
        $this->RegisterVariableString('ForecastJsonSite1', 'Prognose ' . $site1Name . ' (JSON)', '', 60);
        $this->RegisterVariableString('ForecastJsonSite2', 'Prognose ' . $site2Name . ' (JSON)', '', 70);
        IPS_SetHidden($this->GetIDForIdent('ForecastJSON'), true);
        IPS_SetHidden($this->GetIDForIdent('ForecastJsonSite1'), true);
        IPS_SetHidden($this->GetIDForIdent('ForecastJsonSite2'), true);

        // Statusinfos zum Abruf
        $this->RegisterVariableInteger('LastUpdate', 'Letzter erfolgreicher Abruf', '~UnixTimestamp', 80);
        $this->RegisterVariableInteger('NextUpdate', 'Nächster geplanter Abruf', '~UnixTimestamp', 81);
        $this->RegisterVariableInteger('RequestsTodayInfo', 'API Requests heute', '', 82);

        $this->RegisterVariableString('ForecastChart', 'SOLCAST Prognose Chart', '~HTMLBox', 100);

        // Timer entsprechend Intervall setzen
        $intervalHours = $this->ReadPropertyInteger('UpdateIntervalHours');
        if($intervalHours > 0) {
            $this->SetTimerInterval('UpdateTimer', $intervalHours * 3600 * 1000);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("UpdateTimer set to %d Hours", $intervalHours), 0); }
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetValue('NextUpdate', 0);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, "UpdateTimer DEAKTIVIERT", 0); }
        }

        // Instanzstatus prüfen
        $apiKey = $this->ReadPropertyString('APIKey');
        $site1 = $this->ReadPropertyBoolean('Site1Active') && $this->ReadPropertyString('Site1ResourceID') !== '';
        $site2 = $this->ReadPropertyBoolean('Site2Active') && $this->ReadPropertyString('Site2ResourceID') !== '';

        if ($apiKey === '' || (!$site1 && !$site2)) {
            $this->SetStatus(201);
        } else {
            $this->SetStatus(102);
        }
    }

    /**
     * Fragt beide aktiven PV-Flächen ab, berechnet die Summe je Zeitscheibe
     * und schreibt Statusvariablen + JSON-Puffer.
     * Aufrufbar per Button im Konfigurationsformular oder per Timer.
     */
    public function RequestForecast()
    {
        $apiKey = $this->ReadPropertyString('APIKey');
        if ($apiKey === '') {
            $this->SetStatus(201);
            $logTxt = "SOLCAST: Kein API Key konfiguriert";
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }
            $this->LogMessage($logTxt, KL_WARNING);
            return false;
        }

        if (!$this->CheckAndConsumeRequestBudget()) {
            $this->SetStatus(203);
            $logTxt = "SOLCAST: Tageslimit an API Requests erreicht, Abruf übersprungen";
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }            
            $this->LogMessage($logTxt, KL_WARNING);
            return false;
        }

        $site1Active = $this->ReadPropertyBoolean('Site1Active') && $this->ReadPropertyString('Site1ResourceID') !== '';
        $site2Active = $this->ReadPropertyBoolean('Site2Active') && $this->ReadPropertyString('Site2ResourceID') !== '';

        if (!$site1Active && !$site2Active) {
            $this->SetStatus(201);
            $logTxt = "SOLCAST: Keine aktive PV-Fläche konfiguriert";
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }                  
            $this->LogMessage($logTxt, KL_WARNING);
            return false;
        }

        $site1Series = [];
        $site2Series = [];
        $errorOccurred = false;

        if ($site1Active) {
            $site1Series = $this->FetchSiteForecast($this->ReadPropertyString('Site1ResourceID'), $apiKey, $errorOccurred);
        }
        if ($site2Active) {
            $site2Series = $this->FetchSiteForecast($this->ReadPropertyString('Site2ResourceID'), $apiKey, $errorOccurred);
        }

        if ($errorOccurred && empty($site1Series) && empty($site2Series)) {
            $logTxt = sprintf("ErrorOccurred or SiteSeries empty [Site1 Cnt: %d | Site2 Cnt: %d]", count($site1Series), count($site2Series));
            $this->LogMessage($logTxt, KL_ERROR);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }
            $this->SetStatus(202);
            return false;
        }

        if($this->enableDebug) { $this->SendDebug(__METHOD__, sprintf("Site1Series Cnt: %d | Site1Series Cnt: %d", count(site1Series), count(site2Series)), 0); }

        // Beide Zeitreihen je Zeitstempel (period_end, Unix UTC) zur Summenreihe zusammenführen
        $sumSeries = $this->MergeAndSum($site1Series, $site2Series);

        // JSON-Puffer schreiben (alle drei Varianten P10/P50/P90 enthalten -> für eigene Auswertung/Charts)
        $this->SetValue('ForecastJsonSite1', json_encode(array_values($site1Series)));
        $this->SetValue('ForecastJsonSite2', json_encode(array_values($site2Series)));
        $this->SetValue('ForecastJSON', json_encode(array_values($sumSeries)));

        // primären Wert gemäß Konfiguration (P50/P10/P90) wählen
        $variant = $this->ReadPropertyInteger('ForecastVariant');
        $variantKey = ['pv_estimate', 'pv_estimate10', 'pv_estimate90'][$variant] ?? 'pv_estimate';

        $now = time();
        $this->SetValue('CurrentPower', $this->FindCurrentValue($sumSeries, $variantKey, $now));
        $this->SetValue('CurrentPowerSite1', $this->FindCurrentValue($site1Series, $variantKey, $now));
        $this->SetValue('CurrentPowerSite2', $this->FindCurrentValue($site2Series, $variantKey, $now));

        // Tagesgrenzen (lokale Zeitzone des Symcon-Servers)
        $todayStart = strtotime('today 00:00', $now);
        $todayEnd = strtotime('tomorrow 00:00', $now);
        $tomorrowEnd = strtotime('+1 day', $todayEnd);

        // Prognose Energie heute (gesamter Tag)
        $this->SetValue('TodayForecastEnergy', $this->CalculateEnergyInRange($sumSeries, $variantKey, $todayStart, $todayEnd));
        $this->SetValue('TodayForecastEnergySite1', $this->CalculateEnergyInRange($site1Series, $variantKey, $todayStart, $todayEnd));
        $this->SetValue('TodayForecastEnergySite2', $this->CalculateEnergyInRange($site2Series, $variantKey, $todayStart, $todayEnd));

        // Prognose Restenergie heute (ab jetzt)
        $this->SetValue('TodayRemainingEnergy', $this->CalculateEnergyInRange($sumSeries, $variantKey, $now, $todayEnd));
        $this->SetValue('TodayRemainingEnergySite1', $this->CalculateEnergyInRange($site1Series, $variantKey, $now, $todayEnd));
        $this->SetValue('TodayRemainingEnergySite2', $this->CalculateEnergyInRange($site2Series, $variantKey, $now, $todayEnd));

        // Prognose Energie morgen (gesamter Tag) - nur soweit vom Hobbyist-Plan als Horizont geliefert
        $this->SetValue('TomorrowForecastEnergy', $this->CalculateEnergyInRange($sumSeries, $variantKey, $todayEnd, $tomorrowEnd));
        $this->SetValue('TomorrowForecastEnergySite1', $this->CalculateEnergyInRange($site1Series, $variantKey, $todayEnd, $tomorrowEnd));
        $this->SetValue('TomorrowForecastEnergySite2', $this->CalculateEnergyInRange($site2Series, $variantKey, $todayEnd, $tomorrowEnd));

        // Chart (heute 00:00 bis übermorgen 00:00) als HTMLBox aktualisieren
        $this->SetValue('ForecastChart', $this->BuildForecastChartHtml(
            $site1Series,
            $site2Series,
            $sumSeries,
            $variantKey,
            $todayStart,
            $tomorrowEnd,
            $site1Name,
            $site2Name
        ));

        $this->SetValue('LastUpdate', $now);
        $intervalHours = $this->ReadPropertyInteger('UpdateIntervalHours');
        $this->SetValue('NextUpdate', $now + $intervalHours * 3600);

        if ($errorOccurred) {
            // Eine der beiden Flächen konnte nicht geladen werden, die andere aber schon
            $this->SetStatus(202);
        } else {
            $this->SetStatus(102);
        }

        return true;
    }

    /**
     * Liefert die zuletzt berechnete Summenprognose als PHP-Array,
     * z.B. für Nutzung in eigenen Skripten: SOLCAST_GetForecastArray($id).
     */
    public function GetForecastArray(): array
    {
        $json = $this->GetValue('ForecastJSON');
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }
    public function GetForecastArraySite1(): array
    {
        $json = $this->GetValue('ForecastJsonSite1');
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }  
    public function GetForecastArraySite2(): array
    {
        $json = $this->GetValue('ForecastJsonSite2');
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }      


    /**
     * Baut den Inhalt der HTMLBox-Variable ForecastChart: ein Chart.js Liniendiagramm
     * mit Fläche1, Fläche2 und Summe im Bereich [rangeStart, rangeEnd).
     */
    public function BuildForecastChartHtml(array $site1Series, array $site2Series, array $sumSeries, string $variantKey, int $rangeStart, int $rangeEnd, string $site1Name, string $site2Name): string
    {
        $labels = [];
        $data1 = [];
        $data2 = [];
        $dataSum = [];
        $dataSum10 = [];
        $dataSum90 = [];

        // Zeitachse aus der Summenreihe ableiten (enthält Vereinigung aller Zeitstempel)
        foreach ($sumSeries as $entry) {
            $ts = $entry['period_end'];
            if ($ts < $rangeStart || $ts >= $rangeEnd) {
                continue;
            }
            $labels[] = date('d.m. H:i', $ts);
            $data1[] = round($site1Series[$ts][$variantKey] ?? 0, 3);
            $data2[] = round($site2Series[$ts][$variantKey] ?? 0, 3);
            $dataSum[] = round($entry[$variantKey] ?? 0, 3);
            // P10/P90 immer von der Summenreihe, unabhängig von der gewählten Primärvariante
            $dataSum10[] = round($entry['pv_estimate10'] ?? 0, 3);
            $dataSum90[] = round($entry['pv_estimate90'] ?? 0, 3);
        }

        $chartId = 'solcastChart' . $this->InstanceID;
        $payload = json_encode([
            'labels'    => $labels,
            'site1'     => $data1,
            'site2'     => $data2,
            'sum'       => $dataSum,
            'sum10'     => $dataSum10,
            'sum90'     => $dataSum90,
            'site1Name' => $site1Name,
            'site2Name' => $site2Name
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
                    // obere Bandgrenze (P90) - unsichtbar, dient nur als Füllreferenz für die nächste Datenreihe
                    label: 'P90',
                    data: d.sum90,
                    borderWidth: 0,
                    pointRadius: 0,
                    fill: false
                },
                {
                    // untere Bandgrenze (P10) - füllt die Fläche bis zur vorherigen Datenreihe (P90) -> Unsicherheitsband
                    label: 'Unsicherheitsband (P10-P90)',
                    data: d.sum10,
                    borderWidth: 0,
                    pointRadius: 0,
                    backgroundColor: 'rgba(46,204,113,0.18)',
                    fill: '-1'
                },
                {
                    label: d.site1Name,
                    data: d.site1,
                    borderColor: '#f2a900',
                    backgroundColor: 'rgba(242,169,0,0.15)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0
                },
                {
                    label: d.site2Name,
                    data: d.site2,
                    borderColor: '#2e86de',
                    backgroundColor: 'rgba(46,134,222,0.15)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0
                },
                {
                    label: 'Summe',
                    data: d.sum,
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46,204,113,0.05)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.3,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: {
                        filter: function(item) { return item.text !== 'P90'; }
                    }
                }
            },
            scales: {
                y: { title: { display: true, text: 'kW' } }
            }
        }
    });
})();
</script>
HTML;
    }


    // -----------------------------------------------------------------
    // Interne Hilfsfunktionen
    // -----------------------------------------------------------------

    private function FetchSiteForecast(string $resourceId, string $apiKey, bool &$errorOccurred): array
    {
        $url = self::API_BASE . rawurlencode($resourceId) . '/forecasts?format=json';

        if($this->enableDebug) { $this->SendDebug(__METHOD__, $url, 0); }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $logTxt = 'SOLCAST: cURL Fehler für Resource ' . $resourceId . ': ' . $curlError;
            $this->LogMessage($logTxt, KL_ERROR);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }
            $errorOccurred = true;
            return [];
        }

        if ($httpCode !== 200) {
            $logTxt = 'SOLCAST: HTTP ' . $httpCode . ' für Resource ' . $resourceId . ': ' . substr((string) $response, 0, 300);
            $this->LogMessage($logTxt, KL_ERROR);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }
            $errorOccurred = true;
            return [];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || !isset($data['forecasts']) || !is_array($data['forecasts'])) {
            $logTxt = 'SOLCAST: Unerwartetes Antwortformat für Resource ' . $resourceId;
            $this->LogMessage($logTxt, KL_ERROR);
            if($this->enableDebug) { $this->SendDebug(__METHOD__, $logTxt, 0); }
            $errorOccurred = true;
            return [];
        }

        $series = [];
        foreach ($data['forecasts'] as $entry) {
            if (!isset($entry['period_end'])) {
                continue;
            }
            $timestamp = strtotime($entry['period_end']);
            if ($timestamp === false) {
                continue;
            }
            $series[$timestamp] = [
                'period_end'    => $timestamp,
                'pv_estimate'   => (float) ($entry['pv_estimate'] ?? 0),
                'pv_estimate10' => (float) ($entry['pv_estimate10'] ?? ($entry['pv_estimate'] ?? 0)),
                'pv_estimate90' => (float) ($entry['pv_estimate90'] ?? ($entry['pv_estimate'] ?? 0))
            ];
        }
        ksort($series);
        return $series;
    }

    private function MergeAndSum(array $site1Series, array $site2Series): array
    {
        $timestamps = array_unique(array_merge(array_keys($site1Series), array_keys($site2Series)));
        sort($timestamps);

        $result = [];
        foreach ($timestamps as $ts) {
            $s1 = $site1Series[$ts] ?? ['pv_estimate' => 0, 'pv_estimate10' => 0, 'pv_estimate90' => 0];
            $s2 = $site2Series[$ts] ?? ['pv_estimate' => 0, 'pv_estimate10' => 0, 'pv_estimate90' => 0];

            $result[$ts] = [
                'period_end'    => $ts,
                'pv_estimate'   => round($s1['pv_estimate'] + $s2['pv_estimate'], 4),
                'pv_estimate10' => round($s1['pv_estimate10'] + $s2['pv_estimate10'], 4),
                'pv_estimate90' => round($s1['pv_estimate90'] + $s2['pv_estimate90'], 4)
            ];
        }
        return $result;
    }

    /**
     * Sucht die Zeitscheibe, die "jetzt" abdeckt (period_end ist das ENDE einer 30-Minuten-Scheibe).
     * Fällt auf die nächstgelegene zukünftige Scheibe zurück, falls "jetzt" außerhalb der Prognose liegt.
     */
    public function FindCurrentValue(array $series, string $variantKey, int $now): float
    {
        if (empty($series)) {
            return 0.0;
        }
        foreach ($series as $entry) {
            if ($entry['period_end'] >= $now) {
                return (float) ($entry[$variantKey] ?? 0);
            }
        }
        // alle Zeitscheiben liegen in der Vergangenheit -> letzten bekannten Wert liefern
        $last = end($series);
        return (float) ($last[$variantKey] ?? 0);
    }

    /**
     * Summiert die Energie (kWh) aller Zeitscheiben, deren period_end im Bereich
     * (rangeStart, rangeEnd] liegt. Basis: pv_estimate (kW) * 0,5h je 30-Minuten-Scheibe.
     */
    public function CalculateEnergyInRange(array $series, string $variantKey, int $rangeStart, int $rangeEnd): float
    {
        $sum = 0.0;
        foreach ($series as $entry) {
            if ($entry['period_end'] > $rangeStart && $entry['period_end'] <= $rangeEnd) {
                $sum += ((float) ($entry[$variantKey] ?? 0)) * 0.5;
            }
        }
        return round($sum, 3);
    }

    /**
     * Prüft und verbraucht das tägliche API-Request-Budget (Hobbyist Free Plan).
     * Ein Abrufzyklus verbraucht 2 Requests (eine je aktive PV-Fläche).
     */
    private function CheckAndConsumeRequestBudget(): bool
    {
        $today = date('Y-m-d');
        $resetDay = $this->ReadAttributeString('RequestsResetDay');
        $usedToday = $this->ReadAttributeInteger('RequestsToday');

        if ($resetDay !== $today) {
            $usedToday = 0;
            $this->WriteAttributeString('RequestsResetDay', $today);
        }

        $siteCount = 0;
        if ($this->ReadPropertyBoolean('Site1Active') && $this->ReadPropertyString('Site1ResourceID') !== '') {
            $siteCount++;
        }
        if ($this->ReadPropertyBoolean('Site2Active') && $this->ReadPropertyString('Site2ResourceID') !== '') {
            $siteCount++;
        }

        $maxPerDay = $this->ReadPropertyInteger('MaxRequestsPerDay');
        if ($usedToday + $siteCount > $maxPerDay) {
            return false;
        }

        $usedToday += $siteCount;
        $this->WriteAttributeInteger('RequestsToday', $usedToday);
        $this->SetValue('RequestsTodayInfo', $usedToday);
        return true;
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
