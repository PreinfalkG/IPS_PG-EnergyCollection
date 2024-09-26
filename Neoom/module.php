<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/COMMON.php';
require_once __DIR__ . '/NEOOM.php';

class Neoom extends IPSModule {

	const DUMMY_IDENT_userEnergyConsumption = "userEnergyConsumption";
	const DUMMY_IDENT_userEnergyFeedIn = "userEnergyFeedIn";
	const DUMMY_IDENT_energyCommunity = "energyCommunity";

	use COMMON_FUNCTIONS;
	use NEOOM_FUNCTIONS;

	private $logLevel = 4;		// WARN = 3 | INFO = 4
	private $logCnt = 0;
	private $enableIPSLogOutput = false;


	public function __construct($InstanceID) {

		parent::__construct($InstanceID);		// Diese Zeile nicht löschen

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
			$this->SetUpdateInterval($next_timer - time()); 	// every hour at xx:xx:10
			$this->UpdateMarketdata("on ApplyChanges() ...");
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


		return;
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, "TimerUpdate_NEOOM called ...", 0 , true);
		}

		//$result = $this->RequestNextDay("TimerUpdate_NEOOM");
		if($result === false) {

			if ($this->logLevel >= LogLevel::WARN) {
				$this->AddLog(__FUNCTION__, "Problem 'RequesRequestNextDaytData()' > SET 'TimerUpdate_NEOOM' to 120 sec for next try", 0, true);
			}
			$this->SetUpdateInterval(120); 	// next Update in 120 sec

		} else {

			if ($this->logLevel >= LogLevel::DEBUG) {
				$this->AddLog(__FUNCTION__, sprintf("SET next_timer @%s]", $this->UnixTimestamp2String($next_timer)));
			}
			$this->SetUpdateInterval(time()+10);

		}
	}



	public function RequestNextDay(string $caller = '?') {

		$returnValue = true;

		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("RequestNextDay [Trigger > %s] ...", $caller));
		}
		
		$dateTimeFrom =	GetValueInteger($this->GetIDForIdent("dateTimeQueryTS"));
		$walletId = $this->ReadPropertyString("tb_WalletId");

		$dtQueryFrom = new DateTime('@' . $dateTimeFrom);
		$dtQueryTo = (new DateTime())->modify('-7 days');
		//$dtQueryTo = (new DateTime())->createFromFormat('d/m/Y', '30/10/2023');
		//$dtQueryTo = (new DateTime())->createFromFormat('d/m/Y\\TH:i:s', '30/10/2023T00:00:00');
		//$dtQueryTo = new DateTime('@' . time() - 7*24*3600);
		
		
		if($dtQueryFrom > $dtQueryTo) {
			$this->HandleError(__FUNCTION__, "dtQueryFrom > dtQueryTo");
			$returnValue = false;
		} else {

			$result = $this->QueryUserEnergy($caller, $dtQueryFrom, $dtQueryFrom, $walletId);

            if ($this->logLevel >= LogLevel::COMMUNICATION) {
                $this->AddLog(__FUNCTION__, sprintf("API Response [%s]", print_r($result,true)));
            }

			if($result) {
				$this->Increase_CounterByIdent("updateCntOk");
				$dtQueryFrom->modify('+1 day');
				SetValueInteger($this->GetIDForIdent("dateTimeQueryTS"), $dtQueryFrom->getTimestamp());
			} else {
				$this->HandleError(__FUNCTION__, "RESULT: " . $result);
			}

		}
	}

	public function ReAggregateVariables(string $caller = '?') {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("ReAggregateVariables [Trigger > %s] ...", $caller));
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

		if (!IPS_VariableProfileExists('CentkWh.3')) {
			IPS_CreateVariableProfile('CentkWh.3', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('CentkWh.3', 3);
			IPS_SetVariableProfileText('CentkWh.3', "", " ct/kWh");
			//IPS_SetVariableProfileValues('CentkWh.3', 0, 100, 1);
		}

		if (!IPS_VariableProfileExists('NEOOM_Kwh.3')) {
			IPS_CreateVariableProfile('NEOOM_Kwh.3', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('NEOOM_Kwh.3', 3);
			IPS_SetVariableProfileText('NEOOM_Kwh.3', "", " kWh");
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

		$categorieRoodId = IPS_GetParent($this->InstanceID);

		if($userEnergy_Consumption) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_userEnergyConsumption, "Meine Energie - Bezug", $categorieRoodId, 100, "");
		
			$this->RegisterVariableFloat("ue_consumptionTotal", "Bezug", "", 100);
			$this->RegisterVariableFloat("ue_consumptionEG", "EG Bezug", "", 101);
			$this->RegisterVariableFloat("ue_consumptionGrid", "Netzbezug", "", 102);
			$this->RegisterVariableFloat("ue_consumptionPercent", "Anteil EG Bezug", "", 103);

			$this->RegisterVariableFloat("ue_co2Reduced", "CO2 reduziert", "", 105);
			$this->RegisterVariableFloat("costsSaved", "Kosten gespart", "", 106);
		}

		if($userEnergy_FeedIn) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_userEnergyFeedIn, "Meine Energie - Einspeisung", $categorieRoodId, 110, "");
		
			$this->RegisterVariableFloat("ue_feedInTotal", "Einspeisung", "", 110);
			$this->RegisterVariableFloat("ue_feedInEG", "EG Einspeisung", "", 111);
			$this->RegisterVariableFloat("ue_feedInGrid", "Netzeinspeisung", "", 112);
			$this->RegisterVariableFloat("ue_feedInPercent", "Anteil EG Einspeisung", "", 113);

			
			$this->RegisterVariableFloat("feedInYield", "EG Einspeiseertrag", "", 115);
		}

		if($energyCommunity_Info) {
			//$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_energyCommunity, "Meine EG", $categorieRoodId, 500, "");
		
			$this->RegisterVariableFloat("feedInTotal", "Einspeisung", "", 500);
			$this->RegisterVariableFloat("feedInEG", "EG Einspeisung", "", 510);
			$this->RegisterVariableFloat("feedInGrid", "Netzeinspeisung", "", 520);
			$this->RegisterVariableFloat("feedInPercent", "Anteil EG Einspeisung", "", 530);
		}

		$varId = $this->RegisterVariableInteger("dateTimeQueryTS", "DateTime Query", "~UnixTimestamp", 800);
		$this->RegisterVariableString("dateTimeQueryInfo", "DateTime Query", "", 801);

		SetValueInteger($varId, 1698364800);



		$this->RegisterVariableInteger("updateCntOk", "Request Cnt", "", 900);
		$this->RegisterVariableInteger("updateCntNotOk", "Receive Cnt", "", 901);
		$this->RegisterVariableInteger("errorCnt", "Error Cnt", "", 910);
		$this->RegisterVariableString("lastError", "Last Error", "", 911);
		$this->RegisterVariableInteger("lastErrorTimestamp", "Last Error Timestamp", "~UnixTimestamp", 912);

		$archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
		IPS_ApplyChanges($archivInstanzID);
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
