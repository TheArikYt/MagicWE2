<?php

namespace xenialdan\MagicWE2\task;

use pocketmine\block\Block;
use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\UUID;
use xenialdan\MagicWE2\API;
use xenialdan\MagicWE2\clipboard\CopyClipboard;
use xenialdan\MagicWE2\helper\AsyncChunkManager;
use xenialdan\MagicWE2\Loader;
use xenialdan\MagicWE2\selection\Selection;
use xenialdan\MagicWE2\session\UserSession;

class AsyncCopyTask extends MWEAsyncTask
{

    private $start;
    private $chunks;
    private $selection;
    private $offset;
    private $flags;

    /**
     * AsyncCopyTask constructor.
     * @param Selection $selection
     * @param Vector3 $offset
     * @param UUID $sessionUUID
     * @param Chunk[] $chunks
     * @param int $flags
     * @throws \Exception
     */
    public function __construct(Selection $selection, Vector3 $offset, UUID $sessionUUID, array $chunks, int $flags)
    {
        $this->start = microtime(true);
        $this->chunks = serialize($chunks);
        $this->sessionUUID = $sessionUUID->toString();
        $this->selection = serialize($selection);
        $this->offset = serialize($offset);
        $this->flags = $flags;
    }

    /**
     * Actions to execute when run
     *
     * @return void
     * @throws \Exception
     */
    public function onRun()
    {
        $this->publishProgress([0, "Start"]);
        $chunks = unserialize($this->chunks);
        array_walk($chunks, function ($chunk) {
            return Chunk::fastDeserialize($chunk);
        });
        /** @var Selection $selection */
        $selection = unserialize($this->selection);
        $manager = Selection::getChunkManager($chunks);
        unset($chunks);
        $clipboard = new CopyClipboard($selection->levelid);
        $clipboard->setCenter(unserialize($this->offset));
        $clipboard->setAxisAlignedBB($selection->getAxisAlignedBB());
        $totalCount = $selection->getTotalCount();
        $copied = $this->copyBlocks($selection, $manager, $clipboard);
        $clipboard->chunks = $manager->getChunks();
        $this->setResult(compact("clipboard", "copied", "totalCount"));
    }

    /**
     * @param Selection $selection
     * @param AsyncChunkManager $manager
     * @param CopyClipboard $clipboard
     * @return int
     * @throws \Exception
     */
    private function copyBlocks(Selection $selection, AsyncChunkManager $manager, CopyClipboard &$clipboard): int
    {
        $blockCount = $selection->getTotalCount();
        $i = 0;
        $lastprogress = 0;
        $this->publishProgress([0, "Running, copied $i blocks out of $blockCount | 0% done"]);
        /** @var Block $block */
        foreach ($selection->getBlocks($manager, [], $this->flags) as $block) {
            $chunk = $clipboard->chunks[Level::chunkHash($block->x >> 4, $block->z >> 4)] ?? null;
            if ($chunk === null) {
                $chunk = $manager->getChunk($block->x >> 4, $block->z >> 4);
                $clipboard->chunks[Level::chunkHash($block->x >> 4, $block->z >> 4)] = $chunk;
            }
            $manager->setBlockAt($block->x, $block->y, $block->z, $block);
            $i++;
            $progress = floor($i / $blockCount * 100);
            if ($lastprogress < $progress) {//this prevents spamming packets
                $this->publishProgress([$progress, "Running, copied $i blocks out of $blockCount | " . $progress . "% done"]);
                $lastprogress = $progress;
            }
        }
        return $i;
    }

    public function onCompletion(Server $server)
    {
        $result = $this->getResult();
        $session = API::getSessions()[$this->sessionUUID];
        if ($session instanceof UserSession) $session->getBossBar()->hideFromAll();
        $copied = $result["copied"];
        /** @var CopyClipboard $clipboard */
        $clipboard = $result["clipboard"];
        $totalCount = $result["totalCount"];
        $session->sendMessage(Loader::PREFIX . TF::GREEN . "Async Copy succeed, took " . date("i:s:", microtime(true) - $this->start) . strval(round(microtime(true) - $this->start, 1, PHP_ROUND_HALF_DOWN)) . ", copied $copied blocks out of $totalCount.");
        $session->addClipboard($clipboard);
    }
}