<?php 

trait API {

    static $CloudLoginBaseURL = 'https://ha101-1.overkiz.com/enduser-mobile-web/enduserAPI/login';
    static $USER_AGENT = 'IPS/x.x';


    protected function CloudAuthentication() {
        //Login, Generate and Activate a Token for local API access
        $result = false;

        return $result;
    }


    public function GetTaHomaDevices() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $result = $this->fetchApiData("setup_devices", sprintf("%s/setup/devices", $this->tahomaApiBaseURL));
            $this->profilingEnd(__FUNCTION__);
        } catch (\Exception|\Throwable $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            throw $e;
        } 
        return $result;
    }


    private function fetchApiData($apiName, $url) {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] URL: %s", $apiName, $url ), 0); }

            $res =	$this->client->request('GET', $url,
                [
                    'headers' => [
                        'user-agent' => self::$USER_AGENT,
                        'accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->token,
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'http_errors' => false,
                    'verify' => false
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Status: %s ", $apiName, $statusCode), 0); }
            SetValue($this->GetIDForIdent("updateHttpStatus"), $statusCode);

            if($statusCode == 200) {
                $result = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Data: %s",  $apiName, $result), 0); }

                 //$resultData = json_decode($resultData , true); 
                //if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Json: %s", $apiName, print_r($resultData, true)), 0); }	
            
                $this->profilingEnd(__FUNCTION__, false);

            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                //if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $msg, 0); }  
                throw new Exception($msg);        
            }
            return $result;

        } catch (Exception $e) {
            $result = false;
            $msg = $e->getMessage();
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            //throw new Exception($msg, 10, $e); 
            throw $e;    
        }
    }




    private function TaHomaExecuteCommand($trigger, $deviceUrl, $command, $commandParameter=null) {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $url = sprintf("%s/exec/apply", $this->tahomaApiBaseURL);

            //Todo -> command switch-case
          
            $actionGroup = [];
            $actionGroup["label"] = $trigger . "_" . $command;
            $actionGroup["actions"][0]["commands"][0]["type"] = 0;
            $actionGroup["actions"][0]["commands"][0]["name"] =  $command;

            if(is_null($commandParameter)) {
                $actionGroup["actions"][0]["commands"][0]["parameters"] = [];
            } else {
                $actionGroup["actions"][0]["commands"][0]["parameters"] = $commandParameter;
            }



            $actionGroup["actions"][0]["deviceURL"] = $deviceUrl;
            
            //$json_string = json_encode($actionGroup, JSON_PRETTY_PRINT);
            $json_string = json_encode($actionGroup);

            $logMsg = sprintf("POST Exec-ActionGroup: %s '%s'", $url, $json_string );
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, $logMsg, 0); }

            $this->WebFrontVarAddText($this->GetIDForIdent("lastTaHomaCommands"), $logMsg, 4000);

            $res =	$this->client->request('POST', $url,
                [
                    'headers' => [
                        'user-agent' => self::$USER_AGENT,
                        'accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->token,
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'http_errors' => false,
                    'verify' => false,
                    'body' =>  $json_string
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s ", $statusCode), 0); }
            SetValue($this->GetIDForIdent("updateHttpStatus"), $statusCode);

            if($statusCode == 200) {
                $result = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Data: %s",  $result), 0); }

                 //$resultData = json_decode($resultData , true); 
                //if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Json: %s", $apiName, print_r($resultData, true)), 0); }	
            
                $this->profilingEnd(__FUNCTION__, false);
            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("WARN - Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, $msg, 0); }         
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            //throw new Exception($msg, 10, $e); 
        }
        return $result;
    }


}


?>