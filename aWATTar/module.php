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
		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, $logMsg);
		}

		$this->RegisterPropertyBoolean("EnableAutoUpdate", false);
		$this->RegisterPropertyInteger("LogLevel", LogLevel::INFO);

		$this->RegisterTimer('TimerAutoUpdate_aWATTar', 0, 'aWATTar_TimerAutoUpdate_aWATTar($_IPS["TARGET"]);');

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
	}


	public function Destroy() {
		IPS_LogMessage(__CLASS__ . "_" . __FUNCTION__, sprintf("Destroy Modul '%s' ...", $this->InstanceID));
		parent::Destroy();						//Never delete this line!
	}


	public function onKernelReady() {
		$this->AddLog(__FUNCTION__, "Inital aWATTar Marketdata Update on 'onKernelReady'");
		$this->SetUpdateInterval(10 * 1000);
		//$this->UpdateMarketdata("Inital Update 'onKernelReady'");
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
				$this->AddLog(__FUNCTION__, "Auto-Update stopped [TimerIntervall = 0]");
			}
		} else {
			if ($this->logLevel >= LogLevel::INFO) {
				$this->AddLog(__FUNCTION__, sprintf("Set 'TimerAutoUpdate' to %s sec", $timerInterval));
			}
		}
		$this->SetTimerInterval("TimerAutoUpdate_aWATTar", $timerInterval * 1000);
	}


	public function TimerAutoUpdate_aWATTar() {

		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, "TimerAutoUpdate_aWATTar called ...");
		}
		$this->UpdateMarketdata("TimerAutoUpdate_aWATTar");
	}


	public function UpdateMarketdata(string $caller) {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("UpdateMarketdata [Trigger > %s] ...", $caller));
		}

		$awattarMarketData = $this->RequestMarketdata();
		if ($awattarMarketData !== false) {

			$result = $this->CreateMarketdataExtended($awattarMarketData);
			if ($result !== false) {

				$this->__set("Buff_MarketdataExtended", $this->marketdataExtended);
				$this->__set("Buff_MarketdataExtendedTS", $this->UnixTimestamp2String(time()));
				//IPS_LogMessage("MarketdataExtended", print_r($this->marketdataExtended, true));

				if ($this->logLevel >= LogLevel::DEBUG) {
					$this->AddLog(__FUNCTION__, sprintf("MarketdataExtended created with %d Entries", $this->marketdataExtended["Entries"]));
				}
				$this->Increase_CounterVariable($this->GetIDForIdent("updateCntOk"));
			} else {
				$this->Increase_CounterVariable($this->GetIDForIdent("updateCntNotOk"));
				$this->HandleError(__FUNCTION__, "ERROR creating 'MarketdataExtended'");
					}
		} else {
			$this->HandleError(__FUNCTION__, "no aWATTar Marketdata avialable");
		}

		$next_timer = strtotime(date('Y-m-d H:00:10', strtotime('+1 hour')));
		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, sprintf("SET next_timer @%s]", $this->UnixTimestamp2String($next_timer)));
		}
		$this->SetUpdateInterval($next_timer - time()); 	// next hour at xx:xx:10

		$this->SaveVariables();
	}


	public function SaveVariables() {

		$marketdataExtended = $this->__get("Buff_MarketdataExtended");
		$summeryCnt = 0;
		$marketDataCnt = 0;
		if(is_array($marketdataExtended)) {

			$summeryDummyId = $this->GetDummyModuleByIdent("summary", "Zusammenfassung", $this->InstanceID, $position = 10);
			$marketDataDummyId = $this->GetDummyModuleByIdent("marketData_24h", "Market Data", $this->InstanceID, $position = 100);
	
			foreach ($marketdataExtended as $key => $value) {
				if ($key == "MarketdataArr") {
					$marketdataArr = $value;
					if (is_array($marketdataArr)) {
						$marketDataCnt++;
						foreach ($marketdataArr as $item) {
							$identName = $item["key"];
							$start = $item["start"];
							$end = $item["end"];
							$hour_start = idate('G', $start);
							$hour_end = idate('G', $end);
							if ($hour_end == 0) {
								$hour_end = 24;
							}

							$hourNOW = idate('G');
							$varName =  sprintf("%s - %s [%s]",  date('H:i', $start),  date('H:i', $end), date('d.m.Y', $start));
							if ($hour_start == $hourNOW) {
								$varName =  sprintf("%s - %s [%s] <- NOW",  date('H:i', $start),  date('H:i', $end), date('d.m.Y', $start));
							}
							$epexSpotPrice = $item["EPEXSpot"];
							$varId = $this->SetVariableByIdent($epexSpotPrice, $identName, $varName, $marketDataDummyId, VARIABLE::TYPE_FLOAT, $hour_start+5, "CentkWh.3", $icon = "", true, 4, 1);
							if($varId !== false) {
								if (time() > $end) {
									IPS_SetDisabled($varId, true);
								} else {
									IPS_SetDisabled($varId, false);
								}
								if ($this->logLevel >= LogLevel::TEST) {
									$this->AddLog(__FUNCTION__, sprintf("Set Value '%s' to Variable '%s'", $epexSpotPrice, $identName));
								}
							} else {
								$this->HandleError(__FUNCTION__, sprintf("Error updating Variable '%s'", $identName));
							}
						}
					} else {
						$this->HandleError(__FUNCTION__, "Error: 'MarketdataArr' in 'Buff_MarketdataExtended' is no Array");
					}
				} else {
					$summeryCnt++;
					$this->SetVariableByIdent($value, $key, $key, $summeryDummyId, -1, $position = $summeryCnt);
					if ($this->logLevel >= LogLevel::TEST) {
						$this->AddLog(__FUNCTION__, sprintf("Set '%s' to Variable '%s'", $value, $key));
					}
				}
			}
		} else {
			$this->HandleError(__FUNCTION__, "Error: 'Buff_MarketdataExtended' is no Array");
		}
	}

	public function GetMarketpricesLowerThan(string $caller, float $price,  int $numberOfHours = 1, bool $contiguousHours = false) {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, "GetMarketpricesLowerThan:");
			$this->AddLog(__FUNCTION__, sprintf("Preis: %f | Anzahl Stunden: %d | Zusammenhängend: %s", $price, $numberOfHours, $contiguousHours ? "true" : "false"));
		}
	}

	public function GetLowestMarketprices(string $caller, int $numberOfHours = 1, bool $contiguousHours = false) {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, "GetLowestMarketprices:");
			$this->AddLog(__FUNCTION__, sprintf("Anzahl Stunden: %d | Zusammenhängend: %s", $numberOfHours, $contiguousHours ? "true" : "false"));

			$xx = $this->__get("Buff_MarketdataExtended");
			//var_dump($xx);
		}
	}

	public function GetClassInfo() {
		return print_r($this, true);
	}


	public function ResetCounterVariables(string $caller = '?') {
		if ($this->logLevel >= LogLevel::INFO) {
			$this->AddLog(__FUNCTION__, sprintf("RESET Counter Variables [Trigger > %s] ...", $caller));
		}

		SetValue($this->GetIDForIdent("updateCntOk"), 0);
		SetValue($this->GetIDForIdent("updateCntNotOk"), 0);
		SetValue($this->GetIDForIdent("errorCnt"), 0);
		SetValue($this->GetIDForIdent("lastError"), "-");
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), 0);
	}

	public function BufferDebugInfos(string $caller = '?') {

		$bufferArr = $this->GetBufferList();
		$bufferCnt = count($bufferArr);
		if ($bufferCnt > 0) {
			$this->AddLog(__FUNCTION__, "List of used Buffers:");
			foreach ($bufferArr as $bufferName) {
				$this->AddLog(__FUNCTION__, sprintf(" - %s {%s}", $bufferName, print_r($this->__get($bufferName), true)));
			}
		} else {
			$this->AddLog(__FUNCTION__, "No Buffers used!");
		}
		$this->SaveVariables();
	}


	protected function RegisterProfiles() {

		if (!IPS_VariableProfileExists('CentkWh.3')) {
			IPS_CreateVariableProfile('CentkWh.3', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileDigits('CentkWh.3', 3);
			IPS_SetVariableProfileText('CentkWh.3', "", " ct/kWh");
			//IPS_SetVariableProfileValues('CentkWh.3', 0, 100, 1);
		}

		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Profiles registered");
		}
	}

	protected function RegisterVariables() {

		//$this->RegisterVariableInteger("modbusReceiveCnt", "Modbus Receive Cnt", "", 900);
		//$this->RegisterVariableInteger("modbusReceiveLast", "Modbus Last Receive", "~UnixTimestamp", 901);
		//$this->RegisterVariableInteger("modbusTransmitCnt", "Modbus Transmit Cnt", "", 910);

		$this->RegisterVariableInteger("updateCntOk", "Request Cnt", "", 900);
		$this->RegisterVariableInteger("updateCntNotOk", "Receive Cnt", "", 901);
		$this->RegisterVariableString("errorCnt", "Error Cnt", "", 910);
		$this->RegisterVariableString("lastError", "Last Error", "", 911);
		$this->RegisterVariableInteger("lastErrorTimestamp", "Last Error Timestamp", "~UnixTimestamp", 912);

		$archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
		IPS_ApplyChanges($archivInstanzID);
		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Variables registered");
		}
	}


	protected function HandleError($sender, $msg) {
		$this->Increase_CounterVariable($this->GetIDForIdent("errorCnt"));
		SetValue($this->GetIDForIdent("lastError"), $msg);
		SetValue($this->GetIDForIdent("lastErrorTimestamp"), time());

		if ($this->logLevel >= LogLevel::ERROR) {
			$this->AddLog($sender, $msg);
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