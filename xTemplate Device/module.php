<?

class Template extends IPSModule
{
	private $debugLevel = 3;
	private $enableIPSLogOutput = false;
	/*
	7 = ALL 	: Alle Meldungen werden ungefiltert ausgegeben
	6 = TRACE 	: ausführlicheres Debugging, Kommentare
	5 = DEBUG	: allgemeines Debugging (Auffinden von Fehlern)
	4 = INFO	: allgemeine Informationen (Programm gestartet, Programm beendet, Verbindung zu Host Foo aufgebaut, Verarbeitung dauerte SoUndSoviel Sekunden .)
	3 = WARN	: Auftreten einer unerwarteten Situation
	2 = ERROR	: Fehler (Ausnahme wurde abgefangen. Bearbeitung wurde alternativ fortgesetzt)
	1 = FATAL	: Kritischer Fehler, Programmabbruch
	0 = OFF		: Logging ist deaktiviert
	*/	
	
	public function __construct($InstanceID) {
		
		parent::__construct($InstanceID);		// Diese Zeile nicht löschen

		$currentStatus = $this->GetStatus();
		if($currentStatus == 102) {				//Instanz ist aktiv
			$this->debugLevel = $this->ReadPropertyInteger("DebugLevel");
			$this->enableIPSLogOutput = $this->ReadPropertyBoolean("EnableIPSLogOutput");	
		} else {
			if($this->debugLevel >= 4) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("Current Status is '%s'", $currentStatus), 0); }	
		}

		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("Debug Level is '%d'", $this->debugLevel), 0); }	
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("EnableIPSLogOutput is '%d'", $this->enableIPSLogOutput), 0); }
	}	
    
    public function Create() {
		
        parent::Create();	// Diese Zeile nicht löschen
		
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
		
		$this->ConnectParent("{52BEE200-B86B-94C5-D6E8-300706FA053C}"); // Splitter

		$this->RegisterPropertyBoolean('AutoUpdate', false);
		$this->RegisterPropertyInteger("TimerInterval", 5000);		
		$this->RegisterPropertyInteger("DebugLevel", 3);
		$this->RegisterPropertyBoolean('EnableIPSLogOutput', false);		
		
		//Update Info
		$this->RegisterVariableInteger('UpdateCnt', 'Update Cnt', "", 990);	
		$this->RegisterVariableInteger('CRCErrorCnt', 'CRC Error Cnt', "", 991);	
		$this->RegisterVariableInteger('LastUpdate', 'Last Update', "~UnixTimestamp", 999);	
		
		//Timers
		$this->RegisterTimer('Timer_AutoUpdate', 0, 'Template_Timer_AutoUpdate($_IPS[\'TARGET\']);');

	}

	public function Destroy() {
		parent::Destroy();									//Never delete this line!
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
	}

	public function ApplyChanges()	{

		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
				
		$this->RegisterMessage(0, IPS_KERNELSTARTED);		// wait until IPS is started, dataflow does not work until stated	
		
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("RegisterMessage for %s [%s]" ,$this->InstanceID, IPS_GetName($this->InstanceID)), 0); }
		$this->RegisterMessage($this->InstanceID, IM_CHANGESTATUS);
		$this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT); 
		$this->RegisterMessage($this->InstanceID, IM_CONNECT);
        $this->RegisterMessage($this->InstanceID, IM_DISCONNECT); 

		$parentConnectionID = $this->GetParentConnectionID($this->InstanceID);					//Splitter
		if($parentConnectionID > 0) {
			if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("RegisterMessage for %s [%s]" ,$parentConnectionID, IPS_GetName($parentConnectionID)), 0); }
			$this->RegisterMessage($parentConnectionID, IM_CHANGESTATUS);
			$this->RegisterMessage($parentConnectionID, FM_CONNECT);
			$this->RegisterMessage($parentConnectionID, FM_DISCONNECT); 
			$this->RegisterMessage($parentConnectionID, IM_CONNECT);
			$this->RegisterMessage($parentConnectionID, IM_DISCONNECT); 
			
			$parentConnectionID = $this->GetParentConnectionID($parentConnectionID);			//IO
			if($parentConnectionID > 0) {
				if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("RegisterMessage for %s [%s]" ,$parentConnectionID, IPS_GetName($parentConnectionID)), 0); }
				$this->RegisterMessage($parentConnectionID, IM_CHANGESTATUS);
				$this->RegisterMessage($parentConnectionID, FM_CONNECT);
				$this->RegisterMessage($parentConnectionID, FM_DISCONNECT); 
				$this->RegisterMessage($parentConnectionID, IM_CONNECT);
				$this->RegisterMessage($parentConnectionID, IM_DISCONNECT); 
			}
		}
		
		
		parent::ApplyChanges();								// Diese Zeile nicht löschen
		if (IPS_GetKernelRunlevel() <> KR_READY) {			// check kernel ready, if not wait
			return;
		}
				
		$this->debugLevel = $this->ReadPropertyInteger("DebugLevel");
		$this->AddDebugLogEntry(__FUNCTION__, sprintf("INFO :: Set Debug Level  to %d", $this->debugLevel), 0);
		
		$this->enableIPSLogOutput = $this->ReadPropertyBoolean("EnableIPSLogOutput");	
		$this->AddDebugLogEntry(__FUNCTION__, sprintf("INFO :: Set IPS-Log-Output  to %b", $this->enableIPSLogOutput), 0);
		
		$autoUpdate = $this->ReadPropertyBoolean("AutoUpdate");		
		if($autoUpdate) {
			$timerInterval = $this->ReadPropertyInteger("TimerInterval");
		} else {
			$timerInterval = 0;
		}
		$this->SetTimerInterval("Timer_AutoUpdate", $timerInterval);				

		if($this->debugLevel >= 4) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("Interval 'Timer_AutoUpdate' set to '%d' seconds", $timerInterval), 0); }
	}
	
	public function Timer_AutoUpdate() {
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
		$this->RequestData();		
	}
	
	public function RequestData() {
		$data = "Hallo...";
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, $data, 1); }
		$this->SendToSplitter(utf8_encode($data));
	}
	
	protected function SendToSplitter(string $payload)
	{						
		if($this->debugLevel >= 5) { $this->AddDebugLogEntry(__FUNCTION__, $payload, 1); }
		$result = $this->SendDataToParent(json_encode(Array("DataID" => "{71DCBA97-31AB-A772-BCC3-4B24CF96723B}", "Buffer" => $payload))); // Interface GUI
		return $result;
	}
	
	public function ReceiveData($JSONString)
	{
		$data = json_decode($JSONString);
		$rawDataBuffer = $data->Buffer;
		$rawDataBufferDecoded = utf8_decode($rawDataBuffer);
		
		if($this->debugLevel >= 5) { $this->AddDebugLogEntry(__FUNCTION__, $rawDataBuffer, 1); }
		if($this->debugLevel >= 5) { $this->AddDebugLogEntry(__FUNCTION__ . " (UTF8 decoded)", $rawDataBufferDecoded, 1); }
		
		$rawDataLen = strlen($rawDataBufferDecoded);
		$rawData = substr($rawDataBufferDecoded, 0, -2);
		$rawCRC = substr($rawDataBufferDecoded, -2, 2);
		$calcCRC = $this->CRC($rawData);

		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__ . " [.rawData.]", $rawData, 1); }
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__ . " [.rawDataLen.]", $rawDataLen, 0); }
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__ . " [.rawCRC.]", $rawCRC, 1); }
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__ . " [.calcCRC.]", $calcCRC, 1); }
		
		if ($rawCRC != $calcCRC) {
			if($this->debugLevel >= 3) { $this->AddDebugLogEntry(__FUNCTION__, "CRC ERROR [".$this->String2Hex($rawCRC) . " <> " . $this->String2Hex($calcCRC) ."]", 0); }
			$this->SetValue('CRCErrorCnt', $this->GetValue('CRCErrorCnt')+1);
		} else {
		
			$byte_array = unpack('C*', $rawData);
		
			//Parse Data and Save Values
		
			//Update Info
			$this->SetValue('UpdateCnt', $this->GetValue('UpdateCnt')+1);
			$this->SetValue('LastUpdate', time());			
		}
	}
		
	private function AddDebugLogEntry($name, $daten, $format) {
		$this->SendDebug("[" . __CLASS__ . "] - " . $name, $daten, $format); 	

		if($this->enableIPSLogOutput) {
			if($format == 0) {
				IPS_LogMessage("[" . __CLASS__ . "] - " . $name, $daten);	
			} else {
				IPS_LogMessage("[" . __CLASS__ . "] - " . $name, $this->String2Hex($daten));			
			}
		}
	}
	
	private function String2Hex($string) {
		$hex='';
		for ($i=0; $i < strlen($string); $i++) {
			//$hex .= dechex(ord($string[$i]));
			$hex .= "0x" . sprintf("%02X", ord($string[$i])) . " ";
		}
		return trim($hex);
	}
	
	private function ByteStr2ByteArray($s) {
		return array_slice(unpack("C*", "\0".$s), 1);
	}	
		
	// Calculate CRC
	private function CRC($data) {
		$crc = 0xFFFF;
		// ToDo ...
		return $crc;
	}	
	
	
	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		switch ($Message) {
			case IPS_KERNELSTARTED: 	// only after IP-Symcon started
				$this->KernelReady(); 	// if IP-Symcon is ready
				break;		
			case FM_CONNECT:	//DM_CONNECT
				$this->AddDebugLogEntry(__FUNCTION__, sprintf("FM_CONNECT :: Sender: %s | Data: %s", $SenderID, print_r($Data,true)), 0);
                //$this->RegisterParent();
                //if ($this->HasActiveParent()) { $this->IOChangeState(IS_ACTIVE); } else {$this->IOChangeState(IS_INACTIVE); }
                break;
            case FM_DISCONNECT:	//DM_DISCONNECT
				$this->AddDebugLogEntry(__FUNCTION__, sprintf("FM_DISCONNECT :: Sender: %s | Data: %s", $SenderID, print_r($Data,true)), 0);
                //$this->RegisterParent();
                //$this->IOChangeState(IS_INACTIVE);
                break;
			case IM_CONNECT:	//DM_CONNECT
				$this->AddDebugLogEntry(__FUNCTION__, sprintf("IM_CONNECT :: Sender: %s | Data: %s", $SenderID, print_r($Data,true)), 0);
				//$this->RegisterParent();
                break;
            case IM_DISCONNECT:	//DM_DISCONNECT
				$this->AddDebugLogEntry(__FUNCTION__, sprintf("IM_DISCONNECT :: Sender: %s | Data: %s", $SenderID, print_r($Data,true)), 0);
				//$this->RegisterParent();
                break;				
            case IM_CHANGESTATUS:
				$this->AddDebugLogEntry(__FUNCTION__, sprintf("IM_CHANGESTATUS :: Sender: %s | Data: %s", $SenderID, print_r($Data,true)), 0);
                //if ($SenderID == $this->ParentID)
                //    $this->IOChangeState($Data[0]);
                break;					
		}
	}

    protected function RegisterParent() {
        $OldParentId = $this->GetBuffer('ParentID');
        $ParentId = @IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($ParentId <> $OldParentId) {
            if ($OldParentId > 0) {
				if($this->debugLevel >= 4) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("UnregisterMessage 'IM_CHANGESTATUS' for %d", $OldParentId), 0); }	
                $this->UnregisterMessage($OldParentId, IM_CHANGESTATUS);
			}
            if ($ParentId > 0) {
				if($this->debugLevel >= 4) { $this->AddDebugLogEntry(__FUNCTION__, sprintf("RegisterMessage 'IM_CHANGESTATUS' for %d", $ParentId), 0); }
                $this->RegisterMessage($ParentId, IM_CHANGESTATUS);
			}  else {
                $ParentId = 0;
			}
			$this->SetBuffer('ParentID', $ParentId);
        }
        return $ParentId;
    } 
	
	protected function GetParentConnectionID($InstanceID) {
		$instance = IPS_GetInstance($InstanceID);
		return ($instance['ConnectionID'] > 0) ? $instance['ConnectionID'] : false;
	}
	
    protected function HasActiveParent() {
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] > 0) {
            $parent = IPS_GetInstance($instance['ConnectionID']);
            if ($parent['InstanceStatus'] == 102) {
                return true;
			}
        }
        return false;
    }  

    protected function IOChangeState($State) {
        if ($State == IS_ACTIVE) {	// Wenn der IO Aktiv wurde
            //$this->startCommunication();  / TODO
        }
        else { // und wenn nicht setzen wir uns auf inactive
            $this->SetStatus(IS_INACTIVE);
        }
    }  	


	/**
	 * Wird ausgefÃ¼hrt wenn der Kernel hochgefahren wurde.
	 * @access protected
	 */
	protected function KernelReady() {
		$this->ApplyChanges();
	}
	
	//Profile
	protected function RegisterVariableProfile($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Vartype) {
		if (!IPS_VariableProfileExists($Name)) {
			IPS_CreateVariableProfile($Name, $Vartype); // 0 boolean, 1 int, 2 float, 3 string,
		} else {
			$profile = IPS_GetVariableProfile($Name);
			if ($profile['ProfileType'] != $Vartype)
				$this->AddDebugLogEntry(__FUNCTION__ , "Variable profile type does not match for profile " . $Name, 0);
		}
		IPS_SetVariableProfileIcon($Name, $Icon);
		IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
		IPS_SetVariableProfileDigits($Name, $Digits); //  Nachkommastellen
		IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize); // string $ProfilName, float $Minimalwert, float $Maximalwert, float $Schrittweite
	}

	protected function RegisterProfileAssociation($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Vartype, $Associations) {
		if (sizeof($Associations) === 0) {
			$MinValue = 0;
			$MaxValue = 0;
		}
		$this->RegisterProfile($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Vartype);

		//boolean IPS_SetVariableProfileAssociation ( string $ProfilName, float $Wert, string $Name, string $Icon, integer $Farbe )
		foreach ($Associations as $Association) {
			IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
		}
	}

	
	/***********************************************************
	 * Migrations
	 ***********************************************************/

	/**
	 * Polyfill for IP-Symcon 4.4 and older
	 * @param string $Ident
	 * @param mixed $Value
	 */
	//Add this Polyfill for IP-Symcon 4.4 and older
	protected function SetValue($Ident, $Value) {
		if (IPS_GetKernelVersion() >= 5) {
			parent::SetValue($Ident, $Value);
		} else {
			SetValue($this->GetIDForIdent($Ident), $Value);
		}
	}	
}