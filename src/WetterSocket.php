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
    private string $lastBroadcastString;

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
    private int $timestampLastBroadcastShort;

    /**
     * @return int
     */
    public function getTimestampLastBroadcastShort(): int
    {
        return $this->timestampLastBroadcastShort;
    }

    /**
     * @return int
     */
    public function getTimestampLastBroadcastFull(): int
    {
        return $this->timestampLastBroadcastFull;
    }

    //private int $intervalShortAnnouncement = 1;
    private int $intervalShortBroadcast = 5 * 60;

    private int $timestampLastBroadcastFull;
    //private int $intervalFullAnnouncement = 60;
    private int $intervalFullBroadcast = 60 * 60;
    private int $timestampLastBroadcastAny;


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
            /**
             * @var Record $r
             */
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

        return round((rad2deg(atan2($sinSum, $cosSum)) + 360)) % 360;
    }


    public function __construct($ip = null, $port = self::DEFAULT_PORT, $debug = false, $savedStateFile = "/run/wetter_socket/wetter_socket_state.json")
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


        $time = time();
        $this->timestampLastBroadcastFull = $time;
        $this->timestampLastBroadcastShort = $time;
        $this->timestampLastBroadcastAny = $time;

        $this->initFromSavedState($this->savedStateFile);
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


    /**
     * @noinspection PhpUnusedParameterInspection
     */
    public function onInput(Server $server, Socket $client, $message)
    {

        if (str_contains($message, "HTTP/1.1")) {
            try {
                $this->handleHttpRequest($client);
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        } else {
            echo $message . PHP_EOL;
            $this->handleStationInput($message, $client);
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

    }


    private function playWaveFileArray(array $wavefiles): void
    {


        /**
         * /usr/bin/shnjoin -Oalways -aFUNK -d/var/www/BERGSTATION/ -rnone -q /var/www/BERGSTATION/soundfiles/funk/out-of-order.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav /var/www/BERGSTATION/soundfiles/funk/p0.mus.wav
         */

        $tempDir = "/run/wetter_socket";
        if (PHP_OS === "Darwin") {
            $tempDir = "/tmp/wetter_socket";
        }
        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir)) {
                error_log("Unable to create broadcast file. Please create $tempDir");
                return;
            }
        }

        if (!is_writable($tempDir)) {
            error_log("$tempDir not writable for broadcast");
            return;
        }


        $shnArr = ["shnjoin", "-Oalways", "-aFunk", "-d$tempDir", "-rnone", "-q"];
        $shnCommand = array_merge($shnArr, $wavefiles);

        $commandStr = implode(" ", $shnCommand);
        echo $commandStr . PHP_EOL;
        exec("$commandStr > /dev/null", $output, $result_code);
        if ($result_code !== 0) {
            error_log("Failed to join wav files");
            error_log($commandStr);
            foreach ($output as $o) {
                error_log($o);
            }
            return;
        } else {
            $this->lastBroadcastString = "";
            foreach ($wavefiles as $wv){
                $this->lastBroadcastString .= basename($wv) . " ";
            }
            $this->lastBroadcastString = trim($this->lastBroadcastString);
        }

        echo "Radio broadcast: " . PHP_EOL;
        foreach ($wavefiles as $f) {
            $base = basename($f);
            if (preg_match("/^p[0-3]$/", $base)) {
                continue;
            }
            echo "$base ";
        }
        echo PHP_EOL;


        $nohupCommand = 'bash -c "exec nohup setsid play ' . $tempDir . '/Funk.wav > /dev/null 2>&1 &"';
        echo "command: $nohupCommand" . PHP_EOL;
        exec($nohupCommand);

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

    private function broadcastRadio(string $message): void
    {
        $soundfiles = $this->createSoundArrayFromString($message);
        $this->playWaveFileArray($soundfiles);

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
            $record->geierResponse = $result["response"];
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
            "last_full_broadcast" => $this->timestampLastBroadcastFull,
            "last_short_broadcast" => $this->timestampLastBroadcastShort,
            "last_any_broadcast" => $this->timestampLastBroadcastAny,
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


        if (isset($json->last_full_broadcast) && is_int($json->last_full_broadcast)) {
            $this->timestampLastBroadcastFull = $json->last_full_broadcast;
            echo "Radio playback time (full) set from state" . PHP_EOL;
        }

        if (isset($json->last_short_broadcast) && is_int($json->last_full_broadcast)) {
            $this->timestampLastBroadcastShort = $json->last_short_broadcast;
            echo "Radio playback time (short) set from state" . PHP_EOL;
        }

        if (isset($json->last_any_broadcast) && is_int($json->last_any_broadcast)) {
            $this->timestampLastBroadcastAny = $json->last_any_broadcast;
            echo "Radio playback time (any) set from state" . PHP_EOL;
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

    private function handleStationInput($message, Socket $client): void
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

            $timeSinceLastFullBroadcast = $now - $this->timestampLastBroadcastFull;
            $timeSinceLastShortBroadcast = $now - $this->timestampLastBroadcastShort;
            $timeSinceLastAnyBroadcast = $now - $this->timestampLastBroadcastAny;

            echo "time since last any broadcast: $timeSinceLastAnyBroadcast of {$this->intervalShortBroadcast}s" . PHP_EOL;
            echo "time since last short broadcast: $timeSinceLastShortBroadcast of {$this->intervalShortBroadcast}s" . PHP_EOL;
            echo "time since last full broadcast: $timeSinceLastFullBroadcast of {$this->intervalFullBroadcast}s" . PHP_EOL;

            if ($timeSinceLastAnyBroadcast >= $this->intervalShortBroadcast) {
                if ($timeSinceLastFullBroadcast >= $this->intervalFullBroadcast) {
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
                    $this->broadcastRadio($message);
                    $this->timestampLastBroadcastFull = $now;
                    $this->timestampLastBroadcastAny = $now;
                } else {
                    if ($timeSinceLastShortBroadcast >= $this->intervalShortBroadcast) {
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
                        $this->broadcastRadio($message);
                        $this->timestampLastBroadcastShort = $now;
                        $this->timestampLastBroadcastAny = $now;
                    }
                }
            }

            echo "---" . PHP_EOL;
        }
    }

    private function handleHttpRequest(Socket $client): void
    {

        $records = [];
        foreach ($this->records as $r) {
            /**
             * @var Record $r
             */
            $records[] = $r->toAssocArray();
        }
        $notAvailable = "n/a";
        $maxWindspeedRecord = $this->getStrongestGust() ?? $notAvailable;
        $directionAverage = $this->getDirectionAverage() ?? $notAvailable;
        $windspeedMax = $maxWindspeedRecord->windspeedMax ?? $notAvailable;

        $winddirection = $maxWindspeedRecord->winddirection ?? $notAvailable;
        if (is_float($winddirection)) {
            try {
                $windDirectionNicename = Record::getWindDirectionNicename($winddirection) ?? $notAvailable;
            } catch (Exception $e) {
                error_log($e->getMessage());
                $windDirectionNicename = $notAvailable;
            }
        } else {
            $windDirectionNicename = $notAvailable;
        }
        $speedAverage = $this->getSpeedAverage() ?? $notAvailable;
        $windDirectionNicenameAvg = Record::getWindDirectionNicename($directionAverage) ?? $notAvailable;
        $timestampLastBroadcastAny = $this->timestampLastBroadcastAny ?? $notAvailable;
        $timestampLastBroadcastShort = $this->timestampLastBroadcastShort ?? $notAvailable;
        $timestampLastBroadcastFull = $this->timestampLastBroadcastFull ?? $notAvailable;
        $lastBroadcastString = $this->lastBroadcastString ?? "";
        $info = [

            "period" => [
                "timespan" => self::MAX_RECORD_AGE_FOR_AVERAGING * 60,
                "max_windspeed" => [
                    "windspeed" => $windspeedMax,
                    "wind_direction" => $winddirection,
                    "wind_direction_name" => $windDirectionNicename
                ],
                "average_windspeed" => [
                    "windspeed" => $speedAverage,
                    "wind_direction" => $directionAverage,
                    "wind_direction_name" => $windDirectionNicenameAvg
                ],
            ],
            "last_broadcast_times" => [
                "any" => $timestampLastBroadcastAny,
                "short" => $timestampLastBroadcastShort,
                "full" => $timestampLastBroadcastFull,
                "text" => $lastBroadcastString
            ],
            "records" => array_reverse($records),
        ];
        $json = json_encode($info);
        $size = strlen($json);
        $reply = <<<HEREDOC
HTTP/1.1 200 OK
Server: WetterSocket/1.0.0 (Unix)
Content-Length: $size
Content-Language: en
Connection: close
Content-Type: application/json

$json
HEREDOC;


        try {
            $client->send($reply);
        } catch (SocketException $e) {
            error_log($e->getMessage());
        }
    }


}

