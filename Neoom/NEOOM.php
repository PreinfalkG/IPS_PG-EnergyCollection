<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

trait NEOOM_FUNCTIONS {

    public function QueryUserEnergy(string $caller, DateTime $dtQueryFrom, DateTime $dtQueryTo, string $walletId) {

        $httpResponse = false;
        $apiURL = 'https://app-api.neoom.com/graphql';
        $bearerToken = $this->ReadPropertyString("tb_BearerToken");

        if ($this->logLevel >= LogLevel::COMMUNICATION) {
            $logMsg = sprintf("Request '%s' with Bearer '%s' for '%s - %s'", $apiURL, $bearerToken, $dtQueryFrom->format('c'), $dtQueryTo->format('c'));
            $this->AddLog(__FUNCTION__, sprintf("QueryUserEnergy [Trigger > %s] \n %s", $caller, $logMsg));
        }

        $queryData = '{
            "operationName": "UserEnergy",
            "variables": {
                "from": "%DATE_FROM%",
                "to": "%DATE_TO%",
                "walletId": "%WALLED_ID%"
            },
            "query": "query UserEnergy($from: DateTime!, $to: DateTime, $walletId: ID!) {\n  userEnergy(from: $from, to: $to, walletId: $walletId) {\n    object {\n      ...UserEnergyObject\n      __typename\n    }\n    data {\n      ...UserEnergyData\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment UserEnergyObject on Object {\n  id\n  meteringPoints {\n    ...MeteringPoint\n    __typename\n  }\n  energyCommunity {\n    ...EnergyCommunityFragment\n    __typename\n  }\n  wishEnergyCommunity {\n    id\n    name\n    ecType\n    __typename\n  }\n  energyCommunityJoinedMessageHidden\n  name\n  __typename\n}\n\nfragment MeteringPoint on MeteringPoint {\n  id\n  meteringPoint\n  name\n  type\n  energyPrice\n  energySource\n  generationPower\n  storagePower\n  storageCapacityUsable\n  object {\n    id\n    __typename\n  }\n  type\n  __typename\n}\n\nfragment EnergyCommunityFragment on EnergyCommunity {\n  id\n  name\n  ecType\n  hasCustomPlantOperatorContract\n  generatingCountingPointNumber\n  phone\n  email\n  energyPrice\n  totalMembers\n  taxType\n  zvr\n  plantOperator {\n    id\n    name\n    __typename\n  }\n  __typename\n}\n\nfragment UserEnergyData on UserEnergyData {\n  consumption {\n    total\n    eg\n    grid\n    percent\n    __typename\n  }\n  feedIn {\n    total\n    eg\n    grid\n    percent\n    __typename\n  }\n  co2Reduced\n  costsSaved\n  feedInYield\n  __typename\n}"
        }';
    
        $queryFrom = $dtQueryFrom->format(DateTime::ATOM);
        $queryTo = $dtQueryTo->format(DateTime::ATOM);
        $queryData = str_replace("%DATE_FROM%", $queryFrom, $queryData);
        $queryData = str_replace("%DATE_TO%", $queryTo, $queryData);
        $queryData = str_replace("%WALLED_ID%", $walletId, $queryData);

        if ($this->logLevel >= LogLevel::COMMUNICATION) {
            $this->AddLog(__FUNCTION__, sprintf("QueryUserEnergy [GraphQL] %s ", $queryData));
        }

        $curlOptions = [
            CURLOPT_TIMEOUT => 8,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => array(
                'x-app-version: latest',
                'x-vivid-api-scope: Frontend',
                'Content-Type: application/json',
                'Authorization: Bearer '. $bearerToken
            ),
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => $queryData
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

            if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $this->AddLog(__FUNCTION__, sprintf("QueryUserEnergy httpResponse [%s]", print_r($httpResponse,true)));
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
            $jsonResponse = json_decode($httpResponse);
            if (json_last_error() == JSON_ERROR_NONE) {
                $this->SetStatus(102);
                //return isset($jsonResponse['data']) ? $jsonResponse['data'] : false;
                return $jsonResponse;
            } else {
                $this->SetStatus(200);
                return false;
            }
        }
    }


    public function ExtractAndAddLoggedValues($dateTime, $jsonData) {

        $returnValue = true;

        if(isset($jsonData->errors)) {
            if(isset($jsonData->errors[0]->message)) {
                $errorMsg = $jsonData->errors[0]->message;
                $this->HandleError(__FUNCTION__, $errorMsg, 0, true);
            } else {
                $this->HandleError(__FUNCTION__, "Unknown ERROR", 0, true);
            }
            return false;
        } 

        if($this->ReadPropertyBoolean("cb_UserEnergyFeedIn")) {
            if(isset($jsonData->data->userEnergy[0]->data->consumption)) {
                $eg = $jsonData->data->userEnergy[0]->data->consumption->eg;
                $grid = $jsonData->data->userEnergy[0]->data->consumption->grid;
                $total = $jsonData->data->userEnergy[0]->data->consumption->total;
                $percent = $jsonData->data->userEnergy[0]->data->consumption->percent;           
    
                $varId_User_consumptionEG = $this->GetIDForIdent("user_consumptionEG");
                $varId_User_consumptionGrid = $this->GetIDForIdent("user_consumptionGrid");
                $varId_User_consumptionTotal = $this->GetIDForIdent("user_consumptionTotal");
                $varId_User_consumptionPercent = $this->GetIDForIdent("user_consumptionPercent");
    
                $this->AddLoggedValue($varId_User_consumptionEG,        $dateTime, round($eg/1000, 3));
                $this->AddLoggedValue($varId_User_consumptionGrid,      $dateTime, round($grid/1000, 3));
                $this->AddLoggedValue($varId_User_consumptionTotal,     $dateTime, round($total/1000, 3));
                $this->AddLoggedValue($varId_User_consumptionPercent,   $dateTime, round($percent, 2));

                //SetValueFloat($varId_User_consumptionEG,         round($eg/1000, 3));
                //SetValueFloat($varId_User_consumptionGrid,       round($grid/1000, 3));
                //SetValueFloat($varId_User_consumptionTotal,      round($total/1000, 3));            
                //SetValueFloat($varId_User_consumptionPercent,    round($percent, 2));

            } else {
                $returnValue = false;
                if ($this->logLevel >= LogLevel::WARN) {
                    $logMsg = sprintf("WARN: 'data->userEnergy[0]->data->feedIn' not found in JSON Data {}", print_r($jsonData));
                    $this->AddLog(__FUNCTION__, $logMsg );
                }
            }
        }

        if($this->ReadPropertyBoolean("cb_UserEnergyFeedIn")) {
            if(isset($jsonData->data->userEnergy[0]->data->feedIn)) {
                $eg = $jsonData->data->userEnergy[0]->data->feedIn->eg;
                $grid = $jsonData->data->userEnergy[0]->data->feedIn->grid;
                $total = $jsonData->data->userEnergy[0]->data->feedIn->total;
                $percent = $jsonData->data->userEnergy[0]->data->feedIn->percent;           
 
                $varId_User_feedInEG = $this->GetIDForIdent("user_feedInEG");
                $varId_User_feedInGrid = $this->GetIDForIdent("user_feedInGrid");
                $varId_User_feedInTotal = $this->GetIDForIdent("user_feedInTotal");
                $varId_User_feedInPercent = $this->GetIDForIdent("user_feedInPercent");
 
                $this->AddLoggedValue($varId_User_feedInEG,        $dateTime, round($eg/1000, 3));
                $this->AddLoggedValue($varId_User_feedInGrid,      $dateTime, round($grid/1000, 3));
                $this->AddLoggedValue($varId_User_feedInTotal,     $dateTime, round($total/1000, 3));
                $this->AddLoggedValue($varId_User_feedInPercent,   $dateTime, round($percent, 2));
 
                //SetValueFloat($varId_User_feedInEG,         round($eg/1000, 3));
                //SetValueFloat($varId_User_feedInGrid,       round($grid/1000, 3));
                //SetValueFloat($varId_User_feedInTotal,      round($total/1000, 3));            
                //SetValueFloat($varId_User_feedInPercent,    round($percent, 2));
 
            } else {
                $returnValue = false;
                if ($this->logLevel >= LogLevel::WARN) {
                    $logMsg = sprintf("WARN: 'data->userEnergy[0]->data->feedIn' not found in JSON Data {}", print_r($jsonData));
                    $this->AddLog(__FUNCTION__, $logMsg );
                }
            }
        }


        if(isset($jsonData->data->userEnergy[0]->data->feedInYield)) {

            $co2Reduced = $jsonData->data->userEnergy[0]->data->co2Reduced;
            $costsSaved = $jsonData->data->userEnergy[0]->data->costsSaved;
            $feedInYield = $jsonData->data->userEnergy[0]->data->feedInYield;

            $varId_User_co2Reduced = $this->GetIDForIdent("user_co2Reduced");
            $varId_User_costsSaved = $this->GetIDForIdent("user_costsSaved");
            $varId_User_feedInYield = $this->GetIDForIdent("user_feedInYield");

            $this->AddLoggedValue($varId_User_co2Reduced,    $dateTime, round($co2Reduced, 2));
            $this->AddLoggedValue($varId_User_costsSaved,    $dateTime, round($costsSaved, 8));
            $this->AddLoggedValue($varId_User_feedInYield,   $dateTime, round($feedInYield, 8));

            //SetValueFloat($varId_User_co2Reduced,   round($co2Reduced, 2));
            //SetValueFloat($varId_User_costsSaved,   round($costsSaved, 8));
            //SetValueFloat($varId_User_feedInYield,  round($feedInYield, 8));

            $varId_DateTimeQueryInfo = $this->GetIDForIdent("dateTimeQueryInfo");
            SetValueString($varId_DateTimeQueryInfo,  $dateTime->format('d_m_Y H:i:s'));

        } else {
            $returnValue = false;
            if ($this->logLevel >= LogLevel::WARN) {
                $logMsg = sprintf("WARN: 'data->userEnergy[0]->data->feedInYield' not found in JSON Data {}", print_r($jsonData));
                $this->AddLog(__FUNCTION__, $logMsg );
            }
        }


        if($this->ReadPropertyBoolean("cb_EnergyCommunityInfos")) {
            if(isset($jsonData->data->userEnergy[0]->object->energyCommunity)) {
                $energyPrice = $jsonData->data->userEnergy[0]->object->energyCommunity->energyPrice;
                $totalMembers = $jsonData->data->userEnergy[0]->object->energyCommunity->totalMembers;

                if($dateTime < new DateTime('01.04.2024')) {
                    $energyPrice = 18;
                } elseif ($dateTime < new DateTime('01.10.2024')) {
                    $energyPrice = 13;
                }

                $varId_EG_EnergyPrice = $this->GetIDForIdent("eg_energyPrice");
                $varId_EG_TotalMembers = $this->GetIDForIdent("eg_totalMembers");

                $this->AddLoggedValue($varId_EG_EnergyPrice,   $dateTime, round($energyPrice, 2));
                $this->AddLoggedValue($varId_EG_TotalMembers,  $dateTime, round($totalMembers));

                //SetValue($varId_EG_EnergyPrice,     round($energyPrice, 2));
                //SetValue($varId_EG_TotalMembers,    round($totalMembers));

            } else {
                $returnValue = false;
                if ($this->logLevel >= LogLevel::WARN) {
                    $logMsg = sprintf("WARN: 'data->userEnergy[0]->object->energyCommunity' not found in JSON Data {}", print_r($jsonData));
                    $this->AddLog(__FUNCTION__, $logMsg );
                }
            }
        }
   
        return $returnValue;

    }


    function AddLoggedValue($varId, $dateTime, $value) {
    
        $returnValue = true;

        $loggingStatus = AC_GetLoggingStatus($this->archivInstanzID, $varId);
        if($loggingStatus) {

            $dateTime = $dateTime->setTime(23,59,50);
            $timeStamp = $dateTime->getTimestamp();

            if ($this->logLevel >= LogLevel::DEBUG) {
                $logMsg = sprintf("AddLoggedValue {%d, %s - %s, %s}", $varId, $dateTime->format('d-m-Y H:i:s'), date('d.m.Y H:i:s', $timeStamp), $value);
                $this->AddLog(__FUNCTION__, $logMsg );
            }
            
            $dataArr = [];
            $dataEntry = [];
            $dataEntry['TimeStamp'] = $timeStamp;
            $dataEntry['Value'] = $value;
            $dataArr[] = $dataEntry;
        
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
