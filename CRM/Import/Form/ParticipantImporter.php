<?php
require_once 'CRM/Core/Form.php';
class CRM_Import_Form_ParticipantImporter extends CRM_Core_Form {
	
	private $fields, $participants = array(), $unknownParticipants = array(), $xmlFile, $location, $filename, $new = 0, $existing = 0;

	function buildQuickForm() {
		CRM_Utils_System::setTitle(ts('Participant Import'));
		$this->addEntityRef('event_id', ts('Select Event'), array(
			'entity' => 'event',
			'placeholder' => ts('- Select Event -'),
			'select' => array('minimumInputLength' => 0),
		));
		$this->addEntityRef('status_id', ts('Select Status'), array(
			'entity' => 'ParticipantStatusType',
			'placeholder' => ts('- Select Status -'),
			'select' => array('minimumInputLength' => 0),
		));
		$this->addEntityRef('role_id', ts('Select Role'), array(
			'entity' => 'OptionValue',
			'api' => array('params' => array('option_group_id' => 'participant_role')),
			'placeholder' => ts('- Select Role -'),
			'select' => array('minimumInputLength' => 0),
		));
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
	
	function postProcess() {
		$values = $this->exportValues();

		$this->location = CIVICRM_TEMPLATE_COMPILEDIR . '/../import-tmp/';
		if(!file_exists($this->location))
			mkdir($this->location, 0777, true);

		$this->filename = $values['event_id'].'-'.date("Ymdhis");
		$this->xmlFile = $this->location.$this->filename.'.xml';
		if(move_uploaded_file($_FILES['participants']['tmp_name'], $this->xmlFile)) {
			if($this->parseXML()) {
				if(count($this->unknownParticipants) && $_POST['create_contacts']) $this->createContacts();
			} else {
				throw new Exception("XML file doesn't contain participant data.");
			}
		} else {
			throw new Exception("Unable to locate XML file.");
		}
		parent::postProcess();
		unlink($this->xmlFile);
		if(count($this->unknownParticipants) && !$_POST['create_contacts']) $this->serializeContacts();
		CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/event-participant-import-complete', array(
			"event" => $_POST['event_id'],
			"new" => $this->new,
			"existing" => $this->existing,
			"matched" => 0
		)));
	}
	
	function parseXML() {
		libxml_use_internal_errors(true);
		if(file_exists($this->xmlFile)) {
			$xmlReader = simplexml_load_file($this->xmlFile);
			if($this->xmlFile) {
				foreach($xmlReader as $participant) {
					if($participant->td[0] == "ID" || empty($participant->td[0]) || empty($participant->td[1]) || empty($participant->td[2])) continue;
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
					$this->existing++;
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 2 - match with firstname, lastname and date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("first_name" => $participant['first_name_1'], "last_name" => $participant['last_name'], 'birth_date' => $participant['birth_date']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					$this->setIdentification($civiContact['id'], $participant['id']);
					$this->existing++;
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 3 - match with lastname and date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("last_name" => $participant['last_name'], 'birth_date' => $participant['birth_date']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					$this->setIdentification($civiContact['id'], $participant['id']);
					$this->existing++;
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Attempt 4 - match with firstname and date of birth
				try {
					$civiContact = civicrm_api3('Contact', 'getsingle', array("first_name" => $participant['first_name_1'], 'birth_date' => $participant['birth_date']));
					$this->registerParticipation($civiContact['id'], $participant['registration']);
					$this->setIdentification($civiContact['id'], $participant['id']);
					$this->existing++;
					continue;
				} catch (Exception $e) {
					$civiContact = null;
				}
				// Still unknown, add to array
				$this->unknownParticipants[$participant['id']] = $participant;
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
				'role_id'			=> $_POST['role_id'],
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
					$this->new++;
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
	
	function serializeContacts() {
		file_put_contents($this->location.$this->filename.".json", json_encode($this->unknownParticipants));
		CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/event-participant-import-form', array(
			'json' => $this->filename.".json",
			"event_id" => $_POST['event_id'],
			"status_id" => $_POST['status_id'],
			"role_id" => $_POST['role_id'],
			"existing" => $this->existing
		)));
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