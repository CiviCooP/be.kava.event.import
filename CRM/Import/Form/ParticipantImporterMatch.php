<?php
require_once 'CRM/Core/Form.php';

class CRM_Import_Form_ParticipantImporterMatch extends CRM_Core_Form {

  public $fields;
  private $unknownParticipants = [];
  private $event_id;
  private $status_id;
  private $role_id;
  private $json;
  private $new = 0;
  private $existing = 0;
  private $matched = 0;

  public function buildQuickForm() {
    /* Set page title */
    CRM_Utils_System::setTitle(ts('Participant Import'));

    /* Gather parameters */
    $this->event_id = ($_SERVER['REQUEST_METHOD'] == "GET") ? $_GET['event_id'] : $_POST['event_id'];
    $this->status_id = ($_SERVER['REQUEST_METHOD'] == "GET") ? $_GET['status_id'] : $_POST['status_id'];
    $this->role_id = ($_SERVER['REQUEST_METHOD'] == "GET") ? $_GET['role_id'] : $_POST['role_id'];
    $this->json = ($_SERVER['REQUEST_METHOD'] == "GET") ? $_GET['json'] : $_POST['json'];
    $this->existing = ($_SERVER['REQUEST_METHOD'] == "GET") ? $_GET['existing'] : $_POST['existing'];
    $this->getParticipants();
    $this->fetchCustom();

    /* Build form using the get parameters */
    if ($_SERVER['REQUEST_METHOD'] == "GET") {
      $this->addElement('hidden', 'event_id', $this->event_id);
      $this->addElement('hidden', 'status_id', $this->status_id);
      $this->addElement('hidden', 'role_id', $this->role_id);
      $this->addElement('hidden', 'json', $this->json);
      $this->addElement('hidden', 'existing', $this->existing);
      $this->addButtons([['type' => 'submit', 'name' => ts('Submit'), 'isDefault' => TRUE]]);
      foreach ($this->unknownParticipants as $participant) {
        $this->addEntityRef("con_" . $participant['id'], ts($participant['first_name_1'] . " " . $participant['last_name']));
      }
      $this->assign('elementNames', $this->getRenderableElementNames());
      parent::buildQuickForm();
    }

    /* Handle the form */
    if ($_SERVER['REQUEST_METHOD'] == "POST") {
      $this->postHandler();
    }
  }

  public function fetchCustom() {
    try {
      $this->fields = new stdClass;
      $this->fields->extraGroup = civicrm_api3('CustomGroup', 'Getsingle', ["name" => "contact_individual"]);
      $this->fields->identificatieNummer = civicrm_api3('CustomField', 'Getsingle', ["name" => "eID_code", "custom_group_id" => $this->fields->extraGroup['id']]);
    } catch (Exception $e) {
      throw new Exception("Customfield eID_code has not been found.");
    }
  }

  public function getParticipants() {
    //$this->unknownParticipants = json_decode(file_get_contents(substr(__DIR__, 0, strpos(__DIR__, "import")) . "import/tmp/" . $this->json), TRUE);

    $location = CIVICRM_TEMPLATE_COMPILEDIR . '/../import-tmp/';
    $this->unknownParticipants = json_decode(file_get_contents($location . $this->json), TRUE);
  }

  public function postHandler() {
    foreach ($_POST as $id => $match) {
      if (!stristr($id, "con_")) {
        continue;
      }
      $id = substr($id, 4);
      if (empty($match)) {
        $participantIdentifier = $this->createContact($this->unknownParticipants[$id]);
      }
      if (!empty($match)) {
        $this->registerParticipation($match, $this->unknownParticipants[$id]['registration']);
        $this->setIdentification($match, $this->unknownParticipants[$id]);
        $this->matched ++;
      }
    }
    //unlink(substr(__DIR__, 0, strpos(__DIR__, "import")) . "import/tmp/" . $this->json);
    $location = CIVICRM_TEMPLATE_COMPILEDIR . '/../import-tmp/';
    unlink($location . $this->json);
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/event-participant-import-complete', [
      "event"    => $this->event_id,
      "new"      => $this->new,
      "existing" => $this->existing,
      "matched"  => $this->matched,
    ]));
  }

  // Functies hieronder zijn wel wat dubbel en niet echt DRY - eigenlijk liever afsplitsen
  public function createContact($participant) {
    try {
      CRM_Import_Logger::log("Trying to create contact for participant in match class (" . $participant['first_name_1'] . " " . $participant['last_name'] . ").");
      if (!empty($participant['first_name_1']) AND !empty($participant['last_name'])) {
        $contact = civicrm_api3('Contact', 'Create', [
          'contact_type'                                       => 'individual',
          'first_name'                                         => trim($participant['first_name_1'] . ' ' . $participant['first_name_2']),
          'last_name'                                          => $participant['last_name'],
          'birth_date'                                         => $participant['birth_date'],
          'custom_' . $this->fields->identificatieNummer['id'] => $participant['id'],
        ]);
        $this->registerParticipation($contact['id'], $participant['registration']);
        civicrm_api3('Address', 'Create', [
          'contact_id'       => $contact['id'],
          'is_primary'       => 1,
          'location_type_id' => 1,
          'street_address'   => $participant['address'],
          'postal_code'      => $participant['postcode'],
          'country_id'       => $this->fetchCountryIdentifier($participant['land']),
        ]);
        $this->new ++;

        return $contact['id'];
      }
    } catch (Exception $e) {
      CRM_Import_Logger::log("ERROR: Failed to create contact in match class! " . $e->getMessage());
      throw new Exception("Failed to create participant! " . $e);
    }
  }

  public function fetchCountryIdentifier($isoCode) {
    try {
      $country = civicrm_api3('Country', 'Getsingle', ['iso_code' => $isoCode]);

      return $country['id'];
    } catch (Exception $e) {
      return NULL;
    }
  }

  public function registerParticipation($participantIdentifier, $date) {
    try {
      CRM_Import_Logger::log("Checking if participant is already registered for contact id " . $participantIdentifier . " and event " . $this->event_id . ".");
      $result = civicrm_api3('Participant', 'Get', [
        'event_id'   => $this->event_id, 
	'contact_id' => $participantIdentifier,
      ]);
      if ($result['count'] != 0) {
	foreach ($result['values'] as $existing_participant) {
	    CRM_Import_Logger::log("Update existing participant record in match class for participant id " . $existing_participant['participant_id'] . " contact id " . $participantIdentifier . " and event " . $this->event_id . ".");
	    civicrm_api3('Participant', 'Create', [
              'id'   	      => $existing_participant['participant_id'],
	      'event_id'      => $this->event_id,
              'contact_id'    => $participantIdentifier,
	      'status_id'     => $this->status_id,
              'role_id'       => $this->role_id,
              'register_date' => $date
	    ]);
	}
      } else {
        CRM_Import_Logger::log("Adding participant record in match class for contact id " . $participantIdentifier . " and event " . $this->event_id . ".");
        civicrm_api3('Participant', 'Create', [
          'event_id'      => $this->event_id,
          'contact_id'    => $participantIdentifier,
          'status_id'     => $this->status_id,
          'role_id'       => $this->role_id,
          'register_date' => $date,
        ]);
      }
    } catch (Exception $e) {
      CRM_Import_Logger::log("ERROR: Failed to create participant record in match class! " . $e->getMessage());
      throw new Exception("Failed to create participant record in match class! " . $e->getMessage());
    }
  }

  public function setIdentification($contact_id, $identificationNumber) {
    try {
      civicrm_api3('CustomValue', 'Create', ['entity_id' => $contact_id, 'custom_' . $this->fields->identificatieNummer['id'] => $identificationNumber]);
    } catch (Exception $e) {
      // Exceptions die niks doen leiden wel snel tot bugs, laten we het iig loggen -KL
      CRM_Import_Logger::log("WARNING: Failed to set identification for contact " . $contact_id . " (identification number " . $identificationNumber . ").");
    }
  }

  private function getRenderableElementNames() {
    $elementNames = [];
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }

    return $elementNames;
  }

}
