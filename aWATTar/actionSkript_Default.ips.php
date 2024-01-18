<?php

const INSTANCE_ID = %%INSTANZ_ID%%;


$ipsVariable = $_IPS['VARIABLE'];
SetValue($ipsVariable, $_IPS['VALUE']);

//aWATTar_UpdatePriceBasedSwitches(INSTANCE_ID, 'ActionSkript', true);
$parrentId = IPS_GetParent($ipsVariable);
aWATTar_UpdatePriceBasedSwitch(INSTANCE_ID, 'ActionSkript', $parrentId);

?>