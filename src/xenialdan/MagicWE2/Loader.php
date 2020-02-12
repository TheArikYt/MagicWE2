<?php

declare(strict_types=1);

namespace xenialdan\MagicWE2;

use muqsit\invmenu\InvMenuHandler;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\lang\BaseLang;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use pocketmine\utils\TextFormat as TF;
use RuntimeException;
use xenialdan\MagicWE2\commands\biome\BiomeInfoCommand;
use xenialdan\MagicWE2\commands\biome\BiomeListCommand;
use xenialdan\MagicWE2\commands\biome\SetBiomeCommand;
use xenialdan\MagicWE2\commands\brush\BrushCommand;
use xenialdan\MagicWE2\commands\clipboard\ClearClipboardCommand;
use xenialdan\MagicWE2\commands\clipboard\CopyCommand;
use xenialdan\MagicWE2\commands\clipboard\Cut2Command;
use xenialdan\MagicWE2\commands\clipboard\CutCommand;
use xenialdan\MagicWE2\commands\clipboard\PasteCommand;
use xenialdan\MagicWE2\commands\DonateCommand;
use xenialdan\MagicWE2\commands\generation\CylinderCommand;
use xenialdan\MagicWE2\commands\HelpCommand;
use xenialdan\MagicWE2\commands\history\ClearhistoryCommand;
use xenialdan\MagicWE2\commands\history\RedoCommand;
use xenialdan\MagicWE2\commands\history\UndoCommand;
use xenialdan\MagicWE2\commands\InfoCommand;
use xenialdan\MagicWE2\commands\LanguageCommand;
use xenialdan\MagicWE2\commands\LimitCommand;
use xenialdan\MagicWE2\commands\region\ReplaceCommand;
use xenialdan\MagicWE2\commands\region\SetCommand;
use xenialdan\MagicWE2\commands\ReportCommand;
use xenialdan\MagicWE2\commands\selection\ChunkCommand;
use xenialdan\MagicWE2\commands\selection\HPos1Command;
use xenialdan\MagicWE2\commands\selection\HPos2Command;
use xenialdan\MagicWE2\commands\selection\info\CountCommand;
use xenialdan\MagicWE2\commands\selection\info\ListChunksCommand;
use xenialdan\MagicWE2\commands\selection\info\SizeCommand;
use xenialdan\MagicWE2\commands\selection\Pos1Command;
use xenialdan\MagicWE2\commands\selection\Pos2Command;
use xenialdan\MagicWE2\commands\SetRangeCommand;
use xenialdan\MagicWE2\commands\TestCommand;
use xenialdan\MagicWE2\commands\tool\DebugCommand;
use xenialdan\MagicWE2\commands\tool\FloodCommand;
use xenialdan\MagicWE2\commands\tool\ToggledebugCommand;
use xenialdan\MagicWE2\commands\tool\TogglewandCommand;
use xenialdan\MagicWE2\commands\tool\WandCommand;
use xenialdan\MagicWE2\commands\utility\CalculateCommand;
use xenialdan\MagicWE2\commands\VersionCommand;
use xenialdan\MagicWE2\exception\ActionRegistryException;
use xenialdan\MagicWE2\exception\ShapeRegistryException;
use xenialdan\MagicWE2\helper\SessionHelper;
use xenialdan\MagicWE2\selection\shape\ShapeRegistry;
use xenialdan\MagicWE2\task\action\ActionRegistry;
use const pocketmine\RESOURCE_PATH;

class Loader extends PluginBase
{
    const FAKE_ENCH_ID = 201;
    const PREFIX = TF::BOLD . TF::GOLD . "[MagicWE2]" . TF::RESET . " ";
    /** @var Loader */
    private static $instance = null;
    /** @var null|ShapeRegistry */
    public static $shapeRegistry = null;
    /** @var null|ActionRegistry */
    public static $actionRegistry = null;
    /** @var BaseLang */
    private $baseLang;
    /** @var string[] Donator names */
    public $donators = [];
    /** @var string */
    public $donatorData = "";

    /**
     * Returns an instance of the plugin
     * @return Loader
     */
    public static function getInstance(): Loader
    {
        return self::$instance;
    }

    /**
     * ShapeRegistry
     * @return ShapeRegistry
     * @throws ShapeRegistryException
     */
    public static function getShapeRegistry(): ShapeRegistry
    {
        if (self::$shapeRegistry) return self::$shapeRegistry;
        throw new ShapeRegistryException("Shape registry is not initialized");
    }

    public function onLoad(): void
    {
        self::$instance = $this;
        $ench = new Enchantment(self::FAKE_ENCH_ID, "", 0, Enchantment::SLOT_ALL, Enchantment::SLOT_NONE, 1);
        Enchantment::registerEnchantment($ench);
        self::$shapeRegistry = new ShapeRegistry();
        self::$actionRegistry = new ActionRegistry();
        SessionHelper::init();

        self::initBlockstates();
    }

    private static function initBlockstates(): void
    {
        $STATES_NBT = file_get_contents(RESOURCE_PATH . '/vanilla/r12_to_current_block_map.nbt');
        $stream = new NetworkLittleEndianNBTStream();
        /** @var ListTag $rootListTag */
        $rootListTag = $stream->read($STATES_NBT);
        //Load default states
        $defaultStates = new CompoundTag("defaultStates");
        foreach ($rootListTag->getAllValues() as $rootCompound) {
            /** @var CompoundTag $rootCompound */
            $oldCompound = $rootCompound->getCompoundTag("old");
            $newCompound = $rootCompound->getCompoundTag("new");
            $states = clone $newCompound->getCompoundTag("states");
            if ($oldCompound->getShort("val") === 0) {
                $states->setName($oldCompound->getString("name"));
                $defaultStates->setTag($states);
            }
        }
        //write out all blocknames with states (without default states)
        foreach ($rootListTag->getAllValues() as $rootCompound) {
            /** @var CompoundTag $rootCompound */
            $oldCompound = $rootCompound->getCompoundTag("old");
            $newCompound = $rootCompound->getCompoundTag("new");
            $currentoldName = $oldCompound->getString("name");
            if (in_array($currentoldName, [
                    "minecraft:wood",
                    "minecraft:wooden_slab",
                ]) && in_array($oldCompound->getShort("val"), [
                    12,
                    0,
                    6,
                    7,
                ]))
                self::printStates($newCompound->getTag("states"), $defaultStates, $currentoldName, false);//disable printing for now
        }
        //testing cases
        $tests = [
            #"minecraft:tnt",
            "minecraft:wood",
            #"minecraft:log",
            "minecraft:wooden_slab",
            #"minecraft:wooden_slab_wrongname",
            #"minecraft:wooden_slab[foo=bar]",
            #"minecraft:wooden_slab[top_slot_bit=]",
            "minecraft:wooden_slab[top_slot_bit=true]",
            "minecraft:wooden_slab[top_slot_bit=false]",
            "minecraft:wooden_slab[wood_type=oak]",
            #"minecraft:wooden_slab[wood_type=spruce]",
            #"minecraft:wooden_slab[wood_type=spruce,top_slot_bit=false]",
            #"minecraft:wooden_slab[wood_type=spruce,top_slot_bit=true]",
            #"minecraft:end_rod[]",
            #"minecraft:end_rod[facing_direction=1]",
            #"minecraft:end_rod[block_light_level=14]",
            #"minecraft:end_rod[block_light_level=13]",
            #"minecraft:light_block[block_light_level=14]",
            "minecraft:stone[]",
            "minecraft:stone[stone_type=granite]",
            "minecraft:stone[stone_type=andesite]",
            "minecraft:stone[stone_type=wrongtag]",//seems to just not find a block at all. neat!
        ];
        foreach ($tests as $test) {
            MainLogger::getLogger()->debug(TF::GOLD . "Search query: " . TF::LIGHT_PURPLE . $test);
            $blockData = strtolower(str_replace("minecraft:", "", $test ?? "air"));
            $re = '/([\w:]+)(?:\[([\w=,]*)\])?/m';
            preg_match_all($re, $blockData, $matches, PREG_SET_ORDER, 0);
            $selectedBlockName = "minecraft:" . ($matches[0][1] ?? "air");
            $defaultStatesNamedTag = $defaultStates->getTag($selectedBlockName);
            if (!$defaultStatesNamedTag instanceof CompoundTag) {
                var_dump("Not found: $selectedBlockName");
                continue;
            }
            $extraData = $matches[0][2] ?? "";
            $explode = explode(",", $extraData);
            #$finalStatesList = new CompoundTag("states");
            $finalStatesList = clone $defaultStatesNamedTag;
            $finalStatesList->setName("states");
            foreach ($explode as $boom) {
                if (strpos($boom, "=") === false) continue;
                [$k, $v] = explode("=", $boom);
                $v = strtolower(trim($v));
                if (empty($v)) {
                    var_dump("Empty value for state $k");
                    continue 2;
                }
                //TODO add state alias here
                #$tag = $defaultStatesNamedTag->getTag($k);
                $tag = $finalStatesList->getTag($k);
                if ($tag === null) {
                    var_dump("Invalid state $k");
                    continue 2;
                }
                if ($tag instanceof StringTag) {
                    $finalStatesList->setString($tag->getName(), $v);
                } else if ($tag instanceof IntTag) {
                    $finalStatesList->setInt($tag->getName(), intval($v));
                } else if ($tag instanceof ByteTag) {
                    if ($v !== "true" && $v !== "false") {
                        var_dump("Invalid value $v for state $k");
                        continue 2;
                    }
                    $val = ($v === "true" ? 1 : 0);
                    $finalStatesList->setByte($tag->getName(), $val);
                } else {//never happens
                    continue 2;
                }
            }
            //print final list
            self::printStates($finalStatesList, $defaultStates, $selectedBlockName, false);
            //print found block(s)
            $blocks = [];
            foreach ($rootListTag->getAllValues() as $rootCompound) {
                /** @var CompoundTag $rootCompound */
                $oldCompound = $rootCompound->getCompoundTag("old");
                $newCompound = $rootCompound->getCompoundTag("new");
                $states = $newCompound->getCompoundTag("states");
                if (($oldCompound->getString("name") === $selectedBlockName || $newCompound->getString("name") === $selectedBlockName) && $states->equals($finalStatesList)) {
                    $block = Item::fromString($selectedBlockName . ":" . $oldCompound->getShort("val"))->getBlock();
                    $blocks[] = $block;
                    MainLogger::getLogger()->debug(TF::GREEN . "Found block: " . TF::GOLD . $block);
                }
            }
            if (empty($blocks)) continue;//no block found
            //"Hack" to get just one block if multiple results have been found. Most times this results in the default one (meta:0)
            $smallestMeta = PHP_INT_MAX;
            $result = null;
            foreach ($blocks as $block) {
                if ($block->getDamage() < $smallestMeta) {
                    $smallestMeta = $block->getDamage();
                    $result = $block;
                }
            }
            MainLogger::getLogger()->debug(TF::LIGHT_PURPLE . "Final block: " . TF::AQUA . $result);
        }
    }

    /**
     * @param CompoundTag $printedCompound
     * @param CompoundTag $defaultStates
     * @param string $blockIdentifier
     * @param bool $skipDefaults
     * @return void
     * @throws RuntimeException
     */
    private static function printStates(CompoundTag $printedCompound, CompoundTag $defaultStates, string $blockIdentifier, bool $skipDefaults): void
    {
        $s = $failed = [];
        foreach ($printedCompound as $statesTagEntry) {
            $defaultStatesNamedTag = $defaultStates->getTag($blockIdentifier);
            /** @var ByteTag|IntTag|StringTag $namedTag */
            $namedTag = $defaultStatesNamedTag->getTag($statesTagEntry->getName());
            if ($namedTag === null) {
                continue;
            }
            //skip defaults
            if ($skipDefaults && $namedTag->getValue() === $statesTagEntry->getValue()) continue;
            //prepare string
            if ($statesTagEntry instanceof ByteTag) {
                $s[] = TF::RED . $statesTagEntry->getName() . "=" . ($statesTagEntry->getValue() ? TF::GREEN . "true" : TF::RED . "false") . TF::RESET;
            } else if ($statesTagEntry instanceof IntTag) {
                $s[] = TF::BLUE . $statesTagEntry->getName() . "=" . TF::BLUE . strval($statesTagEntry->getValue()) . TF::RESET;
            } else if ($statesTagEntry instanceof StringTag) {
                $s[] = TF::LIGHT_PURPLE . $statesTagEntry->getName() . "=" . TF::LIGHT_PURPLE . strval($statesTagEntry->getValue()) . TF::RESET;
            }
        }
        if (count($s) === 0) {
            MainLogger::getLogger()->debug($blockIdentifier);
        } else {
            MainLogger::getLogger()->debug($blockIdentifier . "[" . implode(",", $s) . "]");
        }
    }

    /**
     * ActionRegistry
     * @return ActionRegistry
     * @throws ActionRegistryException
     */
    public static function getActionRegistry(): ActionRegistry
    {
        if (self::$actionRegistry) return self::$actionRegistry;
        throw new ActionRegistryException("Action registry is not initialized");
    }

    /**
     * @throws PluginException
     */
    public function onEnable(): void
    {
        $lang = $this->getConfig()->get("language", BaseLang::FALLBACK_LANGUAGE);
        $this->baseLang = new BaseLang((string)$lang, $this->getFile() . "resources" . DIRECTORY_SEPARATOR . "lang" . DIRECTORY_SEPARATOR);
        if ($this->getConfig()->get("show-startup-icon", false)) $this->showStartupIcon();
        //$this->loadDonator();
        $this->getLogger()->warning("WARNING! Commands and their permissions changed! Make sure to update your permission sets!");
        if (!InvMenuHandler::isRegistered()) InvMenuHandler::register($this);
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        $this->getServer()->getCommandMap()->registerAll("MagicWE2", [
            /* -- selection -- */
            new Pos1Command("/pos1", "Set position 1", ["/1"]),
            new Pos2Command("/pos2", "Set position 2", ["/2"]),
            new HPos1Command("/hpos1", "Set position 1 to targeted block", ["/h1"]),
            new HPos2Command("/hpos2", "Set position 2 to targeted block", ["/h2"]),
            new ChunkCommand("/chunk", "Set the selection to your current chunk"),
            /* -- tool -- */
            new WandCommand("/wand", "Gives you the selection wand"),
            new TogglewandCommand("/togglewand", "Toggle the wand tool on/off", ["/toggleeditwand"]),
            new DebugCommand("/debug", "Gives you the debug stick, which gives information about the clicked block"),
            new ToggledebugCommand("/toggledebug", "Toggle the debug stick on/off"),
            /* -- selection modify -- */
            //new ContractCommand("/contract", "Contract the selection area"),
            //new ShiftCommand("/shift", "Shift the selection area"),
            //new OutsetCommand("/outset", "Outset the selection area"),
            //new InsetCommand("/inset", "Inset the selection area"),
            /* -- selection info -- */
            new SizeCommand("/size", "Get information about the selection"),
            new CountCommand("/count", "Counts the number of blocks matching a mask in selection", ["/analyze"]),
            new ListChunksCommand("/listchunks", "List chunks that your selection includes"),
            /* -- region -- */
            new SetCommand("/set", "Fill a selection with the specified blocks"),
            //new LineCommand("/line", "Draws a line segment between cuboid selection corners"),
            new ReplaceCommand("/replace", "Replace blocks in an area with other blocks"),
            #new OverlayCommand("/overlay", "Set a block on top of blocks in the region", ["/cover"]),
            //new CenterCommand("/center", "Set the center block(s)",["/middle"]),
            //new NaturalizeCommand("/naturalize", "3 layers of dirt on top then rock below"),
            //new WallsCommand("/walls", "Build the four sides of the selection"),
            //new FacesCommand("/faces", "Build the walls, ceiling, and floor of a selection"),
            //new MoveCommand("/move", "Move the contents of the selection"),
            //new StackCommand("/stack", "Repeat the contents of the selection"),
            //new HollowCommand("/hollow", "Hollows out the object contained in this selection"),
            /* -- cosmetic -- */
            //new ForestCommand("/forest", "Make a forest within the region"),
            //new FloraCommand("/flora", "Make flora within the region"),
            /* -- generation -- */
            new CylinderCommand("/cylinder", "Create a cylinder", ["/cyl"]),
            //new HollowCylinderCommand("/hcyl", "Generates a hollow cylinder"),
            //new SphereCommand("/sphere", "Generates a filled sphere"),
            //new HollowSphereCommand("/hsphere", "Generates a hollow sphere"),
            //new PyramidCommand("/pyramid", "Generates a filled pyramid"),
            //new HollowPyramidCommand("/hpyramid", "Generates a hollow pyramid"),
            //new PumpkinsCommand("/pumpkins", "Generate pumpkin patches"),
            /* -- clipboard -- */
            new CopyCommand("/copy", "Copy the selection to the clipboard"),
            new PasteCommand("/paste", "Paste the clipboard’s contents"),
            new CutCommand("/cut", "Cut the selection to the clipboard"),
            new Cut2Command("/cut2", "Cut the selection to the clipboard - the new way"),
            new ClearClipboardCommand("/clearclipboard", "Clear your clipboard"),
            //new FlipCommand("/flip","Flip the contents of the clipboard across the origin"),
            //new RotateCommand("/rotate","Rotate the contents of the clipboard around the origin"),
            /* -- history -- */
            new UndoCommand("/undo", "Rolls back the last action"),
            new RedoCommand("/redo", "Applies the last undo action again"),
            new ClearhistoryCommand("/clearhistory", "Clear your history"),
            /* -- schematic -- */
            //new SchematicCommand("/schematic", "Schematic commands for saving/loading areas"),
            /* -- navigation -- */
            //new UnstuckCommand("/unstuck", "Switch between your position and pos1 for placement"),
            //new AscendCommand("/ascend", "Switch between your position and pos1 for placement", ["/asc"]),
            //new DescendCommand("/descend", "Switch between your position and pos1 for placement", ["/desc"]),
            //new CeilCommand("/ceil", "Switch between your position and pos1 for placement"),
            //new ThruCommand("/thru", "Switch between your position and pos1 for placement"),
            //new UpCommand("/up", "Switch between your position and pos1 for placement"),
            /* -- generic -- */
            //new TogglePlaceCommand("/toggleplace", "Switch between your position and pos1 for placement"),
            //new SearchItemCommand("/searchitem", "Search for an item"),
            //new RangeCommand("/range", "Set the brush range"),
            new TestCommand("/test", "test action"),//TODO REMOVE
            new SetRangeCommand("/setrange", "Set tool range", ["/toolrange"]),
            new LimitCommand("/limit", "Set the block change limit. Use -1 to disable"),
            new HelpCommand("/help", "MagicWE help command", ["/?", "/mwe", "/wehelp"]),//Blame MCPE for client side /help shit! only the aliases work
            new VersionCommand("/version", "MagicWE version", ["/ver"]),
            new InfoCommand("/info", "Information about MagicWE"),
            new ReportCommand("/report", "Report a bug to GitHub", ["/bug", "/github"]),
            new DonateCommand("/donate", "Donate to support development of MagicWE!", ["/support", "/paypal"]),
            new LanguageCommand("/language", "Set your language", ["/lang"]),
            new DonateCommand("/donate", "Support the development of MagicWE and get a cape!", ["/support", "/paypal"]),
            /* -- biome -- */
            new BiomeListCommand("/biomelist", "Gets all biomes available", ["/biomels"]),
            new BiomeInfoCommand("/biomeinfo", "Get the biome of the targeted block"),
            new SetBiomeCommand("/setbiome", "Sets the biome of your current block or region"),
            /* -- utility -- */
            //new DrainCommand("/drain", "Drain a pool"),
            //new FixLavaCommand("/fixlava", "Fix lava to be stationary"),
            //new FixWaterCommand("/fixwater", "Fix water to be stationary"),
            //new SnowCommand("/snow", "Creates a snow layer cover in the selection"),
            //new ThawCommand("/thaw", "Thaws blocks in the selection"),
            new CalculateCommand("/calculate", "Evaluate a mathematical expression", ["/calc", "/eval", "/evaluate", "/solve"]),
        ]);
        if (class_exists("xenialdan\\customui\\API")) {
            $this->getLogger()->notice("CustomUI found, can use ui-based commands");
            $this->getServer()->getCommandMap()->registerAll("MagicWE2", [
                /* -- brush -- */
                new BrushCommand("/brush", "Opens the brush tool menu"),
                /* -- tool -- */
                new FloodCommand("/flood", "Opens the flood fill tool menu", ["/floodfill"]),
            ]);
        } else {
            $this->getLogger()->notice(TF::RED . "CustomUI NOT found, can NOT use ui-based commands");
        }
    }

    public function onDisable(): void
    {
        #$this->getLogger()->debug("Destroying Sessions");
        foreach (SessionHelper::getPluginSessions() as $session) {
            SessionHelper::destroySession($session, false);
        }
        foreach (SessionHelper::getUserSessions() as $session) {
            SessionHelper::destroySession($session);
        }
        #$this->getLogger()->debug("Sessions successfully destroyed");
    }

    /**
     * @return BaseLang
     * @api
     */
    public function getLanguage(): BaseLang
    {
        return $this->baseLang;
    }

    public function getToolDistance(): int
    {
        return intval($this->getConfig()->get("tool-range", 100));
    }

    /**
     * @return array
     * @throws RuntimeException
     */
    public static function getInfo(): array
    {
        return [
            "| " . TF::GREEN . Loader::getInstance()->getFullName() . TF::RESET . " | Information |",
            "| --- | --- |",
            "| Website | " . Loader::getInstance()->getDescription()->getWebsite() . " |",
            "| Version | " . Loader::getInstance()->getDescription()->getVersion() . " |",
            "| Plugin API Version | " . implode(", ", Loader::getInstance()->getDescription()->getCompatibleApis()) . " |",
            "| Authors | " . implode(", ", Loader::getInstance()->getDescription()->getAuthors()) . " |",
            "| Enabled | " . (Server::getInstance()->getPluginManager()->isPluginEnabled(Loader::getInstance()) ? TF::GREEN . "Yes" : TF::RED . "No") . TF::RESET . " |",
            "| Uses UI | " . (class_exists("xenialdan\\customui\\API") ? TF::GREEN . "Yes" : TF::RED . "No") . TF::RESET . " |",
            "| Phar | " . (Loader::getInstance()->isPhar() ? TF::GREEN . "Yes" : TF::RED . "No") . TF::RESET . " |",
            "| PMMP Protocol Version | " . Server::getInstance()->getVersion() . " |",
            "| PMMP Version | " . Server::getInstance()->getPocketMineVersion() . " |",
            "| PMMP API Version | " . Server::getInstance()->getApiVersion() . " |",
        ];
    }

    private function showStartupIcon(): void
    {
        $colorAxe = TF::BOLD . TF::DARK_PURPLE;
        $colorAxeStem = TF::LIGHT_PURPLE;
        $colorAxeSky = TF::LIGHT_PURPLE;
        $colorAxeFill = TF::GOLD;
        $axe = [
            "              {$colorAxe}####{$colorAxeSky}      ",
            "            {$colorAxe}##{$colorAxeFill}####{$colorAxe}##{$colorAxeSky}    ",
            "          {$colorAxe}##{$colorAxeFill}######{$colorAxe}##{$colorAxeSky}    ",
            "        {$colorAxe}##{$colorAxeFill}########{$colorAxe}####{$colorAxeSky}  ",
            "        {$colorAxe}##{$colorAxeFill}######{$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeSky}  ",
            "          {$colorAxe}######{$colorAxeStem}##{$colorAxe}##{$colorAxeFill}##{$colorAxe}##",
            "            {$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeFill}####{$colorAxe}##",
            "          {$colorAxe}##{$colorAxeStem}##{$colorAxe}##  {$colorAxe}####{$colorAxeSky}  ",
            "        {$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeSky}          ",
            "      {$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeSky}            ",
            "    {$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeSky}              ",
            "  {$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeSky}                ",
            "{$colorAxe}##{$colorAxeStem}##{$colorAxe}##{$colorAxeSky}       MagicWE v.2",
            "{$colorAxe}####{$colorAxeSky}        by XenialDan"];
        foreach (array_map(function ($line) {
            return preg_replace_callback(
                '/ +(?<![#§l5d6]] )(?= [#§l5d6]+)|(?<=[#§l5d6] ) +(?=\s)/',
                #'/ +(?<!# )(?= #+)|(?<=# ) +(?=\s)/',
                function ($v) {
                    return substr(str_shuffle(str_pad('+*~', strlen($v[0]))), 0, strlen($v[0]));
                },
                TF::LIGHT_PURPLE . $line
            );
        }, $axe) as $axeMsg)
            $this->getLogger()->info($axeMsg);
    }

    /**
     * Returns the path to the language files folder.
     *
     * @return string
     */
    public function getLanguageFolder(): string
    {
        return $this->getFile() . "resources" . DIRECTORY_SEPARATOR . "lang" . DIRECTORY_SEPARATOR;
    }

    /**
     * Get a list of available languages
     * @return array
     */
    public function getLanguageList(): array
    {
        return BaseLang::getLanguageList($this->getLanguageFolder());
    }
}