<?php
/**
 * NeonPlan 3D Module for MajorDoMo
 *
 * Visual 3D floor plan editor and interactive home control.
 * Compatible with MajorDoMo Simple Devices and Locations.
 *
 * @package project
 * @author MajorDoMo Community
 * @copyright 2026
 * @license MIT
 */

class neonplan3d extends module
{
    public $storage_dir;

    function __construct()
    {
        $this->name = "neonplan3d";
        $this->title = "NeonPlan 3D";
        $this->module_category = "<#LANG_SECTION_APPLICATIONS#>";
        $this->storage_dir = ROOT . 'cms/neonplan3d/';
        $this->checkInstalled();
    }

    function saveParams($data = 1)
    {
        $p = array();
        if (isset($this->id)) $p["id"] = $this->id;
        if (isset($this->view_mode)) $p["view_mode"] = $this->view_mode;
        if (isset($this->edit_mode)) $p["edit_mode"] = $this->edit_mode;
        if (isset($this->tab)) $p["tab"] = $this->tab;
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id)) $this->id = $id;
        if (isset($mode)) $this->mode = $mode;
        if (isset($view_mode)) $this->view_mode = $view_mode;
        if (isset($edit_mode)) $this->edit_mode = $edit_mode;
        if (isset($tab)) $this->tab = $tab;
    }

    function initDirectories()
    {
        $dirs = [
            $this->storage_dir,
            $this->storage_dir . 'buildings/',
            $this->storage_dir . 'images/',
            $this->storage_dir . 'history/'
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        $plans_file = $this->storage_dir . 'plans.json';
        if (!file_exists($plans_file)) {
            $default_plans = [
                'plans' => [
                    [
                        'id' => 'main',
                        'name' => 'Дом',
                        'icon' => null,
                        'site' => null,
                        'created' => time(),
                        'revision' => 1,
                        'floors' => 1,
                        'rooms' => 0,
                        'furniture' => 0
                    ]
                ],
                'trash' => [],
                'locate_plan' => 'main'
            ];
            file_put_contents($plans_file, json_encode($default_plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $building_file = $this->storage_dir . 'buildings/main.json';
        if (!file_exists($building_file)) {
            $default_building = [
                'version' => 1,
                'settings' => [
                    'wall_exterior' => 0.24,
                    'wall_interior' => 0.12,
                    'grid' => 0.05
                ],
                'floors' => [
                    [
                        'id' => 'floor_eg',
                        'name' => '1 этаж',
                        'elevation' => 0,
                        'height' => 2.7,
                        'cut_height' => 1.2,
                        'rooms' => [],
                        'openings' => [],
                        'furniture' => [],
                        'placements' => [],
                        'background' => null
                    ]
                ]
            ];
            file_put_contents($building_file, json_encode($default_building, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    function install($data = '')
    {
        $this->initDirectories();
        parent::install();
    }

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['DATA_SOURCE'] = $this->data_source;
        $out['TAB'] = $this->tab;
        $this->data = $out;
        if ($this->action == 'admin') {
            $file = 'admin.html';
        } else {
            $file = $this->name.'.html';
        }
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $file, $this->data, $this);
        $this->result = $p->result;
    }

    function admin(&$out)
    {
        $this->initDirectories();
        $this->getParams();

        $plans_data = $this->loadPlansList();
        $out['PLANS_TOTAL'] = count($plans_data['plans']);

        $dev_total = current(SQLSelectOne("SELECT COUNT(*) FROM devices"));
        $out['DEVICES_TOTAL'] = (int)$dev_total;

        $rooms_total = current(SQLSelectOne("SELECT COUNT(*) FROM locations"));
        $out['ROOMS_TOTAL'] = (int)$rooms_total;

        $out['SERVER_URL'] = ROOTHTML;
    }

    function usual(&$out)
    {
        $this->initDirectories();
        $this->getParams();

        $ajax = gr('ajax');
        $action = gr('action');

        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');

            if ($action == 'bootstrap') {
                $bootstrap = $this->getBootstrapData();
                echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($action == 'states') {
                $states = $this->getMjdStatesOnly();
                echo json_encode(['states' => $states], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($action == 'ws_bridge') {
                $payload = json_decode(file_get_contents('php://input'), true);
                if (!$payload && isset($_POST['payload'])) {
                    $payload = json_decode($_POST['payload'], true);
                }
                $response = $this->handleWSMessage($payload ?: []);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($action == 'call_service') {
                $payload = json_decode(file_get_contents('php://input'), true);
                $domain = $payload['domain'] ?? gr('domain');
                $service = $payload['service'] ?? gr('service');
                $data = $payload['data'] ?? [];
                $res = $this->callMjdService($domain, $service, $data);
                echo json_encode(['success' => true, 'result' => $res], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode(['error' => 'Unknown ajax action']);
            exit;
        }

        // Fullscreen view / module web page
        $out['STANDALONE'] = 1;
        $out['ROOTHTML'] = ROOTHTML;
    }

    function loadPlansList()
    {
        $plans_file = $this->storage_dir . 'plans.json';
        if (file_exists($plans_file)) {
            $data = json_decode(file_get_contents($plans_file), true);
            if (is_array($data)) return $data;
        }
        return [
            'plans' => [
                [
                    'id' => 'main',
                    'name' => 'Дом',
                    'icon' => null,
                    'site' => null,
                    'created' => time(),
                    'revision' => 1,
                    'floors' => 1,
                    'rooms' => 0,
                    'furniture' => 0
                ]
            ],
            'trash' => [],
            'locate_plan' => 'main'
        ];
    }

    function savePlansList($data)
    {
        $plans_file = $this->storage_dir . 'plans.json';
        file_put_contents($plans_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    function loadBuilding($plan_id = 'main')
    {
        $plan_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $plan_id) ?: 'main';
        $file = $this->storage_dir . 'buildings/' . $plan_id . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) return $data;
        }
        return [
            'version' => 1,
            'settings' => [
                'wall_exterior' => 0.24,
                'wall_interior' => 0.12,
                'grid' => 0.05
            ],
            'floors' => []
        ];
    }

    function saveBuilding($plan_id, $building)
    {
        $plan_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $plan_id) ?: 'main';
        $file = $this->storage_dir . 'buildings/' . $plan_id . '.json';
        file_put_contents($file, json_encode($building, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Update revision in plans list
        $plans = $this->loadPlansList();
        $rev = 1;
        foreach ($plans['plans'] as &$p) {
            if ($p['id'] == $plan_id) {
                $p['revision'] = ($p['revision'] ?? 1) + 1;
                $rev = $p['revision'];
                // Update stats
                $floors = $building['floors'] ?? [];
                $p['floors'] = count($floors);
                $rooms_cnt = 0;
                $furn_cnt = 0;
                foreach ($floors as $fl) {
                    $rooms_cnt += count($fl['rooms'] ?? []);
                    $furn_cnt += count($fl['furniture'] ?? []);
                }
                $p['rooms'] = $rooms_cnt;
                $p['furniture'] = $furn_cnt;
                break;
            }
        }
        $this->savePlansList($plans);
        return $rev;
    }

    function handleWSMessage($msg)
    {
        $type = $msg['type'] ?? '';

        switch ($type) {
            case 'neonplan3d/plans/list':
                return $this->loadPlansList();

            case 'neonplan3d/plans/create':
                $plans = $this->loadPlansList();
                $id = 'p' . substr(md5(uniqid('', true)), 0, 8);
                $name = $msg['name'] ?? 'Новый план';
                $building = $msg['building'] ?? $this->loadBuilding($msg['copy_of'] ?? 'main');
                $new_entry = [
                    'id' => $id,
                    'name' => $name,
                    'icon' => $msg['icon'] ?? null,
                    'site' => null,
                    'created' => time(),
                    'revision' => 1,
                    'floors' => count($building['floors'] ?? []),
                    'rooms' => 0,
                    'furniture' => 0
                ];
                $plans['plans'][] = $new_entry;
                $this->savePlansList($plans);
                $this->saveBuilding($id, $building);
                return ['plan' => $id];

            case 'neonplan3d/plans/update':
                $plans = $this->loadPlansList();
                $plan_id = $msg['plan'] ?? 'main';
                foreach ($plans['plans'] as &$p) {
                    if ($p['id'] == $plan_id) {
                        if (array_key_exists('name', $msg)) $p['name'] = $msg['name'];
                        if (array_key_exists('icon', $msg)) $p['icon'] = $msg['icon'];
                        if (array_key_exists('site', $msg)) $p['site'] = $msg['site'];
                        break;
                    }
                }
                $this->savePlansList($plans);
                return null;

            case 'neonplan3d/plans/order':
                $order = $msg['plans'] ?? [];
                $plans = $this->loadPlansList();
                $rank = array_flip($order);
                usort($plans['plans'], function ($a, $b) use ($rank) {
                    $ra = $rank[$a['id']] ?? 999;
                    $rb = $rank[$b['id']] ?? 999;
                    return $ra <=> $rb;
                });
                $this->savePlansList($plans);
                return null;

            case 'neonplan3d/plans/delete':
                $plan_id = $msg['plan'] ?? '';
                if ($plan_id && $plan_id != 'main') {
                    $plans = $this->loadPlansList();
                    foreach ($plans['plans'] as $i => $p) {
                        if ($p['id'] == $plan_id) {
                            $p['deleted_at'] = time();
                            $plans['trash'][] = $p;
                            array_splice($plans['plans'], $i, 1);
                            break;
                        }
                    }
                    $this->savePlansList($plans);
                }
                return null;

            case 'neonplan3d/plans/restore':
                $plan_id = $msg['plan'] ?? '';
                $plans = $this->loadPlansList();
                foreach ($plans['trash'] as $i => $p) {
                    if ($p['id'] == $plan_id) {
                        unset($p['deleted_at']);
                        $plans['plans'][] = $p;
                        array_splice($plans['trash'], $i, 1);
                        break;
                    }
                }
                $this->savePlansList($plans);
                return null;

            case 'neonplan3d/building/get':
                $plan_id = $msg['plan'] ?? 'main';
                $plans = $this->loadPlansList();
                $rev = 1;
                foreach ($plans['plans'] as $p) {
                    if ($p['id'] == $plan_id) {
                        $rev = $p['revision'] ?? 1;
                        break;
                    }
                }
                return [
                    'building' => $this->loadBuilding($plan_id),
                    'revision' => $rev,
                    'plan' => $plan_id
                ];

            case 'neonplan3d/building/save':
                $plan_id = $msg['plan'] ?? 'main';
                $building = $msg['building'] ?? [];
                $new_rev = $this->saveBuilding($plan_id, $building);
                return ['revision' => $new_rev];

            case 'neonplan3d/image/get':
                $img_id = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $msg['image_id'] ?? ($msg['id'] ?? ''));
                $file = $this->storage_dir . 'images/' . $img_id;
                $content = file_exists($file) ? file_get_contents($file) : null;
                return ['image' => $content, 'data' => $content];

            case 'neonplan3d/image/set':
                $img_id = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $msg['image_id'] ?? ($msg['id'] ?? ''));
                $data = $msg['data'] ?? ($msg['image'] ?? '');
                if ($img_id && $data) {
                    file_put_contents($this->storage_dir . 'images/' . $img_id, $data);
                }
                return null;

            case 'neonplan3d/image/delete':
                $img_id = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $msg['image_id'] ?? ($msg['id'] ?? ''));
                $file = $this->storage_dir . 'images/' . $img_id;
                if (file_exists($file)) @unlink($file);
                return null;

            case 'neonplan3d/history/list':
                $plan_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $msg['plan'] ?? 'main');
                $hist_file = $this->storage_dir . 'history/' . $plan_id . '.json';
                $history = file_exists($hist_file) ? json_decode(file_get_contents($hist_file), true) : [];
                $arr = is_array($history) ? $history : [];
                return ['snapshots' => $arr, 'points' => $arr];

            case 'neonplan3d/history/snapshot':
                $plan_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $msg['plan'] ?? 'main');
                $hist_file = $this->storage_dir . 'history/' . $plan_id . '.json';
                $history = file_exists($hist_file) ? json_decode(file_get_contents($hist_file), true) : [];
                if (!is_array($history)) $history = [];
                $snap_id = 's_' . time();
                array_unshift($history, [
                    'id' => $snap_id,
                    'saved_at' => time(),
                    'building' => $msg['building'] ?? $this->loadBuilding($plan_id)
                ]);
                $history = array_slice($history, 0, 15);
                file_put_contents($hist_file, json_encode($history, JSON_UNESCAPED_UNICODE));
                return ['id' => $snap_id];

            case 'neonplan3d/history/restore':
                $plan_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $msg['plan'] ?? 'main');
                $snap_id = $msg['snapshot_id'] ?? ($msg['id'] ?? '');
                $hist_file = $this->storage_dir . 'history/' . $plan_id . '.json';
                $history = file_exists($hist_file) ? json_decode(file_get_contents($hist_file), true) : [];
                foreach ($history as $h) {
                    if ($h['id'] == $snap_id) {
                        $rev = $this->saveBuilding($plan_id, $h['building']);
                        return ['building' => $h['building'], 'revision' => $rev];
                    }
                }
                return ['error' => 'Snapshot not found'];

            case 'neonplan3d/plans/locate':
                return ['plan' => $msg['plan'] ?? 'main'];

            case 'neonplan3d/packs/list':
                return [
                    'packs' => [
                        [
                            'format' => 'fp3dpack',
                            'version' => 1,
                            'id' => 'majordomo.pro',
                            'name' => 'MajorDoMo 3D Pro',
                            'publisher' => 'MajorDoMo',
                            'licensee' => 'MajorDoMo User',
                            'features' => [
                                'camera_cockpit',
                                'weather',
                                'screens',
                                'energy_pro',
                                'sound',
                                'auto_pro',
                                'time_travel',
                                'pool',
                                'household',
                                'frames',
                                'fridge_smart',
                                'fun_pets',
                                'fun_halloween',
                                'fun_winter'
                            ],
                            'items' => []
                        ]
                    ]
                ];

            case 'neonplan3d/license/get':
                return [
                    'instance' => 'majordomo_standalone',
                    'active' => true,
                    'key_hint' => 'MJD-PRO',
                    'licensee' => 'MajorDoMo',
                    'checked_at' => time(),
                    'error' => null,
                    'shop_url' => 'https://mastershort.de/neonplan3d/',
                    'packs' => [],
                    'offers' => []
                ];

            case 'neonplan3d/screens/list':
                return ['screens' => []];

            case 'neonplan3d/screens/reload':
                return ['sent' => true];

            case 'neonplan3d/voice/settings':
                return ['sentences' => 'off'];

            case 'neonplan3d/modules/code':
                return ['code' => ''];

            case 'neonplan3d/diagnostics/get':
                return ['enabled' => false];

            default:
                return null;
        }
    }

    function getMjdAreas()
    {
        $areas = [];
        $locations = SQLSelect("SELECT ID, TITLE, PRIORITY FROM locations ORDER BY PRIORITY DESC, TITLE");
        if (is_array($locations)) {
            foreach ($locations as $loc) {
                $areas[(string)$loc['ID']] = [
                    'area_id' => (string)$loc['ID'],
                    'name' => $loc['TITLE'],
                    'floor_id' => null
                ];
            }
        }
        return $areas;
    }

    function getMjdDevicesAndStates()
    {
        $entities = [];
        $states = [];
        $devices_dict = [];

        $devices = SQLSelect("SELECT * FROM devices ORDER BY TITLE");
        if (!is_array($devices)) return ['entities' => $entities, 'states' => $states, 'devices' => $devices_dict];

        foreach ($devices as $dev) {
            $obj = $dev['LINKED_OBJECT'];
            if (!$obj) continue;

            $type = strtolower($dev['TYPE'] ?? '');
            $title = $dev['TITLE'] ?: $obj;
            $loc_id = (string)($dev['LOCATION_ID'] ?? '');
            $dev_id = (string)$dev['ID'];

            $devices_dict[$dev_id] = [
                'id' => $dev_id,
                'name' => $title,
                'area_id' => $loc_id ?: null
            ];

            $domain = 'switch';
            $attrs = [
                'friendly_name' => $title,
                'supported_features' => 0
            ];

            switch ($type) {
                case 'relay':
                    $domain = preg_match('/свет|light|лампа|люстра|бра|подсветка/ui', $title) ? 'light' : 'switch';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    break;

                case 'dimmer':
                    $domain = 'light';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    $level = (int)gg($obj . '.level');
                    $attrs['brightness'] = round(($level / 100) * 255);
                    $attrs['color_mode'] = 'brightness';
                    $attrs['supported_color_modes'] = ['brightness'];
                    break;

                case 'rgb':
                    $domain = 'light';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    $color = gg($obj . '.color');
                    $rgb = [255, 255, 255];
                    if ($color && preg_match('/#?([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})/i', $color, $m)) {
                        $rgb = [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
                    }
                    $attrs['rgb_color'] = $rgb;
                    $attrs['color_mode'] = 'rgb';
                    $attrs['supported_color_modes'] = ['rgb'];
                    $level = (int)gg($obj . '.level');
                    if ($level > 0) $attrs['brightness'] = round(($level / 100) * 255);
                    break;

                case 'motion':
                    $domain = 'binary_sensor';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    $attrs['device_class'] = 'motion';
                    break;

                case 'openclose':
                    $domain = 'binary_sensor';
                    $status = (int)gg($obj . '.status');
                    // In MajorDoMo: status = 1 means closed, status = 0 means open
                    // In Home Assistant binary_sensor (door/window): 'off' is closed, 'on' is open
                    $state_val = ($status === 1) ? 'off' : 'on';
                    $attrs['device_class'] = preg_match('/окн|window/ui', $title) ? 'window' : 'door';
                    break;

                case 'openable':
                    $domain = 'cover';
                    $status = (int)gg($obj . '.status');
                    // In MajorDoMo: status = 1 means closed, status = 0 means open
                    $is_closed = ($status === 1);
                    $state_val = $is_closed ? 'closed' : 'open';
                    $level = gg($obj . '.levelWork');
                    if ($level === '') $level = gg($obj . '.level');
                    if ($level !== '' && is_numeric($level)) {
                        $attrs['current_position'] = (int)$level;
                    } else {
                        $attrs['current_position'] = $is_closed ? 0 : 100;
                    }
                    $attrs['device_class'] = preg_match('/окн|window/ui', $title) ? 'window' : (preg_match('/штор|curtain|blind/ui', $title) ? 'curtain' : 'door');
                    break;

                case 'leak':
                    $domain = 'binary_sensor';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    $attrs['device_class'] = 'moisture';
                    break;

                case 'smoke':
                    $domain = 'binary_sensor';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    $attrs['device_class'] = 'smoke';
                    break;

                case 'temp':
                case 'sensor_temp':
                case 'hum':
                case 'sensor_humidity':
                case 'sensor_temphum':
                case 'temphum':
                case 'temp_hum':
                    $has_humidity = (in_array($type, ['hum', 'sensor_humidity', 'sensor_temphum', 'temphum', 'temp_hum']) || gg($obj . '.valueHumidity') !== '');
                    $has_temperature = (in_array($type, ['temp', 'sensor_temp', 'sensor_temphum', 'temphum', 'temp_hum']) || gg($obj . '.value') !== '');

                    if ($has_humidity && $has_temperature) {
                        // Combined temperature + humidity sensor: emit entities so both room pickers find them
                        $temp_val = gg($obj . '.value');
                        $hum_val  = gg($obj . '.valueHumidity');

                        $main_id = 'sensor.' . strtolower($obj);
                        $temp_id = 'sensor.' . strtolower($obj) . '_temperature';
                        $hum_id  = 'sensor.' . strtolower($obj) . '_humidity';

                        // Main entity (temperature)
                        $entities[$main_id] = [
                            'entity_id' => $main_id,
                            'name'      => $title,
                            'area_id'   => $loc_id ?: null,
                            'device_id' => $dev_id
                        ];
                        $states[$main_id] = [
                            'entity_id'    => $main_id,
                            'state'        => ($temp_val !== '') ? (string)$temp_val : '0',
                            'attributes'   => [
                                'device_class'        => 'temperature',
                                'unit_of_measurement' => '°C',
                                'friendly_name'       => $title,
                            ],
                            'last_updated' => date('c')
                        ];

                        // Temperature sub-entity
                        $entities[$temp_id] = [
                            'entity_id' => $temp_id,
                            'name'      => $title . ' Temperature',
                            'area_id'   => $loc_id ?: null,
                            'device_id' => $dev_id
                        ];
                        $states[$temp_id] = [
                            'entity_id'    => $temp_id,
                            'state'        => ($temp_val !== '') ? (string)$temp_val : '0',
                            'attributes'   => [
                                'device_class'        => 'temperature',
                                'unit_of_measurement' => '°C',
                                'friendly_name'       => $title . ' Temperature',
                            ],
                            'last_updated' => date('c')
                        ];

                        // Humidity sub-entity
                        $entities[$hum_id] = [
                            'entity_id' => $hum_id,
                            'name'      => $title . ' Humidity',
                            'area_id'   => $loc_id ?: null,
                            'device_id' => $dev_id
                        ];
                        $states[$hum_id] = [
                            'entity_id'    => $hum_id,
                            'state'        => ($hum_val !== '') ? (string)$hum_val : '0',
                            'attributes'   => [
                                'device_class'        => 'humidity',
                                'unit_of_measurement' => '%',
                                'friendly_name'       => $title . ' Humidity',
                            ],
                            'last_updated' => date('c')
                        ];

                        continue 2;
                    } elseif ($has_humidity) {
                        $domain = 'sensor';
                        $val = gg($obj . '.value');
                        if ($val === '') $val = gg($obj . '.valueHumidity');
                        $state_val = ($val !== '') ? (string)$val : '0';
                        $attrs['device_class'] = 'humidity';
                        $attrs['unit_of_measurement'] = '%';
                    } else {
                        $domain = 'sensor';
                        $val = gg($obj . '.value');
                        $state_val = ($val !== '') ? (string)$val : '0';
                        $attrs['device_class'] = 'temperature';
                        $attrs['unit_of_measurement'] = '°C';
                    }
                    break;

                case 'sensor_power':
                    $domain = 'sensor';
                    $val = gg($obj . '.value');
                    $state_val = ($val !== '') ? (string)$val : '0';
                    $attrs['device_class'] = 'power';
                    $attrs['unit_of_measurement'] = 'W';
                    break;

                case 'thermostat':
                    $domain = 'climate';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'heat' : 'off';
                    // In MajorDoMo: target temperature is currentTargetValue, room temperature is value
                    $target_temp = gg($obj . '.currentTargetValue');
                    if ($target_temp === '' || !is_numeric($target_temp)) {
                        $target_temp = gg($obj . '.normalTargetValue');
                    }
                    $curr_temp = gg($obj . '.value');
                    $attrs['temperature'] = ($target_temp !== '') ? (float)$target_temp : 21.0;
                    $attrs['current_temperature'] = ($curr_temp !== '') ? (float)$curr_temp : 20.0;
                    $attrs['hvac_modes'] = ['off', 'heat', 'cool'];

                    break;

                case 'vacuum':
                    $domain = 'vacuum';
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'cleaning' : 'docked';
                    break;

                case 'camera':
                    $domain = 'camera';
                    $state_val = 'idle';

                    $preview = gg($obj . '.snapshotPreviewURL');
                    if (!$preview) {
                        callMethod($obj . '.updatePreview');
                        $preview = gg($obj . '.snapshotPreviewURL');
                    }
                    if (!$preview) {
                        $snap = gg($obj . '.snapshotURL');
                        // Use snapshotURL only if it is not an RTSP stream
                        if ($snap && !preg_match('/^rtsp:\/\//i', $snap)) {
                            $preview = $snap;
                        }
                    }
                    if ($preview && !preg_match('/^https?:\/\//i', $preview) && !preg_match('/^\//', $preview)) {
                        $preview = '/' . $preview;
                    }

                    $attrs['entity_picture'] = $preview ?: '';
                    $attrs['model'] = 'camera';
                    $attrs['brand'] = 'MajorDoMo';
                    $stream = gg($obj . '.streamURL');
                    if (!$stream) $stream = gg($obj . '.streamURL_HQ');
                    if ($stream) $attrs['stream_source'] = $stream;
                    break;

                default:
                    $status = (int)gg($obj . '.status');
                    $state_val = $status ? 'on' : 'off';
                    break;
            }

            $entity_id = strtolower($domain . '.' . $obj);

            $entities[$entity_id] = [
                'entity_id' => $entity_id,
                'name' => $title,
                'area_id' => $loc_id ?: null,
                'device_id' => (string)$dev['ID']
            ];

            $states[$entity_id] = [
                'entity_id' => $entity_id,
                'state' => $state_val,
                'attributes' => $attrs,
                'last_updated' => date('c')
            ];
        }

        return ['entities' => $entities, 'states' => $states, 'devices' => $devices_dict];
    }

    function getMjdStatesOnly()
    {
        $res = $this->getMjdDevicesAndStates();
        return $res['states'];
    }

    function getBootstrapData()
    {
        $areas = $this->getMjdAreas();
        $devs = $this->getMjdDevicesAndStates();
        $plans = $this->loadPlansList();
        $building = $this->loadBuilding('main');

        $lat = defined('SETTINGS_SITE_LATITUDE') ? (float)SETTINGS_SITE_LATITUDE : 55.75;
        $lon = defined('SETTINGS_SITE_LONGITUDE') ? (float)SETTINGS_SITE_LONGITUDE : 37.61;

        return [
            'language' => defined('SETTINGS_SITE_LANGUAGE') && SETTINGS_SITE_LANGUAGE == 'ru' ? 'ru' : 'en',
            'areas' => $areas,
            'entities' => $devs['entities'],
            'devices' => $devs['devices'] ?? [],
            'states' => $devs['states'],
            'plans' => $plans,
            'building' => $building,
            'config' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'unit_system' => [
                    'temperature' => '°C',
                    'length' => 'm'
                ]
            ]
        ];
    }

    function callMjdService($domain, $service, $data)
    {
        $entity_id = $data['entity_id'] ?? null;
        if (!$entity_id) return false;

        $target_ids = is_array($entity_id) ? $entity_id : [$entity_id];

        foreach ($target_ids as $id) {
            $parts = explode('.', $id, 2);
            $dom = $parts[0] ?? '';
            $obj_name = $parts[1] ?? '';
            if (!$obj_name) continue;

            // Handle virtual sub-entities like sensor.devicename_temperature or sensor.devicename_humidity
            if (preg_match('/^(.+)_(temperature|humidity)$/', $obj_name, $m)) {
                $obj_name = $m[1];
            }

            $obj = getObject($obj_name);
            if (!$obj) continue;

            if ($service == 'toggle') {
                callMethod($obj_name . '.switch');
            } elseif ($service == 'turn_off') {
                callMethod($obj_name . '.turnOff');
            } elseif ($service == 'turn_on') {
                if (isset($data['brightness_pct'])) {
                    //setGlobal($obj_name . '.level', (int)$data['brightness_pct']);
                    callMethod($obj_name . '.setLevel', ['value' => (int)$data['brightness_pct']]);
                }
                if (isset($data['rgb_color']) && is_array($data['rgb_color'])) {
                    $rgb = $data['rgb_color'];
                    $hex = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
                    setGlobal($obj_name . '.color', $hex);
                }
                callMethod($obj_name . '.turnOn');
            } elseif ($service == 'open_cover') {
                callMethod($obj_name . '.open');
            } elseif ($service == 'close_cover') {
                callMethod($obj_name . '.close');
            } elseif ($service == 'set_cover_position' && isset($data['position'])) {
                callMethod($obj_name . '.setLevel', ['value' => (int)$data['position']]);
            } elseif ($service == 'set_temperature' && isset($data['temperature'])) {
                callMethod($obj_name . '.setTargetTemperature', ['value' => (float)$data['temperature']]);
            } elseif ($service == 'start') {
                callMethod($obj_name . '.start');
            } elseif ($service == 'return_to_base') {
                callMethod($obj_name . '.pause');
            } elseif ($service == 'update_preview' || $service == 'snapshot') {
                callMethod($obj_name . '.updatePreview');
            } elseif ($service == 'take_snapshot') {
                callMethod($obj_name . '.takeSnapshot');
            }
        }
        return true;
    }
}
