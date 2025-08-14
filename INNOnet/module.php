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
		$this->RegisterPropertyString("queryStart_InitTS", "{\"day\":1,\"month\":8,\"year\":2025,\"hour\":0,\"minute\":0,\"second\":0}");

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
			$this->SetTimerInterval("TimerSelectedData_INNOnet", $interval);

		} else {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Disable 'TimerSelectedData_INNOnet' for '%s' [Interval=0}", $this->InstanceID)); }
			$this->SetTimerInterval("TimerSelectedData_INNOnet", 0);
		}			

	}


	public function TimerTariffSignal_INNOnet() {
		if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'TimerTariffSignal_INNOnet' called -> Update API Data 'TariffSignal' ..."); }

		$tariffSignal_AutoUpdate = $this->ReadPropertyBoolean("cb_TariffSignal_EnableAutoUpdate");
		if ($tariffSignal_AutoUpdate) {
					
			$result = $this->TariffSignal_TimeSeriesCollection_UPDATE(__FUNCTION__, true);
			
			if($result < 0) {
				if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, "WARN: 'TariffSignal_TimeSeriesCollection_UPDATE' faild > try again in an hour"); }
				$this->SetTimerTariffSignal(3600);
			} else if ($result > 0) {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'TariffSignal_TimeSeriesCollection_UPDATE' > More Data available"); }
				$this->SetTimerTariffSignal(4);
			} else {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'TariffSignal_TimeSeriesCollection_UPDATE' > No More Data available"); }
				$this->SetTimerTariffSignal($this->tariffSignal_TimerInterval);
			}

		} else {
			if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("TariffSignal_EnableAutoUpdate Disabled -> SetTimerTariffSignal to default [%s sec]", $this->tariffSignal_TimerInterval)); }
			$this->SetTimerTariffSignal($this->tariffSignal_TimerInterval);
		}

	}

	public function TimerSelectedData_INNOnet() {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "TimerSelectedData_INNOnet called -> Update API Data 'SelectedData' ..."); }
		
		$autoUpdate = $this->ReadPropertyBoolean("cb_SelectedData_EnableAutoUpdate");
		if($autoUpdate) {

			$result = $this->SelectedData_TimeSeriesCollection_UPDATE(__FUNCTION__, true);
			
			if($result < 0) {
				if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, "WARN: 'SelectedData_TimeSeriesCollection_UPDATE' faild > try again in an hour"); }
				$this->SetTimerSelectedData(3600, 0);
			} else if ($result > 0) {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'SelectedData_TimeSeriesCollection_UPDATE' > More Data available"); }
				$this->SetTimerSelectedData(4, 0);
			} else {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "'SelectedData_TimeSeriesCollection_UPDATE' > No More Data available"); }
				$this->SetTimerSelectedData(0, $this->selectedData_SecondsAferMidnight);
			}
		} else {
			if ($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("SelectedData_EnableAutoUpdate Disabled -> SetTimerSelectedData to default [%s sec]", $this->selectedData_SecondsAferMidnight)); }
			$this->SetTimerSelectedData(0, $this->selectedData_SecondsAferMidnight);
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

		if($startDateTime < 3600) {
			SetValue($this->GetIDForIdent("queryStartTS_TariffSignal"), 0);
			SetValue($this->GetIDForIdent("queryEndTS_TariffSignal"), 0);
			SetValue($this->GetIDForIdent("queryStartTS_SelectedData"), 0);
			SetValue($this->GetIDForIdent("queryEndTS_SelectedData"), 0);
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

		SetValue($this->GetIDForIdent("httpDuration"), 0);
		SetValue($this->GetIDForIdent("httpStatus200"), 0);
		SetValue($this->GetIDForIdent("httpStatus400"), 0);
		SetValue($this->GetIDForIdent("httpStatusOther"), 0);
		SetValue($this->GetIDForIdent("RequestApiExeption"), 0);

		SetValue($this->GetIDForIdent("errorCnt"), 0);
		SetValue($this->GetIDForIdent("lastError"), "-");
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), 0);

	}



	protected function RegisterProfiles() {

		if (!IPS_VariableProfileExists('INNOnet.Cent.4')) {
			IPS_CreateVariableProfile('INNOnet.Cent.4', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('INNOnet.Cent.4', 4);
			IPS_SetVariableProfileText('INNOnet.Cent.4', "", " ct/kWh");
			//IPS_SetVariableProfileValues('INNOnet.Cent.4', 0, 0, 0.01);
		}

		if (!IPS_VariableProfileExists('INNOnet.kWh')) {
			IPS_CreateVariableProfile('INNOnet.kWh', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('INNOnet.kWh', 3);
			IPS_SetVariableProfileText('INNOnet.kWh', "", " kWh");
			//IPS_SetVariableProfileValues('INNOnet.kWh', 0, 0, 0.01);
		}

		if (!IPS_VariableProfileExists('INNOnet.Flag')) {
			IPS_CreateVariableProfile('INNOnet.Flag', VARIABLE::TYPE_INTEGER);
			IPS_SetVariableProfileText('INNOnet.Flag', "", "");
			IPS_SetVariableProfileValues('INNOnet.Flag', 0, 22, 1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 0, "[%d] NoValue	", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 1, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 2, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 3, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 4, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 5, "[%d] Manually Replaced", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 6, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 7, "[%d] Faulty (fehlerhaft) ", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 8, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 9, "[%d] Valid (gültig)", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 10, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 11, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 12, "[%d] Schedule (Fahrplan)", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 13, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 14, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 15, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 16, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 17, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 18, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 19, "[%d] Missing (fehlt)", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 20, "[%d] Accounted (abgerechnet)", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 21, "[%d] Estimated (geschätzt)	", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 22, "[%d] Interpolated (interpoliert)", "", -1);
			IPS_SetVariableProfileAssociation ('INNOnet.Flag', 23, "[%d] n.a.", "", -1);						
		}

		if (!IPS_VariableProfileExists('Milliseconds.2')) {
			IPS_CreateVariableProfile('Milliseconds.2', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('Milliseconds.2', 2);
			IPS_SetVariableProfileText('Milliseconds.2', "", " ms");
		}

		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Profiles registered");
		}
	}

	protected function RegisterVariables() {

		$this->RegisterVariableInteger("queryStartTS_TariffSignal", "TariffSignal Query-Start", "~UnixTimestamp", 100);
		$this->RegisterVariableInteger("queryEndTS_TariffSignal", "TariffSignal Query-End", "~UnixTimestamp", 101);


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
			$var_Id = $this->RegisterVariableInteger("tariffSignal_Flag", "Tariff Signal Flag", "INNOnet.Flag", 111);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
			$var_Id = $this->RegisterVariableInteger("tariffSignal_Flag2", "Tariff Signal Flag -48h", "INNOnet.Flag", 121);
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

		$this->RegisterVariableInteger("queryStartTS_SelectedData", "SelectedData Query-Start", "~UnixTimestamp", 200);
		$this->RegisterVariableInteger("queryEndTS_SelectedData", "SelectedData Query-End", "~UnixTimestamp", 201);

		if($parse_INNOnetTariff) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_userEnergyConsumption, "Meine Energie - Bezug", $categorieRoodId, 100, "");
			$var_Id = $this->RegisterVariableFloat("selData_INNOnetTariff", "INNOnet Tariff", "INNOnet.Cent.4", 210);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}	

		if($parse_aWATTarEnergy) {
			$var_Id = $this->RegisterVariableFloat("selData_aWATTarMarketprice", "aWATTar Marktpreis", "INNOnet.Cent.4", 220);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}

		if($parse_aWATTarFee) {
			$var_Id = $this->RegisterVariableFloat("selData_aWATTarFee", "aWATTar Fee", "INNOnet.Cent.4", 221);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
		}

		if($parse_aWATTarVat) {
			$var_Id = $this->RegisterVariableFloat("selData_aWATTarVat", "aWATTar Vat", "INNOnet.Cent.4", 222);
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
			$var_Id = $this->RegisterVariableFloat("selData_ObisLieferung", "obis Lieferung", "INNOnet.kWh", 240);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
			$var_Id = $this->RegisterVariableFloat("selData_ObisLieferung_SUM", "obis Lieferung SUM", "INNOnet.kWh", 250);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);
			}			
		}	
		
		if($parse_obisBezug) {
			$var_Id = $this->RegisterVariableFloat("selData_ObisBezug", "obis Bezug", "INNOnet.kWh", 241);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			};
			$var_Id = $this->RegisterVariableFloat("selData_ObisBezug_SUM", "obis Bezug SUM", "INNOnet.kWh", 251);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);
			};

		}	
		
		if($parse_EnergyCommunity) {
			$var_Id = $this->RegisterVariableFloat("selData_ObisEegErzeugung", "obis EEG Erzeugung", "INNOnet.kWh", 242);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
			}
			$var_Id = $this->RegisterVariableFloat("selData_ObisEegErzeugung_Calc", "obis EEG Erzeugung CALC", "INNOnet.kWh", 2243);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);
			}	
			$var_Id = $this->RegisterVariableFloat("selData_ObisEegErzeugung_SUM", "obis EEG Erzeugung SUM", "INNOnet.kWh", 252);
			if(!AC_GetLoggingStatus($this->archivInstanzID, $var_Id)) {	
				AC_SetLoggingStatus($this->archivInstanzID, $var_Id, true); 
				AC_SetAggregationType($this->archivInstanzID, $var_Id, 1);				
			}					
		}			


;
		$this->RegisterVariableInteger("dateTimeQueryTS", "Delete Variable Data from", "~UnixTimestamp", 800);
		
	
		$this->RegisterVariableInteger("TariffSignal_Ok", "TariffSignal Update OK", "", 900);
		$this->RegisterVariableInteger("TariffSignal_NotOk", "TariffSignal Update NOT Ok", "", 901);
		$this->RegisterVariableInteger("TariffSignal_LastUpdate", "TariffSignal LastUpdate", "~UnixTimestamp", 902);

		$this->RegisterVariableInteger("SelectedData_Ok", "SelectedData Update OK", "", 910);
		$this->RegisterVariableInteger("SelectedData_NotOk", "SelectedData Update NOT Ok", "", 911);
		$this->RegisterVariableInteger("SelectedData_LastUpdate", "SelectedData LastUpdate", "~UnixTimestamp", 912);

		$this->RegisterVariableFloat("httpDuration", "HTTP Duration", "Milliseconds.2", 920);
		$this->RegisterVariableInteger("httpStatus200", "HTTP Status 200", "", 921);
		$this->RegisterVariableInteger("httpStatus400", "HTTP Status >= 400", "", 922);
		$this->RegisterVariableInteger("httpStatusOther", "HTTP Status Other", "", 923);
		$this->RegisterVariableInteger("RequestApiExeption", "HTTP Exeption", "", 924);

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
