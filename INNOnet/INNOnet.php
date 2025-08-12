<?php

declare(strict_types=1);


trait INNOnet_FUNCTIONS {


    public function TimeSeriesCollections() {
        //C:\ProgramData\Symcon\logs\INNOnet.json.txt
        //https://app-innonnetwebtsm-dev.azurewebsites.net/api/extensions/timeseriesauthorization/repositories/INNOnet-prod/apikey/{{API_Key}}/timeseriescollections/selected-data?from=2025-08-01T23:00:00Z&to=2025-08-02T23:00:00Z
    }

    public function RequestAPI(string $caller, string $apiURL) {

        $httpResponse = false;

        if ($this->logLevel >= LogLevel::TEST) { $this->AddLog(__FUNCTION__, sprintf("Request API URL '%s' [Trigger: %s", $apiURL, $caller)); }


        $curlOptions = [
            CURLOPT_TIMEOUT => 28,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            )
            ];

        $ch = curl_init($apiURL);

        $startTime =  microtime(true);
        try {
            curl_setopt_array($ch, $curlOptions);
            $httpResponse = curl_exec($ch);
            if ($httpResponse === false) {
                $httpResponse = false;
                $errorMsg = sprintf('{ "ERROR" : "curl_exec > %s [%s] {%s}" }', curl_error($ch), curl_errno($ch), $apiURL);
                $this->HandleError(__FUNCTION__, $errorMsg, 0, true);
            }

            if ($this->logLevel >= LogLevel::TEST) {
                $this->AddLog(__FUNCTION__, sprintf("httpResponse [%s]", print_r($httpResponse,true)));
            }

            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatusCode >= 400) {
                $httpResponse = false;
                $errorMsg = sprintf('{ "ERROR" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                $this->HandleError(__FUNCTION__, $errorMsg, 0, true);
            } else if ($httpStatusCode != 200) {
                $msg = sprintf('{ "WARN" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                if ($this->logLevel >= LogLevel::WARN) {
                    $this->AddLog(__FUNCTION__, $msg, 0, true);
                }
            } else if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $this->AddLog(__FUNCTION__, sprintf("OK > httpStatusCode '%s' < %s ", $httpStatusCode, $apiURL));
            }
        } catch (Exception $e) {
             $httpResponse = false;
            $errorMsg = sprintf('{ "ERROR" : "Exception > %s [%s] {%s}" }', $e->getMessage(), $e->getCode(), $apiURL);
            $this->HandleError(__FUNCTION__, $errorMsg, 0, true);
        } finally {
            curl_close($ch);
            if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $duration = $this->CalcDuration_ms($startTime);
                $this->AddLog(__FUNCTION__, sprintf("CURL Connection closed [Duration: %.2f ms]", $duration));
            }
        }


        if ($httpResponse === false) {
            $this->SetStatus(200);
            return false;
        } else {
            return $httpResponse;

            /*
            $jsonResponse = json_decode($httpResponse);
            if (json_last_error() == JSON_ERROR_NONE) {
                $this->SetStatus(102);
                //return isset($jsonResponse['data']) ? $jsonResponse['data'] : false;
                return $jsonResponse;
            } else {
                $this->SetStatus(200);
                return false;
            }
            */
        }
    }


    function AddLoggedValue($varId, $dateTime, $value) {
    
        $returnValue = true;

        $loggingStatus = AC_GetLoggingStatus($this->archivInstanzID, $varId);
        if($loggingStatus) {

            $dateMiddnigt = clone $dateTime;
            $timeStamp = clone $dateTime;
           
            $dateMiddnigt = $dateMiddnigt->setTime(0,0,10)->getTimestamp();
            $timeStamp = $timeStamp->setTime(23,59,50)->getTimestamp();


            $dataArr = [];

            $dataEntry = [];
            $dataEntry['TimeStamp'] = $dateMiddnigt;
            $dataEntry['Value'] = 0;
            $dataArr[] = $dataEntry;

            $dataEntry = [];
            $dataEntry['TimeStamp'] = $timeStamp;
            $dataEntry['Value'] = $value;
            $dataArr[] = $dataEntry;

            if ($this->logLevel >= LogLevel::INFO) {
                $logMsg = sprintf("AddLoggedValue {%d, %s [%s - %s, %s}", $varId, $dateTime->format('d-m-Y H:i:s'), date('d.m.Y H:i:s', $dateMiddnigt), date('d.m.Y H:i:s', $timeStamp), $value);
                $this->AddLog(__FUNCTION__, $logMsg );
            }            

            $returnValue = AC_AddLoggedValues($this->archivInstanzID, $varId, $dataArr);

        } else {
            $returnValue = false;
            if ($this->logLevel >= LogLevel::WARN) {
                $logMsg = sprintf("Logging für die Variable '%d' NICHT aktiv", $varId);
                $this->AddLog(__FUNCTION__, $logMsg );
            }
        }
        return $returnValue;
    }


}
