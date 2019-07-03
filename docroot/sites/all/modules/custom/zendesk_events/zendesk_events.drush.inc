<?php
function zendesk_events_drush_command(){
     $items['zd-command'] = array(
      'description' => 'Reference events in Google Cal to new Zendesk tickets.',
      'aliases' => array('dzc'),
      'drush zd-command' => 'Triggers the internal note and tags for ZenDesk'
    );
   return $items;
}
