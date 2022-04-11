<? 
const INSTANZID = %%INSTANZID%%;
const VARID_LastCommands = %%VARID_LastCommands%%; 

$ipsVarId = $_IPS["VARIABLE"]; 
$ipsValue = $_IPS["VALUE"]; 

$deviceURL = IPS_GetObject($ipsVarId)["ObjectInfo"];

$parentId = IPS_GetParent($ipsVarId);
$parentName = IPS_GetName($parentId);

if($ipsValue == 101) { $ipsValue = 99; }
if($ipsValue > 101) { $ipsValue = 50; }

$return = TaHomaSwitch_SendCommandRTS(INSTANZID, "actionSkript", $deviceURL, $ipsValue, $ipsVarId);
SetValue($ipsVarId, $return); 

$logText = sprintf("%s WebFront Action :: RTS '%s' [%s] | %s {return: %s} @%s\n", date('d.m.Y H:i:s',time()), $parentName, $ipsVarId, GetValueFormatted($ipsVarId), $return, $deviceURL);
$tempLogText = GetValue(VARID_LastCommands);
$logText = $logText . $tempLogText;
if(strlen($logText) > 4000) { $logText = substr($logText, 0, 4000); }
SetValue(VARID_LastCommands, $logText);

?>