<?php
/**
 * Russian language file for NeonPlan 3D module
 */

$dictionary = array(
    'NEONPLAN3D' => 'NeonPlan 3D',
    'MODULE_NEONPLAN3D' => 'NeonPlan 3D',
    'NEONPLAN3D_ADMIN_TITLE' => 'Управление NeonPlan 3D',
    'NEONPLAN3D_OPEN_FULLSCREEN' => 'Открыть 3D-план на весь экран',
    'NEONPLAN3D_OPEN_EDITOR' => 'Открыть 3D-план и редактор',
    'NEONPLAN3D_PLANS_STORED' => 'Сохраненные планы',
    'NEONPLAN3D_RESET_DEMO' => 'Загрузить демо-план',
    'NEONPLAN3D_BACKUP' => 'Скачать резервную копию (.json)',
    'NEONPLAN3D_EMBED_TITLE' => 'Встраивание в MajorDoMo',
    'NEONPLAN3D_EMBED_SCENES' => 'Для встраивания в Сцены или Меню MajorDoMo используйте iframe с адресом:',
    'NEONPLAN3D_CONNECTED_DEVICES' => 'Подключенные устройства (Simple Devices)',
    'NEONPLAN3D_CONNECTED_ROOMS' => 'Синхронизированные комнаты (Locations)',
    'NEONPLAN3D_DOCUMENTATION' => 'Инструкция и документация'
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
