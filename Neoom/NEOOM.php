<?php

declare(strict_types=1);

//date_default_timezone_set('UTC');


/*

User Energy
from: "2023-10-27T02:00:00Z", to: "2023-10-27T02:00:00Z"
from: "2023-11-01T01:00:00Z", to: "2023-11-01T01:00:00Z"
from: "2023-12-01T01:00:00Z", to: "2023-12-01T01:00:00Z"
from: "2024-01-01T01:00:00Z", to: "2024-01-01T01:00:00Z"
from: "2024-02-01T01:00:00Z", to: "2024-02-01T01:00:00Z"
from: "2024-03-01T01:00:00Z", to: "2024-03-01T01:00:00Z"
from: "2024-04-01T02:00:00Z", to: "2024-04-01T02:00:00Z"
from: "2024-05-01T02:00:00Z", to: "2024-05-01T02:00:00Z"
from: "2024-06-01T02:00:00Z", to: "2024-06-01T02:00:00Z"
from: "2024-07-01T02:00:00Z", to: "2024-07-01T02:00:00Z"
from: "2024-08-01T02:00:00Z", to: "2024-08-01T02:00:00Z"
from: "2024-09-01T02:00:00Z", to: "2024-09-01T02:00:00Z"


Jänner:    from: "2024-01-01T01:00:00Z", to: "2024-01-31T23:59:59Z"
           from: "2024-02-01T01:00:00Z", to: "2024-08-31T23:59:59Z"
Jahr:      from: "2024-01-01T00:00:00Z", to: "2024-12-31T23:59:59Z"

EG Tag1:  from: "2024-02-02T01:00:00Z", to: "2024-02-02T01:00:00Z"
EG Tag2:  from: "2024-06-16T02:00:00Z", to: "2024-06-16T02:00:00Z"
EG Tage1: from: "2024-09-19T00:00:00Z", to: "2024-09-25T00:00:00Z
EG Tage2: from: "2024-06-01T02:00:00Z", to: "2024-06-16T02:00:00Z"
EG Monat: from: "2024-09-01T00:00:00Z", to: "2024-09-30T23:59:59Z
EG Jahr:  from: "2024-01-01T00:00:00Z", to: "2024-12-31T23:59:59Z"

*/


trait NEOOM_FUNCTIONS {

    public function QueryUserEnergy(string $caller, DateTime $dtQueryFrom, DateTime $dtQueryTo, string $walletId) {

        $httpResponse = false;
        $apiURL = 'https://app-api.neoom.com/graphql';
        $bearerToken = $this->ReadPropertyString("tb_BearerToken");

        $queryFrom = $dtQueryFrom->format('Y-m-d\TH:i:s\Z');    //$dtQueryFrom->format(DateTime::ATOM);
        $queryTo = $dtQueryTo->format('Y-m-d\TH:i:s\Z');        //$dtQueryTo->format(DateTime::ATOM);

        $queryFrom = $dtQueryFrom->format(DateTime::ATOM);
        $queryTo = $dtQueryTo->format(DateTime::ATOM);

		//$dateDetails = sprintf("ATOM: %s | c: %s | %s", $dtQueryFrom->format(DateTime::ATOM), $queryFrom, $dtQueryFrom->getTimezone()->getName());
		//SetValueString($this->GetIDForIdent("dateTimeQueryInfo"), $dateDetails);

        if ($this->logLevel >= LogLevel::INFO) {
            $logMsg = sprintf("Request '%s' with Bearer '%s' \n Query FromTo: '%s - %s'", $apiURL, $bearerToken, $queryFrom, $queryTo);
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
    

        $queryData = str_replace("%DATE_FROM%", $queryFrom, $queryData);
        $queryData = str_replace("%DATE_TO%", $queryTo, $queryData);
        $queryData = str_replace("%WALLED_ID%", $walletId, $queryData);

        if ($this->logLevel >= LogLevel::DEBUG) {
            $this->AddLog(__FUNCTION__, sprintf("UserEnergy Query [GraphQL] %s ", substr($queryData,0,230)));
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


    public function ExtractValues(DateTime $dateTime, object $jsonData, bool $addValuesToArchiv=false) {

        $returnValue = true;

        if($this->ReadPropertyBoolean("cb_UserEnergyConsumption")) {
            if(isset($jsonData->data->userEnergy[0]->data->consumption)) {
                $eg = $jsonData->data->userEnergy[0]->data->consumption->eg;
                $grid = $jsonData->data->userEnergy[0]->data->consumption->grid;
                $total = $jsonData->data->userEnergy[0]->data->consumption->total;
                $percent = $jsonData->data->userEnergy[0]->data->consumption->percent;           
    
                $eg = round($eg/1000, 3);
                $grid = round($grid/1000, 3);
                $total = round($total/1000, 3);
                $percent = round($percent, 2);

                $varId_UserConsumption_EG = $this->GetIDForIdent("user_consumptionEG");
                $varId_UserConsumption_Grid = $this->GetIDForIdent("user_consumptionGrid");
                $varId_UserConsumption_Total = $this->GetIDForIdent("user_consumptionTotal");
                $varId_UserConsumption_Percent = $this->GetIDForIdent("user_consumptionPercent");                
    
                if($addValuesToArchiv) {
                    $this->AddLoggedValue($varId_UserConsumption_EG,        $dateTime, $eg);
                    $this->AddLoggedValue($varId_UserConsumption_Grid,      $dateTime, $grid);
                    $this->AddLoggedValue($varId_UserConsumption_Total,     $dateTime, $total);
                    $this->AddLoggedValue($varId_UserConsumption_Percent,   $dateTime, $percent);
                }

                if ($this->logLevel >= LogLevel::TRACE) {
                    $logMsg = sprintf("@%s > EG: %.3f kWh | Grid: %.3f kWh | Total: %.3f kWh | Percent: %.2f", $dateTime->format(DateTime::ATOM), $eg, $grid, $total, $percent);
                    $this->AddLog(__FUNCTION__, sprintf("UserEnergyConsumption: %s", $logMsg));
                }

                //SetValueFloat($varId_UserConsumption_EG,         $eg);
                //SetValueFloat($varId_UserConsumption_Grid,       $grid);
                //SetValueFloat($varId_UserConsumption_Total,      $total);            
                //SetValueFloat($varId_UserConsumption_Percent,    $percent);

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

                $eg = round($eg/1000, 3);
                $grid = round($grid/1000, 3);
                $total = round($total/1000, 3);
                $percent = round($percent, 2);
 
                $varId_User_feedInEG = $this->GetIDForIdent("user_feedInEG");
                $varId_User_feedInGrid = $this->GetIDForIdent("user_feedInGrid");
                $varId_User_feedInTotal = $this->GetIDForIdent("user_feedInTotal");
                $varId_User_feedInPercent = $this->GetIDForIdent("user_feedInPercent");
 
                if($addValuesToArchiv) {
                    $this->AddLoggedValue($varId_User_feedInEG,        $dateTime, $eg);
                    $this->AddLoggedValue($varId_User_feedInGrid,      $dateTime, $grid);
                    $this->AddLoggedValue($varId_User_feedInTotal,     $dateTime, $total);
                    $this->AddLoggedValue($varId_User_feedInPercent,   $dateTime, $percent);
                }
                
                if ($this->logLevel >= LogLevel::TRACE) {
                    $logMsg = sprintf("@%s > EG: %.3f kWh | Grid: %.3f kWh | Total: %.3f kWh | Percent: %.2f", $dateTime->format(DateTime::ATOM), $eg, $grid, $total, $percent);
                    $this->AddLog(__FUNCTION__, sprintf("UserEnergyFeedIn: %s", $logMsg));
                }

                //SetValueFloat($varId_User_feedInEG,         $eg);
                //SetValueFloat($varId_User_feedInGrid,       $grid);
                //SetValueFloat($varId_User_feedInTotal,      $total);            
                //SetValueFloat($varId_User_feedInPercent,    $percent);
 
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

            $co2Reduced = round($co2Reduced, 2);
            $costsSaved = round($costsSaved, 8);
            $feedInYield = round($feedInYield, 8);

            $varId_User_co2Reduced = $this->GetIDForIdent("user_co2Reduced");
            $varId_User_costsSaved = $this->GetIDForIdent("user_costsSaved");
            $varId_User_feedInYield = $this->GetIDForIdent("user_feedInYield");

            if($addValuesToArchiv) {
                $this->AddLoggedValue($varId_User_co2Reduced,    $dateTime, $co2Reduced);
                $this->AddLoggedValue($varId_User_costsSaved,    $dateTime, $costsSaved);
                $this->AddLoggedValue($varId_User_feedInYield,   $dateTime, $feedInYield);
            }

            if ($this->logLevel >= LogLevel::TRACE) {
                $logMsg = sprintf("@%s > CO2Reduced: %.2f kg | CostSaved: %.3f € | FeedInYield: %.3f €", $dateTime->format(DateTime::ATOM), $co2Reduced, $costsSaved, $feedInYield);
                $this->AddLog(__FUNCTION__, sprintf("UserEnergy: %s", $logMsg));
            }


            //SetValueFloat($varId_User_co2Reduced,   $co2Reduced);
            //SetValueFloat($varId_User_costsSaved,   $costsSaved);
            //SetValueFloat($varId_User_feedInYield,  $feedInYield);

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
                
                $energyPrice = round($energyPrice, 2);
                $totalMembers = round($totalMembers);

                if($dateTime < new DateTime('01.04.2024')) {
                    $energyPrice = 18;
                } elseif ($dateTime < new DateTime('01.10.2024')) {
                    $energyPrice = 13;
                }

                $varId_EG_EnergyPrice = $this->GetIDForIdent("eg_energyPrice");
                $varId_EG_TotalMembers = $this->GetIDForIdent("eg_totalMembers");

                if($addValuesToArchiv) {
                    $this->AddLoggedValue($varId_EG_EnergyPrice,   $dateTime, $energyPrice);
                    $this->AddLoggedValue($varId_EG_TotalMembers,  $dateTime, $totalMembers);
                }

                if ($this->logLevel >= LogLevel::TRACE) {
                    $logMsg = sprintf("@%s > EnergyPrice: %.2f Cent/kWh | TotalMembers: %d", $dateTime->format(DateTime::ATOM), $energyPrice, $totalMembers);
                    $this->AddLog(__FUNCTION__, sprintf("EnergyCommunityInfos: %s", $logMsg));
                }

                //SetValue($varId_EG_EnergyPrice,     $energyPrice);
                //SetValue($varId_EG_TotalMembers,    $totalMembers);

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
