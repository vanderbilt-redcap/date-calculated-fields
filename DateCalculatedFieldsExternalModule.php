<?php
/**
 * Created by PhpStorm.
 * User: moorejr5
 * Date: 3/21/2018
 * Time: 12:54 PM
 */

namespace Vanderbilt\DateCalculatedFieldsExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;


class DateCalculatedFieldsExternalModule extends AbstractExternalModule
{
	function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
		global $Proj;
		/*echo "<pre>";
		print_r($this->getProjectSetting('event-pipe'));
		echo "</pre>";*/
		/*echo "<pre>";
		print_r($Proj->events);
		echo "</pre>";*/
		echo $this->createCalcuationJava($Proj,$instrument,$event_id);
	}

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
		global $Proj;
		echo $this->createCalcuationJava($Proj,$instrument,$event_id);
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
		global $Proj;
		$sourceFields = $this->getProjectSetting('source');
		$destinationFields = $this->getProjectSetting('destination');
		$daysAdd = $this->getProjectSetting('days-difference');
		$fieldsOnForm = $Proj->forms[$instrument]['fields'];

		foreach ($sourceFields as $index => $fieldName) {
			$fieldsToSave = array();
			# Determines whether we want to override data that already exists in a record
			$overwriteText = ($this->getProjectSetting('source-overwrite')[$index] == "1" ? "overwrite" : "normal");
			# Make sure that the field that we're piping was submitted on the record save
			if (in_array($fieldName,array_keys($_POST)) && $_POST[$fieldName] != "") {
				foreach ($destinationFields[$index] as $destIndex => $destinationField) {
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
							if ($eventToPipe == $event_id) {
								$currentEvent = true;
							}
							$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));

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

							//if ($currentEvent) {
								$newDate = date_add($postDate, date_interval_create_from_date_string($daysOffset . ' days'));
								$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->dateSaveFormat($Proj->metadata[$destinationField]['element_validation_type']));
							//}

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
									$startDate = date_add($postDate, date_interval_create_from_date_string((int)$daysOffset . ' days'));
									# Add the amount of days necessary from the start offset
									$startDate = date_add($startDate, date_interval_create_from_date_string($startOffset . ' days'));
									$fieldsToSave[$record][$eventToPipe][$this->getProjectSetting('event-start-date')[$index][$destIndex]] = $startDate->format($this->dateSaveFormat($Proj->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type']));
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
										$endOffset = (int)$eventInfo['offset_min'];
									}
									# Add the base amount of days to offset for the event
									$endDate = date_add($postDate, date_interval_create_from_date_string((int)$daysOffset . ' days'));
									# Add the amount of days necessary from the start offset
									$endDate = date_add($endDate, date_interval_create_from_date_string((int)$endOffset . ' days'));
									$fieldsToSave[$record][$eventToPipe][$this->getProjectSetting('event-end-date')[$index][$destIndex]] = $endDate->format($this->dateSaveFormat($Proj->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['element_validation_type']));
								}
							}
							/*if (!empty($fieldsToSave)) {
								$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
							}*/
						}
					}
					# If we're not piping to other events, make sure we pipe to any fields on the same event that aren't on the current data entry form
					elseif (!in_array($destinationField,array_keys($fieldsOnForm))) {
						$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));
						$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));

						$fieldsToSave[$record][$event_id][$destinationField] = $newDate->format($this->dateSaveFormat($Proj->metadata[$destinationField]['element_validation_type']));
						/*if (!empty($fieldsToSave)) {
							$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
						}*/
					}
				}
			}
			if (!empty($fieldsToSave)) {
				$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
			}
		}
		//exit;
	}

	function redcap_module_link_check_display($project_id, $link, $record, $instrument, $instance, $page) {
		if(\REDCap::getUserRights(USERID)[USERID]['design'] == '1'){
			return $link;
		}
		return null;
	}

	/*
	 * Generate the necessary Javascript code to get on-form data piping working.
	 * @param $project REDCap Class object.
	 * @param $instrument Form name of the current form.
	 * @param $event_id Event ID.
	 * @return String containing javascript code
	 */
	function createCalcuationJava(\Project $project,$instrument,$event_id) {
		$sourceFields = $this->getProjectSetting('source');
		$destinationFields = $this->getProjectSetting('destination');
		$daysAdd = $this->getProjectSetting('days-difference');
		#Get the list of fields that exist on the current form
		$fieldsOnForm = $project->forms[$instrument]['fields'];
		$eventInfo = $project->eventInfo[$event_id];
		$javaString = "";

		foreach($sourceFields as $index => $fieldName) {
			# Make sure field exists on the current form
			if (in_array($fieldName,array_keys($fieldsOnForm))) {
				# Need to create an 'on blur' function for the field to be piped from
				$javaString .= "<script>
					$('input[name=$fieldName]').blur(function() {
						var dateValue = $(this).val();
						if (dateValue != '') {";
							if (strpos($project->metadata[$fieldName]['element_validation_type'],"_dmy") !== false) {
								if (strpos($project->metadata[$fieldName]['element_validation_type'],"datetime_") !== false) {
									$javaString .= "dateValue = dateValue.replace(/(\d{2})-(\d{2})-(\d{4}) (.*)/, '$2-$1-$3 $4');";
								}
								else {
									$javaString .= "dateValue = dateValue.replace(/(\d{2})-(\d{2})-(\d{4})/, '$2-$1-$3');";
								}
							}
							$javaString .= "var date = new Date(dateValue);";
							if (strpos($project->metadata[$fieldName]['element_validation_type'],"datetime_") !== false) {
								$javaString .= "var userTimezoneOffset = date.getTimezoneOffset() * 60000;";
							}
							else {
								$javaString .= "var userTimezoneOffset = 0;";
							}
				# For each field to pipe to, need to generate their own date format based on the field's validation settings
				foreach ($destinationFields[$index] as $destIndex => $destinationField) {
					if (in_array($destinationField,array_keys($fieldsOnForm))) {
						if ($this->getProjectSetting('event-source')[$index][$destIndex] != "" && $event_id != $this->getProjectSetting('event-source')[$index][$destIndex]) continue;
						$eventPipeList = $this->getProjectSetting('event-pipe')[$index][$destIndex];
						if ($this->getProjectSetting('pipe-to-event')[$index][$destIndex] == "1" && !in_array($event_id,$eventPipeList)) continue;
						$daysOffset = "";
						# If we don't specify the number of days to add per event in the project, use the project's event days offset setting
						if ($daysAdd[$index][$destIndex] != "") {
							//$daysOffset = $daysAdd[$index][$destIndex] * (($eventIndex - $currentEventIndex)+1);
							$daysOffset = $daysAdd[$index][$destIndex];
							/*$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));
							$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
						}
						else {
							$daysOffset = "0";
							/*$newDate = date_add($postDate,date_interval_create_from_date_string($eventInfo['day_offset'].' days'));
							$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
						}
						$javaString .= "var mySubDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysOffset . "*86400000));
						console.log(date.getTime());
									$('input[name=$destinationField]').val(";
						$javaString .= $this->getDateFormat($project->metadata[$destinationField]['element_validation_type'],'mySubDate','javascript');
						//mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
						$javaString .= ");";
						# Make sure whether we need to pipe into a "Start Date" date range field
						if ($this->getProjectSetting('event-start-date')[$index][$destIndex] != "") {

							$eventsWithStart = $project->getEventsFormDesignated($project->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['form_name']);
							if (in_array($event_id,$eventsWithStart)) {
								$startOffset = "";
								# Use the default start day offset value for the REDCap event unless specified in the module settings
								if ($this->getProjectSetting('start-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('start-days-add')[$index][$destIndex])) {
									$startOffset = (int)$this->getProjectSetting('start-days-add')[$index][$destIndex];
								}
								else {
									$startOffset = '-'.(int)$eventInfo['offset_min'];
								}
								$javaString .= "var myStartDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysOffset . "*86400000)+(" . $startOffset . "*86400000));
									$('input[name=".$this->getProjectSetting('event-start-date')[$index][$destIndex]."]').val(";
								$javaString .= $this->getDateFormat($project->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type'],'myStartDate','javascript');
								//mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
								$javaString .= ");";
							}
						}
						# Make sure whether we need to pipe into a "End Date" date range field
						if ($this->getProjectSetting('event-end-date')[$index][$destIndex] != "") {
							$eventsWithEnd = $project->getEventsFormDesignated($project->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['form_name']);
							if (in_array($event_id,$eventsWithEnd)) {
								$endOffset = "";
								# Use the default end day offset value for the REDCap event unless specified in the module settings
								if ($this->getProjectSetting('end-days-add')[$index][$destIndex] != "" && is_numeric($this->getProjectSetting('end-days-add')[$index][$destIndex])) {
									$endOffset = (int)$this->getProjectSetting('end-days-add')[$index][$destIndex];
								}
								else {
									$endOffset = (int)$eventInfo['offset_min'];
								}
								$javaString .= "var myEndDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysOffset . "*86400000)+(" . $endOffset . "*86400000));
									$('input[name=".$this->getProjectSetting('event-end-date')[$index][$destIndex]."]').val(";
								$javaString .= $this->getDateFormat($project->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['element_validation_type'],'myEndDate','javascript');
								//mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
								$javaString .= ");";
							}
						}
					}
				}
				$javaString .= "}
					});
					
					function addZ(n) {
					  return n < 10 ? '0' + n : '' + n;
					}
				</script>";
			}
		}

		return $javaString;
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
}