<?php

namespace Schwarzwaldgeier\WetterSocket;

use PHPUnit\Framework\TestCase;


class WetterSocketTest extends TestCase
{
    /**
     * @throws \Navarr\Socket\Exception\SocketException
     */
    public function testSaveCurrentState(){
        require_once ("../src/socket.php");
        $savegame = "/tmp/test";
        $oldSocket = new WetterSocket("127.0.0.1", 7950, true, $savegame);
        $records = [];
        $records[] = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV5.0,");
        $records[] = new Record("22:52:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD31.25, WC3.09, WV5.0,");

        $oldSocket->setRecords($records);


        if (is_file($savegame)) {
            unlink($savegame);
        }
        $oldSocket->saveCurrentState($savegame);
        self::assertFileExists($savegame);

        $newSocket = new WetterSocket("127.0.0.1", 7950, true);
        $newSocket->initFromSavedState($savegame);
        
        self::assertEquals($newSocket->getTimestampLastPlaybackFull(), $oldSocket->getTimestampLastPlaybackFull());
        self::assertEquals($newSocket->getTimestampLastPlaybackShort(), $oldSocket->getTimestampLastPlaybackShort());


        for ($i=0; $i<count($newSocket->getRecords()); $i++){
            self::assertEquals((string)$newSocket->getRecords()[$i], (string)$oldSocket->getRecords()[$i]);
        }
        
        

    }
    public function testDiscardOldSavedStates(){
        require_once ("../src/socket.php");
        $savegame = "/tmp/test";
        $json = <<<HEREDOC
{"records":["22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV5.0,","22:52:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD31.25, WC3.09, WV5.0,"],"time":1644555519,"last_full_playback":1644855519,"last_short_playback":1644855519}
HEREDOC;
        file_put_contents($savegame, $json);


        $newSocket = new WetterSocket("127.0.0.1", 7950, true, $savegame);
        self::assertEmpty($newSocket->getRecords());
    }
}
