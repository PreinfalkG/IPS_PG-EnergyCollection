<? 

$ipsVarId = $_IPS["VARIABLE"]; 
$ipsValue = $_IPS["VALUE"]; 

const INSTANCE_ID = %%INSTANZ_ID%%;

SetValue($ipsVarId, $ipsValue);

$parentId = IPS_GetParent($ipsVarId);

$varId_mode = IPS_GetObjectIDByIdent("_mode", $parentId);
$varId_timeWindowStart = IPS_GetObjectIDByIdent("_timeWindowStart", $parentId);
$varId_timeWindowEnd = IPS_GetObjectIDByIdent("_timeWindowEnd", $parentId);
$varId_duration = IPS_GetObjectIDByIdent("_duration", $parentId);
$varId_continuousHours = IPS_GetObjectIDByIdent("_continuousHours", $parentId);
$varId_threshold = IPS_GetObjectIDByIdent("_threshold", $parentId);
$varId_switch = IPS_GetObjectIDByIdent("_switch", $parentId);
$varId_wochenplan = IPS_GetObjectIDByIdent("_wochenplan", $varId_switch);
//IPS_SetEventScheduleGroup($varId_wochenplan, 0, 127);

$varId_data = IPS_GetObjectIDByIdent("_data", $parentId);

switch($ipsValue) {
    case 0:
        IPS_SetDisabled($varId_timeWindowStart, true);
        IPS_SetHidden($varId_timeWindowStart, true);
        IPS_SetDisabled($varId_timeWindowEnd, true);
        IPS_SetHidden($varId_timeWindowEnd, true);
        IPS_SetDisabled($varId_duration, true);
        IPS_SetHidden($varId_duration, true);
        IPS_SetDisabled($varId_continuousHours, true);
        IPS_SetHidden($varId_continuousHours, true);
        IPS_SetDisabled($varId_threshold, true);
        IPS_SetHidden($varId_threshold, true);
        //IPS_SetDisabled($varId_switch, false);
        //IPS_SetHidden($varId_switch, false);
        IPS_SetDisabled($varId_wochenplan, true);
        IPS_SetHidden($varId_wochenplan, true);  
        IPS_SetEventActive($varId_wochenplan, false);            
        IPS_SetDisabled($varId_data, false);
        IPS_SetHidden($varId_data, false);    
        SetValueBoolean($varId_switch, false);                            
        SetValue($varId_data, "Der Virtuelle Schalter ist immer AUS");
        aWATTar_UpdatePriceBasedSwitch(INSTANCE_ID, 'ActionSkript',  $parentId);
        break;
    case 1:
        IPS_SetDisabled($varId_timeWindowStart, true);
        IPS_SetHidden($varId_timeWindowStart, true);
        IPS_SetDisabled($varId_timeWindowEnd, true);
        IPS_SetHidden($varId_timeWindowEnd, true);        
        IPS_SetDisabled($varId_duration, true);
        IPS_SetHidden($varId_duration, true);
        IPS_SetDisabled($varId_continuousHours, true);
        IPS_SetHidden($varId_continuousHours, true);
        IPS_SetDisabled($varId_threshold, true);
        IPS_SetHidden($varId_threshold, true);    
        //IPS_SetDisabled($varId_switch, false);
        //IPS_SetHidden($varId_switch, false);
        IPS_SetDisabled($varId_wochenplan, true);
        IPS_SetHidden($varId_wochenplan, true); 
        IPS_SetEventActive($varId_wochenplan, false);                                    
        IPS_SetDisabled($varId_data, false);
        IPS_SetHidden($varId_data, false);    
        SetValueBoolean($varId_switch, true);                            
        SetValue($varId_data, "Der Virtuelle Schalter ist immer EIN");    
        aWATTar_UpdatePriceBasedSwitch(INSTANCE_ID, 'ActionSkript',  $parentId);
        break;
    case 2:
        IPS_SetDisabled($varId_timeWindowStart, false);
        IPS_SetHidden($varId_timeWindowStart, false);
        IPS_SetDisabled($varId_timeWindowEnd, false);
        IPS_SetHidden($varId_timeWindowEnd, false);        
        IPS_SetDisabled($varId_duration, false);
        IPS_SetHidden($varId_duration, false);
        IPS_SetDisabled($varId_continuousHours, false);
        IPS_SetHidden($varId_continuousHours, false);
        IPS_SetDisabled($varId_threshold, false);
        IPS_SetHidden($varId_threshold, false);
        //IPS_SetDisabled($varId_switch, false);
        //IPS_SetHidden($varId_switch, false);
        IPS_SetDisabled($varId_wochenplan, false);
        IPS_SetHidden($varId_wochenplan, false);   
        IPS_SetEventActive($varId_wochenplan, false);  
        IPS_SetDisabled($varId_data, false);
        IPS_SetHidden($varId_data, false);    
        SetValueBoolean($varId_switch, false);        
        SetValue($varId_data, "xxXXxX");     
        aWATTar_UpdatePriceBasedSwitch(INSTANCE_ID, 'ActionSkript',  $parentId);   
        break;
    case 3:
        IPS_SetDisabled($varId_timeWindowStart, false);
        IPS_SetHidden($varId_timeWindowStart, false);
        IPS_SetDisabled($varId_timeWindowEnd, false);
        IPS_SetHidden($varId_timeWindowEnd, false);           
        IPS_SetDisabled($varId_duration, false);
        IPS_SetHidden($varId_duration, false);
        IPS_SetDisabled($varId_continuousHours, false);
        IPS_SetHidden($varId_continuousHours, false);
        IPS_SetDisabled($varId_threshold, true);
        IPS_SetHidden($varId_threshold, true);
        //IPS_SetDisabled($varId_switch, false);
        //IPS_SetHidden($varId_switch, false);
        IPS_SetDisabled($varId_wochenplan, false);
        IPS_SetHidden($varId_wochenplan, false);         
        IPS_SetEventActive($varId_wochenplan, false);  
        IPS_SetDisabled($varId_data, false);
        IPS_SetHidden($varId_data, false);    
        SetValueBoolean($varId_switch, false);                            
        SetValue($varId_data, "yyYYyy");       
        aWATTar_UpdatePriceBasedSwitch(INSTANCE_ID, 'ActionSkript',  $parentId);
        break;
    default:
        break;
}

?>