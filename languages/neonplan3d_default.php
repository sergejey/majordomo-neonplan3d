<?php
/**
 * Default language file for NeonPlan 3D module
 */

$dictionary = array(
    'NEONPLAN3D' => 'NeonPlan 3D',
    'MODULE_NEONPLAN3D' => 'NeonPlan 3D',
    'NEONPLAN3D_ADMIN_TITLE' => 'NeonPlan 3D Configuration',
    'NEONPLAN3D_OPEN_FULLSCREEN' => 'Open 3D Floor Plan (Fullscreen)',
    'NEONPLAN3D_OPEN_EDITOR' => 'Open 3D Plan & Editor',
    'NEONPLAN3D_PLANS_STORED' => 'Stored Plans',
    'NEONPLAN3D_RESET_DEMO' => 'Reset to Demo Floor Plan',
    'NEONPLAN3D_BACKUP' => 'Download Backup (.json)',
    'NEONPLAN3D_EMBED_TITLE' => 'Embedding in MajorDoMo',
    'NEONPLAN3D_EMBED_SCENES' => 'To embed in MajorDoMo Scenes or Layouts, use an iframe with URL:',
    'NEONPLAN3D_CONNECTED_DEVICES' => 'Connected Simple Devices',
    'NEONPLAN3D_CONNECTED_ROOMS' => 'Mapped Rooms / Locations',
    'NEONPLAN3D_DOCUMENTATION' => 'Manual and Documentation'
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
