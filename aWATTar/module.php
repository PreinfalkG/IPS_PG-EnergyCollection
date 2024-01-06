<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/COMMON.php';
require_once __DIR__ . '/aWATTar.php';

class aWATTar extends IPSModule {

	use COMMON_FUNCTIONS;
	use AWATTAR_FUNCTIONS;

	private $logLevel = 3;		// WARN = 3;
	private $logCnt = 0;
	private $enableIPSLogOutput = false;		
    
	public $marketdataExtended = [];

	public function __construct($InstanceID) {
	
		parent::__construct($InstanceID);		// Diese Zeile nicht löschen

		$this->logLevel = @$this->ReadPropertyInteger("LogLevel"); 
		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("Log-Level is %d", $this->logLevel)); }	
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
		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, $logMsg);
		}

		$this->RegisterPropertyBoolean("EnableAutoUpdate", false);
		$this->RegisterPropertyInteger("LogLevel", LogLevel::INFO);
	}


	public function Destroy() {
		IPS_LogMessage(__CLASS__ . "_" . __FUNCTION__, sprintf("Destroy Modul '%s' ...", $this->InstanceID));
		parent::Destroy();						//Never delete this line!
	}

	public function ApplyChanges() {

		parent::ApplyChanges();		//Never delete this line!

		$this->logLevel = $this->ReadPropertyInteger("LogLevel");
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("Set Log-Level to %d", $this->logLevel));
		}

		$this->RegisterProfiles();
		$this->RegisterVariables();

		//$meterModbusPort = $this->ReadPropertyInteger("MeterModbusPort");	

	}


	public function UpdateMarketdata(string $caller) {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("Update Data called [Trigger > %s] ...", $caller));
		}

		$marketdata = $this->RequestMarketdata();
		if($marketdata !== false) {
			$this->ProcessMarketdata($marketdata);
			IPS_LogMessage("marketdataArr", print_r($this->marketdataExtended, true));
		}


        if ($this->logLevel >= LogLevel::COMMUNICATION) {
			$this->AddLog(__FUNCTION__, print_r($marketdata, true));
		}

	}


	public function GetClassInfo() {
		return print_r($this, true);
	}



	public function ResetCounterVariables(string $caller = '?') {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("RESET Counter Variables [Trigger > %s] ...", $caller));
		}


		//SetValue($this->GetIDForIdent("modbusReceiveCnt"), 0); 
		//SetValue($this->GetIDForIdent("modbusReceiveLast"), 0); 	
		//SetValue($this->GetIDForIdent("modbusTransmitCnt"), 0); 
	}


	protected function RegisterProfiles() {

		/*
			if ( !IPS_VariableProfileExists('EV.level') ) {
				IPS_CreateVariableProfile('EV.level', VARIABLE::TYPE_INTEGER );
				IPS_SetVariableProfileDigits('EV.level', 0 );
				IPS_SetVariableProfileText('EV.level', "", " %" );
				IPS_SetVariableProfileValues('EV.level', 0, 100, 1);
			} */

		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Profiles registered");
		}
	}

	protected function RegisterVariables() {

		//$this->RegisterVariableInteger("modbusReceiveCnt", "Modbus Receive Cnt", "", 900);
		//$this->RegisterVariableInteger("modbusReceiveLast", "Modbus Last Receive", "~UnixTimestamp", 901);
		//$this->RegisterVariableInteger("modbusTransmitCnt", "Modbus Transmit Cnt", "", 910);

		$archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
		IPS_ApplyChanges($archivInstanzID);
		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Variables registered");
		}
	}


	protected function AddLog($name, $daten, $format = 0) {
		$this->logCnt++;
		$logSender = "[" . __CLASS__ . "] - " . $name;
		if ($this->logLevel >= LogLevel::DEBUG) {
			$logSender = sprintf("%02d-T%2d [%s] - %s", $this->logCnt, $_IPS['THREAD'], __CLASS__, $name);
		}
		$this->SendDebug($logSender, $daten, $format);

		if ($this->enableIPSLogOutput) {
			if ($format == 0) {
				IPS_LogMessage($logSender, $daten);
			} else {
				IPS_LogMessage($logSender, $this->String2Hex($daten));
			}
		}
	}
}
