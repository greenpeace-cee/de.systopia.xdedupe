<?php
/*-------------------------------------------------------+
| SYSTOPIA's Extended Deduper                            |
| Copyright (C) 2019 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Xdedupe_ExtensionUtil as E;

/**
 * This is the actual merge process
 */
class CRM_Xdedupe_Merge{

  protected $resolvers = [];
  protected $required_contact_attributes = NULL;
  protected $force_merge = FALSE;
  protected $stats = [];
  protected $merge_log = NULL;
  protected $merge_log_handle = NULL;
  protected $_contact_cache = [];

  public function __construct($params) {
    // initialise stats
    $this->stats = [
        'tuples_merged'      => 0,
        'contacts_merged'    => 0,
        'conflicts_resolved' => 0,
        'errors'             => [],
        'failed'             => [],
    ];

    // get resolvers and the required attributes
    $this->resolvers = [];
    $required_contact_attributes = ['is_deleted', 'contact_type'];
    $resolver_classes = explode(',', CRM_Utils_Array::value('resolvers', $params, ''));
    foreach ($resolver_classes as $resolver_class) {
      $resolver_class = trim($resolver_class);
      if (empty($resolver_class)) continue;
      if (class_exists($resolver_class)) {
        /** @var $resolver CRM_Xdedupe_Resolver */
        $resolver = new $resolver_class($this);
        $this->resolvers[] = $resolver;
        $required_contact_attributes += $resolver->getContactAttributes();
      } else {
        $this->logError("Resolver class '{$resolver_class}' not found!");
      }
    }
    $this->required_contact_attributes = implode(',', $required_contact_attributes);

    // set force merge
    $this->force_merge = !empty($params['force_merge']);

    // initialise merge_log
    if (!empty($params['merge_log'])) {
      $this->merge_log = $params['merge_log'];
    } else {
      $this->merge_log = tempnam('/tmp', 'xdedupe_merge');
    }
    $this->merge_log_handle = fopen($this->merge_log, 'a');
  }

  public function __destruct() {
    if ($this->merge_log_handle) {
      fclose($this->merge_log_handle);
    }
  }

  /**
   * Log a general merge message to the merge log
   *
   * @param $message string message
   */
  public function log($message) {
    fputs($this->merge_log_handle, $message);
    CRM_Core_Error::debug_log_message("XMERGE: {$message}");
  }

  /**
   * Log an error message to the merge log, and the internal error counter
   *
   * @param $message string message
   */
  public function logError($message) {
    $this->stats['errors'][] = $message;
    $this->log("ERROR: " . $message);
  }

  /**
   * @param $main_contact_id   int   main contact ID
   * @param $other_contact_ids array other contact IDs
   */
  public function multiMerge($main_contact_id, $other_contact_ids) {
    // do some verification here
    $this->loadContacts([$main_contact_id] + $other_contact_ids);
    $main_contact = $this->getContact($main_contact_id);
    if (!empty($main_contact['is_deleted'])) {
      $this->logError("Main contact [{$main_contact_id}] is deleted. This is wrong!");
      return;
    }

    // now simply merge all contacts individually:
    foreach ($other_contact_ids as $other_contact_id) {
      $this->merge($main_contact_id, $other_contact_id);
    }
  }

  /**
   * Merge the other contact into the main contact, using
   *  CiviCRM's merge function. Before, though, the
   *  resovlers ar applied.
   *
   * @param $main_contact_id  int main contact ID
   * @param $other_contact_id int other contact ID
   */
  public function merge($main_contact_id, $other_contact_id) {
    // first: verify that the contact's are "fit" for merging
    $this->loadContacts([$main_contact_id, $other_contact_id]);
    $main_contact = $this->getContact($main_contact_id);
    if (!empty($main_contact['is_deleted'])) {
      $this->logError("Main contact [{$main_contact_id}] is deleted. This is wrong!");
      return;
    }
    $other_contact = $this->getContact($other_contact_id);
    if (!empty($other_contact['is_deleted'])) {
      $this->logError("Other contact [{$other_contact_id}] is deleted. This is wrong!");
      return;
    }

    try {
      // then: run resolvers
      /** @var $resolver CRM_Xdedupe_Resolver */
      foreach ($this->resolvers as $resolver) {
        $changes = $resolver->resolve($main_contact_id, $other_contact_id);
        if ($changes) {
          $this->stats['conflicts_resolved'] += 1;
          $this->unloadContact($main_contact_id);
        }
      }

      // now: run the merge
      civicrm_api3('Contact', 'merge', [
          'to_keep_id'   => $main_contact_id,
          'to_remove_id' => $other_contact_id,
          'mode'         => ($this->force_merge ? '' : 'safe')
      ]);

    } catch (Exception $ex) {
      // TODO error handling
    }

    // finally: update the stats
    $this->unloadContact($main_contact_id);
    $this->unloadContact($other_contact_id);
    // TODO
  }


  /**
   * Load the given contact IDs into the internal contact cache
   *
   * @param $contact_ids array list of contact IDs
   * @return array list of contact IDs that have been loaded into cache, the other ones were already in there
   */
  public function loadContacts($contact_ids) {
    // first: check which ones are already there
    $contact_ids_to_load = [];
    foreach ($contact_ids as $contact_id) {
      if (!isset($this->_contact_cache[$contact_id])) {
        $contact_ids_to_load[] = $contact_id;
      }
    }

    // load remaining contacts
    if (!empty($contact_ids_to_load)) {
      $query = civicrm_api3('Contact', 'get', [
          'id'           => ['IN' => $contact_ids_to_load],
          'option.limit' => 0,
          'return'       => $this->required_contact_attributes,
          'sequential'   => 0
      ]);
      foreach ($query['values'] as $contact) {
        $this->_contact_cache[$contact['id']] = $contact;
      }
    }

    return $contact_ids_to_load;
  }

  /**
   * Remove the given contact ID from cache, e.g. when we know it's changed
   */
  public function unloadContact($contact_id) {
    unset($this->_contact_cache[$contact_id]);
  }

  /**
   * Get the single contact. If it's not cached, load it first
   * @param $contact_id int contact ID to load
   *
   * @return array contact data
   */
  public function getContact($contact_id) {
    if (!isset($this->_contact_cache[$contact_id])) {
      $this->loadContacts([$contact_id]);
    }
    return $this->_contact_cache[$contact_id];
  }
}
