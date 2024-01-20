<?php

declare(strict_types=1);

trait AWATTAR_FUNCTIONS {

    protected function RequestMarketdata() {
        // build params
        $params1 = [
            'start' => strtotime(date('d.m.Y 00:00:00')) * 1000,
            'end' => (time() + 3600 * 24) * 1000
        ];
        $params2 = [
            'start' => strtotime(date('d.m.Y 00:00:00')) * 1000
        ];    
        $params3 = [
            'start' => strtotime(date('d.m.Y 00:00:00')) * 1000,
            'end' => strtotime('tomorrow 24:00') * 1000
        ];            
        $params4 = [
            'start' => strtotime(date('d.m.Y 14:00:00')) * 1000,
            'end' => strtotime('tomorrow 13:00') * 1000
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

        //$apiURL = 'https://api.awattar.at/v1/marketdata';
        $apiURL = 'https://api.awattar.at/v1/marketdata?' . http_build_query($params3);
        //$apiURL = 'https://api.awattar.at/v1/marketdata?' . http_build_query($params4);

        
        if ($this->logLevel >= LogLevel::COMMUNICATION) {
            $this->AddLog(__FUNCTION__, sprintf("Request '%s' [start: %s | end: %s]", $apiURL, $this->UnixTimestamp2String($params3["start"]/1000), $this->UnixTimestamp2String($params3["end"]/1000)));
        }

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
            } else if ($this->logLevel >= LogLevel::COMMUNICATION) {
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
            'LastUpdate' => time(),
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

        $result = true;
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

                if ($this->logLevel >= LogLevel::INFO) {
                    $this->AddLog(__FUNCTION__, sprintf("Buff_MarketdataExtended updated with %d Entries", $this->marketdataExtended["Entries"]));
                }
                $this->Increase_CounterVariable($this->GetIDForIdent("updateCntOk"));

                $result = $this->UpdateMarketdataVariables();

            } else {
                $result = false;
                $this->Increase_CounterVariable($this->GetIDForIdent("updateCntNotOk"));
                $this->HandleError(__FUNCTION__, "ERROR creating 'MarketdataExtended'");
            }
        } else {
            $result = false;
            $this->HandleError(__FUNCTION__, "Error: no aWATTar Marketdata avialable");
        }

        return $result;
    }

    protected function UpdateWochenplan(int $priceSwitchRoodId) {
       
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
        $threshold = null;
        if($priceMode == 2) { $threshold = GetValueFloat($varId_threshold); }
        
        $timeWindowStart = GetValueInteger($varId_timeWindowStart) + 3600;
        $timeWindowEnd = GetValueInteger($varId_timeWindowEnd) + 3600;

        $todaySeconds = time() - strtotime("today");
        if($todaySeconds > $timeWindowStart) {
              $timeWindowStart += 24*3600;
        }
        if($timeWindowStart > $timeWindowEnd) {
            $timeWindowEnd += 24*3600;
        }

        $startTS = strtotime('midnight') + $timeWindowStart;
        $endTS = strtotime(date("Y-m-d 0:0:0")) + $timeWindowEnd; //+ 3600;
        if ($timeWindowEnd <= $timeWindowStart) {
            $endTS = $endTS + 3600 * 24;
        }

        $duration = GetValueInteger($varId_duration);
        $continuousHours = GetValueBoolean($varId_continuousHours);
        //if($priceMode == 3) { $continuousHours = true; }
        //$switch = GetValueBoolean($varId_switch);            

        $hoursBelowThresholdArr = $this->GetHoursWithLowestPrice($startTS, $endTS, $threshold, $duration, $continuousHours, true);
        $this->SetWochenplanEventPoints($varId_wochenplan, $priceSwitchRoodId, $hoursBelowThresholdArr);

        $_data = GetValue($varId_data);
        $_data .= sprintf("\r\nFolgender Zeitraum wurde verwendet: %s - %s", $this->UnixTimestamp2String($startTS), $this->UnixTimestamp2String($endTS));
        $_data .= sprintf("\r\nLast Updated @%s", $this->UnixTimestamp2String(time()));
        SetValue($varId_data, $_data);
    }


    protected function SetWochenplanEventPoints(int $varId_wochenplan, int $priceSwitchRoodId, array $hoursArr ) {

        $switchOnInfo = "OnHours: ";
        $varId_data = IPS_GetObjectIDByIdent("_data", $priceSwitchRoodId);

        IPS_SetEventActive($varId_wochenplan, false);
        $this->ResetWochenplan($varId_wochenplan);

        $SchaltpunktID = 0;
        $averagePrice = 0;
        $count = count($hoursArr);
        for ($i = 0; $i < $count; $i++) {
            $start = $hoursArr[$i]["start"];
            $end = $hoursArr[$i]["end"];
            $EPEXSpot = $hoursArr[$i]["EPEXSpot"];

            $eventScheduleGroupDay = $this->GetEventScheduleGroupDayFromTimestamp($start);
            IPS_SetEventScheduleGroup($varId_wochenplan, $eventScheduleGroupDay, $eventScheduleGroupDay);

            $SchaltpunktID++;
            IPS_SetEventScheduleGroupPoint($varId_wochenplan, $eventScheduleGroupDay, $SchaltpunktID, idate('H', $start), idate('i', $start),  idate('s', $start), 1);
            $averagePrice += $EPEXSpot;
            $switchOnInfo .= sprintf("\r\n %s {%s ct/kwh}", date('d.m.y H:i', $start), $EPEXSpot);
            if ($this->logLevel >= LogLevel::TRACE) {
                $this->AddLog(__FUNCTION__, sprintf(" [%d/%d] Start-POINT added: %s - %s ct/kWh", $i, $count, date('d.m.y H:i', $start), $EPEXSpot));
            }

            $doAdddEndPoint = false;
            if ($i >= $count - 1) {
                $doAdddEndPoint = true;
            } else if ($end != $hoursArr[$i + 1]["start"]) {
                $doAdddEndPoint = true;
            }
            if ($doAdddEndPoint) {
                $SchaltpunktID++;
                IPS_SetEventScheduleGroupPoint($varId_wochenplan, $eventScheduleGroupDay, $SchaltpunktID, idate('H', $end), idate('i', $end),  idate('s', $end), 0);
                if ($this->logLevel >= LogLevel::TRACE) {
                    //$this->AddLog(__FUNCTION__, sprintf(" [%d/%d] End-POINT added: %s - %s ct/kWh", $i, $count, date('d.m.y H:i', $end), $EPEXSpot));  
                    $this->AddLog(__FUNCTION__, sprintf(" [%d/%d] End-POINT added: %s", $i, $count, date('d.m.y H:i', $end)));
                }
            }
        }
        if ($count > 0) {
            IPS_SetEventActive($varId_wochenplan, true);
            $averagePrice = round($averagePrice / $count, 3);
            $switchOnInfo .= sprintf("\r\n Durchschnitt der %s Stunden: %s ct/kwh", $count, $averagePrice);
        } else {
            $switchOnInfo = "KEINE Stunden mit den Vorgaben vorhanden!";
        }
        SetValue($varId_data, $switchOnInfo);


    }


    protected function GetHoursWithLowestPrice(int $startTS, int $endTS, float $threshold=null, int $durationSec, bool $continuousHours, bool $futureHoursOnly=true) {
        
        $hoursBelowThresholdArr = [];

        $numberOfHours = idate('H', $durationSec);

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("Start: %s} | End: %s",  $this->UnixTimestamp2String($startTS), $this->UnixTimestamp2String($endTS)));
            $this->AddLog(__FUNCTION__, sprintf(" | threshold: %s ct | continuousHours: %b | durationSec %s = %s hours {=%s}",  print_r($threshold, true),  $continuousHours, $durationSec, $numberOfHours,$this->UnixTimestamp2String($durationSec)));
        }      

        $marketdataArr = $this->GetMarketdataArr($startTS, $endTS, $threshold, $futureHoursOnly);
        if ($marketdataArr !== false) {
            if($continuousHours) {
                $hoursBelowThresholdArr =  $this->GetLowestContinuousHours($marketdataArr, $numberOfHours);
            } else {
                $hoursBelowThresholdArr =  $this->GetLowestPriceHours($marketdataArr, $numberOfHours);
            }      
        } else {
            if ($this->logLevel >= LogLevel::WARN) {
                $this->AddLog(__FUNCTION__, "KEINE aktuellen Marktdaten verfügbar > [GetMarketdataArr() === false]");
            }
        }
        return $hoursBelowThresholdArr;
    }


    protected function GetLowestPriceHours(array $inputDataArr, int $numberOfHours) {

        $hoursWithLowestPriceArr = [];

        if ($inputDataArr !== false) {
            $col = array_column($inputDataArr, "EPEXSpot");
            array_multisort($col, SORT_ASC, $inputDataArr);

            $hoursWithLowestPriceArr = array_slice($inputDataArr, 0, $numberOfHours, true);
            if (count($hoursWithLowestPriceArr) > 1) {
                $col = array_column($hoursWithLowestPriceArr, "start");
                array_multisort($col, SORT_ASC, $hoursWithLowestPriceArr);
            }
        } else {
            if ($this->logLevel >= LogLevel::WARN) {
                $this->AddLog(__FUNCTION__, "KEINE aktuellen Marktdaten verfügbar!");
            }
        }

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("IputDataArr Cnt: %s | GetLowest %s hours | OutputDataArr Cnt: %s", count($inputDataArr), $numberOfHours, count($hoursWithLowestPriceArr)));
        }

        return $hoursWithLowestPriceArr;
    }

    protected function GetLowestContinuousHours(array $inputDataArr, int $numberOfHours) {

        $hoursWithLowestPriceArr = [];
        $priceArrTEMP = [];

        if ($inputDataArr !== false) {
            $entries = count($inputDataArr);

            $index1 = 0;
            foreach ($inputDataArr as $item) {
                $sufficientContinuousHours = true;
                //if ($this->logLevel >= LogLevel::TRACE) {
                //    $this->SendDebug(__FUNCTION__, sprintf("foreach Item Key '%s'",  $item["key"]), 0);
                //}
                $price = 0;
                $startTS = -1;
                $endTS = -1;
                $key = $item["key"];
                for ($i = $index1; $i <= $index1 + $numberOfHours - 1; $i++) {

                    if ($i >= $entries) {
                        $sufficientContinuousHours = false;
                        if ($this->logLevel >= LogLevel::TRACE) {
                            $this->AddLog(__FUNCTION__, sprintf(" - %s with %d h > zu wenige Elemente vorhanden" ,$key, $numberOfHours));
                        }                         
                        break;
                    } else {
                        $startTS = $inputDataArr[$i]["start"];
                    }

                    if(($endTS != -1) AND ($endTS != $startTS)) {                      
                        $sufficientContinuousHours = false;
                        if ($this->logLevel >= LogLevel::TRACE) {
                            $this->AddLog(__FUNCTION__, sprintf(" - %s with %d h > no continuous hour {endTS: %s != startTS: %s}", $key, $numberOfHours, $this->UnixTimestamp2String($endTS), $this->UnixTimestamp2String($startTS)));
                        }                          
                        break;
                    } else {
                        $price += $inputDataArr[$i]["EPEXSpot"];
                        $endTS = $inputDataArr[$i]["end"];
                    }
                 }
                if ($sufficientContinuousHours) {
                    $priceArrTEMP[] = ["startKey" => $key, "price" => round($price, 3)];

                    if ($this->logLevel >= LogLevel::TRACE) {
                        $this->AddLog(__FUNCTION__, sprintf(" - add %s with %d h > average price %.3f ct/kWh" ,$key, $numberOfHours, $price/$numberOfHours));
                    }                    
                }
                $index1++;
            }

            if (count($priceArrTEMP) > 0) {
                $col = array_column($priceArrTEMP, "price");
                array_multisort($col, SORT_ASC, SORT_NUMERIC, $priceArrTEMP);

                $startKey = $priceArrTEMP[0]["startKey"];
                $averagePrice = $priceArrTEMP[0]["price"];

                if ($this->logLevel >= LogLevel::TRACE) {
                    $this->AddLog(__FUNCTION__, sprintf(" - lowest average price with %d continuous hours starts at %s" ,$numberOfHours, $startKey));
                } 

                $inputDataArrStartKey = array_search($startKey , array_column($inputDataArr, 'key'));

                if($inputDataArrStartKey !== false) {
                    for($i=$inputDataArrStartKey; $i < ($inputDataArrStartKey + $numberOfHours); $i++ ) {
                        $hoursWithLowestPriceArr[] = $inputDataArr[$i];
                    }
                }
            }
       
        } else {
            if ($this->logLevel >= LogLevel::WARN) {
                $this->AddLog(__FUNCTION__, "KEINE aktuellen Marktdaten verfügbar!");
            }
        }

        if ($this->logLevel >= LogLevel::TRACE) {
            $this->AddLog(__FUNCTION__, sprintf("IputDataArr Cnt: %s | GetLowest %s hours | OutputDataArr Cnt: %s", count($inputDataArr), $numberOfHours, count($hoursWithLowestPriceArr)));
        }

        return $hoursWithLowestPriceArr;
    }

    protected function GetMarketdataArr(int $startTS = 0, int $endTS = 0, float $threshold=null, bool $futureHoursOnly=false) {

        $marketdataArr = [];
        $dateTimeNow = time();

        $marketdataExtended = $this->__get("Buff_MarketdataExtended");
        if (is_array($marketdataExtended)) {
            if (array_key_exists("MarketdataArr", $marketdataExtended)) {
                if (is_array($marketdataArr)) {

                    foreach ($marketdataExtended["MarketdataArr"] as $item) {

                        $addItem = false;
                        $start = $item["start"];
                        if ($startTS > 0) {
                            if ($start >= $startTS) {
                                if ($futureHoursOnly) {
                                    if ($start > $dateTimeNow) {
                                        $addItem = true;
                                    } else {
                                        $addItem = false;
                                    }
                                } else {
                                    $addItem = true;
                                }
                            }
                        } else {
                            if ($futureHoursOnly) {
                                if ($start > $dateTimeNow) {
                                    $addItem = true;
                                } else {
                                    $addItem = false;
                                }
                            } else {
                                $addItem = true;
                            }
                        }

                        if ($endTS > 0) {
                            if ($start + 3600 > $endTS) {
                                $addItem = false;
                            }
                        }

                        if (!is_null($threshold)) {
                            if ($item["EPEXSpot"] > $threshold) {
                                $addItem = false;
                            }
                        }                        

                        if ($addItem) {
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
            $this->AddLog(__FUNCTION__, sprintf("Return marketdataArr [StartTS: %s - EndTS: %s | threshold: %.3f | FutureHoursOnly: %b]", $this->UnixTimestamp2String($startTS), $this->UnixTimestamp2String($endTS), print_r($threshold, true), $futureHoursOnly));
            foreach ($marketdataArr as $marketdataItem) {
                $this->AddLog(__FUNCTION__, sprintf(" %s - %s ct/kWh", date('d.m.y H:i', $marketdataItem["start"]),  $marketdataItem["EPEXSpot"]));
            }
        }

        return $marketdataArr;
    }

    public function GetMarketdataArrFromBuffer(string $caller) {

        $marketdataArr = $this->GetMarketdataArr(0, 0, null, false);
        $this->DebugPriceArr($marketdataArr, "MarketdataArr");
        return $marketdataArr;
    }

    protected function DebugPriceArr(array $inputDataArr, string $name) {
    
        $this->AddLog(__FUNCTION__, sprintf("|| %s", $name));
        foreach($inputDataArr as $key => $value) {
            $start = date('d.m.y H:i', $value["start"]);
            $end = date('d.m.y H:i', $value["end"]);
            $price = $value["EPEXSpot"];

            $logMsg = sprintf("|| -key: '%s' | %s - %s | %.3f ct/kWh", print_r($key, true), $start, $end, $price);
            $this->AddLog(__FUNCTION__, $logMsg);
        }
    }

    protected function GetEventScheduleGroupDayFromTimestamp(int $timestamp) {

        $weekday = idate('w', $timestamp);          // (0 für Sonntag)

        $eventScheduleGroupDay = 64;                // Initialisierung auf Sonntag
        switch ($weekday) {
            case 1:                                 // Montag
                $eventScheduleGroupDay = 1;     
                break;
            case 2:                                 // Dienstag
                $eventScheduleGroupDay = 2;
                break;
            case 3:                                 // Mittwoch
                $eventScheduleGroupDay = 4;
                break;
            case 4:                                 // Donnerstag
                $eventScheduleGroupDay = 8;
                break;
            case 5:                                 // Freitag
                $eventScheduleGroupDay = 16;
                break;
            case 6:                                 // Samstag
                $eventScheduleGroupDay = 32;
                break;
            default:                                // Sonntag
                $eventScheduleGroupDay = 64;     
                break;
        }

        return $eventScheduleGroupDay;
    }
}
