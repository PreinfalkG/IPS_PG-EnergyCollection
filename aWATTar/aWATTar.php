<?php

declare(strict_types=1);

trait AWATTAR_FUNCTIONS {

    private function RequestMarketdata() {
        // build params
        $params = [
            'start' => strtotime(date('d.m.Y 00:00:00')) * 1000
        ];

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 60,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/yaml',
                'User-Agent: IP_Symcon'
            ]
        ];

        $apiURL = 'https://api.awattar.at/v1/marketdata?' . http_build_query($params);

        if ($this->logLevel >= LogLevel::DEBUG) {
            $this->AddLog(__FUNCTION__, $apiURL);
        }

        $ch = curl_init($apiURL);
        curl_setopt_array($ch, $curlOptions);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);


        if ($response && json_last_error() == JSON_ERROR_NONE) {
            $this->SetStatus(102);

            $next_timer = strtotime(date('Y-m-d H:00:10', strtotime('+1 hour')));
            //$this->SetTimerInterval('UpdateData', ($next_timer - time()) * 1000); // every hour
            return isset($response['data']) ? $response['data'] : [];
        } else {
            $this->SetStatus(200);
            //$this->SetTimerInterval('UpdateData', 0); // disable timer
            return false;
        }
    }


    private function ProcessMarketdata(array $jsonMarketdata) {

        $epexSpotPriceArr = [];

        $this->marketdataExtended = [
            '_Timestamp' => time(),
            '_LowestPrice' => 999999999999,
            '_HighestPrice' => 0,
            '_AveragePrice' => 0,
            '_CurrentPrice' => 0,
            '_Entries' => 0,
            'MarketdataArr' => []
        ];


        $marketdataArr = [];

        foreach ($jsonMarketdata as $item) {

            
            $start = $item['start_timestamp'] / 1000;
            $end = $item['end_timestamp'] / 1000;

            $epexSpotPrice = floatval($item['marketprice'] / 10);
            $epexSpotPriceArr[] = $epexSpotPrice;

            $hour_start = date('G', $start);
            $hour_end = date('G',  $end);
            if ($hour_end == 0) {
                $hour_end = 24;
            }

            $key = 'EPEXSpot_' . $hour_start . '_' . $hour_end . 'h';

            $marketdataArr[$key]['EPEXSpot'] = $epexSpotPrice;
            $marketdataArr[$key]['start'] = $start;
            $marketdataArr[$key]['end'] = $end;


            if ($hour_start == date('G')) {
                $this->marketdataExtended['_CurrentPrice'] = $epexSpotPrice;
            } 

            if ($epexSpotPrice < $this->marketdataExtended['_LowestPrice']) {
                $this->marketdataExtended['_LowestPrice'] = $epexSpotPrice;
            }   
            
            if ($epexSpotPrice > $this->marketdataExtended['_HighestPrice']) {
                $this->marketdataExtended['_HighestPrice'] = $epexSpotPrice;
            }            

        }

        $cnt = count($epexSpotPriceArr);
        $this->marketdataExtended['_Entries'] = $cnt;
        $this->marketdataExtended['_AveragePrice'] = (float)round(array_sum($epexSpotPriceArr) / $cnt, 4);

        $this->marketdataExtended['MarketdataArr'] =  $marketdataArr;


    }

}
