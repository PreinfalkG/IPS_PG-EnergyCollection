<?php

declare(strict_types=1);


trait INNOnet_FUNCTIONS {


	public function TariffSignal_TimeSeriesCollection_UPDATE(string $caller = '?', bool $addValuesToArchiv=false){
		
		$returnValue = -1;

		$dateTimeFrom =	GetValueInteger($this->GetIDForIdent("queryEndTS_TariffSignal"));
		if($dateTimeFrom < 3600) {

	        $jsonString = $this->ReadPropertyString('queryStart_InitTS');
	        $dateTimeData = json_decode($jsonString, true);

			if ($dateTimeData && $dateTimeData['year'] > 0) {
				// Unix-Timestamp erstellen
				$dateTimeFrom = mktime( $dateTimeData['hour'], $dateTimeData['minute'], $dateTimeData['second'], $dateTimeData['month'], $dateTimeData['day'], $dateTimeData['year'] );
				SetValueInteger($this->GetIDForIdent("queryStartTS_TariffSignal"), $dateTimeFrom);
				SetValueInteger($this->GetIDForIdent("queryEndTS_TariffSignal"), 0);
			}
		} 

		$queryFrom = new DateTimeImmutable('@' . $dateTimeFrom);
		$queryFrom->setTimezone(new DateTimeZone('Europe/Vienna'));

		$queryHours = $this->ReadPropertyInteger("ns_TariffSignal_OffsetHours");
		$queryTo = $queryFrom->add(new DateInterval(sprintf('PT%sH', $queryHours)));

		//$numberOfDaysBack = $this->ReadPropertyInteger("ns_SelectedDataQuery_OffsetDaysUntilNow");
		//$modifier = sprintf("-%d days", $numberOfDaysBack);
		//$queryExitDate = (new DateTime())->modify($modifier);

		$dateTimeNow = new DateTimeImmutable('now', $this->local_timezone);
		//$queryExitDate = new DateTime('tomorrow +1 day midnight');
		//$queryExitDate->setTimezone(new DateTimeZone('Europe/Vienna'));

		if($queryFrom > $dateTimeNow) {
			$queryFrom = new DateTimeImmutable('today midnight');
			$queryFrom->setTimezone(new DateTimeZone('Europe/Vienna'));
			$queryTo = $queryFrom->add(new DateInterval(sprintf('PT%sH', $queryHours)));
			$returnValue = 0;

		} else {
			$returnValue = 1;
		}

		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, sprintf("TariffSignal API Request [Trigger > %s] | QueryFrom: %s | QueryTo: %s | queryHours: %s ", $caller, $queryFrom->format('Y-m-d H:i:s T'), $queryTo->format('Y-m-d H:i:s T'), $queryHours));
		} 

		$result = $this->TariffSignal_TimeSeriesCollection($queryFrom, $queryTo, __FUNCTION__, $addValuesToArchiv);

		if($result === true) {
			$this->IncreaseCounterVar("TariffSignal_Ok");
			if ($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "TariffSignal Update Ok"); };
			SetValueInteger($this->GetIDForIdent("queryStartTS_TariffSignal"), $queryFrom->getTimestamp());
			SetValueInteger($this->GetIDForIdent("queryEndTS_TariffSignal"), $queryTo->getTimestamp());
		} else {
			$this->IncreaseCounterVar("TariffSignal_NotOk");
			if ($this->logLevel >= LogLevel::WARM) { $this->AddLog(__FUNCTION__, "TariffSignal Update NOT Ok"); };
			$returnValue = -1;
		}	


		return $returnValue;
	}

	public function TariffSignal_TimeSeriesCollection(?DateTimeImmutable $fromDateTime=null, ?DateTimeImmutable $toDateTime=null, string $caller = '?', bool $addValuesToArchiv=false) {

		$resultOk = true;

		if(is_null($fromDateTime)) { 
			$fromDateTime =  new DateTimeImmutable('first day of last month');
			$fromDateTime = $fromDateTime->setTimezone($this->local_timezone);
			$fromDateTime = $fromDateTime->setTime(0, 0, 0);
			$toDateTime = $fromDateTime->setTime(23, 59, 59);

			if ($this->logLevel >= LogLevel::TRACE) {
				$this->AddLog(__FUNCTION__, sprintf("fromDateTime: %s | toDateTime: %s", $fromDateTime->format('Y-m-d H:i:s T'), $toDateTime->format('Y-m-d H:i:s T')));
			}
		}

		//$jsonfilePath = IPS_GetKernelDir() . 'logs\INNOnet.json';

		try{
			$apiKey = $this->ReadPropertyString("tb_APIKEY");
			$zaehlpunktBezug = $this->ReadPropertyString("tb_ZaehlpunktBezug");
			$queryFromParameter = $fromDateTime->setTimezone($this->utc_timezone)->format('Y-m-d\TH:i:s\Z');
			$queryToParameter = $toDateTime->setTimezone($this->utc_timezone)->format('Y-m-d\TH:i:s\Z');
			$apiUrlTariffSignal = sprintf("https://app-innonnetwebtsm-dev.azurewebsites.net/api/extensions/timeseriesauthorization/repositories/INNOnet-prod/apikey/%s/timeseries/tariff-signal-%s/data?from=%s&to=%s", $apiKey, $zaehlpunktBezug, $queryFromParameter, $queryToParameter);

			$dataArr = null;
			if(false) {
				if ($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("JSON File Path: %s ", $jsonfilePath)); }
				$json = file_get_contents($jsonfilePath);
				$dataArr = json_decode($json, true); 
				if ($dataArr === null) {
					if ($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, "Fehler beim Dekodieren der JSON-Datei."); }
					$resultOk = false;
				}
			} else {
				$responseData = $this->RequestAPI(__FUNCTION__, $apiUrlTariffSignal);
				if($responseData === false) {
					$resultOk = false;
				} else {
					$dataArr = json_decode($responseData, true); 
					if ($dataArr === null) {
						if ($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, "Fehler beim Dekodieren der JSON-Datei."); }
						$resultOk = false;
					}				
				}
			}

			if($resultOk) {

				if(array_key_exists("Data", $dataArr)) {

					$lastDataRecordTS=0;
					$elemDataCnt = count($dataArr['Data']);
					$this->AddLog(__FUNCTION__, sprintf("'TariffSignal' enthält %d Elemente", $elemDataCnt));

					$arrIpsArchiv_Value = [];
					$arrIpsArchiv_Flag = [];
                    $arrIpsArchiv_ValueWithOffset = [];
                    $arrIpsArchiv_FlagWithOffset = [];    

					$dateTimeNow = new DateTimeImmutable('now', $this->local_timezone);
					$heute_start = new DateTime('today', $this->local_timezone);
        			$morgen_start = new DateTime('tomorrow', $this->local_timezone);
        			$uebermorgen_start = new DateTime('tomorrow +1 day', $this->local_timezone);

					$count_heute = 0;
					$count_morgen = 0;

					foreach($dataArr['Data'] as $dataItem) {
						$from = new DateTimeImmutable($dataItem['From']);
						$from = $from->setTimezone($this->local_timezone);
						$value = boolval($dataItem["Value"]); 
						$flag = intval($dataItem["Flag"]); 

						if($from <= $dateTimeNow) {
							$arrIpsArchiv_Value[] = ['TimeStamp' => $from->getTimestamp(), 'Value' => $value];
							$arrIpsArchiv_Flag[] = ['TimeStamp' => $from->getTimestamp(), 'Value' => $flag];
							$lastDataRecordTS = $from->getTimestamp();
							if($this->logLevel >= LogLevel::TEST) {  $this->AddLog(__FUNCTION__, sprintf("Add Entry | TimeStamp: %s <= NOW: %s ", $from->format('Y-m-d H:i:s T'), $dateTimeNow->format('Y-m-d H:i:s T'))); }
						} else {
							if($this->logLevel >= LogLevel::TEST) {  $this->AddLog(__FUNCTION__, sprintf("Skip Entry | TimeStamp: %s > NOW: %s ", $from->format('Y-m-d H:i:s T'), $dateTimeNow->format('Y-m-d H:i:s T'))); }
						}

                        $fromWithOffset = $from->modify('-2 days');
                        if($fromWithOffset < $dateTimeNow) {
                            $arrIpsArchiv_ValueWithOffset[] = ['TimeStamp' => $fromWithOffset->getTimestamp(), 'Value' => $value];
                            $arrIpsArchiv_FlagWithOffset[] = ['TimeStamp' => $fromWithOffset->getTimestamp(), 'Value' => $flag];
							//if($this->logLevel >= LogLevel::TEST) {  $this->AddLog(__FUNCTION__, sprintf("Add Entry WithOffset | TimeStamp: %s <= NOW: %s ", $fromWithOffset->format('Y-m-d H:i:s T'), $dateTimeNow->format('Y-m-d H:i:s T'))); }
                        } else {
							if($this->logLevel >= LogLevel::WARN) {  $this->AddLog(__FUNCTION__, sprintf("Skip Entry WithOffset | TimeStamp: %s > NOW: %s ", $fromWithOffset->format('Y-m-d H:i:s T'), $dateTimeNow->format('Y-m-d H:i:s T'))); }
                        }

                     	if ($value) {
                            if ($from >= $heute_start && $from < $morgen_start) {
                                $count_heute++;
                            } else if ($from >= $morgen_start && $from < $uebermorgen_start) {
                                $count_morgen++;
                            }
                        }						

					}

					
					if($addValuesToArchiv) { 


						if($this->ReadPropertyBoolean("cb_TariffSignal_Value")) {

							$varIdent = "tariffSignal_Value";
							$varId = $this->GetIDForIdent($varIdent);
							if($varId !== false) {
								$result = AC_AddLoggedValues($this->archivInstanzID, $varId, $arrIpsArchiv_Value); 
								if($result) {
									SetValueInteger($this->GetIDForIdent("TariffSignal_LastUpdate"), time());
									if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv_Value), $varIdent, $varId)); }
								} else {
									if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent)); }
								}
							} else {
								if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Variable with Ident '%s' not found", $varIdent)); }			
							}	
							
							$varIdent = "tariffSignal_Value2";
							$varId = $this->GetIDForIdent($varIdent);
							if($varId !== false) {
								$result = AC_AddLoggedValues($this->archivInstanzID, $varId, $arrIpsArchiv_ValueWithOffset); 
								if($result) {
									if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv_ValueWithOffset), $varIdent, $varId)); }
								} else {
									if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent)); }
								}
							} else {
								if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Variable with Ident '%s' not found", $varIdent)); }			
							}								

						} else {
							if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, "AddValuesToArchiv disabled"); }
						}

						
						if($this->ReadPropertyBoolean("cb_TariffSignal_Flag")) {
							$varIdent = "tariffSignal_Flag";
							$varId = $this->GetIDForIdent($varIdent);
							if($varId !== false) {
								$result = AC_AddLoggedValues($this->archivInstanzID, $varId, $arrIpsArchiv_Flag); 
								if($result) {
									if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv_Value), $varIdent, $varId)); }
								} else {
									if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent)); }
								}
							} else {
								if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Variable with Ident '%s' not found", $varIdent)); }			
							}

							$varIdent = "tariffSignal_Flag2";
							$varId = $this->GetIDForIdent($varIdent);
							if($varId !== false) {
								$result = AC_AddLoggedValues($this->archivInstanzID, $varId, $arrIpsArchiv_FlagWithOffset); 
								if($result) {
									if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv_FlagWithOffset), $varIdent, $varId)); }
								} else {
									if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent)); }
								}
							} else {
								if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Variable with Ident '%s' not found", $varIdent)); }			
							}

						} else {
							if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, "AddValuesToArchiv disabled"); }
						}

						
						if($this->ReadPropertyBoolean("cb_TariffSignal_CreateSonnenfensterInfos")) {
							SetValueInteger($this->GetIDForIdent("duration_SonnenfesterToday"), $count_heute*1800);  
							SetValueInteger($this->GetIDForIdent("duration_sonnenfesterTomorrow"), $count_morgen*1800);  
						}
					}
					/*
						$lastDataRecord = new DateTimeImmutable('@' . $lastDataRecordTS);
						$lastDataRecord->setTimezone(new DateTimeZone('Europe/Vienna'));

					if($lastDataRecordTS > 0) {
						if($this->logLevel >= LogLevel::TEST) {  
							$this->AddLog(__FUNCTION__, sprintf("Set queryStartTS_TariffSignal to: %s", $lastDataRecord->format('Y-m-d H:i:s T'))); }

						SetValueInteger($this->GetIDForIdent("queryStartTS_TariffSignal"), $lastDataRecordTS);
					}
						*/

				} else {
					if ($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, "DataArr Entry has no 'Data' Element"); }
				}
		
			}


		} catch (Exception $e) {
			$resultOk = false;
			if ($this->logLevel >= LogLevel::ERROR) {
				$this->AddLog(__FUNCTION__,"Ein Fehler ist aufgetreten: " . $e->getMessage());
			}
		}

		return $resultOk;
	}



	public function SelectedData_TimeSeriesCollection_UPDATE(string $caller = '?', bool $addValuesToArchiv=false){

		$returnValue = -1;

		$dateTimeFrom =	GetValueInteger($this->GetIDForIdent("queryEndTS_SelectedData"));
		if($dateTimeFrom < 3600) {

	        $jsonString = $this->ReadPropertyString('queryStart_InitTS');
	        $dateTimeData = json_decode($jsonString, true);

			if ($dateTimeData && $dateTimeData['year'] > 0) {
				// Unix-Timestamp erstellen
				$dateTimeFrom = mktime( $dateTimeData['hour'], $dateTimeData['minute'], $dateTimeData['second'], $dateTimeData['month'], $dateTimeData['day'], $dateTimeData['year'] );
				SetValueInteger($this->GetIDForIdent("queryStartTS_SelectedData"), $dateTimeFrom);
				SetValueInteger($this->GetIDForIdent("queryEndTS_SelectedData"), 0);
			}
		} 

		$queryFrom = new DateTimeImmutable('@' . $dateTimeFrom);
		$queryFrom->setTimezone(new DateTimeZone('Europe/Vienna'));

		$queryHours = $this->ReadPropertyInteger("ns_SelectedData_QueryHours");
		$queryTo = $queryFrom->add(new DateInterval(sprintf('PT%sH', $queryHours)));

		$numberOfDaysBack = $this->ReadPropertyInteger("ns_SelectedDataQuery_OffsetDaysUntilNow");
		$modifier = sprintf("-%d days", $numberOfDaysBack);
		$queryExitDate = (new DateTime())->modify($modifier);


		if($queryTo > $queryExitDate) {
			$this->SetTimerSelectedData(0, 16*3600); 
            if ($this->logLevel >= LogLevel::DEBUG) {
			    //$this->AddLog(__FUNCTION__, "END :: queryTo >= queryExitDate", 0, true);
                $this->AddLog(__FUNCTION__, sprintf("END :: queryFrom '%s' | queryTo '%s > queryExitDate '%s", $queryFrom->format('Y-m-d H:i:s T'), $queryTo->format('Y-m-d H:i:s T'), $queryExitDate->format('Y-m-d H:i:s T')));
            }
			$returnValue = 0;
		} else {

			if ($this->logLevel >= LogLevel::DEBUG) {
				$this->AddLog(__FUNCTION__, sprintf("Selected-Data API Request [Trigger > %s]\n - QueryFrom [ATOM]: %s\n - QueryTo [ATOM]: %s [modifier: %s | queryExitDate: %s]\n", $caller, $queryFrom->format(DateTime::ATOM), $queryTo->format(DateTime::ATOM), $modifier, $queryExitDate->format(DateTime::ATOM)));
			} 

			$result = $this->SelectedData_TimeSeriesCollection($queryFrom, $queryTo, __FUNCTION__, $addValuesToArchiv);

			if($result === true) {
				$this->IncreaseCounterVar("SelectedData_Ok");
				SetValueInteger($this->GetIDForIdent("queryStartTS_SelectedData"), $queryFrom->getTimestamp());
				SetValueInteger($this->GetIDForIdent("queryEndTS_SelectedData"), $queryTo->getTimestamp());
				$returnValue = 1;
			} else {
				$this->IncreaseCounterVar("SelectedData_NotOk");
				$returnValue = -1;
			}

		}
		return $returnValue;
	}

	public function SelectedData_TimeSeriesCollection(?DateTimeImmutable $fromDateTime=null, ?DateTimeImmutable $toDateTime=null, string $caller = '?', bool $addValuesToArchiv=false) {

		$resultOk = true;

		if(is_null($fromDateTime)) { 
			$fromDateTime =  new DateTimeImmutable('first day of last month');
			$fromDateTime = $fromDateTime->setTimezone($this->local_timezone);
			$fromDateTime = $fromDateTime->setTime(0, 0, 0);
			$toDateTime = $fromDateTime->setTime(23, 59, 59);

			if ($this->logLevel >= LogLevel::TRACE) {
				$this->AddLog(__FUNCTION__, sprintf("fromDateTime: %s | toDateTime: %s", $fromDateTime->format('Y-m-d H:i:s T'), $toDateTime->format('Y-m-d H:i:s T')));
			}
		}

		//$jsonfilePath = IPS_GetKernelDir() . 'logs\INNOnet.json';

		try{
			$apiKey = $this->ReadPropertyString("tb_APIKEY");
			$queryFromParameter = $fromDateTime->setTimezone($this->utc_timezone)->format('Y-m-d\TH:i:s\Z');
			$queryToParameter = $toDateTime->setTimezone($this->utc_timezone)->format('Y-m-d\TH:i:s\Z');
			$apiUrlSelectedData = sprintf("https://app-innonnetwebtsm-dev.azurewebsites.net/api/extensions/timeseriesauthorization/repositories/INNOnet-prod/apikey/%s/timeseriescollections/selected-data?from=%s&to=%s", $apiKey, $queryFromParameter, $queryToParameter);

			$dataArr = null;
			if(false) {
				if ($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("JSON File Path: %s ", $jsonfilePath)); }
				$json = file_get_contents($jsonfilePath);
				$dataArr = json_decode($json, true); 
				if ($dataArr === null) {
					if ($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, "Fehler beim Dekodieren der JSON-Datei."); }
					$resultOk = false;
				}
			} else {
				$responseData = $this->RequestAPI(__FUNCTION__, $apiUrlSelectedData);
				if($responseData === false) {
					$resultOk = false;
				} else {

					$dataArr = json_decode($responseData, true); 
					if ($dataArr === null) {
						if ($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, "Fehler beim Dekodieren der JSON-Datei."); }
						$resultOk = false;
					}				

				}

			}

			if($resultOk) {

				$INNOnetTariff = "innonet-tariff";	
				$aWATTar_Marketprice = "AWTAT-tid-HLAT";
				$aWATTar_Fee = "AWTAT-tid-HLAT-Fee";
				$aWATTar_Vat = "AWTAT-tid-HLAT-Vat";
				$INNOnetTariffSignal ="tariff-signal";
				$obis_Lieferung = "obis-Lieferung-Wirkenergie";
				$obis_Bezug = "obis-Bezug-Wirkenergie";
				$obisEEG_Erzeugung = "obis-Gemeinschaftliche-Erzeugung";


				foreach ($dataArr as $dataArrElem) {

					if(array_key_exists('Name', $dataArrElem)) {

						$elemName = $dataArrElem['Name']; 
						$elemDataCnt = count($dataArrElem['Data']['Data']);
						$this->AddLog(__FUNCTION__, sprintf("'%s' enthält %d Elemente", $elemName, $elemDataCnt));

						if(str_starts_with($elemName, $INNOnetTariff)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_InnonetTarif")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $INNOnetTariff, "selData_INNOnetTariff", "", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $INNOnetTariff)); } }
						} else if(str_ends_with($elemName, $aWATTar_Marketprice)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_aWATTarEnergy")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $aWATTar_Marketprice, "selData_aWATTarMarketprice", "", 100, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $aWATTar_Marketprice)); } }
						} else if(str_ends_with($elemName, $aWATTar_Fee)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_aWATTarFee")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $aWATTar_Fee, "selData_aWATTarFee", "", 100, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $aWATTar_Fee)); } }
						} else if(str_ends_with($elemName, $aWATTar_Vat)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_aWATTarVat")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $aWATTar_Vat, "selData_aWATTarVat", "", 100, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $aWATTar_Vat)); } }
						} else if(str_starts_with($elemName, $INNOnetTariffSignal)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_TariffSignal")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $INNOnetTariffSignal, "selData_TariffSignal", "", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $INNOnetTariffSignal)); } }
						} else if(str_ends_with($elemName, $obis_Lieferung)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_obisLieferung")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $obis_Lieferung, "selData_ObisLieferung", "selData_ObisLieferung_SUM", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $obis_Lieferung)); } }
						} else if(str_ends_with($elemName, $obis_Bezug)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_obisBezug")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $obis_Bezug, "selData_ObisBezug", "selData_ObisBezug_SUM", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $obis_Bezug)); } }							
						} else if(str_ends_with($elemName, $obisEEG_Erzeugung)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_obisEnergyCommunity")) {
								$this->SelectedData_TimeSeriesCollection_ExtractValues($dataArrElem, $obisEEG_Erzeugung, "selData_ObisEegErzeugung", "selData_ObisEegErzeugung_SUM", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $obisEEG_Erzeugung)); } }
						}				

					} else {
						if ($this->logLevel >= LogLevel::WARN) {
							$this->AddLog(__FUNCTION__, "DataArr Entry has no 'Name' Element");
						}				
					}


				}
			}


		} catch (Exception $e) {
			$resultOk = false;
			if ($this->logLevel >= LogLevel::ERROR) {
				$this->AddLog(__FUNCTION__,"Ein Fehler ist aufgetreten: " . $e->getMessage());
			}
		}

		return $resultOk;
	}

	public function SelectedData_TimeSeriesCollection_ExtractValues(array $dataArrElem, string $elemNameShort, string $varIdent, string $varIdent2, float $factor, bool $addValuesToArchiv = false)	{
		
		$arrIpsArchiv = [];
		$arrIpsArchiv_SUM = [];

		$varId = $this->GetIDForIdent($varIdent);
		$varId2 = 0;
		if(!empty($varIdent2)) {
			$varId2 = $this->GetIDForIdent($varIdent2);
		}

		$elemCnt = 0;
		$value_SUM = 0;
		if($varId !== false) {
			foreach($dataArrElem['Data']['Data'] as $dataItem) {
				$from = new DateTimeImmutable($dataItem['From']);
				$from = $from->setTimezone($this->local_timezone);
				$value = floatval($dataItem["Value"]); 
				$value = $value * $factor;
			
				if ($this->logLevel >= LogLevel::TRACE) {
					$this->AddLog(__FUNCTION__, sprintf("%s :: %s | %s", $elemNameShort, $from->format('Y-m-d H:i:s T'), $value));
				}	
				
				$arrIpsArchiv[] = ['TimeStamp' => $from->getTimestamp(), 'Value' => $value];

				if($varId2 > 0) {
					$value_SUM = $value_SUM + $value;

					if($elemCnt == 0) {
						$arrIpsArchiv_SUM[] = ['TimeStamp' => $from->getTimestamp()-1, 'Value' => 0];	
					}
					$arrIpsArchiv_SUM[] = ['TimeStamp' => $from->getTimestamp(), 'Value' => $value_SUM];
				}
				$elemCnt++;

			}

			if ($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ArchivId: %s | VarName: %s | DataCnt: %s | ElemNameShort: %s", $this->archivInstanzID, IPS_GetName($varId), count($arrIpsArchiv), $elemNameShort)); }
			if($addValuesToArchiv) { 
				$result = AC_AddLoggedValues($this->archivInstanzID, $varId, $arrIpsArchiv); 			
				if($result) {
					SetValueInteger($this->GetIDForIdent("SelectedData_LastUpdate"), time());
					if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv), $varIdent, $varId)); }
				} else {
					if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent)); }
				}

				if($varId2 > 0) {
					$result = AC_AddLoggedValues($this->archivInstanzID, $varId2, $arrIpsArchiv_SUM); 			
					if($result) {
						if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv), $varIdent2, $varId2)); }
					} else {
						if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent2)); }
					}
				}
			
			} else {
				if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("AddValuesToArchiv for '%s' disabled", $varIdent2)); }
			}
		} else {
			if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Variable with Ident '%s' not found in '%s'", $varIdent2, $elemNameShort)); }			
		}


	}



    public function RequestAPI(string $caller, string $apiURL) {

        $httpResponse = false;

        $apiURL_Query = "n.a.";
        $url_komponenten = parse_url($apiURL);
        if (isset($url_komponenten['query'])) {
            $apiURL_Query = $url_komponenten['query'];
        }

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

            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpStatusCode == 200) {
                $this->IncreaseCounterVar("httpStatus200");
                if ($this->logLevel >= LogLevel::COMMUNICATION) {
                    $this->AddLog(__FUNCTION__, sprintf("OK > httpStatusCode '%s' [ %s ]", $httpStatusCode, $apiURL_Query));
                }
            } else if ($httpStatusCode >= 400) {
                $httpResponse = false;
                $this->IncreaseCounterVar("httpStatus400");
                $errorMsg = sprintf('{ "ERROR" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                $this->HandleError(__FUNCTION__, $errorMsg, 0, true);
            } else {
                $this->IncreaseCounterVar("httpStatusOther");
                $msg = sprintf('{ "WARN" : "httpStatusCode >%s< [%s]" }', $httpStatusCode, $apiURL);
                if ($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, $msg, 0, true); }
            }  

            if ($this->logLevel >= LogLevel::TEST) {
                $this->AddLog(__FUNCTION__, sprintf("httpResponse [%s]", print_r($httpResponse,true)));
            }

        } catch (Exception $e) {
            $httpResponse = false;
            $this->IncreaseCounterVar("RequestApiExeption");
            $errorMsg = sprintf('{ "ERROR" : "Exception > %s [%s] {%s}" }', $e->getMessage(), $e->getCode(), $apiURL);
            $this->HandleError(__FUNCTION__, $errorMsg, 0, true);
        } finally {
            SetValueFloat($this->GetIDForIdent("httpDuration"), $duration);
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
			$this->SetStatus(102);
            return $httpResponse;
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
