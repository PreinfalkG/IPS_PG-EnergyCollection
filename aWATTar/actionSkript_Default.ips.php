<?php

const INSTANCE_ID = %%INSTANZ_ID%%;


$ipsVariable = $_IPS['VARIABLE'];
SetValue($ipsVariable, $_IPS['VALUE']);


$identName = IPS_GetObject($ipsVariable)["ObjectIdent"];
switch($identName) {
    case '_duration':
    case '_timeWindowStart':
    case '_timeWindowEnd':
        $timeSpan = GetValueInteger($ipsVariable);
        if(($timeSpan % 3600) != 0) {
            $timeSpan = strtotime(date('d.m.Y H:00:00', $timeSpan));
            SetValueInteger($ipsVariable, $timeSpan);
        }
        break;
}


//aWATTar_UpdatePriceBasedSwitches(INSTANCE_ID, 'ActionSkript', true);
$parrentId = IPS_GetParent($ipsVariable);
aWATTar_UpdatePriceBasedSwitch(INSTANCE_ID, 'ActionSkript', $parrentId);

?>