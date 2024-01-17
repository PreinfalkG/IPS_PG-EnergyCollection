<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/COMMON.php';
require_once __DIR__ . '/aWATTar.php';

class aWATTar extends IPSModule {

	const DUMMY_IDENT_PriceBasedSwitch = "priceBasedSwitch";

	const IDENT_ActionSkript_Default = "ActionSkript_Default";
	const IDENT_ActionSkript_PriceMode = "ActionSkript_PriceMode";

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
		$this->RegisterPropertyInteger("priceBasedSwitches", 0);


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

		$next_timer = strtotime(date('Y-m-d H:00:10', strtotime('+1 hour')));
		if ($this->logLevel >= LogLevel::DEBUG) {
			$this->AddLog(__FUNCTION__, sprintf("SET next_timer @%s]", $this->UnixTimestamp2String($next_timer)));
		}
		$this->SetUpdateInterval($next_timer - time()); 	// next hour at xx:xx:10

	}


	public function UpdatePriceBasedSwitches(string $caller = '?', bool $useExistingPeriods) {

		$categorieRoodId = IPS_GetParent($this->InstanceID);
		$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_PriceBasedSwitch, "Preisbasierter Schalter", $categorieRoodId, 100, "Plug");
		$childrenIDs = IPS_GetChildrenIDs($dummyParentId);
		foreach ($childrenIDs as $childId) {
			$ident = IPS_GetObject($childId)["ObjectIdent"];
			if (str_starts_with($ident, "priceBasedSwitch_")) {
				$this->UpdatePriceSwitch($childId, $useExistingPeriods);
			}
		}
	}

	public function UpdatePriceSwitch(int $priceSwitchRoodId, bool $useExistingPeriods) {

		$ident = IPS_GetObject($priceSwitchRoodId)["ObjectIdent"];
		if (str_starts_with($ident, "priceBasedSwitch_")) {

			if ($this->logLevel >= LogLevel::TRACE) {
				$this->AddLog(__FUNCTION__, sprintf("Update PriceSwitch '%s'", $ident));
			}

			$varId_mode = IPS_GetObjectIDByIdent("_mode", $priceSwitchRoodId);
			$varId_switch = IPS_GetObjectIDByIdent("_switch", $priceSwitchRoodId);
			$varId_wochenplan = IPS_GetObjectIDByIdent("_wochenplan", $varId_switch);
			$varId_data = IPS_GetObjectIDByIdent("_data", $priceSwitchRoodId);

			$priceMode = GetValueInteger($varId_mode);

			switch ($priceMode) {
				case 0:
					$this->ResetWochenplan($varId_wochenplan);
					SetValueBoolean($varId_switch, false);
					SetValue($varId_data, "Der Virtuelle Schalter ist immer AUS");
					break;
				case 1:
					$this->ResetWochenplan($varId_wochenplan);
					SetValueBoolean($varId_switch, true);
					SetValue($varId_data, "Der Virtuelle Schalter ist immer EIN");
					break;
				case 2:
					//$this->ResetWochenplan($varId_wochenplan);
					SetValue($varId_data, "-");
					$this->SetHoursBelowThreshold($priceSwitchRoodId);
					break;
				case 3:
					//$this->ResetWochenplan($varId_wochenplan);
					SetValue($varId_data, "-");
					$lowestContinuousHours = $this->SetHoursWithLowestPrice($priceSwitchRoodId);
					break;
				default:
					break;
			}
		}
	}


	public function SaveVariables() {

		$marketdataExtended = $this->__get("Buff_MarketdataExtended");
		$summeryCnt = 0;
		$marketDataCnt = 0;
		if (is_array($marketdataExtended)) {

			$summeryDummyId = $this->CreateDummyInstanceByIdent("summary", "Zusammenfassung", $this->InstanceID, $position = 10);
			$marketDataDummyId = $this->CreateDummyInstanceByIdent("marketData_24h", "Marktdaten 24 Stunden", $this->InstanceID, $position = 300);

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
							$varId = $this->SetVariableByIdent($epexSpotPrice, $identName, $varName, $marketDataDummyId, VARIABLE::TYPE_FLOAT, $hour_start + 5, "CentkWh.3", $icon = "", true, 4, 1);
							if ($varId !== false) {
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


		if (!IPS_VariableProfileExists('aWATTar.PriceBasedSwitch.Mode')) {
			IPS_CreateVariableProfile('aWATTar.PriceBasedSwitch.Mode', VARIABLE::TYPE_INTEGER);
			IPS_SetVariableProfileText('aWATTar.PriceBasedSwitch.Mode', "", "");
			IPS_SetVariableProfileValues('aWATTar.PriceBasedSwitch.Mode', 0, 3, 0);
			IPS_SetVariableProfileIcon('aWATTar.PriceBasedSwitch.Mode', "");
			IPS_SetVariableProfileAssociation('aWATTar.PriceBasedSwitch.Mode', 0, "Always OFF", "", -1);
			IPS_SetVariableProfileAssociation('aWATTar.PriceBasedSwitch.Mode', 1, "Always ON", "", -1);
			IPS_SetVariableProfileAssociation('aWATTar.PriceBasedSwitch.Mode', 2, "Marktpreis unter Wunschpreis", "", -1);
			IPS_SetVariableProfileAssociation('aWATTar.PriceBasedSwitch.Mode', 3, "günstigste Stunden des Tages", "", -1);
		}

		if (!IPS_VariableProfileExists('aWATTar.threshold')) {
			IPS_CreateVariableProfile('aWATTar.threshold', VARIABLE::TYPE_FLOAT);
			IPS_SetVariableProfileText('aWATTar.threshold', "", " ct/kWh");
			IPS_SetVariableProfileDigits('aWATTar.threshold', 1);
			IPS_SetVariableProfileValues('aWATTar.threshold', -20, 50, 0.1);
			IPS_SetVariableProfileIcon('aWATTar.threshold', "");
		}

		if (!IPS_VariableProfileExists('aWATTar.ContinuousHours')) {
			IPS_CreateVariableProfile('aWATTar.ContinuousHours', VARIABLE::TYPE_BOOLEAN);
			IPS_SetVariableProfileText('aWATTar.ContinuousHours', "", "");
			//IPS_SetVariableProfileValues ('aWATTar.ContinuousHours', -20, 50, 0.1);
			IPS_SetVariableProfileIcon('aWATTar.ContinuousHours', "");
			IPS_SetVariableProfileAssociation('aWATTar.ContinuousHours', 0, "nein", "", -1);
			IPS_SetVariableProfileAssociation('aWATTar.ContinuousHours', 1, "JA", "", 13885710);
		}

		if (!IPS_VariableProfileExists('aWATTar.priceBasedSwitch')) {
			IPS_CreateVariableProfile('aWATTar.priceBasedSwitch', VARIABLE::TYPE_BOOLEAN);
			IPS_SetVariableProfileText('aWATTar.priceBasedSwitch', "", "");
			//IPS_SetVariableProfileValues ('aWATTar.priceBasedSwitch', -20, 50, 0.1);
			IPS_SetVariableProfileIcon('aWATTar.priceBasedSwitch', "");
			IPS_SetVariableProfileAssociation('aWATTar.priceBasedSwitch', 0, "AUS", "Sleep", 16711680);				//CloseAll
			IPS_SetVariableProfileAssociation('aWATTar.priceBasedSwitch', 1, "EIN", "Rocket", 65280);					//Bulb
		}



		if ($this->logLevel >= LogLevel::TRACE) {
			$this->AddLog(__FUNCTION__, "Profiles registered");
		}
	}

	protected function RegisterVariables() {

		//$this->RegisterVariableInteger("modbusReceiveCnt", "Modbus Receive Cnt", "", 900);
		//$this->RegisterVariableInteger("modbusReceiveLast", "Modbus Last Receive", "~UnixTimestamp", 901);
		//$this->RegisterVariableInteger("modbusTransmitCnt", "Modbus Transmit Cnt", "", 910);


		$priceBasedSwitches = $this->ReadPropertyInteger("priceBasedSwitches");

		$categorieRoodId = IPS_GetParent($this->InstanceID);
		$dummyParentId = $this->CreateCategoryByIdent(self::DUMMY_IDENT_PriceBasedSwitch, "Preisbasierter Schalter", $categorieRoodId, 100, "Plug");

		for ($i = 1; $i <= 5; $i++) {
			$identName = sprintf("%s_%s", self::DUMMY_IDENT_PriceBasedSwitch, $i);
			if ($i <= $priceBasedSwitches) {
				$instanceName = sprintf("Preisbasierter Schalter #%s", $i);
				$position = 200 + ($i * 10);

				$dummyId = $this->CreateDummyInstanceByIdent($identName, $instanceName, $dummyParentId, $position++, "Euro");
				if ($dummyId === false) {
					$this->SendDebug(__FUNCTION__, sprintf("ERROR crating Dummy Instance for '%s'", $instanceName));
				} else {

					//Create Actionscript and Variable for PriceBasedSwitchMode
					$filePath = __DIR__ . "/actionSkript_PriceMode.ips.php";
					//IPS_LogMessage(__FUNCTION__, $filePath);
					$actionSkriptPriceMode_Inhalt = $this->LoadFileContents($filePath);
					$actionSkriptPriceMode_Inhalt = str_replace("%%INSTANZ_ID%%", strval($this->InstanceID), $actionSkriptPriceMode_Inhalt);
					$actionSkriptPriceMode_ObjId = $this->RegisterScript(self::IDENT_ActionSkript_PriceMode, self::IDENT_ActionSkript_PriceMode, $actionSkriptPriceMode_Inhalt, 990);
					IPS_SetHidden($actionSkriptPriceMode_ObjId, true);
					IPS_SetDisabled($actionSkriptPriceMode_ObjId, true);
					if ($this->logLevel >= LogLevel::TRACE) {
						$this->AddLog(__FUNCTION__, sprintf("ActionSkrip '%s' Registered: %s", self::IDENT_ActionSkript_PriceMode, $actionSkriptPriceMode_ObjId));
					}

					$varIdMode = $this->SetVariableByIdent(0, "_mode", "Mode", $dummyId, VARIABLE::TYPE_INTEGER, $position++, "aWATTar.PriceBasedSwitch.Mode", "");
					IPS_SetIcon($varIdMode, "EnergyProduction");
					IPS_SetVariableCustomAction($varIdMode, $actionSkriptPriceMode_ObjId);


					//Create ActionscriptDefault
					$actionSkriptDefault_Inhalt = $this->LoadFileContents(__DIR__ . "/actionSkript_Default.ips.php");
					$actionSkriptDefault_Inhalt = str_replace("%%INSTANZ_ID%%", strval($this->InstanceID), $actionSkriptDefault_Inhalt);
					$actionSkriptDefault_ObjId = $this->RegisterScript(self::IDENT_ActionSkript_Default, self::IDENT_ActionSkript_Default, $actionSkriptDefault_Inhalt, 990);
					IPS_SetHidden($actionSkriptDefault_ObjId, true);
					IPS_SetDisabled($actionSkriptDefault_ObjId, true);
					if ($this->logLevel >= LogLevel::TRACE) {
						$this->AddLog(__FUNCTION__, sprintf("ActionSkrip '%s' Registered: %s", self::IDENT_ActionSkript_Default, $actionSkriptDefault_ObjId));
					}


					$varId = $this->SetVariableByIdent(9.0, "_threshold", "Wunschpreis", $dummyId, VARIABLE::TYPE_FLOAT, $position++, "aWATTar.threshold", "");
					IPS_SetIcon($varId, "Graph");
					IPS_SetVariableCustomAction($varId, $actionSkriptDefault_ObjId);

					$varId = $this->SetVariableByIdent(-3600, "_timeWindowStart", "Zeitfenster VON [hh:mm]", $dummyId, VARIABLE::TYPE_INTEGER, $position++, "~UnixTimestampTime", "");
					IPS_SetVariableCustomAction($varId, $actionSkriptDefault_ObjId);

					$varId = $this->SetVariableByIdent(-3600, "_timeWindowEnd", "Zeitfenster BIS [hh:mm]", $dummyId, VARIABLE::TYPE_INTEGER, $position++, "~UnixTimestampTime", "");
					IPS_SetVariableCustomAction($varId, $actionSkriptDefault_ObjId);


					$varId = $this->SetVariableByIdent(7200, "_duration", "Dauer [hh:mm]", $dummyId, VARIABLE::TYPE_INTEGER, $position++, "~UnixTimestampTime", "");
					IPS_SetIcon($varId, "Hourglass");
					IPS_SetVariableCustomAction($varId, $actionSkriptDefault_ObjId);

					$varId = $this->SetVariableByIdent(0, "_continuousHours", "zusammenhängende Stunden", $dummyId, VARIABLE::TYPE_BOOLEAN, $position++, "aWATTar.ContinuousHours", "");
					IPS_SetIcon($varId, "Distance");	//"Transparent");
					IPS_SetVariableCustomAction($varId, $actionSkriptDefault_ObjId);

					$varIdSwitch = $this->SetVariableByIdent(0, "_switch", "Virtueller Schalter", $dummyId, VARIABLE::TYPE_BOOLEAN, $position++, "aWATTar.priceBasedSwitch", "");
					IPS_SetDisabled($varIdSwitch, false);
					//IPS_SetVariableCustomAction($varId, $actionSkriptDefault_ObjId);

					$eid = @IPS_GetObjectIDByIdent("_wochenplan", $varIdSwitch);
					if ($eid === false) {
						$eid = IPS_CreateEvent(2);		//Wochneplan
						IPS_SetParent($eid, $varIdSwitch);
						IPS_SetIdent($eid, "_wochenplan");
						IPS_SetEventActive($eid, false);
						IPS_SetName($eid, "Wochenplan ON-Hours");
						IPS_SetEventScheduleActionEx($eid, 0, "OFF", 0xC1C1C1, "{3644F802-C152-464A-868A-242C2A3DEC5C}", ["VALUE" => false]);	//0xEDEDED
						IPS_SetEventScheduleActionEx($eid, 1, "ON", 0x00FF11, "{3644F802-C152-464A-868A-242C2A3DEC5C}", ["VALUE" => true]);
					}
					$varId = $this->SetVariableByIdent(0, "_data", "Info", $dummyId, VARIABLE::TYPE_STRING, $position++, "~TextBox", "");

					IPS_Sleep(100);
					RequestActionEx($varIdMode, 0, "Modul");
				}
			} else {
				$dummyId = @IPS_GetObjectIDByIdent($identName, $dummyParentId);
				if ($dummyId !== false) {
					$childrenIDs = IPS_GetChildrenIDs($dummyId);
					foreach ($childrenIDs as $childId) {
						if (IPS_HasChildren($childId)) {
							$objId = IPS_GetChildrenIDs($childId)[0];
							if (IPS_GetObject($objId)["ObjectType"] == 4) {
								IPS_DeleteEvent($objId);
							}
						}

						if (IPS_IsChild($childId, $dummyParentId, true)) {
							IPS_DeleteVariable($childId);
							if ($this->logLevel >= LogLevel::DEBUG) {
								$this->AddLog(__FUNCTION__, sprintf("Variable '%s' deleted", $childId));
							}
						}
					}
					if (IPS_IsChild($dummyId, $dummyParentId, true)) {
						IPS_DeleteInstance($dummyId);
						if ($this->logLevel >= LogLevel::DEBUG) {

							$this->AddLog(__FUNCTION__, sprintf("Dummy Instanz '%s' deleted", $identName));
						}
					}
				} else {
					if ($this->logLevel >= LogLevel::DEBUG) {
						$this->AddLog(__FUNCTION__, sprintf("Dummy Instanz '%s' does not exist", $identName));
					}
				}
			}
		}


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
