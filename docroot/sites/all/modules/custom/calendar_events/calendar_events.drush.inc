<?php
function calendar_events_drush_command(){
     $items['gcal-command'] = array(
      'description' => 'Grabs Calendar events & associated ticket IDs',
      'aliases' => array('gcal'),
      'drush zd-command' => 'Triggers the internal note and tags for Gcal'
    );
   return $items;
}
