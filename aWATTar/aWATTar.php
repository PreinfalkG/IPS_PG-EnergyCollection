<?php

declare(strict_types=1);

trait AWATTAR_FUNCTIONS {

    private function RequestMarketdata() {
        // build params
        $params = [
            'start' => strtotime(date('d.m.Y 00:00:00')) * 1000,
            'end' => (time() + 3600*24) * 1000
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
        //$apiURL = 'https://api.awattar.at/v1/marketdata';
        $ch = curl_init($apiURL);

  
        $httpResponse = false;
        $startTime =  microtime(true);
        try {
            curl_setopt_array($ch, $curlOptions);
            $httpResponse = curl_exec($ch);
            if ($httpResponse === false) {
                $httpResponse = false;
                $errorMsg = sprintf('{ "ERROR" : "curl_exec > %s [%s] {%s}" }', curl_error($ch), curl_errno($ch), $apiURL);  
                $this->HandleError(__FUNCTION__, $errorMsg);
            } 

            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatusCode >= 400) {
                $httpResponse = false;
                $errorMsg = sprintf('{ "ERROR" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                $this->HandleError(__FUNCTION__, $errorMsg);
            } else if($httpStatusCode != 200) {
                $msg = sprintf('{ "WARN" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                if ($this->logLevel >= LogLevel::WARN) {
                    $this->AddLog(__FUNCTION__, $msg);
                }                
            }

            if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $this->AddLog(__FUNCTION__, sprintf("OK > httpStatusCode '%s' < %s ", $httpStatusCode, $apiURL));
            }

    	} catch(Exception $e) {
            $httpResponse = false;
            $errorMsg = sprintf('{ "ERROR" : "Exception > %s [%s] {%s}" }', $e->getMessage(), $e->getCode(), $apiURL);
            $this->HandleError(__FUNCTION__, $errorMsg);
		} finally {
            curl_close($ch);
            if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $duration = $this->CalcDuration_ms($startTime);
                $this->AddLog(__FUNCTION__, sprintf("CURL Connection closed [Duration: %.2f ms]", $duration));
            }            
        }

        if($httpResponse !== false) {
            $jsonResponse = json_decode($httpResponse, true);

            if (json_last_error() == JSON_ERROR_NONE) {
                $this->SetStatus(102);
                return isset($jsonResponse['data']) ? $jsonResponse['data'] : false;
            } else {
                $this->SetStatus(200);
                return false;
            }
        } else {
            $this->SetStatus(200);
            return false;
        }
        
    }


    private function CreateMarketdataExtended(array $jsonMarketdata) {

        $epexSpotPriceArr = [];

        $marketdataExt = [
            'CurrentPrice' => 0,
            'AveragePrice' => 0,
            'LowestPrice' => 999999999999,
            'HighestPrice' => 0,
            'Entries' => 0,
            'FirstStartHour' => mktime(0, 0, 0, 1, 1, 2038),
            'LastStartHour' => 0,     
            'Timestamp' => time(),
            'MarketdataArr' => []
        ];


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


            $marketdataArrElem = [];
            $marketdataArrElem['key'] =  'EPEXSpot_' . $hour_start . '_' . $hour_end . 'h';
            $marketdataArrElem['EPEXSpot'] = $epexSpotPrice;
            $marketdataArrElem['start'] = $start;
            $marketdataArrElem['end'] = $end;
            $marketdataArrElem['startDateTime'] = $this->UnixTimestamp2String($start);
            $marketdataExt['MarketdataArr'][idate('G', $start)] =  $marketdataArrElem;


            if ($hour_start == date('G')) {
                $marketdataExt['CurrentPrice'] = $epexSpotPrice;
            } 

            if ($epexSpotPrice < $marketdataExt['LowestPrice']) {
                $marketdataExt['LowestPrice'] = $epexSpotPrice;
            }   
            
            if ($epexSpotPrice > $marketdataExt['HighestPrice']) {
                $marketdataExt['HighestPrice'] = $epexSpotPrice;
            }            

            if ($start < $marketdataExt['FirstStartHour']) {
                $marketdataExt['FirstStartHour'] = $start;
            }  

            if ($start > $marketdataExt['LastStartHour']) {
                $marketdataExt['LastStartHour'] = $start;
            }              

        }

        $cnt = count($epexSpotPriceArr);
        $marketdataExt['Entries'] = $cnt;
        $marketdataExt['AveragePrice'] = (float)round(array_sum($epexSpotPriceArr) / $cnt, 4);

        $this->marketdataExtended = $marketdataExt;

        return true;

        $marketdataArrTemp =  $this->marketdataExtended['MarketdataArr'];
        $col = array_column($marketdataArrTemp, "EPEXSpot" );
        array_multisort( $col, SORT_ASC, $marketdataArrTemp);
        //var_dump($marketdataArrTemp);

    }

}
