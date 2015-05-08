<?php
require_once 'CRM/Core/Form.php';

class CRM_Import_Form_ParticipantImporter extends CRM_Core_Form {
	
	private $fields, $participants = array(), $suggestedParticipants = array(), $unknownParticipants = array();

	function buildQuickForm() {
		$this->add('select', 'event_id', 'Event', $this->fetchEventList(), TRUE);
		$this->add('select', 'status_id', 'Status', $this->fetchStatusList(), TRUE);
		$this->add('select', 'create_contacts', 'Create new contacts', array("1" => "Yes", "0" => "No"), TRUE);
		$this->add('file', 'participants', 'Event Registration file', TRUE);
		$this->addButtons(array(array('type' => 'submit', 'name' => ts('Submit'), 'isDefault' => TRUE)));
		$this->assign('elementNames', $this->getRenderableElementNames());
		parent::buildQuickForm();
		$this->fetchCustom();
	}
	
	function fetchCustom() {
		try {
			$this->fields = new stdClass;
			$this->fields->extraGroup = civicrm_api3('CustomGroup', 'Getsingle', array("name" => "Extra"));
			$this->fields->identificatieNummer = civicrm_api3('CustomField', 'Getsingle', array("name" => "Identificatienummer", "custom_group_id" => $this->fields->extraGroup['id']));
		} catch(Exception $e) {
			throw new Exception("Customfield identificatie has not been found.");
		}
	}
	
	
	function fetchEventList() {
		try {
			$returnArray = array();
			$events = civicrm_api3('Event', 'get', array("limit" => -1));
			foreach($events['values'] as $event) $returnArray[$event['id']] = $event['title'];
			return $returnArray;
		} catch (Exception $e) {
			$returnArray = array();
		}
	}
	
	function fetchStatusList() {
		try {
			$returnArray = array();
			$statusses = civicrm_api3('ParticipantStatusType', 'get', array("limit" => -1));
			foreach($statusses['values'] as $status) $returnArray[$status['id']] = $status['label'];
			return $returnArray;
		} catch (Exception $e) {
			$returnArray = array();
		}
	}
	
	function postProcess() {
		$values = $this->exportValues();
		$xmlFile = substr(__DIR__, 0, strpos(__DIR__, "import")).'import/uploads/'.$values['event_id'].'-'.date("Ymdhis").'.xml';
		if(move_uploaded_file($_FILES['participants']['tmp_name'], $xmlFile)) {
			if($this->parseXML($xmlFile)) {
				if(count($this->unknownParticipants) && $_POST['create_contacts']) $this->createContacts();
			} else {
				throw new Exception("XML file doesn't contain participant data.");
			}
		} else {
			throw new Exception("Unable to locate XML file.");
		}
		parent::postProcess();
		unlink($xmlFile);
	}
	
	function parseXML($xmlFile) {
		libxml_use_internal_errors(true);
		if(file_exists($xmlFile)) {
			$xmlReader = simplexml_load_file($xmlFile);
			if($xmlFile) {
				foreach($xmlReader as $participant) {
					if($participant->td[0] == "ID") continue;
					$tmpParticipant = array(
						'id' 			=> (string) $participant->td[0],
						'last_name' 	=> (string) $participant->td[1],
						'first_name_1' 	=> (string) $participant->td[2],
						'first_name_2' 	=> (string) $participant->td[3],
						'first_name_3' 	=> (string) $participant->td[4],
						'address' 		=> (string) $participant->td[5],
						'postcode' 		=> (string) $participant->td[6],
						'gemeente' 		=> (string) $participant->td[7],
						'land' 			=> (string) $participant->td[8],
						'birth_date' 	=> (string) $participant->td[9],
						'birth_place' 	=> (string) $participant->td[10],
						'registration' 	=> (string) $participant->td[11]
					);
					$this->participants[] = $tmpParticipant;
				}
				$this->matchParticipants();
			} else {
				throw new Exception("XML file doesn't contain participant data.");
			}
		} else {
			throw new Exception("Unable to locate XML file.");
		}
		return true;
	}
	
	function matchParticipants() {
		if(count($this->participants)) {
			foreach($this->participants as $participant) {
				// Attempt 1 - match with identifier
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("custom_7" => $participant['id']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 2 - match with firstname, lastname and date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("first_name" => $participant['first_name_1'], "last_name" => $participant['last_name'], 'birth_date' => $participant['birth_date']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					$this->setIdentification($civiContact['id'], $participant['id']);
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 3 - match with lastname and date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("last_name" => $participant['last_name'], 'birth_date' => $participant['birth_date']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					$this->setIdentification($civiContact['id'], $participant['id']);
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 4 - match with firstname and date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("first_name" => $participant['first_name_1'], 'birth_date' => $participant['birth_date']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					$this->setIdentification($civiContact['id'], $participant['id']);
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 5 - match with date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array('birth_date' => $participant['birth_date']));
					$this->suggestedParticipants[] = array_merge($participant, $civiContact);
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Still unknown, add to array
				$this->unknownParticipants[] = $participant;
			}
		} else {
			throw new Exception("No participants have been found.");
		}
	}
	
	function registerParticipation($participantIdentifier, $date) {
		try {
			civicrm_api3('Participant', 'Create', array(
				'event_id' 			=> $_POST['event_id'],
				'contact_id' 		=> $participantIdentifier,
				'status_id'			=> $_POST['status_id'],
				'register_date' 	=> $date
			));
		} catch (Exception $e) {
			echo $e;
		}
	}
	
	function createContacts() {
		if(count($this->unknownParticipants)) {
			foreach($this->unknownParticipants as $participant) {
				try {
					if(!empty($participant['first_name_1']) AND !empty($participant['last_name'])) {
						$contact = civicrm_api3('Contact', 'Create', array(
							'contact_type' 	=> 'individual',
							'first_name' 	=> trim($participant['first_name_1'].' '.$participant['first_name_2']),
							'last_name' 	=> $participant['last_name'],
							'birth_date'	=> $participant['birth_date']
						));
						$this->registerParticipation($contact['id'], $participant['registration']);
						civicrm_api3('Address', 'Create', array(
							'contact_id'		=> $contact['id'],
							'is_primary'		=> 1,
							'location_type_id' 	=> 1,
							'street_address' 	=> $participant['address'],
							'postal_code' 		=> $participant['postcode'],
							'country_id' 		=> $this->fetchCountryIdentifier($participant['land'])
						));
						$this->setIdentification($contact['id'], $participant['id']);
					}
				} catch (Exception $e) {
					throw new Exception("Failed to create participant! ".$e);
				}
			}
		}
	}
	
	function fetchCountryIdentifier($isoCode) {
		try {
			$country = civicrm_api3('Country', 'Getsingle', array('iso_code' => $isoCode));
			return $country['id'];
		} catch(Exception $e) {
			return null;
		}
	}
	
	function setIdentification($contact_id, $identificationNumber) {
		try {
			civicrm_api3('CustomValue', 'Create', array('entity_id' => $contact_id, 'custom_'.$this->fields->identificatieNummer['id'] => $identificationNumber));
		} catch(Exception $e) {}
	}
	
	function getRenderableElementNames() {
		$elementNames = array();
		foreach ($this->_elements as $element) {
			$label = $element->getLabel();
			if (!empty($label)) $elementNames[] = $element->getName();
		}
		return $elementNames;
	}
}