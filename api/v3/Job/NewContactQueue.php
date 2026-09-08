<?php


function _civicrm_api3_job_new_contact_queue_spec(&$spec) {
}
function civicrm_api3_job_new_contact_queue($params) {
  $queueSize = 200;
  $start = microtime(TRUE);

  $query = "SELECT id, contact_id FROM civicrm_declinator_queue ORDER BY id ASC LIMIT %1";
  $dao = CRM_Core_DAO::executeQuery($query, [1 => [$queueSize, 'Integer']]);

  $processedCount = 0;
  while ($dao->fetch()) {
    $queueId = $dao->id;
    $contactId = $dao->contact_id;

    CRM_Moregreetings_Renderer::updateMoreGreetings($contactId);

    CRM_Core_DAO::executeQuery(
      "DELETE FROM civicrm_declinator_queue WHERE id = %1",
      [1 => [$queueId, 'Integer']]
    );

    $processedCount++;
  }

  return civicrm_api3_create_success([
    'count' => $processedCount,
    'time' => microtime(TRUE) - $start,
  ], $params);
}
