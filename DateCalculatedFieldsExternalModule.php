<?php
/**
 * Created by PhpStorm.
 * User: moorejr5
 * Date: 3/21/2018
 * Time: 12:54 PM
 */

namespace Vanderbilt\DateCalculatedFieldsExternalModule;

use DateTime;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Locking;

class DateCalculatedFieldsExternalModule extends AbstractExternalModule
{
	function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
	    global $Proj;
		/*$testString = "[field] - 5+2 - [field2]";
		$parsed = $this->parseLogicString($testString);

		$recordData = \Records::getData($project_id,'array',array($record),array(),array(),array($group_id));
		$metaData = $Proj->metadata;
		echo "<pre>";
		print_r($recordData);
		echo "</pre>";
		echo "<pre>";
		print_r($metaData);
		echo "</pre>";*/
		echo $this->createCalcuationJava($Proj,$instrument,$record,$event_id,$repeat_instance);
	}

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
		global $Proj;
		echo $this->createCalcuationJava($Proj,$instrument,$record,$event_id,$repeat_instance);
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
		global $Proj;

		$sourceFields = $this->getProjectSetting('source');
		$destinationFields = $this->getProjectSetting('destination');
		$daysAdd = $this->getProjectSetting('days-difference');
		$daysOrMonthsArray = $this->getProjectSetting('days-or-months');
		$fieldsOnForm = $Proj->forms[$instrument]['fields'];

		$longitudinal = $Proj->longitudinal;
        $events = array_flip($Proj->getUniqueEventNames());

		foreach ($sourceFields as $index => $fieldName) {
			$fieldsToSave = array();
			# Determines whether we want to override data that already exists in a record
			$overwriteText = ($this->getProjectSetting('source-overwrite')[$index] == "1" ? "overwrite" : "normal");
			# Make sure that the field that we're piping was submitted on the record save
            $sourceFormat = "Y-m-d";
            if (strpos($Proj->metadata[$fieldName]['element_validation_type'],'datetime') !== false) {
                if (strpos($Proj->metadata[$fieldName]['element_validation_type'],'datetime_seconds') !== false) {
                    $sourceFormat = "Y-m-d H:i:s";
                }
                else {
                    $sourceFormat = "Y-m-d H:i";
                }
            }
			if (in_array($fieldName,array_keys($_POST)) && $_POST[$fieldName] != "" && $this->validateDate($_POST[$fieldName],$sourceFormat)) {
				foreach ($destinationFields[$index] as $destIndex => $destinationField) {
                    $Locking = new Locking();
                    $Locking->findLocked($Proj, $record, array($destinationField), ($longitudinal ? $events : $Proj->firstEventId));

                    $destFieldForm = $Proj->metadata[$destinationField]['form_name'];
                    $destSurveyID = $Proj->forms[$destFieldForm]['survey_id'];
                    $destSurveysComplete = array();
                    if ($destSurveyID != "") {
                        $destSurveysComplete = $this->getSurveyCompletionStatus($record,$destSurveyID,$repeat_instance);
                    }

				    $daysOrMonths = $daysOrMonthsArray[$index][$destIndex];
					if ($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'','php') == "") continue;
					# Make sure that we want to pipe to other events
					if ($this->getProjectSetting('pipe-to-event')[$index][$destIndex] == "1") {
						# Make sure that the event we're on is one of the source events for piping
						if ($this->getProjectSetting('event-source')[$index][$destIndex] != "" && $event_id != $this->getProjectSetting('event-source')[$index][$destIndex]) continue;
						$eventsWithForm = $Proj->getEventsFormDesignated($Proj->metadata[$destinationField]['form_name']);

						# Get full list of events for this project (for this arm)
						$eventList = array_keys($Proj->events[getArm()]['events']);
						# Get full list of events that we want to pipe to for this source field
						$eventPipeList = $this->getProjectSetting('event-pipe')[$index][$destIndex];

						$currentEventIndex = array_search($event_id,$eventList);
						$currentEvent = false;

						foreach ($eventList as $eventIndex => $eventToPipe) {
                            if (isset($Locking->locked[$record][$eventToPipe][$repeat_instance][$destinationField]) || ($destSurveysComplete[$eventToPipe] && $Proj->surveys[$destSurveyID]['pdf_auto_archive'] == '2')) continue;
							if ($eventToPipe == $event_id) {
								$currentEvent = true;
							}
							$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));
							$componentDate = array('year'=>$postDate->format("Y"),'month'=>$postDate->format("m"),'day'=>$postDate->format('d'),'hour'=>$postDate->format('H'),'minute'=>$postDate->format('i'),'second'=>$postDate->format('s'));

							if (!in_array($eventToPipe,$eventsWithForm)) continue;
							if (!in_array($eventToPipe,$eventPipeList)) continue;
							if ($eventToPipe == $event_id && in_array($destinationField,$fieldsOnForm)) continue;

							$eventInfo = $Proj->eventInfo[$eventToPipe];
							$daysOffset = "";
							# If we don't specify the number of days to add per event in the project, use the project's event days offset setting
							if ($daysAdd[$index][$destIndex] != "") {
								//$daysOffset = $daysAdd[$index][$destIndex] * (($eventIndex - $currentEventIndex)+1);
								$daysOffset = $daysAdd[$index][$destIndex];
								/*$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));
								$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
							}
							else {
								$daysOffset = $eventInfo['day_offset'] - $Proj->eventInfo[$event_id]['day_offset'];
								/*$newDate = date_add($postDate,date_interval_create_from_date_string($eventInfo['day_offset'].' days'));
								$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
							}

							$newDate = $this->generateNewDate($postDate,$daysOrMonths,$daysOffset,$componentDate);

							if (is_a($newDate,'DateTime')) {
                                $fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->dateSaveFormat($Proj->metadata[$destinationField]['element_validation_type']));
                            }


							# Make sure whether we need to pipe into a "Start Date" date range field
							if ($this->getProjectSetting('event-start-date')[$index][$destIndex] != "") {

								$eventsWithStart = $Proj->getEventsFormDesignated($Proj->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['form_name']);
								if (in_array($eventToPipe,$eventsWithStart)) {
									$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));
									$startOffset = "";
									# Use the default start day offset value for the REDCap event unless specified in the module settings
									if ($this->getProjectSetting('start-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('start-days-add')[$index][$destIndex])) {
										$startOffset = (int)$this->getProjectSetting('start-days-add')[$index][$destIndex];
									}
									else {
										$startOffset = '-'.(int)$eventInfo['offset_min'];
									}
									# Add the base amount of days to offset for the event
									//$startDate = date_add($postDate, date_interval_create_from_date_string((int)$daysOffset . ' days'));
									# Add the amount of days necessary from the start offset
									//$startDate = date_add($startDate, date_interval_create_from_date_string($startOffset . ' days'));
                                    $combinedOffset = (int)$daysOffset + (int)$startOffset;
									$startDate = $this->generateNewDate($postDate,$daysOrMonths,$combinedOffset,$componentDate);
									if (is_a($startDate,'DateTime')) {
                                        $fieldsToSave[$record][$eventToPipe][$this->getProjectSetting('event-start-date')[$index][$destIndex]] = $startDate->format($this->dateSaveFormat($Proj->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type']));
                                    }
								}
							}
							# Make sure whether we need to pipe into a "End Date" date range field
							if ($this->getProjectSetting('event-end-date')[$index][$destIndex] != "") {
								$eventsWithEnd = $Proj->getEventsFormDesignated($Proj->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['form_name']);
								if (in_array($eventToPipe,$eventsWithEnd)) {
									$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));
									$endOffset = "";
									# Use the default end day offset value for the REDCap event unless specified in the module settings
									if ($this->getProjectSetting('end-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('end-days-add')[$index][$destIndex])) {
										$endOffset = (int)$this->getProjectSetting('end-days-add')[$index][$destIndex];
									}
									else {
										$endOffset = (int)$eventInfo['offset_max'];
									}
									# Add the base amount of days to offset for the event
									//$endDate = date_add($postDate, date_interval_create_from_date_string((int)$daysOffset . ' days'));
									# Add the amount of days necessary from the start offset
									//$endDate = date_add($endDate, date_interval_create_from_date_string((int)$endOffset . ' days'));
                                    $combinedOffset = (int)$daysOffset + (int)$endOffset;
									$endDate = $this->generateNewDate($postDate,$daysOrMonths,$combinedOffset,$componentDate);
									if (is_a($endDate,'DateTime')) {
                                        $fieldsToSave[$record][$eventToPipe][$this->getProjectSetting('event-end-date')[$index][$destIndex]] = $endDate->format($this->dateSaveFormat($Proj->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['element_validation_type']));
                                    }

								}
							}
							/*if (!empty($fieldsToSave)) {
								$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
							}*/
						}
					}
					# If we're not piping to other events, make sure we pipe to any fields on the same event that aren't on the current data entry form
					elseif (!in_array($destinationField,array_keys($fieldsOnForm))) {
                        if (isset($Locking->locked[$record][$event_id][$repeat_instance][$destinationField]) || ($destSurveysComplete[$event_id] && $Proj->surveys[$destSurveyID]['pdf_auto_archive'] == '2')) continue;
						$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));

                        $componentDate = array('year'=>$postDate->format("Y"),'month'=>$postDate->format("m"),'day'=>$postDate->format('d'),'hour'=>$postDate->format('H'),'minute'=>$postDate->format('i'),'second'=>$postDate->format('s'));
						//$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));
                        $newDate = $this->generateNewDate($postDate,$daysOrMonths,$daysAdd[$index][$destIndex],$componentDate);
                        if (is_a($newDate,'DateTime')) {
                            $fieldsToSave[$record][$event_id][$destinationField] = $newDate->format($this->dateSaveFormat($Proj->metadata[$destinationField]['element_validation_type']));
                        }
						/*if (!empty($fieldsToSave)) {
							$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
						}*/
					}
				}
			}
			if (!empty($fieldsToSave)) {
				$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
				/*if (!empty($output['errors'])) {
                    $errorString = stripslashes(json_encode($output['errors'], JSON_PRETTY_PRINT));
                    $errorString = str_replace('""', '"', $errorString);

                    $message = "The " . $this->getModuleName() . " module could not save updated date fields because of the following error(s):\n\n$errorString";
                    error_log($message);
                    throw new \Exception($message);
                }*/

                if(!empty($output['errors'])){
                    $errorString = stripslashes(json_encode($output['errors'], JSON_PRETTY_PRINT));
                    $errorString = str_replace('""', '"', $errorString);

                    $message = "The " . $this->getModuleName() . " module could not save updated date fields because of the following error(s):\n\n$errorString";
                    error_log($message);

                    $errorEmail = $this->getProjectSetting('error_email');
                    if (empty($errorEmail)) $errorEmail = "james.r.moore@vumc.org";
                    if(!empty($errorEmail)){
                        ## Add check for universal from email address
                        global $from_email;
                        if($from_email != '') {
                            $headers = "From: ".$from_email."\r\n";
                        }
                        else {
                            $headers = null;
                        }
                        mail($errorEmail, $this->getModuleName() . " Module Error", $message, $headers);
                    }
                }
			}
		}
		//$this->exitAfterHook();
	}

	function redcap_module_link_check_display($project_id, $link, $record, $instrument, $instance, $page) {
		if(\REDCap::getUserRights(USERID)[USERID]['design'] == '1'){
			return $link;
		}
		return null;
	}

	function generateNewDate($postDate,$daysOrMonths,$daysOffset,$componentDate) {
        $daysPerMonth = array(
            1=>31,
            2=>28,
            3=>31,
            4=>30,
            5=>31,
            6=>30,
            7=>31,
            8=>31,
            9=>30,
            10=>31,
            11=>30,
            12=>31
        );
	    $newDate = "";
        if ($daysOrMonths == "months") {
            $newMonth = $componentDate['month'] + $daysOffset;
            $newYear = $componentDate['year'];
            $newDay = $componentDate['day'];
            while ($newMonth > 12) {
                $newMonth -= 12;
                $newYear += 1;
            }
            if ($newMonth == 2) {
                if ($newYear % 4 == 0) {
                    if ($newDay > 29) {
                        $newDay = 29;
                    }
                }
                else {
                    if ($newDay > 28) {
                        $newDay = 28;
                    }
                }
            }
            elseif ($newDay > $daysPerMonth[$newMonth]) {
                $newDay = $daysPerMonth[$newMonth];
            }
            $newDate = new \DateTime($newYear."-".$newMonth."-".$newDay." ".$componentDate['hour'].":".$componentDate['minute'].":".$componentDate['second']);
        }
        else {
            //if ($currentEvent) {
            $newDate = date_add($postDate, date_interval_create_from_date_string($daysOffset . ' days'));
            //}
        }
        return $newDate;
    }
    /*
     * Generate the necessary Javascript code to get on-form data piping working.
     * @param $string String with mathematical logic.
     * @return Array with
     *  Index 0: Array of field names wrapped in brackets
     *  Index 1: Array of field names
     *  Index 2: Array of mathematical operators
     *  Index 3: Array of values to be processed by mathematical operators
     */
    function parseLogicString($string) {
        preg_match_all("/\[(.*?)\]/", $string, $matchRegEx);
        preg_match_all('/[+*\/-]/', $string, $matches);
        $stringsToReplace = $matchRegEx[0];
        $fieldNamesReplace = $matchRegEx[1];

        if (count($fieldNamesReplace[0]) > 1 || count($stringsToReplace[0]) > 1) return array();

        $daysAdd = array();
        if (isset($matches[0]) && !empty($matches[0])) {
            $lastPosition = 0;
            foreach ($matches[0] as $index => $operator) {
                $thisPosition = strpos($string, $matches[0][$index],$lastPosition+1);
                if ($index == 0) {
                    $daysAdd[$index] = trim(substr($string,0,$thisPosition));
                } else {
                    $daysAdd[$index] = trim(substr($string,$lastPosition + 1,($thisPosition - ($lastPosition + 1))));
                }
                $lastPosition = $thisPosition;
            }
            if ($lastPosition != "") {
                $daysAdd[] = trim(substr($string,$lastPosition + 1));
            }
        }
        else {
            $daysAdd[] = $string;
        }

        return array($stringsToReplace,$fieldNamesReplace,$matches[0],$daysAdd);
    }

    /*
     * Generate the necessary Javascript code to get on-form data piping working.
     * @param $interval Initial integer.
     * @param $operator Mathematical operator to use for operation.
     * @param $operatee Second integer to be interacted on initial integer by operator
     * @return Integer result of mathematical operation.
     */
    function processOperator($interval, $operator, $operatee) {
        if (!is_numeric($interval) || !is_numeric($operatee)) return $interval;
        switch ($operator) {
            case "+":
                return intval($interval) + intval($operatee);
                break;
            case "-":
                return intval($interval) + intval($operatee);
                break;
            case "*":
                return intval($interval) * intval($operatee);
                break;
            case "/":
                return intval($interval) / intval($operatee);
                break;
        }
        return $interval;
    }

    function getNumberFromLogicString($parseArray,$recordData,$metaData,$record,$event_id,$instance=1) {
        $total = 0;

        $bracketFieldList = $parseArray[0];
        $fieldNameList = $parseArray[1];
        $operators = $parseArray[2];
        $numbers = $parseArray[3];
        foreach ($numbers as $index => $number) {
            if (in_array($number,$bracketFieldList)) {
                $fieldName = $fieldNameList[array_keys($bracketFieldList,$number)[0]];
                if (isset($recordData[$record][$event_id][$fieldName]) && is_numeric($recordData[$record][$event_id][$fieldName])) {

                }
                elseif (isset($recordData[$record]['repeat_instances'][$event_id][$metaData[$fieldName]['form_name']][$instance][$fieldName]) && is_numeric($recordData[$record]['repeat_instances'][$event_id][$metaData[$fieldName]['form_name']][$instance][$fieldName])) {

                }
                else {
                    $total += 0;
                }
            }
            elseif (is_numeric($number)) {

            }
        }
    }

	/*
	 * Generate the necessary Javascript code to get on-form data piping working.
	 * @param $project REDCap Class object.
	 * @param $instrument Form name of the current form.
	 * @param $record_id ID of record being viewed
	 * @param $event_id Event ID.
	 * @param $instance Instance currently being viewed
	 * @return String containing javascript code
	 */
	function createCalcuationJava(\Project $project,$instrument,$record_id,$event_id,$instance) {
		$sourceFields = $this->getProjectSetting('source');
		$destinationFields = $this->getProjectSetting('destination');
		$daysAdd = $this->getProjectSetting('days-difference');
        $daysOrMonthArray = $this->getProjectSetting('days-or-months');

		#Get the list of fields that exist on the current form
		$fieldsOnForm = $project->forms[$instrument]['fields'];
		$eventInfo = $project->eventInfo[$event_id];
		$javaString = "<script>";
		foreach($sourceFields as $index => $fieldName) {
            $recordData = \Records::getData($project->project_id, 'array', array($record_id), array_merge($sourceFields, $destinationFields[$index]));

            # Make sure field exists on the current form
            if (in_array($fieldName, array_keys($fieldsOnForm))) {
                # Need to create an 'on blur' function for the field to be piped from
                $javaString .= "$('input[name=$fieldName]').blur(function() {
						var dateValue = $(this).val();
						if (dateValue != '') {";
                if (strpos($project->metadata[$fieldName]['element_validation_type'], "_dmy") !== false) {
                    if (strpos($project->metadata[$fieldName]['element_validation_type'], "datetime_") !== false) {
                        $javaString .= "dateValue = dateValue.replace(/(\d{2})-(\d{2})-(\d{4}) (.*)/, '$2-$1-$3 $4');";
                    } else {
                        $javaString .= "dateValue = dateValue.replace(/(\d{2})-(\d{2})-(\d{4})/, '$2-$1-$3');";
                    }
                }
                $javaString .= "dateValue = dateValue.replace(/-/g,'/');";

                $javaString .= "var date = new Date(dateValue);";
                if (strpos($project->metadata[$fieldName]['element_validation_type'], "datetime_") !== false) {
                    $javaString .= "var userTimezoneOffset = date.getTimezoneOffset() * 60000;";
                } else {
                    $javaString .= "var userTimezoneOffset = 0;";
                }
                # For each field to pipe to, need to generate their own date format based on the field's validation settings

                foreach ($destinationFields[$index] as $destIndex => $destinationField) {
                    if (in_array($destinationField, array_keys($fieldsOnForm))) {
                        if ($this->getProjectSetting('event-source')[$index][$destIndex] != "" && $event_id != $this->getProjectSetting('event-source')[$index][$destIndex]) continue;
                        $eventPipeList = $this->getProjectSetting('event-pipe')[$index][$destIndex];
                        $daysOrMonths = $daysOrMonthArray[$index][$destIndex];
                        if ($this->getProjectSetting('pipe-to-event')[$index][$destIndex] == "1" && !in_array($event_id, $eventPipeList)) continue;
                        $daysOffset = "";
                        # If we don't specify the number of days to add per event in the project, use the project's event days offset setting
                        if ($daysAdd[$index][$destIndex] != "") {
                            //$daysOffset = $daysAdd[$index][$destIndex] * (($eventIndex - $currentEventIndex)+1);
                            $daysOffset = $daysAdd[$index][$destIndex];
                            /*$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));
                            $fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
                        } else {
                            $daysOffset = "0";
                            /*$newDate = date_add($postDate,date_interval_create_from_date_string($eventInfo['day_offset'].' days'));
                            $fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
                        }
                        if ($daysOrMonths == "months") {
                            $javaString .= "var mySubDate = addMonthsToDate(date,$daysOffset);console.log(mySubDate.getUTCFullYear());";
                        }
                        else {
                            $javaString .= "var mySubDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysOffset . "*86400000));console.log(mySubDate);";
                        }
                        $javaString .= "$('input[name=$destinationField]').val(";
                        $javaString .= $this->getDateFormat($project->metadata[$destinationField]['element_validation_type'], 'mySubDate', 'javascript');
                        //mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
                        $javaString .= ");";
                        # Make sure whether we need to pipe into a "Start Date" date range field
                        if ($this->getProjectSetting('event-start-date')[$index][$destIndex] != "") {

                            $eventsWithStart = $project->getEventsFormDesignated($project->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['form_name']);
                            if (in_array($event_id, $eventsWithStart)) {
                                $startOffset = "";
                                # Use the default start day offset value for the REDCap event unless specified in the module settings
                                if ($this->getProjectSetting('start-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('start-days-add')[$index][$destIndex])) {
                                    $startOffset = (int)$this->getProjectSetting('start-days-add')[$index][$destIndex];
                                } else {
                                    $startOffset = '-' . (int)$eventInfo['offset_min'];
                                }
                                if ($daysOrMonths == "months") {
                                    $javaString .= "var myStartDate = addMonthsToDate(date,".((int)$daysOffset + (int)$startOffset).");";
                                }
                                else {
                                    $javaString .= "var myStartDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysOffset . "*86400000)+(" . $startOffset . "*86400000));";
                                }
                                $javaString .= "$('input[name=" . $this->getProjectSetting('event-start-date')[$index][$destIndex] . "]').val(";
                                $javaString .= $this->getDateFormat($project->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type'], 'myStartDate', 'javascript');
                                //mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
                                $javaString .= ");";
                            }
                        }
                        # Make sure whether we need to pipe into a "End Date" date range field
                        if ($this->getProjectSetting('event-end-date')[$index][$destIndex] != "") {
                            $eventsWithEnd = $project->getEventsFormDesignated($project->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['form_name']);
                            if (in_array($event_id, $eventsWithEnd)) {
                                $endOffset = "";
                                # Use the default end day offset value for the REDCap event unless specified in the module settings
                                if ($this->getProjectSetting('end-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('end-days-add')[$index][$destIndex])) {
                                    $endOffset = (int)$this->getProjectSetting('end-days-add')[$index][$destIndex];
                                } else {
                                    $endOffset = (int)$eventInfo['offset_max'];
                                }
                                if ($daysOrMonths == "months") {
                                    $javaString .= "var myEndDate = addMonthsToDate(date,".((int)$daysOffset + (int)$endOffset).");";
                                }
                                else {
                                    $javaString .= "var myEndDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysOffset . "*86400000)+(" . $endOffset . "*86400000));";
                                }
                                $javaString .= "$('input[name=" . $this->getProjectSetting('event-end-date')[$index][$destIndex] . "]').val(";
                                $javaString .= $this->getDateFormat($project->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['element_validation_type'], 'myEndDate', 'javascript');
                                //mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
                                $javaString .= ");";
                            }
                        }
                    }
                }
                $javaString .= "}
					});";
            }

            $sourceFieldForm = $project->metadata[$fieldName]['form_name'];

            $sourceDate = ($recordData[$record_id]['repeat_instances'][$event_id][$sourceFieldForm][intval($instance) - 1][$fieldName] != "" ? $recordData[$record_id]['repeat_instances'][$event_id][$sourceFieldForm][intval($instance) - 1][$fieldName] : $recordData[$record_id][$event_id][$fieldName]);
            if (!$this->validateDate($sourceDate)) continue;

            $javaString .= "var instancedate = new Date('$sourceDate');";
            if (strpos($project->metadata[$fieldName]['element_validation_type'], "datetime_") !== false) {
                $javaString .= "var instanceuserTimezoneOffset = date.getTimezoneOffset() * 60000;";
            } else {
                $javaString .= "var instanceuserTimezoneOffset = 0;";
            }
            //$javaString .= "console.log('The date is: '+instancedate);";
            foreach ($destinationFields[$index] as $destIndex => $destinationField) {
                if ($this->getProjectSetting('pipe-to-event')[$index][$destIndex] != "2" || intval($instance) == 1) continue;
                if ($this->getProjectSetting('pipe-to-event')[$index][$destIndex] == "2" && ($recordData[$record_id][$event_id][$fieldName] == "" && $recordData[$record_id]['repeat_instances'][$event_id][$sourceFieldForm][intval($instance) - 1][$fieldName] == "")) continue;
                if (in_array($destinationField, array_keys($fieldsOnForm))) {
                    $daysOffset = "";
                    $daysOrMonths = $daysOrMonthArray[$index][$destIndex];
                    # If we don't specify the number of days to add per event in the project, use the project's event days offset setting
                    if ($daysAdd[$index][$destIndex] != "") {
                        //$daysOffset = $daysAdd[$index][$destIndex] * (($eventIndex - $currentEventIndex)+1);
                        $daysOffset = $daysAdd[$index][$destIndex];
                        /*$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));
                        $fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
                    } else {
                        $daysOffset = "0";
                        /*$newDate = date_add($postDate,date_interval_create_from_date_string($eventInfo['day_offset'].' days'));
                        $fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
                    }
                    if ($daysOrMonths == "months") {
                        $javaString .= "var myInstanceSubDate = addMonthsToDate(instancedate,$daysOffset);";
                    }
                    else {
                        $javaString .= "var myInstanceSubDate = new Date(instancedate.getTime()-instanceuserTimezoneOffset+(" . $daysOffset . "*86400000));";
                    }
                    $javaString .= "$('input[name=$destinationField]').val(";
                    $javaString .= $this->getDateFormat($project->metadata[$destinationField]['element_validation_type'], 'myInstanceSubDate', 'javascript');
                    //mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
                    $javaString .= ");";
                    # Make sure whether we need to pipe into a "Start Date" date range field
                    if ($this->getProjectSetting('event-start-date')[$index][$destIndex] != "") {

                        $eventsWithStart = $project->getEventsFormDesignated($project->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['form_name']);
                        if (in_array($event_id, $eventsWithStart)) {
                            $startOffset = "";
                            # Use the default start day offset value for the REDCap event unless specified in the module settings
                            if ($this->getProjectSetting('start-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('start-days-add')[$index][$destIndex])) {
                                $startOffset = (int)$this->getProjectSetting('start-days-add')[$index][$destIndex];
                            } else {
                                $startOffset = '-' . (int)$eventInfo['offset_min'];
                            }

                            if ($daysOrMonths == "months") {
                                $javaString .= "var myInstanceStartDate = addMonthsToDate(instancedate,".((int)$daysOffset + (int)$startOffset).");";
                            }
                            else {
                                $javaString .= "var myInstanceStartDate = new Date(instancedate.getTime()-instanceuserTimezoneOffset+(" . $daysOffset . "*86400000)+(" . $startOffset . "*86400000));";
                            }
                            $javaString .= "$('input[name=" . $this->getProjectSetting('event-start-date')[$index][$destIndex] . "]').val(";
                            $javaString .= $this->getDateFormat($project->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type'], 'myInstanceStartDate', 'javascript');
                            //mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
                            $javaString .= ");";
                        }
                    }
                    # Make sure whether we need to pipe into a "End Date" date range field
                    if ($this->getProjectSetting('event-end-date')[$index][$destIndex] != "") {
                        $eventsWithEnd = $project->getEventsFormDesignated($project->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['form_name']);
                        if (in_array($event_id, $eventsWithEnd)) {
                            $endOffset = "";
                            # Use the default end day offset value for the REDCap event unless specified in the module settings
                            if ($this->getProjectSetting('end-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('end-days-add')[$index][$destIndex])) {
                                $endOffset = (int)$this->getProjectSetting('end-days-add')[$index][$destIndex];
                            } else {
                                $endOffset = (int)$eventInfo['offset_max'];
                            }
                            if ($daysOrMonths == "months") {
                                $javaString .= "var myInstanceEndDate = addMonthsToDate(instanceDate,".((int)$daysOffset + (int)$endOffset).");";
                            }
                            else {
                                $javaString .= "var myInstanceEndDate = new Date(instancedate.getTime()-instanceuserTimezoneOffset+(" . $daysOffset . "*86400000)+(" . $endOffset . "*86400000));";
                            }
                            $javaString .= "$('input[name=" . $this->getProjectSetting('event-end-date')[$index][$destIndex] . "]').val(";
                            $javaString .= $this->getDateFormat($project->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['element_validation_type'], 'myInstanceEndDate', 'javascript');
                            //mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
                            $javaString .= ");";
                        }
                    }
                }
            }
        }
		if ($daysOrMonths == "months") {
            $javaString .= "function addMonthsToDate(postDate,monthsOffset) {
                var currentYear = postDate.getFullYear();
                var currentMonth = postDate.getMonth();
                console.log('Current: '+currentMonth);
                var currentDate = postDate.getDate();
                var currentHour = postDate.getHours();
                var currentMinute = postDate.getMinutes();
                var currentSecond = postDate.getSeconds();
                var newMonth = currentMonth + monthsOffset;

                while (newMonth > 11) {
                    currentYear += 1;
                    newMonth -= 12;
                }
                if (newMonth == 1 && currentDate > 29) {
                    if (currentYear % 4 == 0) {
                        currentDate = 29;
                    }
                    else {
                        currentDate = 28;
                    }
                }
                else if ((newMonth == 0 || newMonth == 2 || newMonth == 4 || newMonth == 6 || newMonth == 7 || newMonth == 9 || newMonth == 11) && currentDate > 30) {
                    currentDate = 30;
                }
                return new Date(currentYear,newMonth,currentDate,currentHour,currentMinute,currentSecond);
		    }
		    function addDaysToMonth(postDate,daysOffset) {
		        var currentYear = postDate.getFullYear();
                var currentMonth = postDate.getMonth();
                console.log('Current: '+currentMonth);
                var currentDate = postDate.getDate();
                var currentHour = postDate.getHours();
                var currentMinute = postDate.getMinutes();
                var currentSecond = postDate.getSeconds();
                var newDate = currentDate + daysOffset;
		    }";
        }
        $javaString .= "function addZ(n) {
					  return n < 10 ? '0' + n : '' + n;
					}
				</script>";

		return $javaString;
	}

    /*
     * Returns true if the survey matching all parameters has a completion time.
     * @param $record Record ID
     * @param $survey_id Survey ID in redcap_surveys database
     * @param $instance Instance ID (function defaults non-numerics to 1
     * @return boolean
     */
	function getSurveyCompletionStatus($record,$survey_id,$instance) {
	    if (!is_numeric($instance) || $instance < 1) {
	        $instance = 1;
        }

	    $eventStatuses = array();
        $sql = "select p.event_id, r.completion_time
						from redcap_surveys_participants p, redcap_surveys_response r
						where p.survey_id = ".prep($survey_id)." and p.participant_id = r.participant_id
						and r.instance = ".prep($instance)." and r.record = ".prep($record)." and r.first_submit_time is not null";
        //echo "$sql<br/>";
        $q = db_query($sql);
        while ($row = db_fetch_assoc($q)) {
            // If response is completed
            if ($row['completion_time'] != '') {
                $eventStatuses[$row['event_id']] = true;
            }
        }
        return $eventStatuses;
    }

	/*
	 * Determines the format that a date field needs to be saved within the database.
	 * @param $validation_type The type of date format for the field being examined.
	 * @return Date format string. Default of 'Y-m-d'
	 */
	function dateSaveFormat($validation_type) {
		$format = "Y-m-d";
		if (strpos($validation_type,"datetime_") !== false) {
			if (strpos($validation_type,"_seconds_") !== false) {
				$format = "Y-m-d H:i:s";
			}
			else {
				$format = "Y-m-d H:i";
			}
		}
		return $format;
	}

	/*
	 * Determine the correct date formatting based on a field's element validation.
	 * @param $elementValidationType The element validation for the data field being examined.
	 * @param $type Either 'php' or 'javascript', based on where the data format string is being injected
	 * @return Date format string
	 */
	function getDateFormat($elementValidationType, $fieldName, $type) {
		$returnString = "";
		switch ($elementValidationType) {
			case "date_mdy":
				if ($type == "php") {
					$returnString = "m-d-Y";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ($fieldName.getUTCMonth()+1)+'-'+addZ($fieldName.getUTCDate())+'-'+$fieldName.getUTCFullYear()";
				}
				break;
			case "date_dmy":
				if ($type == "php") {
					$returnString = "d-m-Y";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ($fieldName.getUTCDate())+'-'+addZ($fieldName.getUTCMonth()+1)+'-'+$fieldName.getUTCFullYear()";
				}
				break;
			case "date_ymd":
				if ($type == "php") {
					$returnString = "Y-m-d";
				}
				elseif ($type == "javascript") {
					$returnString = "$fieldName.getUTCFullYear()+'-'+addZ($fieldName.getUTCMonth()+1)+'-'+addZ($fieldName.getUTCDate())";
				}
				break;
			case "datetime_mdy":
				if ($type == "php") {
					$returnString = "m-d-Y H:i";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ($fieldName.getUTCMonth()+1)+'-'+addZ($fieldName.getUTCDate())+'-'+$fieldName.getUTCFullYear()+' '+addZ($fieldName.getUTCHours())+':'+addZ($fieldName.getUTCMinutes())";
				}
				break;
			case "datetime_dmy":
				if ($type == "php") {
					$returnString = "d-m-Y H:i";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ($fieldName.getUTCDate())+'-'+addZ($fieldName.getUTCMonth()+1)+'-'+$fieldName.getUTCFullYear()+' '+addZ($fieldName.getUTCHours())+':'+addZ($fieldName.getUTCMinutes())";
				}
				break;
			case "datetime_ymd":
				if ($type == "php") {
					$returnString = "Y-m-d H:i";
				}
				elseif ($type == "javascript") {
					$returnString = "$fieldName.getUTCFullYear()+'-'+addZ($fieldName.getUTCMonth()+1)+'-'+addZ($fieldName.getUTCDate())+' '+addZ($fieldName.getUTCHours())+':'+addZ($fieldName.getUTCMinutes())";
				}
				break;
			case "datetime_seconds_mdy":
				if ($type == "php") {
					$returnString = "m-d-Y H:i:s";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ($fieldName.getUTCMonth()+1)+'-'+addZ($fieldName.getUTCDate())+'-'+$fieldName.getUTCFullYear()+' '+addZ($fieldName.getUTCHours())+':'+addZ($fieldName.getUTCMinutes())+':'+addZ($fieldName.getUTCSeconds())";
				}
				break;
			case "datetime_seconds_dmy":
				if ($type == "php") {
					$returnString = "d-m-Y H:i:s";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ($fieldName.getUTCDate())+'-'+addZ($fieldName.getUTCMonth()+1)+'-'+$fieldName.getUTCFullYear()+' '+addZ($fieldName.getUTCHours())+':'+addZ($fieldName.getUTCMinutes())+':'+addZ($fieldName.getUTCSeconds())";
				}
				break;
			case "datetime_seconds_ymd":
				if ($type == "php") {
					$returnString = "Y-m-d H:i:s";
				}
				elseif ($type == "javascript") {
					$returnString = "$fieldName.getUTCFullYear()+'-'+addZ($fieldName.getUTCMonth()+1)+'-'+addZ($fieldName.getUTCDate())+' '+addZ($fieldName.getUTCHours())+':'+addZ($fieldName.getUTCMinutes())+':'+addZ($fieldName.getUTCSeconds())";
				}
				break;
			default:
				$returnString = '';
		}
		return $returnString;
	}

    function validateDate($date,$format='Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return $d && $d->format($format) === $date;
    }
}