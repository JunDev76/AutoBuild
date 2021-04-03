<?php

/*
   _____                   __  __ _       _                 _ _      _  __
  / ____|                 |  \/  (_)     (_)               | | |    (_)/ _|
 | |     _ __ ___  ___ ___| \  / |_ _ __  _ _ __ ___   __ _| | |     _| |_ ___
 | |    | '__/ _ \/ __/ __| |\/| | | '_ \| | '_ ` _ \ / _` | | |    | |  _/ _ \
 | |____| | | (_) \__ \__ \ |  | | | | | | | | | | | | (_| | | |____| | ||  __/
  \_____|_|  \___/|___/___/_|  |_|_|_| |_|_|_| |_| |_|\__,_|_|______|_|_| \___|

This program was produced by CrossTeam and cannot be reproduced, distributed or used without permission.

Development Team
 - Jun-KR (https://github.com/Jun-KR)
 - Le0onKR (https://github.com/Le0onKR)

Team Github
 - Cross-minimal-life (https://github.com/Cross-minimal-life)

Copyright 2021. CrossTeam. Allrights reserved.
 */

namespace CrossMinimalLife;

use JUNKR\form\ButtonForm;
use JUNKR\form\ModalForm;
use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\EmotePacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\Config;

class AutoBuild extends PluginBase implements Listener{

    public $data = [];
    public $block = ["-1,0,0" => "2:0", "0,0,0" => "3:0"];
    public $buildmode = [], $canplace = [];
    public $database, $db;
    public $buildpos = [];

    public function onEnable(){
        $this->database = new Config($this->getDataFolder() . 'data.yml', Config::YAML);
        $this->db = $this->database->getAll();

        Server::getInstance()->getPluginManager()->registerEvents($this, $this);

        $cmd = new PluginCommand("건축물추가", $this);
        $cmd->setDescription("건축물을 관리합니다.");
        $cmd->setPermission("op");
        $this->getServer()->getCommandMap()->register($this->getDescription()->getName(), $cmd);

        $cmd = new PluginCommand("buildpos", $this);
        $cmd->setDescription("buildpos 명령어");
        $cmd->setPermission("op");
        $this->getServer()->getCommandMap()->register($this->getDescription()->getName(), $cmd);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onDisable(){
        $this->database->setAll($this->db);
        $this->database->save();
    }

    public function onjoin(PlayerJoinEvent $ev){
        $this->buildon($ev->getPlayer(), "테스트");
    }

    public $oldblock = [];
    
    public function buildon(Player $player, $name){
        $this->buildmode[$player->getName()] = $name;
        $this->getScheduler()->scheduleRepeatingTask(new class($player, $this) extends Task{
            private $player, $owner;

            public function __construct($player, $owner){
                $this->player = $player;
                $this->owner = $owner;
            }

            public function onRun(int $currentTick){
                if(!$this->player->isOnline()){
                    return;
                }

                if(!isset($this->owner->buildmode[$this->player->getName()])){
                    $this->getHandler()->cancel();
                    return;
                }

                if(!isset($this->owner->canplace[$this->player->getName()])){
                    return;
                }

                $this->player->sendPopup($this->owner->canplace[$this->player->getName()] ? "✅ §a이곳에 §r§e" . $this->owner->buildmode[$this->player->getName()] . "§a(을)를 건설 할 수 있습니다.\n§f§l웅크리기(시작)과 점프를 같이 눌러 건설하세요.\n§7웅크리기(취소)를 눌러 작업을 취소 할 수 있습니다." : "❎ §c이곳에 §r§e" . $this->owner->buildmode[$this->player->getName()] . "§c(을)를 건설 할 수 없습니다.\n§7웅크리기(취소)를 눌러 작업을 취소 할 수 있습니다.");
            }
        }, 5);

        $this->getScheduler()->scheduleRepeatingTask(new class($player, $this) extends Task{
            private $player, $owner;

            public function __construct($player, $owner){
                $this->player = $player;
                $this->owner = $owner;
                $this->owner->oldblock[$player->getName()] = [];
            }

            public function onRun(int $currentTick){
                /** @var Player $player */
                $player = $this->player;

                if(!$player->isOnline()){
                    return;
                }

                if(!isset($this->owner->buildmode[$this->player->getName()])){
                    $this->getHandler()->cancel();
                    return;
                }

                if($this->owner->oldblock[$player->getName()] !== []){
                    $player->getLevel()->sendBlocks([$player], $this->owner->oldblock[$player->getName()]);
                    $this->owner->oldblock[$player->getName()] = [];
                }

                if(!$player->isSneaking()){
                    //    return;
                }

                $yaw = $player->yaw;
                $xyz = $player->add(-sin(deg2rad($player->yaw)) * 4, (sin($player->pitch / 180 * M_PI - 3) * 3) + 2, cos(deg2rad($yaw)) * 4);

                $yaws = [0, 90, 180, 270, 360];
                $ryaws = [360, 90, 180, 270, 360, 90, 180, 270];
                $min = PHP_INT_MAX;

                $target = round($yaw);

                foreach($yaws as $key => $yawone){
                    $a = abs($yawone - $target);

                    if($min > $a){
                        $min = $a;
                        $yaw = $ryaws[$key + 2];
                    }
                }

                $this->owner->canplace[$player->getName()] = true;
                $oldblocks = [];
                $blocks = [];
                foreach($this->owner->db[$this->owner->buildmode[$this->player->getName()]] as $v => $block){
                    $v3 = explode(":", $v);
                    $x = intval($v3[0]);
                    $z = intval($v3[2]);

                    if($yaw === 90 or $yaw === 270){
                        $xt = $x;
                        $zt = $z;

                        $x = $zt;
                        $z = $xt;
                    }

                    if($yaw === 90){
                        $x = $x * -1;
                    }

                    if($yaw === 180){
                        $x = $x * -1;
                        $z = $z * -1;
                    }

                    if($yaw === 270){
                        $z = $z * -1;
                    }

                    $v3 = new Vector3($x, intval($v3[1]), $z);
                    $bid = explode(":", $block);
                    $bdm = $bid[1];
                    $bid = $bid[0];

                    $v3 = new Vector3($xyz->x + $v3->x, $xyz->y + $v3->y, $xyz->z + $v3->z);
                    $oldblocks[] = new Vector3(intval($v3->x), intval($v3->y), intval($v3->z));

                    if(($b = $player->level->getBlock($v3))->getId() !== 0){
                        if($b->isSolid()){
                            $this->owner->canplace[$player->getName()] = false;
                            $bid = 152;
                            $bdm = 0;
                        }
                    }

                    $block = Block::get($bid, $bdm);
                    $block->x = intval($v3->x);
                    $block->y = intval($v3->y);
                    $block->z = intval($v3->z);
                    $block->level = $player->level;
                    $blocks[] = $block;
                }

                $this->owner->oldblock[$player->getName()] = $oldblocks;

                if(count($blocks) !== 0){
                    $player->level->sendBlocks([$player], $blocks);
                }
            }
        }, 5);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if($command->getName() === "건축물추가"){
            $form = new ButtonForm(function(Player $player, $data){
            });

            $form->setTitle("건축물");
            $form->setContent("건축물을 관리합니다");

            $form->addButton("§l건축물 추가");
            $form->addButton("§l건축물 삭제");

            return true;
        }

        if($command->getName() === "buildpos"){
            if(!isset($args[0])){
                $sender->sendMessage("/buildpos 1 || /buildpos 2");
                return true;
            }
            /** @var Player $sender */
            if($args[0] === "1"){
                $this->buildpos[$sender->getName()]['x'][0] = $sender->x;
                $this->buildpos[$sender->getName()]['y'][0] = $sender->y;
                $this->buildpos[$sender->getName()]['z'][0] = $sender->z;
                $sender->sendMessage("완료");
                return true;
            }elseif($args[0] === "2"){
                $this->buildpos[$sender->getName()]['x'][1] = $sender->x;
                $this->buildpos[$sender->getName()]['y'][1] = $sender->y;
                $this->buildpos[$sender->getName()]['z'][1] = $sender->z;
                $sender->sendMessage("완료");
                return true;
            }

            if($args[0] === "toarray"){
                if(!isset($args[1])){
                    return true;
                }
                $x = $this->buildpos[$sender->getName()]['x'];
                $y = $this->buildpos[$sender->getName()]['y'];
                $z = $this->buildpos[$sender->getName()]['z'];

                $pos1[0] = intval(min($x));
                $pos1[1] = intval(min($y));
                $pos1[2] = intval(min($z));

                $pos2[0] = intval(max($x));
                $pos2[1] = intval(max($y));
                $pos2[2] = intval(max($z));

                $array = [];
                for($x = $pos1[0]; $x <= $pos2[0]; $x++)
                    for($y = $pos1[1]; $y <= $pos2[1]; $y++)
                        for($z = $pos1[2]; $z <= $pos2[2]; $z++){
                            $pos = new Vector3((int) $x, (int) $y, (int) $z);
                            if(($b = $sender->level->getBlock($pos))->getId() !== 0){
                                $array[$pos->x . ":" . ($pos->y - 4) . ":" . $pos->z] = $b->getId() . ":" . $b->getDamage();
                            }
                        }

                $this->db[$args[1]] = $array;
            }
        }

        return true;
    }

    public function Teleport(EntityTeleportEvent $ev){
        $player = $ev->getEntity();
        if(!$player instanceof Player){
            return;
        }

        if(isset($this->buildmode[$player->getName()])){
            unset($this->buildmode[$player->getName()]);
        }
    }

    public function move(PlayerMoveEvent $ev){
        $player = $ev->getPlayer();

        if(!isset($this->buildmode[$player->getName()])){
            return;
        }

        if($player->isSneaking()){
            if($player->level->getBlock($player->add(0, -1, 0))->getId() === 0 and $player->level->getBlock($player->add(0, -2, 0))->getId() !== 0){
                if(!$this->canplace[$player->getName()]){
                    return;
                }

                $form = new ModalForm(function(Player $player, $data){
                    if($data === true){
                        $form = new ModalForm(function(Player $player, $data){
                            if($data === true){
                                $xyz = $player->add(-sin(deg2rad($player->yaw)) * 4, (sin($player->pitch / 180 * M_PI - 3) * 3) + 2, cos(deg2rad($player->yaw)) * 4);

                                $yaws = [0, 90, 180, 270, 360];
                                $ryaws = [360, 90, 180, 270, 360, 90, 180, 270];
                                $min = PHP_INT_MAX;

                                $target = round($player->yaw);

                                foreach($yaws as $key => $yawone){
                                    $a = abs($yawone - $target);

                                    if($min > $a){
                                        $min = $a;
                                        $yaw = $ryaws[$key + 2];
                                    }
                                }

                                $blocks = [];
                                foreach($this->db[$this->buildmode[$player->getName()]] as $v => $block){
                                    $v3 = explode(":", $v);
                                    $x = intval($v3[0]);
                                    $z = intval($v3[2]);

                                    if($yaw === 90 or $yaw === 270){
                                        $xt = $x;
                                        $zt = $z;

                                        $x = $zt;
                                        $z = $xt;
                                    }

                                    if($yaw === 90){
                                        $x = $x * -1;
                                    }

                                    if($yaw === 180){
                                        $x = $x * -1;
                                        $z = $z * -1;
                                    }

                                    if($yaw === 270){
                                        $z = $z * -1;
                                    }

                                    $v3 = new Vector3($x, intval($v3[1]), $z);
                                    $bid = explode(":", $block);
                                    $bdm = $bid[1];
                                    $bid = $bid[0];

                                    $v3 = new Vector3($xyz->x + $v3->x, $xyz->y + $v3->y, $xyz->z + $v3->z);
                                    $oldblocks[] = new Vector3(intval($v3->x), intval($v3->y), intval($v3->z));

                                    if(($b = $player->level->getBlock($v3))->getId() !== 0){
                                        if($b->isSolid()){
                                            return;
                                        }
                                    }

                                    $block = Block::get($bid, $bdm);
                                    $block->x = intval($v3->x);
                                    $block->y = intval($v3->y);
                                    $block->z = intval($v3->z);
                                    $block->level = $player->level;
                                    $blocks[] = $block;
                                }

                                $level = $player->level;
                                foreach($blocks as $block){
                                    $level->setBlock($block, $block);
                                }

                                if(isset($this->buildmode[$player->getName()])){
                                    unset($this->buildmode[$player->getName()]);
                                }
                                $player->setSneaking(false);
                            }
                        });

                        $form->setTitle("§l건축하기");
                        $form->setContent("정말 건축하시겠습니까?");

                        $form->setButton1("§l건축하기");
                        $form->setButton2("§l취소");
                        $form->sendForm($player);
                    }
                });

                $form->setTitle("§l건축하기");
                $form->setContent("§e" . $this->buildmode[$player->getName()] . "§f 을(를) 건축하시겠습니까?\n\n되돌릴 수 없습니다.\n\n§f예상 소요시간 : " . count($this->db[$this->buildmode[$player->getName()]]) . "분");

                $form->setButton1("§l건축하기");
                $form->setButton2("§l취소");
                $form->sendForm($player);
                return;
            }
        }
    }

    public function datapacket(DataPacketReceiveEvent $event){
        $player = $event->getPlayer();
        $packet = $event->getPacket();

        if($packet instanceof PlayerActionPacket){
            if($packet->action === PlayerActionPacket::ACTION_STOP_SNEAK){
                if($player->level->getBlock($player->add(0, -1, 0))->getId() === 0 and $player->level->getBlock($player->add(0, -2, 0))->getId() !== 0){
                    return;
                }

                if(isset($this->buildmode[$player->getName()])){
                    $player->sendPopup("✅ §f작업을 취소했습니다.");
                    unset($this->buildmode[$player->getName()]);

                    if(isset($this->oldblock[$player->getName()])){
                        if($this->oldblock[$player->getName()] !== []){
                            $player->getLevel()->sendBlocks([$player], $this->oldblock[$player->getName()]);
                            $this->oldblock[$player->getName()] = [];
                        }
                    }
                }
            }
        }
    }

}