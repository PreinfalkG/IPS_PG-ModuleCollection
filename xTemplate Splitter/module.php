<?

class TemplateSplitter extends IPSModule
{
	private $debugLevel = 4;
	private $enableIPSLogOutput = false;

	public function __construct($InstanceID) {
		
		parent::__construct($InstanceID);				// Diese Zeile nicht löschen
		
		$currentStatus = $this->GetStatus();
		if($currentStatus == 102) {						//Instanz ist aktiv
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
		
        parent::Create();								//Never delete this line!
		
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
		
		$this->RegisterPropertyInteger("DebugLevel", 4);
		$this->RegisterPropertyBoolean('EnableIPSLogOutput', false);			
		
		//Vars
		$this->RegisterVariableString("BufferIN", "BufferIN", "", -4);	
		
		//These lines are parsed on Symcon Startup or Instance creation
        //You cannot use variables here. Just static values.
		$this->RequireParent("{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}"); //  I/O
    }
	
	public function Destroy() {
		parent::Destroy();								//Never delete this line!
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
	}	

    public function ApplyChanges() {
	
        parent::ApplyChanges();							//Never delete this line!
		
		if($this->debugLevel >= 6) { $this->AddDebugLogEntry(__FUNCTION__, "called ...", 0); }
		
		$this->debugLevel = $this->ReadPropertyInteger("DebugLevel");
		$this->AddDebugLogEntry(__FUNCTION__, sprintf("INFO :: Set Debug Level  to %d", $this->debugLevel), 0);
		
		$this->enableIPSLogOutput = $this->ReadPropertyBoolean("EnableIPSLogOutput");	
		$this->AddDebugLogEntry(__FUNCTION__, sprintf("INFO :: Set IPS-Log-Output  to %b", $this->enableIPSLogOutput), 0);	   
	      	   
    }
    
    // Type String, Declaration can be used when PHP 7 is available
    // public function ReceiveData(string $JSONString)
	public function ReceiveData($JSONString)
	{	 
		// Empfangene Daten vom I/O
		$data = json_decode($JSONString);
		if($this->debugLevel >= 5) { $this->AddDebugLogEntry(__FUNCTION__, $data->Buffer, 1); }
		if($this->debugLevel >= 5) { $this->AddDebugLogEntry(__FUNCTION__ . " (UTF8 decoded)", utf8_decode($data->Buffer), 1); }
		
		$this->SendDataToChildren(json_encode(Array("DataID" => "{DAAABEF0-127D-FF0A-6A74-38F9459D237B}", "Buffer" => $data->Buffer))); // Splitter Interface GUI						
	}
	
	// Type String, Declaration can be used when PHP 7 is available
    //public function ForwardData(string $JSONString)
    public function ForwardData($JSONString)
	{
		// Empfangene Daten von der Device Instanz
		$data = json_decode($JSONString);
		if($this->debugLevel >= 4) { $this->AddDebugLogEntry(__FUNCTION__ . ">SendDataToParent", $data->Buffer, 1); }
		
		// Weiterleiten zur I/O Instanz
		$result = $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $data->Buffer))); // TX GUI
		
		//ECHO
		$this->SendDataToChildren(json_encode(Array("DataID" => "{DAAABEF0-127D-FF0A-6A74-38F9459D237B}", "Buffer" => $data->Buffer)));
		
		return $result;	 
	}
	
	private function startsWith($haystack, $needle) {
		return strpos($haystack, $needle) === 0;
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
		for ($i=0; $i < strlen($string); $i++){
			//$hex .= dechex(ord($string[$i]));
			$hex .= "0x" . sprintf("%02X", ord($string[$i])) . " ";
		}
		return $hex;
	}
	
	
}