<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../libs/COMMON.php';
require_once __DIR__ . '/NEOOM.php';

class Neoom extends IPSModule {

	const DUMMY_IDENT_userEnergyConsumption = "userEnergyConsumption";
	const DUMMY_IDENT_userEnergyFeedIn = "userEnergyFeedIn";
	const DUMMY_IDENT_energyCommunity = "energyCommunity";

	use COMMON_FUNCTIONS;
	use NEOOM_FUNCTIONS;

	private $timerIntervalSec = 5;
	private $logLevel = 4;		// WARN = 3 | INFO = 4
	private $logCnt = 0;
	private $enableIPSLogOutput = false;
	private $archivInstanzID;


	public function __construct($InstanceID) {

		parent::__construct($InstanceID);		// Diese Zeile nicht löschen

		$this->archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];

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

		$this->RegisterPropertyBoolean("EnableAutoUpdate", false);
		$this->RegisterPropertyInteger("LogLevel", LogLevel::INFO);

		//$this->RegisterPropertyInteger("sTD_StartDateTime", 0);
		$this->RegisterPropertyString("tb_BearerToken", "9JOjy207cQil7sw35ScyyF6RuB61xHneJmRJacaM-YY");
		$this->RegisterPropertyString("tb_WalletId", "9197");

		$this->RegisterPropertyInteger("sd_DateFrom", 1698364800);
		$this->RegisterPropertyInteger("ns_QueryOffsetUntilNow", 5);
		$this->RegisterPropertyBoolean("cb_UserEnergyConsumption", false);
		$this->RegisterPropertyBoolean("cb_UserEnergyFeedIn", false);
		$this->RegisterPropertyBoolean("cb_EnergyCommunityInfos", false);		

		$this->RegisterTimer('TimerUpdate_NEOOM', 0, 'NEOOM_TimerUpdate_NEOOM('.$this->InstanceID.');');
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
				$this->AddLog(__FUNCTION__, "Set Initial Interval for 'TimerUpdate_NEOOM' to 30 seconds", 0, true); 
				$this->SetUpdateInterval(5);
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

		$autoUpdate = $this->ReadPropertyBoolean("EnableAutoUpdate");
		if ($autoUpdate) {
			$next_timer = strtotime(date('Y-m-d H:00:10', strtotime('+1 hour')));
			if ($this->logLevel >= LogLevel::INFO) {
				$this->AddLog(__FUNCTION__, sprintf("SET next_timer @%s]", $this->UnixTimestamp2String($next_timer)));
			}
			$this->SetUpdateInterval($this->timerIntervalSec);
		} else {
			$this->SetUpdateInterval(0);
		}
	}


	public function SetUpdateInterval(int $timerInterval) {
		if ($timerInterval < 1) {
			if ($this->logLevel >= LogLevel::INFO) {
				$this->AddLog(__FUNCTION__, "'TimerUpdate_NEOOM' stopped [TimerIntervall = 0]");
			}
		} else {
			if ($this->logLevel >= LogLevel::INFO) {
				$this->AddLog(__FUNCTION__, sprintf("Set 'TimerUpdate_NEOOM' Interval to %s sec", $timerInterval));
			}
		}
		$this->SetTimerInterval("TimerUpdate_NEOOM", $timerInterval * 1000);
	}


	public function TimerUpdate_NEOOM() {

		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, "TimerUpdate_NEOOM called ...", 0 , true);
		}

		$result = $this->RequestNextDay("TimerUpdate_NEOOM");
		if($result === false) {

			if ($this->logLevel >= LogLevel::WARN) {
				$this->AddLog(__FUNCTION__, "Problem 'RequestNextDay()' > SET 'TimerUpdate_NEOOM' to 120 sec for next try", 0, true);
			}
			$this->SetUpdateInterval($this->timerIntervalSec * 10); 

		} else {

			//if ($this->logLevel >= LogLevel::DEBUG) {
				//$this->AddLog(__FUNCTION__, sprintf("SET next_timer @%s]", $this->UnixTimestamp2String($next_timer)));
			//}
			//$this->SetUpdateInterval($this->timerIntervalSec);

		}
	}



	public function RequestNextDay(string $caller = '?') {

		$returnValue = true;
		
		$dateTimeFrom =	GetValueInteger($this->GetIDForIdent("dateTimeQueryTS"));
		$walletId = $this->ReadPropertyString("tb_WalletId");

		$dtQueryFrom = new DateTime('@' . $dateTimeFrom);

		$numberOfDaysBack = $this->ReadPropertyInteger("ns_QueryOffsetUntilNow");
		$modifier = sprintf("-%d days", $numberOfDaysBack);
		$dtQueryTo = (new DateTime())->modify($modifier);
		//$dtQueryTo = (new DateTime())->modify('-7 days');		
		//$dtQueryTo = (new DateTime())->createFromFormat('d/m/Y', '30/10/2023');
		//$dtQueryTo = (new DateTime())->createFromFormat('d/m/Y\\TH:i:s', '30/10/2023T00:00:00');
		//$dtQueryTo = new DateTime('@' . time() - 7*24*3600);

		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, sprintf("API RequestNextDay [Trigger > %s] \n - QueryFrom: %s\n - QueryTo: %s [%s]\n - Wallet ID: %s", $caller, $dtQueryFrom->format(DateTime::ATOM), $dtQueryTo->format(DateTime::ATOM), $modifier, $walletId));
		}
		
		if($dtQueryFrom > $dtQueryTo) {
			$this->HandleError(__FUNCTION__, "dtQueryFrom > dtQueryTo");
			$returnValue = false;
		} else {

			$dtQueryFrom->setTime(2,0,0);

			$dtQueryTo = $dtQueryFrom;
			$result = $this->QueryUserEnergy($caller, $dtQueryFrom, $dtQueryTo, $walletId);

			if ($this->logLevel >= LogLevel::DEBUG) {
                $this->AddLog(__FUNCTION__, sprintf("API Response [%s]", print_r($result,true)));
            }

			if($result === false) {
				$this->HandleError(__FUNCTION__, "RESULT: " . $result);
				$returnValue = false;
			} else {
				$returnValue = $this->ExtractAndAddLoggedValues($dtQueryFrom, $result);
				if($returnValue) {
					$this->Increase_CounterByIdent("updateCntOk");
					$dtQueryFrom->modify('+1 day');
					SetValueInteger($this->GetIDForIdent("dateTimeQueryTS"), $dtQueryFrom->getTimestamp());
				}
			}
		}

		return $returnValue;

	}

	public function SetStartDate(string $caller = '?') {

		SetValueInteger($this->GetIDForIdent("dateTimeQueryTS"), 1698364800);	//EG Start 1698364800 | 27.10.2023
		SetValueString($this->GetIDForIdent("dateTimeQueryInfo"), "-");
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("Set 'DateTime Query' to %s [Trigger > %s]", $caller, date('d.m.Y H:i:s', 1698364800)));
		}
	}


	public function ReAggregateVariables(string $caller = '?') {
		$childrenIDs = IPS_GetChildrenIDs($this->InstanceID);
		foreach($childrenIDs as $varId) {
			
			$loggingStatus = AC_GetLoggingStatus($this->archivInstanzID, $varId);
			if($loggingStatus) {
				AC_ReAggregateVariable($this->archivInstanzID, $varId);
				IPS_Sleep(25);

				if ($this->logLevel >= LogLevel::INFO) {
					$this->AddLog(__FUNCTION__, sprintf("ReAggregateVariable: %d [%s]] ...", $varId, IPS_GetName($varId)));
				}
			}
		}
	}

	public function ResetCounterVariables(string $caller = '?') {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("ResetCounterVariables [Trigger > %s] ...", $caller));
		}

		SetValue($this->GetIDForIdent("updateCntOk"), 0);
		SetValue($this->GetIDForIdent("updateCntNotOk"), 0);
		SetValue($this->GetIDForIdent("errorCnt"), 0);
		SetValue($this->GetIDForIdent("lastError"), "-");
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), 0);
	}




	protected function RegisterProfiles() {

		if (!IPS_VariableProfileExists('CentkWh.2')) {
			IPS_CreateVariableProfile('CentkWh.2', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('CentkWh.2', 3);
			IPS_SetVariableProfileText('CentkWh.2', "", " ct/kWh");
			//IPS_SetVariableProfileValues('CentkWh.3', 0, 100, 1);
		}

		if (!IPS_VariableProfileExists('NEOOM_Kwh.3')) {
			IPS_CreateVariableProfile('NEOOM_Kwh.3', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('NEOOM_Kwh.3', 3);
			IPS_SetVariableProfileText('NEOOM_Kwh.3', "", " kWh");
			//IPS_SetVariableProfileValues('NEOOM_Kwh.3', 0, 100, 1);
		}

		if (!IPS_VariableProfileExists('Percent.2')) {
			IPS_CreateVariableProfile('Percent.2', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('Percent.2', 2);
			IPS_SetVariableProfileText('Percent.2', "", " %");
			//IPS_SetVariableProfileValues('NEOOM_Kwh.3', 0, 100, 1);
		}
		

		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Profiles registered");
		}
	}

	protected function RegisterVariables() {


		$userEnergy_Consumption = $this->ReadPropertyBoolean("cb_UserEnergyConsumption");
		$userEnergy_FeedIn = $this->ReadPropertyBoolean("cb_UserEnergyFeedIn");
		$energyCommunity_Info = $this->ReadPropertyBoolean("cb_EnergyCommunityInfos");

		//$categorieRoodId = IPS_GetParent($this->InstanceID);

		if($userEnergy_Consumption) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_userEnergyConsumption, "Meine Energie - Bezug", $categorieRoodId, 100, "");
			$this->RegisterVariableFloat("user_consumptionTotal", "Bezug", "NEOOM_Kwh.3", 100);
			$this->RegisterVariableFloat("user_consumptionEG", "EG Bezug", "NEOOM_Kwh.3", 101);
			$this->RegisterVariableFloat("user_consumptionGrid", "Netzbezug", "NEOOM_Kwh.3", 102);
			$this->RegisterVariableFloat("user_consumptionPercent", "Anteil EG Bezug", "Percent.2", 103);
		}

		if($userEnergy_FeedIn) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_userEnergyFeedIn, "Meine Energie - Einspeisung", $categorieRoodId, 110, "");
			$this->RegisterVariableFloat("user_feedInTotal", "Einspeisung", "NEOOM_Kwh.3", 110);
			$this->RegisterVariableFloat("user_feedInEG", "EG Einspeisung", "NEOOM_Kwh.3", 111);
			$this->RegisterVariableFloat("user_feedInGrid", "Netzeinspeisung", "NEOOM_Kwh.3", 112);
			$this->RegisterVariableFloat("user_feedInPercent", "Anteil EG Einspeisung", "Percent.2", 113);
		}

		$this->RegisterVariableFloat("user_co2Reduced", "CO2 reduziert", "", 150);
		$this->RegisterVariableFloat("user_costsSaved", "Kosten gespart", "~Euro", 151);
		$this->RegisterVariableFloat("user_feedInYield", "EG Einspeiseertrag", "~Euro", 152);

		if($energyCommunity_Info) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_energyCommunity, "Meine EG", $categorieRoodId, 500, "");
			$this->RegisterVariableFloat("eg_energyPrice", "Energie Preis", "CentkWh.2", 200);
			$this->RegisterVariableInteger("eg_totalMembers", "EG Mitglieder", "", 210);
		}

		$varId = $this->RegisterVariableInteger("dateTimeQueryTS", "DateTime Query", "~UnixTimestamp", 800);
		$this->RegisterVariableString("dateTimeQueryInfo", "DateTime Query", "", 801);
		SetValueInteger($varId, 1698364800);	//EG Start 1698364800 | 27.10.2023


		$this->RegisterVariableInteger("updateCntOk", "Request Cnt", "", 900);
		$this->RegisterVariableInteger("updateCntNotOk", "Receive Cnt", "", 901);
		$this->RegisterVariableInteger("errorCnt", "Error Cnt", "", 910);
		$this->RegisterVariableString("lastError", "Last Error", "", 911);
		$this->RegisterVariableInteger("lastErrorTimestamp", "Last Error Timestamp", "~UnixTimestamp", 912);

		IPS_ApplyChanges($this->archivInstanzID);
		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Variables registered");
		}
	}

	public function GetClassInfo() {
		return print_r($this, true);
	}

	protected function HandleError($sender, $msg) {
		$this->Increase_CounterVariable($this->GetIDForIdent("errorCnt"));
		SetValue($this->GetIDForIdent("lastError"), $msg);
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), time());

		if ($this->logLevel >= LogLevel::ERROR) {
			$this->AddLog($sender, $msg, 0, true);
		}
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
