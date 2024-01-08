<?

declare(strict_types=1);

abstract class LogLevel {
    const ALL = 9;
    const TEST = 8;
    const TRACE = 7;
    const COMMUNICATION = 6;
    const DEBUG = 5;
    const INFO = 4;
    const WARN = 3;
    const ERROR = 2;
    const FATAL = 1;
}

abstract class VARIABLE {
    const TYPE_BOOLEAN = 0;
    const TYPE_INTEGER = 1;
    const TYPE_FLOAT = 2;
    const TYPE_STRING = 3;
}


trait COMMON_FUNCTIONS {



    public function __get($name) {
        return unserialize($this->GetBuffer($name));
    }

    public function __set($name, $value) {
        $this->SetBuffer($name, serialize($value));
    }




    protected function CreateCategoryByIdent($identName, $categoryName, $parentId, $position = 0, $iocon = "") {

        $identName = $this->GetValidIdent($identName);
        $categoryId = @IPS_GetObjectIDByIdent($identName, $parentId);
        if ($categoryId === false) {

            if ($this->logLevel >= LogLevel::TRACE) {
                $this->AddLog(
                    __FUNCTION__,
                    sprintf("Create IPS-Category :: Name: %s | Ident: %s | ParentId: %s", $categoryName, $identName, $parentId)
                );
            }

            $categoryId = IPS_CreateCategory();
            IPS_SetParent($categoryId, $parentId);
            IPS_SetIdent($categoryId, $identName);
            IPS_SetName($categoryId, $categoryName);
            IPS_SetPosition($categoryId, $position);
            if (!empty($icon)) {
                IPS_SetIcon($categoryId, $icon);
            }
        } else {
            if ($this->logLevel >= LogLevel::TRACE) {
                $this->AddLog(__FUNCTION__, sprintf("IPS-Category exists:: Name: %s | Ident: %s | ParentId: %s", $categoryName, $identName, $parentId)
                );
            }
        }
        return $categoryId;
    }


    protected function CreateDummyInstanceByIdent($identName, $instanceName, $parentId, $position = 0, $icon = "") {

        $identName = $this->GetValidIdent($identName);
        $instanceId = @IPS_GetObjectIDByIdent($identName, $parentId);
        if ($instanceId === false) {

            if ($this->logLevel >= LogLevel::TRACE) {
                $this->AddLog(
                    __FUNCTION__,
                    sprintf("Create IPS-DummyInstance :: Name: %s | Ident: %s | ParentId: %s", $instanceName, $identName, $parentId)
                );
            }

            $instanceId = IPS_CreateInstance("{485D0419-BE97-4548-AA9C-C083EB82E61E}");
            IPS_SetParent($instanceId, $parentId);
            IPS_SetIdent($instanceId, $identName);
            IPS_SetName($instanceId, $instanceName);
            IPS_SetPosition($instanceId, $position);
            if (!empty($icon)) {
                IPS_SetIcon($instanceId, $icon);
            }
        } else {
            if ($this->logLevel >= LogLevel::TRACE) {
                $this->AddLog(__FUNCTION__, sprintf("IPS-DummyInstance exists:: Name: %s | Ident: %s | ParentId: %s", $instanceName, $identName, $parentId)
                );
            }
        }
        return $instanceId;
    }


    protected function SetVariableByIdent($value, $identName, $varName, $parentId, $varType = 3, $position = 0, $varProfile = "", $icon = "", $forceUpdate = false, $round = null, $faktor = 1) {

        $variable_created = false;
        $identName = $this->GetValidIdent($identName);
        $varId = @IPS_GetObjectIDByIdent($identName, $parentId);
        if ($varId === false) {
            if ($varType < 0) {
                $varType = $this->GetTypeFromValue($value);
            }

            if ($this->logLevel >= LogLevel::TRACE) {
                $this->AddLog(__FUNCTION__, sprintf("Create IPS-Variable :: Type: %d | Ident: %s | Profile: %s | Name: %s", $varType, $identName, $varProfile, $varName));
            }
            $varId = IPS_CreateVariable($varType);
            $variable_created = true;
        }

        if ($variable_created || $forceUpdate) {
            IPS_SetParent($varId, $parentId);
            IPS_SetName($varId, $varName);
            IPS_SetIdent($varId, $identName);
            IPS_SetPosition($varId, $position);
            IPS_SetVariableCustomProfile($varId, $varProfile);
            if (!empty($icon)) {
                IPS_SetIcon($varId, $icon);
            }
            //$archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
            //AC_SetLoggingStatus ($archivInstanzID, $varId, true);
            //IPS_ApplyChanges($archivInstanzID);
        }

        if ($faktor != 1) {
            $value = $value * $faktor;
        }

        if (!is_null($round)) {
            $value = round($value, $round);
        }

        $result = SetValue($varId, $value);

        if (!$result) {
            if ($this->logLevel >= LogLevel::WARN) {
                $this->AddLog(__FUNCTION__, sprintf("WARN :: Cannot save Variable '%s' with value '%s' [parentId: %s | identName: %s | varId: %s | type: %s]", $varName, print_r($value), $parentId, $identName, $varId, gettype($value)));
            }
            return false;
        } else {
            return $varId;
        }
    }

    protected function GetValidIdent($ident) {

        $ident = strtr($ident, [
            '-' => '_',
            ' ' => '_',
            ':' => '_',
            '%' => 'p'
        ]);

        $ident = preg_replace('/[^A-Za-z0-9\.\_]/', '', $ident);
        return $ident;
    }

    protected function GetTypeFromValue($value) {
        $varType = VARIABLE::TYPE_STRING;
        switch (gettype($value)) {
            case "boolean":
                $varType = VARIABLE::TYPE_BOOLEAN;
                break;
            case "integer":
                $varType = VARIABLE::TYPE_INTEGER;
                break;
            case "double":
            case "float":
                $varType = VARIABLE::TYPE_FLOAT;
                break;
            default:
                $varType = VARIABLE::TYPE_STRING;
                break;
        }
        return $varType;
    }

    protected function Increase_CounterVariable(int $varId) {
        SetValueInteger($varId, GetValueInteger($varId) + 1);
    }

    function UnixTimestamp2String(int $timestamp) {
        return date('d.m.Y H:i:s', $timestamp);
    }

    protected function String2Hex($string) {
        $hex = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $hex .= sprintf("%02X", ord($string[$i])) . " ";
        }
        return trim($hex);
    }


    protected function LoadFileContents($fileName) {
        if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Load File Content form '%s'", $fileName)); }	
        return file_get_contents($fileName);
    }

    protected function CalcDuration_ms(float $timeStart) {
        $duration =  microtime(true) - $timeStart;
        return round($duration * 1000, 2);
    }
}
