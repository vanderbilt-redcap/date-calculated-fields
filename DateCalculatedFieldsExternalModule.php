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
			$overwriteText = ($this->getProjectSetting('source-overwrite')[$index] == "1" ? "overwrite" : "normal");
			if (in_array($fieldName,array_keys($_POST)) && $_POST[$fieldName] != "") {
				foreach ($destinationFields[$index] as $destIndex => $destinationField) {
					if ($this->getProjectSetting('pipe-to-event')[$index][$destIndex] == "1") {
						if ($this->getProjectSetting('event-source')[$index][$destIndex] != "" && $event_id != $this->getProjectSetting('event-source')[$index][$destIndex]) continue;
						$eventsWithForm = $Proj->getEventsFormDesignated($Proj->metadata[$destinationField]['form_name']);

						$eventList = array_keys($Proj->events[getArm()]['events']);
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
							if ($daysAdd[$index][$destIndex] != "") {
								$daysOffset = $daysAdd[$index][$destIndex] * (($eventIndex - $currentEventIndex)+1);
								/*$newDate = date_add($postDate,date_interval_create_from_date_string($daysAdd[$index][$destIndex].' days'));
								$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
							}
							else {
								$daysOffset = $eventInfo['day_offset'] - $Proj->eventInfo[$event_id]['day_offset'];
								/*$newDate = date_add($postDate,date_interval_create_from_date_string($eventInfo['day_offset'].' days'));
								$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->getDateFormat($Proj->metadata[$destinationField]['element_validation_type'],'php'));*/
							}

							if ($currentEvent) {
								$newDate = date_add($postDate, date_interval_create_from_date_string($daysOffset . ' days'));
								$fieldsToSave[$record][$eventToPipe][$destinationField] = $newDate->format($this->dateSaveFormat($Proj->metadata[$destinationField]['element_validation_type']));
							}

							if ($this->getProjectSetting('event-start-date')[$index][$destIndex] != "") {
								$eventsWithStart = $Proj->getEventsFormDesignated($Proj->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['form_name']);
								if (in_array($eventToPipe,$eventsWithStart)) {
									$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));
									$startDate = date_add($postDate, date_interval_create_from_date_string(((int)$daysOffset - (int)$eventInfo['offset_min']) . ' days'));
									$fieldsToSave[$record][$eventToPipe][$this->getProjectSetting('event-start-date')[$index][$destIndex]] = $startDate->format($this->dateSaveFormat($Proj->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type']));
								}
							}
							if ($this->getProjectSetting('event-end-date')[$index][$destIndex] != "") {
								$eventsWithEnd = $Proj->getEventsFormDesignated($Proj->metadata[$this->getProjectSetting('event-end-date')[$index][$destIndex]]['form_name']);
								if (in_array($eventToPipe,$eventsWithEnd)) {
									$postDate = new \DateTime(db_real_escape_string($_POST[$fieldName]));
									$endDate = date_add($postDate, date_interval_create_from_date_string(((int)$daysOffset + (int)$eventInfo['offset_max']) . ' days'));
									$fieldsToSave[$record][$eventToPipe][$this->getProjectSetting('event-end-date')[$index][$destIndex]] = $endDate->format($this->dateSaveFormat($Proj->metadata[$this->getProjectSetting('event-start-date')[$index][$destIndex]]['element_validation_type']));
								}
							}
							/*if (!empty($fieldsToSave)) {
								$output = \Records::saveData($project_id,'array',$fieldsToSave,$overwriteText);
							}*/
						}
					}
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
			echo "<pre>";
			print_r($output);
			echo "</pre>";
			echo "<pre>";
			print_r($fieldsToSave);
			echo "</pre>";
			//exit;
		}
		exit;
	}

	function redcap_module_link_check_display($project_id, $link, $record, $instrument, $instance, $page) {
		if(\REDCap::getUserRights(USERID)[USERID]['design'] == '1'){
			return $link;
		}
		return null;
	}

	function createCalcuationJava(\Project $project,$instrument,$event_id) {
		$sourceFields = $this->getProjectSetting('source');
		$destinationFields = $this->getProjectSetting('destination');
		$daysAdd = $this->getProjectSetting('days-difference');
		$fieldsOnForm = $project->forms[$instrument]['fields'];
		$javaString = "";

		foreach($sourceFields as $index => $fieldName) {
			if (in_array($fieldName,array_keys($fieldsOnForm))) {
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
							$javaString .= "var date = new Date(dateValue);
							var userTimezoneOffset = date.getTimezoneOffset() * 60000;";
				foreach ($destinationFields[$index] as $destIndex => $destinationField) {
					if (in_array($destinationField,array_keys($fieldsOnForm))) {
						$javaString .= "var mySubDate = new Date(date.getTime()-userTimezoneOffset+(" . $daysAdd[$index][$destIndex] . "*86400000));
									$('input[name=$destinationField]').val(";
						$javaString .= $this->getDateFormat($project->metadata[$destinationField]['element_validation_type'],'javascript');
						//mySubDate$destIndex.getUTCFullYear()+'-'+addZ(mySubDate$destIndex.getUTCMonth()+1)+'-'+addZ(mySubDate$destIndex.getUTCDate())
						$javaString .= ");";
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

	function getDateFormat($elementValidationType, $type) {
		$returnString = "";
		switch ($elementValidationType) {
			case "date_mdy":
				if ($type == "php") {
					$returnString = "m-d-Y";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ(mySubDate.getUTCMonth()+1)+'-'+addZ(mySubDate.getUTCDate())+'-'+mySubDate.getUTCFullYear()";
				}
				break;
			case "date_dmy":
				if ($type == "php") {
					$returnString = "d-m-Y";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ(mySubDate.getUTCDate())+'-'+addZ(mySubDate.getUTCMonth()+1)+'-'+mySubDate.getUTCFullYear()";
				}
				break;
			case "date_ymd":
				if ($type == "php") {
					$returnString = "Y-m-d";
				}
				elseif ($type == "javascript") {
					$returnString = "mySubDate.getUTCFullYear()+'-'+addZ(mySubDate.getUTCMonth()+1)+'-'+addZ(mySubDate.getUTCDate())";
				}
				break;
			case "datetime_mdy":
				if ($type == "php") {
					$returnString = "m-d-Y H:i";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ(mySubDate.getUTCMonth()+1)+'-'+addZ(mySubDate.getUTCDate())+'-'+mySubDate.getUTCFullYear()+' '+addZ(mySubDate.getUTCHours())+':'+addZ(mySubDate.getUTCMinutes())";
				}
				break;
			case "datetime_dmy":
				if ($type == "php") {
					$returnString = "d-m-Y H:i";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ(mySubDate.getUTCDate())+'-'+addZ(mySubDate.getUTCMonth()+1)+'-'+mySubDate.getUTCFullYear()+' '+addZ(mySubDate.getUTCHours())+':'+addZ(mySubDate.getUTCMinutes())";
				}
				break;
			case "datetime_ymd":
				if ($type == "php") {
					$returnString = "Y-m-d H:i";
				}
				elseif ($type == "javascript") {
					$returnString = "mySubDate.getUTCFullYear()+'-'+addZ(mySubDate.getUTCMonth()+1)+'-'+addZ(mySubDate.getUTCDate())+' '+addZ(mySubDate.getUTCHours())+':'+addZ(mySubDate.getUTCMinutes())";
				}
				break;
			case "datetime_seconds_mdy":
				if ($type == "php") {
					$returnString = "m-d-Y H:i:s";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ(mySubDate.getUTCMonth()+1)+'-'+addZ(mySubDate.getUTCDate())+'-'+mySubDate.getUTCFullYear()+' '+addZ(mySubDate.getUTCHours())+':'+addZ(mySubDate.getUTCMinutes())+':'+addZ(mySubDate.getUTCSeconds())";
				}
				break;
			case "datetime_seconds_dmy":
				if ($type == "php") {
					$returnString = "d-m-Y H:i:s";
				}
				elseif ($type == "javascript") {
					$returnString = "addZ(mySubDate.getUTCDate())+'-'+addZ(mySubDate.getUTCMonth()+1)+'-'+mySubDate.getUTCFullYear()+' '+addZ(mySubDate.getUTCHours())+':'+addZ(mySubDate.getUTCMinutes())+':'+addZ(mySubDate.getUTCSeconds())";
				}
				break;
			case "datetime_seconds_ymd":
				if ($type == "php") {
					$returnString = "Y-m-d H:i:s";
				}
				elseif ($type == "javascript") {
					$returnString = "mySubDate.getUTCFullYear()+'-'+addZ(mySubDate.getUTCMonth()+1)+'-'+addZ(mySubDate.getUTCDate())+' '+addZ(mySubDate.getUTCHours())+':'+addZ(mySubDate.getUTCMinutes())+':'+addZ(mySubDate.getUTCSeconds())";
				}
				break;
			default:
				$returnString = '';
		}
		return $returnString;
	}

	function processModuleActionTag($actionTag) {
	}
}