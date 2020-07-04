<?php
/**
 * @controller GET Wrapper
 * @desc Wrapper for sending API requests on pure GET only
 */

class Getwrap_Controller extends MVC_Controller
{
    public function index()
    {
        $this->header->allow();

        $type = $this->sanitize->string($this->url->segment(3));

        $this->cache->container("system.settings");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->getSystemSettings());
        endif;

        set_system($this->cache->getAll());

        switch($type):
            case "send":
                $request = $this->sanitize->array($_GET);
                $key = $this->sanitize->string(isset(
                    $_GET["key"]) ? 
                    $_GET["key"] : 
                    response(400, "Invalid Request!")
                );

                if(!$this->sanitize->length($key, 5))
                    response(400, "Invalid Request!");

                $this->cache->container("api.keys");

                if($this->cache->empty()):
                    $this->cache->setArray($this->api->getKeys());
                endif;

                $keys = $this->cache->getAll();

                if(!array_key_exists($key, $keys))
                    response(401, "API key is not valid!");

                $api = $keys[$key];

                $this->cache->container("user.subscription.{$api["hash"]}");

                if($this->cache->empty()):
                    $this->cache->setArray($this->system->checkSubscriptionByUserID($api["uid"]) > 0 ? $this->system->getPackageByUserID($api["uid"]) : $this->system->getDefaultPackage());
                endif;

                set_subscription($this->cache->getAll());

                if(!isset($request["phone"], $request["message"]))
                    response(400, "Invalid Request!");

                $request["phone"] = "+{$request["phone"]}";

                try {
                    $number = $this->phone->parse($request["phone"]);

                    if(!$number->isValidNumber())
                        response(400, "Invalid mobile number!");

                    if(!$number->getNumberType(Brick\PhoneNumber\PhoneNumberType::MOBILE))
                        response(400, "Invalid mobile number!");

                    $request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);
                } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                    response(400, $e->getMessage());
                }

                if(isset($request["sim"])):
                    if(!$this->sanitize->isInt($request["sim"]))
                        response(400, "Invalid Request!");
                endif;

                if(isset($request["priority"])):
                    if(!$this->sanitize->isInt($request["priority"]))
                        response(400, "Invalid Request!");
                endif;

                if(isset($request["device"])):
                    if(!$this->sanitize->isInt($request["device"]))
                        response(400, "Invalid Request!");
                endif;

                if(!$this->sanitize->length($request["message"], 5))
                    response(400, "Message is too short!");

                $this->cache->container("api.devices.{$api["hash"]}");

                if($this->cache->empty()):
                    $this->cache->setArray($this->api->getDevices($api["uid"]));
                endif;

                $devices = $this->cache->getAll();

                if($this->system->checkQuota($api["uid"]) < 1):
                    $this->system->create("quota", [
                        "uid" => $api["uid"],
                        "sent" => 0,
                        "received" => 0
                    ]);
                endif;

                if(limitation(subscription_send, $this->system->countQuota($api["uid"])["sent"]))
                    response(400, "Maximum allowed sending for today has been reached!");

                if(!isset($request["device"])):
                    foreach($devices as $device):
                        $this->cache->container("gateway.{$device["did"]}.{$api["hash"]}");

                        $usort[] = [
                            "id" => $device["id"],
                            "did" => $device["did"],
                            "pending" => count($this->cache->getAll())
                        ];
                    endforeach;
                    
                    usort($usort, function($previous, $next) {
                        return $previous["pending"] > $next["pending"] ? 1 : -1;
                    });

                    $did = $usort[0]["did"];
                    $device = $usort[0]["id"];
                else:
                    if(!array_key_exists($request["device"], $devices)):
                        response(400, "Device doesn't exist!");
                    else:
                        $did = $devices[$request["device"]]["did"];
                        $device = $request["device"];
                    endif;
                endif;

                $filtered = [
                    "uid" => $api["uid"],
                    "did" => $did,
                    "sim" => (isset($request["sim"]) ? ($request["sim"] < 1 ? 0 : 1) : 0),
                    "phone" => $request["phone"],
                    "message" => $request["message"],
                    "status" => 0,
                    "priority" => (isset($request["priority"]) ? ($request["priority"] < 1 ? 0 : 1) : 0),
                    "api" => 1
                ];

                $create = $this->api->create("sent", $filtered);

                if($create):
                    $this->cache->container("gateway.{$did}.{$api["hash"]}");

                    $this->cache->set($create, [
                        "api" => (boolean) 1,
                        "sim" => $filtered["sim"],
                        "device" => (int) $device,
                        "phone" => $filtered["phone"],
                        "message" => $filtered["message"],
                        "priority" => (boolean) ($filtered["priority"] < 1 ? 0 : 1),
                        "timestamp" => time()
                    ]);

                    $this->cache->container("messages.{$api["hash"]}");
                    $this->cache->clear();

                    $this->system->increment($api["uid"], "sent");

                    response(200, "Message has been added to queue on {$devices[$device]["name"]}", [
                        "api" => (boolean) 1,
                        "sim" => $filtered["sim"],
                        "device" => (int) $device,
                        "phone" => $filtered["phone"],
                        "message" => $filtered["message"],
                        "priority" => (boolean) $filtered["priority"],
                        "timestamp" => time()
                    ]);
                else:
                    response(400, "Something went wrong!");
                endif;
        
                break;
            default:
                $vars = [
                    "site_url" => (system_protocol < 2 ? str_replace("//", "http://", site_url) : str_replace("//", "https://", site_url))
                ];
                
                $this->smarty->display("_apidoc/layout.tpl", $vars);
        endswitch;
    }

    public function create()
    {
        $this->header->allow();

        $request = $this->sanitize->array($_GET);
        $type = $this->sanitize->string($this->url->segment(4));
        $key = $this->sanitize->string(isset(
            $_GET["key"]) ? 
            $_GET["key"] : 
            response(400, "Invalid Request!")
        );

        if(empty($key))
            response(400, "Invalid Request!");

        $this->cache->container("api.keys");

        if($this->cache->empty()):
            $this->cache->setArray($this->api->getKeys());
        endif;

        $keys = $this->cache->getAll();

        if(!array_key_exists($key, $keys))
            response(401, "API key is not valid!");

        $api = $keys[$key];

        $this->cache->container("user.subscription.{$api["hash"]}");

        if($this->cache->empty()):
            $this->cache->setArray($this->system->checkSubscriptionByUserID($api["uid"]) > 0 ? $this->system->getPackageByUserID($api["uid"]) : $this->system->getDefaultPackage());
        endif;

        set_subscription($this->cache->getAll());

        switch($type):
            case "contact":
                if(!in_array("create_{$type}", $api["permissions"]))
                    response(403, "Permission \"create_{$type}\" not granted!");

                if(!isset($request["phone"], $request["name"], $request["group"]))
                    response(400, "Invalid Request");

                if(empty($request["name"]))
                    response(400, "Contact name cannot be empty!");

                if(limitation(subscription_contact, $this->system->countContacts($api["uid"])))
                    response(400, "Maximum allowed contacts has been reached!");

                $request["phone"] = "+{$request["phone"]}";

                try {
                    $number = $this->phone->parse($request["phone"]);
                } catch(Brick\PhoneNumber\PhoneNumberParseException $e) {
                    response(400, $e->getMessage());
                }

                if (!$number->isValidNumber())
                    response(400, "Invalid mobile number!");

                if(!$number->getNumberType(Brick\PhoneNumber\PhoneNumberType::MOBILE))
                    response(400, "Invalid mobile number!");

                $request["phone"] = $number->format(Brick\PhoneNumber\PhoneNumberFormat::E164);

                if(!$this->sanitize->isInt($request["group"]))
                    response(400, "Invalid Request!");

                $this->cache->container("api.groups.{$api["hash"]}");

                if($this->cache->empty()):
                    $this->cache->setArray($this->api->getGroups($api["uid"]));
                endif;

                if(!array_key_exists($request["group"], $this->cache->getAll()))
                    response(400, "Invalid Request!");

                $this->cache->container("api.contacts.{$api["hash"]}");

                if($this->cache->empty()):
                    $this->cache->setArray($this->api->getContacts($api["uid"]));
                endif;

                if(array_key_exists($request["phone"], $this->cache->getAll()))
                    response(400, "Contact number already exist!");

                $filtered = [
                    "uid" => $api["uid"],
                    "gid" => $request["group"],
                    "phone" => $request["phone"],
                    "name" => $request["name"]
                ];

                if($this->api->create("contacts", $filtered)):
                    $this->cache->container("api.contacts.{$api["hash"]}");
                    $this->cache->clear();

                    response(200, "Contact saved successfully!");
                else:
                    response(400, "Something went wrong!");
                endif;

                break;
            case "group":
                if(!in_array("create_{$type}", $api["permissions"]))
                    response(403, "Permission \"create_{$type}\" not granted!");

                if(!isset($request["name"]))
                    response(400, "Invalid Request!");

                if(empty($request["name"]))
                    response(400, "Group name cannot be empty!");

                $filtered = [
                    "uid" => $api["uid"],
                    "name" => $request["name"]
                ];

                if($this->api->create("groups", $filtered)):
                    $this->cache->container("api.groups.{$api["hash"]}");
                    $this->cache->clear();

                    response(200, "Contact group saved sauccessfully!");
                else:
                    response(400, "Something went wrong!");
                endif;

                break;
            default:
                response(400, "Invalid Request!");
        endswitch;
    }
}