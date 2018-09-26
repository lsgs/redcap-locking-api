<?php
/**
 * REDCap External Module: Locking API
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
error_reporting(0);

try {
        $result = $module->readStatus();
} catch (Exception $ex) {
        RestUtility::sendResponse(400, $ex->getMessage());
}
RestUtility::sendResponse(200, $result);