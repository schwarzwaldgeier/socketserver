<?php

namespace Schwarzwaldgeier\WetterSocket;

use Navarr\Socket\Exception\SocketException;
use PHPUnit\Framework\TestCase;


class WetterSocketTest extends TestCase
{
    /**
     * @throws SocketException
     */
    public function testSaveCurrentState(){
        require_once(__DIR__ . "/../src/WetterSocket.php");
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

        $newSocket = new WetterSocket("127.0.0.1", 7950, true, $savegame);

        
        self::assertEquals($newSocket->getTimestampLastPlaybackFull(), $oldSocket->getTimestampLastPlaybackFull());
        self::assertEquals($newSocket->getTimestampLastPlaybackShort(), $oldSocket->getTimestampLastPlaybackShort());


        for ($i=0; $i<count($newSocket->getRecords()); $i++){
            self::assertEquals((string)$newSocket->getRecords()[$i], (string)$oldSocket->getRecords()[$i]);
        }
        
        

    }
    public function testDiscardOldSavedStates(){
        require_once(__DIR__ . "/../src/WetterSocket.php");
        $savegame = "/tmp/test";
        $json = <<<HEREDOC
{
  "records": [
    "20:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS0.64, WD200.00, WC-9.50, WV231.35,",
    "21:56:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,"
  ],
  "time": 1644870477,
  "last_full_playback": 1644870476,
  "last_short_playback": 1644870476
}

HEREDOC;
        file_put_contents($savegame, $json);


        $newSocket = new WetterSocket("127.0.0.1", 7950, true, $savegame);
        self::assertEmpty($newSocket->getRecords());
    }

    /**
     * @throws SocketException
     */
    public function testGetWindspeedAverage(){
        $savegame = $this->createSavefile();
        $socket = new WetterSocket("127.0.0.1", 7950, true, "$savegame");
        $this->assertEquals(40, $socket->getSpeedAverage());
    }

    public function testGetStrongestGust(){
        $savegame = $this->createSavefile();
        $socket = new WetterSocket("127.0.0.1", 7950, true, "$savegame");

        $strongest = $socket->getStrongestGust();
        $windspeedMax = $strongest->windspeedMax;
        $this->assertEquals(46, $windspeedMax);
    }

    public function testGetAverageDirection(){
        $savegame = $this->createSavefile();
        $socket = new WetterSocket("127.0.0.1", 7950, true, "$savegame");

        $avg = $socket->getDirectionAverage();

        $this->assertEquals(246, $avg);
    }

    private function createSavefile(): string
    {
        require_once(__DIR__ . "/../src/WetterSocket.php");
        $savegame = "/tmp/test";
        if (is_file($savegame)) {
            unlink($savegame);
        }

        $time = time();
        $state = <<<HEREDOC
{
  "records": [
    "20:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS0.64, WD200.00, WC-9.50, WV30.35,",
    "20:56:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS70.64, WD39.98, WC-9.50, WV20.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,",
    "21:55:00, 01.01.00, TE4.22, DR926.73, FE95.63, WS45.64, WD39.98, WC-9.50, WV231.35,"
  ],
  "time": $time,
  "last_full_playback": 1644870476,
  "last_short_playback": 1644870476
}
HEREDOC;
        file_put_contents($savegame, $state);
        return $savegame;
    }
}
