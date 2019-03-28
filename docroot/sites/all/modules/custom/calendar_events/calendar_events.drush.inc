<?php
/**
 * Implements hook_drush_command().
 */
function calendar_events_drush_command(){
     $items['gcal-command'] = array(
      'description' => 'Demonstrate how Drush commands work.',
      'aliases' => array('gcal'),
      'drush zd-command' => 'Triggers the internal note and tags for Gcal'
    );
   return $items;
}