<?php

declare(strict_types=1);

/*  
 *  A plugin for PocketMine-MP.
 *
 *	This program is free software: you can redistribute it and/or modify
 *	it under the terms of the GNU General Public License as published by
 *	the Free Software Foundation, either version 3 of the License, or
 *	(at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU General Public License for more details.
 *
 *	You should have received a copy of the GNU General Public License
 *	along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 	
 */

namespace usy4\FPS;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\ServerboundDiagnosticsPacket;
use pocketmine\utils\TextFormat;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\resourcepacks\ResourcePack;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use Ifera\ScoreHud\event\PlayerTagUpdateEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use libCustomPack\libCustomPack;

class Main extends PluginBase implements Listener
{

    /** @var array<string, int> */
    private array $playerFps = [];

    /** @var array<string, array> */
    private array $playerSettings = [];

    /** @var Config */
    private Config $playerData;

    /** @var bool */
    private bool $scoreHudEnabled = false;

    /** @var ResourcePack */
    private static ResourcePack $pack;

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        // Save default config from resources
        $this->saveResource("config.yml");

        // Create plugin data folder
        if (!is_dir($this->getDataFolder())) {
            mkdir($this->getDataFolder());
        }

        // Initialize player data config
        $this->playerData = new Config($this->getDataFolder() . "players.yml", Config::YAML);

        // Initialize resource pack from plugin resources
        $this->initializeResourcePack();

        // Check for ScoreHUD
        $this->scoreHudEnabled = $this->getServer()->getPluginManager()->getPlugin("ScoreHud") !== null;
    }

    public function onDisable(): void
    {
        $this->savePlayerData();

        // Unregister and cleanup resource pack
        if (isset(self::$pack)) {
            libCustomPack::unregisterResourcePack(self::$pack);
            $this->getLogger()->debug('Resource pack uninstalled');
            @unlink($this->getDataFolder() . self::$pack->getPackName() . '.mcpack');
            $this->getLogger()->debug('Resource pack file deleted');
        }

        $this->getLogger()->info(TextFormat::RED . "FPS disabled.");
    }

    private function initializeResourcePack(): void
    {
        try {
            // Register resource pack generated from plugin resources
            libCustomPack::registerResourcePack(self::$pack = libCustomPack::generatePackFromResources($this));
            $this->getLogger()->info(TextFormat::GREEN . "✓ Resource pack registered from plugin resources");
        } catch (\Exception $e) {
            $this->getLogger()->error("Failed to register resource pack: " . $e->getMessage());
        }
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $this->loadPlayerSettings($player);

        // Initialize ScoreHUD tag as hidden on join
        $this->updateScoreHudTag($player, null);
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $player = $event->getPlayer();
        $this->savePlayerSettings($player);
        unset($this->playerFps[$player->getName()]);
        unset($this->playerSettings[$player->getName()]);
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if ($player === null) return;

        if ($packet instanceof ServerboundDiagnosticsPacket) {
            $fps = (int)$packet->getAvgFps();
            $this->playerFps[$player->getName()] = $fps;

            $settings = $this->getPlayerSettings($player);

            if ($settings['enabled']) {
                $this->displayFPS($player, $fps);
            }

            // Always update ScoreHUD tag when packet is received
            $this->updateScoreHudTag($player, $fps);
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if ($command->getName() === "fps") {
            if (!$sender instanceof Player) {
                $sender->sendMessage(TextFormat::RED . "This command can only be used in-game!");
                return true;
            }

            if (!$sender->hasPermission("fps.command.fps")) {
                $sender->sendMessage(TextFormat::RED . "You don't have permission to use this command!");
                return true;
            }

            $this->openSettingsForm($sender);
            return true;
        }
        return false;
    }

    private function displayFPS(Player $player, int $fps): void
    {
        $settings = $this->getPlayerSettings($player);
        $fpsColor = $this->getFpsColor($fps, $settings);
        $textColor = $settings['text_color'];

        $message = $textColor . "FPS: " . $fpsColor . $fps;

        switch ($settings['display_type']) {
            case 'popup':
                $player->sendPopup($message);
                break;
            case 'tip':
                $player->sendTip($message);
                break;
            case 'jukebox':
                $player->sendJukeboxPopup($message);
                break;
            case 'scoreboard':
                // For scoreboard mode, we only show via ScoreHUD
                // The ScoreHUD update happens in updateScoreHudTag method
                break;
        }
    }

    private function getFpsColor(int $fps, array $settings): string
    {
        if ($fps >= 60) {
            return $settings['colors']['high'];
        } elseif ($fps >= 30) {
            return $settings['colors']['medium'];
        } else {
            return $settings['colors']['low'];
        }
    }

    private function getPlayerSettings(Player $player): array
    {
        $name = $player->getName();

        if (!isset($this->playerSettings[$name])) {
            $this->loadPlayerSettings($player);
        }

        return $this->playerSettings[$name];
    }

    private function openSettingsForm(Player $player): void
    {
        $settings = $this->getPlayerSettings($player);
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) return;

            switch ($data) {
                case 0: // Toggle FPS
                    $this->toggleFPS($player);
                    break;
                case 1: // Display Settings
                    $this->openDisplaySettings($player);
                    break;
                case 2: // Color Settings
                    $this->openColorSettings($player);
                    break;
                case 3: // Help
                    $this->sendDiagnosticInstructions($player);
                    break;
            }
        });

        $form->setTitle(TextFormat::GOLD . "FPS Settings");

        $currentFps = $this->playerFps[$player->getName()] ?? null;
        $content = "";

        if ($currentFps !== null) {
            $content .= TextFormat::GREEN . "✓ Client Diagnostics: Enabled\n";
            $content .= TextFormat::WHITE . "Current FPS: " . $this->getFpsColor($currentFps, $settings) . $currentFps . "\n";
        } else {
            $content .= TextFormat::RED . "✗ Client Diagnostics: Disabled\n";
            $content .= TextFormat::YELLOW . "Enable it in: Settings → Creator Settings → Client Diagnostics\n";
        }

        $content .= "\nStatus: " . ($settings['enabled'] ? TextFormat::GREEN . "Enabled" : TextFormat::RED . "Disabled");
        $content .= "\nDisplay: " . TextFormat::AQUA . ucfirst($settings['display_type']);

        $form->setContent($content);

        $form->addButton(($settings['enabled'] ? TextFormat::RED . "Disable" : TextFormat::GREEN . "Enable") . " FPS Display");
        $form->addButton(TextFormat::AQUA . "Display Settings");
        $form->addButton(TextFormat::YELLOW . "Color Settings");
        $form->addButton(TextFormat::BLUE . "Setup Help");

        $player->sendForm($form);
    }

    private function updateScoreHudTag(Player $player, ?int $fps): void
    {
        if (!$this->scoreHudEnabled) return;

        $settings = $this->getPlayerSettings($player);

        // Check if we should show the FPS in scoreboard
        $shouldShow = $fps !== null &&
            $settings['enabled'] &&
            ($settings['display_type'] === 'scoreboard' || $settings['display_type'] === 'all');

        if ($shouldShow) {
            // Create formatted FPS string with colors
            $fpsColor = $this->getFpsColor($fps, $settings);
            $textColor = $settings['text_color'];
            $formattedFps = $textColor . "FPS: " . $fpsColor . $fps;

            $ev = new PlayerTagUpdateEvent(
                $player,
                new ScoreTag("fps.display", $formattedFps)
            );
        } else {
            // Hide the tag by setting it to empty or special hidden value
            $ev = new PlayerTagUpdateEvent(
                $player,
                new ScoreTag("fps.display", "")
            );
        }

        $ev->call();
    }

    private function loadPlayerSettings(Player $player): void
    {
        $name = $player->getName();
        $default = [
            'enabled' => false,
            'display_type' => 'popup',
            'text_color' => TextFormat::WHITE,
            'colors' => [
                'high' => TextFormat::GREEN,
                'medium' => TextFormat::YELLOW,
                'low' => TextFormat::RED
            ]
        ];

        $this->playerSettings[$name] = $this->playerData->get($name, $default);
    }

    private function savePlayerSettings(Player $player): void
    {
        $name = $player->getName();
        if (isset($this->playerSettings[$name])) {
            $this->playerData->set($name, $this->playerSettings[$name]);
        }
    }

    private function savePlayerData(): void
    {
        $this->playerData->save();
    }

    private function toggleFPS(Player $player): void
    {
        $name = $player->getName();
        $this->playerSettings[$name]['enabled'] = !$this->playerSettings[$name]['enabled'];

        $status = $this->playerSettings[$name]['enabled'] ? "enabled" : "disabled";
        $player->sendMessage(TextFormat::GREEN . "FPS display " . $status . "!");

        $this->savePlayerSettings($player);

        // Update ScoreHUD tag immediately when toggling
        $currentFps = $this->playerFps[$player->getName()] ?? null;
        $this->updateScoreHudTag($player, $currentFps);
    }

    private function openDisplaySettings(Player $player): void
    {
        $form = new CustomForm(function (Player $player, ?array $data) {
            if ($data === null) return;

            $types = ['popup', 'tip', 'jukebox', 'scoreboard'];
            $this->playerSettings[$player->getName()]['display_type'] = $types[$data[0]];

            $player->sendMessage(TextFormat::GREEN . "Display type updated to: " . $types[$data[0]]);
            $this->savePlayerSettings($player);

            // Update ScoreHUD tag when display type changes
            $currentFps = $this->playerFps[$player->getName()] ?? null;
            $this->updateScoreHudTag($player, $currentFps);
        });

        $form->setTitle(TextFormat::AQUA . "Display Settings");

        $settings = $this->getPlayerSettings($player);
        $types = ['popup', 'tip', 'jukebox', 'scoreboard'];
        $currentIndex = array_search($settings['display_type'], $types);

        $form->addDropdown("Display Type", [
            "Popup (Above hotbar)",
            "Tip (Above action bar)",
            "Jukebox (Center-top)",
            "Scoreboard (ScoreHUD required)"
        ], $currentIndex);

        $player->sendForm($form);
    }

    private function openColorSettings(Player $player): void
    {
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) return;

            switch ($data) {
                case 0:
                    $this->openTextColorSettings($player);
                    break;
                case 1:
                    $this->openFpsColorSettings($player);
                    break;
            }
        });

        $form->setTitle(TextFormat::YELLOW . "Color Settings");
        $form->setContent("Choose what colors to customize:");
        $form->addButton(TextFormat::WHITE . "Text Color\n" . TextFormat::GRAY . "(FPS: label color)");
        $form->addButton(TextFormat::GREEN . "FPS Number Colors\n" . TextFormat::GRAY . "(High/Medium/Low FPS)");

        $player->sendForm($form);
    }

    private function openTextColorSettings(Player $player): void
    {
        $colors = [
            'White' => TextFormat::WHITE,
            'Gray' => TextFormat::GRAY,
            'Black' => TextFormat::BLACK,
            'Dark Blue' => TextFormat::DARK_BLUE,
            'Dark Green' => TextFormat::DARK_GREEN,
            'Dark Aqua' => TextFormat::DARK_AQUA,
            'Dark Red' => TextFormat::DARK_RED,
            'Dark Purple' => TextFormat::DARK_PURPLE,
            'Gold' => TextFormat::GOLD,
            'Blue' => TextFormat::BLUE,
            'Green' => TextFormat::GREEN,
            'Aqua' => TextFormat::AQUA,
            'Red' => TextFormat::RED,
            'Light Purple' => TextFormat::LIGHT_PURPLE,
            'Yellow' => TextFormat::YELLOW
        ];

        $form = new SimpleForm(function (Player $player, ?int $data) use ($colors) {
            if ($data === null) return;

            $colorNames = array_keys($colors);
            $selectedColor = $colors[$colorNames[$data]];

            $this->playerSettings[$player->getName()]['text_color'] = $selectedColor;
            $player->sendMessage(TextFormat::GREEN . "Text color updated!");
            $this->savePlayerSettings($player);

            // Update ScoreHUD tag with new colors
            $currentFps = $this->playerFps[$player->getName()] ?? null;
            $this->updateScoreHudTag($player, $currentFps);
        });

        $form->setTitle("Select Text Color");
        foreach ($colors as $name => $code) {
            $form->addButton($code . $name);
        }

        $player->sendForm($form);
    }

    private function openFpsColorSettings(Player $player): void
    {
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) return;

            switch ($data) {
                case 0:
                    $this->openSpecificFpsColorSettings($player, 'high', 'High FPS (60+)');
                    break;
                case 1:
                    $this->openSpecificFpsColorSettings($player, 'medium', 'Medium FPS (30-59)');
                    break;
                case 2:
                    $this->openSpecificFpsColorSettings($player, 'low', 'Low FPS (<30)');
                    break;
            }
        });

        $form->setTitle("FPS Number Colors");
        $form->addButton(TextFormat::GREEN . "High FPS Color\n" . TextFormat::GRAY . "(60+ FPS)");
        $form->addButton(TextFormat::YELLOW . "Medium FPS Color\n" . TextFormat::GRAY . "(30-59 FPS)");
        $form->addButton(TextFormat::RED . "Low FPS Color\n" . TextFormat::GRAY . "(<30 FPS)");

        $player->sendForm($form);
    }

    private function openSpecificFpsColorSettings(Player $player, string $type, string $title): void
    {
        $colors = [
            'White' => TextFormat::WHITE,
            'Gray' => TextFormat::GRAY,
            'Dark Blue' => TextFormat::DARK_BLUE,
            'Dark Green' => TextFormat::DARK_GREEN,
            'Dark Aqua' => TextFormat::DARK_AQUA,
            'Dark Red' => TextFormat::DARK_RED,
            'Dark Purple' => TextFormat::DARK_PURPLE,
            'Gold' => TextFormat::GOLD,
            'Blue' => TextFormat::BLUE,
            'Green' => TextFormat::GREEN,
            'Aqua' => TextFormat::AQUA,
            'Red' => TextFormat::RED,
            'Light Purple' => TextFormat::LIGHT_PURPLE,
            'Yellow' => TextFormat::YELLOW
        ];

        $form = new SimpleForm(function (Player $player, ?int $data) use ($colors, $type) {
            if ($data === null) return;

            $colorNames = array_keys($colors);
            $selectedColor = $colors[$colorNames[$data]];

            $this->playerSettings[$player->getName()]['colors'][$type] = $selectedColor;
            $player->sendMessage(TextFormat::GREEN . ucfirst($type) . " FPS color updated!");
            $this->savePlayerSettings($player);

            // Update ScoreHUD tag with new colors
            $currentFps = $this->playerFps[$player->getName()] ?? null;
            $this->updateScoreHudTag($player, $currentFps);
        });

        $form->setTitle("Select " . $title . " Color");
        foreach ($colors as $name => $code) {
            $form->addButton($code . $name);
        }

        $player->sendForm($form);
    }

    private function sendDiagnosticInstructions(Player $player): void
    {
        $player->sendMessage(TextFormat::YELLOW . "=== FPS Setup ===");
        $player->sendMessage(TextFormat::AQUA . "To enable FPS display, follow these steps:");
        $player->sendMessage(TextFormat::WHITE . "1. Open Minecraft Settings");
        $player->sendMessage(TextFormat::WHITE . "2. Go to Creator Settings");
        $player->sendMessage(TextFormat::WHITE . "3. Turn 'Enable Client Diagnostics' on");
        $player->sendMessage(TextFormat::WHITE . "4. Use " . TextFormat::GREEN . "/fps" . TextFormat::WHITE . " to configure display");
        $player->sendMessage(TextFormat::YELLOW . "==================");
    }

    public function getFpsForPlayer(Player $player): int
    {
        return $this->playerFps[$player->getName()] ?? 0;
    }
}
