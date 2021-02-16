<?php
/**
 * REDCap External Module: Locking API
 * @author Luke Stevens, Murdoch Children's Research Institute
 * @author Ekin Tertemiz, Swiss Tropical and Public Health Institute
 */
error_reporting(0);

try {
        $result = $module->updateLockStatus(true);
} catch (Exception $ex) {
        RestUtility::sendResponse(400, $ex->getMessage());
}
RestUtility::sendResponse(200, $result);