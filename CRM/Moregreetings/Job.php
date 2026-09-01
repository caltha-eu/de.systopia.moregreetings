<?php
/*-------------------------------------------------------+
| SYSTOPIA - MORE GREETINGS EXTENSION                    |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
|         P. Batroff (batroff@systopia.de)               |
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

declare(strict_types = 1);

use Civi\Api4\Contact;

define('MOREGREETINGS_JOB_SIZE', 250);
/**
 * Runner Job to apply the current templates to all contacts
 */
class CRM_Moregreetings_Job {

  public $title     = NULL;
  protected $offset = NULL;
  protected $count  = NULL;

  protected function __construct($offset, $count) {
    $this->offset  = $offset;
    $this->count   = $count;

    // set title
    $this->title = ts('Updating moregreetings on contacts %1-%2', [
      1 => $this->offset,
      2 => $this->offset + $this->count,
      'domain' => 'de.systopia.moregreetings',
    ]);
  }

  public function run($context) {
    // get contact IDs
    $id_query = CRM_Core_DAO::executeQuery("
      SELECT id AS contact_id
      FROM civicrm_contact
      WHERE is_deleted = 0
      LIMIT {$this->count}
      OFFSET {$this->offset}");
    $contact_ids = [];
    while ($id_query->fetch()) {
      $contact_ids[] = $id_query->contact_id;
    }

    // determine the fields to load
    $templates = Civi::settings()->get('moregreetings_templates');
    $used_fields = CRM_Moregreetings_Renderer::getUsedContactFields($templates);

    $active_fields = CRM_Moregreetings_Config::getActiveFields();
    foreach ($active_fields as $key => $field) {
      $field_keys[] = "custom_{$field['id']}";
    }

    $api_select_fields = ['id'];
    $alias_map = [];
    $all_fields = array_merge($field_keys, $used_fields);

    foreach ($all_fields as $field_str) {
      if (preg_match('/custom_(\d+)/', $field_str, $matches)) {
        $field_id = (int) $matches[1];

        $field_info = \Civi\Api4\CustomField::get(FALSE)
          ->addSelect('name', 'custom_group_id.name')
          ->addWhere('id', '=', $field_id)
          ->execute()
          ->first();

        if ($field_info) {
          $real_field_name = $field_info['custom_group_id.name'] . '.' . $field_info['name'];
          $api_select_fields[] = $real_field_name;
          $alias_map[$real_field_name] = "custom_{$field_id}";
        }
      }
      else {
        $api_select_fields[] = $field_str;
      }
    }
    $api_select_fields = array_unique($api_select_fields);

    // load contacts
    // remark: if you change these parameters, see if you also want to adjust
    //  CRM_Moregreetings_Renderer::updateMoreGreetings and CRM_Moregreetings_Renderer::updateMoreGreetingsForContacts
    $contacts = Contact::get(FALSE)
      ->setSelect($api_select_fields)
      ->addSelect('id')
      ->addWhere('id', 'IN', $contact_ids)
      ->execute();
    foreach ($contacts as $contact) {
      foreach ($alias_map as $real_name => $custom_key) {
        if (array_key_exists($real_name, $contact)) {
          $contact[$custom_key] = $contact[$real_name];
        }
      }

      CRM_Moregreetings_Renderer::updateMoreGreetings($contact['id'], $contact);
    }

    return TRUE;
  }

  /**
   * Use CRM_Queue_Runner to apply the templates
   * This doesn't return, but redirects to the runner
   */
  public static function launchApplicationRunner() {
	$queue = self::prepareQueue('moregreetings_application');
    // create a runner and launch it
    $runner = new CRM_Queue_Runner([
      'title'     => ts('Applying Moregreetings Templates', ['domain' => 'de.systopia.moregreetings']),
      'queue'     => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl'  => CRM_Utils_System::url('civicrm/admin/setting/moregreetings', 'reset=1'),
    ]);
    // does not return
    $runner->runAllViaWeb();
  }
/**
   * Use CRM_Queue_Runner to apply the templates
   * This doesn't redirect to the runner
   */
  public static function launchCron() {
    $queue = self::prepareQueue('moregreetings_cron');
    $runner = new CRM_Queue_Runner(array(
      'title'     => ts("Applying Moregreetings Templates by Cron Job", array('domain' => 'de.systopia.moregreetings')),
      'queue'     => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
    ));
    return $runner->runAll();
  }

  /**
   * Prepare queue.
   *
   * @param string $name Name of queue
   *
   * @return \CRM_Queue_Queue
   */
  private static function prepareQueue($name) {
	// get general contact count (not deleted)
    $contact_count = CRM_Core_DAO::singleValueQuery("SELECT COUNT(id) FROM civicrm_contact WHERE is_deleted=0");

    // create a queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'type'  => 'Sql',
      'name'  => $name,
      'reset' => TRUE,
    ));

    // create the items
    for ($offset = 0; $offset < $contact_count; $offset += MOREGREETINGS_JOB_SIZE) {
      $queue->createItem(new CRM_Moregreetings_Job($offset, MOREGREETINGS_JOB_SIZE));
    }

    // create a runner and launch it
    $runner = new CRM_Queue_Runner(array(
      'title'     => ts("Applying Moregreetings Templates by Cron Job", array('domain' => 'de.systopia.moregreetings')),
      'queue'     => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
    ));
    return $queue;
  }
}
