<?php
require_once 'CRM/Core/Form.php';

class CRM_Import_Form_ParticipantImporterMatch extends CRM_Core_Form {

  public $fields, $unknownParticipants = [], $event_id, $status_id, $role_id, $json, $new = 0, $existing = 0, $matched = 0;

  function buildQuickForm() {
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

  function fetchCustom() {
    try {
      $this->fields = new stdClass;
      $this->fields->extraGroup = civicrm_api3('CustomGroup', 'Getsingle', ["name" => "contact_individual"]);
      $this->fields->identificatieNummer = civicrm_api3('CustomField', 'Getsingle', ["name" => "eID_code", "custom_group_id" => $this->fields->extraGroup['id']]);
    } catch (Exception $e) {
      throw new Exception("Customfield eID_code has not been found.");
    }
  }

  function getParticipants() {
    $this->unknownParticipants = json_decode(file_get_contents(substr(__DIR__, 0, strpos(__DIR__, "import")) . "import/tmp/" . $this->json), TRUE);
  }

  function postHandler() {
    foreach ($_POST as $id => $match) {
      if (!stristr($id, "con_")) {
        continue;
      }
      $id = substr($id, 4);
      if (empty($match)) {
        $participantIdentifier = $this->createContact($this->unknownParticipants[ $id ]);
      }
      if (!empty($match)) {
        $this->registerParticipation($match, $this->unknownParticipants[ $id ]['registration']);
        $this->setIdentification($match, $this->unknownParticipants[ $id ]);
        $this->matched ++;
      }
    }
    unlink(substr(__DIR__, 0, strpos(__DIR__, "import")) . "import/tmp/" . $this->json);
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/event-participant-import-complete', [
      "event"    => $this->event_id,
      "new"      => $this->new,
      "existing" => $this->existing,
      "matched"  => $this->matched,
    ]));
  }

  function createContact($participant) {
    try {
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
      throw new Exception("Failed to create participant! " . $e);
    }
  }

  function fetchCountryIdentifier($isoCode) {
    try {
      $country = civicrm_api3('Country', 'Getsingle', ['iso_code' => $isoCode]);

      return $country['id'];
    } catch (Exception $e) {
      return NULL;
    }
  }

  function registerParticipation($participantIdentifier, $date) {
    try {
      civicrm_api3('Participant', 'Create', [
        'event_id'      => $this->event_id,
        'contact_id'    => $participantIdentifier,
        'status_id'     => $this->status_id,
        'status_id'     => $this->role_id,
        'register_date' => $date,
      ]);
    } catch (Exception $e) {
      echo $e;
    }
  }

  function setIdentification($contact_id, $identificationNumber) {
    try {
      civicrm_api3('CustomValue', 'Create', ['entity_id' => $contact_id, 'custom_' . $this->fields->identificatieNummer['id'] => $identificationNumber]);
    } catch (Exception $e) {
    }
  }

  function getRenderableElementNames() {
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