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
 * Implement a "Finder", i.e. a class that will identify potential dupes in the DB
 */
class CRM_Xdedupe_Finder_PostalCodeStreetCity extends CRM_Xdedupe_Finder_Address {

  public function __construct($alias, $params) {
    parent::__construct($alias, $params, ['postal_code', 'street_address', 'city']);
  }

  /**
   * get the name of the finder
   * @return string name
   */
  public function getName() {
    return E::ts("Postal Code, Street Address and City");
  }

  /**
   * get an explanation what the finder does
   * @return string name
   */
  public function getHelp() {
    return E::ts("Looks for identical postal code, street address, and city");
  }
}
