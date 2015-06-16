<?php

require_once 'import.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function import_civicrm_config(&$config) {
  _import_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function import_civicrm_xmlMenu(&$files) {
  _import_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function import_civicrm_install() {
  return _import_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function import_civicrm_uninstall() {
  return _import_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function import_civicrm_enable() {
  return _import_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function import_civicrm_disable() {
  return _import_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function import_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _import_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function import_civicrm_managed(&$entities) {
  return _import_civix_civicrm_managed($entities);
}


/**
 * Implementation of hook_civicrm_navigationMenu *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function import_civicrm_navigationMenu(&$params) {

  foreach($params as &$menu) {
    // print_r($params);
    if(array_key_exists('attributes', $menu) && $menu['attributes']['name'] == 'Events') {

      $maxKey = (max(array_keys($menu['child'])));
      $menu['child'][$maxKey]['attributes']['separator'] = 1;

      $menu['child'][$maxKey+1] = array (
          'attributes' => array (
              'label'      => ts('Deelnemers importeren uit Pharfolio'),
              'name'       => ts('Deelnemers importeren uit Pharfolio'),
              'url'        => 'civicrm/event-participant-import',
              'permission' => 'edit event participants',
              'operator'   => null,
              'separator'  => null,
              'parentID'   => 2,
              'navID'      => $maxKey+1,
              'active'     => 1,
          ),
      );
    }
  }
}