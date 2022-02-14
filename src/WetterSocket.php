<?php

namespace Schwarzwaldgeier\WetterSocket;

use Exception;
use JetBrains\PhpStorm\NoReturn;
use Navarr\Socket\Exception\SocketException;
use Navarr\Socket\Socket;
use Navarr\Socket\Server;


use function atan2;
use function cos;
use function deg2rad;
use function rad2deg;
use function sin;

$dir = dirname(__FILE__);

require_once "$dir/../vendor/autoload.php";
require_once "Record.php";

class WetterSocket extends Server
{
    const MAX_RECORD_AGE_FOR_AVERAGING = 20;
    private bool $debug;
    const DEFAULT_PORT = 7977;

    protected string $soundDir;
    protected array $records = [];
    private string $savedStateFile;
    private bool $alreadySaved = false;
    private int $newestRecordTimestamp = 0;

    /**
     * @return array
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @param array $records
     */
    public function setRecords(array $records): void
    {
        $this->records = $records;
    }

    private array $buffer = [];
    private int $timestampLastPlaybackShort = 0;

    /**
     * @return int
     */
    public function getTimestampLastPlaybackShort(): int
    {
        return $this->timestampLastPlaybackShort;
    }

    /**
     * @return int
     */
    public function getTimestampLastPlaybackFull(): int
    {
        return $this->timestampLastPlaybackFull;
    }

    //private int $intervalShortAnnouncement = 1;
    private int $intervalShortAnnouncement = 5 * 60;

    private int $timestampLastPlaybackFull = 0;
    //private int $intervalFullAnnouncement = 60;
    private int $intervalFullAnnouncement = 60 * 60;


    /**
     * @return float
     */
    public function getSpeedAverage(): float
    {
        $sum = 0.0;
        $num = 0;

        foreach ($this->records as $r) {
            if (!$r instanceof Record) {
                continue;
            }

            $ageDiff = abs($r->getAgeDiff($this->newestRecordTimestamp));

            if ($ageDiff > (self::MAX_RECORD_AGE_FOR_AVERAGING * 60)) {
                continue;
            }

            if (isset($r->windspeed)) {
                $sum += $r->windspeed;
                $num++;
            }
        }
        if ($num === 0) {
            return 0;
        }
        return $sum / $num;
    }

    public function getStrongestGust(): ?Record
    {
        $strongest = null;
        foreach ($this->records as $r) {

            if (!($r instanceof Record)) {
                continue;
            }

            $ageDiff = abs($r->getAgeDiff($this->newestRecordTimestamp));

            if ($ageDiff > (self::MAX_RECORD_AGE_FOR_AVERAGING * 60)) {
                continue;
            }

            if ($strongest === null) {
                $strongest = $r;
            }

            if ($r->windspeedMax >= $strongest->windspeedMax) {
                $strongest = $r;
            }
        }

        return $strongest;
    }

    public function getDirectionAverage(): int
    {
        $dirs = [];
        foreach ($this->records as $r) {
            $ageDiff = abs($r->getAgeDiff($this->newestRecordTimestamp));

            if ($ageDiff > (self::MAX_RECORD_AGE_FOR_AVERAGING * 60)) {
                continue;
            }

            if (isset($r->winddirection)) {
                $dirs[] = $r->winddirection;
            }
        }

        if (empty($dirs)) {
            return -1;
        }

        $sinSum = 0;
        $cosSum = 0;
        foreach ($dirs as $value) {
            $sinSum += sin(deg2rad($value));
            $cosSum += cos(deg2rad($value));
        }

        return (((int)rad2deg((int)atan2((int)$sinSum, (int)$cosSum)) + 360) % 360);
    }


    public function __construct($ip = null, $port = self::DEFAULT_PORT, $debug = false, $savedStateFile = "/tmp/wetter_socket_state.json")
    {
        parent::__construct($ip, $port);
        $this->debug = $debug;
        $this->addHook(Server::HOOK_CONNECT, array($this, 'onConnect'));
        $this->addHook(Server::HOOK_INPUT, array($this, 'onInput'));
        $this->addHook(Server::HOOK_DISCONNECT, array($this, 'onDisconnect'));

        $dir = dirname(__FILE__);
        $this->soundDir = "$dir/../sound";

        $this->savedStateFile = $savedStateFile;


        $signals = [SIGHUP, SIGINT, SIGTERM, SIGABRT];
        foreach ($signals as $signo) {

            pcntl_signal($signo, [$this, "handleTerminations"]);

        }

        register_shutdown_function([$this, 'saveCurrentState']);
        register_shutdown_function([$this, 'disconnectClients']);


        $loaded = false;
        if (is_file($this->savedStateFile)) {
            echo "Loading saved state" . PHP_EOL;
            $loaded = $this->initFromSavedState($this->savedStateFile);
        }
        if (!$loaded) {
            $this->timestampLastPlaybackFull = time();
            $this->timestampLastPlaybackShort = time();
        }

    }

    #[NoReturn] public function handleTerminations(int $signo, mixed $signinfo)
    {
        echo "Got signal $signo" . PHP_EOL;
        if (is_array($signinfo)) {
            foreach ($signinfo as $key => $value) {
                echo "\t$key: $value" . PHP_EOL;
            }
        }

        $this->saveCurrentState();
        $this->disconnectClients();

        die();
    }


    /** @noinspection PhpUnusedParameterInspection */
    public function onConnect(Server $server, Socket $client, $message)
    {
        echo 'Connection Established', "\n";
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onInput(Server $server, Socket $client, $message)
    {
        $messages = explode("\n", $message);

        foreach ($messages as $message) {
            $this->buffer[] = $message;
            $bufSize = count($this->buffer);
            if ($bufSize > 2) {
                array_shift($this->buffer);
            }
            $bufSize = count($this->buffer);
            if ($bufSize > 2) { //should never happen but whatever
                $this->buffer = [
                    $this->buffer[$bufSize - 1],
                    $this->buffer[$bufSize - 2],
                ];
            }

            //check if buffer is at the right state, i.e. timestamp is in first message, rest in  second
            $partsFirst = explode(",", $this->buffer[0]);
            if (!isset($this->buffer[1])) {
                continue;
            }
            $partsSecond = explode(",", $this->buffer[1]);
            if (!isset($partsFirst[0])) {
                continue;
            }
            if (!isset($partsSecond[0])) {
                continue;
            }

            $partsFirst[0] = trim($partsFirst[0]);

            if (!preg_match("/^\d{2}:\d{2}:\d{2}$/", $partsFirst[0])) {
                continue;
            }


            $compiledMessage = $this->buffer[0] . $this->buffer[1];
            echo date("d.m.Y H:i:s") . PHP_EOL;
            echo "Received: $compiledMessage" . PHP_EOL . PHP_EOL;
            try {
                $client->write($compiledMessage, strlen($compiledMessage));
            } catch (SocketException $e) {
                echo $e->getMessage();
            }


            $record = new Record($compiledMessage);
            if (!$record->isValid()) {
                continue;
            }

            $this->newestRecordTimestamp = $record->secondsSinceStartup;

            echo $record . PHP_EOL;
            $this->sendToGeier($record);


            $this->records[] = $record;
            $recordBufferSize = self::MAX_RECORD_AGE_FOR_AVERAGING;


            while (count($this->records) > $recordBufferSize) {
                array_shift($this->records);
            }


            $count = count($this->records);
            echo "$count of $recordBufferSize records in buffer" . PHP_EOL;


            $dirAvg = $this->getDirectionAverage();
            if ($dirAvg === -1) {
                $directionAverage = "";
            } else {
                $directionAverage = Record::getWindDirectionNicename($dirAvg);
            }

            $speedAverage = $this->getSpeedAverage();

            $recordWithStrongestGust = $this->getStrongestGust();
            if ($recordWithStrongestGust instanceof Record) {
                $strongestGustSpeed = $recordWithStrongestGust->windspeedMax;
                if (isset($recordWithStrongestGust->winddirection)) {
                    $strongestGustNiceDirection = Record::getWindDirectionNicename($recordWithStrongestGust->winddirection);
                } else {
                    $strongestGustNiceDirection = ""; //nullwind
                }
            } else {
                $strongestGustSpeed = 0;
                $strongestGustNiceDirection = "";
            }
            $now = time();

            $timeSinceLastFullPlayback = $now - $this->timestampLastPlaybackFull;
            $timeSinceLastShortPlayback = $now - $this->timestampLastPlaybackShort;

            echo "time since last full message: $timeSinceLastFullPlayback" . PHP_EOL;
            echo "time since last short message: $timeSinceLastShortPlayback" . PHP_EOL;

            if ($timeSinceLastFullPlayback >= $this->intervalFullAnnouncement) {
                if (isset($record->winddirection)) {
                    $direction = Record::getWindDirectionNicename($record->winddirection);
                } else {
                    $direction = "";
                }
                $message = <<<HEREDOC
p3
hier-ist-die-wetterstation-des-gleitschirmvereins-baden-auf-dem-merkur
aktuelle-windmessung $direction $record->windspeed kmh
durchschnittlicher-wind-der-letzten-20-minuten $directionAverage $speedAverage kmh
staerkste-windboe-der-letzten-20-minuten $strongestGustNiceDirection $strongestGustSpeed kmh
tschuess
p3
HEREDOC;
                $this->playAnnouncement($message);
                $this->timestampLastPlaybackFull = $now;
            } else {


                if ($timeSinceLastShortPlayback > $this->intervalShortAnnouncement) {
                    if (isset($record->winddirection)) {
                        $direction = Record::getWindDirectionNicename($record->winddirection);
                    } else {
                        $direction = "";
                    }
                    $message = <<<HEREDOC
p3
aktuelle-windmessung $direction $record->windspeed 
staerkste-windboe $strongestGustNiceDirection $strongestGustSpeed 
durchschnitt  $directionAverage  $speedAverage  kmh 
tschuess
p3
HEREDOC;
                    $this->playAnnouncement($message);
                    $this->timestampLastPlaybackShort = $now;
                }
            }

            echo "---" . PHP_EOL;
        }
    }

    public function createSoundArrayFromString(string $messages): array
    {

        $files = [];
        $parts = preg_split('/\s+/', $messages);
        foreach ($parts as $part) {
            $part = trim($part);
            if (is_numeric($part)) {
                $number = (int)$part;
                if ($number <= 99) {
                    try {
                        $files[] = $this->getClosestNumberSoundFile($number);
                    } catch (Exception $e) {
                        error_log($e->getMessage());
                        continue;
                    }
                } else if ($number >= 100 && $number <= 300) {
                    $hundreds = floor($number / 100) * 100;

                    $tens = $number - $hundreds;
                    try {
                        $tensFile = $this->getClosestNumberSoundFile($tens);
                    } catch (Exception $e) {
                        error_log($e->getMessage());
                        continue;
                    }

                    $files[] = $this->soundDir . "/natural/" . $hundreds;
                    $files[] = $tensFile;

                } else {
                    error_log("number too high for sound file");

                }
            } else {
                $file = $this->soundDir . "/natural/" . $part;
                if (!is_file($file)) {
                    error_log("Missing file: $file");
                    continue;
                }

                $files[] = $file;
            }
        }

        return $files;

    }

    /** @noinspection PhpUnusedParameterInspection */
    public function onDisconnect(Server $server, Socket $client, $message)
    {
        echo 'Disconnection', "\n";
    }


    private function playWaveFileArray(array $wavefiles): void
    {


        /**
         * /usr/bin/shnjoin -Oalways -aFUNK -d/var/www/BERGSTATION/ -rnone -q /var/www/BERGSTATION/soundfiles/funk/out-of-order.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav
         */

        $shnArr = ["shnjoin", "-Oalways", "-aFunk", "-d/tmp", "-rnone", "-q"];
        $shnCommand = array_merge($shnArr, $wavefiles);
        $commandStr = implode(" ", $shnCommand);
        exec("$commandStr > /dev/null", $output, $result_code);
        if ($result_code !== 0) {
            error_log("Failed to join wav files");
            error_log($commandStr);
            foreach ($output as $o) {
                error_log($o);
            }
            return;
        }

        echo "Playing: " . PHP_EOL;
        foreach ($wavefiles as $f) {
            $base = basename($f);
            if (preg_match("/^p[0-3]$/", $base)) {
                continue;
            }
            echo "$base ";
        }
        echo PHP_EOL;

        exec("play /tmp/Funk.wav > /dev/null 2>&1", $out, $result);
        if ($result !== 0) {
            error_log("'play' returned exit code $result");
            foreach ($out as $o) {
                error_log($o);
            }
        }

    }

    /**
     * @throws Exception
     */
    private function getClosestNumberSoundFile(int $number): string
    {
        for ($i = $number; $i <= 99; $i++) {
            $file = $this->soundDir . "/natural/" . $i;
            if (is_file($file)) {
                return $file;
            }
        }
        throw new Exception("Found no file");
    }

    private function playAnnouncement(string $message): void
    {
        $long = $this->createSoundArrayFromString($message);
        $this->playWaveFileArray($long);

    }

    private function sendToGeier(Record $record): void
    {
        $dir = dirname(__FILE__);
        $secrets = "$dir/endpoints.secret.php";
        if (!is_file($secrets)) {
            error_log("Missing config file for sendig to geier");
            return;
        }
        require_once $secrets;
        $geier = new ExternalEndpoint(GEIER, TOKEN);
        $geier->getParamsFromRecord($record);
        $geier->method = "GET";
        if ($this->debug) {
            echo "Debug mode, not sending data to website" . PHP_EOL;
            return;
        }
        $result = $geier->send();

        if ($result["status"] !== 200) {
            error_log("Failed to send to website: " . $result["status"]);

        } else {
            echo "Sent to website. Response was: " . $result["response"] . PHP_EOL;
        }

    }

    public function saveCurrentState(): bool
    {
        if ($this->alreadySaved) {
            return false;
        }
        $recordInits = [];
        foreach ($this->records as $r) {
            if ($r instanceof Record) {
                $recordInits[] = $r->initialString;
            }
        }


        $out = [
            "records" => $recordInits,
            "time" => time(),
            "last_full_playback" => $this->timestampLastPlaybackFull,
            "last_short_playback" => $this->timestampLastPlaybackShort,
        ];
        $json = json_encode($out);
        if ($json === false) {
            error_log("Failed to create json");
            error_log(print_r($out, true));
        }

        $size = file_put_contents($this->savedStateFile, $json);
        if ($size === false) {
            error_log("Failed to save state");
        } else {
            $this->alreadySaved = true;
            echo "Saved current state to $this->savedStateFile, $size bytes" . PHP_EOL;
            return true;
        }
        return false;
    }

    protected function initFromSavedState($file): bool
    {

        $now = time();
        if (!is_file($file)) {
            return false;
        }

        $fileAge = $now - filemtime($file);
        if ($fileAge > self::MAX_RECORD_AGE_FOR_AVERAGING * 60) {
            unlink($file);
            echo "$file creation date too old ({$fileAge}s), ignoring" . PHP_EOL;
            return false;
        }

        $str = file_get_contents($file);
        $json = json_decode($str);

        if (!isset($json->time)) {
            error_log("state time not set");
            unlink($file);
            return false;
        }

        $stateTime = $json->time;
        if (!is_int($stateTime)) {
            error_log("state time not int");
            unlink($file);
            return false;
        }

        if ($now - $stateTime > self::MAX_RECORD_AGE_FOR_AVERAGING * 60) {
            error_log("records found in $file are too old");
            unlink($file);
            return false;
        }


        if (isset($json->last_full_playback) && is_int($json->last_full_playback)) {
            $this->timestampLastPlaybackFull = $json->last_full_playback;
            echo "Radio playback time (full) set from state" . PHP_EOL;
        }

        if (isset($json->last_short_playback) && is_int($json->last_full_playback)) {
            $this->timestampLastPlaybackShort = $json->last_short_playback;
            echo "Radio playback time (short) set from state" . PHP_EOL;
        }

        if (empty($json->records)) {
            echo "No records in state, ignoring" . PHP_EOL;
        } else {
            $loadedRecords = 0;
            foreach ($json->records as $recordStr) {
                try {
                    $record = new Record($recordStr);
                    if (!$record->isValid()) {
                        error_log("Ignored invalid record");
                        continue;
                    }
                    $this->records[] = $record;
                    if ($record->secondsSinceStartup > $this->newestRecordTimestamp) {
                        $this->newestRecordTimestamp = $record->secondsSinceStartup;

                    }
                    $loadedRecords++;
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }
            echo "$loadedRecords records loaded from saved state" . PHP_EOL;
        }
        unlink($file);
        return true;


    }

    private function disconnectClients(): void
    {
        foreach ($this->clients as $client) {
            $this->disconnect($client);
        }
    }


}
