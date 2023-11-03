<?php

declare(strict_types=1);

require_once __DIR__ . '/API.php'; 
require_once __DIR__ . '/../libs/COMMON.php'; 
require_once __DIR__ . '/../libs/vendor/autoload.php';


class SomfyTaHoma extends IPSModule	{

	use PG_COMMON;
	use API;
	//use GuzzleHttp\Client;

	const PROF_NAMES = ["fetchApiData", "GetTaHomaDevices", "TaHomaExecuteCommand"];
	const IDENT_ActionsSkriptShutterRTS = "actionsSkriptShutterRTS";
	const IDENT_ActionsSkriptShutterIO = "actionsSkriptShutterIO";

	private $logLevel = 3;
	private $logCnt = 0;	
	private $enableIPSLogOutput = false;

	private $somfyUserId;
	private $somfyPassword;

	private $gatewayIP;
	private $gatewayPin;
	
	private $tokenLabel;
	private $token;

	private $TahomaApiBaseURL;

	private $client;
	private $clientCookieJar;

	public function __construct($InstanceID) {
	
		parent::__construct($InstanceID);		// Diese Zeile nicht löschen

		$this->logLevel = @$this->ReadPropertyInteger("LogLevel"); 
		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("Log-Level is %d", $this->logLevel)); }

		$currentStatus = @$this->GetStatus();
		if($currentStatus == 102) {				//Instanz ist aktiv

			$this->somfyUserId = $this->ReadPropertyString("tbUserId");
			$this->somfyPassword = $this->ReadPropertyString("tbUserPassword");		
			$this->gatewayIP = $this->ReadPropertyString("tbGatewayIp");		
			$this->gatewayPin = $this->ReadPropertyString("tbGatewayPin");	
			$this->tokenLabel = $this->ReadPropertyString("tbTokenLabel");

			$this->token = 	GetValue($this->GetIDForIdent("token"));	//"6250e7301fa2f528a971";

			$this->tahomaApiBaseURL = sprintf("https://%s:8443/enduser-mobile-web/1/enduserAPI", $this->gatewayIP);

			$this->client = new GuzzleHttp\Client();
			$this->clientCookieJar = new GuzzleHttp\Cookie\CookieJar();

		} else {
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Current Status is '%s'", $currentStatus)); }	
		}

	}


	public function Create() {

		parent::Create();			//Never delete this line!

		$logMsg = sprintf("Create Modul '%s [%s]'...", IPS_GetName($this->InstanceID), $this->InstanceID);
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg); }
		IPS_LogMessage(__CLASS__."_".__FUNCTION__, $logMsg);

		$logMsg = sprintf("KernelRunlevel '%s'", IPS_GetKernelRunlevel());
		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, $logMsg); }	

		$this->RegisterPropertyBoolean('AutoUpdate', false);
		$this->RegisterPropertyInteger("TimerInterval", 120);		
		$this->RegisterPropertyInteger("LogLevel", 4);

		$this->RegisterPropertyString("tbUserId", "");
		$this->RegisterPropertyString("tbUserPassword", "");
		$this->RegisterPropertyString("tbGatewayIp", "10.0.10.169");
		$this->RegisterPropertyString("tbGatewayPin", "2010-7317-7836");
		$this->RegisterPropertyString("tbTokenLabel", "ADW20-IoT");
		
		//Register Attributes for simple profiling
		foreach(self::PROF_NAMES as $profName) {
			$this->RegisterAttributeInteger("prof_" . $profName, 0);
			$this->RegisterAttributeInteger("prof_" . $profName . "_OK", 0);
			$this->RegisterAttributeInteger("prof_" . $profName  . "_NotOK", 0);
			$this->RegisterAttributeFloat("prof_" . $profName . "_Duration", 0);
		}
		
		$this->RegisterTimer('TimerAutoUpdate_TaHomeSwitch', 0, 'TaHomaSwitch_TimerAutoUpdate_TaHomeSwitch($_IPS["TARGET"]);');

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
	}

	public function Destroy() {
		IPS_LogMessage(__CLASS__."_".__FUNCTION__, sprintf("Destroy Modul '%s' ...", $this->InstanceID));
		parent::Destroy();						//Never delete this line!
	}

	public function ApplyChanges() {

		parent::ApplyChanges();				//Never delete this line!

		$this->logLevel = $this->ReadPropertyInteger("LogLevel");
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Log-Level to %d", $this->logLevel)); }
		

		$this->RegisterProfiles();
		$this->RegisterVariables();  
		$this->CreateActionSkripts();
			
		$autoUpdate = $this->ReadPropertyBoolean("AutoUpdate");		
		if($autoUpdate) {
			$timerInterval = $this->ReadPropertyInteger("TimerInterval");
		} else {
			$timerInterval = 0;
		}

		$this->SetUpdateInterval($timerInterval);
	}


	public function MessageSink($TimeStamp, $SenderID, $Message, $Data)	{
		$logMsg = sprintf("TimeStamp: %s | SenderID: %s | Message: %s | Data: %s", $TimeStamp, $SenderID, $Message, json_encode($Data));
		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, $logMsg); }
		//IPS_LogMessage(__CLASS__."_".__FUNCTION__, $logMsg);
	}

	
	public function SetUpdateInterval(int $timerInterval) {
		if ($timerInterval == 0) {  
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "Auto-Update stopped [TimerIntervall = 0]"); }	
		}else if ($timerInterval < 20) { 
			$timerInterval = 20; 
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval)); }	
			//$this->UpdateTaHomaDevices(__FUNCTION__);
		} else {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval)); }
			//$this->UpdateTaHomaDevices(__FUNCTION__);
		}
		$this->SetTimerInterval("TimerAutoUpdate_TaHomeSwitch", $timerInterval*1000);	
	}


	public function TimerAutoUpdate_TaHomeSwitch() {

		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "TimerAutoUpdate_TaHomeSwitch called ..."); }

		$skipUdateSec = 600;
		$lastUpdate  = time() - round(IPS_GetVariable($this->GetIDForIdent("updateCntError"))["VariableUpdated"]);
		if ($lastUpdate > $skipUdateSec) {

			$this->UpdateAll(__FUNCTION__);

		} else {
			SetValue($this->GetIDForIdent("updateCntSkip"), GetValue($this->GetIDForIdent("updateCntSkip")) + 1);
			$logMsg =  sprintf("INFO :: Skip Update for %d sec for Instance '%s' [%s] >> last error %d seconds ago...", $skipUdateSec, $this->InstanceID, IPS_GetName($this->InstanceID),  $lastUpdate);
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg); }
		}						
	}

	
	public function UpdateTaHomaDevices(string $caller='?') {
		//if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Update TaHoma Devices [%s] ...", $caller)); }
		$devices = $this->GetTaHomaDevices();

		if($devices !== false) {
			//$this->AddLog(__FUNCTION__, print_r($devices, true), 0);
			$deviceJsonData = json_decode($devices, true); 

			foreach($deviceJsonData as $device) {

				$deviceURL = $device["deviceURL"];
				$label = $device["label"];
				$available = $device["available"];
				$synced = $device["synced"];
				$enabled = $device["enabled"];
				$controllableName = $device["controllableName"];

				if($this->logLevel >= LogLevel::TRACE) { 
					$logMsg = sprintf(" - %s :: available: %s |  synced: %s |  enabled: %s |  controllableName: %s |  deviceURL: %s\r\n", $label, $available, $synced, $enabled, $controllableName, $deviceURL);
					//$this->AddLog(__FUNCTION__, $logMsg, 0); 
				}

				$categiryIdent = str_replace(':','', $controllableName);
				$dummyModulIdent = str_replace('://','', $deviceURL);
				$dummyModulIdent = str_replace($this->gatewayPin,'_', $dummyModulIdent);
				$dummyModulIdent = str_replace('_/','_', $dummyModulIdent);
				$dummyModulIdent = str_replace('/','', $dummyModulIdent);

				if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf(" - %s [%s] {available: %b | synced: %b | enabled: %b}", $label, $dummyModulIdent, $available, $synced, $enabled)); }
				//if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf(" %s - %s\r\n", $categiryIdent, $dummyModulIdent)); }


				$parentRootId = IPS_GetParent($InstanceID);

				switch($controllableName) {

					case "rts:ExteriorVenetianBlindRTSComponent":

						$categoryId = $this->GetCategoryID($categiryIdent, "Raffstore WZ", $parentRootId, 0);
						$dummyModulId = $this->GetDummyModuleID($dummyModulIdent, $label, $categoryId, 0);
						IPS_SetInfo($dummyModulId, $deviceURL);

						//$varId = $this->GetOrCreateIpsVariable($dummyModulIdent, ["up", "my", "down", "stop"]);
						$varId = $this->GetOrCreateIpsVariable($dummyModulId, "ControlShutter", "Steuerung", VARIABLE::TYPE_INTEGER, $position=10, $varProfile="Somfy.ShutterPosition.RTS");
						IPS_SetInfo($varId, $deviceURL);	
						IPS_SetVariableCustomAction($varId, GetValue($this->GetIDForIdent("actionsSkriptId_ShutterRTS")));

						break;

					case "io:HorizontalAwningIOComponent":
						
						$categoryId = $this->GetCategoryID($categiryIdent, "Terrassendachmarkisen", $parentRootId, 0);
						$dummyModulId = $this->GetDummyModuleID($dummyModulIdent, $label, $categoryId, 0);
						IPS_SetInfo($dummyModulId, $deviceURL);
						
						//$varId = $this->GetOrCreateIpsVariable($dummyModulIdent, ["up", "my", "down", "stop"]);
						$varId = $this->GetOrCreateIpsVariable($dummyModulId, "ControlShutter", "Steuerung", VARIABLE::TYPE_INTEGER, $position=10, $varProfile="Somfy.ShutterPosition.RTS");
						IPS_SetInfo($varId, $deviceURL);	
						IPS_SetVariableCustomAction($varId, GetValue($this->GetIDForIdent("actionsSkriptId_ShutterRTS")));							
						
						//$varId = $this->GetOrCreateIpsVariable($dummyModulIdent, ["up", "my", "down", "stop"]);
						$varId = $this->GetOrCreateIpsVariable($dummyModulId, "ControlShutter", "Steuerung", VARIABLE::TYPE_INTEGER, $position=10, $varProfile="Somfy.ShutterPosition.IO");
						IPS_SetInfo($varId, $deviceURL);	
						IPS_SetVariableCustomAction($varId, GetValue($this->GetIDForIdent("actionsSkriptId_ShutterIO")));

						//$this->SaveVariableValue($label, $dummyModulId, "label", "label", VARIABLE::TYPE_STRING, 200, "");
						$this->SaveVariableValue($available, $dummyModulId, "available", "available", VARIABLE::TYPE_BOOLEAN, 200, "");
						$this->SaveVariableValue($synced, $dummyModulId, "synced", "synced", VARIABLE::TYPE_BOOLEAN, 201, "");
						$this->SaveVariableValue($enabled, $dummyModulId, "enabled", "enabled", VARIABLE::TYPE_BOOLEAN, 202, "");
						//SaveVariableValue($value, $parentId, $varIdent, $varName, $varType=3, $position=0, $varProfile="", $asMaxValue=false) {

						break;

					case "io:VerticalExteriorAwningIOComponent":

						$categoryId = $this->GetCategoryID($categiryIdent, "Senkrechtmarkisen", $parentRootId, 0);
						$dummyModulId = $this->GetDummyModuleID($dummyModulIdent, $label, $categoryId, 0);
						IPS_SetInfo($dummyModulId, $deviceURL);

						//$varId = $this->GetOrCreateIpsVariable($dummyModulIdent, ["up", "my", "down", "stop"]);
						$varId = $this->GetOrCreateIpsVariable($dummyModulId, "ControlShutter", "Steuerung", VARIABLE::TYPE_INTEGER, $position=10, $varProfile="Somfy.ShutterPosition.IO");
						IPS_SetInfo($varId, $deviceURL);	
						IPS_SetVariableCustomAction($varId, GetValue($this->GetIDForIdent("actionsSkriptId_ShutterIO")));

						//$this->SaveVariableValue($label, $dummyModulId, "label", "label", VARIABLE::TYPE_STRING, 200, "");
						$this->SaveVariableValue($available, $dummyModulId, "available", "available", VARIABLE::TYPE_BOOLEAN, 200, "");
						$this->SaveVariableValue($synced, $dummyModulId, "synced", "synced", VARIABLE::TYPE_BOOLEAN, 201, "");
						$this->SaveVariableValue($enabled, $dummyModulId, "enabled", "enabled", VARIABLE::TYPE_BOOLEAN, 202, "");
						//SaveVariableValue($value, $parentId, $varIdent, $varName, $varType=3, $position=0, $varProfile="", $asMaxValue=false) {							

						break;
						
					default:
						$categoryId = $this->GetCategoryID($categiryIdent, $controllableName, $parentRootId, 0);
						$dummyModulId = $this->GetDummyModuleID($dummyModulIdent, $label, $categoryId, 0);
						IPS_SetInfo($dummyModulId, $deviceURL);
						break;						
				}

				$pos = 100;
				foreach ($device["states"] as $state) {
					$stateName =  $state["name"];
					$stateIdent = str_replace(':','_', $stateName);
					$stateName = str_replace('core:','', $stateName);
					$stateValue =  $state["value"];

					if(is_array($stateValue)){
						foreach ($stateValue as $key => $value) {
							$this->SaveVariableValue($value, $dummyModulId, $stateIdent."_".$key, $key, -1, $pos, "");
						}

					} else {
						$this->SaveVariableValue($stateValue, $dummyModulId, $stateIdent, $stateName, -1, $pos, "");
					}

					if($this->logLevel >= LogLevel::TRACE) { 
						$logMsg = sprintf("  categiryIdent: %s :: dummyModulIdent: %s |  stateIdent: %s |  stateName: %s | # stateType: %s | Count: %s\r\n", $categiryIdent, $dummyModulIdent, $stateIdent, $stateName, gettype($stateValue), print_r($stateValue, true));
						$this->AddLog(__FUNCTION__, $logMsg, 0); 
					}

					//
					$pos++;
				}

			}
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("TaHoma Devices updated [%s] ...", $caller)); }
			return true;
		//	$this->UpdateIpsVariables($dataArr, "Charger_Infos", 20, "Site", 30);
		} else { 
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "WARN :: IPS Variables NOT updated !"); } 
			return false;
		}	
	}			

	//commands: my, setPosition, close, up, open, down, stop

	//stop, tiltPositive, tiltNegative, up, down, open, close, my

	public function UpdateAll(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Update TaHoma Devices [%s] ...", $caller)); }
			
		$currentStatus = $this->GetStatus();
		if($currentStatus == 102) {			
			$start_Time = microtime(true);
			try {
				
				$this->UpdateTaHomaDevices($caller);
				//if($return) {
				//	SetValue($this->GetIDForIdent("updateCntOk"), GetValue($this->GetIDForIdent("updateCntOk")) + 1);  
				//	if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "Update DONE"); }
				//} else {
				//	SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1);  
				//	if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, "Problem updating IPS Variables!"); }							
				//}

			} catch (Exception $e) {
				$errorMsg = $e->getMessage();
				$errorMsg = sprintf("Exception occurred :: %s", $errorMsg);
				if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $errorMsg ); }
				SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1);  
				SetValue($this->GetIDForIdent("updateLastError"), $errorMsg);					
			}

			$duration = $this->CalcDuration_ms($start_Time);
			//SetValue($this->GetIDForIdent("updateLastDuration"), $duration); 

		} else {
			//SetValue($this->GetIDForIdent("instanzInactivCnt"), GetValue($this->GetIDForIdent("instanzInactivCnt")) + 1);
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Instanz '%s - [%s]' not activ [Status=%s]", $this->InstanceID, IPS_GetName($this->InstanceID), $currentStatus)); }
		}
	}	

	public function SendCommandRTS(string $caller='?', string $deviceUrl, string $value, int $triggerVarId) {

		$commandName = "";
		$returnValue = null;
		switch($value) {
			case "open":
			case "up":
			case "0":
				$commandName = "up";
				$returnValue = 0;
				break;
			case "close":
			case "down":
			case "4":
			case "100":
				$commandName = "down";
				$returnValue = 100;
				break;      
			case "my":
			case "99";
			case "101":
				$commandName = "my";
				$returnValue = 99;
				break;                                        
			case "stop":
			case "2":                    
			case "101":                        
				$commandName = "stop";
				$returnValue = 50;
				break;     
			default:
				$commandName = "stop";
				$returnValue = 50;
			break;     
		}


		$deviceName = IPS_GetName(IPS_GetParent($triggerVarId));

		$result = $this->TaHomaExecuteCommand($triggerVarId, $deviceUrl, $commandName);
		if($result !== false) {		
			$resultJson = json_decode($result); 
			if (isset($resultJson->execId)) {
				$result = $resultJson->execId;
			}
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("%s '%s'´Result: '%s' [WebFront Return Value: %s] @%s",$deviceName, $commandName, print_r($result,true), $returnValue, $deviceUrl)); }
			return $returnValue;
		} else {
			$returnValue = -1;
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("%s '%s' FAILD: '%s' [WebFront Return Value: %s] @%s", $deviceName, $commandName, int_r($result,true), $returnValue, $deviceUrl)); }
			return $returnValue;
		}
	}

	public function SendCommandIO(string $caller='?', string $deviceUrl, string $value, int $triggerVarId) {
		$commandName = "";
		$returnValue = null;
		switch($value) {
			case "open":
			case "up":
			case "0":
				$commandName = "up";
				$commandParameter = null;
				$returnValue = 0;
				break;
			case "close":
			case "down":
			case "100":
				$commandName = "down";
				$commandParameter = null;
				$returnValue = 100;
				break;      
			case "my":
				$commandName = "my";
				$commandParameter = null;
				$returnValue = 101;
				break;                                        
			case "stop":              
			case "102":                        
				$commandName = "stop";
				$commandParameter = null;
				$returnValue = 102;
				break;     
			default:
				$commandName = "setPosition";
				$commandParameter = [intval($value)];
				$returnValue = $value;
			break;     
		}

		$result = $this->TaHomaExecuteCommand($triggerVarId, $deviceUrl, $commandName, $commandParameter);
		if($result !== false) {		
			$resultJson = json_decode($result); 
			if (isset($resultJson->execId)) {
				$result = $resultJson->execId;
			}
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("'%s' Result: '%s' [WebFront Return Value: %s] @%s", $commandName, print_r($result,true), $returnValue, $deviceUrl)); }
			return $returnValue;
		} else {
			$returnValue = -1;
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("'%s' FAILD: '%s' [WebFront Return Value: %s] @%s", $commandName, int_r($result,true), $returnValue, $deviceUrl)); }
			return $returnValue;
		}			
	}

	public function NegociateToken(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Negociate Token [%s] ...", $caller)); }
		SetValue($this->GetIDForIdent("token"), "6250e7301fa2f528a971");
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("DoTo >> manual set token to '%s'", GetValue($this->GetIDForIdent("token")))); }

	}

	public function Reset_UpdateVariables(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RESET Update Variables [%s] ...", $caller)); }

		SetValue($this->GetIDForIdent("updateCntOk"), 0);
		SetValue($this->GetIDForIdent("updateCntSkip"), 0);
		SetValue($this->GetIDForIdent("updateCntError"), 0); 
		SetValue($this->GetIDForIdent("updateLastError"), "-"); 

		SetValue($this->GetIDForIdent("lastTaHomaCommands"), "-"); 
		SetValue($this->GetIDForIdent("lastWebFrontCommands"), "-"); 
	}

	public function Reset_Token(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RESET 'Token' [%s] ...", $caller)); }
		SetValue($this->GetIDForIdent("token"), "");
	}

	public function GetClassInfo() {
		return print_r($this, true);
	}

	protected function RegisterProfiles() {

		if ( !IPS_VariableProfileExists('Somfy.ShutterPosition.RTS') ) {
			IPS_CreateVariableProfile('Somfy.ShutterPosition.RTS', VARIABLE::TYPE_INTEGER);
			IPS_SetVariableProfileText('Somfy.ShutterPosition.RTS', "", "" );
			IPS_SetVariableProfileIcon('Somfy.ShutterPosition.RTS', "Jalousie");
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.RTS', 0, 		"[%d] Geöffnet", "", 65280);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.RTS', 50, 		"n.a.", "", 65280);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.RTS', 99, 		"my", "", 12699978);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.RTS', 100, 	"[%d] Geschlossen", "", -1);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.RTS', 101, 	"my", "", -1);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.RTS', 102, 	"stop", "", -1);
		}	


		if ( !IPS_VariableProfileExists('Somfy.ShutterPosition.IO') ) {
			IPS_CreateVariableProfile('Somfy.ShutterPosition.IO', VARIABLE::TYPE_INTEGER);
			IPS_SetVariableProfileText('Somfy.ShutterPosition.IO', "", "" );
			IPS_SetVariableProfileIcon('Somfy.ShutterPosition.IO', "Raffstore");
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 0, 		"[%d] Geöffnet", "", 65280);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 25, 		"[%d] %%", "", 65280);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 50, 		"[%d] %%", "", 65280);								
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 75, 		"[%d] %%", "", 65280);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 100, 		"[%d] Geschlossen", "", -1);
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 101, 		"my", "", 12699978);				
			IPS_SetVariableProfileAssociation('Somfy.ShutterPosition.IO', 102, 		"stop", "", -1);
		}	

		/*
		if ( !IPS_VariableProfileExists('EV.level') ) {
			IPS_CreateVariableProfile('EV.level', VARIABLE::TYPE_INTEGER );
			IPS_SetVariableProfileDigits('EV.level', 0 );
			IPS_SetVariableProfileText('EV.level', "", " %" );
			IPS_SetVariableProfileValues('EV.level', 0, 100, 1);
		} 

		if ( !IPS_VariableProfileExists('EV.Percent') ) {
			IPS_CreateVariableProfile('EV.Percent', VARIABLE::TYPE_FLOAT );
			IPS_SetVariableProfileDigits('EV.Percent', 1 );
			IPS_SetVariableProfileText('EV.Percent', "", " %" );
			//IPS_SetVariableProfileValues('EV.Percent', 0, 0, 0);
		} 	
		*/

		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Profiles registered"); }
	}

	protected function RegisterVariables() {
	
		$this->RegisterVariableInteger("updateCntOk", "Update Cnt", "", 910);
		$this->RegisterVariableInteger("updateCntSkip", "Update Cnt Skip", "", 911);	
		$this->RegisterVariableInteger("updateCntError", "Update Cnt Error", "", 912);
		$this->RegisterVariableString("updateLastError", "Update Last Error", "", 913);
		$this->RegisterVariableInteger("updateHttpStatus", "Update HTTP Status", "", 914);

		$this->RegisterVariableString("token", "Token", "", 950);

		$this->RegisterVariableInteger("actionsSkriptId_ShutterRTS", "Actions SkriptId - ShutterRTS", "", 960);
		$this->RegisterVariableInteger("actionsSkriptId_ShutterIO", "Actions SkriptId - ShutterIO", "", 961);

		$this->RegisterVariableString("lastTaHomaCommands", "last TaHoma Commands", "~TextBox", 970);
		$this->RegisterVariableString("lastWebFrontCommands", "last WebFront Commands", "~TextBox", 970);

		$archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
		IPS_ApplyChanges($archivInstanzID);
		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Variables registered"); }

	}


	protected function CreateActionSkripts() {

		$actionsSkriptId_ShutterRTS = GetValue($this->GetIDForIdent("actionsSkriptId_ShutterRTS"));
		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ActionsSkriptId_ShutterRTS exist: %s", $actionsSkriptId_ShutterRTS)); }

		if($actionsSkriptId_ShutterRTS < 1000) {
			$varId_LastWebFrontCommands = $this->GetIDForIdent("lastWebFrontCommands");
			/*$scriptContent = '<? $varId=$_IPS["VARIABLE"]; SetValue($varId, $_IPS["VALUE"]); BYD_CloseConnection(IPS_GetParent($varId)); ?>'; */
			/*$actionScriptContent_ShutterRTS = sprintf('<? const VARID_LastCommand = %s; $varId=$_IPS["VARIABLE"]; SetValue($varId, $_IPS["VALUE"]); ?>', $varId_LastTaHomaCommand); */
			$actionScriptContent_ShutterRTS = $this->LoadFileContents(__DIR__."\actionSkript_Template_RTS.php");
			$actionScriptContent_ShutterRTS = str_replace("%%INSTANZID%%", strval($this->InstanceID) , $actionScriptContent_ShutterRTS);
			$actionScriptContent_ShutterRTS = str_replace("%%VARID_LastCommands%%", strval($varId_LastWebFrontCommands), $actionScriptContent_ShutterRTS);
			$actionsSkriptId_ShutterRTS = $this->RegisterScript(self::IDENT_ActionsSkriptShutterRTS, "Aktionsskript RTS Shutter", $actionScriptContent_ShutterRTS, 990);
			if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ActionsSkrip ShutterRTS Registered: %s", $actionsSkriptId_ShutterRTS)); }				
			IPS_SetParent($actionsSkriptId_ShutterRTS, $this->InstanceID);
			IPS_SetHidden($actionsSkriptId_ShutterRTS, false);
			IPS_SetDisabled($actionsSkriptId_ShutterRTS, false);
			SetValue($this->GetIDForIdent("actionsSkriptId_ShutterRTS"), $actionsSkriptId_ShutterRTS);
		}

		$actionsSkriptId_ShutterIO = GetValue($this->GetIDForIdent("actionsSkriptId_ShutterIO"));
		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ActionsSkriptId_ShutterIO exist: %s", $actionsSkriptId_ShutterIO)); }
		if($actionsSkriptId_ShutterIO < 1000) {
			$varId_LastWebFrontCommands = $this->GetIDForIdent("lastWebFrontCommands");
			/*$scriptContent = '<? $varId=$_IPS["VARIABLE"]; SetValue($varId, $_IPS["VALUE"]); BYD_CloseConnection(IPS_GetParent($varId)); ?>'; */
			/*$actionScriptContent_ShutterIO = sprintf('<? const VARID_LastCommand = %s; $varId=$_IPS["VARIABLE"]; SetValue($varId, $_IPS["VALUE"]); ?>', $varId_LastTaHomaCommand);*/
			$actionScriptContent_ShutterIO = $this->LoadFileContents(__DIR__."\actionSkript_Template_IO.php");
			$actionScriptContent_ShutterIO = str_replace("%%INSTANZID%%", strval($this->InstanceID), $actionScriptContent_ShutterIO);
			$actionScriptContent_ShutterIO = str_replace("%%VARID_LastCommands%%", strval($varId_LastWebFrontCommands), $actionScriptContent_ShutterIO);
			$actionsSkriptId_ShutterIO = $this->RegisterScript(self::IDENT_ActionsSkriptShutterIO, "Aktionsskript IO Shutter", $actionScriptContent_ShutterIO, 990);
			if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("ActionsSkrip ShutterIO Registered: %s", $actionsSkriptId_ShutterIO)); }
			IPS_SetParent($actionsSkriptId_ShutterIO, $this->InstanceID);
			IPS_SetHidden($actionsSkriptId_ShutterIO, false);
			IPS_SetDisabled($actionsSkriptId_ShutterIO, false);
			SetValue($this->GetIDForIdent("actionsSkriptId_ShutterIO"), $actionsSkriptId_ShutterIO);
		}

	}

	protected function AddLog($name, $daten, $format=0) {
		$this->logCnt++;
		$logSender = "[".__CLASS__."] - " . $name;
		if($this->logLevel >= LogLevel::DEBUG) {
			$logSender = sprintf("%02d-T%2d [%s] - %s", $this->logCnt, $_IPS['THREAD'], __CLASS__, $name);
		} 
		$this->SendDebug($logSender, $daten, $format); 	
	
		if($this->enableIPSLogOutput) {
			if($format == 0) {
				IPS_LogMessage($logSender, $daten);	
			} else {
				IPS_LogMessage($logSender, $this->String2Hex($daten));			
			}
		}
	}
}