<?php

declare(strict_types=1);

trait AWATTAR_FUNCTIONS {

    protected function RequestMarketdata() {
        // build params
        $params = [
            'start' => strtotime(date('d.m.Y 00:00:00')) * 1000,
            'end' => (time() + 3600 * 24) * 1000
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
            } else if ($httpStatusCode != 200) {
                $msg = sprintf('{ "WARN" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                if ($this->logLevel >= LogLevel::WARN) {
                    $this->AddLog(__FUNCTION__, $msg);
                }
            }

            if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $this->AddLog(__FUNCTION__, sprintf("OK > httpStatusCode '%s' < %s ", $httpStatusCode, $apiURL));
            }
        } catch (Exception $e) {
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

        if ($httpResponse !== false) {
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


    protected function CreateMarketdataExtended(array $jsonMarketdata) {

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
            $marketdataArrElem['EPEXSpot'] = round($epexSpotPrice, 3);
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
    }


	public function UpdateMarketdata(string $caller) {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("UpdateMarketdata [Trigger > %s] ...", $caller));
		}

		$awattarMarketData = $this->RequestMarketdata();
		if ($awattarMarketData !== false) {
			$result = $this->CreateMarketdataExtended($awattarMarketData);
			if ($result !== false) {

				$this->__set("Buff_MarketdataExtended", $this->marketdataExtended);
				$this->__set("Buff_MarketdataExtendedTS", $this->UnixTimestamp2String(time()));
				$this->__set("Buff_MarketdataExtendedCnt", intval($this->__get("Buff_MarketdataExtendedCnt")) + 1);
				//IPS_LogMessage("MarketdataExtended", print_r($this->marketdataExtended, true));

				if ($this->logLevel >= LogLevel::DEBUG) {
					$this->AddLog(__FUNCTION__, sprintf("MarketdataExtended created with %d Entries", $this->marketdataExtended["Entries"]));
				}
				$this->Increase_CounterVariable($this->GetIDForIdent("updateCntOk"));
			} else {
				$this->Increase_CounterVariable($this->GetIDForIdent("updateCntNotOk"));
				$this->HandleError(__FUNCTION__, "ERROR creating 'MarketdataExtended'");
			}
		} else {
			$this->HandleError(__FUNCTION__, "no aWATTar Marketdata avialable");
		}

		$this->SaveVariables();
	}


    protected function GetHoursBelowThreshold(float $threshold, int $duration, bool $continuousHours) {
        
        $hoursBelowThresholdArr = [];

        $durationHours = idate('G', $duration);

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("duration %s | %s hours", print_r($duration, true), $durationHours));
        }

        $marketdataArrFromNow = $this->GetMarketdataArr(true);
        if($marketdataArrFromNow !== false) {

            $itemCnt = 0;
      
            $col = array_column($marketdataArrFromNow, "EPEXSpot");
            array_multisort($col, SORT_ASC, $marketdataArrFromNow);

            foreach ($marketdataArrFromNow as $item) {
                $start = $item["start"];
                $end = $item["end"];
                $EPEXSpot = $item["EPEXSpot"];
               
                if ($EPEXSpot <= $threshold) {
                    $itemCnt++;
                    $hoursBelowThresholdArr[] = $item;
                }
                if($itemCnt >= $durationHours) { break; }
            }      
            if(count($hoursBelowThresholdArr) > 1) {
                $col = array_column($hoursBelowThresholdArr, "start");
                array_multisort($col, SORT_ASC, $hoursBelowThresholdArr);
            }            
        } else {
            $hoursBelowThresholdArr = false;
        }
        return $hoursBelowThresholdArr;
    }


    protected function GetLowestContinuousHours(float $threshold, int $duration) {

        $continuousHoursArr = [];

        $priceArrTEMP = [];

        $durationHours = idate('G', $duration);

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("duration %s | %s hours", print_r($duration, true), $durationHours));
        }

        $marketdataArrFromNow = $this->GetMarketdataArr(true);
        $entries = count($marketdataArrFromNow);
        //$this->SendDebug(__FUNCTION__, sprintf("marketdataArrFromNow Entries: %s", $entries), 0);
        if($marketdataArrFromNow !== false) {

            $index1 = 0;        
            foreach($marketdataArrFromNow as $item) {
                $fullPeriod = true;
                $this->SendDebug(__FUNCTION__, sprintf("foreach Item Key '%s'",  $item["key"]), 0);
                $price = 0;
                $key = $item["key"];
                $start = date('H:i', $item["start"]);
                for($i=$index1; $i<=$index1+$durationHours-1; $i++) {
                    if($i >= $entries) {
                        //$this->SendDebug(__FUNCTION__, sprintf("break at %s (%s)", $i, $entries), 0);
                        $fullPeriod = false;
                        break;
                    } else {
                        //$this->SendDebug(__FUNCTION__, sprintf("for '%s' {%s}",  $marketdataArrFromNow[$i]["key"], $price), 0);
                        $price += $marketdataArrFromNow[$i]["EPEXSpot"];
                    }
                }
                if($fullPeriod) {
                    $priceArrTEMP[] = ["key" => $key, "price" => round($price, 3)];
                }
                $index1++;
            }   



        } else {
            $continuousHoursArr = false;
        }

        //asort($priceArrTEMP, SORT_NUMERIC);
        $col = array_column($priceArrTEMP, "price");
        array_multisort($col, SORT_ASC, SORT_NUMERIC, $priceArrTEMP);

        $continuousHoursArr = $priceArrTEMP;


        return $continuousHoursArr;
    }

    protected function GetMarketdataArr(bool $futureHoursOnly) {

        $marketdataArr = [];
        $dateTimeNow = time();

        $marketdataExtended = $this->__get("Buff_MarketdataExtended");
        if (is_array($marketdataExtended)) {
            if (array_key_exists("MarketdataArr", $marketdataExtended)) {
                if (is_array($marketdataArr)) {
                    if($futureHoursOnly) {
                        foreach ($marketdataExtended["MarketdataArr"] as $item) {
                            $start = $item["start"];
                            if ($start > $dateTimeNow) {
                                $marketdataArr[] = $item;
                            }
                        }
                    } else {
                        $marketdataArr = $marketdataExtended["MarketdataArr"];
                    }
                } else {
                    if ($this->logLevel >= LogLevel::WARN) {
                        $this->AddLog(__FUNCTION__, sprintf("'marketdataArr' is no array {%s}", print_r($marketdataArr, true)));
                    }                    
                    $marketdataArr = false;
                }
            } else {
                if ($this->logLevel >= LogLevel::WARN) {
                    $this->AddLog(__FUNCTION__, "Buff_MarketdataExtended does not contain Array Key 'MarketdataArr'");
                }                
                $marketdataArr = false;
            }
        } else {
            if ($this->logLevel >= LogLevel::WARN) {
                $this->AddLog(__FUNCTION__, sprintf("Buff_MarketdataExtended is no array {%s}", print_r($marketdataExtended, true)));
            }
            $marketdataArr = false;
        }
        return $marketdataArr;
    }


}
