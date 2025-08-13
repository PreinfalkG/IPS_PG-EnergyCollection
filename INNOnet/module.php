<?php

declare(strict_types=1);

//date_default_timezone_set('UTC');

require_once __DIR__ . '/../libs/COMMON.php';
require_once __DIR__ . '/INNOnet.php';

class INNOnet extends IPSModule {

	const DUMMY_IDENT_userEnergyConsumption = "userEnergyConsumption";
	const DUMMY_IDENT_userEnergyFeedIn = "userEnergyFeedIn";
	const DUMMY_IDENT_energyCommunity = "energyCommunity";

	use COMMON_FUNCTIONS;
	use INNOnet_FUNCTIONS;

	private $timerIntervalSec = 5;
	private $logLevel = 4;		// WARN = 3 | INFO = 4
	private $logCnt = 0;
	private $enableIPSLogOutput = false;
	private $archivInstanzID;

	public $local_timezone;
	public $utc_timezone; 

	public $tariffSignal_TimerInterval;
	public $selectedData_SecondsAferMidnight;


	public function __construct($InstanceID) {

		parent::__construct($InstanceID);		// Diese Zeile nicht löschen

		$this->archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];

		$this->local_timezone = new DateTimeZone('Europe/Vienna');
		$this->utc_timezone = new DateTimeZone('UTC');

		$this->tariffSignal_TimerInterval = 15*60;
		$this->selectedData_SecondsAferMidnight = 16*3600 + 5;

		$this->logLevel = @$this->ReadPropertyInteger("LogLevel");
		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, sprintf("Log-Level is %d", $this->logLevel));
		}
	}

	public function Create() {
		//Never delete this line!
		parent::Create();

		$logMsg = sprintf("Create Modul '%s [%s]'...", IPS_GetName($this->InstanceID), $this->InstanceID);
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, $logMsg);
		}
		IPS_LogMessage(__CLASS__ . "_" . __FUNCTION__, $logMsg);

		$logMsg = sprintf("KernelRunlevel '%s'", IPS_GetKernelRunlevel());
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, $logMsg);
		}
		IPS_LogMessage(__CLASS__ . "_" . __FUNCTION__, $logMsg);

	
		$this->RegisterPropertyInteger("LogLevel", LogLevel::INFO);

		//$this->RegisterPropertyInteger("sTD_StartDateTime", 0);
		$this->RegisterPropertyString("tb_APIKEY", "48befc1b-37bd-4199-bc02-d841b2048ae4");
		$this->RegisterPropertyString("tb_ZaehlpunktBezug", "AT0031000000000000000000990048744");
		//$this->RegisterPropertyString("dt_QueryInitStartFrom", '{"day":01,"month":08,"year":2025,"hour":0,"minute":0,"second":0}');
		$this->RegisterPropertyString("dt_QueryInitStartFrom", "");

		$this->RegisterPropertyBoolean("cb_TariffSignal_EnableAutoUpdate", false);
		$this->RegisterPropertyInteger("ns_TariffSignal_UpdateInterval", 15);
		$this->RegisterPropertyInteger("ns_TariffSignal_OffsetHours", 24);
		$this->RegisterPropertyBoolean("cb_TariffSignal_Value", false);
		$this->RegisterPropertyBoolean("cb_TariffSignal_Flag", false);
		$this->RegisterPropertyBoolean("cb_TariffSignal_CreateSonnenfensterInfos", false);

		$this->RegisterPropertyBoolean("cb_SelectedData_EnableAutoUpdate", false);
		$this->RegisterPropertyInteger("ns_SelectedData_QueryHours", 24);
		$this->RegisterPropertyInteger("ns_SelectedDataQuery_OffsetDaysUntilNow", 2);
		$this->RegisterPropertyBoolean("cb_SelectedData_InnonetTarif", false);
		$this->RegisterPropertyBoolean("cb_SelectedData_aWATTarEnergy", false);
		$this->RegisterPropertyBoolean("cb_SelectedData_aWATTarFee", false);		
		$this->RegisterPropertyBoolean("cb_SelectedData_aWATTarVat", false);	
		$this->RegisterPropertyBoolean("cb_SelectedData_TariffSignal", false);	
		$this->RegisterPropertyBoolean("cb_SelectedData_obisLieferung", false);	
		$this->RegisterPropertyBoolean("cb_SelectedData_obisBezug", false);	
		$this->RegisterPropertyBoolean("cb_SelectedData_obisEnergyCommunity", false);	

		$this->RegisterTimer('TimerTariffSignal_INNOnet', 0, 'INNOnet_TimerTariffSignal_INNOnet('.$this->InstanceID.');');
		$this->RegisterTimer('TimerSelectedData_INNOnet', 0, 'INNOnet_TimerSelectedData_INNOnet($_IPS["TARGET"]);');

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
	}


	public function Destroy() {
		IPS_LogMessage(__CLASS__ . "_" . __FUNCTION__, sprintf("Destroy Modul '%s' ...", $this->InstanceID));
		parent::Destroy();						//Never delete this line!
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		$logMsg = sprintf("TimeStamp: %s | SenderID: %s | Message: %d | Data: %s", $TimeStamp, $SenderID, $Message, json_encode($Data));
		$this->AddLog(__FUNCTION__, $logMsg, 0, true); 

		if($Message == IPS_KERNELMESSAGE) {
			if ($Data[0] == KR_READY ) {
				$this->AddLog(__FUNCTION__, "Set Initial Interval for 'TimerTariffSignal_INNOnet' to 60 Sec", 0, true); 
				$this->SetTimerTariffSignal(60);
				$this->AddLog(__FUNCTION__, "Set Initial Interval for 'TimerSelectedData_INNOnet' to 300 Sec", 0, true); 
				$this->SetTimerSelectedData(300, 0);
			}
		}
	}


	public function ApplyChanges() {

		parent::ApplyChanges();		//Never delete this line!

		$this->logLevel = $this->ReadPropertyInteger("LogLevel");
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("Set Log-Level to %d", $this->logLevel));
		}

		$this->RegisterProfiles();
		$this->RegisterVariables();

		$this->SetTimerTariffSignal(5);
		$this->SetTimerSelectedData(30, 0);
	}


	public function SetTimerTariffSignal(int $timerInterval) {

		if ($timerInterval < 1) {
			if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'TimerTariffSignal_INNOnet' stopped [TimerIntervall = 0]"); }
			$this->SetTimerInterval("TimerTariffSignal_INNOnet", 0);
		} else {
			if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set 'TimerTariffSignal_INNOnet' Interval to %s sec", $timerInterval)); }
			$this->SetTimerInterval("TimerTariffSignal_INNOnet", $timerInterval * 1000);
		}
	}
	
	public function SetTimerSelectedData(int $timerNextInterval=0, int $secondsAferMidnight=0) {

		if($timerNextInterval > 0) {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set 'TimerSelectedData_INNOnet' for '%s' to %s sec", $this->InstanceID, $timerNextInterval)); }
			$this->SetTimerInterval("TimerSelectedData_INNOnet", $timerNextInterval*1000);
		} else if($secondsAferMidnight >= 0) {
			$timerDateTime = 0;
			$seconds_since_midnight  = time() - strtotime('today midnight');

			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("seconds_since_midnight: %s | secondsAferMidnight: %s", $seconds_since_midnight, $secondsAferMidnight)); }

			if($seconds_since_midnight > $secondsAferMidnight) {
				$timerDateTime = strtotime('tomorrow midnight') + $secondsAferMidnight;
			} else {
				$timerDateTime = strtotime('today midnight') + $secondsAferMidnight;
			}
		
			$diff = $timerDateTime - time();
			$interval = $diff * 1000;	
			$logMsg = sprintf("Set 'TimerSelectedData_INNOnet' for '%s' to %s [Interval: %s ms]", $this->InstanceID, date('d.m.Y H:i:s', $timerDateTime), $interval);
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg); }
			IPS_LogMessage(__CLASS__."_".__FUNCTION__, $logMsg);			
			$this->SetTimerInterval("TimerSelectedData_INNOnet", $interval);

		} else {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Disable 'TimerSelectedData_INNOnet' for '%s' [Interval=0}", $this->InstanceID)); }
			$this->SetTimerInterval("TimerSelectedData_INNOnet", 0);
		}			

	}


	public function TimerTariffSignal_INNOnet() {
		if ($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "'TimerTariffSignal_INNOnet' called -> Update API Data 'TariffSignal' ...", 0 , true); }

		$tariffSignal_AutoUpdate = $this->ReadPropertyBoolean("cb_TariffSignal_EnableAutoUpdate");
		if ($tariffSignal_AutoUpdate) {
					
			$result = $this->TimeSeriesCollection_TariffSignal_UPDATE(__FUNCTION__, true);
			
			if($result < 0) {
				if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, "WARN: 'TimeSeriesCollection_TariffSignal_UPDATE' faild > try again in an hour"); }
				$this->SetTimerTariffSignal(3600);
			} else if ($result > 0) {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'TimeSeriesCollection_TariffSignal_UPDATE' > More Data available"); }
				$this->SetTimerTariffSignal(4);
			} else {
				if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "'TimeSeriesCollection_TariffSignal_UPDATE' > No More Data available"); }
				$this->SetTimerTariffSignal($this->tariffSignal_TimerInterval);
			}

		} else {
			if ($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("TariffSignal_EnableAutoUpdate Disabled -> SetTimerTariffSignal to default [%s sec]", $this->tariffSignal_TimerInterval)); }
			$this->SetTimerTariffSignal($this->tariffSignal_TimerInterval);
		}

	}

	public function TimerSelectedData_INNOnet() {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "TimerSelectedData_INNOnet called -> Update API Data 'SelectedData' ..."); }
		
		$autoUpdate = $this->ReadPropertyBoolean("cb_SelectedData_EnableAutoUpdate");
		if($autoUpdate) {

			$result = $this->TimeSeriesCollection_SelectedData_UPDATE(__FUNCTION__, true);
			
			if($result < 0) {
				if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, "WARN: 'TimeSeriesCollection_SelectedData_UPDATE' faild > try again in an hour"); }
				$this->SetTimerSelectedData(3600, 0);
			} else if ($result > 0) {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'TimeSeriesCollection_SelectedData_UPDATE' > More Data available"); }
				$this->SetTimerSelectedData(4, 0);
			} else {
				if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "'TimeSeriesCollection_SelectedData_UPDATE' > No More Data available"); }
				$this->SetTimerSelectedData(0, $this->selectedData_SecondsAferMidnight);
			}
		} else {
			if ($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("SelectedData_EnableAutoUpdate Disabled -> SetTimerSelectedData to default [%s sec]", $this->selectedData_SecondsAferMidnight)); }
			$this->SetTimerSelectedData(0, $this->selectedData_SecondsAferMidnight);
		}
	}

	public function TimeSeriesCollection_TariffSignal_UPDATE(string $caller = '?', bool $addValuesToArchiv=false){
		
		$returnValue = -1;

		$dateTimeFrom =	GetValueInteger($this->GetIDForIdent("tariffSignal_QueryStartTS"));
		if($dateTimeFrom < 3600) {

	        $jsonString = $this->ReadPropertyString('dt_QueryInitStartFrom');
	        $dateTimeData = json_decode($jsonString, true);

			if ($dateTimeData && $dateTimeData['year'] > 0) {
				// Unix-Timestamp erstellen
				$dateTimeFrom = mktime( $dateTimeData['hour'], $dateTimeData['minute'], $dateTimeData['second'], $dateTimeData['month'], $dateTimeData['day'], $dateTimeData['year'] );
				SetValueInteger($this->GetIDForIdent("tariffSignal_QueryStartTS"), $dateTimeFrom);
			}
		} 

		$queryFrom = new DateTimeImmutable('@' . $dateTimeFrom);
		$queryFrom->setTimezone(new DateTimeZone('Europe/Vienna'));

		$queryHours = $this->ReadPropertyInteger("ns_TariffSignal_OffsetHours");
		$queryTo = $queryFrom->add(new DateInterval(sprintf('PT%sH', $queryHours)));

		//$numberOfDaysBack = $this->ReadPropertyInteger("ns_SelectedDataQuery_OffsetDaysUntilNow");
		//$modifier = sprintf("-%d days", $numberOfDaysBack);
		//$queryExitDate = (new DateTime())->modify($modifier);

		$queryExitDate = new DateTime('tomorrow +1 day midnight');

		if($queryTo >= $queryExitDate) {
			$this->SetTimerSelectedData(0, 16*3600); 
			$this->AddLog(__FUNCTION__, "END :: queryTo >= queryExitDate", 0, true);
			$returnValue = 0;
		} else {

			if ($this->logLevel >= LogLevel::DEBUG) {
				$this->AddLog(__FUNCTION__, sprintf("TariffSignal API Request [Trigger > %s]\n - QueryFrom [ATOM]: %s\n - QueryTo [ATOM]: %s [queryExitDate: %s]\n", $caller, $queryFrom->format(DateTime::ATOM), $queryTo->format(DateTime::ATOM), $queryExitDate->format(DateTime::ATOM)));
			} 

			$result = $this->TimeSeriesCollection_TariffSignal($queryFrom, $queryTo, __FUNCTION__, $addValuesToArchiv);

			if($result === true) {
				$this->IncreaseCounterVar("TariffSignal_Ok");
				//SetValueInteger($this->GetIDForIdent("tariffSignal_QueryStartTS"), $queryTo->getTimestamp());
				$returnValue = 1;
			} else {
				$this->IncreaseCounterVar("TariffSignal_NotOk");
				$returnValue = -1;
			}	

		}
		return $returnValue;

		
		return 0;
	}

	public function TimeSeriesCollection_TariffSignal(?DateTimeImmutable $fromDateTime=null, ?DateTimeImmutable $toDateTime=null, string $caller = '?', bool $addValuesToArchiv=false) {

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
							$lastDataRecordTS = $from->getTimestamp()+1800;
						}

                        $fromWithOffset = $from->modify('-2 days');
                        if($fromWithOffset < $dateTimeNow) {
                            $arrIpsArchiv_ValueWithOffset[] = ['TimeStamp' => $fromWithOffset->getTimestamp(), 'Value' => $value];
                            $arrIpsArchiv_FlagWithOffset[] = ['TimeStamp' => $fromWithOffset->getTimestamp(), 'Value' => $flag];
                        } else {
							if($this->logLevel >= LogLevel::WARN) {  $this->AddLog(__FUNCTION__, sprintf("WARN: Skip Adding 'AC_AddLoggedValues' (From: %s > NOW)", $fromWithOffset->format('Y-m-d H:i:s T'))); }
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
									if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv_Value), $varIdent, $varId)); }
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
					if($lastDataRecordTS > 0) {
						SetValueInteger($this->GetIDForIdent("tariffSignal_QueryStartTS"), $lastDataRecordTS);
					}

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



	public function TimeSeriesCollection_SelectedData_UPDATE(string $caller = '?', bool $addValuesToArchiv=false){

		$returnValue = -1;

		$dateTimeFrom =	GetValueInteger($this->GetIDForIdent("selData_QueryStartTS"));
		if($dateTimeFrom < 3600) {

	        $jsonString = $this->ReadPropertyString('dt_QueryInitStartFrom');
	        $dateTimeData = json_decode($jsonString, true);

			if ($dateTimeData && $dateTimeData['year'] > 0) {
				// Unix-Timestamp erstellen
				$dateTimeFrom = mktime( $dateTimeData['hour'], $dateTimeData['minute'], $dateTimeData['second'], $dateTimeData['month'], $dateTimeData['day'], $dateTimeData['year'] );
				SetValueInteger($this->GetIDForIdent("selData_QueryStartTS"), $dateTimeFrom);
			}
		} 

		$queryFrom = new DateTimeImmutable('@' . $dateTimeFrom);
		$queryFrom->setTimezone(new DateTimeZone('Europe/Vienna'));

		$queryHours = $this->ReadPropertyInteger("ns_SelectedData_QueryHours");
		$queryTo = $queryFrom->add(new DateInterval(sprintf('PT%sH', $queryHours)));

		$numberOfDaysBack = $this->ReadPropertyInteger("ns_SelectedDataQuery_OffsetDaysUntilNow");
		$modifier = sprintf("-%d days", $numberOfDaysBack);
		$queryExitDate = (new DateTime())->modify($modifier);


		if($queryTo >= $queryExitDate) {
			$this->SetTimerSelectedData(0, 16*3600); 
			$this->AddLog(__FUNCTION__, "END :: queryTo >= queryExitDate", 0, true);
			$returnValue = 0;
		} else {

			if ($this->logLevel >= LogLevel::DEBUG) {
				$this->AddLog(__FUNCTION__, sprintf("Selected-Data API Request [Trigger > %s]\n - QueryFrom [ATOM]: %s\n - QueryTo [ATOM]: %s [modifier: %s | queryExitDate: %s]\n", $caller, $queryFrom->format(DateTime::ATOM), $queryTo->format(DateTime::ATOM), $modifier, $queryExitDate->format(DateTime::ATOM)));
			} 

			$result = $this->TimeSeriesCollection_SelectedData($queryFrom, $queryTo, __FUNCTION__, $addValuesToArchiv);

			if($result === true) {
				$this->IncreaseCounterVar("SelectedData_Ok");
				SetValueInteger($this->GetIDForIdent("selData_QueryStartTS"), $queryTo->getTimestamp());
				$returnValue = 1;
			} else {
				$this->IncreaseCounterVar("SelectedData_NotOk");
				$returnValue = -1;
			}

		}
		return $returnValue;
	}

	public function TimeSeriesCollection_SelectedData(?DateTimeImmutable $fromDateTime=null, ?DateTimeImmutable $toDateTime=null, string $caller = '?', bool $addValuesToArchiv=false) {

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
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $INNOnetTariff, "selData_INNOnetTariff", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $INNOnetTariff)); } }
						} else if(str_ends_with($elemName, $aWATTar_Marketprice)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_aWATTarEnergy")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $aWATTar_Marketprice, "selData_aWATTarMarketprice", 100, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $aWATTar_Marketprice)); } }
						} else if(str_ends_with($elemName, $aWATTar_Fee)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_aWATTarFee")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $aWATTar_Fee, "selData_aWATTarFee", 100, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $aWATTar_Fee)); } }
						} else if(str_ends_with($elemName, $aWATTar_Vat)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_aWATTarVat")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $aWATTar_Vat, "selData_aWATTarVat", 100, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $aWATTar_Vat)); } }
						} else if(str_starts_with($elemName, $INNOnetTariffSignal)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_TariffSignal")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $INNOnetTariffSignal, "selData_TariffSignal", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $INNOnetTariffSignal)); } }
						} else if(str_ends_with($elemName, $obis_Lieferung)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_obisLieferung")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $obis_Lieferung, "selData_ObisLieferung", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $obis_Lieferung)); } }
						} else if(str_ends_with($elemName, $obis_Bezug)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_obisBezug")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $obis_Bezug, "selData_ObisBezug", 1, $addValuesToArchiv);
							} else { if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ExtractValues for '%s' is disabled", $obis_Bezug)); } }							
						} else if(str_ends_with($elemName, $obisEEG_Erzeugung)) {
							if($this->ReadPropertyBoolean("cb_SelectedData_obisEnergyCommunity")) {
								$this->TimeSeriesCollection_SelectedData_ExtractValues($dataArrElem, $obisEEG_Erzeugung, "selData_ObisEegErzeugung", 1, $addValuesToArchiv);
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

	public function TimeSeriesCollection_SelectedData_ExtractValues(array $dataArrElem, string $elemNameShort, string $varIdent, float $factor, bool $addValuesToArchiv = false)	{
		
		$arrIpsArchiv = [];

		$varId = $this->GetIDForIdent($varIdent);
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

			}

			if ($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ArchivId: %s | VarName: %s | DataCnt: %s | ElemNameShort: %s", $this->archivInstanzID, IPS_GetName($varId), count($arrIpsArchiv), $elemNameShort)); }
			if($addValuesToArchiv) { 
				$result = AC_AddLoggedValues($this->archivInstanzID, $varId, $arrIpsArchiv); 
				if($result) {
					if($this->logLevel >= LogLevel::INFO) {  $this->AddLog(__FUNCTION__, sprintf("AC_AddLoggedValues %d Entries added for VarIdent '%s' [%d]", count($arrIpsArchiv), $varIdent, $varId)); }
				} else {
					if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("ERROR on AC_AddLoggedValues for VarIdent '%s'", $varIdent)); }
				}
			
			} else {
				if($this->logLevel >= LogLevel::ERROR) {  $this->AddLog(__FUNCTION__, sprintf("AddValuesToArchiv for '%s' disabled", $varIdent)); }
			}
		} else {
			if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Variable with Ident '%s' not found in '%s'", $varIdent, $elemNameShort)); }			
		}


	}

	public function DeleteVariableData(string $caller = '?') {

		$startDateTime = GetValueInteger($this->GetIDForIdent("dateTimeQueryTS"));
		if ($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("DELETE Variable Data from %s until NOW", date('d.m.Y H:i:s', $startDateTime))); }
		$childrenIDs = IPS_GetChildrenIDs($this->InstanceID);
		foreach($childrenIDs as $varId) {
			$loggingStatus = AC_GetLoggingStatus($this->archivInstanzID, $varId);
			if($loggingStatus) {
				$location = IPS_GetLocation($varId);
				if (str_contains($location, "INNOnet")) {
					$deletedCnt = AC_DeleteVariableData($this->archivInstanzID, $varId, $startDateTime, 0);
					//$deletedCnt = -1;
					IPS_Sleep(25);
					AC_SetLoggingStatus($this->archivInstanzID, $varId, true);
					if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("%s Datensätze gelöscht: %d [%s] ",  $deletedCnt, $varId, IPS_GetName($varId))); }
				} else {
					if ($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Datensatz Löschung übersprungen > Variablen Location nicht eindeutig [%s] ",  $location)); }
				}
			}
		}
	}

	public function ReAggregateVariables(string $caller = '?') {
		$childrenIDs = IPS_GetChildrenIDs($this->InstanceID);
		foreach($childrenIDs as $varId) {
			
			$loggingStatus = AC_GetLoggingStatus($this->archivInstanzID, $varId);
			if($loggingStatus) {
				AC_ReAggregateVariable($this->archivInstanzID, $varId);
				IPS_Sleep(25);

				if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("ReAggregateVariable: %d [%s]] ...", $varId, IPS_GetName($varId))); }
			}
		}
	}

	public function ResetCounterVariables(string $caller = '?') {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("ResetCounterVariables [Trigger > %s] ...", $caller));
		}

		SetValue($this->GetIDForIdent("dateTimeQueryTS"), 0);

		SetValue($this->GetIDForIdent("TariffSignal_Ok"), 0);
		SetValue($this->GetIDForIdent("TariffSignal_NotOk"), 0);
		SetValue($this->GetIDForIdent("SelectedData_Ok"), 0);
		SetValue($this->GetIDForIdent("SelectedData_NotOk"), 0);

		SetValue($this->GetIDForIdent("httpStatus200"), 0);
		SetValue($this->GetIDForIdent("httpStatus400"), 0);
		SetValue($this->GetIDForIdent("httpStatusOther"), 0);
		SetValue($this->GetIDForIdent("RequestApiExeption"), 0);

		SetValue($this->GetIDForIdent("errorCnt"), 0);
		SetValue($this->GetIDForIdent("lastError"), "-");
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), 0);

	}



	protected function RegisterProfiles() {

		if (!IPS_VariableProfileExists('INNOnet_Cent.4')) {
			IPS_CreateVariableProfile('INNOnet_Cent.4', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('INNOnet_Cent.4', 4);
			IPS_SetVariableProfileText('INNOnet_Cent.4', "", " ct/kWh");
			//IPS_SetVariableProfileValues('INNOnet_Cent.4', 0, 0, 0.01);
		}

		if (!IPS_VariableProfileExists('INNOnet_Kwh')) {
			IPS_CreateVariableProfile('INNOnet_Kwh', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('INNOnet_Kwh', 3);
			IPS_SetVariableProfileText('INNOnet_Kwh', "", " kWh");
			//IPS_SetVariableProfileValues('INNOnet_Kwh', 0, 0, 0.01);
		}

		if (!IPS_VariableProfileExists('Percent.2')) {
			IPS_CreateVariableProfile('Percent.2', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('Percent.2', 2);
			IPS_SetVariableProfileText('Percent.2', "", " %");
		}
		

		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Profiles registered");
		}
	}

	protected function RegisterVariables() {

		$this->RegisterVariableInteger("tariffSignal_QueryStartTS", "TariffSignal Query-Start-DateTime", "~UnixTimestamp", 100);


		if($this->ReadPropertyBoolean("cb_TariffSignal_Value")) {
			$var_Id = $this->RegisterVariableBoolean("tariffSignal_Value", "Tariff Signal", "", 110);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
			$var_Id = $this->RegisterVariableBoolean("tariffSignal_Value2", "Tariff Signal -48h", "", 120);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}			
		}	
		if($this->ReadPropertyBoolean("cb_TariffSignal_Flag")) {
			$var_Id = $this->RegisterVariableInteger("tariffSignal_Flag", "Tariff Signal Flag", "", 111);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
			$var_Id = $this->RegisterVariableInteger("tariffSignal_Flag2", "Tariff Signal Flag -48h", "", 121);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}			
		}

		if($this->ReadPropertyBoolean("cb_TariffSignal_CreateSonnenfensterInfos")) {
			$var_Id = $this->RegisterVariableInteger("duration_SonnenfesterToday", "Sonnenfenser Heute", "", 130);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}

			$var_Id = $this->RegisterVariableInteger("duration_sonnenfesterTomorrow", "Sonnenfenser Morgen", "", 131);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}

		}	
								


		$parse_INNOnetTariff = $this->ReadPropertyBoolean("cb_SelectedData_InnonetTarif");
		$parse_aWATTarEnergy = $this->ReadPropertyBoolean("cb_SelectedData_aWATTarEnergy");
		$parse_aWATTarFee = $this->ReadPropertyBoolean("cb_SelectedData_aWATTarFee");
		$parse_aWATTarVat = $this->ReadPropertyBoolean("cb_SelectedData_aWATTarVat");
		$parse_TariffSignal = $this->ReadPropertyBoolean("cb_SelectedData_TariffSignal");
		$parse_obisLieferung = $this->ReadPropertyBoolean("cb_SelectedData_obisLieferung");
		$parse_obisBezug = $this->ReadPropertyBoolean("cb_SelectedData_obisBezug");
		$parse_EnergyCommunity = $this->ReadPropertyBoolean("cb_SelectedData_obisEnergyCommunity");

		//$categorieRoodId = IPS_GetParent($this->InstanceID);

		$this->RegisterVariableInteger("selData_QueryStartTS", "SelectedData Query-Start-DateTime", "~UnixTimestamp", 200);

		if($parse_INNOnetTariff) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_userEnergyConsumption, "Meine Energie - Bezug", $categorieRoodId, 100, "");
			$var_Id = $this->RegisterVariableFloat("selData_INNOnetTariff", "INNOnet Tariff", "INNOnet_Cent.4", 210);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}	

		if($parse_aWATTarEnergy) {
			$var_Id = $this->RegisterVariableFloat("selData_aWATTarMarketprice", "aWATTar Marktpreis", "INNOnet_Cent.4", 220);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}

		if($parse_aWATTarFee) {
			$var_Id = $this->RegisterVariableFloat("selData_aWATTarFee", "aWATTar Fee", "INNOnet_Cent.4", 221);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}

		if($parse_aWATTarVat) {
			$var_Id = $this->RegisterVariableFloat("selData_aWATTarVat", "aWATTar Vat", "INNOnet_Cent.4", 222);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}		

		if($parse_TariffSignal) {
			$var_Id = $this->RegisterVariableFloat("selData_TariffSignal", "INNOnet Tariff Signal", "", 230);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}		

		if($parse_obisLieferung) {
			$var_Id = $this->RegisterVariableFloat("selData_ObisLieferung", "obis Lieferung", "INNOnet_Kwh", 240);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);
			}
		}	
		
		if($parse_obisBezug) {
			$var_Id = $this->RegisterVariableFloat("selData_ObisBezug", "obis Bezug", "INNOnet_Kwh", 250);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);
			};
		}	
		
		if($parse_EnergyCommunity) {
			$var_Id = $this->RegisterVariableFloat("selData_ObisEegErzeugung", "obis EEG Erzeugung", "INNOnet_Kwh", 260);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
			$var_Id = $this->RegisterVariableFloat("selData_ObisEegErzeugung_Calc", "obis EEG Erzeugung CALC", "INNOnet_Kwh", 261);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);
			}			
		}			

;
		$this->RegisterVariableInteger("dateTimeQueryTS", "Delete Variable Data from", "~UnixTimestamp", 800);
		
	
		$this->RegisterVariableInteger("TariffSignal_Ok", "TariffSignal Update OK", "", 900);
		$this->RegisterVariableInteger("TariffSignal_NotOk", "TariffSignal Update NOT Ok", "", 901);

		$this->RegisterVariableInteger("SelectedData_Ok", "SelectedData Update OK", "", 910);
		$this->RegisterVariableInteger("SelectedData_NotOk", "SelectedData Update NOT Ok", "", 911);

		$this->RegisterVariableInteger("httpStatus200", "HTTP Status 200", "", 920);
		$this->RegisterVariableInteger("httpStatus400", "HTTP Status >= 400", "", 921);
		$this->RegisterVariableInteger("httpStatusOther", "HTTP Status Other", "", 922);
		$this->RegisterVariableInteger("RequestApiExeption", "HTTP Exeption", "", 923);

		$this->RegisterVariableInteger("errorCnt", "Error Cnt", "", 930);
		$this->RegisterVariableString("lastError", "Last Error", "", 931);
		$this->RegisterVariableInteger("lastErrorTimestamp", "Last Error Timestamp", "~UnixTimestamp", 932);

		IPS_ApplyChanges($this->archivInstanzID);
		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Variables registered");
		}
	}

	public function GetClassInfo() {
		return print_r($this, true);
	}

	protected function IncreaseCounterVar(string $ident) {
		$varId = $this->GetIDForIdent($ident);
		if($varId !== false) {
			SetValueInteger($varId, GetValueInteger($varId) + 1);
		}
		
	}

	protected function HandleError(string $sender, string $msg) {
		$this->IncreaseCounterVar("errorCnt");
		SetValue($this->GetIDForIdent("lastError"), $msg);
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), time());
		$this->AddLog($sender, $msg, 0, true);
	}

	protected function AddLog($name, $daten, $format = 0, $ipsLogOutput=false) {
		$this->logCnt++;
		$logSender = "[" . __CLASS__ . "] - " . $name;
		if ($this->logLevel >= LogLevel::DEBUG) {
			$logSender = sprintf("%02d-T%2d [%s] - %s", $this->logCnt, $_IPS['THREAD'], __CLASS__, $name);
		}
		$this->SendDebug($logSender, $daten, $format);

		if ($this->enableIPSLogOutput or $ipsLogOutput) {
			if ($format == 0) {
				IPS_LogMessage($logSender, $daten);
			} else {
				IPS_LogMessage($logSender, $this->String2Hex($daten));
			}
		}
	}
}
