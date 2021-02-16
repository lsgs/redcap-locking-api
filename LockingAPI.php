<?php
/**
 * REDCap External Module: Locking API
 * Lock, unlock and read the lock status of instruments using API calls
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\LockingAPI;

use DateTime;
use ExternalModules\ExternalModules;
use ExternalModules\AbstractExternalModule;
use Logging;
use Project;
use REDCap;
use RestUtility;
use Locking;

/**
 * REDCap External Module: Locking API
 */
class LockingAPI extends AbstractExternalModule
{
        protected static $AllowedFormats = array('json', 'csv', 'xml');
        
        protected static function errorResponse($message) {
                self::sendResponse(400, $message);
        }
        
        protected static function sendResponse($status=200, $response='') {
                RestUtility::sendResponse($status, $response);
        }

        private $lang;
        private $page;
        private $project_id;
        private $super_user;
        private $user;
        private $user_rights;
        private $get;
        private $post;
        private $request;
        private $returnFormat;
        private $record;
        private $event_id;
        private $instrument;
        private $instance;
        private $lock_status;
        private $lock_record_level;
        private $lock_record_status;
        private $arm;
        private $arm_id;
        private $format;

        public function __construct() {
                parent::__construct();
                global $lang, $user_rights;
                $this->page = PAGE;
                $this->lang = &$lang;
                $this->user_rights = &$user_rights;
                $this->get = &$_GET;
                $this->post = &$_POST;
        }

        protected function isModuleEnabledForProject() {
                return ExternalModules::getProjectSetting($this->PREFIX, $this->project_id, ExternalModules::KEY_ENABLED);
        }
        
        protected function processLockingApiRequest() {
                global $Proj, $longitudinal;
                
                $this->request = RestUtility::processRequest(true);

                $this->user = $this->request->getRequestVars()['username'];
                $this->super_user = SUPER_USER=='1';
                $this->project_id = $this->request->getRequestVars()['projectid'];
                $Proj = $this->Proj = new Project($this->project_id);
                $longitudinal = $this->Proj->longitudinal;
                
                if(!$this->isModuleEnabledForProject()) { 
                        self::errorResponse("The requested module is currently disabled on this project."); 
                }

                $rights = REDCap::getUserRights($this->user);
                $this->user_rights = $rights[$this->user];

                $this->validatePostParams();

                if ($this->get['page']!=='status' && !$this->user_rights['lock_record'] && !$this->super_user) {
                        self::sendResponse(403, "You do not have Lock/Unlock Records permission"); 
                }
        }

        protected function validatePostParams() {
                if (!isset($this->Proj)) { throw new Exception("Can't validate POST params without first setting Proj."); }
                $this->returnFormat = $this->validateReturnFormat();
                $this->lock_record_level = $this->validateLockRecordLevel();
                $this->format = $this->validateFormat();                
                $this->record = $this->validateRecord();
                $this->event_id = $this->validateEvent();
                $this->instrument = $this->validateInstrument();
                $this->instance = $this->validateInstance();
                $this->arm = $this->validateArm();

        }
        
        protected function validateReturnFormat() {
                if (isset($this->post['returnFormat']) && $this->post['returnFormat']!=='') {
                        if (!in_array($this->request->getRequestVars()['returnFormat'], static::$AllowedFormats)) {
                                self::errorResponse("Unrecognised return format specified: '".$this->post['returnFormat']."'"); 
                        }
                }
                
                return (isset($this->post['returnFormat']) && $this->post['returnFormat']!=='')
                        ? $this->post['returnFormat'] 
                        : 'xml';
        }

        protected function validateRecord() {
                if (!isset($this->post['record']) || $this->post['record']==='') {
                        self::errorResponse("Record(s) not supplied.");
                } 

                # Accept multiple records and check if they exist if input format is json
                if($this->format == 'json') {

                        $records = array();

                        if(is_array($this->post['record'])) {
                                # Support array parameter via php/curl
                                $records = $this->post['record'];
                        } else {
                                # Transform json into array
                                $records = json_decode($this->post['record']);
                        }
                        
                        # Taken and edited from API > record > delete.php:delRecords()
                        // First check if all records submitted exist
	                $existingRecords = \Records::getData('array', $records, $this->Proj->table_pk);
                        // Return error if some records don't exist
                        if (count($existingRecords) != count($records)) {
                                self::errorResponse("One or more of the supplied records do not exist. Not existing record IDs:" . " " . implode(", ", array_diff($records, array_keys($existingRecords))));
                        }

                        return $records;
                }
                else {
                        $this->post['record'] = urldecode($this->post['record']);
                
                        $rec = REDCap::getData(array(
                                'records'=>$this->post['record'],
                                'groups'=>$this->user_rights['group_id']
                            ));
        
                        
                        if (count($rec)===0) { 
                                self::errorResponse("Record '".$this->post['record']."' not found."); 
                        }

                        return $this->post['record'];
                }

                
        }
        
        protected function validateEvent() {
                $eventId = '';
                if ($this->Proj->longitudinal && isset($this->post['event']) && is_int($this->post['event'])) {
                        if (false===REDCap::getEventNames(false, false, $this->post['event'])) { self::errorResponse("Invalid event id ".$this->post['event']); }
                        $eventId = "{$this->post['event']}";
                } else if ($this->Proj->longitudinal && isset($this->post['event'])) {
                        $eventId = REDCap::getEventIdFromUniqueEvent($this->post['event']);
                        if ($eventId===false) { self::errorResponse("Invalid event name ".$this->post['event']); }
                }
                return $eventId;
        }
        
        protected function validateInstrument() {
                $formname = '';
                if (isset($this->post['instrument'])) {
                        $formname = $this->post['instrument'];
                        if (false===REDCap::getInstrumentNames($formname)) { 
                                self::errorResponse("Invalid instrument $formname"); 
                        }

                        // if event supplied, check that event/instrument combo is valid
                        if ($this->event_id!=='' && !in_array($formname.'_complete', REDCap::getValidFieldsByEvents($this->project_id, $this->event_id, false))) { 
                                self::errorResponse("Invalid event/instrument combination: '".REDCap::getEventNames(true, false, $this->event_id)."' / '$formname'"); 
                        }
                }
                return $formname;
        }
        
        protected function validateInstance() {
                $instance = '';
                // if instance specified, check event or instrument is repeating
                if (isset($this->post['instance'])) {
                        $instance = $this->post['instance'];
                        
                        if ((int)$instance > 1) { 
                                if ($this->instrument==='') {
                                        if (!$this->Proj->isRepeatingFormOrEvent($this->event_id, null)) {
                                                self::errorResponse("Not a repeating event: '".REDCap::getEventNames(true, false, $this->event_id)."'"); 
                                        }
                                } else {
                                        if ($this->event_id!=='' && !$this->Proj->isRepeatingFormOrEvent($this->event_id, $this->instrument)) {
                                                self::errorResponse("Invalid repeating event/instrument combination: '".$this->event_id."' / '".$this->instrument."'"); 
                                        }
                                }
                        }
                        
                        if ((int)$instance < 1 ) {
                                self::errorResponse("Invalid instance value $instance"); 
                        }
                }
                return $instance;
        }

        protected function validateLockRecordLevel() {
                $lock_record_level = false;
                if(isset($this->post['lock_record_level'])  && $this->post['lock_record_level']!=='' && $this->post['lock_record_level']!== 'false' ) {
                        $lock_record_level = $this->post['lock_record_level'];
                }

                return $lock_record_level;
        }

        public function validateFormat() {
                $format = "";
                if(isset($this->post['format']) && $this->post['format']!=='' ) {

                        if($this->post['format'] == 'json') {

                                if($this->lock_record_level != true) {
                                        self::errorResponse("JSON format is not yet supported for this type of request.");
                                        exit();
                                }

                                $format = $this->post['format'];
                        }
                }

                return $format;
        }

        public function validateArm() {
                $arm = 1;
                if( isset($this->post['arm']) && $this->post['arm']!=='' ) {
                        # Check if arm exists
                        if( isset($this->Proj->events[$this->post['arm']]['id']) ) {                                
                                # Check if record exists within arm                                                              
                                $recordInArm = $recordInArm = count(\Records::getRecordList( $this->project_id, array(), false, false, $this->post['arm'], null, 0, $this->record ));

                                if( $recordInArm > 0 ) {
                                        $arm = $this->post['arm'];
                                }
                                else {
                                        $invalid_arm = $this->post['arm'];
                                        self::errorResponse("Record with ID $this->record is not included in arm $invalid_arm");   
                                }
                        }
                        else {
                                self::errorResponse("Invalid arm $arm"); 
                        }
                }
                $this->arm_id = $this->Proj->events[$arm]['id'];
                return $arm;
        }
               
        public function readCurrentLockStatus() {
                if ($this->Proj->longitudinal) {
                        $events = REDCap::getEventNames(true, false);
                } else {
                        $events[$this->Proj->firstEventId] = '';
                }
                $forms = REDCap::getInstrumentNames();
                
                // make array of event-form mapping
                $eventForms = array();
                $includedForms = array();
                $includedFormStatusFields = array();
                foreach ($events as $eventId => $event_name) {
                        
                        if ($this->event_id==='' || $this->event_id===$eventId) {
                                if ($this->Proj->longitudinal) {
                                        $eventFields = REDCap::getValidFieldsByEvents($this->project_id, $event_name, false);
                                } else {
                                        $eventFields = REDCap::getFieldNames();
                                }
                                foreach (array_keys($forms) as $form_name) {
                                        if (($this->instrument==='' || $this->instrument===$form_name) && in_array($form_name.'_complete', $eventFields)) {
                                                $includedForms[] = $form_name;
                                                $includedFormStatusFields[] = $form_name.'_complete';
                                                $eventForms[$eventId]['event_name'] = $event_name;
                                                $eventForms[$eventId]['event_forms'][$form_name]['is_repeating'] = $this->Proj->isRepeatingFormOrEvent($eventId, $form_name);
                                                $eventForms[$eventId]['event_forms'][$form_name]['data'] = array();
                                        }
                                }
                        }
                }
                
                // read recorded form status values
                $sql = "select record, event_id, field_name, value, instance from redcap_data where project_id=".db_escape($this->project_id)." and record='".db_escape($this->record)."' ";
                $sql .= "and event_id in (".implode(',',array_keys($eventForms)).") ";
                $sql .= "and field_name in ('".implode("','",$includedFormStatusFields)."') ";

                $r = db_query($sql);
                if ($r->num_rows > 0) {
                    while ($row = $r->fetch_assoc()) {
                        $e = $row['event_id'];
                        $f = substr($row['field_name'], 0, strlen($row['field_name'])-9); // rtrim($row['field_name'], '_complete');
                        $i = is_null($row['instance'])?'1':$row['instance'];
                        $v = $row['value'];
                        if ($this->instance==='' || $this->instance===$i) {
                            $eventForms[$e]['event_forms'][$f]['data'][$i]['form_status'] = $v;
                        }
                    }
                }
                
                // read current lock status (form cannot be locked and have no data - always at least form status = '0'
                $sql = "select record,event_id,form_name,instance,username,timestamp from redcap_locking_data where project_id=".db_escape($this->project_id)." and record='".db_escape($this->record)."' ";
                $sql .= "and event_id in (".implode(',',array_keys($eventForms)).") ";
                $sql .= "and form_name in ('".implode("','",$includedForms)."') ";
                $sql .= "order by record,event_id,form_name,instance ";

                $r = db_query($sql);
                if ($r->num_rows > 0) {
                    while ($row = $r->fetch_assoc()) {
                        $e = $row['event_id'];
                        $f = $row['form_name'];
                        $i = $row['instance']; // always 1+, never null
                        $u = $row['username'];
                        $t = $row['timestamp'];
                        if ($this->instance==='' || $this->instance===$i) {
                            $eventForms[$e]['event_forms'][$f]['data'][$i]['username'] = $u;
                            $eventForms[$e]['event_forms'][$f]['data'][$i]['timestamp'] = $t;
                        }
                    }
                }

                $this->lock_status = $eventForms;
        }

        public function handleLockRecordLevel(bool $lock) {

                foreach($this->record as $record) {
                        $isWholeRecordLocked = Locking::isWholeRecordLocked($this->project_id, $record, $this->arm);
                        if($lock == true && !$isWholeRecordLocked) {
                                Locking::lockWholeRecord($this->project_id, $record, $this->arm);
                        } 
                        else if ($lock == false && $isWholeRecordLocked) {
                                Locking::unlockWholeRecord($this->project_id, $record, $this->arm);
                        }
                }

        }

             
        public function readStatus() {
                $this->processLockingApiRequest();
                if($this->lock_record_level == true) {
                        $this->readLockRecordLevelStatus();
                        return $this->returnLockRecordLevel();
                }
                else {
                        $this->readCurrentLockStatus();
                        return $this->formatReturnData();
                }

        }

        public function readLockRecordLevelStatus() {

                # Prepare Query
                $query = $this->createQuery();
                $query->add('
                        SELECT * 
                        FROM `redcap_locking_records`                         
                        WHERE `project_id` = ? 
                        AND `arm_id` = ?
                ',
                [                        
                        $this->project_id,
                        $this->arm_id
                ]);                
                $query->add('and')->addInClause('record', $this->record);

                $result = $query->execute();
                $unlocked_records = $this->record;

                # Push locked records into status response
                while($row = $result->fetch_assoc()) {
                        $this->lock_record_status[] = $row;
                        $key = array_search ($row["record"], $unlocked_records);
                        unset($unlocked_records[$key]);
                }

                # Push unlocked records into status response
                foreach ($unlocked_records as $unlocked_record) {

                        $this->lock_record_status[] = array( 
                                "lr_id" => null,
                                "project_id" => $this->project_id,
                                "record" => $unlocked_record, 
                                "arm_id" => $this->arm_id, 
                                "username" =>  null,
                                "timestamp" => null
                        );
                }


        }

        protected function returnLockRecordLevel() {              

                if($this->returnFormat == 'json') {
                        $response = json_encode($this->lock_record_status);
                }
                else if($this->returnFormat == 'csv') {

                        # Generate csv header from first object element, using object{0} to access
                        $response = implode(",", array_keys($this->lock_record_status{0}))."\n";
                        # Add rows as comma-separated list
                        foreach((array) $this->lock_record_status as $row) {
                                $response .= implode (", ", $row)."\n";
                        }
                        
                }
                else {
                        $response = '<?xml version="1.0" encoding="UTF-8" ?>';

                        foreach($this->lock_record_status as $status) {
                                $response .= '<lock_record_level_status>';

                                foreach($status as $key => $value) {
                                        $response .= "<$key>$value</$key>";
                                }
                                $response .= '</lock_record_level_status>';
                        }                        
                        $response .= "</xml>";
                }
                # Send response with correct formatting
                RestUtility::sendResponse(200, $response, $this->returnFormat);
                
        }
        
        public function lockInstruments() {
                return $this->updateLockStatus(true);
        }
        
        public function unlockInstruments() {
                return $this->updateLockStatus(false);
        }
        
        protected function updateLockStatus($lock=true) {
                $this->processLockingApiRequest();
        
                if($this->lock_record_level == true) {
                        $this->handleLockRecordLevel($lock);
                        $this->readLockRecordLevelStatus();

                        return $this->returnLockRecordLevel();
                }
                else {
                        $this->readCurrentLockStatus();

                        // update redcap_locking_data for submitted instruments
                        $toChange = array();
                        foreach ($this->lock_status as $eventId => $event) {
                                foreach ($event['event_forms'] as $form_name => $form_data) {
                                        if (count($form_data['data']) > 0) {
                                                // form has been saved (and is locked or not)
                                                foreach ($form_data['data'] as $instance => $instanceData) {
                                                        $locked = (isset($instanceData['username']));
                                                        if (($lock && !$locked) || (!$lock && $locked)) { // lock unlocked forms or un lock locked forms 
                                                                $toChange[] = array(
                                                                        'record'=>$this->record,
                                                                        'event_id'=>$eventId,
                                                                        'instrument'=>$form_name,
                                                                        'instance'=>$instance
                                                                );
                                                        }
                                                }
                                        }
                                }
                        }
                        
                        foreach ($toChange as $thisChange) {
                                if ($lock) {
                                        $result = $this->writeLock($thisChange['record'], $thisChange['event_id'], $thisChange['instrument'], $thisChange['instance']);
                                        if ($result !== false) {
                                                $this->lock_status[$thisChange['event_id']]['event_forms'][$thisChange['instrument']]['data'][$thisChange['instance']]['username'] = $this->user;
                                                $this->lock_status[$thisChange['event_id']]['event_forms'][$thisChange['instrument']]['data'][$thisChange['instance']]['timestamp'] = $result;
                                        }
                                } else {
                                        $result = $this->writeUnlock($thisChange['record'], $thisChange['event_id'], $thisChange['instrument'], $thisChange['instance']);
                                        if ($result) {
                                                $this->lock_status[$thisChange['event_id']]['event_forms'][$thisChange['instrument']]['data'][$thisChange['instance']]['username'] = '';
                                                $this->lock_status[$thisChange['event_id']]['event_forms'][$thisChange['instrument']]['data'][$thisChange['instance']]['timestamp'] = '';
                                        }
                                }
                        }

                        return $this->formatReturnData();
                }
                               
        }

        /** 
         * writeLock()
         * Unfortunately the core locking code is not callable so this is 
         * taken and edited from v8.6.5/Locking/single_form_action.php
         * @param string $record
         * @param string $eventId
         * @param string $instrument
         * @param string $instance
         * @return string $timestamp (false on failure)
         */
        protected function writeLock($record, $eventId, $instrument, $instance) {
                $return = false;
                $ts = (new DateTime())->format('Y-m-d H:i:s');
                $sql = "insert into redcap_locking_data (project_id, record, event_id, form_name, username, timestamp, instance) ".
                       "values ($this->project_id, '" . db_escape($record) . "', " . db_escape($eventId) . ", ".
                       "'" . db_escape($instrument) . "', '" . db_escape($this->user) . "', '".db_escape($ts)."', " . db_escape($instance) . ")";
                $description = "Lock record (".$this->PREFIX.")";
                
                if (db_query($sql)) {
                        $log = "Record: $record";
                        if ($this->Proj->longitudinal) { 
                                $log .= "\nEvent: ". REDCap::getEventNames(false, true, $eventId);
                        }
                        $log .= "\nForm: $instrument\nInstance: $instance";
                        Logging::logEvent($sql,"redcap_locking_data","LOCK_RECORD",$record,$log,$description,'','','',true,$eventId,$instance);
                        $return = $ts;
                }
                return $return;
        }
        
        /** 
         * writeUnlock()
         * Unfortunately the core unlocking code is not callable so this is 
         * taken and edited from v8.6.5/Locking/single_form_action.php
         * @param string $record
         * @param string $eventId
         * @param string $instrument
         * @param string $instance
         * @return boolean $success
         */
        protected function writeUnlock($record, $eventId, $instrument, $instance) {
                $return = false;
		$sql = "delete from redcap_locking_data where project_id = " . db_escape($this->project_id). " and record = '" . db_escape($record) . "' ".
                       "and event_id = " . db_escape($eventId) . " and form_name = '" . db_escape($instrument) . "' and instance = ".db_escape($instance)." limit 1";
                $description = "Unlock record (".$this->PREFIX.")";

		// Regardless of whether the e-signture is shown or not, check first if an e-signature exists in case we need to negate it
		$sqle2 = "select 1 from redcap_esignatures where project_id = ".db_escape($this->project_id)." and record = '" . db_escape($record) . "' ".
                         "and event_id = " . db_escape($eventId) . " and form_name = '" . db_escape($instrument) . "' and instance = ".db_escape($instance);
		if (db_num_rows(db_query($sqle2)) > 0)
		{
			// Negate the e-signature. NOTE: Anyone with locking privileges can negate an e-signature.
			$sqle = "delete from redcap_esignatures where project_id = ".db_escape($this->project_id)." and record = '" . db_escape($record) . "' ".
                         "and event_id = " . db_escape($eventId) . " and form_name = '" . db_escape($instrument) . "' and instance = ".db_escape($instance)." limit 1";
			$descriptione = "Negate e-signature (".$this->PREFIX.")";
		}
                
                if (db_query($sql)) {
                        $log = "Record: $record";
                        if ($this->Proj->longitudinal) { 
                                $log .= "\nEvent: ". REDCap::getEventNames(false, true, $eventId);
                        }
                        $log .= "\nForm: $instrument\nInstance: $instance";
                        Logging::logEvent($sql,"redcap_locking_data","LOCK_RECORD",$record,$log,$description,'','','',true,$eventId,$instance);

                        // Save and log e-signature action, if required
                        if (isset($sqle) && db_query($sqle))
                        {
                                Logging::logEvent($sqle,"redcap_esignatures","ESIGNATURE",$record,$log,$descriptione,'','','',true,$eventId,$instance);
                        }
                        $return = true;
                }
                return $return;
        }
        
        protected function formatReturnData() {
                $response = '';
                
                switch ($this->returnFormat) {
                    case 'csv':
                        $response = $this->formatReturnDataCsv();
                        break;
                    case 'json':
                        $response = $this->formatReturnDataJson();
                        break;
                    default:
                        $response = $this->formatReturnDataXml();
                }
                return $response;
        }
        
        protected function formatReturnDataCsv() {
                $delim = ',';
                $includeEvent = $this->Proj->longitudinal;
                $response = "record,".(($includeEvent)?'redcap_event_name,':'')."instrument,instance,lock_status,username,timestamp";
                
                foreach ($this->lock_status as $event) {
                        $event_name = $event['event_name'];
                        foreach ($event['event_forms'] as $form_name => $form_data) {
                                if (count($form_data['data']) > 0) {
                                        // form has been saved (and is locked or not)
                                        foreach ($form_data['data'] as $instance => $instanceData) {
                                                $locked = (isset($instanceData['username'])) ? '1' : '0';
                                                $un = (isset($instanceData['username'])) ? $instanceData['username'] : '';
                                                $ts = (isset($instanceData['timestamp'])) ? $instanceData['timestamp'] : '';
                                                $response .= 
                                                        PHP_EOL.$this->record.
                                                        (($includeEvent)?$delim.$event_name:'').
                                                        $delim.$form_name.
                                                        $delim.$instance.
                                                        $delim.$locked.
                                                        $delim.$un.
                                                        $delim.$ts;
                                        }
                                } else {
                                        // form has never been saved
                                        $response .= 
                                                PHP_EOL.$this->record.
                                                (($includeEvent)?$delim.$event_name:'').
                                                $delim.$form_name.
                                                $delim.
                                                $delim.
                                                $delim.
                                                $delim;
                                }
                        }
                }
                return $response;
        }
        
        protected function formatReturnDataJson() {
                if (isset($this->get['raw'])) { return json_encode($this->lock_status); }
                
                $includeEvent = $this->Proj->longitudinal;
                $rtn = array();
                
                foreach ($this->lock_status as $event) {
                        $event_name = $event['event_name'];
                        foreach ($event['event_forms'] as $form_name => $form_data) {
                                if (count($form_data['data']) > 0) {
                                        // form has been saved (and is locked or not)
                                        foreach ($form_data['data'] as $instance => $instanceData) {
                                                $locked = (isset($instanceData['username'])) ? '1' : '0';
                                                $un = (isset($instanceData['username'])) ? $instanceData['username'] : '';
                                                $ts = (isset($instanceData['timestamp'])) ? $instanceData['timestamp'] : '';
                                                $thisForm = array(); 
                                                $thisForm['record'] = $this->record;
                                                if ($includeEvent) { $thisForm['redcap_event_name'] = $event_name; }
                                                $thisForm['instrument'] = $form_name;
                                                $thisForm['instance'] = $instance;
                                                $thisForm['locked'] = $locked;
                                                $thisForm['username'] = $un;
                                                $thisForm['timestamp'] = $ts;
                                                $rtn[] = $thisForm;
                                        }
                                } else {
                                        // form has never been saved
                                        $thisForm = array(); 
                                        $thisForm['record'] = $this->record;
                                        if ($includeEvent) { $thisForm['redcap_event_name'] = $event_name; }
                                        $thisForm['instrument'] = $form_name;
                                        $thisForm['instance'] = '';
                                        $thisForm['locked'] = '';
                                        $thisForm['username'] = '';
                                        $thisForm['timestamp'] = '';
                                        $rtn[] = $thisForm;
                                }
                        }
                }
                return json_encode($rtn);
        }
        
        protected function formatReturnDataXml() {
                $includeEvent = $this->Proj->longitudinal;
                $response = '<?xml version="1.0" encoding="UTF-8" ?><lock_status>';
                
                foreach ($this->lock_status as $event) {
                        $event_name = $event['event_name'];
                        foreach ($event['event_forms'] as $form_name => $form_data) {
                                if (count($form_data['data']) > 0) {
                                        // form has been saved (and is locked or not)
                                        foreach ($form_data['data'] as $instance => $instanceData) {
                                                $locked = (isset($instanceData['username'])) ? '1' : '0';
                                                $un = (isset($instanceData['username'])) ? $instanceData['username'] : '';
                                                $ts = (isset($instanceData['timestamp'])) ? $instanceData['timestamp'] : '';
                                                $response .= 
                                                        "<record>{$this->record}</record>".
                                                        (($includeEvent)?"<redcap_event_name>$event_name</redcap_event_name>":"").
                                                        "<instrument>$form_name</instrument>".
                                                        "<instance>$instance</instance>".
                                                        "<locked>$locked</locked>".
                                                        "<username>$un</username>".
                                                        "<timestamp>$ts</timestamp>";
                                        }
                                } else {
                                        // form has never been saved
                                        $response .= 
                                                "<record>{$this->record}</record>".
                                                (($includeEvent)?"<redcap_event_name>$event_name</redcap_event_name>":"").
                                                "<instrument>$form_name</instrument>".
                                                "<instance></instance>".
                                                "<locked></locked>".
                                                "<username></username>".
                                                "<timestamp></timestamp>";
                                }
                        }
                }
                return $response.'</lock_status>';
        }
}