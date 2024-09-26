<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

trait NEOOM_FUNCTIONS {

    public function QueryUserEnergy(string $caller, DateTime $dtQueryFrom, DateTime $dtQueryTo, string $walletId) {

        $result = true;
        $apiURL = 'https://app-api.neoom.com/graphql';
        $bearerToken = $this->ReadPropertyString("tb_BearerToken");

        $logMsg = sprintf("Request '%s' with Bearer '%s' for '%s - %s'", $apiURL, $bearerToken, $dtQueryFrom->format(DateTime::ATOM), $dtQueryTo->format('c'));

        if ($this->logLevel >= LogLevel::INFO) {
            $this->AddLog(__FUNCTION__, sprintf("QueryUserEnergy [Trigger > %s] ...", $caller));
        }

        $queryData = '{
            "operationName": "UserEnergy",
            "variables": {
                "from": "%DATETIME%",
                "to": "%DATETIME%",
                "walletId": "%WALLED_ID%"
            },
            "query": "query UserEnergy($from: DateTime!, $to: DateTime, $walletId: ID!) {\n  userEnergy(from: $from, to: $to, walletId: $walletId) {\n    object {\n      ...UserEnergyObject\n      __typename\n    }\n    data {\n      ...UserEnergyData\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment UserEnergyObject on Object {\n  id\n  meteringPoints {\n    ...MeteringPoint\n    __typename\n  }\n  energyCommunity {\n    ...EnergyCommunityFragment\n    __typename\n  }\n  wishEnergyCommunity {\n    id\n    name\n    ecType\n    __typename\n  }\n  energyCommunityJoinedMessageHidden\n  name\n  __typename\n}\n\nfragment MeteringPoint on MeteringPoint {\n  id\n  meteringPoint\n  name\n  type\n  energyPrice\n  energySource\n  generationPower\n  storagePower\n  storageCapacityUsable\n  object {\n    id\n    __typename\n  }\n  type\n  __typename\n}\n\nfragment EnergyCommunityFragment on EnergyCommunity {\n  id\n  name\n  ecType\n  hasCustomPlantOperatorContract\n  generatingCountingPointNumber\n  phone\n  email\n  energyPrice\n  totalMembers\n  taxType\n  zvr\n  plantOperator {\n    id\n    name\n    __typename\n  }\n  __typename\n}\n\nfragment UserEnergyData on UserEnergyData {\n  consumption {\n    total\n    eg\n    grid\n    percent\n    __typename\n  }\n  feedIn {\n    total\n    eg\n    grid\n    percent\n    __typename\n  }\n  co2Reduced\n  costsSaved\n  feedInYield\n  __typename\n}"
        }';
    
        $dtQueryAsString = $dtQueryFrom->format(DateTime::ATOM);
        $queryData = str_replace("%DATETIME%", $dtQueryAsString, $queryData);
        $queryData = str_replace("%WALLED_ID%", $walletId, $queryData);

        if ($this->logLevel >= LogLevel::COMMUNICATION) {
            $this->AddLog(__FUNCTION__, sprintf("QueryUserEnergy [Trigger > %s] ...", $queryData));
        }

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 60,
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

        if ($this->logLevel >= LogLevel::COMMUNICATION) {
            $this->AddLog(__FUNCTION__, sprintf("httpResponse [%s]", $httpResponse));
        }

        if ($httpResponse !== false) {
            $jsonResponse = json_decode($httpResponse);

            if (json_last_error() == JSON_ERROR_NONE) {
                $this->SetStatus(102);
                //return isset($jsonResponse['data']) ? $jsonResponse['data'] : false;
                return $jsonResponse;
            } else {
                $this->SetStatus(200);
                return false;
            }
        } else {
            $this->SetStatus(200);
            return false;
        }
    }


}
