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


    protected function SetHoursBelowThreshold(int $priceSwitchRoodId) {
        
        $hoursBelowThresholdArr = [];
        $switchOnInfo = "OnHours: ";

        $varId_mode = IPS_GetObjectIDByIdent("_mode", $priceSwitchRoodId);
        $varId_threshold = IPS_GetObjectIDByIdent("_threshold", $priceSwitchRoodId);           
        $varId_timeWindowStart = IPS_GetObjectIDByIdent("_timeWindowStart", $priceSwitchRoodId);
        $varId_timeWindowEnd = IPS_GetObjectIDByIdent("_timeWindowEnd", $priceSwitchRoodId);
        $varId_duration = IPS_GetObjectIDByIdent("_duration", $priceSwitchRoodId);            
        $varId_continuousHours = IPS_GetObjectIDByIdent("_continuousHours", $priceSwitchRoodId);	
        $varId_switch = IPS_GetObjectIDByIdent("_switch", $priceSwitchRoodId);
        $varId_wochenplan = IPS_GetObjectIDByIdent("_wochenplan", $varId_switch);            
        $varId_data = IPS_GetObjectIDByIdent("_data", $priceSwitchRoodId);

        $priceMode = GetValueInteger($varId_mode);
        $threshold = GetValueFloat($varId_threshold);
        $timeWindowStart = GetValueInteger($varId_timeWindowStart);
        $timeWindowEnd = GetValueInteger($varId_timeWindowEnd);
        $duration = GetValueInteger($varId_duration);
        $continuousHours = GetValueBoolean($varId_continuousHours);
        $switch = GetValueBoolean($varId_switch);

        $durationHours = idate('G', $duration);

        $startTS = strtotime('midnight') + $timeWindowStart + 3600;
        $endTS = strtotime(date("Y-m-d 0:0:0")) + $timeWindowEnd + 3600;
        if($timeWindowEnd <= $timeWindowStart) {
            $endTS = $endTS + 3600*24;
        }

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("StartWindow: %s {%s} | EndWindow: %s {%s} | duration %s | %s hours", $this->UnixTimestamp2String($startTS), $timeWindowStart, $this->UnixTimestamp2String($endTS), $timeWindowEnd, print_r($duration, true), $durationHours));
        }

        $marketdataArr = $this->GetMarketdataArr($startTS, $endTS, true);
        if($marketdataArr !== false) {

            $itemCnt = 0;
            $col = array_column($marketdataArr, "EPEXSpot");
            array_multisort($col, SORT_ASC, $marketdataArr);

            foreach ($marketdataArr as $item) {
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

            IPS_SetEventActive($varId_wochenplan, false);
            $this->ResetWochenplan($varId_wochenplan);
            IPS_SetEventScheduleGroup($varId_wochenplan, 0, 127);

            $SchaltpunktID = 0; 
            $averagePrice= 0;
            $count = count($hoursBelowThresholdArr);
            for($i=0; $i < $count; $i++) {
                $start = $hoursBelowThresholdArr[$i]["start"];
                $end = $hoursBelowThresholdArr[$i]["end"];
                $EPEXSpot = $hoursBelowThresholdArr[$i]["EPEXSpot"];

                $SchaltpunktID++;
                IPS_SetEventScheduleGroupPoint($varId_wochenplan, 0, $SchaltpunktID, idate('H', $start), idate('i', $start),  idate('s', $start), 1);
                $averagePrice += $EPEXSpot;
                $switchOnInfo .= sprintf("\r\n %s {%s ct/kwh}", date('d.m.y H:i', $start), $EPEXSpot);
                if ($this->logLevel >= LogLevel::TRACE) {
                    $this->AddLog(__FUNCTION__, sprintf(" [%d/%d] Start-POINT added: %s - %s ct/kWh", $i, $count, date('d.m.y H:i', $start), $EPEXSpot));  
                }

                $doAdddEndPoint = false;
                if($i >= $count-1) { 
                    $doAdddEndPoint = true; 
                } else if($end != $hoursBelowThresholdArr[$i+1]["start"]) { 
                    $doAdddEndPoint = true; 
                }
                if($doAdddEndPoint) {
                    $SchaltpunktID++;
                    IPS_SetEventScheduleGroupPoint($varId_wochenplan, 0, $SchaltpunktID, idate('H', $end), idate('i', $end),  idate('s', $end), 0);
                    if ($this->logLevel >= LogLevel::TRACE) {
                        //$this->AddLog(__FUNCTION__, sprintf(" [%d/%d] End-POINT added: %s - %s ct/kWh", $i, $count, date('d.m.y H:i', $end), $EPEXSpot));  
                        $this->AddLog(__FUNCTION__, sprintf(" [%d/%d] End-POINT added: %s", $i, $count, date('d.m.y H:i', $end)));  
                    }
                }
            }
            if($count > 0) {
                IPS_SetEventActive($varId_wochenplan, true);
                $averagePrice = round($averagePrice / $count, 3);
                $switchOnInfo .= sprintf("\r\n Durchschnitt der %s Stunden: %s ct/kwh", $count, $averagePrice);
            } else {
                $switchOnInfo = "KEINE Stunden mit den Vorgaben vorhanden!";
            }

        } else {
            $hoursBelowThresholdArr = false;
        }
        SetValue($varId_data, $switchOnInfo);
        return $hoursBelowThresholdArr;
    }


    protected function SetHoursWithLowestPrice(int $priceSwitchRoodId) {

        $continuousHoursArr = [];
        $priceArrTEMP = [];

        $varId_mode = IPS_GetObjectIDByIdent("_mode", $priceSwitchRoodId);
        $varId_threshold = IPS_GetObjectIDByIdent("_threshold", $priceSwitchRoodId);           
        $varId_timeWindowStart = IPS_GetObjectIDByIdent("_timeWindowStart", $priceSwitchRoodId);
        $varId_timeWindowEnd = IPS_GetObjectIDByIdent("_timeWindowEnd", $priceSwitchRoodId);
        $varId_duration = IPS_GetObjectIDByIdent("_duration", $priceSwitchRoodId);            
        $varId_continuousHours = IPS_GetObjectIDByIdent("_continuousHours", $priceSwitchRoodId);	
        $varId_switch = IPS_GetObjectIDByIdent("_switch", $priceSwitchRoodId);
        $varId_wochenplan = IPS_GetObjectIDByIdent("_wochenplan", $varId_switch);            
        $varId_data = IPS_GetObjectIDByIdent("_data", $priceSwitchRoodId);

        $priceMode = GetValueInteger($varId_mode);
        $threshold = GetValueFloat($varId_threshold);
        $timeWindowStart = GetValueInteger($varId_timeWindowStart);
        $timeWindowEnd = GetValueInteger($varId_timeWindowEnd);
        $duration = GetValueInteger($varId_duration);
        $continuousHours = GetValueBoolean($varId_continuousHours);
        $switch = GetValueBoolean($varId_switch);

        $this->ResetWochenplan($varId_wochenplan);


        $durationHours = idate('G', $duration);

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("duration %s | %s hours", print_r($duration, true), $durationHours));
        }

        $marketdataArrFromNow = $this->GetMarketdataArr(0,0,true);
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

    protected function GetMarketdataArr(int $startTS=0, int $endTS=0, bool $futureHoursOnly=false) {

        $marketdataArr = [];
        $dateTimeNow = time();

        $marketdataExtended = $this->__get("Buff_MarketdataExtended");
        if (is_array($marketdataExtended)) {
            if (array_key_exists("MarketdataArr", $marketdataExtended)) {
                if (is_array($marketdataArr)) {

                    foreach ($marketdataExtended["MarketdataArr"] as $item) {

                        $addItem = false;
                        $start = $item["start"];
                        if($startTS > 0) {
                            if($start >= $startTS){
                                if($futureHoursOnly) {
                                    if ($start > $dateTimeNow) { $addItem = true; } else { $addItem = false; }
                                } else {
                                    $addItem = true;
                                }
                            }
                        } else {
                            if($futureHoursOnly) {
                                if ($start > $dateTimeNow) { $addItem = true; } else { $addItem = false; }
                            } else {
                                $addItem = true;
                            }
                        }

                        if($endTS > 0) {
                            if ($start + 3600 > $endTS) {
                                $addItem = false;
                            }
                        }

                        if($addItem) {
                            $marketdataArr[] = $item;
                        }
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

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("Return marketdataArr [StartTS: %s - EndTS: %s | %b] \r\n%s", $this->UnixTimestamp2String($startTS), $this->UnixTimestamp2String($endTS), $futureHoursOnly, print_r($marketdataArr, true)));
            foreach($marketdataArr as $marketdataItem) {
                $this->AddLog(__FUNCTION__, sprintf(" %s - %s ct/kWh", date('d.m.y H:i', $marketdataItem["start"]),  $marketdataItem["EPEXSpot"]));  
            }
        }

        return $marketdataArr;
    }


}
